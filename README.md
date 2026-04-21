# Courier Parcel Extractor

AI-powered courier parcel extraction dashboard that converts parcel images into validated CSV/JSON records using Gemini, FastAPI, PHP, and Google Cloud.

## Why It Matters

Courier teams often spend significant time manually reading parcel photos, typing receiver details, checking AWB numbers, validating phone/PIN values, and preparing CSV reports. This project automates that workflow while keeping an operator in control of review, downloads, duplicate handling, and master data updates.

It demonstrates practical AI application engineering across multimodal extraction, backend job processing, validation logic, cloud deployment, and a production-style operations dashboard.

## Demo

https://github.com/user-attachments/assets/1440788a-575a-4987-9098-58d17fcf8d55

## What It Does

- Uploads parcel image batches from a web dashboard
- Extracts receiver, address, PIN, phone, and AWB details using Gemini
- Validates important courier fields such as PIN, phone number, and AWB
- Infers city values using a local pincode map
- Tracks batch progress with live job monitoring
- Supports cancel, retry, download, and completed-run cleanup flows
- Exports structured CSV and JSON outputs
- Stores verified rows in a persistent daily master store
- Logs operational events such as login, batch processing, downloads, resets, and cancellations

## System Design

### Frontend Dashboard

The `frontend/` folder contains a PHP dashboard designed for local XAMPP testing and HostGator shared-hosting deployment. It handles operator login, batch submission, job status screens, CSV/JSON downloads, daily master records, duplicate handling, and activity visibility.

### Backend Extraction Service

The `backend/` folder contains a Python service designed for Cloud Run deployment. It creates extraction jobs, queues processing with Cloud Tasks, stores state and outputs in Cloud Storage, calls Gemini for parcel field extraction, validates output, and exposes job/download endpoints to the frontend.

## Key Features

- Gemini-based multimodal parcel data extraction
- Queue-based batch processing with live progress updates
- AWB, phone, and PIN normalization
- Pincode-based city enrichment
- Excel-safe CSV export formatting
- Daily master store and batch-level downloads
- Operator activity log and job lifecycle tracking
- Separate deployment paths for Cloud Run backend and HostGator frontend

## Tech Stack

- Python
- FastAPI
- Gemini API
- Google Cloud Run
- Google Cloud Tasks
- Google Cloud Storage
- PHP
- JavaScript
- HTML/CSS

## Project Structure

```text
courier-parcel-extractor/
|-- backend/
|   |-- main.py
|   |-- extractor.py
|   |-- storage.py
|   |-- task_queue.py
|   |-- csv_utils.py
|   `-- cities/
|-- frontend/
|   |-- index.php
|   |-- job.php
|   |-- download.php
|   |-- activity_view.php
|   |-- app/
|   `-- assets/
|-- docs/
|   |-- LOCAL_SETUP.md
|   |-- API_KEY_SETUP.md
|   |-- CLOUD_RUN_DEPLOY.md
|   |-- HOSTGATOR_DEPLOY.md
|   `-- ARCHITECTURE.md
|-- scripts/
|-- .gitignore
`-- README.md
```

## Start Here

- Local setup: [docs/LOCAL_SETUP.md](docs/LOCAL_SETUP.md)
- API key setup: [docs/API_KEY_SETUP.md](docs/API_KEY_SETUP.md)
- Cloud Run backend deploy: [docs/CLOUD_RUN_DEPLOY.md](docs/CLOUD_RUN_DEPLOY.md)
- HostGator frontend deploy: [docs/HOSTGATOR_DEPLOY.md](docs/HOSTGATOR_DEPLOY.md)
- Architecture overview: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)

## Repository Notes

- Real secrets, API keys, runtime files, uploads, and generated deployment bundles are intentionally ignored.
- `frontend/storage/` is runtime state and should remain writable in deployment.
- `hostgator-deploy/` is generated locally for deployment packaging and is not part of the public source tree.
