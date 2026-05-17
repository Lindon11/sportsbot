<?php

declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../telegram.php';

$config = fb_config();

if (empty($config['telegram']['webhook_enabled'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Webhook mode is disabled.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$expectedSecret = trim((string) ($config['telegram']['webhook_secret_token'] ?? ''));
if ($expectedSecret !== '') {
    $providedSecret = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
    if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
        fb_log('warning', 'telegram.webhook.auth_failed', [
            'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid webhook secret token.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Empty webhook payload.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$update = json_decode($rawBody, true);
if (!is_array($update)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload.'], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $db = fb_open_db($config);
    $summary = fb_process_telegram_webhook_update($config, $db, $update);
    fb_log('info', 'telegram.webhook.processed', [
        'updates' => (int) ($summary['updates'] ?? 0),
        'messages' => (int) ($summary['messages'] ?? 0),
        'callbacks' => (int) ($summary['callbacks'] ?? 0),
        'follows' => (int) ($summary['follows'] ?? 0),
        'menus' => (int) ($summary['menus'] ?? 0),
        'topics' => (int) ($summary['topics'] ?? 0),
        'errors' => $summary['errors'] ?? [],
    ]);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    fb_log('error', 'telegram.webhook.failed', [
        'error' => $error->getMessage(),
        'file' => $error->getFile(),
        'line' => $error->getLine(),
    ]);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Webhook processing failed.'], JSON_UNESCAPED_SLASHES);
}
