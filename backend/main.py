from __future__ import annotations

import os
import re
import time
from datetime import datetime, timedelta, timezone, tzinfo
from functools import lru_cache
from typing import Annotated
from uuid import uuid4
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from fastapi import FastAPI, Header, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse, Response
from pydantic import BaseModel

from csv_utils import rows_to_csv_bytes
from extractor import BatchProcessingResult, JobCanceledError, ParcelExtractor, UploadedImage
from storage import CloudStorageBackend
from task_queue import TaskQueueClient


app = FastAPI(title="Parcel Extractor Backend", version="1.0.0")


class JobProcessRequest(BaseModel):
    job_id: str


class ProgressState:
    def __init__(self, storage_backend: CloudStorageBackend, job_id: str, job_state: dict) -> None:
        self.storage_backend = storage_backend
        self.job_id = job_id
        self.job_state = job_state
        self.last_saved_progress = int(job_state.get("progress", 0))
        self.last_saved_at = time.time()

    def __call__(self, progress: int, message: str) -> None:
        now = time.time()
        live_status = ""
        try:
            live_state = self.storage_backend.load_job_status(self.job_id)
            live_status = str(live_state.get("status", "")).strip().lower()
        except FileNotFoundError:
            live_status = ""

        canceling = live_status == "canceling"
        self.job_state["status"] = "canceling" if canceling else "processing"
        self.job_state["progress"] = progress
        self.job_state["message"] = (
            "Cancel requested. Waiting for the worker to release this run."
            if canceling
            else message
        )
        self.job_state["updated_at"] = int(now)

        should_save = (
            progress >= 100
            or progress - self.last_saved_progress >= 5
            or now - self.last_saved_at >= 10
        )
        if should_save:
            self.storage_backend.save_job_status(self.job_id, self.job_state)
            self.last_saved_progress = progress
            self.last_saved_at = now


def is_job_canceled(storage_backend: CloudStorageBackend, job_id: str) -> bool:
    try:
        job_state = storage_backend.load_job_status(job_id)
    except FileNotFoundError:
        return False

    return str(job_state.get("status", "")).strip().lower() in {"canceled", "canceling"}


@lru_cache(maxsize=1)
def get_storage_backend() -> CloudStorageBackend:
    return CloudStorageBackend()


@lru_cache(maxsize=1)
def get_task_queue() -> TaskQueueClient:
    return TaskQueueClient()


@lru_cache(maxsize=1)
def get_app_timezone() -> tzinfo:
    timezone_name = os.getenv("APP_TIMEZONE", "Asia/Calcutta").strip() or "Asia/Calcutta"
    try:
        return ZoneInfo(timezone_name)
    except ZoneInfoNotFoundError:
        return timezone(timedelta(hours=5, minutes=30), name="Asia/Calcutta")


def current_business_datetime() -> datetime:
    return datetime.now(get_app_timezone())


def current_business_date() -> str:
    return current_business_datetime().date().isoformat()


def current_timestamp() -> int:
    return int(time.time())


def build_job_id() -> str:
    return "job_" + current_business_datetime().strftime("%Y%m%d_%H%M%S") + "_" + uuid4().hex[:8]


def sanitize_download_name(value: str, fallback: str) -> str:
    value = re.sub(r"[^\w\-\. ]+", "_", value).strip(" .\t\n\r\0\x0B")
    return value or fallback


def service_base_url_from_request(request: Request) -> str:
    configured_base_url = os.getenv("SERVICE_BASE_URL", "").strip()
    if configured_base_url:
        return configured_base_url.rstrip("/")

    forwarded_proto = request.headers.get("x-forwarded-proto", "").strip()
    host = request.headers.get("host", "").strip()
    if forwarded_proto and host:
        return f"{forwarded_proto}://{host}".rstrip("/")

    return str(request.base_url).rstrip("/")


