<?php

require __DIR__ . '/app/bootstrap.php';

if (!is_post_request()) {
    redirect_to('index.php');
}

$jobId = trim((string) ($_POST['job'] ?? ''));
if ($jobId === '') {
    set_flash('errors', ['Completed run reference is missing.']);
    redirect_to('index.php');
}

clear_completed_job_reference($jobId);
set_flash('success', 'Previous completed run was cleared. You can start a new batch now.');
redirect_to('index.php');
