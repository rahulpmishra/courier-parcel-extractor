from __future__ import annotations

import json
import os
import re
import tempfile
import zipfile
from io import BytesIO
from typing import Any

from google.api_core.exceptions import NotFound
from google.cloud import storage


DEFAULT_USAGE_KEY = "config/api_usage.json"


def _clean_path_part(value: str, fallback: str) -> str:
    value = re.sub(r"[^A-Za-z0-9._-]+", "_", value).strip("._")
    return value or fallback


class CloudStorageBackend:
    def __init__(self, bucket_name: str | None = None) -> None:
        bucket_name = (bucket_name or os.getenv("GCS_BUCKET_NAME", "")).strip()
        if not bucket_name:
            raise RuntimeError("GCS_BUCKET_NAME is required for backend storage.")

        self.client = storage.Client()
        self.bucket = self.client.bucket(bucket_name)

    def upload_bytes(self, key: str, data: bytes, content_type: str = "application/octet-stream") -> None:
        blob = self.bucket.blob(key)
        blob.upload_from_string(data, content_type=content_type)

    def download_bytes(self, key: str) -> bytes:
        blob = self.bucket.blob(key)
        try:
            return blob.download_as_bytes()
        except NotFound as exc:
            raise FileNotFoundError(key) from exc

    def delete_blob(self, key: str) -> None:
        blob = self.bucket.blob(key)
        try:
            blob.delete()
        except NotFound:
            return

    def save_json(self, key: str, payload: Any) -> None:
        self.upload_bytes(
            key,
            json.dumps(payload, indent=2, ensure_ascii=False).encode("utf-8"),
            content_type="application/json",
        )

    def load_json(self, key: str, default: Any | None = None) -> Any:
        try:
            data = self.download_bytes(key)
        except FileNotFoundError:
            return default

        try:
            return json.loads(data.decode("utf-8"))
        except json.JSONDecodeError:
            return default

    def save_job_status(self, job_id: str, payload: dict) -> None:
        self.save_json(self.job_status_key(job_id), payload)

    def load_job_status(self, job_id: str) -> dict:
        data = self.load_json(self.job_status_key(job_id))
        if not isinstance(data, dict):
            raise FileNotFoundError(f"Job status not found for {job_id}.")
        return data

    def save_job_result(self, job_id: str, rows: list[dict]) -> None:
        self.save_json(self.job_result_key(job_id), rows)

    def load_job_result(self, job_id: str) -> list[dict]:
        data = self.load_json(self.job_result_key(job_id))
        if not isinstance(data, list):
            raise FileNotFoundError(f"Job result not found for {job_id}.")
        return data

    def load_usage_state(self, run_date: str, default_daily_budget: int) -> dict:
        data = self.load_json(DEFAULT_USAGE_KEY, default={})
        if not isinstance(data, dict):
            data = {}

        daily_budget = _normalize_daily_budget(data.get("daily_budget", default_daily_budget), default_daily_budget)
        stored_date = str(data.get("date", "")).strip()
        requests_used = _normalize_requests_used(data.get("requests_used", 0), daily_budget)

        if stored_date != run_date:
            requests_used = 0

        snapshot = {
            "date": run_date,
            "daily_budget": daily_budget,
            "requests_used": requests_used,
            "requests_remaining": max(daily_budget - requests_used, 0),
        }
        self.save_usage_state(snapshot)
        return snapshot

    def save_usage_state(self, snapshot: dict) -> dict:
        normalized = {
            "date": str(snapshot.get("date", "")).strip(),
            "daily_budget": _normalize_daily_budget(snapshot.get("daily_budget", 0), 0),
            "requests_used": 0,
            "requests_remaining": 0,
        }
        normalized["requests_used"] = _normalize_requests_used(
            snapshot.get("requests_used", 0),
            normalized["daily_budget"],
        )
        normalized["requests_remaining"] = max(normalized["daily_budget"] - normalized["requests_used"], 0)
        self.save_json(DEFAULT_USAGE_KEY, normalized)
        return normalized

    def save_job_input_bundle(self, job_id: str, files: list[dict]) -> list[dict]:
        manifest_files: list[dict] = []

        with tempfile.NamedTemporaryFile(suffix=".zip", delete=False) as temp_file:
            temp_path = temp_file.name

        try:
            with zipfile.ZipFile(temp_path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                for index, file in enumerate(files):
                    original_name = str(file.get("original_name", "")).strip() or f"image_{index + 1}.jpg"
                    relative_path = str(file.get("relative_path", original_name)).strip() or original_name
                    file_sender = str(file.get("sender", "")).strip()
                    content_type = str(file.get("content_type", "")).strip() or "application/octet-stream"
                    archive_name = f"files/{index:03d}_{_clean_path_part(original_name, f'image_{index + 1}.jpg')}"
                    archive.writestr(archive_name, file.get("content", b""))

                    manifest_files.append(
                        {
                            "original_name": original_name,
                            "relative_path": relative_path,
                            "sender": file_sender,
                            "content_type": content_type,
                            "bundle_member_name": archive_name,
                        }
                    )

                archive.writestr(
                    "manifest.json",
                    json.dumps({"files": manifest_files}, indent=2, ensure_ascii=False),
                )

            with open(temp_path, "rb") as bundle_file:
                self.upload_bytes(self.job_input_bundle_key(job_id), bundle_file.read(), "application/zip")
        finally:
            if os.path.exists(temp_path):
                os.unlink(temp_path)

        return manifest_files

    def load_job_input_bundle(self, job_id: str) -> list[dict]:
        data = self.download_bytes(self.job_input_bundle_key(job_id))

        with zipfile.ZipFile(BytesIO(data), "r") as archive:
            manifest_raw = archive.read("manifest.json")
            manifest = json.loads(manifest_raw.decode("utf-8"))
            files = manifest.get("files", []) if isinstance(manifest, dict) else []

            output_files: list[dict] = []
            for file in files:
                if not isinstance(file, dict):
                    continue

                member_name = str(file.get("bundle_member_name", "")).strip()
                if not member_name:
                    continue

                output_files.append(
                    {
                        "file_name": str(file.get("original_name", "")).strip(),
                        "relative_path": str(file.get("relative_path", "")).strip(),
                        "sender": str(file.get("sender", "")).strip(),
                        "content_type": str(file.get("content_type", "")).strip() or "application/octet-stream",
                        "image_bytes": archive.read(member_name),
                    }
                )

        return output_files

    def delete_job_input_bundle(self, job_id: str) -> None:
        self.delete_blob(self.job_input_bundle_key(job_id))

    @staticmethod
    def job_status_key(job_id: str) -> str:
        return f"jobs/{job_id}/status.json"

    @staticmethod
    def job_result_key(job_id: str) -> str:
        return f"jobs/{job_id}/result.json"

    @staticmethod
    def job_input_bundle_key(job_id: str) -> str:
        return f"jobs/{job_id}/input_bundle.zip"


def _normalize_daily_budget(value: Any, fallback: int) -> int:
    try:
        budget = int(value)
    except (TypeError, ValueError):
        budget = fallback

    return budget if budget > 0 else fallback


def _normalize_requests_used(value: Any, daily_budget: int) -> int:
    try:
        used = int(value)
    except (TypeError, ValueError):
        used = 0

    return max(0, min(used, max(daily_budget, 0)))