def verify_internal_task_secret(x_task_secret: str | None) -> None:
    expected = os.getenv("INTERNAL_TASK_SECRET", "").strip()
    if expected and x_task_secret != expected:
        raise HTTPException(status_code=403, detail="Invalid internal task secret.")


def verify_app_shared_secret(x_app_key: str | None) -> None:
    expected = os.getenv("APP_SHARED_SECRET", "").strip()
    if expected and x_app_key != expected:
        raise HTTPException(status_code=403, detail="Invalid application key.")


def build_public_job_payload(job_state: dict) -> dict:
    return {
        "job_id": job_state.get("job_id", ""),
        "sender": job_state.get("sender", ""),
        "status": job_state.get("status", "unknown"),
        "progress": int(job_state.get("progress", 0)),
        "message": job_state.get("message", ""),
        "file_count": int(job_state.get("file_count", 0)),
        "created_at": int(job_state.get("created_at", 0)),
        "updated_at": int(job_state.get("updated_at", 0)),
        "files": [
            {
                "original_name": file.get("original_name", ""),
                "relative_path": file.get("relative_path", ""),
                "sender": file.get("sender", ""),
            }
            for file in job_state.get("files", [])
            if isinstance(file, dict)
        ],
    }


def parse_indexed_field(key: str, base_name: str) -> int | None:
    if key == base_name:
        return 0

    match = re.fullmatch(rf"{re.escape(base_name)}\[(\d+)\]", key)
    if match:
        return int(match.group(1))

    if key == f"{base_name}[]":
        return 0

    return None


def normalize_incoming_files(
    sender: str,
    images: list[UploadFile],
    relative_paths: list[str],
    file_senders: list[str],
) -> list[dict]:
    normalized_files: list[dict] = []

    for index, image in enumerate(images):
        original_name = (image.filename or "").strip() or f"image_{index + 1}.jpg"
        relative_path = (
            relative_paths[index].strip()
            if index < len(relative_paths) and relative_paths[index].strip()
            else original_name
        )
        per_file_sender = (
            file_senders[index].strip()
            if index < len(file_senders) and file_senders[index].strip()
            else sender
        )
        normalized_files.append(
            {
                "original_name": original_name,
                "relative_path": relative_path,
                "sender": per_file_sender,
                "content_type": image.content_type or "application/octet-stream",
                "_upload_file": image,
            }
        )

    return normalized_files


async def parse_job_form(request: Request) -> tuple[str, list[dict]]:
    form = await request.form()
    sender = str(form.get("sender", "")).strip()

    file_entries: list[tuple[int, UploadFile]] = []
    relative_path_map: dict[int, str] = {}
    sender_map: dict[int, str] = {}

    fallback_file_index = 0
    fallback_relative_index = 0
    fallback_sender_index = 0

    for key, value in form.multi_items():
        if hasattr(value, "filename") and hasattr(value, "read"):
            parsed_index = parse_indexed_field(key, "images")
            if parsed_index is None:
                continue

            if key in {"images", "images[]"}:
                parsed_index = fallback_file_index
                fallback_file_index += 1

            file_entries.append((parsed_index, value))
            continue

        if not isinstance(value, str):
            continue

        relative_index = parse_indexed_field(key, "relative_paths")
        if relative_index is not None:
            if key in {"relative_paths", "relative_paths[]"}:
                relative_index = fallback_relative_index
                fallback_relative_index += 1
            relative_path_map[relative_index] = value
            continue

        sender_index = parse_indexed_field(key, "file_senders")
        if sender_index is not None:
            if key in {"file_senders", "file_senders[]"}:
                sender_index = fallback_sender_index
                fallback_sender_index += 1
            sender_map[sender_index] = value

    file_entries.sort(key=lambda item: item[0])
    images = [entry[1] for entry in file_entries]
    relative_paths = [relative_path_map.get(index, "") for index, _entry in file_entries]
    file_senders = [sender_map.get(index, "") for index, _entry in file_entries]
    return sender, normalize_incoming_files(sender, images, relative_paths, file_senders)


