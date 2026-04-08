<?php

declare(strict_types=1);

function submit_job(string $sender, array $files): array
{
    if (app_config('backend_mode') === 'mock') {
        return mock_submit_job($sender, $files);
    }

    $endpoint = rtrim((string) app_config('backend_base_url'), '/') . '/jobs';
    $postFields = [
        'sender' => $sender,
    ];

    foreach ($files as $index => $file) {
        $postFields['images[' . $index . ']'] = new CURLFile(
            $file['tmp_name'],
            $file['type'] ?: 'application/octet-stream',
            $file['name']
        );
        $postFields['relative_paths[' . $index . ']'] = (string) ($file['relative_path'] ?? $file['name'] ?? '');
        $postFields['file_senders[' . $index . ']'] = (string) ($file['sender'] ?? $sender);
    }

    return api_json_request('POST', $endpoint, $postFields, true);
}


function get_api_usage_today(): array
{
    if (app_config('backend_mode') === 'mock') {
        return load_api_usage_snapshot();
    }

    $endpoint = rtrim((string) app_config('backend_base_url'), '/') . '/usage/today';
    return api_json_request('GET', $endpoint, [], false, 6, 2);
}


function get_job(string $jobId): array
{
    if (app_config('backend_mode') === 'mock') {
        return mock_get_job($jobId);
    }

    $endpoint = rtrim((string) app_config('backend_base_url'), '/') . '/jobs/' . rawurlencode($jobId);
    return api_json_request('GET', $endpoint, [], false, 6, 2);
}


function cancel_job_run(string $jobId): array
{
    if (app_config('backend_mode') === 'mock') {
        throw new RuntimeException('Job cancellation is unavailable in mock mode.');
    }

    $endpoint = rtrim((string) app_config('backend_base_url'), '/') . '/jobs/' . rawurlencode($jobId) . '/cancel';
    return api_json_request('POST', $endpoint, []);
}


function get_download(string $jobId, string $type): array
{
    if (app_config('backend_mode') === 'mock') {
        return mock_get_download($jobId, $type);
    }

    return [
        'mode' => 'redirect',
        'url' => rtrim((string) app_config('backend_base_url'), '/') . '/jobs/' . rawurlencode($jobId) . '/download/' . rawurlencode($type),
    ];
}


function get_job_rows(string $jobId): array
{
    if (app_config('backend_mode') === 'mock') {
        return mock_get_job_rows($jobId);
    }

    $endpoint = rtrim((string) app_config('backend_base_url'), '/') . '/jobs/' . rawurlencode($jobId) . '/download/json';
    return api_json_request('GET', $endpoint, [], false, 20, 1);
}


function backend_auth_headers(): array
{
    $headers = ['Accept: application/json'];
    $secret = trim((string) app_config('backend_shared_secret'));
    if ($secret !== '') {
        $headers[] = 'X-App-Key: ' . $secret;
    }

    return $headers;
}


function api_json_request(
    string $method,
    string $url,
    array $payload = [],
    bool $multipart = false,
    ?int $timeoutSeconds = null,
    ?int $maxAttemptsOverride = null
): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for API mode.');
    }

    $attempt = 0;
    $isGet = strtoupper($method) === 'GET';
    $maxAttempts = $maxAttemptsOverride ?? ($isGet ? 4 : 1);
    $timeout = $timeoutSeconds ?? ($isGet ? 8 : 120);

    while (true) {
        $attempt++;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to start API request.');
        }

        $headers = backend_auth_headers();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ];

        if ($method === 'POST') {
            if ($multipart) {
                $options[CURLOPT_POSTFIELDS] = $payload;
            } else {
                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_POSTFIELDS] = json_encode($payload);
            }
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            if ($attempt < $maxAttempts && $isGet) {
                sleep(2);
                continue;
            }
            throw new RuntimeException('Backend request failed: ' . $error);
        }

        $data = json_decode($response, true);
        if ($statusCode === 429 || $statusCode >= 500) {
            if ($attempt < $maxAttempts && $isGet) {
                sleep(2);
                continue;
            }
        }

        if ($statusCode >= 400) {
            $message = is_array($data) ? ($data['message'] ?? $data['detail'] ?? 'Backend returned an error.') : 'Backend returned an error.';
            throw new RuntimeException((string) $message);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Backend returned invalid JSON.');
        }

        return $data;
    }
}
