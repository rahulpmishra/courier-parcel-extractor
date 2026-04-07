<?php

declare(strict_types=1);

return [
    'app_name' => 'Parcel Extractor Control Desk',
    'backend_mode' => 'api',
    'backend_base_url' => 'https://your-cloud-run-service-url',
    'backend_shared_secret' => 'replace-with-your-shared-secret',
    'job_refresh_seconds' => 5,
    'max_upload_files' => 100,
    'allowed_extensions' => ['jpg', 'jpeg', 'png'],
    'max_upload_size_mb' => 20,
    'storage_path' => dirname(__DIR__) . '/storage',
    'api_usage_path' => dirname(dirname(__DIR__)) . '/api_usage.json',
    'login_data_path' => dirname(__DIR__) . '/logindata.json',
    'daily_store_prefix' => 'daily_master_',
    'activity_store_prefix' => 'activity_',
    'pending_store_prefix' => 'pending_batch_',
    'daily_retention_days' => 7,
    'activity_retention_days' => 2,
];
