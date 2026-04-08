<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Calcutta');

$GLOBALS['frontend_config'] = require __DIR__ . '/config.php';

require __DIR__ . '/helpers.php';
require __DIR__ . '/mock_backend.php';
require __DIR__ . '/api_client.php';

$currentScript = current_frontend_script();
if ($currentScript === 'login.php' && is_authenticated()) {
    redirect_to('index.php');
}

if ($currentScript !== 'login.php') {
    require_authentication();
}

ensure_directory(storage_path('jobs'));
ensure_directory(storage_path('uploads'));
ensure_directory(storage_path('pending'));
ensure_directory(storage_path('activity'));
