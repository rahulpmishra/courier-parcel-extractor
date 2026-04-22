<?php

require __DIR__ . '/app/bootstrap.php';

$errors = get_flash('errors', []);
$success = get_flash('success');
$jobId = trim((string) ($_GET['job'] ?? ''));
$pollWarnings = [];
if ($jobId === '') {
    set_flash('errors', ['Job ID is missing.']);
    redirect_to('index.php');
}

try {
    $job = get_job($jobId);
    remember_job_snapshot($job);
    save_active_job_reference($job);
} catch (Throwable $exception) {
    $job = get_job_snapshot($jobId);
    if ($job === null) {
        set_flash('errors', [$exception->getMessage()]);
        redirect_to('index.php');
    }

    $job['message'] = 'Waiting for backend update. Retrying automatically...';
    $job['status'] = (string) ($job['status'] ?? 'queued');
    $job['progress'] = (int) ($job['progress'] ?? 5);
    $pollWarnings[] = 'Temporary polling issue: ' . $exception->getMessage();
}

$status = $job['status'] ?? 'unknown';
$progress = (int) ($job['progress'] ?? 0);
$isFinished = in_array($status, ['completed', 'failed', 'canceled'], true);
$refreshSeconds = (int) app_config('job_refresh_seconds');
$statusKicker = job_status_kicker((string) $status);
$statusSubnote = job_status_subnote((string) $status);
$skippedFiles = [];

if ($status === 'completed') {
    try {
        $skippedFiles = skipped_result_file_names(get_job_rows($jobId));
    } catch (Throwable $exception) {
        $pollWarnings[] = 'Unable to load skipped file details: ' . $exception->getMessage();
    }
}

if ($isFinished) {
    clear_active_job_reference($jobId);
}

