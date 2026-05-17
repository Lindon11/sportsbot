<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function fb_telegram_message_thread_id(array $config, mixed $messageThreadId = null): ?int
{
    if ($messageThreadId === null || $messageThreadId === '') {
        $messageThreadId = $config['telegram']['message_thread_id'] ?? null;
    }

    if ($messageThreadId === null || $messageThreadId === '') {
        return null;
    }

    $threadId = is_numeric((string) $messageThreadId) ? (int) $messageThreadId : 0;

    return $threadId > 0 ? $threadId : null;
}

function fb_telegram_explicit_message_thread_id(mixed $messageThreadId = null): ?int
{
    if ($messageThreadId === null || $messageThreadId === '') {
        return null;
    }

    $threadId = is_numeric((string) $messageThreadId) ? (int) $messageThreadId : 0;

    return $threadId > 0 ? $threadId : null;
}

function fb_telegram_target(string $chatId, mixed $messageThreadId = null): array
{
    $threadId = is_numeric((string) $messageThreadId) ? (int) $messageThreadId : 0;

    return [
        'chat_id' => trim($chatId),
        'message_thread_id' => $threadId > 0 ? $threadId : null,
    ];
}

function fb_telegram_target_key(array $target): string
{
    $chatId = trim((string) ($target['chat_id'] ?? ''));
    $threadId = fb_telegram_explicit_message_thread_id($target['message_thread_id'] ?? null);

    return $threadId !== null ? $chatId . ':' . $threadId : $chatId;
}

function fb_telegram_targets_from_value(mixed $value): array
{
    if (is_string($value)) {
        $items = preg_split('/[\r\n,]+/', $value) ?: [];
    } elseif (is_array($value)) {
        $items = array_key_exists('chat_id', $value) || array_key_exists('chat', $value) || array_key_exists('id', $value)
            ? [$value]
            : $value;
    } else {
        $items = [];
    }

    $targets = [];

    foreach ($items as $item) {
        $chatId = '';
        $threadId = null;

        if (is_array($item)) {
            $chatId = trim((string) ($item['chat_id'] ?? $item['chat'] ?? $item['id'] ?? ''));
            $threadId = $item['message_thread_id'] ?? $item['thread_id'] ?? $item['topic_id'] ?? $item['topic'] ?? null;
        } else {
            $raw = trim((string) $item);

            if ($raw === '') {
                continue;
            }

            if (preg_match('/^(-?\d+)[|:](\d+)$/', $raw, $matches) === 1) {
                $chatId = $matches[1];
                $threadId = (int) $matches[2];
            } else {
                $chatId = $raw;
            }
        }

        if ($chatId === '') {
            continue;
        }

        $target = fb_telegram_target($chatId, $threadId);
        $targets[fb_telegram_target_key($target)] = $target;
    }

    return array_values($targets);
}

function fb_telegram_chat_ids_from_value(mixed $value): array
{
    return array_values(array_unique(array_map(
        static fn (array $target): string => (string) $target['chat_id'],
        fb_telegram_targets_from_value($value)
    )));
}

function fb_telegram_default_targets(array $config): array
{
    $primaryChatId = trim((string) ($config['telegram']['chat_id'] ?? ''));
    $targets = [];

    if ($primaryChatId !== '') {
        $primary = fb_telegram_target($primaryChatId, $config['telegram']['message_thread_id'] ?? null);
        $targets[fb_telegram_target_key($primary)] = $primary;
    }

    foreach (fb_telegram_targets_from_value($config['telegram']['extra_chat_ids'] ?? []) as $target) {
        $targets[fb_telegram_target_key($target)] = $target;
    }

    foreach (fb_telegram_targets_from_value($config['telegram']['routes']['default'] ?? []) as $target) {
        $targets[fb_telegram_target_key($target)] = $target;
    }

    return array_values($targets);
}

function fb_telegram_default_chat_ids(array $config): array
{
    return array_values(array_unique(array_map(
        static fn (array $target): string => (string) $target['chat_id'],
        fb_telegram_default_targets($config)
    )));
}

