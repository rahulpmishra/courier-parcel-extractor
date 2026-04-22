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
    $skippedCount = count(skipped_result_file_names($rows));
    $result = merge_rows_into_daily_store($rows);
    clear_completed_job_reference($jobId);
    $message = $result['row_count'] . ' rows currently in the master store. '
        . $result['added_count'] . ' added and '
        . $result['replaced_count'] . ' replaced from this batch.';

    if ($skippedCount > 0) {
        $message .= ' ' . $skippedCount . ' fully blank skipped file'
            . ($skippedCount === 1 ? ' was' : 's were')
            . ' not added.';
    }

    set_flash('success', $message);
} catch (Throwable $exception) {
    set_flash('errors', [$exception->getMessage()]);
}

redirect_to('job.php?job=' . rawurlencode($jobId));
