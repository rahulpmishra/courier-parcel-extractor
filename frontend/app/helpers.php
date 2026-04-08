<?php

declare(strict_types=1);

function app_config(?string $key = null)
{
    $config = $GLOBALS['frontend_config'] ?? [];

    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? null;
}


function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


function storage_path(string $path = ''): string
{
    $base = rtrim((string) app_config('storage_path'), '/\\');
    if ($path === '') {
        return $base;
    }

    return $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}


function ensure_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}


function set_flash(string $key, $value): void
{
    $_SESSION['_flash'][$key] = $value;
}


function get_flash(string $key, $default = null)
{
    if (!isset($_SESSION['_flash'][$key])) {
        return $default;
    }

    $value = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);

    return $value;
}


function remember_job_snapshot(array $job): void
{
    $jobId = trim((string) ($job['job_id'] ?? ''));
    if ($jobId === '') {
        return;
    }

    $_SESSION['job_snapshots'][$jobId] = $job;
}


function get_job_snapshot(string $jobId): ?array
{
    $jobId = trim($jobId);
    if ($jobId === '') {
        return null;
    }

    $snapshot = $_SESSION['job_snapshots'][$jobId] ?? null;
    return is_array($snapshot) ? $snapshot : null;
}


function forget_job_snapshot(string $jobId): void
{
    $jobId = trim($jobId);
    if ($jobId === '') {
        return;
    }

    unset($_SESSION['job_snapshots'][$jobId]);
}


function active_job_store_path(): string
{
    return storage_path('jobs/active_job.json');
}


function completed_job_store_path(): string
{
    return storage_path('jobs/completed_job.json');
}


function is_active_job_status(string $status): bool
{
    return in_array(strtolower(trim($status)), ['queued', 'processing', 'canceling'], true);
}


function normalize_active_job_payload(array $job): array
{
    return [
        'job_id' => trim((string) ($job['job_id'] ?? '')),
        'sender' => trim((string) ($job['sender'] ?? '')),
        'status' => trim((string) ($job['status'] ?? 'queued')),
        'progress' => (int) ($job['progress'] ?? 0),
        'message' => trim((string) ($job['message'] ?? '')),
        'file_count' => (int) ($job['file_count'] ?? 0),
        'created_at' => (int) ($job['created_at'] ?? time()),
        'updated_at' => (int) ($job['updated_at'] ?? time()),
    ];
}


function load_active_job_reference(): ?array
{
    $path = active_job_store_path();
    if (!is_file($path)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }

    $job = normalize_active_job_payload($data);
    return $job['job_id'] !== '' ? $job : null;
}


