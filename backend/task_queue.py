from __future__ import annotations

import json
import os

from google.cloud import tasks_v2


class TaskQueueClient:
    def __init__(self) -> None:
        self.project_id = os.getenv("GOOGLE_CLOUD_PROJECT", "").strip()
        self.location = os.getenv("CLOUD_TASKS_LOCATION", "us-central1").strip()
        self.queue_name = os.getenv("CLOUD_TASKS_QUEUE", "").strip()
        self.internal_task_secret = os.getenv("INTERNAL_TASK_SECRET", "").strip()
        self.service_account_email = os.getenv("CLOUD_TASKS_SERVICE_ACCOUNT_EMAIL", "").strip()
        self.oidc_audience = os.getenv("CLOUD_TASKS_OIDC_AUDIENCE", "").strip()
        self.client = tasks_v2.CloudTasksClient()

    def enqueue_process_job(self, service_base_url: str, job_id: str) -> None:
        if not self.project_id or not self.queue_name:
            raise RuntimeError("GOOGLE_CLOUD_PROJECT and CLOUD_TASKS_QUEUE are required for task queueing.")

        parent = self.client.queue_path(self.project_id, self.location, self.queue_name)
        url = service_base_url.rstrip("/") + "/internal/process-job"
        headers = {"Content-Type": "application/json"}
        if self.internal_task_secret:
            headers["X-Task-Secret"] = self.internal_task_secret

        http_request: dict = {
            "http_method": tasks_v2.HttpMethod.POST,
            "url": url,
            "headers": headers,
            "body": json.dumps({"job_id": job_id}).encode("utf-8"),
        }

        if self.service_account_email:
            http_request["oidc_token"] = {
                "service_account_email": self.service_account_email,
            }
            if self.oidc_audience:
                http_request["oidc_token"]["audience"] = self.oidc_audience

        self.client.create_task(parent=parent, task={"http_request": http_request})
