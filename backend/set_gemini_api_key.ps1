param(
    [Parameter(Mandatory = $true)]
    [string]$ApiKey,

    [Parameter(Mandatory = $false)]
    [string]$ProjectId = "kurierwala-ocr-backend",

    [Parameter(Mandatory = $false)]
    [string]$Region = "us-central1",

    [Parameter(Mandatory = $false)]
    [string]$ServiceName = "parcel-extractor-backend"
)

$ErrorActionPreference = "Stop"

function Resolve-GcloudCommand {
    $command = Get-Command gcloud -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    $fallback = "C:\Users\rahul\AppData\Local\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd"
    if (Test-Path -LiteralPath $fallback) {
        return $fallback
    }

    throw "gcloud was not found. Install Google Cloud SDK or update the fallback path in set_gemini_api_key.ps1."
}

$gcloud = Resolve-GcloudCommand

Write-Host "Updating GEMINI_API_KEY on Cloud Run service '$ServiceName'..." -ForegroundColor Cyan
& $gcloud run services update $ServiceName `
    --project $ProjectId `
    --region $Region `
    --update-env-vars "GEMINI_API_KEY=$ApiKey" | Out-Host

Write-Host ""
Write-Host "API key update completed." -ForegroundColor Green
Write-Host "Project : $ProjectId"
Write-Host "Region  : $Region"
Write-Host "Service : $ServiceName"