function save_active_job_reference(array $job): void
{
    $normalized = normalize_active_job_payload($job);
    if ($normalized['job_id'] === '') {
        return;
    }

    if (!is_active_job_status($normalized['status'])) {
        clear_active_job_reference($normalized['job_id']);
        return;
    }

    $path = active_job_store_path();
    ensure_directory(dirname($path));
    file_put_contents($path, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}


function clear_active_job_reference(?string $jobId = null): void
{
    $path = active_job_store_path();
    if (!is_file($path)) {
        return;
    }

    if ($jobId !== null && $jobId !== '') {
        $current = load_active_job_reference();
        if (is_array($current) && trim((string) ($current['job_id'] ?? '')) !== trim($jobId)) {
            return;
        }
    }

    @unlink($path);
}


function load_completed_job_reference(): ?array
{
    $path = completed_job_store_path();
    if (!is_file($path)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }

    $job = normalize_active_job_payload($data);
    return $job['job_id'] !== '' ? $job : null;
}


function save_completed_job_reference(array $job): void
{
    $normalized = normalize_active_job_payload($job);
    if ($normalized['job_id'] === '') {
        return;
    }

    $path = completed_job_store_path();
    ensure_directory(dirname($path));
    file_put_contents($path, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}


function clear_completed_job_reference(?string $jobId = null): void
{
    $path = completed_job_store_path();
    if (!is_file($path)) {
        return;
    }

    if ($jobId !== null && $jobId !== '') {
        $current = load_completed_job_reference();
        if (is_array($current) && trim((string) ($current['job_id'] ?? '')) !== trim($jobId)) {
            return;
        }
    }

    @unlink($path);
}


function current_completed_job_summary(): ?array
{
    $completedJob = load_completed_job_reference();
    if ($completedJob === null) {
        return null;
    }

    $jobId = trim((string) ($completedJob['job_id'] ?? ''));
    if ($jobId === '') {
        clear_completed_job_reference();
        return null;
    }

    try {
        $liveJob = get_job($jobId);
        $normalized = normalize_active_job_payload($liveJob);
        $status = strtolower(trim((string) ($normalized['status'] ?? '')));
        if ($status === 'completed') {
            save_completed_job_reference($normalized);
            return $normalized;
        }

        clear_completed_job_reference($jobId);
        return null;
    } catch (Throwable $exception) {
        return $completedJob;
    }
}


function current_active_job_summary(bool $refresh = true): ?array
{
    $activeJob = load_active_job_reference();
    if ($activeJob === null) {
        return null;
    }

    if (!$refresh) {
        return is_active_job_status((string) ($activeJob['status'] ?? '')) ? $activeJob : null;
    }

    $jobId = trim((string) ($activeJob['job_id'] ?? ''));
    if ($jobId === '') {
        clear_active_job_reference();
        return null;
    }

    try {
        $liveJob = get_job($jobId);
        $normalized = normalize_active_job_payload($liveJob);
        if (is_active_job_status((string) ($normalized['status'] ?? ''))) {
            save_active_job_reference($normalized);
            return $normalized;
        }

        clear_active_job_reference($jobId);
        return null;
    } catch (Throwable $exception) {
        return is_active_job_status((string) ($activeJob['status'] ?? '')) ? $activeJob : null;
    }
}


function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}


function is_post_request(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}


function current_frontend_script(): string
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    return basename($script);
}


function login_data_path(): string
{
    return (string) app_config('login_data_path');
}


function load_login_users(): array
{
    $path = login_data_path();
    if ($path === '' || !is_file($path)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }

    $users = [];
    if (isset($data['users']) && is_array($data['users'])) {
        $users = $data['users'];
    } elseif (isset($data['id']) || isset($data['password'])) {
        $users = [$data];
    }

    return array_values(array_filter($users, static function ($user): bool {
        return is_array($user) && trim((string) ($user['id'] ?? '')) !== '';
    }));
}


function login_config_ready(): bool
{
    return count(load_login_users()) > 0;
}


function is_authenticated(): bool
{
    return !empty($_SESSION['auth_user_id']);
}


function authenticated_user_id(): string
{
    return trim((string) ($_SESSION['auth_user_id'] ?? ''));
}


function authenticate_user(string $userId, string $password): bool
{
    $userId = trim($userId);

    foreach (load_login_users() as $user) {
        $storedId = trim((string) ($user['id'] ?? ''));
        if ($storedId === '' || $storedId !== $userId) {
            continue;
        }

        $storedPassword = (string) ($user['password'] ?? '');
        if ($storedPassword !== '' && hash_equals($storedPassword, $password)) {
            $_SESSION['auth_user_id'] = $storedId;
            session_regenerate_id(true);
            return true;
        }
    }

    return false;
}


function logout_user(): void
{
    unset($_SESSION['auth_user_id']);
    session_regenerate_id(true);
}


function require_authentication(): void
{
    if (is_authenticated()) {
        return;
    }

    $_SESSION['auth_redirect_to'] = current_frontend_script();
    redirect_to('login.php');
}


function redirect_after_login(): void
{
    $target = trim((string) ($_SESSION['auth_redirect_to'] ?? ''));
    unset($_SESSION['auth_redirect_to']);

    if ($target !== '' && $target !== 'login.php') {
        redirect_to($target);
    }

    redirect_to('index.php');
}


