# Cloud Run Backend Deploy

## Backend folder

Deploy from:

```text
D:\KURIERWALA\parcel-extractor-control-desk\backend
```

## Required files

- `deploy_backend.ps1`
- `cloudrun.env.example.yaml`
- your real `cloudrun.env.yaml`

## Prepare environment file

Create:

```text
backend\cloudrun.env.yaml
```

from:

```text
backend\cloudrun.env.example.yaml
```

Fill in your own:

- Gemini API key
- bucket name
- GCP project
- Cloud Run service base URL
- shared secrets

## Deploy

```powershell
cd D:\KURIERWALA\parcel-extractor-control-desk\backend
.\deploy_backend.ps1 -ProjectId "your-project-id" -BucketName "your-bucket-name"
```

## Key rotation

To update only the Gemini API key without a full source build:

```cmd
"D:\KURIERWALA\parcel-extractor-control-desk\backend\setkey.cmd" AQ.YOUR_NEW_KEY
```

## Important production note

Cloud Run environment variable changes still create a new revision, but they do not require a full source rebuild when using the key-update helper.
