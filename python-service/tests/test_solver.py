import pytest
from app.contracts.solver_schemas import (
    SolveAssignmentsRequest,
    DateRange,
    SchedulingRuleConfig,
    TargetLecturer,
    SupervisorLecturer,
    TeachingOccurrence,
    TeachingBusyInterval,
    AssignmentItem
)
from app.scheduler.cp_sat_solver import CpSatScheduleSolver

def test_hard_constraint_h01_no_self_supervision():
    solver = CpSatScheduleSolver()
    # Dosen 1 is both target and supervisor
    req = SolveAssignmentsRequest(
        contract_version="1.0",
        request_id="test-h01",
        input_revision=1,
        input_hash="hash-h01",
        period_id=1,
        stage_id=1,
        stage_no=1,
        date_range=DateRange(starts_on="2026-10-01", ends_on="2026-10-15"),
        rules=SchedulingRuleConfig(),
        targets=[TargetLecturer(id=1, name="Dosen 1")],
        supervisors=[SupervisorLecturer(id=1, name="Dosen 1")], # Only himself available
        occurrences=[TeachingOccurrence(
            id=101,
            target_id=1,
            occurs_on="2026-10-05",
            starts_at="2026-10-05T02:00:00Z",
            ends_at="2026-10-05T03:40:00Z",
            course_name="Algoritma",
            class_name="IF23A"
        )]
    )
    res = solver.solve(req)
    assert len(res.assignments) == 0
    assert res.coverage_status == "NONE"
    assert res.unassigned[0].target_id == 1
    assert "NO_FREE_SUPERVISOR" in res.unassigned[0].reason_codes
    assert res.diagnostics["candidate_count_by_supervisor"] == {"1": 0}
    assert res.diagnostics["supervisors_without_candidates"] == [1]

def test_hard_constraint_h03_teaching_overlap():
    solver = CpSatScheduleSolver()
    # Supervisor 2 teaches at 02:00-03:40 UTC, target 1 also occurs at 02:00-03:40 UTC
    req = SolveAssignmentsRequest(
        contract_version="1.0",
        request_id="test-h03",
        input_revision=1,
        input_hash="hash-h03",
        period_id=1,
        stage_id=1,
        stage_no=1,
        date_range=DateRange(starts_on="2026-10-01", ends_on="2026-10-15"),
        rules=SchedulingRuleConfig(buffer_minutes=0),
        targets=[TargetLecturer(id=1, name="Dosen Target")],
        supervisors=[SupervisorLecturer(id=2, name="Dosen Supervisor")],
        occurrences=[TeachingOccurrence(
            id=101,
            target_id=1,
            occurs_on="2026-10-05",
            starts_at="2026-10-05T02:00:00Z",
            ends_at="2026-10-05T03:40:00Z",
            course_name="Algoritma",
            class_name="IF23A"
        )],
        teaching_busy_intervals=[TeachingBusyInterval(
            supervisor_id=2,
            occurs_on="2026-10-05",
            starts_at="2026-10-05T02:00:00Z",
            ends_at="2026-10-05T03:40:00Z"
        )]
    )
    res = solver.solve(req)
    assert len(res.assignments) == 0
    assert res.coverage_status == "NONE"