function normalize_uploaded_files(array $fileInput): array
{
    if (empty($fileInput) || !isset($fileInput['name'])) {
        return [];
    }

    $normalized = [];
    $names = (array) $fileInput['name'];
    $tmpNames = (array) ($fileInput['tmp_name'] ?? []);
    $errors = (array) ($fileInput['error'] ?? []);
    $sizes = (array) ($fileInput['size'] ?? []);
    $types = (array) ($fileInput['type'] ?? []);
    $fullPaths = (array) ($fileInput['full_path'] ?? []);

    foreach ($names as $index => $name) {
        if ($name === '' && ($errors[$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $relativePath = (string) ($fullPaths[$index] ?? '');
        if ($relativePath === '') {
            $relativePath = (string) $name;
        }

        $normalized[] = [
            'name' => (string) $name,
            'relative_path' => $relativePath,
            'source_key' => normalize_source_key($relativePath, (string) $name),
            'sender' => derive_file_sender_from_relative_path($relativePath),
            'tmp_name' => (string) ($tmpNames[$index] ?? ''),
            'error' => (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($sizes[$index] ?? 0),
            'type' => (string) ($types[$index] ?? ''),
        ];
    }

    return $normalized;
}


function normalize_source_key(string $relativePath, string $fallbackName = ''): string
{
    $normalizedPath = str_replace('\\', '/', trim($relativePath));
    if ($normalizedPath !== '') {
        return $normalizedPath;
    }

    return basename(trim($fallbackName));
}


function normalized_sender_key(string $sender): string
{
    return strtolower(trim($sender));
}


function normalized_base_name(string $sourcePath, string $fileName = ''): string
{
    $path = trim($sourcePath) !== '' ? $sourcePath : $fileName;
    return strtolower(basename(trim($path)));
}


function source_alias_key(string $sourcePath, string $fileName = '', string $sender = ''): string
{
    $senderKey = normalized_sender_key($sender);
    $baseName = normalized_base_name($sourcePath, $fileName);

    if ($baseName === '') {
        return '';
    }

    return $senderKey !== '' ? $senderKey . '|' . $baseName : $baseName;
}


function source_paths_overlap(string $leftPath, string $rightPath): bool
{
    $leftPath = normalize_source_key($leftPath);
    $rightPath = normalize_source_key($rightPath);

    if ($leftPath === '' || $rightPath === '') {
        return false;
    }

    if ($leftPath === $rightPath) {
        return true;
    }

    return str_ends_with($leftPath, '/' . $rightPath) || str_ends_with($rightPath, '/' . $leftPath);
}


function source_aliases_compatible(
    string $leftSourcePath,
    string $leftFileName,
    string $leftSender,
    string $rightSourcePath,
    string $rightFileName,
    string $rightSender
): bool {
    $leftBase = normalized_base_name($leftSourcePath, $leftFileName);
    $rightBase = normalized_base_name($rightSourcePath, $rightFileName);

    if ($leftBase === '' || $leftBase !== $rightBase) {
        return false;
    }

    $leftSenderKey = normalized_sender_key($leftSender);
    $rightSenderKey = normalized_sender_key($rightSender);

    if ($leftSenderKey === $rightSenderKey) {
        return true;
    }

    return in_array($leftSenderKey, ['', 'others'], true) || in_array($rightSenderKey, ['', 'others'], true);
}


function find_existing_daily_store_key(
    array $rowsByFile,
    string $fileKey,
    string $aliasKey,
    string $fileName = '',
    string $sender = ''
): ?string
{
    if ($fileKey !== '' && array_key_exists($fileKey, $rowsByFile)) {
        return $fileKey;
    }

    if ($aliasKey === '') {
        return null;
    }

    foreach ($rowsByFile as $existingKey => $existingRow) {
        if (!is_array($existingRow)) {
            continue;
        }

        $existingAlias = source_alias_key(
            (string) ($existingRow['source_path'] ?? $existingKey),
            (string) ($existingRow['file'] ?? ''),
            (string) ($existingRow['sender'] ?? '')
        );

        if ($existingAlias === $aliasKey) {
            return (string) $existingKey;
        }

        if (
            source_paths_overlap($fileKey, (string) ($existingRow['source_path'] ?? $existingKey))
            && source_aliases_compatible(
                $fileKey,
                $fileName,
                $sender,
                (string) ($existingRow['source_path'] ?? $existingKey),
                (string) ($existingRow['file'] ?? ''),
                (string) ($existingRow['sender'] ?? '')
            )
        ) {
            return (string) $existingKey;
        }
    }

    return null;
}


function job_status_kicker(string $status): string
{
    return match ($status) {
        'queued' => 'Signal captured.',
        'processing' => 'Run in motion.',
        'canceling' => 'Release requested.',
        'completed' => 'Run complete.',
        'failed' => 'Run interrupted.',
        'canceled' => 'Run canceled.',
        default => 'Awaiting telemetry.',
    };
}


function job_status_subnote(string $status): string
{
    return match ($status) {
        'queued' => 'The deck is holding this batch in queue and will lock onto the next live update automatically.',
        'processing' => 'Frames are being read now. Stay on this screen and the control deck will keep syncing progress.',
        'canceling' => 'Cancel was requested. The worker is releasing this run and the desk will free up as soon as the current step ends.',
        'completed' => 'Output is ready for export and the verified rows can be pushed into today\'s master.',
        'failed' => 'This run hit a blocker. Check the notice below, correct it, and relaunch when ready.',
        'canceled' => 'This run was stopped manually. No further images from this batch will be processed.',
        default => 'This screen will keep watching for fresh telemetry from the live service.',
    };
}


function derive_file_sender_from_relative_path(string $relativePath): string
{
    $normalizedPath = str_replace('\\', '/', trim($relativePath));
    if ($normalizedPath === '') {
        return 'others';
    }

    $parts = array_values(array_filter(explode('/', $normalizedPath), static function ($part) {
        return $part !== '';
    }));

    if (count($parts) <= 1) {
        return 'others';
    }

    $folderParts = array_slice($parts, 0, -1);
    $sender = trim((string) end($folderParts));

    return $sender !== '' ? $sender : 'others';
}


function validate_upload_batch(string $sender, array $files): array
{
    $errors = [];

    if ($sender === '') {
        $errors[] = 'Sender or batch name is required.';
    }

    if (!$files) {
        $errors[] = 'Select at least one image or one folder.';
        return $errors;
    }

    $maxFiles = (int) app_config('max_upload_files');
    if (count($files) > $maxFiles) {
        $errors[] = 'You can upload at most ' . $maxFiles . ' images at once.';
    }

    $allowedExtensions = array_map('strtolower', (array) app_config('allowed_extensions'));
    $maxBytes = (int) app_config('max_upload_size_mb') * 1024 * 1024;

    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed for ' . ($file['name'] ?? 'unknown file') . '.';
            continue;
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'Unsupported file type for ' . ($file['name'] ?? 'unknown file') . '.';
        }

        if (($file['size'] ?? 0) <= 0) {
            $errors[] = 'File appears empty: ' . ($file['name'] ?? 'unknown file') . '.';
        }

        if (($file['size'] ?? 0) > $maxBytes) {
            $errors[] = 'File exceeds the size limit (' . app_config('max_upload_size_mb') . ' MB): ' . ($file['name'] ?? 'unknown file') . '.';
        }
    }

    return array_values(array_unique($errors));
}


function derive_sender_name(array $files, string $sender = ''): string
{
    $sender = trim($sender);
    if ($sender !== '') {
        return $sender;
    }

    foreach ($files as $file) {
        $relativePath = str_replace('\\', '/', (string) ($file['relative_path'] ?? ''));
        if ($relativePath !== '' && strpos($relativePath, '/') !== false) {
            $parts = array_values(array_filter(explode('/', $relativePath), static function ($part) {
                return $part !== '';
            }));

            if ($parts) {
                return trim((string) $parts[0]) ?: 'others';
            }
        }
    }

    return 'others';
}


function format_timestamp($value): string
{
    if ($value === null || $value === '') {
        return 'N/A';
    }

    if (is_numeric($value)) {
        return date('d M Y, h:i A', (int) $value);
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return (string) $value;
    }

    return date('d M Y, h:i A', $timestamp);
}


function current_business_date(): string
{
    return date('Y-m-d');
}


function activity_store_path(?string $date = null): string
{
    $date = $date ?: current_business_date();
    return storage_path('activity/' . app_config('activity_store_prefix') . $date . '.json');
}


function cleanup_old_activity_files(): void
{
    $retentionDays = max(1, (int) app_config('activity_retention_days'));
    $activityDir = storage_path('activity');
    ensure_directory($activityDir);

    $cutoffTimestamp = strtotime('-' . ($retentionDays - 1) . ' days midnight');
    $prefix = (string) app_config('activity_store_prefix');
    $items = scandir($activityDir) ?: [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $activityDir . DIRECTORY_SEPARATOR . $item;
        if (!is_file($path)) {
            continue;
        }

        $pattern = '/^' . preg_quote($prefix, '/') . '(\d{4}-\d{2}-\d{2})\.json$/';
        if (!preg_match($pattern, $item, $matches)) {
            continue;
        }

        $fileDate = strtotime($matches[1] . ' 00:00:00');
        if ($fileDate === false) {
            continue;
        }

        if ($fileDate < $cutoffTimestamp) {
            @unlink($path);
        }
    }
}


function load_activity_rows(?string $date = null): array
{
    cleanup_old_activity_files();

    $path = activity_store_path($date);
    if (!is_file($path)) {
        return [];
    }

    $rows = json_decode((string) file_get_contents($path), true);
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter($rows, 'is_array'));
}


function save_activity_rows(array $rows, ?string $date = null): array
{
    cleanup_old_activity_files();

    $path = activity_store_path($date);
    ensure_directory(dirname($path));

    usort($rows, static function (array $left, array $right): int {
        return (int) ($right['created_at'] ?? 0) <=> (int) ($left['created_at'] ?? 0);
    });

    file_put_contents($path, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $rows;
}


function activity_time_label(int $createdAt): string
{
    return date('d M Y, h:i A', $createdAt);
}


function activity_event_label(string $eventType): string
{
    $eventType = trim($eventType);

    return match ($eventType) {
        'login' => 'Login',
        'daily_csv_download' => 'Daily CSV Download',
        'batch_csv_download' => 'Batch CSV Download',
        'daily_reset' => 'Daily Reset',
        'job_canceled' => 'Run Canceled',
        default => 'Batch Processed',
    };
}


function append_activity_row(array $row, ?string $date = null): void
{
    $createdAt = (int) ($row['created_at'] ?? time());
    $date = $date ?: date('Y-m-d', $createdAt);
    $rows = load_activity_rows($date);
    $rows[] = $row;
    save_activity_rows($rows, $date);
}


function log_batch_activity(array $job, string $sender, array $files): void
{
    $createdAt = (int) ($job['created_at'] ?? time());
    $date = date('Y-m-d', $createdAt);
    $rows = load_activity_rows($date);
    $jobId = trim((string) ($job['job_id'] ?? ''));

    foreach ($rows as $existingRow) {
        if (trim((string) ($existingRow['job_id'] ?? '')) === $jobId && $jobId !== '') {
            return;
        }
    }

    $rows[] = [
        'event_type' => 'batch',
        'event_label' => activity_event_label('batch'),
        'job_id' => $jobId,
        'batch_name' => $sender,
        'details' => $sender,
        'reference' => $jobId,
        'actor' => authenticated_user_id(),
        'file_count' => count($files),
        'created_at' => $createdAt,
        'time_label' => activity_time_label($createdAt),
    ];

    save_activity_rows($rows, $date);
}


function log_activity_event(string $eventType, array $context = [], ?int $createdAt = null): void
{
    $createdAt = $createdAt ?? time();
    $count = $context['count'] ?? null;
    if ($count !== null) {
        $count = max(0, (int) $count);
    }

    append_activity_row([
        'event_type' => $eventType,
        'event_label' => activity_event_label($eventType),
        'job_id' => trim((string) ($context['job_id'] ?? '')),
        'batch_name' => trim((string) ($context['batch_name'] ?? '')),
        'details' => trim((string) ($context['details'] ?? '')),
        'reference' => trim((string) ($context['reference'] ?? '')),
        'actor' => trim((string) ($context['actor'] ?? authenticated_user_id())),
        'file_count' => $count,
        'created_at' => $createdAt,
        'time_label' => activity_time_label($createdAt),
    ], date('Y-m-d', $createdAt));
}


function activity_dates_for_view(): array
{
    $dates = [current_business_date(), date('Y-m-d', strtotime('-1 day'))];
    return array_values(array_unique($dates));
}


function activity_summary_for_view(): array
{
    $sections = [];

    foreach (activity_dates_for_view() as $date) {
        $rows = load_activity_rows($date);
        $sections[] = [
            'date' => $date,
            'label' => $date === current_business_date() ? 'Today' : 'Previous Day',
            'rows' => $rows,
            'count' => count($rows),
        ];
    }

    return $sections;
}


function load_api_usage_snapshot(): array
{
    $path = (string) app_config('api_usage_path');
    $defaultBudget = 0;
    $snapshot = [
        'date' => current_business_date(),
        'daily_budget' => $defaultBudget,
        'requests_used' => 0,
        'requests_remaining' => $defaultBudget,
    ];

    if ($path === '' || !is_file($path)) {
        return $snapshot;
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return $snapshot;
    }

    $budget = max(0, (int) ($data['daily_budget'] ?? 0));
    $used = max(0, (int) ($data['requests_used'] ?? 0));
    $fileDate = trim((string) ($data['date'] ?? ''));

    if ($fileDate !== '' && $fileDate === current_business_date()) {
        $snapshot['requests_used'] = $used;
        $snapshot['requests_remaining'] = max(0, $budget - $used);
    }

    $snapshot['date'] = $fileDate !== '' ? $fileDate : $snapshot['date'];
    $snapshot['daily_budget'] = $budget;

    return $snapshot;
}


function daily_store_path(?string $date = null): string
{
    return storage_path('daily/' . app_config('daily_store_prefix') . 'store.json');
}


function legacy_daily_store_files(): array
{
    $dailyDir = storage_path('daily');
    ensure_directory($dailyDir);

    $prefix = (string) app_config('daily_store_prefix');
    $items = scandir($dailyDir) ?: [];
    $legacyFiles = [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dailyDir . DIRECTORY_SEPARATOR . $item;
        if (!is_file($path)) {
            continue;
        }

        if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d{4}-\d{2}-\d{2})\.json$/', $item, $matches)) {
            continue;
        }

        $legacyFiles[] = [
            'date' => $matches[1],
            'path' => $path,
        ];
    }

    usort($legacyFiles, static function (array $left, array $right): int {
        return strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
    });

    return $legacyFiles;
}


