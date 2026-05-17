<?php

namespace App\Plugins\SportsBot\Services;

use App\Core\Services\GameHooks;
use App\Plugins\SportsBot\Contracts\MessageRendererInterface;
use App\Plugins\SportsBot\Contracts\NotifierInterface;
use App\Plugins\SportsBot\Contracts\SportsDataProviderInterface;
use App\Plugins\SportsBot\Models\SportsBotMatchState;
use App\Plugins\SportsBot\Models\SportsBotRun;
use App\Plugins\SportsBot\Models\SportsBotSentAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SportsBotRunner
{
    public function __construct(
        private readonly SportsDataProviderInterface $provider = new TheSportsDbClient(),
        private readonly MessageRendererInterface $renderer = new DefaultMessageRenderer(),
        private readonly NotifierInterface $notifier = new TelegramNotifier(),
    ) {
    }

    public function run(bool $dryRun = false, ?bool $sendOverride = null): array
    {
        $startedAt = now();
        $run = SportsBotRun::create([
            'mode' => 'native',
            'dry_run' => $dryRun,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        $summary = [
            'total_live_scores' => 0,
            'normalized_matches' => 0,
            'allowed_matches' => 0,
            'generated_alerts' => 0,
            'duplicate_alerts' => 0,
            'sent_alerts' => 0,
            'dry_run_alerts' => 0,
            'send_enabled' => false,
            'messages' => [],
        ];

        try {
            $shouldSend = !$dryRun && ($sendOverride ?? (bool) config('plugins.SportsBot.send_messages', false));
            $summary['send_enabled'] = $shouldSend;

            $rows = $this->applyFilter('sportsbot.live_rows', $this->provider->fetchLiveScores());
            $summary['total_live_scores'] = count($rows);

            $limit = max(1, (int) config('plugins.SportsBot.coverage.max_live_matches_per_run', 75));

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $match = $this->applyFilter('sportsbot.match.normalized', $this->normalizeMatch($row));

                if (!$match) {
                    continue;
                }

                $summary['normalized_matches']++;

                if (!$this->isAllowed($match)) {
                    continue;
                }

                $summary['allowed_matches']++;

                if ($summary['allowed_matches'] > $limit) {
                    break;
                }

                $previous = SportsBotMatchState::where('event_id', $match['event_id'])->first();
                $alerts = $this->detectAlerts($match, $previous);
                $alerts = $this->applyAlertFilter($match, $previous, $alerts);
                $summary['generated_alerts'] += count($alerts);

                foreach ($alerts as $alert) {
                    if (SportsBotSentAlert::where('alert_key', $alert['alert_key'])->exists()) {
                        $summary['duplicate_alerts']++;
                        continue;
                    }

                    $message = $this->renderMessage($alert);

                    if ($shouldSend) {
                        $results = $this->notifier->send($message, ['alert' => $alert]);
                        $this->markAlertSent($alert, $results, now());
                        GameHooks::apply('sportsbot.alert.sent', ['alert' => $alert, 'results' => $results]);
                        $summary['sent_alerts']++;
                    } else {
                        $summary['dry_run_alerts']++;
                        $summary['messages'][] = $this->summaryMessage($alert);
                    }
                }

                $this->upsertMatchState($match, $previous);
            }

            $summary['finished_in_seconds'] = now()->diffInSeconds($startedAt);
            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'summary' => $summary,
            ]);

            return $summary;
        } catch (Throwable $error) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $summary,
                'error' => $error->getMessage(),
            ]);

            Log::error('sportsbot.native_run.failed', [
                'error' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ]);

            throw $error;
        }
    }

    public function health(): array
    {
        $providerKeyConfigured = trim((string) config('plugins.SportsBot.provider.api_key', '')) !== '';
        $telegramConfigured = (new TelegramNotifier())->configured();
        $sendMessages = (bool) config('plugins.SportsBot.send_messages', false);

        return [
            'ok' => (bool) config('plugins.SportsBot.enabled', true)
                && $providerKeyConfigured
                && (!$sendMessages || $telegramConfigured),
            'plugin_enabled' => (bool) config('plugins.SportsBot.enabled', true),
            'schedule_enabled' => (bool) config('plugins.SportsBot.schedule.enabled', false),
            'send_messages' => $sendMessages,
            'provider' => (string) config('plugins.SportsBot.provider.name', 'thesportsdb'),
            'provider_key_configured' => $providerKeyConfigured,
            'telegram_configured' => $telegramConfigured,
            'enabled_sports' => (array) config('plugins.SportsBot.coverage.enabled_sports', []),
            'allowed_league_count' => count((array) config('plugins.SportsBot.coverage.allowed_league_ids', [])),
        ];
    }

    private function normalizeMatch(array $row): ?array
    {
        $eventId = trim((string) ($row['idEvent'] ?? $row['event_id'] ?? ''));

        if ($eventId === '') {
            return null;
        }

        $homeTeam = trim((string) ($row['strHomeTeam'] ?? $row['home_team'] ?? ''));
        $awayTeam = trim((string) ($row['strAwayTeam'] ?? $row['away_team'] ?? ''));

        if ($homeTeam === '' || $awayTeam === '') {
            return null;
        }

        $match = [
            'event_id' => $eventId,
            'live_score_id' => $this->nullableString($row['idLiveScore'] ?? null),
            'sport' => $this->canonicalSport((string) ($row['strSport'] ?? 'Soccer')),
            'league_id' => $this->nullableString($row['idLeague'] ?? null),
            'league_name' => $this->nullableString($row['strLeague'] ?? null),
            'home_team_id' => $this->nullableString($row['idHomeTeam'] ?? null),
            'away_team_id' => $this->nullableString($row['idAwayTeam'] ?? null),
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'home_badge' => $this->nullableString($row['strHomeTeamBadge'] ?? null),
            'away_badge' => $this->nullableString($row['strAwayTeamBadge'] ?? null),
            'status' => $this->nullableString($row['strStatus'] ?? $row['status'] ?? null),
            'progress' => $this->nullableString($row['strProgress'] ?? $row['progress'] ?? null),
            'home_score' => $this->nullableInt($row['intHomeScore'] ?? $row['home_score'] ?? null),
            'away_score' => $this->nullableInt($row['intAwayScore'] ?? $row['away_score'] ?? null),
        ];

        $match['raw_hash'] = hash('sha256', json_encode($match, JSON_THROW_ON_ERROR));

        return $match;
    }

    private function isAllowed(array $match): bool
    {
        $enabledSports = $this->normalizeList((array) config('plugins.SportsBot.coverage.enabled_sports', ['Soccer']));
        $sport = $this->normalizeKey((string) ($match['sport'] ?? ''));
        $allowed = $sport !== '' && isset($enabledSports[$sport]);

        $allowedLeagueIds = array_map('strval', (array) config('plugins.SportsBot.coverage.allowed_league_ids', []));

        if ($allowed && $allowedLeagueIds !== []) {
            $leagueId = (string) ($match['league_id'] ?? '');
            $allowed = $leagueId !== '' && in_array($leagueId, $allowedLeagueIds, true);
        }

        $payload = GameHooks::apply('sportsbot.match.allowed', [
            'match' => $match,
            'allowed' => $allowed,
        ]);

        return (bool) ($payload['allowed'] ?? $allowed);
    }

    private function detectAlerts(array $match, ?SportsBotMatchState $previous): array
    {
        if (!$previous) {
            if (
                (bool) config('plugins.SportsBot.features.send_first_seen_live_alerts', false)
                && $this->isLiveStatus($match['status'])
            ) {
                return [$this->alert('match_started', $match)];
            }

            return [];
        }

        $alerts = [];

        if (
            (bool) config('plugins.SportsBot.features.send_score_updates', true)
            && ($previous->home_score !== $match['home_score'] || $previous->away_score !== $match['away_score'])
            && $match['home_score'] !== null
            && $match['away_score'] !== null
        ) {
            $alerts[] = $this->alert('score_update', $match);
        }

        if ((bool) config('plugins.SportsBot.features.send_status_updates', true)) {
            if (!$this->isLiveStatus($previous->status) && $this->isLiveStatus($match['status'])) {
                $alerts[] = $this->alert('match_started', $match);
            }

            if (!$this->isFinalStatus($previous->status) && $this->isFinalStatus($match['status'])) {
                $alerts[] = $this->alert('full_time', $match);
            }
        }

        return $alerts;
    }

    private function alert(string $type, array $match): array
    {
        $scorePart = ($match['home_score'] ?? 'x') . '-' . ($match['away_score'] ?? 'x');
        $statusPart = $this->normalizeKey((string) ($match['status'] ?? ''));

        return [
            'type' => $type,
            'alert_key' => implode(':', array_filter([
                'native',
                $type,
                $match['event_id'],
                $scorePart,
                $statusPart,
            ])),
            'match' => $match,
        ];
    }

    private function applyAlertFilter(array $match, ?SportsBotMatchState $previous, array $alerts): array
    {
        $payload = GameHooks::apply('sportsbot.alerts.detected', [
            'match' => $match,
            'previous' => $previous,
            'alerts' => $alerts,
        ]);

        return array_values(array_filter($payload['alerts'] ?? $alerts, 'is_array'));
    }

    private function renderMessage(array $alert): string
    {
        $message = $this->renderer->render($alert);
        $payload = GameHooks::apply('sportsbot.alert.message', [
            'alert' => $alert,
            'message' => $message,
        ]);

        return (string) ($payload['message'] ?? $message);
    }

    private function upsertMatchState(array $match, ?SportsBotMatchState $previous): void
    {
        $now = now();
        $data = array_merge($match, [
            'first_seen_at' => $previous?->first_seen_at ?? $now,
            'last_seen_at' => $now,
        ]);

        SportsBotMatchState::updateOrCreate(['event_id' => $match['event_id']], $data);
    }

    private function markAlertSent(array $alert, array $results, Carbon $sentAt): void
    {
        DB::transaction(function () use ($alert, $results, $sentAt): void {
            SportsBotSentAlert::firstOrCreate(
                ['alert_key' => $alert['alert_key']],
                [
                    'event_id' => $alert['match']['event_id'],
                    'sport' => $alert['match']['sport'] ?? null,
                    'alert_type' => $alert['type'],
                    'payload' => array_merge($alert, ['telegram_results' => $results]),
                    'sent_at' => $sentAt,
                ]
            );
        });
    }

    private function applyFilter(string $hook, mixed $value): mixed
    {
        return GameHooks::apply($hook, $value);
    }

    private function summaryMessage(array $alert): string
    {
        $match = $alert['match'];

        return sprintf(
            '%s: %s %s-%s %s (%s)',
            $alert['type'],
            $match['home_team'],
            $match['home_score'] ?? 'x',
            $match['away_score'] ?? 'x',
            $match['away_team'],
            $match['status'] ?? 'unknown'
        );
    }

    private function isLiveStatus(?string $status): bool
    {
        $key = $this->normalizeKey((string) $status);

        return $key !== ''
            && !in_array($key, ['ns', 'not started', 'tbd', 'postponed', 'cancelled', 'canceled'], true)
            && !$this->isFinalStatus($status);
    }

    private function isFinalStatus(?string $status): bool
    {
        return in_array($this->normalizeKey((string) $status), [
            'ft',
            'full time',
            'aet',
            'aot',
            'ap',
            'after penalties',
            'final',
            'match finished',
        ], true);
    }

    private function canonicalSport(string $sport): string
    {
        $key = $this->normalizeKey($sport);

        return match ($key) {
            '', 'football' => 'Soccer',
            'rugby union', 'rugby league' => 'Rugby',
            'formula one', 'f1' => 'Formula 1',
            'mixed martial arts', 'ultimate fighting championship', 'ufc' => 'MMA',
            default => trim($sport),
        };
    }

    private function normalizeList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $key = $this->normalizeKey($this->canonicalSport((string) $value));

            if ($key !== '') {
                $normalized[$key] = true;
            }
        }

        return $normalized;
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);

        return preg_replace('/\s+/', ' ', $value) ?: $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