def test_hard_constraint_h05_threshold_gte_3():
    solver = CpSatScheduleSolver()
    # Supervisor has 3 classes on 2026-10-05
    req = SolveAssignmentsRequest(
        contract_version="1.0",
        request_id="test-h05",
        input_revision=1,
        input_hash="hash-h05",
        period_id=1,
        stage_id=1,
        stage_no=1,
        date_range=DateRange(starts_on="2026-10-01", ends_on="2026-10-15"),
        rules=SchedulingRuleConfig(block_supervision_when_teaching_count_gte=3),
        targets=[TargetLecturer(id=1, name="Dosen Target")],
        supervisors=[SupervisorLecturer(id=2, name="Dosen Supervisor")],
        occurrences=[TeachingOccurrence(
            id=101,
            target_id=1,
            occurs_on="2026-10-05",
            starts_at="2026-10-05T08:00:00Z", # completely different time
            ends_at="2026-10-05T09:40:00Z",
            course_name="Algoritma",
            class_name="IF23A"
        )],
        teaching_busy_intervals=[
            TeachingBusyInterval(supervisor_id=2, occurs_on="2026-10-05", starts_at="2026-10-05T01:00:00Z", ends_at="2026-10-05T02:00:00Z"),
            TeachingBusyInterval(supervisor_id=2, occurs_on="2026-10-05", starts_at="2026-10-05T03:00:00Z", ends_at="2026-10-05T04:00:00Z"),
            TeachingBusyInterval(supervisor_id=2, occurs_on="2026-10-05", starts_at="2026-10-05T05:00:00Z", ends_at="2026-10-05T06:00:00Z"),
        ]
    )
    res = solver.solve(req)
    assert len(res.assignments) == 0

def test_feasible_assignment_and_locked_preservation():
    solver = CpSatScheduleSolver()
    req = SolveAssignmentsRequest(
        contract_version="1.0",
        request_id="test-feasible",
        input_revision=1,
        input_hash="hash-feasible",
        period_id=1,
        stage_id=1,
        stage_no=1,
        date_range=DateRange(starts_on="2026-10-01", ends_on="2026-10-15"),
        rules=SchedulingRuleConfig(),
        targets=[TargetLecturer(id=1, name="Target 1"), TargetLecturer(id=2, name="Target 2")],
        supervisors=[SupervisorLecturer(id=10, name="Supervisor 10"), SupervisorLecturer(id=20, name="Supervisor 20")],
        occurrences=[
            TeachingOccurrence(id=101, target_id=1, occurs_on="2026-10-05", starts_at="2026-10-05T01:00:00Z", ends_at="2026-10-05T02:40:00Z", course_name="MK1", class_name="K1"),
            TeachingOccurrence(id=102, target_id=2, occurs_on="2026-10-05", starts_at="2026-10-05T03:00:00Z", ends_at="2026-10-05T04:40:00Z", course_name="MK2", class_name="K2")
        ],
        locked_assignments=[AssignmentItem(target_id=1, supervisor_id=10, occurrence_id=101)]
    )
    res = solver.solve(req)
    assert res.solver_status in ("OPTIMAL", "FEASIBLE")
    assert res.coverage_status == "FULL"
    assert len(res.assignments) == 2
    # Ensure locked assignment was preserved exactly
    target1_assigned = [a for a in res.assignments if a.target_id == 1][0]
    assert target1_assigned.supervisor_id == 10
    assert target1_assigned.occurrence_id == 101


def priority_request(occurrences, **kwargs):
    return SolveAssignmentsRequest(
        request_id="offline-priority", input_revision=1, input_hash="offline-priority",
        period_id=1, stage_id=1, stage_no=1,
        date_range=DateRange(starts_on="2026-10-01", ends_on="2026-10-15"),
        rules=SchedulingRuleConfig(),
        targets=[TargetLecturer(id=target_id, name=f"Target {target_id}") for target_id in sorted({occ.target_id for occ in occurrences})],
        supervisors=[SupervisorLecturer(id=10, name="Supervisor")],
        occurrences=occurrences, **kwargs,
    )


def priority_occurrence(occurrence_id, mode, target_id=1, day="2026-10-05"):
    return TeachingOccurrence(
        id=occurrence_id, target_id=target_id, occurs_on=day,
        starts_at=f"{day}T01:00:00Z", ends_at=f"{day}T02:40:00Z",
        delivery_mode=mode, course_name="Mata Kuliah", class_name="Kelas",
    )


