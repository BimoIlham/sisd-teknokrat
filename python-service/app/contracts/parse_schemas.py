from typing import List, Optional, Dict, Any
from pydantic import BaseModel, Field

class SourceLocator(BaseModel):
    page: int
    bbox: Optional[List[float]] = None

class NormalizedRow(BaseModel):
    lecturer_name: str
    lecturer_external_code: Optional[str] = None
    faculty_name: Optional[str] = None
    study_program_name: Optional[str] = None
    semester: Optional[str] = None
    academic_year: Optional[str] = None
    day_of_week: Optional[int] = Field(default=None, ge=1, le=7)
    start_time: str
    end_time: str
    class_name: str
    course_name: str
    course_code: Optional[str] = None
    room_name: Optional[str] = None
    delivery_mode: Optional[str] = None

class ValidationIssue(BaseModel):
    code: str
    severity: str = "ERROR" # ERROR, WARNING, INFO
    row_no: Optional[int] = None
    field: Optional[str] = None
    message: str
    suggested_action: Optional[str] = None

class ParsedRow(BaseModel):
    row_no: int
    source: SourceLocator
    raw: Dict[str, Any] = Field(default_factory=dict)
    normalized: Optional[NormalizedRow] = None
    confidence: Optional[float] = None
    issues: List[ValidationIssue] = Field(default_factory=list)

class ParserInfo(BaseModel):
    name: str
    version: str

class ParseScheduleResponse(BaseModel):
    contract_version: str = "1.0"
    request_id: str
    import_id: int
    parser: ParserInfo
    page_count: int
    issues: List[ValidationIssue] = Field(default_factory=list)
    rows: List[ParsedRow] = Field(default_factory=list)