def process_job(job_id: str) -> dict:
    storage_backend = get_storage_backend()
    job_state = storage_backend.load_job_status(job_id)
    reporter = ProgressState(storage_backend, job_id, job_state)

    try:
        if str(job_state.get("status", "")).strip().lower() in {"canceled", "canceling"}:
            storage_backend.delete_job_input_bundle(job_id)
            job_state["message"] = "Run was canceled before processing started."
            job_state["status"] = "canceled"
            job_state["updated_at"] = current_timestamp()
            storage_backend.save_job_status(job_id, job_state)
            return build_public_job_payload(job_state)

        uploaded_files = storage_backend.load_job_input_bundle(job_id)
        uploaded_images = [
            UploadedImage(
                file_name=file.get("file_name", ""),
                source_path=file.get("relative_path", "") or file.get("file_name", ""),
                sender=file.get("sender", "") or str(job_state.get("sender", "")),
                image_bytes=file.get("image_bytes", b""),
            )
            for file in uploaded_files
        ]

        reporter(10, f"Loaded {len(uploaded_images)} files. Starting extraction.")
        extractor = ParcelExtractor(storage_backend)
        result: BatchProcessingResult = extractor.process_batch(
            uploaded_images,
            status_callback=reporter,
            cancellation_checker=lambda: is_job_canceled(storage_backend, job_id),
        )
        storage_backend.save_job_result(job_id, result.rows)
        storage_backend.delete_job_input_bundle(job_id)

        job_state["status"] = "completed"
        job_state["progress"] = 100
        job_state["message"] = "Processing complete. Download actions are ready."
        job_state["updated_at"] = current_timestamp()
        job_state["leftover_files"] = result.leftover_files
        job_state["usage"] = result.usage
        storage_backend.save_job_status(job_id, job_state)
        return build_public_job_payload(job_state)
    except JobCanceledError:
        storage_backend.delete_job_input_bundle(job_id)
        job_state["status"] = "canceled"
        job_state["message"] = "Run canceled. Worker slot released."
        job_state["updated_at"] = current_timestamp()
        storage_backend.save_job_status(job_id, job_state)
        return build_public_job_payload(job_state)
    except Exception as exc:
        storage_backend.delete_job_input_bundle(job_id)
        job_state["status"] = "failed"
        job_state["message"] = str(exc)
        job_state["updated_at"] = current_timestamp()
        storage_backend.save_job_status(job_id, job_state)
        raise


@app.get("/")
def root() -> dict:
    return {"ok": True, "service": "parcel-extractor-backend"}


@app.get("/usage/today")
def usage_today(
    x_app_key: Annotated[str | None, Header(alias="X-App-Key")] = None,
) -> dict:
    verify_app_shared_secret(x_app_key)
    storage_backend = get_storage_backend()
    return storage_backend.load_usage_state(
        current_business_date(),
        int(os.getenv("DAILY_REQUEST_BUDGET", "300")),
    )


@app.post("/jobs")
async def create_job(
    request: Request,
    x_app_key: Annotated[str | None, Header(alias="X-App-Key")] = None,
) -> dict:
    verify_app_shared_secret(x_app_key)
    sender, normalized_files = await parse_job_form(request)
    if not sender:
        raise HTTPException(status_code=400, detail="Sender is required.")

    if not normalized_files:
        raise HTTPException(status_code=400, detail="At least one image is required.")
    for file in normalized_files:
        upload_file = file.pop("_upload_file", None)
        if upload_file is None:
            raise HTTPException(status_code=400, detail="Uploaded file payload is invalid.")
        file["content"] = await upload_file.read()

    job_id = build_job_id()
    storage_backend = get_storage_backend()
    manifest_files = storage_backend.save_job_input_bundle(job_id, normalized_files)

    created_at = current_timestamp()
    job_state = {
        "job_id": job_id,
        "sender": sender,
        "status": "queued",
        "progress": 5,
        "message": "Job created and queued for processing.",
        "file_count": len(manifest_files),
        "files": [
            {
                "original_name": file.get("original_name", ""),
                "relative_path": file.get("relative_path", ""),
                "sender": file.get("sender", ""),
            }
            for file in manifest_files
        ],
        "created_at": created_at,
        "updated_at": created_at,
    }

    storage_backend.save_job_status(job_id, job_state)

    try:
        base_url = service_base_url_from_request(request)
        get_task_queue().enqueue_process_job(base_url, job_id)
    except Exception as exc:
        storage_backend.delete_job_input_bundle(job_id)
        storage_backend.delete_blob(storage_backend.job_status_key(job_id))
        raise HTTPException(status_code=500, detail=str(exc)) from exc

    return build_public_job_payload(job_state)