function fb_telegram_route_targets(array $config, ?string $route = null): array
{
    $defaultTargets = fb_telegram_default_targets($config);
    $route = trim((string) $route);
    $routeKey = fb_sport_key($route);
    $isDefaultRoute = $routeKey === '' || $routeKey === 'default';
    $source = 'default';
    $targets = $defaultTargets;

    if (!$isDefaultRoute) {
        $dbTargets = fb_telegram_db_route_targets($config, $route);
        if ($dbTargets !== []) {
            // Named routes must use assigned topic targets when available.
            $source = 'db_topics';
            $targets = $dbTargets;
        } else {
            $configuredTargets = fb_telegram_config_route_targets($config, $route);
            if ($configuredTargets !== []) {
                $source = 'configured_route';
                $targets = $configuredTargets;
            } else {
                // No assigned route target: fallback to default only.
                $source = 'default_fallback';
                $targets = $defaultTargets;
            }
        }
    }

    fb_log('info', 'telegram.route_targets_resolved', [
        'route' => $route !== '' ? $route : 'default',
        'source' => $source,
        'target_count' => count($targets),
        'targets' => array_map(static function (array $target): string {
            $chatId = (string) ($target['chat_id'] ?? '');
            $thread = $target['message_thread_id'] ?? null;
            return $chatId . ':' . ($thread !== null ? (string) $thread : '-');
        }, $targets),
    ]);

    return $targets;
}

function fb_telegram_route_chat_ids(array $config, ?string $route = null): array
{
    return array_values(array_unique(array_map(
        static fn (array $target): string => (string) $target['chat_id'],
        fb_telegram_route_targets($config, $route)
    )));
}

function fb_get_route_target(array $config, ?string $route = null): array
{
    $route = trim((string) $route);
    if ($route === '') {
        $route = 'default';
    }

    $routeKey = fb_sport_key($route);
    $isDefaultRoute = $routeKey === '' || $routeKey === 'default';
    $source = 'default';
    $targets = fb_telegram_default_targets($config);
    $assigned = $isDefaultRoute;
    $fallback = false;

    if (!$isDefaultRoute) {
        $dbTargets = fb_telegram_db_route_targets($config, $route);
        if ($dbTargets !== []) {
            $source = 'db_topics';
            $targets = $dbTargets;
            $assigned = true;
            $fallback = false;
        } else {
            $configuredTargets = fb_telegram_config_route_targets($config, $route);
            if ($configuredTargets !== []) {
                $source = 'configured_route';
                $targets = $configuredTargets;
                $assigned = true;
                $fallback = false;
            } else {
                $source = 'default_fallback';
                $targets = fb_telegram_default_targets($config);
                $assigned = false;
                $fallback = true;
            }
        }
    }

    return [
        'route' => $route,
        'targets' => $targets,
        'assigned' => $assigned,
        'fallback' => $fallback,
        'source' => $source,
        'warning' => $fallback ? 'Route not assigned — using General topic.' : '',
    ];
}

function fb_telegram_db_route_targets(array $config, string $route): array
{
    $route = trim($route);
    if ($route === '' || fb_sport_key($route) === 'default' || !is_file($config['paths']['state_db'])) {
        return [];
    }

    try {
        $db = fb_open_db($config);
        $topics = fb_list_telegram_topics($db, null, 500);
        $routeRequested = strtoupper($route);
        $routeRequestedKey = strtoupper(fb_sport_key($route));
        $targets = [];

        foreach ($topics as $topic) {
            $topicRoute = strtoupper(trim((string) ($topic['route'] ?? '')));
            if ($topicRoute === '') {
                continue;
            }

            if ($topicRoute === $routeRequested || $topicRoute === $routeRequestedKey) {
                $target = [
                    'chat_id' => (string) ($topic['chat_id'] ?? ''),
                    'message_thread_id' => is_numeric((string) ($topic['message_thread_id'] ?? null))
                        ? (int) $topic['message_thread_id']
                        : null,
                ];
                if ($target['chat_id'] === '') {
                    continue;
                }
                $targets[fb_telegram_target_key($target)] = $target;
            }
        }

        return array_values($targets);
    } catch (Throwable) {
        return [];
    }
}

