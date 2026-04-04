param(
    [Parameter(Mandatory = $true)]
    [string]$ProjectId,

    [Parameter(Mandatory = $true)]
    [string]$BucketName,

    [Parameter(Mandatory = $false)]
    [string]$Region = "us-central1",

    [Parameter(Mandatory = $false)]
    [string]$QueueName = "parcel-extractor-queue",

    [Parameter(Mandatory = $false)]
    [string]$ServiceName = "parcel-extractor-backend",

    [Parameter(Mandatory = $false)]
    [string]$EnvFile = ".\cloudrun.env.yaml"
)

$ErrorActionPreference = "Stop"

function Require-Command {
    param([string]$Name)

    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Required command '$Name' was not found. Install it first and retry."
    }
}

function Ensure-EnvFile {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Environment file '$Path' was not found. Create it from cloudrun.env.example.yaml first."
    }
}

Require-Command "gcloud"
Ensure-EnvFile $EnvFile

Write-Host "Setting active Google Cloud project..." -ForegroundColor Cyan
gcloud config set project $ProjectId | Out-Host

Write-Host "Enabling required APIs..." -ForegroundColor Cyan
gcloud services enable `
    run.googleapis.com `
    cloudbuild.googleapis.com `
    artifactregistry.googleapis.com `
    cloudtasks.googleapis.com `
    storage.googleapis.com | Out-Host

Write-Host "Creating Cloud Storage bucket if needed..." -ForegroundColor Cyan
$bucketCheck = gcloud storage buckets list --project $ProjectId --format="value(name)" 2>$null | Where-Object { $_ -eq $BucketName }
if (-not $bucketCheck) {
    gcloud storage buckets create "gs://$BucketName" --location=$Region --uniform-bucket-level-access | Out-Host
} else {
    Write-Host "Bucket gs://$BucketName already exists." -ForegroundColor Yellow
}

Write-Host "Creating Cloud Tasks queue if needed..." -ForegroundColor Cyan
$queueCheck = gcloud tasks queues describe $QueueName --location $Region --format="value(name)" 2>$null
if (-not $queueCheck) {
    gcloud tasks queues create $QueueName --location $Region | Out-Host
} else {
    Write-Host "Queue $QueueName already exists in $Region." -ForegroundColor Yellow
}

Write-Host "Applying Cloud Tasks queue limits..." -ForegroundColor Cyan
gcloud tasks queues update $QueueName `
    --location $Region `
    --max-concurrent-dispatches 1 `
    --max-dispatches-per-second 1 | Out-Host

Write-Host "Deploying Cloud Run service..." -ForegroundColor Cyan
gcloud run deploy $ServiceName `
    --source . `
    --region $Region `
    --platform managed `
    --allow-unauthenticated `
    --min-instances 0 `
    --max-instances 2 `
    --cpu 1 `
    --memory 1Gi `
    --concurrency 1 `
    --timeout 3600 `
    --env-vars-file $EnvFile | Out-Host

Write-Host ""
Write-Host "Deployment command completed." -ForegroundColor Green
Write-Host "Next:" -ForegroundColor Cyan
Write-Host "1. Open Cloud Run and copy the service URL."
Write-Host "2. Update frontend/app/config.php:"
Write-Host "   - backend_mode => 'api'"
Write-Host "   - backend_base_url => your Cloud Run URL"
Write-Host "   - backend_shared_secret => APP_SHARED_SECRET from the env file"
Write-Host "3. Test /usage/today and one small batch before going live."