@app.post("/internal/process-job")
def process_job_endpoint(
    payload: JobProcessRequest,
    x_task_secret: Annotated[str | None, Header(alias="X-Task-Secret")] = None,
) -> dict:
    verify_internal_task_secret(x_task_secret)
    process_job(payload.job_id)
    return {"ok": True, "job_id": payload.job_id}


@app.get("/jobs/{job_id}")
def get_job(
    job_id: str,
    x_app_key: Annotated[str | None, Header(alias="X-App-Key")] = None,
) -> dict:
    verify_app_shared_secret(x_app_key)
    storage_backend = get_storage_backend()

    try:
        job_state = storage_backend.load_job_status(job_id)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail="Job not found.") from exc

    return build_public_job_payload(job_state)


@app.post("/jobs/{job_id}/cancel")
def cancel_job(
    job_id: str,
    x_app_key: Annotated[str | None, Header(alias="X-App-Key")] = None,
) -> dict:
    verify_app_shared_secret(x_app_key)
    storage_backend = get_storage_backend()

    try:
        job_state = storage_backend.load_job_status(job_id)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail="Job not found.") from exc

    status = str(job_state.get("status", "")).strip().lower()
    if status in {"completed", "failed", "canceled"}:
        return build_public_job_payload(job_state)

    if status == "queued":
        job_state["status"] = "canceled"
        job_state["message"] = "Run was canceled before processing started."
    else:
        job_state["status"] = "canceling"
        job_state["message"] = "Cancel requested. Waiting for the worker to release this run."

    job_state["updated_at"] = current_timestamp()
    storage_backend.save_job_status(job_id, job_state)
    return build_public_job_payload(job_state)


@app.get("/jobs/{job_id}/download/json")
def download_job_json(
    job_id: str,
    x_app_key: Annotated[str | None, Header(alias="X-App-Key")] = None,
) -> JSONResponse:
    verify_app_shared_secret(x_app_key)
    storage_backend = get_storage_backend()

    try:
        job_state = storage_backend.load_job_status(job_id)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail="Job not found.") from exc

    if job_state.get("status") != "completed":
        raise HTTPException(status_code=409, detail="Job output is not ready yet.")

    try:
        rows = storage_backend.load_job_result(job_id)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail="Job result not found.") from exc

    return JSONResponse(content=rows)


@app.get("/jobs/{job_id}/download/csv")
def download_job_csv(
    job_id: str,
    x_app_key: Annotated[str | None, Header(alias="X-App-Key")] = None,
) -> Response:
    verify_app_shared_secret(x_app_key)
    storage_backend = get_storage_backend()

    try:
        job_state = storage_backend.load_job_status(job_id)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail="Job not found.") from exc

    if job_state.get("status") != "completed":
        raise HTTPException(status_code=409, detail="Job output is not ready yet.")

    try:
        rows = storage_backend.load_job_result(job_id)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail="Job result not found.") from exc

    sender_name = sanitize_download_name(str(job_state.get("sender", "")).strip(), "output")
    csv_bytes = rows_to_csv_bytes(rows)
    headers = {
        "Content-Disposition": f'attachment; filename="{sender_name}.csv"',
    }
    return Response(content=csv_bytes, media_type="text/csv; charset=UTF-8", headers=headers)