function fb_telegram_config_route_targets(array $config, string $route): array
{
    $route = trim($route);
    if ($route === '' || fb_sport_key($route) === 'default') {
        return [];
    }

    $routes = $config['telegram']['routes'] ?? [];
    if (!is_array($routes)) {
        return [];
    }

    $wantedKeys = array_filter(array_unique([
        fb_sport_key($route),
        fb_sport_key(fb_canonical_sport($route, $route)),
    ]));

    foreach ($routes as $routeName => $routeValue) {
        if (fb_sport_key((string) $routeName) === 'default') {
            continue;
        }

        $routeKeys = array_filter(array_unique([
            fb_sport_key((string) $routeName),
            fb_sport_key(fb_canonical_sport((string) $routeName, (string) $routeName)),
        ]));

        if (array_intersect($wantedKeys, $routeKeys) !== []) {
            return fb_telegram_targets_from_value($routeValue);
        }
    }

    return [];
}

function fb_telegram_send_photo(array $config, string $imagePath, string $caption = '', ?int $messageThreadId = null, array $options = []): array
{
    fb_require_extensions(['curl']);

    if (!is_file($imagePath)) {
        throw new RuntimeException('Telegram photo does not exist: ' . $imagePath);
    }

    $token = (string) $config['telegram']['bot_token'];
    $chatId = (string) $config['telegram']['chat_id'];
    $url = rtrim($config['telegram']['api_base'], '/') . '/bot' . $token . '/sendPhoto';

    $postFields = [
        'chat_id' => $chatId,
        'photo' => new CURLFile($imagePath, 'image/png', basename($imagePath)),
        'caption' => $caption,
        'disable_notification' => $config['telegram']['disable_notification'] ? 'true' : 'false',
    ];

    $threadId = $messageThreadId !== null
        ? fb_telegram_explicit_message_thread_id($messageThreadId)
        : fb_telegram_message_thread_id($config);
    if ($threadId !== null) {
        $postFields['message_thread_id'] = (string) $threadId;
    }

    foreach ($options as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $postFields[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) $config['telegram']['timeout'],
        CURLOPT_CONNECTTIMEOUT => (int) $config['telegram']['connect_timeout'],
        CURLOPT_USERAGENT => 'football-alert-bot/1.0',
    ]);

    $body = curl_exec($curl);

    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Telegram sendPhoto failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    $decoded = json_decode((string) $body, true);

    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        throw new RuntimeException(sprintf('Telegram sendPhoto HTTP %d: %s', $statusCode, substr((string) $body, 0, 500)));
    }

    return $decoded;
}

function fb_telegram_send_message(array $config, string $message, ?string $chatId = null, ?int $messageThreadId = null, array $options = []): array
{
    fb_require_extensions(['curl']);

    $message = trim($message);

    if ($message === '') {
        throw new RuntimeException('Telegram message cannot be empty.');
    }

    $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);

    if ($length > 4096) {
        throw new RuntimeException('Telegram message is too long. Use 4096 characters or fewer.');
    }

    $token = (string) $config['telegram']['bot_token'];
    $usingDefaultChat = $chatId === null;
    $chatId = $chatId ?? (string) $config['telegram']['chat_id'];
    $url = rtrim($config['telegram']['api_base'], '/') . '/bot' . $token . '/sendMessage';

    $postFields = [
        'chat_id' => $chatId,
        'text' => $message,
        'disable_web_page_preview' => 'true',
        'disable_notification' => ($chatId !== ($config['alerts']['error_alert_chat_id'] ?? null))
            ? ($config['telegram']['disable_notification'] ? 'true' : 'false')
            : 'false',
    ];

    $threadId = $messageThreadId !== null
        ? fb_telegram_explicit_message_thread_id($messageThreadId)
        : ($usingDefaultChat ? fb_telegram_message_thread_id($config) : null);
    if ($threadId !== null) {
        $postFields['message_thread_id'] = (string) $threadId;
    }

    foreach ($options as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $postFields[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) $config['telegram']['timeout'],
        CURLOPT_CONNECTTIMEOUT => (int) $config['telegram']['connect_timeout'],
        CURLOPT_USERAGENT => 'football-alert-bot/1.0',
    ]);

    $body = curl_exec($curl);

    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Telegram sendMessage failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    $decoded = json_decode((string) $body, true);

    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        throw new RuntimeException(sprintf('Telegram sendMessage HTTP %d: %s', $statusCode, substr((string) $body, 0, 500)));
    }

    return $decoded;
}

