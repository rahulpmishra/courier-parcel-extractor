# Backend Overview

This folder contains the Python backend used for parcel-image extraction and job processing.

## Responsibilities

- create and track extraction jobs
- queue processing with Cloud Tasks
- store job state and output in Cloud Storage
- call Gemini for parcel field extraction
- validate and normalize output
- expose usage and job endpoints to the frontend

## Main files

- `main.py`
- `extractor.py`
- `storage.py`
- `task_queue.py`
- `csv_utils.py`
- `deploy_backend.ps1`
- `cloudrun.env.example.yaml`

## Deployment

Deploy from this folder:

```powershell
cd path\to\parcel-extractor-control-desk\backend
.\deploy_backend.ps1 -ProjectId "your-project-id" -BucketName "your-bucket-name"
```

## Local script

For standalone extraction testing:

```powershell
cd path\to\parcel-extractor-control-desk\backend
python .\extract_shipments.py "C:\path\to\parcel-images"
```

## Notes

- Cancel behavior is cooperative, not a hard kill.
- The processing model is intentionally single-worker for controlled batch execution.
- Excel-safe AWB formatting support exists in CSV utilities for downstream exports.
