<?php

require __DIR__ . '/app/bootstrap.php';

$errors = get_flash('errors', []);
$old = get_flash('old', []);
$success = get_flash('success');
$backendMode = app_config('backend_mode');
$maxFiles = app_config('max_upload_files');
$dailySnapshot = load_daily_store();
$dailyRowCount = (int) ($dailySnapshot['row_count'] ?? 0);
$apiUsageWarning = null;
$activeJob = current_active_job_summary();

try {
    $apiUsage = get_api_usage_today();
} catch (Throwable $exception) {
    $apiUsage = [
        'requests_used' => 0,
        'daily_budget' => 0,
    ];
    $apiUsageWarning = 'API usage count is unavailable right now.';
}

$apiHitsToday = (int) ($apiUsage['requests_used'] ?? 0);
$apiDailyBudget = (int) ($apiUsage['daily_budget'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(app_config('app_name')) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="shell shell-home<?= ($success || $errors) ? ' shell-home-has-alert' : '' ?>">
        <header class="hero">
            <div class="hero-copy">
                <p class="eyebrow">Kurierwala Mission Control</p>
                <h1><?= h(app_config('app_name')) ?></h1>
                <p class="lead">
                    Live intake, extraction, and daily oversight in one clean control deck.
                    Drop a batch, watch the run, and move verified rows into the master without leaving the console.
                </p>
                <?php if ($activeJob): ?>
                    <div class="hero-run-dock">
                        <div class="hero-run-head">
                            <div class="hero-run-copy">
                                <p class="hero-run-kicker">Active Run Online</p>
                                <p>
                                    <?php $activeStatus = strtolower((string) ($activeJob['status'] ?? 'queued')); ?>
                                    <?php if ($activeStatus === 'canceling'): ?>
                                        A cancel request is being released right now. Return to the active run for the latest update.
                                    <?php elseif ($activeStatus === 'processing'): ?>
                                        A batch is processing right now. Return to the active run to watch progress.
                                    <?php else: ?>
                                        A batch is waiting in queue right now. Return to the active run to watch progress.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="hero-run-actions">
                            <a class="button button-primary" href="job.php?job=<?= rawurlencode((string) ($activeJob['job_id'] ?? '')) ?>">Return to Active Run</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="hero-card">
                <div class="panel-head panel-head-compact panel-head-actions">
                    <h2>Daily Master Control</h2>
                    <a class="button button-secondary button-compact" href="logout.php">Logout</a>
                </div>
                <?php if ($apiUsageWarning): ?>
                    <div class="alert alert-warning alert-compact"><?= h($apiUsageWarning) ?></div>
                <?php endif; ?>
                <div class="actions actions-vertical actions-hero">
                    <a class="button button-primary" href="daily_download.php">Download Master CSV</a>
                    <a class="button button-secondary" href="daily_view.php">View Master Data</a>
                    <div class="actions actions-split">
                        <form action="daily_reset.php" method="post" class="inline-form inline-form-fill" onsubmit="return confirm('Reset the full master data store?');">
                            <button type="submit" class="button button-secondary button-danger-soft button-fill">Reset Master Data</button>
                        </form>
                        <a class="button button-secondary button-fill" href="activity_view.php">See Activity</a>
                    </div>
                </div>
                <div class="hero-stats">
                    <div class="stat">
                        <span class="stat-label">Rows In Master</span>
                        <span class="stat-value"><?= h((string) $dailyRowCount) ?></span>
                    </div>
                    <div class="stat">
                        <span class="stat-label">Total API Hits Today</span>
                        <span class="stat-value"><?= h((string) $apiHitsToday) ?>/<?= h((string) $apiDailyBudget) ?></span>
                    </div>
                </div>
            </div>
        </header>

        <main class="grid">
            <section class="panel panel-form panel-full">
                <div class="panel-head">
                    <h2>Launch Batch</h2>
                    <p>
                        <?php if ($activeJob): ?>
                            A live run is already occupying the desk. Return to it before launching a fresh batch.
                        <?php else: ?>
                            Queue a sender or folder, let the desk process the intake, then review the verified output when it lands.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-compact-page"><?= h($success) ?></div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="alert alert-error alert-compact-page">
                        <strong>Fix these before continuing:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= h($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form action="submit.php" method="post" enctype="multipart/form-data" class="upload-form">
                    <label class="field">
                        <span>Sender / Batch Name</span>
                        <input
                            type="text"
                            name="sender"
                            id="sender-input"
                            placeholder="Example: t or evening-batch-17-apr"
                            value="<?= h($old['sender'] ?? '') ?>"
                            maxlength="120"
                            required
                        >
                    </label>

                    <div class="field-grid">
                        <label class="field">
                            <span>Parcel Images</span>
                            <input
                                type="file"
                                name="images[]"
                                id="images-input"
                                accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                multiple
                            >
                            <small>Use this for loose files. Limit: <?= h((string) $maxFiles) ?> images in one batch.</small>
                        </label>

                        <label class="field">
                            <span>Parcel Folder</span>
                            <input
                                type="file"
                                name="folder_images[]"
                                id="folder-input"
                                accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                webkitdirectory
                                directory
                                multiple
                            >
                            <small>Send a full folder, including nested folders, within the same <?= h((string) $maxFiles) ?> image limit.</small>
                        </label>
                    </div>

                    <div class="actions actions-submit">
                        <button
                            type="submit"
                            class="button button-primary"
                            data-default-label="Start Batch"
                            data-busy-label="Submitting Batch..."
                            <?= $activeJob ? 'disabled' : '' ?>
                        >
                            Start Batch
                        </button>
                        <span class="submit-status<?= $activeJob ? ' submit-status-static' : '' ?>" aria-live="polite">
                            <?php if ($activeJob): ?>
                                Another run is active. Open it before submitting a new batch.
                            <?php else: ?>
                                Submitting batch. Preparing intake channel...
                            <?php endif; ?>
                        </span>
                    </div>
                </form>
            </section>
        </main>
    </div>

    <div class="home-signoff" aria-hidden="true">Built by Tony Stark</div>

    <script src="assets/app.js"></script>
</body>
</html>