function fb_telegram_edit_message_text(array $config, string $chatId, int $messageId, string $text, ?int $messageThreadId = null, array $options = []): array
{
    fb_require_extensions(['curl']);

    $token = (string) ($config['telegram']['bot_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('Telegram bot token is not configured.');
    }

    $url = rtrim((string) $config['telegram']['api_base'], '/') . '/bot' . $token . '/editMessageText';
    $fields = [
        'chat_id' => $chatId,
        'message_id' => (string) $messageId,
        'text' => $text,
    ];

    if ($messageThreadId !== null) {
        $fields['message_thread_id'] = (string) $messageThreadId;
    }

    // copy options, encoding arrays as JSON where needed
    foreach ($options as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $fields[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) ($config['telegram']['timeout'] ?? 25),
        CURLOPT_CONNECTTIMEOUT => (int) ($config['telegram']['connect_timeout'] ?? 10),
        CURLOPT_USERAGENT => 'football-alert-bot/1.0',
    ]);

    $body = curl_exec($curl);

    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Telegram editMessageText failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    $decoded = json_decode((string) $body, true);

    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        throw new RuntimeException(sprintf('Telegram editMessageText HTTP %d: %s', $statusCode, substr((string) $body, 0, 500)));
    }

    return $decoded;
}

function fb_telegram_send_message_to_targets(array $config, string $message, array $targets, array $options = []): array
{
    $results = [];
    $routeMeta = is_array($options['_route_meta'] ?? null) ? $options['_route_meta'] : [];
    unset($options['_route_meta']);

    foreach (fb_telegram_targets_from_value($targets) as $target) {
        $key = fb_telegram_target_key($target);
        try {
            $results[$key] = fb_telegram_send_message(
                $config,
                $message,
                (string) $target['chat_id'],
                $target['message_thread_id'] ?? null,
                $options
            );
            fb_log('info', 'telegram.route_send', [
                'route' => (string) ($routeMeta['route'] ?? 'default'),
                'chat_id' => (string) ($target['chat_id'] ?? ''),
                'message_thread_id' => $target['message_thread_id'] ?? null,
                'post_type' => (string) ($routeMeta['post_type'] ?? 'message'),
                'event_id' => (string) ($routeMeta['event_id'] ?? ''),
                'fallback' => !empty($routeMeta['fallback']),
                'response' => $results[$key],
            ]);
        } catch (Throwable $e) {
            fb_log('warning', 'Failed to send message to chat', [
                'route' => (string) ($routeMeta['route'] ?? 'default'),
                'chat_id' => $target['chat_id'],
                'message_thread_id' => $target['message_thread_id'] ?? null,
                'post_type' => (string) ($routeMeta['post_type'] ?? 'message'),
                'event_id' => (string) ($routeMeta['event_id'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            $results[$key] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    return $results;
}

function fb_telegram_send_message_to_chats(array $config, string $message, array $chatIds): array
{
    return fb_telegram_send_message_to_targets($config, $message, $chatIds);
}

function fb_telegram_send_message_all_groups(array $config, string $message, array $options = []): array
{
    return fb_telegram_send_message_route($config, $message, 'default', $options);
}

function fb_telegram_send_message_route(array $config, string $message, ?string $route = null, array $options = []): array
{
    $routeTarget = fb_get_route_target($config, $route);
    $routeMeta = is_array($options['_route_meta'] ?? null) ? $options['_route_meta'] : [];
    $options['_route_meta'] = $routeMeta + [
        'route' => (string) ($routeTarget['route'] ?? 'default'),
        'post_type' => (string) ($routeMeta['post_type'] ?? 'message'),
        'event_id' => (string) ($routeMeta['event_id'] ?? ''),
        'fallback' => !empty($routeTarget['fallback']),
    ];
    return fb_telegram_send_message_to_targets($config, $message, $routeTarget['targets'] ?? [], $options);
}

function fb_telegram_send_error_alert(array $config, string $message, array $context = []): void
{
    $errorChatId = $config['alerts']['error_alert_chat_id'] ?? null;

    if (empty($errorChatId) || empty($config['telegram']['bot_token'])) {
        return;
    }

    $text = "⚠️ Bot Error\n" . $message;

    if ($context !== []) {
        $text .= "\n" . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    try {
        fb_telegram_send_message($config, $text, $errorChatId);
    } catch (Throwable $e) {
        fb_log('error', 'Failed to send error alert to Telegram', [
            'error' => $e->getMessage(),
        ]);
    }
}

function fb_telegram_send_photo_all_groups(array $config, string $imagePath, string $caption = '', array $options = []): array
{
    return fb_telegram_send_photo_route($config, $imagePath, $caption, 'default', $options);
}

function fb_telegram_send_photo_route(array $config, string $imagePath, string $caption = '', ?string $route = null, array $options = []): array
{
    $routeTarget = fb_get_route_target($config, $route);
    $routeMeta = is_array($options['_route_meta'] ?? null) ? $options['_route_meta'] : [];
    $options['_route_meta'] = $routeMeta + [
        'route' => (string) ($routeTarget['route'] ?? 'default'),
        'post_type' => (string) ($routeMeta['post_type'] ?? 'photo'),
        'event_id' => (string) ($routeMeta['event_id'] ?? ''),
        'fallback' => !empty($routeTarget['fallback']),
    ];
    return fb_telegram_send_photo_to_targets($config, $imagePath, $caption, $routeTarget['targets'] ?? [], $options);
}

function fb_telegram_send_photo_to_targets(array $config, string $imagePath, string $caption, array $targets, array $options = []): array
{
    $results = [];
    $routeMeta = is_array($options['_route_meta'] ?? null) ? $options['_route_meta'] : [];
    unset($options['_route_meta']);

    foreach (fb_telegram_targets_from_value($targets) as $target) {
        $key = fb_telegram_target_key($target);
        try {
            $results[$key] = fb_telegram_send_photo_to(
                $config,
                $imagePath,
                $caption,
                (string) $target['chat_id'],
                $target['message_thread_id'] ?? null,
                $options
            );
            fb_log('info', 'telegram.route_send', [
                'route' => (string) ($routeMeta['route'] ?? 'default'),
                'chat_id' => (string) ($target['chat_id'] ?? ''),
                'message_thread_id' => $target['message_thread_id'] ?? null,
                'post_type' => (string) ($routeMeta['post_type'] ?? 'photo'),
                'event_id' => (string) ($routeMeta['event_id'] ?? ''),
                'fallback' => !empty($routeMeta['fallback']),
                'response' => $results[$key],
            ]);
        } catch (Throwable $e) {
            fb_log('warning', 'Failed to send photo to chat', [
                'route' => (string) ($routeMeta['route'] ?? 'default'),
                'chat_id' => $target['chat_id'],
                'message_thread_id' => $target['message_thread_id'] ?? null,
                'post_type' => (string) ($routeMeta['post_type'] ?? 'photo'),
                'event_id' => (string) ($routeMeta['event_id'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            $results[$key] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    return $results;
}

function fb_telegram_send_photo_to_chats(array $config, string $imagePath, string $caption, array $chatIds): array
{
    return fb_telegram_send_photo_to_targets($config, $imagePath, $caption, $chatIds);
}

function fb_telegram_send_photo_to(array $config, string $imagePath, string $caption, string $chatId, ?int $messageThreadId = null, array $options = []): array
{
    fb_require_extensions(['curl']);

    if (!is_file($imagePath)) {
        throw new RuntimeException('Telegram photo does not exist: ' . $imagePath);
    }

    $token = (string) $config['telegram']['bot_token'];
    $url = rtrim($config['telegram']['api_base'], '/') . '/bot' . $token . '/sendPhoto';

    $postFields = [
        'chat_id' => $chatId,
        'photo' => new CURLFile($imagePath, 'image/png', basename($imagePath)),
        'caption' => $caption,
        'disable_notification' => $config['telegram']['disable_notification'] ? 'true' : 'false',
    ];

    $threadId = fb_telegram_explicit_message_thread_id($messageThreadId);
    if ($threadId !== null) {
        $postFields['message_thread_id'] = (string) $threadId;
    }

    foreach ($options as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $postFields[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) $config['telegram']['timeout'],
        CURLOPT_CONNECTTIMEOUT => (int) $config['telegram']['connect_timeout'],
        CURLOPT_USERAGENT => 'football-alert-bot/1.0',
    ]);

    $body = curl_exec($curl);

    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Telegram sendPhoto failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    $decoded = json_decode((string) $body, true);

    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        throw new RuntimeException(sprintf('Telegram sendPhoto HTTP %d: %s', $statusCode, substr((string) $body, 0, 500)));
    }

    return $decoded;
}

function fb_telegram_api_request(array $config, string $method, array $fields = []): array
{
    fb_require_extensions(['curl']);

    $token = (string) ($config['telegram']['bot_token'] ?? '');

    if ($token === '') {
        throw new RuntimeException('Telegram bot token is not configured.');
    }

    $url = rtrim((string) $config['telegram']['api_base'], '/') . '/bot' . $token . '/' . $method;
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) ($config['telegram']['timeout'] ?? 25),
        CURLOPT_CONNECTTIMEOUT => (int) ($config['telegram']['connect_timeout'] ?? 10),
        CURLOPT_USERAGENT => 'football-alert-bot/1.0',
    ]);

    $body = curl_exec($curl);

    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Telegram ' . $method . ' failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    $decoded = json_decode((string) $body, true);

    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        throw new RuntimeException(sprintf('Telegram %s HTTP %d: %s', $method, $statusCode, substr((string) $body, 0, 500)));
    }

    return $decoded;
}

function fb_telegram_update_offset(SQLite3 $db): int
{
    $row = fb_fetch_one($db, 'SELECT value FROM telegram_update_state WHERE state_key = "getUpdatesOffset"', []);
    $value = $row !== null ? (string) ($row['value'] ?? '') : '';

    return is_numeric($value) ? max(0, (int) $value) : 0;
}

function fb_telegram_save_update_offset(SQLite3 $db, int $offset): void
{
    fb_execute(
        $db,
        'INSERT INTO telegram_update_state (state_key, value, updated_at)
         VALUES ("getUpdatesOffset", :value, :updated_at)
         ON CONFLICT(state_key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
        [
            ':value' => (string) max(0, $offset),
            ':updated_at' => fb_now(),
        ]
    );
}

function fb_telegram_answer_callback(array $config, string $callbackQueryId, string $text, bool $showAlert = false): void
{
    if ($callbackQueryId === '') {
        return;
    }

    try {
        fb_telegram_api_request($config, 'answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => substr($text, 0, 190),
            'show_alert' => $showAlert ? 'true' : 'false',
        ]);
    } catch (Throwable $error) {
        fb_log('warning', 'Could not answer Telegram callback', [
            'error' => $error->getMessage(),
        ]);
    }
}

function fb_telegram_save_topic_from_message(SQLite3 $db, array $message): bool
{
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $chatId = trim((string) ($chat['id'] ?? ''));
    $threadId = is_numeric((string) ($message['message_thread_id'] ?? '')) ? (int) $message['message_thread_id'] : 0;

    if ($chatId === '' || $threadId <= 0) {
        return false;
    }

    $name = '';
    $meta = [];
    $source = 'message';

    if (is_array($message['forum_topic_created'] ?? null)) {
        $topic = $message['forum_topic_created'];
        $name = trim((string) ($topic['name'] ?? ''));
        $meta = $topic;
        $source = 'forum_topic_created';
    } elseif (is_array($message['forum_topic_edited'] ?? null)) {
        $topic = $message['forum_topic_edited'];
        $name = trim((string) ($topic['name'] ?? ''));
        $meta = $topic;
        $source = 'forum_topic_edited';
    }

    return fb_save_telegram_topic($db, $chatId, $threadId, $name !== '' ? $name : null, $meta, $source);
}

function fb_telegram_message_text(array $message): string
{
    return trim((string) ($message['text'] ?? $message['caption'] ?? ''));
}

function fb_telegram_is_menu_command(string $text): bool
{
    return preg_match('/^\/(?:start|menu|help)(?:@\w+)?(?:\s|$)/i', trim($text)) === 1;
}

function fb_telegram_topic_label_from_command(string $text): ?string
{
    if (preg_match('/^\/(?:topic|settopic)(?:@\w+)?\s+(.+)$/is', trim($text), $matches) !== 1) {
        return null;
    }

    $name = trim((string) $matches[1]);

    return $name !== '' ? substr($name, 0, 80) : null;
}

function fb_telegram_process_message_update(array $config, SQLite3 $db, array $message): array
{
    $summary = [
        'topic_saved' => false,
        'menu_sent' => false,
        'topic_label_saved' => false,
    ];

    $summary['topic_saved'] = fb_telegram_save_topic_from_message($db, $message);

    $text = fb_telegram_message_text($message);
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $chatId = trim((string) ($chat['id'] ?? ''));
    $threadId = is_numeric((string) ($message['message_thread_id'] ?? '')) ? (int) $message['message_thread_id'] : null;
    $topicLabel = fb_telegram_topic_label_from_command($text);

    if ($topicLabel !== null && $chatId !== '' && $threadId !== null) {
        if (fb_save_telegram_topic($db, $chatId, $threadId, $topicLabel, [], 'topic_command')) {
            $summary['topic_label_saved'] = true;
            $summary['topic_saved'] = true;
            fb_telegram_send_message($config, 'Topic label saved: ' . $topicLabel, $chatId, $threadId);
        }

        return $summary;
    }

    if (!fb_telegram_is_menu_command($text)) {
        return $summary;
    }

    if ($chatId === '') {
        return $summary;
    }

    fb_telegram_send_message(
        $config,
        fb_bot_menu_text(),
        $chatId,
        $threadId,
        ['reply_markup' => fb_bot_menu_reply_markup($config, $db, $chatId), 'parse_mode' => 'HTML']
    );
    $summary['menu_sent'] = true;

    return $summary;
}

function fb_telegram_menu_callback_action(string $callbackData): ?string
{
    $parts = explode('|', trim($callbackData));

    if (count($parts) !== 2 || $parts[0] !== 'fbm') {
        return null;
    }

    $action = preg_replace('/[^a-z0-9_]/', '', strtolower($parts[1]));

    $allowed = [
        'home', 'live_all', 'live_football', 'fixtures_all', 'fixtures_football', 'fixtures_basketball',
        'tv_now', 'tv_today', 'tables_football', 'my_teams', 'premium',
        // keep older aliases
        'live', 'fixtures', 'tv', 'tables', 'scorers', 'favourites'
    ];

    return in_array($action, $allowed, true) ? $action : null;
}

function fb_telegram_process_menu_callback(array $config, SQLite3 $db, array $callback, string $action): bool
{
    $callbackId = (string) ($callback['id'] ?? '');
    $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $chatId = trim((string) ($chat['id'] ?? ''));
    $threadId = is_numeric((string) ($message['message_thread_id'] ?? '')) ? (int) $message['message_thread_id'] : null;

    fb_telegram_answer_callback($config, $callbackId, fb_bot_menu_action_label($action));

    if ($chatId === '') {
        return false;
    }

    fb_telegram_save_topic_from_message($db, $message);

    // Home/main menu
    if ($action === 'home') {
        $text = fb_bot_menu_text();
        $reply = fb_bot_menu_reply_markup($config, $db, $chatId);

        // Try to edit the originating message when possible
        $messageId = is_numeric((string) ($message['message_id'] ?? '')) ? (int) $message['message_id'] : null;

        if ($messageId !== null) {
            try {
                fb_telegram_edit_message_text($config, $chatId, $messageId, $text, $threadId, ['reply_markup' => $reply, 'parse_mode' => 'HTML']);
                return true;
            } catch (Throwable $e) {
                // fallback to sendMessage below
            }
        }

        fb_telegram_send_message(
            $config,
            $text,
            $chatId,
            $threadId,
            ['reply_markup' => $reply, 'parse_mode' => 'HTML']
        );

        return true;
    }

    // Prepare HTML-formatted submenu content
    try {
        $html = fb_format_bot_submenu_message($config, $db, $action);
    } catch (Throwable $e) {
        $html = fb_text_to_html('That submenu is unavailable right now: ' . $e->getMessage());
    }

    $replyMarkup = fb_bot_submenu_reply_markup($config, $db, $chatId, 'home');

    $messageId = is_numeric((string) ($message['message_id'] ?? '')) ? (int) $message['message_id'] : null;

    if ($messageId !== null) {
        try {
            fb_telegram_edit_message_text($config, $chatId, $messageId, $html, $threadId, ['reply_markup' => $replyMarkup, 'parse_mode' => 'HTML']);
            return true;
        } catch (Throwable $e) {
            // fall through to sendMessage fallback
        }
    }

    fb_telegram_send_message(
        $config,
        $html,
        $chatId,
        $threadId,
        ['reply_markup' => $replyMarkup, 'parse_mode' => 'HTML']
    );

    return true;
}

function fb_process_telegram_updates(array $config, SQLite3 $db): array
{
    $summary = [
        'updates' => 0,
        'messages' => 0,
        'callbacks' => 0,
        'follows' => 0,
        'menus' => 0,
        'topics' => 0,
        'errors' => [],
    ];

    if (empty($config['telegram']['updates_enabled']) || empty($config['telegram']['bot_token'])) {
        return $summary;
    }

    try {
        $payload = fb_telegram_api_request($config, 'getUpdates', [
            'offset' => (string) fb_telegram_update_offset($db),
            'limit' => '50',
            'timeout' => '0',
            'allowed_updates' => json_encode(['message', 'callback_query'], JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $error) {
        fb_log('warning', 'Could not poll Telegram updates', [
            'error' => $error->getMessage(),
        ]);
        $summary['errors'][] = $error->getMessage();
        return $summary;
    }

    $updates = is_array($payload['result'] ?? null) ? $payload['result'] : [];
    $nextOffset = null;

    foreach ($updates as $update) {
        if (!is_array($update)) {
            continue;
        }

        $summary['updates']++;
        $updateId = (int) ($update['update_id'] ?? 0);
        if ($updateId > 0) {
            $nextOffset = max((int) ($nextOffset ?? 0), $updateId + 1);
        }

        $messageUpdate = $update['message'] ?? null;
        if (is_array($messageUpdate)) {
            $summary['messages']++;
            try {
                $messageSummary = fb_telegram_process_message_update($config, $db, $messageUpdate);
                if (!empty($messageSummary['topic_saved'])) {
                    $summary['topics']++;
                }
                if (!empty($messageSummary['menu_sent'])) {
                    $summary['menus']++;
                }
            } catch (Throwable $error) {
                fb_log('warning', 'Could not process Telegram message update', [
                    'error' => $error->getMessage(),
                ]);
                $summary['errors'][] = $error->getMessage();
            }
        }

        $callback = $update['callback_query'] ?? null;
        if (!is_array($callback)) {
            continue;
        }

        $summary['callbacks']++;
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $menuAction = fb_telegram_menu_callback_action($data);

        if ($menuAction !== null) {
            try {
                if (fb_telegram_process_menu_callback($config, $db, $callback, $menuAction)) {
                    $summary['menus']++;
                }
            } catch (Throwable $error) {
                fb_log('warning', 'Could not process Telegram menu callback', [
                    'action' => $menuAction,
                    'error' => $error->getMessage(),
                ]);
                fb_telegram_answer_callback($config, $callbackId, 'Could not load that menu right now.', true);
                $summary['errors'][] = $error->getMessage();
            }
            continue;
        }

        $follow = fb_follow_button_payload($db, $data);

        if ($follow === null) {
            fb_telegram_answer_callback($config, $callbackId, 'That follow button has expired.', true);
            continue;
        }

        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = trim((string) ($chat['id'] ?? ''));
        $threadId = is_numeric((string) ($message['message_thread_id'] ?? '')) ? (int) $message['message_thread_id'] : null;

        if ($chatId === '') {
            fb_telegram_answer_callback($config, $callbackId, 'Open the bot chat once, then try again.', true);
            continue;
        }

        fb_save_customer_follow(
            $db,
            $chatId,
            $threadId,
            is_array($callback['from'] ?? null) ? $callback['from'] : [],
            $follow
        );

        $summary['follows']++;
        $answerText = (($follow['kind'] ?? '') === 'feed')
            ? 'Subscribed to ' . $follow['subject'] . '. Your preference is saved.'
            : 'Following ' . $follow['subject'] . '. Updates will be highlighted in this group.';
        fb_telegram_answer_callback(
            $config,
            $callbackId,
            $answerText
        );
    }

    if ($nextOffset !== null) {
        fb_telegram_save_update_offset($db, (int) $nextOffset);
    }

    return $summary;
}
