from datetime import datetime, date, timedelta
from typing import List, Dict, Any, Tuple, Optional
from ortools.sat.python import cp_model
from app.contracts.solver_schemas import (
    SolveAssignmentsRequest,
    SolveAssignmentsResponse,
    AssignmentItem,
    UnassignedDiagnostic
)

def parse_iso_dt(dt_str: str) -> datetime:
    # Accept standard ISO formats: 2026-10-05T09:00:00Z, 2026-10-05T09:00:00+00:00 or with microseconds
    clean = dt_str.replace("Z", "+00:00")
    return datetime.fromisoformat(clean)

def intervals_overlap(start1: datetime, end1: datetime, start2: datetime, end2: datetime, buffer_mins: int = 0) -> bool:
    from datetime import timedelta
    buf = timedelta(minutes=buffer_mins)
    # pair involving supervision has buffer
    return (start1 - buf) < end2 and start2 < (end1 + buf)

class CpSatScheduleSolver:
    def solve(self, req: SolveAssignmentsRequest) -> SolveAssignmentsResponse:
        diagnostics: Dict[str, Any] = {
            "target_count": len(req.targets),
            "supervisor_count": len(req.supervisors),
            "occurrence_count": len(req.occurrences),
            "optimality_proven": False,
            "wall_time_ms": 0
        }

        # 1. Index supervisors and their daily teaching counts
        supervisor_map = {s.id: s for s in req.supervisors}
        target_map = {t.id: t for t in req.targets}

        # Teaching busy by supervisor: supervisor_id -> list of (start_dt, end_dt, occurs_on)
        teaching_by_sup: Dict[int, List[Tuple[datetime, datetime, str]]] = {s.id: [] for s in req.supervisors}
        teaching_count_by_sup_date: Dict[Tuple[int, str], int] = {}

        for ti in req.teaching_busy_intervals:
            s_id = ti.supervisor_id
            if s_id in teaching_by_sup:
                s_dt = parse_iso_dt(ti.starts_at)
                e_dt = parse_iso_dt(ti.ends_at)
                teaching_by_sup[s_id].append((s_dt, e_dt, ti.occurs_on))
                k = (s_id, ti.occurs_on)
                teaching_count_by_sup_date[k] = teaching_count_by_sup_date.get(k, 0) + 1

        # Reserved supervisions: supervisor_id -> list of (start_dt, end_dt, occurs_on)
        reserved_by_sup: Dict[int, List[Tuple[datetime, datetime, str]]] = {s.id: [] for s in req.supervisors}
        for res in req.reserved_supervisions:
            s_id = res.supervisor_id
            if s_id in reserved_by_sup:
                s_dt = parse_iso_dt(res.starts_at)
                e_dt = parse_iso_dt(res.ends_at)
                reserved_by_sup[s_id].append((s_dt, e_dt, res.occurs_on))

        # Prior stage assignments: target_id -> (supervisor_id, occurs_on_date)
        prior_by_target: Dict[int, Tuple[int, date]] = {}
        for psa in req.prior_stage_assignments:
            try:
                p_date = date.fromisoformat(psa.occurs_on)
                prior_by_target[psa.target_id] = (psa.supervisor_id, p_date)
            except Exception:
                pass

        # Locked assignments: target_id -> AssignmentItem
        locked_by_target: Dict[int, AssignmentItem] = {la.target_id: la for la in req.locked_assignments}

        # Previous auto assignments set for stability objective
        prev_auto_set = {(pa.target_id, pa.supervisor_id, pa.occurrence_id) for pa in req.previous_auto_assignments}

        # 2. Filter candidate occurrences per target
        # occurrence_id -> TeachingOccurrence
        occ_map = {occ.id: occ for occ in req.occurrences}
        # target_id -> list of occurrences
        occ_by_target: Dict[int, List[Any]] = {t.id: [] for t in req.targets}
        for occ in req.occurrences:
            if occ.target_id in occ_by_target:
                occ_by_target[occ.target_id].append(occ)

        # 3. Generate candidate triples (target_id, supervisor_id, occurrence_id)
        # Prefilter according to Hard Constraints H01, H02, H03, H04, H05, H08
        candidates: List[Tuple[int, int, int]] = []
        unassigned_reasons: Dict[int, List[str]] = {t.id: [] for t in req.targets}

        buffer_minutes = req.rules.buffer_minutes or 0
        block_teaching_count = req.rules.block_supervision_when_teaching_count_gte or 3

        for target in req.targets:
            t_id = target.id
            target_occs = occ_by_target.get(t_id, [])
            if not target_occs:
                unassigned_reasons[t_id].append("NO_VALID_CLASSES_IN_STAGE")
                continue

            # Check if target is locked
            if t_id in locked_by_target:
                la = locked_by_target[t_id]
                candidates.append((la.target_id, la.supervisor_id, la.occurrence_id))
                continue

            target_has_valid_candidate = False

            for occ in target_occs:
                occ_dt_start = parse_iso_dt(occ.starts_at)
                occ_dt_end = parse_iso_dt(occ.ends_at)
                occ_date = date.fromisoformat(occ.occurs_on)

                # Prior stage check H08
                if req.stage_no == 2 and t_id in prior_by_target:
                    prior_sup, prior_date = prior_by_target[t_id]
                    if req.rules.require_t2_after_t1 and occ_date <= prior_date:
                        continue
                    if req.rules.min_stage_gap_days > 0 and (occ_date - prior_date).days < req.rules.min_stage_gap_days:
                        continue
                    if req.rules.stage_supervisor_policy == "SAME":
                        pass # only prior_sup will be considered
                    elif req.rules.stage_supervisor_policy == "DIFFERENT":
                        pass # prior_sup will be excluded

                for sup in req.supervisors:
                    s_id = sup.id
                    if occ.allowed_supervisor_ids is not None and s_id not in occ.allowed_supervisor_ids:
                        continue
                    # H01: No self-supervision
                    if s_id == t_id:
                        continue

                    # Policy: stage_supervisor_policy
                    if req.stage_no == 2 and t_id in prior_by_target:
                        prior_sup, _ = prior_by_target[t_id]
                        if req.rules.stage_supervisor_policy == "SAME" and s_id != prior_sup:
                            continue
                        if req.rules.stage_supervisor_policy == "DIFFERENT" and s_id == prior_sup:
                            continue

                    # Policy: cross_unit_policy
                    if req.rules.cross_unit_policy == "SAME_FACULTY":
                        if sup.faculty_id and occ.faculty_id and sup.faculty_id != occ.faculty_id:
                            continue
                    elif req.rules.cross_unit_policy == "SAME_STUDY_PROGRAM":
                        if sup.study_program_id and occ.study_program_id and sup.study_program_id != occ.study_program_id:
                            continue

                    # H05: Ambang mengajar >= 3 kelas pada tanggal yang sama
                    daily_teaching_count = teaching_count_by_sup_date.get((s_id, occ.occurs_on), 0)
                    if daily_teaching_count >= block_teaching_count:
                        continue

                    # H03: Bentrok mengajar supervisor + buffer
                    teaching_overlap = False
                    for t_start, t_end, _ in teaching_by_sup.get(s_id, []):
                        if intervals_overlap(occ_dt_start, occ_dt_end, t_start, t_end, buffer_minutes):
                            teaching_overlap = True
                            break
                    if teaching_overlap:
                        continue

                    # H04: Bentrok supervisi tetap lain (lintas periode/tahap) + buffer
                    res_overlap = False
                    for r_start, r_end, _ in reserved_by_sup.get(s_id, []):
                        if intervals_overlap(occ_dt_start, occ_dt_end, r_start, r_end, buffer_minutes):
                            res_overlap = True
                            break
                    if res_overlap:
                        continue

                    candidates.append((t_id, s_id, occ.id))
                    target_has_valid_candidate = True

            if not target_has_valid_candidate and t_id not in locked_by_target:
                unassigned_reasons[t_id].append("NO_FREE_SUPERVISOR")

        # 4. Build CP-SAT Model
        model = cp_model.CpModel()
        var_x: Dict[Tuple[int, int, int], cp_model.IntVar] = {}

        for (t_id, s_id, occ_id) in candidates:
            var_name = f"x_{t_id}_{s_id}_{occ_id}"
            var_x[(t_id, s_id, occ_id)] = model.NewBoolVar(var_name)

        # H07 & Locked: Exactly 1 assignment for locked targets, <= 1 for other targets
        for target in req.targets:
            t_id = target.id
            t_vars = [var_x[k] for k in candidates if k[0] == t_id]
            if t_id in locked_by_target:
                la = locked_by_target[t_id]
                locked_key = (la.target_id, la.supervisor_id, la.occurrence_id)
                if locked_key in var_x:
                    model.Add(var_x[locked_key] == 1)
                else:
                    # Model invalid: locked assignment was not among candidate structures
                    diagnostics["model_invalid_reason"] = f"Locked assignment for target {t_id} is unavailable."
                    return SolveAssignmentsResponse(
                        contract_version="1.0",
                        request_id=req.request_id,
                        input_revision=req.input_revision,
                        input_hash=req.input_hash,
                        solver_status="MODEL_INVALID",
                        coverage_status="NONE",
                        assignments=[],
                        unassigned=[UnassignedDiagnostic(target_id=t.id, reason_codes=["LOCKED_TARGET_CONFLICT"]) for t in req.targets],
                        diagnostics=diagnostics
                    )
            else:
                if t_vars:
                    model.Add(sum(t_vars) <= 1)

        # H04: Mutual exclusion for supervisor overlapping candidates
        # Group candidates by supervisor
        sup_candidates: Dict[int, List[Tuple[int, int, int]]] = {s.id: [] for s in req.supervisors}
        for cand in candidates:
            sup_candidates[cand[1]].append(cand)

        for s_id, c_list in sup_candidates.items():
            starts_and_ends = [
                (
                    candidate,
                    parse_iso_dt(occ_map[candidate[2]].starts_at),
                    parse_iso_dt(occ_map[candidate[2]].ends_at),
                )
                for candidate in c_list
            ]
            starts_and_ends.sort(key=lambda interval: interval[1])
            for index, (candidate_a, start_a, end_a) in enumerate(starts_and_ends):
                latest_conflict_start = end_a + timedelta(minutes=buffer_minutes)
                for next_index in range(index + 1, len(starts_and_ends)):
                    candidate_b, start_b, end_b = starts_and_ends[next_index]
                    if start_b >= latest_conflict_start:
                        break
                    if intervals_overlap(start_a, end_a, start_b, end_b, buffer_minutes):
                        model.Add(var_x[candidate_a] + var_x[candidate_b] <= 1)

        # Policy constraints: max_supervisions_per_day, max_supervisions_per_week
        if req.rules.max_supervisions_per_day is not None:
            max_day = req.rules.max_supervisions_per_day
            for s_id, c_list in sup_candidates.items():
                # Group by occurs_on date
                by_date: Dict[str, List[Any]] = {}
                for cand in c_list:
                    d_str = occ_map[cand[2]].occurs_on
                    by_date.setdefault(d_str, []).append(var_x[cand])
                for d_str, v_list in by_date.items():
                    model.Add(sum(v_list) <= max_day)

        assigned_vars = list(var_x.values())
        if not assigned_vars:
            return SolveAssignmentsResponse(
                contract_version="1.0",
                request_id=req.request_id,
                input_revision=req.input_revision,
                input_hash=req.input_hash,
                solver_status="FEASIBLE" if not req.targets else "INFEASIBLE",
                coverage_status="NONE",
                assignments=[],
                unassigned=[UnassignedDiagnostic(target_id=t.id, reason_codes=unassigned_reasons.get(t.id) or ["NO_FREE_SUPERVISOR"]) for t in req.targets],
                diagnostics=diagnostics
            )

        total_coverage = sum(assigned_vars)
        stability_bonus = sum(var_x[k] for k in candidates if k in prev_auto_set)
        offline_bonus = sum(var_x[k] for k in candidates if occ_map[k[2]].delivery_mode == "OFFLINE")

        # Max load variable across supervisors
        max_load = model.NewIntVar(0, len(req.targets), "max_load")
        for s_id, c_list in sup_candidates.items():
            if c_list:
                model.Add(sum(var_x[c] for c in c_list) <= max_load)

        # Coverage dominates mode preference; offline dominates balance and stability.
        target_count = len(req.targets)
        secondary_range = 110 * target_count
        offline_weight = secondary_range + 1
        coverage_weight = offline_weight * target_count + secondary_range + 1
        model.Maximize(coverage_weight * total_coverage + offline_weight * offline_bonus + 10 * stability_bonus - 100 * max_load)

        # Solve
        solver = cp_model.CpSolver()
        time_limit = req.solver_options.time_limit_seconds
        solver.parameters.max_time_in_seconds = float(time_limit)
        solver.parameters.num_workers = 4

        start_time = datetime.now()
        status = solver.Solve(model)
        elapsed_ms = int((datetime.now() - start_time).total_seconds() * 1000)
        diagnostics["wall_time_ms"] = elapsed_ms

        assigned_items: List[AssignmentItem] = []
        assigned_target_ids = set()

        if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            for (t_id, s_id, occ_id), v in var_x.items():
                if solver.Value(v) == 1:
                    assigned_items.append(AssignmentItem(target_id=t_id, supervisor_id=s_id, occurrence_id=occ_id))
                    assigned_target_ids.add(t_id)

            diagnostics["optimality_proven"] = (status == cp_model.OPTIMAL)
            diagnostics["assigned_count"] = len(assigned_items)

            solver_status_str = "OPTIMAL" if status == cp_model.OPTIMAL else "FEASIBLE"
            coverage_str = "FULL" if len(assigned_items) == len(req.targets) else ("PARTIAL" if assigned_items else "NONE")

            unassigned_list: List[UnassignedDiagnostic] = []
            for target in req.targets:
                if target.id not in assigned_target_ids:
                    codes = unassigned_reasons.get(target.id) or ["SOLVER_UNASSIGNED_CAPACITY"]
                    unassigned_list.append(UnassignedDiagnostic(
                        target_id=target.id,
                        reason_codes=codes,
                        diagnostic_kind="SOLVER" if target.id in [c[0] for c in candidates] else "PREFILTER"
                    ))

            return SolveAssignmentsResponse(
                contract_version="1.0",
                request_id=req.request_id,
                input_revision=req.input_revision,
                input_hash=req.input_hash,
                solver_status=solver_status_str,
                coverage_status=coverage_str,
                assignments=assigned_items,
                unassigned=unassigned_list,
                diagnostics=diagnostics
            )
        elif status == cp_model.INFEASIBLE:
            return SolveAssignmentsResponse(
                contract_version="1.0",
                request_id=req.request_id,
                input_revision=req.input_revision,
                input_hash=req.input_hash,
                solver_status="INFEASIBLE",
                coverage_status="NONE",
                assignments=[],
                unassigned=[UnassignedDiagnostic(target_id=t.id, reason_codes=["MODEL_INFEASIBLE"]) for t in req.targets],
                diagnostics=diagnostics
            )
        else: # UNKNOWN
            return SolveAssignmentsResponse(
                contract_version="1.0",
                request_id=req.request_id,
                input_revision=req.input_revision,
                input_hash=req.input_hash,
                solver_status="UNKNOWN",
                coverage_status="NONE",
                assignments=[],
                unassigned=[UnassignedDiagnostic(target_id=t.id, reason_codes=["SOLVER_TIMEOUT_UNKNOWN"]) for t in req.targets],
                diagnostics=diagnostics
            )
