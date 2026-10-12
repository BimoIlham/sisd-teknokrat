import secrets

import fitz
import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError
from app.main import app, processing_slots
from app.contracts.solver_schemas import SolveAssignmentsRequest
from app.parsers.schedule_parser import SchedulePdfParser, ResourceLimitError
from app.resource_limits import MAX_REQUEST_BYTES
from test_service_auth import solver_payload


@pytest.fixture
def client(monkeypatch):
    secret = secrets.token_hex(32)
    monkeypatch.setenv("PYTHON_SERVICE_SECRET", secret)
    return TestClient(app, headers={"Authorization": "Bearer " + secret})


def pdf(pages=1, text="Normal schedule document with sufficiently readable text for parsing."):
    with fitz.open() as doc:
        for _ in range(pages):
            doc.new_page().insert_text((50, 50), text)
        return doc.tobytes()


@pytest.mark.parametrize("limit", [0, -1, 61, 999999999, float("inf")])
def test_solver_rejects_extreme_time_limits(client, limit):
    payload = solver_payload()
    payload["solver_options"] = {"time_limit_seconds": limit}
    if limit == float("inf"):
        with pytest.raises(ValidationError):
            SolveAssignmentsRequest.model_validate(payload)
    else:
        assert client.post("/v1/solve-assignments", json=payload).status_code == 422


def test_solver_accepts_the_normal_time_option(client):
    payload = solver_payload()
    payload["solver_options"] = {"time_limit_seconds": 1}
    response = client.post("/v1/solve-assignments", json=payload)
    assert response.status_code == 200
    assert len(response.json()["assignments"]) == 1


def test_solver_rejects_unknown_options_and_oversized_lists(client):
    payload = solver_payload()
    payload["solver_options"] = {"arbitrary_option": 999999999}
    assert client.post("/v1/solve-assignments", json=payload).status_code == 422
    payload.pop("solver_options")
    payload["targets"] *= 1001
    assert client.post("/v1/solve-assignments", json=payload).status_code == 422


def test_solver_bounds_the_candidate_cross_product(client):
    payload = solver_payload()
    payload["supervisors"] *= 200
    payload["occurrences"] *= 501
    assert client.post("/v1/solve-assignments", json=payload).status_code == 422


def test_request_body_limit_rejects_a_large_stream_without_content_length(client):
    def chunks():
        for _ in range(MAX_REQUEST_BYTES // (1024 * 1024) + 1):
            yield b"a" * (1024 * 1024)
    response = client.post("/v1/solve-assignments", content=chunks(), headers={"Content-Type": "application/json"})
    assert response.status_code == 413


def test_pdf_file_limit_is_enforced_during_reads_and_temp_files_are_closed(client, monkeypatch):
    monkeypatch.setattr("app.main.MAX_PDF_BYTES", 128)
    response = client.post("/v1/parse-schedule", files={"file": ("large.pdf", b"x" * 129, "application/pdf")})
    assert response.status_code == 413
    response = client.post("/v1/parse-schedule", files={"file": ("normal.pdf", pdf(), "application/pdf")})
    assert response.status_code == 413


def test_pdf_page_limit_is_checked_before_table_extraction(client):
    response = client.post("/v1/parse-schedule", files={"file": ("pages.pdf", pdf(201), "application/pdf")})
    assert response.status_code == 422
    assert "200" in response.text


def test_pdf_text_limit_is_checked_before_table_extraction(monkeypatch):
    monkeypatch.setattr("app.parsers.schedule_parser.MAX_PDF_TEXT_CHARS", 10)
    with pytest.raises(ResourceLimitError, match="Teks PDF"):
        SchedulePdfParser().parse(pdf(), 0)


def test_normal_pdf_remains_supported(client):
    response = client.post("/v1/parse-schedule", files={"file": ("normal.pdf", pdf(), "application/pdf")})
    assert response.status_code == 200
    assert response.json()["page_count"] == 1


def test_busy_service_rejects_additional_processing_without_queueing(client):
    assert processing_slots.acquire(blocking=False)
    assert processing_slots.acquire(blocking=False)
    try:
        assert client.post("/v1/solve-assignments", json=solver_payload()).status_code == 503
        assert client.post("/v1/parse-schedule", files={"file": ("busy.pdf", pdf(), "application/pdf")}).status_code == 503
    finally:
        processing_slots.release()
        processing_slots.release()
    assert client.post("/v1/solve-assignments", json=solver_payload()).status_code == 200


def test_metadata_is_bounded_and_parser_failure_releases_processing_capacity(client, monkeypatch):
    response = client.post("/v1/parse-schedule", files={"file": ("normal.pdf", pdf(), "application/pdf")}, data={"metadata": "x" * 8193})
    assert response.status_code == 422
    assert client.post("/v1/solve-assignments", json=solver_payload()).status_code == 200


def test_rejected_upload_closes_its_temporary_file(client, monkeypatch):
    from starlette.datastructures import UploadFile
    closed = []
    original = UploadFile.close
    async def close_tracked(file):
        await original(file)
        closed.append((file.filename, file.file.closed))
    monkeypatch.setattr(UploadFile, "close", close_tracked)
    monkeypatch.setattr("app.main.MAX_PDF_BYTES", 128)
    assert client.post("/v1/parse-schedule", files={"file": ("large.pdf", b"x" * 129, "application/pdf")}).status_code == 413
    assert ("large.pdf", True) in closed


def test_parser_exception_releases_the_processing_slot(client, monkeypatch):
    def fail(*args, **kwargs):
        raise ResourceLimitError("Simulated parser resource failure")
    monkeypatch.setattr("app.main.SchedulePdfParser.parse", fail)
    assert client.post("/v1/parse-schedule", files={"file": ("normal.pdf", pdf(), "application/pdf")}).status_code == 422
    assert client.post("/v1/solve-assignments", json=solver_payload()).status_code == 200
