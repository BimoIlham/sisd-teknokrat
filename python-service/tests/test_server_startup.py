import hmac
import os
import secrets

import pytest

from run_server import configure_service_environment


@pytest.fixture(autouse=True)
def clear_service_environment(monkeypatch):
    for key in ("PYTHON_SERVICE_SECRET", "PYTHON_SERVICE_HOST", "PYTHON_SERVICE_PORT"):
        monkeypatch.delenv(key, raising=False)


def test_startup_loads_only_service_settings_from_local_env(tmp_path, monkeypatch):
    secret = secrets.token_hex(32)
    env_path = tmp_path / ".env"
    env_path.write_text(
        f"PYTHON_SERVICE_SECRET={secret}\n"
        "PYTHON_SERVICE_HOST=127.0.0.1\n"
        "PYTHON_SERVICE_PORT=18100\n"
        "APP_KEY=test-application-key\n"
        "DB_PASSWORD=test-database-password\n",
        encoding="utf-8",
    )
    monkeypatch.delenv("APP_KEY", raising=False)
    monkeypatch.delenv("DB_PASSWORD", raising=False)

    assert configure_service_environment(env_path) == ("127.0.0.1", 18100)
    assert hmac.compare_digest(os.environ["PYTHON_SERVICE_SECRET"], secret)
    assert "APP_KEY" not in os.environ
    assert "DB_PASSWORD" not in os.environ


def test_explicit_process_environment_takes_precedence(tmp_path, monkeypatch):
    env_path = tmp_path / ".env"
    env_path.write_text(
        f"PYTHON_SERVICE_SECRET={secrets.token_hex(32)}\n"
        "PYTHON_SERVICE_HOST=127.0.0.1\n"
        "PYTHON_SERVICE_PORT=18100\n",
        encoding="utf-8",
    )
    secret = secrets.token_hex(32)
    monkeypatch.setenv("PYTHON_SERVICE_SECRET", secret)
    monkeypatch.setenv("PYTHON_SERVICE_HOST", "127.0.0.2")
    monkeypatch.setenv("PYTHON_SERVICE_PORT", "18101")

    assert configure_service_environment(env_path) == ("127.0.0.2", 18101)
    assert hmac.compare_digest(os.environ["PYTHON_SERVICE_SECRET"], secret)


def test_startup_defaults_to_loopback_when_only_secret_is_configured(tmp_path):
    env_path = tmp_path / ".env"
    env_path.write_text(f"PYTHON_SERVICE_SECRET={secrets.token_hex(32)}\n", encoding="utf-8")

    assert configure_service_environment(env_path) == ("127.0.0.1", 8100)


@pytest.mark.parametrize("value", [None, "", "   "])
def test_startup_refuses_missing_or_blank_secret(tmp_path, value):
    env_path = tmp_path / ".env"
    if value is not None:
        env_path.write_text(f'PYTHON_SERVICE_SECRET="{value}"\n', encoding="utf-8")

    with pytest.raises(RuntimeError, match="^Internal service access is not configured\\.$"):
        configure_service_environment(env_path)


def test_empty_process_secret_does_not_fall_back_to_env_file(tmp_path, monkeypatch):
    env_path = tmp_path / ".env"
    env_path.write_text(f"PYTHON_SERVICE_SECRET={secrets.token_hex(32)}\n", encoding="utf-8")
    monkeypatch.setenv("PYTHON_SERVICE_SECRET", "")

    with pytest.raises(RuntimeError, match="^Internal service access is not configured\\.$"):
        configure_service_environment(env_path)


def test_environment_is_not_interpolated_from_other_application_settings(tmp_path, monkeypatch):
    env_path = tmp_path / ".env"
    env_path.write_text(
        "APP_KEY=test-application-key\nPYTHON_SERVICE_SECRET=${APP_KEY}\n",
        encoding="utf-8",
    )
    monkeypatch.delenv("APP_KEY", raising=False)

    configure_service_environment(env_path)

    assert os.environ["PYTHON_SERVICE_SECRET"] == "${APP_KEY}"
    assert "APP_KEY" not in os.environ


def test_utf8_bom_env_file_is_supported(tmp_path):
    secret = secrets.token_hex(32)
    env_path = tmp_path / ".env"
    env_path.write_text(f"PYTHON_SERVICE_SECRET={secret}\n", encoding="utf-8-sig")

    assert configure_service_environment(env_path) == ("127.0.0.1", 8100)
    assert hmac.compare_digest(os.environ["PYTHON_SERVICE_SECRET"], secret)


def test_default_env_location_is_relative_to_launcher_instead_of_working_directory(tmp_path, monkeypatch):
    secret = secrets.token_hex(32)
    env_path = tmp_path / ".env"
    env_path.write_text(f"PYTHON_SERVICE_SECRET={secret}\n", encoding="utf-8")
    service_directory = tmp_path / "python-service"
    service_directory.mkdir()
    unrelated_directory = tmp_path / "unrelated"
    unrelated_directory.mkdir()
    monkeypatch.setattr("run_server.__file__", str(service_directory / "run_server.py"))
    monkeypatch.chdir(unrelated_directory)

    assert configure_service_environment() == ("127.0.0.1", 8100)
    assert hmac.compare_digest(os.environ["PYTHON_SERVICE_SECRET"], secret)


def test_process_configuration_works_without_a_local_env_file(tmp_path, monkeypatch):
    secret = secrets.token_hex(32)
    monkeypatch.setenv("PYTHON_SERVICE_SECRET", secret)

    assert configure_service_environment(tmp_path / "missing.env") == ("127.0.0.1", 8100)
    assert hmac.compare_digest(os.environ["PYTHON_SERVICE_SECRET"], secret)