function load_rows_by_file_snapshot(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }

    return is_array($data['rows_by_file'] ?? null) ? $data['rows_by_file'] : [];
}


function daily_store_snapshot(array $rowsByFile, ?string $date = null): array
{
    ksort($rowsByFile, SORT_NATURAL | SORT_FLAG_CASE);

    $dates = [];
    foreach ($rowsByFile as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowDate = trim((string) ($row['date'] ?? ''));
        if ($rowDate !== '') {
            $dates[$rowDate] = true;
        }
    }

    $dateKeys = array_keys($dates);
    sort($dateKeys, SORT_STRING);
    $earliestDate = $dateKeys[0] ?? '';
    $latestDate = $dateKeys ? $dateKeys[count($dateKeys) - 1] : '';
    $displayDate = $latestDate !== '' ? $latestDate : ($date ?: current_business_date());

    return [
        'date' => $displayDate,
        'earliest_date' => $earliestDate,
        'latest_date' => $latestDate,
        'date_count' => count($dateKeys),
        'row_count' => count($rowsByFile),
        'updated_at' => time(),
        'rows_by_file' => $rowsByFile,
    ];
}


function load_daily_store(?string $date = null): array
{
    $path = daily_store_path($date);
    ensure_directory(dirname($path));

    if (is_file($path)) {
        return daily_store_snapshot(load_rows_by_file_snapshot($path), $date);
    }

    $rowsByFile = [];
    foreach (legacy_daily_store_files() as $legacyFile) {
        foreach (load_rows_by_file_snapshot((string) ($legacyFile['path'] ?? '')) as $key => $row) {
            $rowsByFile[(string) $key] = $row;
        }
    }

    $snapshot = daily_store_snapshot($rowsByFile, $date);
    file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $snapshot;
}


