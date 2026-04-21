param(
    [Parameter(Mandatory = $true)]
    [string]$ApiKey,

    [Parameter(Mandatory = $false)]
    [string]$ProjectId = $env:GOOGLE_CLOUD_PROJECT,

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

    throw "gcloud was not found. Install Google Cloud SDK and make sure it is available in PATH."
}

if ([string]::IsNullOrWhiteSpace($ProjectId)) {
    throw "ProjectId is required. Pass -ProjectId or set the GOOGLE_CLOUD_PROJECT environment variable."
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
