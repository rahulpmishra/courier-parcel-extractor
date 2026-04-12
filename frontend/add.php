<?php

require __DIR__ . '/app/bootstrap.php';

if (!is_post_request()) {
    redirect_to('index.php');
}

$jobId = trim((string) ($_POST['job'] ?? ''));
if ($jobId === '') {
    set_flash('errors', ['Job ID is missing for add action.']);
    redirect_to('index.php');
}

try {
    $rows = get_job_rows($jobId);
    $result = merge_rows_into_daily_store($rows);
    clear_completed_job_reference($jobId);
    set_flash(
        'success',
        $result['row_count'] . ' rows currently in the master store. '
        . $result['added_count'] . ' added and '
        . $result['replaced_count'] . ' replaced from this batch.'
    );
} catch (Throwable $exception) {
    set_flash('errors', [$exception->getMessage()]);
}

redirect_to('job.php?job=' . rawurlencode($jobId));
