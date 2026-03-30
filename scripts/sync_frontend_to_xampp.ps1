param(
    [Parameter(Mandatory = $false)]
    [string]$ProjectRoot = "D:\KURIERWALA\parcel-extractor-control-desk",

    [Parameter(Mandatory = $false)]
    [string]$TargetRoot = "C:\xampp\htdocs\frontend"
)

$ErrorActionPreference = "Stop"

$frontendRoot = Join-Path $ProjectRoot "frontend"

if (-not (Test-Path $frontendRoot)) {
    throw "Frontend folder not found: $frontendRoot"
}

if (-not (Test-Path $TargetRoot)) {
    throw "XAMPP frontend target not found: $TargetRoot"
}

$rootFiles = @(
    ".htaccess",
    "activity_view.php",
    "add.php",
    "cancel_job.php",
    "confirm.php",
    "daily_download.php",
    "daily_reset.php",
    "daily_view.php",
    "dismiss_completed_job.php",
    "download.php",
    "index.php",
    "job.php",
    "job_status.php",
    "login.php",
    "logout.php",
    "submit.php",
    "README.md"
)

foreach ($file in $rootFiles) {
    $source = Join-Path $frontendRoot $file
    if (Test-Path $source) {
        Copy-Item -LiteralPath $source -Destination (Join-Path $TargetRoot $file) -Force
    }
}

foreach ($dir in @("app", "assets")) {
    $source = Join-Path $frontendRoot $dir
    $target = Join-Path $TargetRoot $dir
    if (Test-Path $source) {
        Copy-Item -LiteralPath $source -Destination $target -Recurse -Force
    }
}

Write-Host ""
Write-Host "Frontend synced to XAMPP copy:" -ForegroundColor Green
Write-Host $TargetRoot
