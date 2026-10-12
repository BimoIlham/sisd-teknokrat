import os
from pathlib import Path

import uvicorn
from dotenv import dotenv_values


def configure_service_environment(env_path: Path | None = None) -> tuple[str, int]:
    if env_path is None:
        env_path = Path(__file__).resolve().parent.parent / ".env"

    values = dotenv_values(env_path, encoding="utf-8-sig", interpolate=False)
    for key in ("PYTHON_SERVICE_SECRET", "PYTHON_SERVICE_HOST", "PYTHON_SERVICE_PORT"):
        value = values.get(key)
        if value is not None:
            os.environ.setdefault(key, value)

    secret = os.environ.get("PYTHON_SERVICE_SECRET")
    if not secret or not secret.strip():
        raise RuntimeError("Internal service access is not configured.")

    host = os.environ.get("PYTHON_SERVICE_HOST", "127.0.0.1")
    port = int(os.environ.get("PYTHON_SERVICE_PORT", "8100"))
    return host, port


if __name__ == "__main__":
    host, port = configure_service_environment()
    uvicorn.run("app.main:app", host=host, port=port, log_level="info", reload=False, limit_concurrency=8)
