<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotTelegramRoute;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramRoutingService
{
    /**
     * @return array{route_key:string,resolved_route_key:string,fallback:bool,target_count:int,targets:array<int,array{chat_id:string,message_thread_id:int|null}>,source:string}
     */
    public function resolveTargets(string $routeKey): array
    {
        $normalizedRouteKey = TelegramRouteKeys::normalize($routeKey);
        $isDefaultRoute = $normalizedRouteKey === TelegramRouteKeys::DEFAULT;

        $resolvedRouteKey = $normalizedRouteKey;
        $fallback = false;
        $source = 'default';

        if ($isDefaultRoute) {
            $targets = $this->defaultTargets();
            $source = $this->defaultSource($targets);
        } else {
            $targets = $this->namedRouteTargets($normalizedRouteKey);

            if ($targets === []) {
                foreach (TelegramRouteKeys::fallbackRouteKeys($normalizedRouteKey) as $fallbackRouteKey) {
                    $targets = $this->namedRouteTargets($fallbackRouteKey);

                    if ($targets !== []) {
                        $resolvedRouteKey = $fallbackRouteKey;
                        $fallback = true;
                        $source = 'route_group_fallback';
                        break;
                    }
                }

                if ($targets === []) {
                    $targets = $this->defaultTargets();
                    $resolvedRouteKey = TelegramRouteKeys::DEFAULT;
                    $fallback = true;
                    $source = 'default_fallback';
                }
            } else {
                $source = 'named_route';
            }
        }

        $result = [
            'route_key' => $normalizedRouteKey,
            'resolved_route_key' => $resolvedRouteKey,
            'fallback' => $fallback,
            'target_count' => count($targets),
            'targets' => $targets,
            'source' => $source,
        ];

        Log::info('sportsbot.telegram.route_targets_resolved', [
            'route_key' => $normalizedRouteKey,
            'resolved_route_key' => $resolvedRouteKey,
            'fallback' => $fallback,
            'target_count' => count($targets),
            'source' => $source,
            'targets' => array_map(
                static fn (array $target): string => $target['chat_id'] . ':' . ($target['message_thread_id'] ?? '-'),
                $targets
            ),
        ]);

        return $result;
    }

    /**
     * @return array<int, array{chat_id:string,message_thread_id:int|null}>
     */
    private function namedRouteTargets(string $routeKey): array
    {
        try {
            $rows = SportsBotTelegramRoute::query()
                ->where('route_key', $routeKey)
                ->where('enabled', true)
                ->orderBy('id')
                ->get(['chat_id', 'message_thread_id']);
        } catch (Throwable) {
            return [];
        }

        return $this->uniqueTargets($rows->map(static function ($row): array {
            return [
                'chat_id' => (string) $row->chat_id,
                'message_thread_id' => $row->message_thread_id !== null ? (int) $row->message_thread_id : null,
            ];
        })->all());
    }

    /**
     * @return array<int, array{chat_id:string,message_thread_id:int|null}>
     */
    private function defaultTargets(): array
    {
        try {
            $rows = SportsBotTelegramRoute::query()
                ->where('enabled', true)
                ->where(function ($query): void {
                    $query->where('route_key', TelegramRouteKeys::DEFAULT)
                        ->orWhere('fallback', true);
                })
                ->orderByDesc('fallback')
                ->orderBy('id')
                ->get(['chat_id', 'message_thread_id']);
        } catch (Throwable) {
            return $this->configDefaultTargets();
        }

        $dbTargets = $this->uniqueTargets($rows->map(static function ($row): array {
            return [
                'chat_id' => (string) $row->chat_id,
                'message_thread_id' => $row->message_thread_id !== null ? (int) $row->message_thread_id : null,
            ];
        })->all());

        if ($dbTargets !== []) {
            return $dbTargets;
        }

        return $this->configDefaultTargets();
    }

    /**
     * @param array<int, array{chat_id:string,message_thread_id:int|null}> $targets
     */
    private function defaultSource(array $targets): string
    {
        if ($targets === []) {
            return 'none';
        }

        try {
            $hasDefaultRoutes = SportsBotTelegramRoute::query()
                ->where('enabled', true)
                ->where(function ($query): void {
                    $query->where('route_key', TelegramRouteKeys::DEFAULT)
                        ->orWhere('fallback', true);
                })
                ->exists();
        } catch (Throwable) {
            return 'config_default';
        }

        return $hasDefaultRoutes ? 'default_route' : 'config_default';
    }

    /**
     * @return array<int, array{chat_id:string,message_thread_id:int|null}>
     */
    private function configDefaultTargets(): array
    {
        $targets = [];

        $primaryChatId = trim((string) config('plugins.SportsBot.telegram.chat_id', ''));
        $primaryThreadId = $this->normalizeThreadId(config('plugins.SportsBot.telegram.message_thread_id'));

        if ($primaryChatId !== '') {
            $targets[] = [
                'chat_id' => $primaryChatId,
                'message_thread_id' => $primaryThreadId,
            ];
        }

        $extraTargets = $this->targetsFromValue(config('plugins.SportsBot.telegram.extra_chat_ids', []));

        return $this->uniqueTargets(array_merge($targets, $extraTargets));
    }

    /**
     * @return array<int, array{chat_id:string,message_thread_id:int|null}>
     */
    private function targetsFromValue(mixed $value): array
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
                $threadId = $this->normalizeThreadId($item['message_thread_id'] ?? $item['thread_id'] ?? $item['topic_id'] ?? null);
            } else {
                $raw = trim((string) $item);

                if ($raw === '') {
                    continue;
                }

                if (preg_match('/^(-?\d+)[|:](\d+)$/', $raw, $matches) === 1) {
                    $chatId = $matches[1];
                    $threadId = $this->normalizeThreadId($matches[2]);
                } else {
                    $chatId = $raw;
                }
            }

            if ($chatId === '') {
                continue;
            }

            $targets[] = [
                'chat_id' => $chatId,
                'message_thread_id' => $threadId,
            ];
        }

        return $this->uniqueTargets($targets);
    }

    /**
     * @param array<int, array{chat_id:string,message_thread_id:int|null}> $targets
     * @return array<int, array{chat_id:string,message_thread_id:int|null}>
     */
    private function uniqueTargets(array $targets): array
    {
        $unique = [];

        foreach ($targets as $target) {
            $chatId = trim((string) ($target['chat_id'] ?? ''));
            if ($chatId === '') {
                continue;
            }

            $threadId = $this->normalizeThreadId($target['message_thread_id'] ?? null);
            $key = $chatId . ':' . ($threadId ?? '-');

            $unique[$key] = [
                'chat_id' => $chatId,
                'message_thread_id' => $threadId,
            ];
        }

        return array_values($unique);
    }

    private function normalizeThreadId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $threadId = is_numeric((string) $value) ? (int) $value : 0;

        return $threadId > 0 ? $threadId : null;
    }
}
