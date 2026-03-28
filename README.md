# Parcel Extractor Control Desk

An end-to-end parcel data extraction system that turns parcel images into validated structured outputs through a PHP control desk frontend and a Python Cloud Run backend.

## Demo

Demo GIF:
- add a short workflow GIF here later

Demo video:
- add a walkthrough link here later

## Why it matters

Parcel data entry is often slow, repetitive, and error-prone. Labels can include a mix of:

- printed shipping details
- handwritten recipient notes
- noisy backgrounds
- inconsistent image orientation

This project addresses that by combining multimodal extraction, validation, queue-based batch processing, and an operator-friendly control desk. The result is a workflow that reduces manual effort while still keeping a human in control of downloads, master data updates, and operational review.

## What the system does

- accepts parcel image batches from a web interface
- extracts delivery-side fields with Gemini
- validates important fields such as PIN, phone, and AWB
- enriches city values using a local pincode map
- tracks batch progress with a live monitoring screen
- exports CSV/JSON output
- stores verified rows in a persistent master store
- logs operational events such as login, download, reset, and batch activity

## Main parts

### `frontend/`
PHP control desk intended for:
- local testing in XAMPP
- deployment to HostGator shared hosting

### `backend/`
Python extraction and job-processing service intended for:
- local script testing
- Cloud Run deployment with Cloud Tasks and Cloud Storage

### `docs/`
Setup, deployment, and architecture guides.

### `hostgator-deploy/`
Generated packaging area for clean frontend deployment bundles.

### `scripts/`
Maintenance helpers for rebuilding the HostGator package and syncing the local XAMPP frontend copy.

## Typical workflow

1. Configure your Gemini key and project settings
2. Run or deploy the backend from `backend/`
3. Point the frontend at the backend URL
4. Start batches from the frontend
5. Monitor progress on the job page
6. Download CSV or merge rows into the master store

## Start here

- Local setup: [docs/LOCAL_SETUP.md](./docs/LOCAL_SETUP.md)
- API key setup: [docs/API_KEY_SETUP.md](./docs/API_KEY_SETUP.md)
- Cloud Run backend deploy: [docs/CLOUD_RUN_DEPLOY.md](./docs/CLOUD_RUN_DEPLOY.md)
- HostGator frontend deploy: [docs/HOSTGATOR_DEPLOY.md](./docs/HOSTGATOR_DEPLOY.md)
- Architecture overview: [docs/ARCHITECTURE.md](./docs/ARCHITECTURE.md)

## Repo notes

- The repo is structured so the same codebase can support both local testing and production deployment.
- Real secrets, local runtime files, and generated deployment artifacts should not be committed to the public repo.
- Utility scripts are available under `scripts/` for recurring local maintenance tasks.