$activeJob = current_active_job_summary();
$otherActiveJob = (
    is_array($activeJob)
    && trim((string) ($activeJob['job_id'] ?? '')) !== ''
    && trim((string) ($activeJob['job_id'] ?? '')) !== $jobId
) ? $activeJob : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch <?= h($jobId) ?> | <?= h(app_config('app_name')) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="shell shell-job shell-batch<?= ($success || $errors) ? ' shell-batch-has-alert' : '' ?>">
        <header class="job-topbar">
            <div>
                <p class="eyebrow">Run Deck</p>
                <h1><?= h($job['sender'] ?? 'Untitled Batch') ?></h1>
            </div>
            <a class="button button-secondary" href="index.php">New Batch</a>
        </header>

        <section
            class="panel status-panel"
            data-autorefresh="<?= $isFinished ? '0' : (string) $refreshSeconds ?>"
            data-job-id="<?= h($jobId) ?>"
            data-job-status-url="job_status.php?job=<?= rawurlencode($jobId) ?>"
            data-job-page-url="job.php?job=<?= rawurlencode($jobId) ?>"
        >
            <?php if ($otherActiveJob): ?>
                <div class="alert alert-warning alert-compact-page">
                    Another run is active right now.
                    <a href="job.php?job=<?= rawurlencode((string) ($otherActiveJob['job_id'] ?? '')) ?>">Return to the live run</a>
                    or cancel it before launching another batch.
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-compact-page"><?= h($success) ?></div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-error alert-compact-page">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= h($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($pollWarnings): ?>
                <div class="alert alert-warning alert-compact-page" data-poll-warning>
                    <ul>
                        <?php foreach ($pollWarnings as $warning): ?>
                            <li><?= h($warning) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="status-head">
                <div>
                    <p class="status-kicker" data-status-kicker><?= h($statusKicker) ?></p>
                    <p class="status-label">Run ID</p>
                    <h2><?= h($jobId) ?></h2>
                </div>
                <span class="status-pill status-<?= h($status) ?>" data-status-pill><?= h(ucfirst($status)) ?></span>
            </div>

            <p class="status-message" data-status-message><?= h($job['message'] ?? 'Waiting for update...') ?></p>
            <p class="status-subnote" data-status-subnote><?= h($statusSubnote) ?></p>

            <div class="progress">
                <div class="progress-bar" data-progress-bar style="width: <?= h((string) max(0, min($progress, 100))) ?>%"></div>
            </div>
            <p class="progress-copy" data-progress-copy><?= h((string) $progress) ?>% synced</p>

            <div class="meta-grid">
                <div class="meta-card">
                    <span class="meta-label">Payload</span>
                    <span class="meta-value" data-file-count><?= h((string) ($job['file_count'] ?? 0)) ?></span>
                </div>
                <div class="meta-card">
                    <span class="meta-label">Launch Time</span>
                    <span class="meta-value"><?= h(format_timestamp($job['created_at'] ?? null)) ?></span>
                </div>
                <div class="meta-card">
                    <span class="meta-label">Control Link</span>
                    <span class="meta-value"><?= h(strtoupper(app_config('backend_mode'))) ?></span>
                </div>
            </div>

            <?php if (!empty($job['files'])): ?>
                <div class="file-list-wrap file-list-wrap-scroll">
                    <div class="file-list-head">
                        <h3>Uploaded Files</h3>
                        <?php if ($status === 'completed'): ?>
                            <div class="downloads downloads-inline">
                                <a class="button button-primary" href="download.php?job=<?= rawurlencode($jobId) ?>&type=csv">Download CSV</a>
                                <form action="add.php" method="post" class="inline-form">
                                    <input type="hidden" name="job" value="<?= h($jobId) ?>">
                                    <button type="submit" class="button button-secondary">Add To Daily Master</button>
                                </form>
                            </div>
                        <?php elseif ($status === 'canceled'): ?>
                            <div class="downloads downloads-inline">
                                <a class="button button-secondary" href="index.php">Return Home</a>
                            </div>
                        <?php elseif ($status === 'canceling'): ?>
                            <div class="downloads downloads-inline">
                                <span class="button button-secondary button-static-muted">Cancel Requested</span>
                            </div>
                        <?php elseif (!$isFinished): ?>
                            <div class="downloads downloads-inline">
                                <form action="cancel_job.php" method="post" class="inline-form" onsubmit="return confirm('Cancel this active run?');">
                                    <input type="hidden" name="job" value="<?= h($jobId) ?>">
                                    <button type="submit" class="button button-secondary button-danger-soft">Cancel Run</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <ul class="file-list">
                        <?php foreach ($job['files'] as $file): ?>
                            <li><?= h($file['original_name'] ?? 'Unnamed file') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($skippedFiles): ?>
                <div class="file-list-wrap file-list-wrap-scroll">
                    <div class="alert alert-warning alert-compact-page">
                        <strong>Skipped / Not Processed Files:</strong>
                        These files returned no usable extracted fields and are excluded from CSV downloads and master data.
                    </div>
                    <ul class="file-list">
                        <?php foreach ($skippedFiles as $fileName): ?>
                            <li><?= h($fileName) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($status === 'completed' && empty($job['files'])): ?>
                <div class="downloads">
                    <a class="button button-primary" href="download.php?job=<?= rawurlencode($jobId) ?>&type=csv">Download CSV</a>
                    <form action="add.php" method="post" class="inline-form">
                        <input type="hidden" name="job" value="<?= h($jobId) ?>">
                        <button type="submit" class="button button-secondary">Add To Daily Master</button>
                    </form>
                </div>
            <?php elseif ($status === 'canceled' && empty($job['files'])): ?>
                <div class="downloads">
                    <a class="button button-secondary" href="index.php">Return Home</a>
                </div>
            <?php elseif ($status === 'canceling' && empty($job['files'])): ?>
                <div class="downloads">
                    <span class="button button-secondary button-static-muted">Cancel Requested</span>
                </div>
                <p class="refresh-hint" data-refresh-hint>Live telemetry is syncing in the background while this run releases.</p>
            <?php elseif (!$isFinished && empty($job['files'])): ?>
                <div class="downloads">
                    <form action="cancel_job.php" method="post" class="inline-form" onsubmit="return confirm('Cancel this active run?');">
                        <input type="hidden" name="job" value="<?= h($jobId) ?>">
                        <button type="submit" class="button button-secondary button-danger-soft">Cancel Run</button>
                    </form>
                </div>
                <p class="refresh-hint" data-refresh-hint>Live telemetry refreshes every <?= h((string) $refreshSeconds) ?> seconds while this run is active.</p>
            <?php endif; ?>
        </section>
    </div>

    <script src="assets/app.js"></script>
</body>
</html>
