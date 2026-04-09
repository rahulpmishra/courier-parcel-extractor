<?php

require __DIR__ . '/app/bootstrap.php';

if (!is_post_request()) {
    redirect_to('index.php');
}

$pendingToken = trim((string) ($_POST['pending_token'] ?? ''));

if ($pendingToken !== '' && isset($_POST['cancel_duplicate'])) {
    cleanup_pending_batch($pendingToken);
    set_flash('errors', ['Batch was cancelled because duplicate files were found.']);
    redirect_to('index.php');
}

if ($pendingToken !== '' && isset($_POST['confirm_duplicate'])) {
    try {
        $activeJob = current_active_job_summary();
        if ($activeJob !== null) {
            cleanup_pending_batch($pendingToken);
            set_flash('errors', ['Another batch is already running. Open the active run or cancel it before starting a new batch.']);
            redirect_to('index.php');
        }

        $pending = load_pending_batch($pendingToken);
        $pendingSender = (string) ($pending['sender'] ?? '');
        $pendingFiles = (array) ($pending['files'] ?? []);
        $job = submit_job($pendingSender, $pendingFiles);
        remember_job_snapshot($job);
        save_active_job_reference($job);
        log_batch_activity($job, $pendingSender, $pendingFiles);
        cleanup_pending_batch($pendingToken);
        set_flash('success', 'Batch created successfully after duplicate confirmation.');
        redirect_to('job.php?job=' . rawurlencode($job['job_id']));
    } catch (Throwable $exception) {
        cleanup_pending_batch($pendingToken);
        set_flash('errors', [$exception->getMessage()]);
        redirect_to('index.php');
    }
}

if ($pendingToken !== '' && isset($_POST['remove_duplicates'])) {
    try {
        $activeJob = current_active_job_summary();
        if ($activeJob !== null) {
            cleanup_pending_batch($pendingToken);
            set_flash('errors', ['Another batch is already running. Open the active run or cancel it before starting a new batch.']);
            redirect_to('index.php');
        }

        $pending = load_pending_batch($pendingToken);
        $remainingFiles = remove_duplicate_pending_files($pending);

        if (!$remainingFiles) {
            cleanup_pending_batch($pendingToken);
            set_flash('errors', ['All selected files were already processed today. No new files were left to process.']);
            redirect_to('index.php');
        }

        $pendingSender = (string) ($pending['sender'] ?? '');
        $job = submit_job($pendingSender, $remainingFiles);
        remember_job_snapshot($job);
        save_active_job_reference($job);
        log_batch_activity($job, $pendingSender, $remainingFiles);
        cleanup_pending_batch($pendingToken);
        set_flash('success', 'Duplicate files were removed and the remaining files were sent for processing.');
        redirect_to('job.php?job=' . rawurlencode($job['job_id']));
    } catch (Throwable $exception) {
        cleanup_pending_batch($pendingToken);
        set_flash('errors', [$exception->getMessage()]);
        redirect_to('index.php');
    }
}

$sender = trim((string) ($_POST['sender'] ?? ''));
$files = array_merge(
    normalize_uploaded_files($_FILES['images'] ?? []),
    normalize_uploaded_files($_FILES['folder_images'] ?? [])
);
$sender = derive_sender_name($files, $sender);
$errors = validate_upload_batch($sender, $files);

if ($errors) {
    set_flash('errors', $errors);
    set_flash('old', ['sender' => $sender]);
    redirect_to('index.php');
}

try {
    $activeJob = current_active_job_summary();
    if ($activeJob !== null) {
        set_flash('errors', ['Another batch is already running. Open the active run or cancel it before starting a new batch.']);
        set_flash('old', ['sender' => $sender]);
        redirect_to('index.php');
    }

    $duplicates = duplicate_file_names($files);
    if ($duplicates) {
        $pending = stage_pending_batch($sender, $files, $duplicates);
        redirect_to('confirm.php?token=' . rawurlencode($pending['token']));
    }

    $job = submit_job($sender, $files);
    remember_job_snapshot($job);
    save_active_job_reference($job);
    log_batch_activity($job, $sender, $files);
    set_flash('success', 'Batch created successfully.');
    redirect_to('job.php?job=' . rawurlencode($job['job_id']));
} catch (Throwable $exception) {
    set_flash('errors', [$exception->getMessage()]);
    set_flash('old', ['sender' => $sender]);
    redirect_to('index.php');
}
