<?php

require __DIR__ . '/app/bootstrap.php';

$sections = activity_summary_for_view();
$totalProcesses = 0;

foreach ($sections as $section) {
    $totalProcesses += (int) ($section['count'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Activity | <?= h(app_config('app_name')) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="shell shell-job shell-wide shell-table-page shell-activity-page">
        <header class="job-topbar">
            <div>
                <p class="eyebrow">Operations Log</p>
                <h1>Recent Activity</h1>
            </div>
            <a class="button button-secondary" href="index.php">Back To Home</a>
        </header>

        <section class="panel status-panel">
            <div class="status-head">
                <div>
                    <p class="status-label">Signal Count</p>
                    <h2><?= h((string) $totalProcesses) ?></h2>
                </div>
                <span class="status-pill status-completed">Last 48 Hours</span>
            </div>

            <?php foreach ($sections as $section): ?>
                <div class="activity-section">
                    <div class="status-head activity-head">
                        <div>
                            <p class="status-label"><?= h($section['label']) ?></p>
                            <h2><?= h($section['date']) ?></h2>
                        </div>
                        <span class="status-pill status-completed"><?= h((string) $section['count']) ?> Activit<?= (int) ($section['count'] ?? 0) === 1 ? 'y' : 'ies' ?></span>
                    </div>

                    <?php if (empty($section['rows'])): ?>
                        <p class="status-message">No activity was recorded for this date.</p>
                    <?php else: ?>
                        <div class="table-wrap table-wrap-activity">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Activity</th>
                                        <th>Details</th>
                                        <th>Files</th>
                                        <th>Reference</th>
                                        <th>User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($section['rows'] as $row): ?>
                                        <tr>
                                            <td><?= h($row['time_label'] ?? '') ?></td>
                                            <td><?= h($row['event_label'] ?? 'Batch Processed') ?></td>
                                            <td><?= h(($row['details'] ?? '') !== '' ? ($row['details'] ?? '') : ($row['batch_name'] ?? '')) ?></td>
                                            <td><?= h((string) ($row['file_count'] ?? 0)) ?></td>
                                            <td><?= h(($row['reference'] ?? '') !== '' ? ($row['reference'] ?? '') : ($row['job_id'] ?? '')) ?></td>
                                            <td><?= h($row['actor'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    </div>
</body>
</html>
