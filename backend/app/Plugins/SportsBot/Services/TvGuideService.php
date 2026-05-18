<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Throwable;

class TvGuideService
{
    public function __construct(
        private readonly TheSportsDbClient $provider = new TheSportsDbClient(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSummary(): array
    {
        $timezoneName = (string) config('app.timezone', 'UTC');
        $tz = new DateTimeZone($timezoneName);
        $now = CarbonImmutable::now($tz);
        $hoursAhead = max(1, (int) config('plugins.SportsBot.tv.lookahead_hours', 24));
        $cutoff = $now->addHours($hoursAhead);
        $channels = $this->configuredChannels();
        $sportFilter = $this->sportFilter();
        $events = [];
        $seen = [];
        $errors = [];

        if (!(bool) config('plugins.SportsBot.tv.enabled', true)) {
            $channels = [];
        }

        foreach ($channels as $channel) {
            try {
                $rows = $this->provider->fetchTvByChannel($channel['slug']);
            } catch (Throwable $error) {
                $errors[] = $channel['slug'] . ': ' . $error->getMessage();
                Log::warning('sportsbot.tv_guide.channel_fetch_failed', [
                    'channel' => $channel['slug'],
                    'error' => $error->getMessage(),
                ]);
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $event = $this->normalizeEvent($row, $channel, $tz);
                $eventSportKey = $this->normalizeKey((string) ($event['sport'] ?? ''));

                if ($sportFilter !== [] && !isset($sportFilter[$eventSportKey])) {
                    continue;
                }

                $startsAt = $event['starts_at_carbon'] ?? null;
                if ($startsAt instanceof CarbonImmutable && ($startsAt->lt($now) || $startsAt->gt($cutoff))) {
                    continue;
                }

                $dedupeKey = (($event['event_id'] ?? '') !== '' ? $event['event_id'] : $event['id']) . ':' . $channel['slug'];
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                unset($event['starts_at_carbon']);
                $events[] = $event;
            }
        }

        usort($events, static function (array $a, array $b): int {
            $timeCompare = ((int) ($a['sort_time'] ?? PHP_INT_MAX)) <=> ((int) ($b['sort_time'] ?? PHP_INT_MAX));

            return $timeCompare !== 0 ? $timeCompare : strcmp((string) ($a['channel'] ?? ''), (string) ($b['channel'] ?? ''));
        });

        $grouped = [];
        foreach ($channels as $channel) {
            $grouped[$channel['slug']] = [];
        }

        foreach ($events as $event) {
            $grouped[(string) ($event['configured_channel_slug'] ?? '')][] = $event;
        }

        $sportsGrouped = array_values(array_unique(array_filter(array_map(
            static fn (array $event): string => trim((string) ($event['sport'] ?? '')),
            $events
        ))));

        $summary = [
            'route_key' => TelegramRouteKeys::TV_GUIDE,
            'date' => $now->toDateString(),
            'timezone' => $timezoneName,
            'hours_ahead' => $hoursAhead,
            'channels' => $channels,
            'events_total' => count($events),
            'grouped' => $grouped,
            'sports_grouped' => $sportsGrouped,
            'errors' => $errors,
        ];

        Log::info('sportsbot.tv_guide.summary', [
            'route_key' => TelegramRouteKeys::TV_GUIDE,
            'events_total' => count($events),
            'channels_count' => count($channels),
            'sports_grouped' => $sportsGrouped,
            'errors_count' => count($errors),
        ]);

        return $summary;
    }

    /**
     * @return array<int, array{slug:string,label:string}>
     */
    private function configuredChannels(): array
    {
        $channels = [];

        foreach ((array) $this->settings->get('tv_channels', config('plugins.SportsBot.tv.channels', [])) as $channel) {
            $label = $this->channelLabel((string) $channel);
            $slug = $this->channelSlug((string) $channel);

            if ($slug === '') {
                continue;
            }

            $channels[$slug] = [
                'slug' => $slug,
                'label' => $label !== '' ? $label : $this->channelLabel($slug),
            ];
        }

        return array_values($channels);
    }

    /**
     * @return array<string, true>
     */
    private function sportFilter(): array
    {
        $sports = [];

        foreach ((array) $this->settings->get('enabled_sports', config('plugins.SportsBot.tv.sports', [])) as $sport) {
            $key = $this->normalizeKey((string) $sport);
            if ($key !== '') {
                $sports[$key] = true;
            }

            if ($key === 'football') {
                $sports['soccer'] = true;
            }
        }

        return $sports;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{slug:string,label:string} $channel
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $row, array $channel, DateTimeZone $tz): array
    {
        $startsAt = $this->eventTime($row, $tz);
        $homeTeam = trim((string) ($row['strHomeTeam'] ?? ''));
        $awayTeam = trim((string) ($row['strAwayTeam'] ?? ''));
        $eventName = trim((string) ($row['strEvent'] ?? $row['strFilename'] ?? ''));

        if ($homeTeam !== '' && $awayTeam !== '') {
            $eventName = $homeTeam . ' vs ' . $awayTeam;
        } elseif ($eventName === '') {
            $eventName = 'TV event';
        }

        $channelName = trim((string) ($row['strChannel'] ?? '')) ?: $channel['label'];
        $channelSlug = $this->channelSlug($channelName) ?: $channel['slug'];

        return [
            'id' => (string) ($row['id'] ?? $row['idTV'] ?? sha1(json_encode($row))),
            'event_id' => (string) ($row['idEvent'] ?? ''),
            'sport' => $this->canonicalSport((string) ($row['strSport'] ?? '')),
            'league' => trim((string) ($row['strLeague'] ?? '')),
            'event' => $eventName,
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'channel' => $channelName,
            'channel_slug' => $channelSlug,
            'configured_channel_slug' => $channel['slug'],
            'configured_channel_label' => $channel['label'],
            'starts_at' => $startsAt?->toIso8601String() ?? '',
            'starts_at_carbon' => $startsAt,
            'sort_time' => $startsAt?->timestamp ?? PHP_INT_MAX,
            'time_label' => $startsAt?->format('D H:i') ?? trim((string) (($row['dateEvent'] ?? '') . ' ' . ($row['strTime'] ?? ''))),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function eventTime(array $row, DateTimeZone $tz): ?CarbonImmutable
    {
        $timestamp = trim((string) ($row['strTimestamp'] ?? $row['strEventTimestamp'] ?? $row['strTimeStamp'] ?? ''));

        if ($timestamp !== '') {
            try {
                return CarbonImmutable::parse($timestamp, 'UTC')->setTimezone($tz);
            } catch (Throwable) {
            }
        }

        $date = trim((string) ($row['dateEvent'] ?? $row['dateTV'] ?? ''));
        $time = trim((string) ($row['strTime'] ?? $row['strEventTime'] ?? '00:00:00'));

        if ($date === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($date . ' ' . $time), $tz)->setTimezone($tz);
        } catch (Throwable) {
            return null;
        }
    }

    private function channelSlug(string $channel): string
    {
        $channel = strtolower(trim($channel));
        $channel = str_replace('&', ' and ', $channel);
        $channel = preg_replace('/[^a-z0-9]+/', '_', $channel) ?? $channel;
        $channel = preg_replace('/_+/', '_', $channel) ?? $channel;

        return trim($channel, '_');
    }

    private function channelLabel(string $channel): string
    {
        $channel = trim($channel);

        if ($channel === '') {
            return '';
        }

        if (preg_match('/[A-Z ]/', $channel) === 1 && !str_contains($channel, '_')) {
            return preg_replace('/\s+/', ' ', $channel) ?? $channel;
        }

        $label = ucwords(str_replace('_', ' ', $this->channelSlug($channel)));

        return str_replace(
            ['Bbc', 'Itv', 'Tnt', 'Dazn', ' Tv', ' F1'],
            ['BBC', 'ITV', 'TNT', 'DAZN', ' TV', ' F1'],
            $label
        );
    }

    private function canonicalSport(string $sport): string
    {
        $key = $this->normalizeKey($sport);

        return match ($key) {
            'soccer', 'football' => 'Football',
            'basketball' => 'Basketball',
            'baseball' => 'Baseball',
            'mma', 'mixed martial arts', 'ufc' => 'MMA',
            'tennis' => 'Tennis',
            'rugby', 'rugby union', 'rugby league' => 'Rugby',
            'ice hockey', 'hockey', 'nhl' => 'Ice Hockey',
            default => trim($sport) !== '' ? trim($sport) : 'Sport',
        };
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);

        return preg_replace('/\s+/', ' ', $value) ?: $value;
    }
}
