# Architecture

## Frontend

The frontend is a PHP control desk that:

- authenticates the operator
- submits parcel batches
- polls job status
- downloads batch CSV/JSON
- merges completed rows into a persistent master store
- records recent activity

It is intended for shared hosting and keeps runtime state under `frontend/storage`.

## Backend

The backend is a Python service deployed to Cloud Run that:

- creates jobs
- stores job state in Cloud Storage
- dispatches processing via Cloud Tasks
- runs Gemini extraction
- writes output JSON/CSV
- exposes usage information

## Processing model

- one backend worker is intended to run at a time
- Cloud Tasks queue is restricted to single-dispatch behavior
- new jobs can queue behind an already-running job
- cancel is cooperative, not a hard kill

## Data flow

1. User submits images from frontend
2. Frontend creates a backend job
3. Backend stores job input and enqueues processing
4. Worker processes parcel images and updates job state
5. Frontend reads status and shows progress
6. User downloads batch data or adds verified rows to master store
