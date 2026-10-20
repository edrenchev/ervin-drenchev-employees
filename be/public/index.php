<?php

ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300');
set_time_limit(300);

require dirname(__DIR__) . '/vendor/autoload.php';

use Stask\Libs;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonResponse(int $status, mixed $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower($value[strlen($value) - 1]);
    $number = (float) $value;

    return (int) match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

function uploadLimitBytes(): int
{
    $upload = iniBytes((string) ini_get('upload_max_filesize'));
    $post = iniBytes((string) ini_get('post_max_size'));
    if ($upload <= 0) {
        return $post;
    }
    if ($post <= 0) {
        return $upload;
    }

    return min($upload, $post);
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        $value = $bytes / (1024 * 1024);
        $unit = 'MB';
    } elseif ($bytes >= 1024) {
        $value = $bytes / 1024;
        $unit = 'KB';
    } else {
        return $bytes . ' B';
    }

    $formatted = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

    return $formatted . ' ' . $unit;
}

function handleUpload(): void
{
    $limit = uploadLimitBytes();
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $tooLarge = 'File is too large. Server limit is ' . formatBytes($limit) . '.';

    if ($limit > 0 && $contentLength > $limit && $_FILES === []) {
        jsonResponse(413, ['error' => $tooLarge]);
    }

    $file = $_FILES['file'] ?? null;
    if (!is_array($file)) {
        jsonResponse(400, ['error' => 'No file was uploaded.']);
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    switch ($error) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            jsonResponse(413, ['error' => $tooLarge]);
        case UPLOAD_ERR_PARTIAL:
            jsonResponse(400, ['error' => 'The file was only partially uploaded.']);
        case UPLOAD_ERR_NO_FILE:
            jsonResponse(400, ['error' => 'No file was uploaded.']);
        case UPLOAD_ERR_NO_TMP_DIR:
            jsonResponse(400, ['error' => 'Upload failed. Missing a temporary folder.']);
        case UPLOAD_ERR_CANT_WRITE:
            jsonResponse(400, ['error' => 'Upload failed. Could not write the file to disk.']);
        case UPLOAD_ERR_EXTENSION:
            jsonResponse(400, ['error' => 'Upload failed. A PHP extension stopped the upload.']);
        default:
            jsonResponse(400, ['error' => 'Upload failed.']);
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        jsonResponse(400, ['error' => 'No file was uploaded.']);
    }

    $result = Libs::saveCsv($tmpName, $originalName);
    if (!$result['ok']) {
        jsonResponse($result['status'], ['error' => $result['error']]);
    }

    jsonResponse(201, $result['file']);
}

switch (true) {
    case $method === 'GET' && $path === '/files':
        jsonResponse(200, Libs::listFiles());
        break;

    case $method === 'POST' && $path === '/files':
        handleUpload();
        break;

    case $method === 'GET' && $path === '/files/limit':
        jsonResponse(200, ['maxBytes' => uploadLimitBytes()]);
        break;

    case $method === 'GET' && preg_match('#^/files/([^/]+\.csv)/findLongestPeriodByProject$#', $path, $matches):
        $result = Libs::findLongestPeriodByProject($matches[1]);
        if ($result === null) {
            jsonResponse(404, ['error' => 'Not found']);
        }

        jsonResponse(200, $result);
        break;

    case $method === 'GET' && preg_match('#^/files/([^/]+\.csv)$#', $path, $matches):
        $rows = Libs::readFile($matches[1]);
        if ($rows === null) {
            jsonResponse(404, ['error' => 'Not found']);
        }

        jsonResponse(200, [
            'name' => $matches[1],
            'rows' => $rows,
        ]);
        break;

    case $method === 'GET' && $path === '/':
        $indexHtml = __DIR__ . '/index.html';
        if (is_file($indexHtml)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($indexHtml);
            exit;
        }

        jsonResponse(404, ['error' => 'Not found']);
        break;

    default:
        jsonResponse(404, ['error' => 'Not found']);
}
