param(
    [Parameter(Mandatory = $false)]
    [string]$ProjectRoot = "D:\KURIERWALA\parcel-extractor-control-desk"
)

$ErrorActionPreference = "Stop"

$frontendRoot = Join-Path $ProjectRoot "frontend"
$deployRoot = Join-Path $ProjectRoot "hostgator-deploy"
$publicHtml = Join-Path $deployRoot "public_html"
$zipPath = Join-Path $deployRoot "hostgator-public_html.zip"

if (-not (Test-Path $frontendRoot)) {
    throw "Frontend folder not found: $frontendRoot"
}

if (Test-Path $publicHtml) {
    Remove-Item -LiteralPath $publicHtml -Recurse -Force
}

New-Item -ItemType Directory -Force -Path $publicHtml | Out-Null

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
    "logindata.json"
)

foreach ($file in $rootFiles) {
    $source = Join-Path $frontendRoot $file
    if (Test-Path $source) {
        Copy-Item -LiteralPath $source -Destination (Join-Path $publicHtml $file) -Force
    }
}

foreach ($dir in @("app", "assets")) {
    $source = Join-Path $frontendRoot $dir
    if (Test-Path $source) {
        Copy-Item -LiteralPath $source -Destination (Join-Path $publicHtml $dir) -Recurse -Force
    }
}

$storageRoot = Join-Path $publicHtml "storage"
New-Item -ItemType Directory -Force -Path $storageRoot | Out-Null

$storageSubdirs = @("activity", "daily", "jobs", "pending", "uploads")
foreach ($subdir in $storageSubdirs) {
    New-Item -ItemType Directory -Force -Path (Join-Path $storageRoot $subdir) | Out-Null
}

$sourceStorageRoot = Join-Path $frontendRoot "storage"
if (Test-Path $sourceStorageRoot) {
    Get-ChildItem -Force $sourceStorageRoot | Where-Object { -not $_.PSIsContainer } | ForEach-Object {
        Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $storageRoot $_.Name) -Force
    }

    foreach ($subdir in $storageSubdirs) {
        $sourceSubdir = Join-Path $sourceStorageRoot $subdir
        $targetSubdir = Join-Path $storageRoot $subdir
        if (Test-Path $sourceSubdir) {
            Get-ChildItem -Force $sourceSubdir | Where-Object { -not $_.PSIsContainer } | ForEach-Object {
                Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $targetSubdir $_.Name) -Force
            }
        }
    }
}

if (Test-Path $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

Compress-Archive -Path (Join-Path $publicHtml "*") -DestinationPath $zipPath -CompressionLevel Optimal

Write-Host ""
Write-Host "HostGator package rebuilt:" -ForegroundColor Green
Write-Host $zipPath