function save_daily_store(array $rowsByFile, ?string $date = null): array
{
    $snapshot = daily_store_snapshot($rowsByFile, $date);
    $path = daily_store_path($date);
    ensure_directory(dirname($path));
    file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $snapshot;
}


function merge_rows_into_daily_store(array $rows, ?string $date = null): array
{
    $snapshot = load_daily_store($date);
    $rowsByFile = $snapshot['rows_by_file'];
    $added = 0;
    $replaced = 0;

    foreach ($rows as $row) {
        $fileKey = normalize_source_key(
            (string) ($row['source_path'] ?? ''),
            (string) ($row['file'] ?? '')
        );
        $aliasKey = source_alias_key(
            (string) ($row['source_path'] ?? ''),
            (string) ($row['file'] ?? ''),
            (string) ($row['sender'] ?? '')
        );
        if ($fileKey === '') {
            continue;
        }

        $targetKey = find_existing_daily_store_key(
            $rowsByFile,
            $fileKey,
            $aliasKey,
            (string) ($row['file'] ?? ''),
            (string) ($row['sender'] ?? '')
        );

        if ($targetKey !== null) {
            $replaced++;
        } else {
            $added++;
            $targetKey = $fileKey;
        }

        $rowsByFile[$targetKey] = $row;
    }

    $saved = save_daily_store($rowsByFile, $snapshot['date']);
    $saved['added_count'] = $added;
    $saved['replaced_count'] = $replaced;

    return $saved;
}


