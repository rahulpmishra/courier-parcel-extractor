<?php

require __DIR__ . '/app/bootstrap.php';

$snapshot = load_daily_store();
$csvPath = export_daily_store_csv();
$fileName = basename($csvPath);

log_activity_event('daily_csv_download', [
    'details' => 'Downloaded the master data CSV.',
    'reference' => ($snapshot['latest_date'] ?? '') !== '' ? $snapshot['latest_date'] : current_business_date(),
    'count' => (int) ($snapshot['row_count'] ?? 0),
]);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Length: ' . (string) filesize($csvPath));
header('Content-Disposition: attachment; filename="' . $fileName . '"');
readfile($csvPath);
exit;
