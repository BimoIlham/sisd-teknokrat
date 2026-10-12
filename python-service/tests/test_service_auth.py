import secrets

import pytest
from fastapi import HTTPException
from fastapi.testclient import TestClient
from app.main import app, verify_token


@pytest.fixture
def configured_service(monkeypatch):
    secret = secrets.token_urlsafe(32)
    monkeypatch.setenv("PYTHON_SERVICE_SECRET", secret)
    return TestClient(app), secret


def solver_payload():
    return {
        "request_id": "auth-regression", "input_revision": 1, "input_hash": "test-input",
        "period_id": 1, "stage_id": 1, "stage_no": 1,
        "date_range": {"starts_on": "2026-10-01", "ends_on": "2026-10-31"},
        "rules": {}, "targets": [{"id": 1, "name": "Target"}],
        "supervisors": [{"id": 2, "name": "Supervisor"}],
        "occurrences": [{"id": 10, "target_id": 1, "occurs_on": "2026-10-05",
            "starts_at": "2026-10-05T02:00:00Z", "ends_at": "2026-10-05T03:40:00Z",
            "course_name": "Course", "class_name": "Class"}],
    }


def send(client, endpoint, headers):
    if endpoint == "solver":
        return client.post("/v1/solve-assignments", json=solver_payload(), headers=headers)
    return client.post("/v1/parse-schedule", files={"file": ("test.pdf", b"test", "application/pdf")}, headers=headers)


@pytest.mark.parametrize("endpoint", ["parser", "solver"])
def test_missing_secret_rejects_requests(monkeypatch, endpoint):
    monkeypatch.delenv("PYTHON_SERVICE_SECRET", raising=False)
    response = send(TestClient(app), endpoint, {"Authorization": "Bearer " + secrets.token_urlsafe(32)})
    assert response.status_code == 503


@pytest.mark.parametrize("endpoint", ["parser", "solver"])
@pytest.mark.parametrize("header_kind", ["missing", "wrong", "legacy"])
def test_invalid_credentials_are_rejected(configured_service, endpoint, header_kind):
    client, secret = configured_service
    headers = {} if header_kind == "missing" else ({"X-Service-Token": secret} if header_kind == "legacy" else {"Authorization": "Bearer " + secrets.token_urlsafe(32)})
    response = send(client, endpoint, headers)
    assert response.status_code == 401
    assert secret not in response.text


def test_correct_bearer_secret_allows_solver(configured_service):
    client, secret = configured_service
    response = send(client, "solver", {"Authorization": "Bearer " + secret})
    assert response.status_code == 200
    assert response.json()["assignments"] == [{"target_id": 1, "supervisor_id": 2, "occurrence_id": 10}]
    assert secret not in response.text


def test_correct_bearer_secret_allows_parser(configured_service, monkeypatch):
    client, secret = configured_service
    monkeypatch.setattr("app.main.SchedulePdfParser.parse", lambda *args, **kwargs: {
        "contract_version": "1.0", "request_id": "auth-regression", "import_id": 0,
        "parser": {"name": "test", "version": "1.1.0"}, "page_count": 1, "rows": [],
    })
    response = send(client, "parser", {"Authorization": "Bearer " + secret})
    assert response.status_code == 200
    assert response.json()["parser"]["version"] == "1.1.0"
    assert secret not in response.text


def test_whitespace_secret_is_not_a_configuration(monkeypatch):
    monkeypatch.setenv("PYTHON_SERVICE_SECRET", "   ")
    with pytest.raises(HTTPException) as captured:
        verify_token("Bearer test")
    assert captured.value.status_code == 503


def test_non_ascii_token_is_rejected_safely(configured_service):
    with pytest.raises(HTTPException) as captured:
        verify_token("Bearer \u00e9")
    assert captured.value.status_code == 401


def test_invalid_solver_payload_does_not_bypass_authentication(configured_service):
    client, _ = configured_service
    response = client.post("/v1/solve-assignments", json={}, headers={"Authorization": "Bearer " + secrets.token_urlsafe(32)})
    assert response.status_code == 401


def test_missing_secret_rejects_even_an_invalid_solver_payload(monkeypatch):
    monkeypatch.delenv("PYTHON_SERVICE_SECRET", raising=False)
    response = TestClient(app).post("/v1/solve-assignments", json={})
    assert response.status_code == 503
    assert response.json()["detail"]["code"] == "SERVICE_ACCESS_NOT_CONFIGURED"