function reset_daily_store(?string $date = null): array
{
    return save_daily_store([], $date);
}


function daily_rows_for_view(?string $date = null): array
{
    $snapshot = load_daily_store($date);
    $rows = array_values($snapshot['rows_by_file']);

    usort($rows, static function (array $left, array $right): int {
        $leftDate = (string) ($left['date'] ?? '');
        $rightDate = (string) ($right['date'] ?? '');
        $dateCompare = strcmp($rightDate, $leftDate);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        $leftFile = strtolower((string) ($left['file'] ?? ''));
        $rightFile = strtolower((string) ($right['file'] ?? ''));
        return strnatcasecmp($leftFile, $rightFile);
    });

    return $rows;
}


function csv_export_value(string $column, $value): string
{
    $stringValue = trim((string) $value);
    if ($column === 'awb' && preg_match('/^\d{10,}$/', $stringValue)) {
        return '="' . $stringValue . '"';
    }

    return (string) $value;
}


function export_daily_store_csv(?string $date = null): string
{
    $snapshot = load_daily_store($date);
    $rows = array_values($snapshot['rows_by_file']);
    $outputPath = storage_path('daily/' . app_config('daily_store_prefix') . 'export.csv');
    ensure_directory(dirname($outputPath));

    $handle = fopen($outputPath, 'wb');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, ['date', 'file', 'sender', 'receiver', 'address', 'city', 'pin', 'phone', 'awb']);
    foreach ($rows as $row) {
        fputcsv($handle, [
            csv_export_value('date', $row['date'] ?? ''),
            csv_export_value('file', $row['file'] ?? ''),
            csv_export_value('sender', $row['sender'] ?? ''),
            csv_export_value('receiver', $row['receiver'] ?? ''),
            csv_export_value('address', $row['address'] ?? ''),
            csv_export_value('city', $row['city'] ?? ''),
            csv_export_value('pin', $row['pin'] ?? ''),
            csv_export_value('phone', $row['phone'] ?? ''),
            csv_export_value('awb', $row['awb'] ?? ''),
        ]);
    }
    fclose($handle);

    return $outputPath;
}


