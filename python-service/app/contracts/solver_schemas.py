from typing import List, Optional, Dict, Any
from pydantic import BaseModel, Field, ConfigDict, model_validator
from app.resource_limits import MAX_CANDIDATES

class SchedulingRuleConfig(BaseModel):
    schema_version: str = "1.0"
    block_supervision_when_teaching_count_gte: Optional[int] = 3
    buffer_minutes: int = 0
    max_supervisions_per_day: Optional[int] = None
    max_supervisions_per_week: Optional[int] = None
    max_daily_span_minutes: Optional[int] = None
    cross_unit_policy: str = "ANY" # ANY, SAME_FACULTY, SAME_STUDY_PROGRAM
    stage_supervisor_policy: str = "ANY" # ANY, SAME, DIFFERENT
    min_stage_gap_days: int = 0
    one_observation_per_target_per_stage: bool = True
    require_t2_after_t1: bool = True

class DateRange(BaseModel):
    starts_on: str
    ends_on: str

class TargetLecturer(BaseModel):
    id: int
    name: str = Field(max_length=255)
    faculty_id: Optional[int] = None
    study_program_id: Optional[int] = None

class SupervisorLecturer(BaseModel):
    id: int
    name: str = Field(max_length=255)
    faculty_id: Optional[int] = None
    study_program_id: Optional[int] = None

class TeachingOccurrence(BaseModel):
    id: int
    target_id: int
    occurs_on: str # YYYY-MM-DD
    starts_at: str # ISO8601 UTC
    ends_at: str   # ISO8601 UTC
    delivery_mode: str = "OFFLINE"
    faculty_id: Optional[int] = None
    study_program_id: Optional[int] = None
    course_name: str = Field(max_length=255)
    class_name: str = Field(max_length=255)

class TeachingBusyInterval(BaseModel):
    supervisor_id: int
    occurs_on: str # YYYY-MM-DD
    starts_at: str # ISO8601 UTC
    ends_at: str   # ISO8601 UTC

class ReservedSupervision(BaseModel):
    supervisor_id: int
    target_id: int
    occurs_on: str
    starts_at: str
    ends_at: str

class AssignmentItem(BaseModel):
    target_id: int
    supervisor_id: int
    occurrence_id: int

class PriorStageAssignment(BaseModel):
    target_id: int
    supervisor_id: int
    occurs_on: str

class SolverOptions(BaseModel):
    model_config = ConfigDict(extra="forbid")
    time_limit_seconds: float = Field(default=60, ge=1, le=60, allow_inf_nan=False)

class SolveAssignmentsRequest(BaseModel):
    contract_version: str = "1.0"
    request_id: str = Field(max_length=255)
    input_revision: int
    input_hash: str = Field(max_length=255)
    period_id: int
    stage_id: int
    stage_no: int
    timezone: str = "Asia/Jakarta"
    date_range: DateRange
    rules: SchedulingRuleConfig
    targets: List[TargetLecturer] = Field(max_length=1000)
    supervisors: List[SupervisorLecturer] = Field(max_length=200)
    occurrences: List[TeachingOccurrence] = Field(max_length=10000)
    teaching_busy_intervals: List[TeachingBusyInterval] = Field(default_factory=list, max_length=10000)
    reserved_supervisions: List[ReservedSupervision] = Field(default_factory=list, max_length=10000)
    locked_assignments: List[AssignmentItem] = Field(default_factory=list, max_length=10000)
    previous_auto_assignments: List[AssignmentItem] = Field(default_factory=list, max_length=10000)
    prior_stage_assignments: List[PriorStageAssignment] = Field(default_factory=list, max_length=10000)
    solver_options: SolverOptions = Field(default_factory=SolverOptions)

    @model_validator(mode="after")
    def bound_candidate_work(self) -> "SolveAssignmentsRequest":
        if len(self.occurrences) * len(self.supervisors) > MAX_CANDIDATES:
            raise ValueError("Jumlah kombinasi kandidat melebihi batas 100000.")
        return self

class UnassignedDiagnostic(BaseModel):
    target_id: int
    reason_codes: List[str]
    diagnostic_kind: str = "PREFILTER" # PREFILTER, SOLVER, INFEASIBLE

class SolveAssignmentsResponse(BaseModel):
    contract_version: str = "1.0"
    request_id: str = Field(max_length=255)
    input_revision: int
    input_hash: str = Field(max_length=255)
    solver_status: str # OPTIMAL, FEASIBLE, INFEASIBLE, UNKNOWN, MODEL_INVALID
    coverage_status: str # FULL, PARTIAL, NONE
    assignments: List[AssignmentItem] = Field(default_factory=list)
    unassigned: List[UnassignedDiagnostic] = Field(default_factory=list)
    diagnostics: Dict[str, Any] = Field(default_factory=dict)
