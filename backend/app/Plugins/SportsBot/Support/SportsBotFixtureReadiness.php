<?php

namespace App\Plugins\SportsBot\Support;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use Carbon\Carbon;
use Throwable;

class SportsBotFixtureReadiness
{
    /**
     * @return array{missing_tv:bool,missing_poster:bool,enrichment_due:bool,due:bool,fields:array<int,string>,reason:string}
     */
    public static function enrichmentNeeds(SportsBotFixtureQueue $item, bool $force = false): array
    {
        $fixture = (array) ($item->fixture_data ?? []);
        $payload = (array) ($item->payload ?? []);
        $scraper = (array) ($payload['scraper'] ?? []);
        $normalized = (array) ($scraper['normalized'] ?? []);
        $scrapedFields = (array) ($normalized['fields'] ?? []);
        $fields = [];

        $missingTv = !self::hasTv($fixture);
        if ($missingTv && !self::hasUsableTvFields($scrapedFields)) {
            $fields[] = 'tv';
        }

        $missingPoster = !self::hasPoster($fixture);
        if ($missingPoster && !self::hasPoster($scrapedFields)) {
            $fields[] = 'poster';
        }

        $retry = (array) ($scraper['retry'] ?? []);
        $nextCheck = trim((string) ($retry['next_check_at'] ?? ''));
        $due = $force || $nextCheck === '';
        if (!$due) {
            try {
                $due = Carbon::parse($nextCheck)->lte(now());
            } catch (Throwable) {
                $due = true;
            }
        }

        return [
            'missing_tv' => $missingTv,
            'missing_poster' => $missingPoster,
            'enrichment_due' => $fields !== [] && $due,
            'due' => $due,
            'fields' => $fields,
            'reason' => $fields === [] ? 'complete' : ($due ? 'missing_' . implode('_and_', $fields) : 'cooldown'),
        ];
    }

    public static function hasTv(array $fixture): bool
    {
        foreach (['tv_channel', 'strChannel'] as $field) {
            $channel = trim((string) ($fixture[$field] ?? ''));
            if ($channel !== '' && !self::placeholderTvChannel($channel)) {
                return true;
            }
        }

        foreach ((array) ($fixture['tv_channels'] ?? []) as $channel) {
            $channel = trim((string) $channel);
            if ($channel !== '' && !self::placeholderTvChannel($channel)) {
                return true;
            }
        }

        return false;
    }

    public static function hasUsableTvFields(array $fields): bool
    {
        return self::hasTv($fields);
    }

    public static function hasPoster(array $fixture): bool
    {
        foreach (['event_poster', 'poster', 'event_thumb', 'strThumb'] as $field) {
            if (trim((string) ($fixture[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public static function placeholderTvChannel(string $channel): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $channel) ?? $channel));
        $normalized = trim($normalized, " \t\n\r\0\x0B.:-");

        return in_array($normalized, [
            '',
            'not listed',
            'not shown',
            'not available',
            'no tv',
            'none',
            'unknown',
            'tbc',
            'tv tbc',
            'channel tbc',
            'channels tbc',
            'n/a',
            'na',
            '-',
        ], true);
    }

    public static function fallbackActive(SportsBotFixtureQueue $item): bool
    {
        $diagnostics = (array) ($item->render_diagnostics ?? []);
        $proof = (array) ($diagnostics['proof'] ?? []);

        return (string) ($item->renderer_used ?? '') === 'gd_v3'
            || trim((string) ($item->fallback_reason ?? '')) !== ''
            || (bool) ($proof['fallback_active'] ?? false);
    }

    public static function hasCurrentCard(SportsBotFixtureQueue $item): bool
    {
        $cardPath = SportsBotPaths::cardPath((string) ($item->card_path ?? ''));

        return $cardPath !== '' && @is_file($cardPath);
    }
}
