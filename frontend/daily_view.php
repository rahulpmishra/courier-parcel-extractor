<?php

require __DIR__ . '/app/bootstrap.php';

$rows = daily_rows_for_view();
$snapshot = load_daily_store();
$dateCount = (int) ($snapshot['date_count'] ?? 0);
$earliestDate = (string) ($snapshot['earliest_date'] ?? '');
$latestDate = (string) ($snapshot['latest_date'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Data View | <?= h(app_config('app_name')) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="shell shell-job shell-wide shell-table-page shell-daily-page">
        <header class="job-topbar">
            <div>
                <p class="eyebrow">Master Data View</p>
                <h1>All Stored Rows</h1>
            </div>
            <a class="button button-secondary" href="index.php">Back To Home</a>
        </header>

        <section class="panel status-panel">
            <div class="status-head">
                <div>
                    <p class="status-label">Rows Stored</p>
                    <h2><?= h((string) $snapshot['row_count']) ?></h2>
                </div>
                <div class="master-tools">
                    <label class="master-search" for="master-sender-search">
                        <span>Sender Search</span>
                        <input
                            type="search"
                            id="master-sender-search"
                            placeholder="Search sender"
                            data-master-sender-search
                            autocomplete="off"
                        >
                    </label>
                    <span class="status-pill status-completed">Master Store</span>
                </div>
            </div>

            <?php if (!$rows): ?>
                <p class="status-message">No rows have been added to the master store yet.</p>
            <?php else: ?>
                <p class="status-message">
                    <?= h((string) $snapshot['row_count']) ?> rows across <?= h((string) $dateCount) ?> date(s).
                    <?php if ($earliestDate !== '' && $latestDate !== ''): ?>
                        Showing newest dates first from <?= h($latestDate) ?> back to <?= h($earliestDate) ?>.
                    <?php endif; ?>
                </p>
                <p class="status-subnote">Click Date for newest first, double-click for oldest first. Click City, Pin, Phone, or AWB to bring empty rows up; double-click the same label to reset.</p>
                <div class="table-wrap">
                    <table class="data-table" data-master-table>
                        <thead>
                            <tr>
                                <th>
                                    <button type="button" class="sort-button" data-date-sort-button data-sort-direction="desc">
                                        Date
                                    </button>
                                </th>
                                <th>File</th>
                                <th>Sender</th>
                                <th>Receiver</th>
                                <th>Address</th>
                                <th>
                                    <button type="button" class="sort-button" data-empty-sort-button data-column-index="5">
                                        City
                                    </button>
                                </th>
                                <th>
                                    <button type="button" class="sort-button" data-empty-sort-button data-column-index="6">
                                        Pin
                                    </button>
                                </th>
                                <th>
                                    <button type="button" class="sort-button" data-empty-sort-button data-column-index="7">
                                        Phone
                                    </button>
                                </th>
                                <th>
                                    <button type="button" class="sort-button" data-empty-sort-button data-column-index="8">
                                        AWB
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= h($row['date'] ?? '') ?></td>
                                    <td><?= h($row['file'] ?? '') ?></td>
                                    <td><?= h($row['sender'] ?? '') ?></td>
                                    <td><?= h($row['receiver'] ?? '') ?></td>
                                    <td><?= h($row['address'] ?? '') ?></td>
                                    <td><?= h($row['city'] ?? '') ?></td>
                                    <td><?= h($row['pin'] ?? '') ?></td>
                                    <td><?= h($row['phone'] ?? '') ?></td>
                                    <td><?= h($row['awb'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <script src="assets/app.js"></script>
</body>
</html>
