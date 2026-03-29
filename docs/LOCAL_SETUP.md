# Local Setup

## Canonical source path

Work from:

```text
D:\KURIERWALA\parcel-extractor-control-desk
```

## Local frontend test copy

The local served frontend is currently:

```text
C:\xampp\htdocs\frontend
```

That folder is the XAMPP-served test copy, not the canonical source of truth.

## Local backend script testing

Backend files live in:

```text
D:\KURIERWALA\parcel-extractor-control-desk\backend
```

To run the local extraction script:

```powershell
cd D:\KURIERWALA\parcel-extractor-control-desk\backend
python .\extract_shipments.py
```

## Local frontend testing

1. Start Apache in XAMPP
2. Open:

```text
http://localhost/frontend/
```

3. Log in with the credentials in:

```text
C:\xampp\htdocs\frontend\logindata.json
```

If you are setting up from a fresh clone, first copy:

```text
frontend\logindata.example.json -> frontend\logindata.json
frontend\app\config.example.php -> frontend\app\config.php
```

## Gemini API key

Place your local script key in:

```text
D:\KURIERWALA\parcel-extractor-control-desk\backend\api.txt
```

Supported formats:

- raw key only
- `API_KEY=your_key_here`

## Current local workflow

1. edit source in `D:\KURIERWALA\parcel-extractor-control-desk`
2. sync or copy frontend changes into `C:\xampp\htdocs\frontend` when needed
3. test frontend locally in browser
4. test backend locally or against live Cloud Run

## Useful maintenance scripts

Sync canonical frontend into the XAMPP-served copy:

```powershell
cd D:\KURIERWALA\parcel-extractor-control-desk
.\scripts\sync_frontend_to_xampp.ps1
```

Rebuild the HostGator upload package:

```powershell
cd D:\KURIERWALA\parcel-extractor-control-desk
.\scripts\build_hostgator_package.ps1
```
