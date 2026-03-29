# API Key Setup

## Get a Gemini API key

1. Open [Google AI Studio](https://aistudio.google.com/)
2. Sign in with your Google account
3. Open the API keys section
4. Create a new key under your chosen project

## Backend local script

For local backend script usage, place your key in:

```text
D:\KURIERWALA\parcel-extractor-control-desk\backend\api.txt
```

Supported formats:

- raw API key only
- `API_KEY=your_key_here`

## Cloud Run backend

For Cloud Run deployment, place the key in:

```text
backend\cloudrun.env.yaml
```

using:

```yaml
GEMINI_API_KEY: "your-key-here"
```

## Frontend

The frontend does not need the Gemini key directly.

It only needs:

- backend base URL
- shared secret

configured in:

```text
frontend/app/config.php
```

## Rotating the Gemini key later

You can rotate the backend key with:

```cmd
"D:\KURIERWALA\parcel-extractor-control-desk\backend\setkey.cmd" AQ.YOUR_NEW_KEY
```

That updates only the `GEMINI_API_KEY` on Cloud Run.