function duplicate_file_names(array $files, ?string $date = null): array
{
    $snapshot = load_daily_store($date);
    $existing = $snapshot['rows_by_file'] ?? [];
    $duplicates = [];

    foreach ($files as $file) {
        $fileKey = normalize_source_key(
            (string) ($file['relative_path'] ?? ''),
            (string) ($file['name'] ?? '')
        );
        $aliasKey = source_alias_key(
            (string) ($file['relative_path'] ?? ''),
            (string) ($file['name'] ?? ''),
            (string) ($file['sender'] ?? '')
        );
        if (
            $fileKey !== ''
            && find_existing_daily_store_key(
                $existing,
                $fileKey,
                $aliasKey,
                (string) ($file['name'] ?? ''),
                (string) ($file['sender'] ?? '')
            ) !== null
        ) {
            $duplicates[$fileKey] = $fileKey;
        }
    }

    natcasesort($duplicates);
    return array_values($duplicates);
}


function pending_batch_token(): string
{
    return app_config('pending_store_prefix') . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 8);
}


function pending_batch_dir(string $token): string
{
    return storage_path('pending/' . $token);
}


function pending_batch_manifest_path(string $token): string
{
    return storage_path('pending/' . $token . '.json');
}


function move_file_to_path(string $sourcePath, string $destinationPath): void
{
    ensure_directory(dirname($destinationPath));

    if (is_uploaded_file($sourcePath)) {
        if (!move_uploaded_file($sourcePath, $destinationPath)) {
            throw new RuntimeException('Unable to move uploaded file.');
        }
        return;
    }

    if (@rename($sourcePath, $destinationPath)) {
        return;
    }

    if (!@copy($sourcePath, $destinationPath)) {
        throw new RuntimeException('Unable to store uploaded file.');
    }

    @unlink($sourcePath);
}


