import json
import os
import secrets
from typing import Optional
from threading import BoundedSemaphore
from starlette.concurrency import run_in_threadpool
from starlette.middleware.body_limit import RequestBodyLimitMiddleware
from app.resource_limits import MAX_PDF_BYTES, MAX_REQUEST_BYTES
from fastapi import FastAPI, UploadFile, File, Form, Header, Depends, HTTPException, status
from fastapi.responses import JSONResponse

from app.contracts.parse_schemas import ParseScheduleResponse
from app.contracts.solver_schemas import SolveAssignmentsRequest, SolveAssignmentsResponse
from app.parsers.schedule_parser import SchedulePdfParser, ResourceLimitError
from app.scheduler.cp_sat_solver import CpSatScheduleSolver

app = FastAPI(title="SISD Python Service", version="1.0.0")
app.add_middleware(RequestBodyLimitMiddleware, max_body_size=MAX_REQUEST_BYTES)
processing_slots = BoundedSemaphore(2)

def verify_token(auth_header: Optional[str] = Header(None, alias="Authorization")) -> None:
    secret = os.environ.get("PYTHON_SERVICE_SECRET")
    if not secret or not secret.strip():
        raise HTTPException(status_code=status.HTTP_503_SERVICE_UNAVAILABLE, detail={"code": "SERVICE_ACCESS_NOT_CONFIGURED", "message": "Internal service access is not configured"})
    if not auth_header:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing Authorization header")
    parts = auth_header.split()
    if len(parts) != 2 or parts[0].lower() != "bearer" or not secrets.compare_digest(parts[1].encode("utf-8"), secret.encode("utf-8")):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid internal service token")

@app.get("/health")
def health():
    return {"status": "ok", "service": "sisd-python-service", "version": "1.0.0"}

@app.post("/v1/parse-schedule", response_model=ParseScheduleResponse, dependencies=[Depends(verify_token)])
async def parse_schedule(
    file: UploadFile = File(...),
    metadata: Optional[str] = Form(None),
    x_request_id: Optional[str] = Header(None)
):
    if not processing_slots.acquire(blocking=False):
        await file.close()
        raise HTTPException(status_code=503, detail={"code": "RESOURCE_BUSY", "message": "Service sedang memproses pekerjaan lain. Coba kembali sebentar lagi."})
    try:
        if not (file.filename or "").lower().endswith(".pdf"):
            raise HTTPException(status_code=422, detail="Berkas harus bertipe PDF.")
        contents = bytearray()
        while chunk := await file.read(min(1024 * 1024, MAX_PDF_BYTES + 1 - len(contents))):
            contents.extend(chunk)
            if len(contents) > MAX_PDF_BYTES:
                raise HTTPException(status_code=413, detail="Ukuran PDF melebihi batas 20 MB.")
        parsed_meta = {}
        if metadata:
            if len(metadata) > 8192:
                raise HTTPException(status_code=422, detail="Metadata terlalu panjang.")
            try:
                parsed_meta = json.loads(metadata)
                if not isinstance(parsed_meta, dict):
                    raise ValueError()
            except (ValueError, TypeError):
                raise HTTPException(status_code=422, detail="Metadata harus berupa objek JSON.")
        parser = SchedulePdfParser()
        try:
            return await run_in_threadpool(parser.parse, pdf_bytes=bytes(contents), import_id=parsed_meta.get("import_id", 0), request_id=x_request_id, metadata=parsed_meta)
        except ResourceLimitError as error:
            raise HTTPException(status_code=422, detail=str(error)) from error
    finally:
        try:
            await file.close()
        finally:
            processing_slots.release()

@app.post("/v1/solve-assignments", response_model=SolveAssignmentsResponse, dependencies=[Depends(verify_token)])
def solve_assignments(
    req: SolveAssignmentsRequest
):
    if not processing_slots.acquire(blocking=False):
        raise HTTPException(status_code=503, detail={"code": "RESOURCE_BUSY", "message": "Service sedang memproses pekerjaan lain. Coba kembali sebentar lagi."})
    try:
        return CpSatScheduleSolver().solve(req)
    finally:
        processing_slots.release()
