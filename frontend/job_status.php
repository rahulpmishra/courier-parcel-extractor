<?php

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

$jobId = trim((string) ($_GET['job'] ?? ''));
if ($jobId === '') {
    http_response_code(400);
    echo json_encode(['message' => 'Job ID is missing.']);
    exit;
}

$pollWarnings = [];

try {
    $job = get_job($jobId);
    remember_job_snapshot($job);
    save_active_job_reference($job);
} catch (Throwable $exception) {
    $job = get_job_snapshot($jobId);
    if ($job === null) {
        http_response_code(502);
        echo json_encode(['message' => $exception->getMessage()]);
        exit;
    }

    $job['message'] = 'Waiting for backend update. Retrying automatically...';
    $job['status'] = (string) ($job['status'] ?? 'queued');
    $job['progress'] = (int) ($job['progress'] ?? 5);
    $pollWarnings[] = 'Temporary polling issue: ' . $exception->getMessage();
}

$status = (string) ($job['status'] ?? 'unknown');
$isFinished = in_array($status, ['completed', 'failed', 'canceled'], true);

if ($isFinished) {
    clear_active_job_reference($jobId);
}

echo json_encode([
    'job' => $job,
    'status_kicker' => job_status_kicker($status),
    'status_subnote' => job_status_subnote($status),
    'is_finished' => $isFinished,
    'poll_warnings' => $pollWarnings,
], JSON_UNESCAPED_SLASHES);