function stage_pending_batch(string $sender, array $files, array $duplicates): array
{
    $token = pending_batch_token();
    $batchDir = pending_batch_dir($token);
    ensure_directory($batchDir);

    $storedFiles = [];

    foreach ($files as $index => $file) {
        $originalName = basename((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $storedName = sprintf('%03d_%s.%s', $index + 1, uniqid('pending_', true), $extension);
        $destination = $batchDir . DIRECTORY_SEPARATOR . $storedName;

        move_file_to_path((string) ($file['tmp_name'] ?? ''), $destination);

        $storedFiles[] = [
            'name' => $originalName,
            'relative_path' => (string) ($file['relative_path'] ?? $originalName),
            'source_key' => (string) ($file['source_key'] ?? normalize_source_key((string) ($file['relative_path'] ?? $originalName), $originalName)),
            'sender' => (string) ($file['sender'] ?? ''),
            'tmp_name' => $destination,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) ($file['size'] ?? 0),
            'type' => (string) ($file['type'] ?? ''),
        ];
    }

    $manifest = [
        'token' => $token,
        'sender' => $sender,
        'duplicates' => array_values($duplicates),
        'files' => $storedFiles,
        'created_at' => time(),
    ];

    file_put_contents(
        pending_batch_manifest_path($token),
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    return $manifest;
}


function load_pending_batch(string $token): array
{
    $path = pending_batch_manifest_path($token);
    if (!is_file($path)) {
        throw new RuntimeException('Pending batch confirmation expired or was not found.');
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('Pending batch data is invalid.');
    }

    return $data;
}


function remove_duplicate_pending_files(array $pending): array
{
    $duplicateMap = [];
    foreach ((array) ($pending['duplicates'] ?? []) as $fileName) {
        $normalized = normalize_source_key((string) $fileName);
        if ($normalized !== '') {
            $duplicateMap[$normalized] = true;
        }
    }

    $remainingFiles = [];
    foreach ((array) ($pending['files'] ?? []) as $file) {
        $fileKey = normalize_source_key(
            (string) ($file['source_key'] ?? $file['relative_path'] ?? ''),
            (string) ($file['name'] ?? '')
        );
        if ($fileKey === '' || isset($duplicateMap[$fileKey])) {
            continue;
        }

        $remainingFiles[] = $file;
    }

    return $remainingFiles;
}


function cleanup_pending_batch(string $token): void
{
    $manifestPath = pending_batch_manifest_path($token);
    $batchDir = pending_batch_dir($token);

    if (is_dir($batchDir)) {
        $items = array_diff(scandir($batchDir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $itemPath = $batchDir . DIRECTORY_SEPARATOR . $item;
            if (is_file($itemPath)) {
                @unlink($itemPath);
            }
        }
        @rmdir($batchDir);
    }

    if (is_file($manifestPath)) {
        @unlink($manifestPath);
    }
}
