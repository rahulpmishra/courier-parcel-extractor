<?php

require __DIR__ . '/app/bootstrap.php';

if (!is_post_request()) {
    redirect_to('index.php');
}

$jobId = trim((string) ($_POST['job'] ?? ''));
if ($jobId === '') {
    set_flash('errors', ['Run ID is missing.']);
    redirect_to('index.php');
}

try {
    $job = cancel_job_run($jobId);
    remember_job_snapshot($job);
    $status = strtolower(trim((string) ($job['status'] ?? '')));

    if (is_active_job_status($status)) {
        save_active_job_reference($job);
        set_flash('success', 'Cancel requested. Waiting for the worker to release this run.');
        redirect_to('job.php?job=' . rawurlencode($jobId));
    }

    forget_job_snapshot($jobId);
    clear_active_job_reference($jobId);
    log_activity_event('job_canceled', [
        'job_id' => $jobId,
        'details' => 'Canceled the active run from the job screen.',
        'reference' => $jobId,
    ]);
    set_flash('success', 'Run canceled successfully.');
} catch (Throwable $exception) {
    set_flash('errors', [$exception->getMessage()]);
}

redirect_to('index.php');
