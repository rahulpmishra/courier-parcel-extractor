<?php

require __DIR__ . '/app/bootstrap.php';

$jobId = trim((string) ($_GET['job'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));

function download_safe_name(string $value, string $fallback): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }

    $value = preg_replace('/[^\w\-\. ]+/u', '_', $value) ?? $fallback;
    $value = trim($value, " .\t\n\r\0\x0B");

    return $value !== '' ? $value : $fallback;
}

if ($jobId === '' || !in_array($type, ['csv', 'json'], true)) {
    http_response_code(400);
    echo 'Invalid download request.';
    exit;
}

if (app_config('backend_mode') === 'api') {
    try {
        $rows = get_job_rows($jobId);
        $job = get_job($jobId);
    } catch (Throwable $exception) {
        http_response_code(404);
        echo h($exception->getMessage());
        exit;
    }

    $senderName = trim((string) ($job['sender'] ?? ''));
    $baseName = download_safe_name($senderName, 'output');

    if ($type === 'json') {
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            http_response_code(500);
            echo 'Unable to prepare JSON download.';
            exit;
        }

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '.json"');
        echo $json;
        exit;
    }

    log_activity_event('batch_csv_download', [
        'details' => 'Downloaded batch CSV from the job page.',
        'reference' => $jobId,
        'batch_name' => $senderName,
        'job_id' => $jobId,
        'count' => count($rows),
    ]);

    $handle = fopen('php://temp', 'w+');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, ['date', 'file', 'sender', 'receiver', 'address', 'city', 'pin', 'phone', 'awb']);
    foreach ($rows as $row) {
        fputcsv($handle, [
            csv_export_value('date', $row['date'] ?? ''),
            csv_export_value('file', $row['file'] ?? ''),
            csv_export_value('sender', $row['sender'] ?? ''),
            csv_export_value('receiver', $row['receiver'] ?? ''),
            csv_export_value('address', $row['address'] ?? ''),
            csv_export_value('city', $row['city'] ?? ''),
            csv_export_value('pin', $row['pin'] ?? ''),
            csv_export_value('phone', $row['phone'] ?? ''),
            csv_export_value('awb', $row['awb'] ?? ''),
        ]);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    if ($csv === false) {
        http_response_code(500);
        echo 'Unable to prepare CSV download.';
        exit;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Length: ' . (string) strlen($csv));
    header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
    echo $csv;
    exit;
}

try {
    $download = get_download($jobId, $type);
} catch (Throwable $exception) {
    http_response_code(404);
    echo h($exception->getMessage());
    exit;
}

if ($download['mode'] === 'redirect') {
    if ($type === 'csv') {
        try {
            $job = get_job($jobId);
            log_activity_event('batch_csv_download', [
                'details' => 'Downloaded batch CSV from the job page.',
                'reference' => $jobId,
                'batch_name' => trim((string) ($job['sender'] ?? '')),
                'job_id' => $jobId,
                'count' => (int) ($job['file_count'] ?? 0),
            ]);
        } catch (Throwable $exception) {
            // Ignore logging failures for legacy download mode.
        }
    }
    header('Location: ' . $download['url']);
    exit;
}

$path = $download['path'];
if (!is_file($path)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$fileName = basename($path);
$downloadName = $fileName;

if ($type === 'csv') {
    try {
        $job = get_job($jobId);
        $senderName = trim((string) ($job['sender'] ?? ''));
        $downloadName = download_safe_name($senderName, pathinfo($fileName, PATHINFO_FILENAME)) . '.csv';
    } catch (Throwable $exception) {
        $downloadName = $fileName;
    }
}

$mimeType = $type === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
readfile($path);
exit;
