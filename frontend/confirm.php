<?php

require __DIR__ . '/app/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    set_flash('errors', ['Duplicate confirmation token is missing.']);
    redirect_to('index.php');
}

try {
    $pending = load_pending_batch($token);
} catch (Throwable $exception) {
    set_flash('errors', [$exception->getMessage()]);
    redirect_to('index.php');
}

$duplicates = (array) ($pending['duplicates'] ?? []);
$fileCount = count((array) ($pending['files'] ?? []));
$remainingCount = max(0, $fileCount - count($duplicates));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duplicate Files Found | <?= h(app_config('app_name')) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="shell shell-job shell-confirm">
        <header class="job-topbar">
            <div>
                <p class="eyebrow">Duplicate File Check</p>
                <h1><?= h($pending['sender'] ?? 'Batch') ?></h1>
            </div>
            <a class="button button-secondary" href="index.php">Back Home</a>
        </header>

        <section class="panel status-panel">
            <div class="alert alert-warning">
                <strong>Some files were already processed today.</strong>
                The filenames below already exist in the daily master JSON. Processing again may use API calls for files you already have data for.
            </div>

            <div class="meta-grid">
                <div class="meta-card">
                    <span class="meta-label">Selected Files</span>
                    <span class="meta-value"><?= h((string) $fileCount) ?></span>
                </div>
                <div class="meta-card">
                    <span class="meta-label">Duplicates Found</span>
                    <span class="meta-value"><?= h((string) count($duplicates)) ?></span>
                </div>
                <div class="meta-card">
                    <span class="meta-label">Action Needed</span>
                    <span class="meta-value">Confirm before processing</span>
                </div>
            </div>

            <div class="alert alert-info">
                If you choose <strong>Remove Duplicates</strong>, only <?= h((string) $remainingCount) ?> new file<?= $remainingCount === 1 ? '' : 's' ?> will continue for processing.
            </div>

            <div class="file-list-wrap file-list-wrap-scroll">
                <h3>Already Processed Filenames</h3>
                <ul class="file-list">
                    <?php foreach ($duplicates as $fileName): ?>
                        <li><?= h($fileName) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="downloads">
                <form action="submit.php" method="post" class="inline-form">
                    <input type="hidden" name="pending_token" value="<?= h($token) ?>">
                    <input type="hidden" name="confirm_duplicate" value="1">
                    <button type="submit" class="button button-primary">Process Anyway</button>
                </form>
                <form action="submit.php" method="post" class="inline-form">
                    <input type="hidden" name="pending_token" value="<?= h($token) ?>">
                    <input type="hidden" name="remove_duplicates" value="1">
                    <button type="submit" class="button button-secondary">Remove Duplicates</button>
                </form>
                <form action="submit.php" method="post" class="inline-form">
                    <input type="hidden" name="pending_token" value="<?= h($token) ?>">
                    <input type="hidden" name="cancel_duplicate" value="1">
                    <button type="submit" class="button button-secondary">Cancel Batch</button>
                </form>
            </div>
        </section>
    </div>
</body>
</html>