def test_offline_priority_overrides_previous_online_auto_assignment():
    request = priority_request(
        [priority_occurrence(101, "ONLINE"), priority_occurrence(102, "OFFLINE", day="2026-10-06")],
        previous_auto_assignments=[AssignmentItem(target_id=1, supervisor_id=10, occurrence_id=101)],
    )
    result = CpSatScheduleSolver().solve(request)
    assert result.assignments[0].occurrence_id == 102


def test_online_fallback_when_offline_conflicts_with_teaching():
    request = priority_request(
        [priority_occurrence(101, "OFFLINE"), priority_occurrence(102, "ONLINE", day="2026-10-06")],
        teaching_busy_intervals=[TeachingBusyInterval(supervisor_id=10, occurs_on="2026-10-05", starts_at="2026-10-05T01:00:00Z", ends_at="2026-10-05T02:40:00Z")],
    )
    result = CpSatScheduleSolver().solve(request)
    assert result.coverage_status == "FULL"
    assert result.assignments[0].occurrence_id == 102


def test_online_only_target_is_still_assigned():
    result = CpSatScheduleSolver().solve(priority_request([priority_occurrence(101, "ONLINE")]))
    assert result.coverage_status == "FULL"
    assert result.assignments[0].occurrence_id == 101


def test_offline_preference_keeps_full_coverage_when_two_targets_conflict():
    result = CpSatScheduleSolver().solve(priority_request([
        priority_occurrence(101, "OFFLINE"),
        priority_occurrence(102, "ONLINE", day="2026-10-06"),
        priority_occurrence(201, "OFFLINE", target_id=2),
    ]))
    assert result.coverage_status == "FULL"
    assert {item.occurrence_id for item in result.assignments} == {102, 201}


def test_explicitly_locked_online_assignment_is_preserved():
    request = priority_request(
        [priority_occurrence(101, "ONLINE"), priority_occurrence(102, "OFFLINE", day="2026-10-06")],
        locked_assignments=[AssignmentItem(target_id=1, supervisor_id=10, occurrence_id=101)],
    )
    result = CpSatScheduleSolver().solve(request)
    assert result.assignments[0].occurrence_id == 101


def test_solver_uses_available_supervisors_before_giving_anyone_an_extra_assignment():
    request = SolveAssignmentsRequest(
        request_id="supervisor-coverage",
        input_revision=1,
        input_hash="supervisor-coverage",
        period_id=1,
        stage_id=1,
        stage_no=1,
        date_range=DateRange(starts_on="2026-10-01", ends_on="2026-10-15"),
        rules=SchedulingRuleConfig(),
        targets=[TargetLecturer(id=target_id, name=f"Target {target_id}") for target_id in range(1, 5)],
        supervisors=[
            SupervisorLecturer(id=10, name="Supervisor 10"),
            SupervisorLecturer(id=20, name="Supervisor 20"),
            SupervisorLecturer(id=30, name="Supervisor 30"),
        ],
        occurrences=[
            TeachingOccurrence(
                id=101,
                target_id=1,
                occurs_on="2026-10-05",
                starts_at="2026-10-05T01:00:00Z",
                ends_at="2026-10-05T02:40:00Z",
                course_name="Course 1",
                class_name="Class 1",
                allowed_supervisor_ids=[10, 20, 30],
            ),
            *[
                TeachingOccurrence(
                    id=100 + target_id,
                    target_id=target_id,
                    occurs_on=f"2026-10-{target_id + 5:02d}",
                    starts_at=f"2026-10-{target_id + 5:02d}T01:00:00Z",
                    ends_at=f"2026-10-{target_id + 5:02d}T02:40:00Z",
                    course_name=f"Course {target_id}",
                    class_name=f"Class {target_id}",
                    allowed_supervisor_ids=[10, 20],
                )
                for target_id in range(2, 5)
            ],
        ],
    )

    result = CpSatScheduleSolver().solve(request)

    assert result.coverage_status == "FULL"
    assert {assignment.supervisor_id for assignment in result.assignments} == {10, 20, 30}
    assert result.diagnostics["eligible_but_unused_supervisor_ids"] == []
