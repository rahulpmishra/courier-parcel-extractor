# Frontend Overview

This folder contains the PHP control desk for the parcel extraction workflow.

## Current role

The frontend is responsible for:

- operator login
- batch submission
- job monitoring
- CSV/JSON download
- master data management
- activity visibility

## Deployment targets

- local testing in XAMPP
- production deployment on HostGator shared hosting

## Backend mode

The frontend is currently configured to work in API mode and expects a live backend URL and shared secret in:

```text
frontend/app/config.php
```

For a fresh setup:

- copy `frontend/app/config.example.php` to `frontend/app/config.php`
- copy `frontend/logindata.example.json` to `frontend/logindata.json`

## Important runtime folders

The frontend writes local operational state into:

```text
frontend/storage/
```

Key subfolders:

- `storage/activity`
- `storage/daily`
- `storage/jobs`
- `storage/pending`
- `storage/uploads`

These folders must be writable in the deployed environment.

## Main pages

- `login.php`
- `index.php`
- `submit.php`
- `job.php`
- `download.php`
- `daily_view.php`
- `daily_download.php`
- `daily_reset.php`
- `activity_view.php`

## Important app files

- `app/config.php`
- `app/helpers.php`
- `app/api_client.php`
- `assets/styles.css`
- `assets/app.js`

## Notes

- The local XAMPP-served copy may live outside this repo for browser testing.
- This folder is the source-of-truth frontend code for future changes and deployment packaging.
