<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Support\SportsBotSports;
use Throwable;

class SportsBotRichContentService
{
    public function __construct(
        private readonly TheSportsDbClient $provider = new TheSportsDbClient(),
        private readonly SportsBotCardRenderer $cards = new SportsBotCardRenderer(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    /**
     * @return array{caption:string,reply_markup:array<string,mixed>,card:?array}
     */
    public function match(string $eventId): array
    {
        $event = $this->provider->lookupEvent($eventId) ?? [];
        $stats = $this->provider->lookupEventStats($eventId);
        $timeline = $this->provider->lookupEventTimeline($eventId);
        $tv = $this->provider->lookupEventTv($eventId);

        $caption = $this->caption([
            '<b>' . e($this->eventTitle($event)) . '</b>',
            $this->line('Competition', $event['strLeague'] ?? null),
            $this->line('Score', $this->score($event)),
            $this->line('Status', $event['strStatus'] ?? $event['strProgress'] ?? null),
            $this->line('Venue', $event['strVenue'] ?? null),
            $this->line('Attendance', $event['intAttendance'] ?? null),
            $this->line('Referee', $event['strReferee'] ?? null),
            $this->line('TV', $this->tvSummary($tv)),
            $this->timelineSummary($timeline),
            $this->statsSummary($stats),
        ]);

        return [
            'caption' => $caption,
            'reply_markup' => SportsBotInlineKeyboardBuilder::matchReplyMarkup($eventId, $event),
            'card' => $this->tryCard(fn () => $this->cards->liveMatchCard($event + ['home_team' => $event['strHomeTeam'] ?? '', 'away_team' => $event['strAwayTeam'] ?? ''])),
        ];
    }

    public function stats(string $eventId): array
    {
        $event = $this->provider->lookupEvent($eventId) ?? [];
        $stats = $this->provider->lookupEventStats($eventId);

        $lines = ['<b>📊 Match Stats</b>', e($this->eventTitle($event)), ''];
        foreach (array_slice($stats, 0, 16) as $row) {
            $name = (string) ($row['strStat'] ?? $row['strStatType'] ?? $row['name'] ?? 'Stat');
            $home = (string) ($row['intHome'] ?? $row['strHome'] ?? $row['home'] ?? '-');
            $away = (string) ($row['intAway'] ?? $row['strAway'] ?? $row['away'] ?? '-');
            $lines[] = e($name) . ': <b>' . e($home) . '</b> - <b>' . e($away) . '</b>';
        }

        if (count($lines) <= 3) {
            $lines[] = 'Stats are not available yet.';
        }

        return ['caption' => implode("\n", $lines), 'reply_markup' => SportsBotInlineKeyboardBuilder::matchReplyMarkup($eventId, $event), 'card' => null];
    }

    public function lineups(string $eventId): array
    {
        $event = $this->provider->lookupEvent($eventId) ?? [];
        $lineups = $this->provider->lookupEventLineup($eventId);

        $lines = ['<b>👥 Lineups</b>', e($this->eventTitle($event)), ''];
        foreach (array_slice($lineups, 0, 22) as $row) {
            $team = (string) ($row['strTeam'] ?? $row['team'] ?? '');
            $player = (string) ($row['strPlayer'] ?? $row['player'] ?? 'Player TBC');
            $position = (string) ($row['strPosition'] ?? $row['position'] ?? '');
            $lines[] = trim(e($team) . ' · ' . e($player) . ($position !== '' ? ' (' . e($position) . ')' : ''));
        }

        if (count($lines) <= 3) {
            $lines[] = 'Lineups are not available yet.';
        }

        return ['caption' => implode("\n", $lines), 'reply_markup' => SportsBotInlineKeyboardBuilder::matchReplyMarkup($eventId, $event), 'card' => null];
    }

    public function highlights(string $eventId): array
    {
        $event = $this->provider->lookupEvent($eventId) ?? [];
        $highlights = $this->provider->lookupEventHighlights($eventId);
        $lines = ['<b>▶ Highlights</b>', e($this->eventTitle($event)), ''];
        $keyboard = SportsBotInlineKeyboardBuilder::matchKeyboard($eventId, $event);

        foreach (array_slice($highlights, 0, 4) as $row) {
            $title = (string) ($row['strVideo'] ?? $row['strTitle'] ?? $row['strEvent'] ?? 'Highlight');
            $url = (string) ($row['strVideoEmbed'] ?? $row['strYoutube'] ?? $row['strURL'] ?? '');
            $lines[] = '• ' . e($title);
            if ($url !== '') {
                array_unshift($keyboard, [['text' => 'Watch Live / Highlights', 'url' => $url]]);
            }
        }

        if (count($lines) <= 3) {
            $lines[] = 'Highlights are not available yet.';
        }

        return ['caption' => implode("\n", $lines), 'reply_markup' => SportsBotInlineKeyboardBuilder::inlineKeyboardMarkup($keyboard), 'card' => null];
    }

    public function eventTv(string $eventId): array
    {
        $event = $this->provider->lookupEvent($eventId) ?? [];
        $tv = $this->provider->lookupEventTv($eventId);
        $lines = ['<b>📺 TV Channels</b>', e($this->eventTitle($event)), ''];

        foreach (array_slice($tv, 0, 20) as $row) {
            $lines[] = '• ' . e((string) ($row['strChannel'] ?? $row['strChannelName'] ?? 'Channel TBC')) . ' · ' . e((string) ($row['strCountry'] ?? ''));
        }

        if (count($lines) <= 3) {
            $lines[] = 'No TV channels listed yet.';
        }

        return ['caption' => implode("\n", $lines), 'reply_markup' => SportsBotInlineKeyboardBuilder::matchReplyMarkup($eventId, $event), 'card' => null];
    }

    public function leagueTable(string $leagueId, int $page = 1): array
    {
        $league = $this->provider->lookupLeague($leagueId) ?? ['strLeague' => 'League'];
        $table = $this->provider->leagueTable($leagueId);

        $caption = '<b>🏆 ' . e((string) ($league['strLeague'] ?? 'League Table')) . '</b>' . "\n" .
            'Page ' . max(1, $page) . ' · Tap Top Scorers for player rankings.';

        return [
            'caption' => $caption,
            'reply_markup' => SportsBotInlineKeyboardBuilder::tableReplyMarkup($leagueId, $page),
            'card' => $this->tryCard(fn () => $this->cards->leagueTableCard($league, $table, $page)),
        ];
    }

    public function topScorers(string $leagueId, int $page = 1): array
    {
        $league = $this->provider->lookupLeague($leagueId) ?? ['strLeague' => 'League'];
        $rows = $this->provider->topScorers($leagueId);

        return [
            'caption' => '<b>🎯 Top Scorers</b>' . "\n" . e((string) ($league['strLeague'] ?? 'League')) . ' · Page ' . max(1, $page),
            'reply_markup' => SportsBotInlineKeyboardBuilder::tableReplyMarkup($leagueId, $page),
            'card' => $this->tryCard(fn () => $this->cards->topScorersCard($league, array_slice($rows, max(0, ($page - 1) * 10), 10))),
        ];
    }

    public function team(string $teamId): array
    {
        $team = $this->provider->lookupTeam($teamId) ?? ['strTeam' => 'Team'];

        return [
            'caption' => '<b>' . e((string) ($team['strTeam'] ?? 'Team')) . '</b>' . "\n" .
                $this->line('Sport', $team['strSport'] ?? null) . "\n" .
                $this->line('League', $team['strLeague'] ?? null) . "\n" .
                $this->line('Venue', $team['strStadium'] ?? null),
            'reply_markup' => SportsBotInlineKeyboardBuilder::teamReplyMarkup($teamId),
            'card' => $this->tryCard(fn () => $this->cards->teamProfileCard($team)),
        ];
    }

    public function teamSchedule(string $teamId, string $direction, int $page = 1): array
    {
        $team = $this->provider->lookupTeam($teamId) ?? ['strTeam' => 'Team'];
        $rows = $direction === 'previous' ? $this->provider->previousTeamEvents($teamId) : $this->provider->nextTeamEvents($teamId);
        $rows = array_slice($rows, max(0, ($page - 1) * 8), 8);
        $lines = ['<b>' . e((string) ($team['strTeam'] ?? 'Team')) . ' · ' . ($direction === 'previous' ? 'Previous Results' : 'Next Fixtures') . '</b>', ''];

        foreach ($rows as $row) {
            $lines[] = '• ' . e((string) ($row['dateEvent'] ?? '')) . ' ' . e($this->eventTitle($row));
        }

        if ($rows === []) {
            $lines[] = 'No events found.';
        }

        return [
            'caption' => implode("\n", $lines),
            'reply_markup' => SportsBotInlineKeyboardBuilder::inlineKeyboardMarkup(SportsBotInlineKeyboardBuilder::paginationKeyboard($direction === 'previous' ? 'team_prev' : 'team_next', $teamId, $page)),
            'card' => null,
        ];
    }

    public function sportFixtures(string $sportKey, int $page = 1): array
    {
        $sport = SportsBotSports::providerSport($sportKey);
        $rows = [];
        foreach ((array) config('plugins.SportsBot.fixtures_today.default_league_ids', []) as $leagueId) {
            foreach ($this->provider->nextLeagueEvents((string) $leagueId) as $row) {
                if (strcasecmp((string) ($row['strSport'] ?? ''), $sport) === 0 || SportsBotSports::normalize((string) ($row['strSport'] ?? $sport)) === SportsBotSports::normalize($sport)) {
                    $rows[] = $row;
                }
            }
        }

        $rows = array_slice($rows, max(0, ($page - 1) * 8), 8);
        $lines = ['<b>' . SportsBotSports::icon($sport) . ' ' . e(SportsBotSports::label($sport)) . ' Fixtures</b>', ''];
        foreach ($rows as $row) {
            $eventId = (string) ($row['idEvent'] ?? '');
            $lines[] = '• ' . e((string) ($row['dateEvent'] ?? '')) . ' ' . e($this->eventTitle($row)) . ($eventId !== '' ? ' /match:' . e($eventId) : '');
        }
        if ($rows === []) {
            $lines[] = 'No fixtures found for this sport.';
        }

        return ['caption' => implode("\n", $lines), 'reply_markup' => SportsBotInlineKeyboardBuilder::fixturesTodayReplyMarkup(), 'card' => null];
    }

    /**
     * @return array{caption:string,reply_markup:array<string,mixed>,card:?array}
     */
    public function newsPlaceholder(): array
    {
        return [
            'caption' => "<b>📰 News & Transfers</b>\n\nTransfer and breaking-news feeds are ready as placeholders. Connect a news provider to populate this topic.",
            'reply_markup' => SportsBotInlineKeyboardBuilder::backReplyMarkup(),
            'card' => $this->tryCard(fn () => $this->cards->breakingNewsCard(['title' => 'News & Transfers', 'summary' => 'Provider feed placeholder ready.'])),
        ];
    }

    private function eventTitle(array $event): string
    {
        $home = trim((string) ($event['strHomeTeam'] ?? $event['home_team'] ?? ''));
        $away = trim((string) ($event['strAwayTeam'] ?? $event['away_team'] ?? ''));
        if ($home !== '' && $away !== '') {
            return $home . ' vs ' . $away;
        }

        return (string) ($event['strEvent'] ?? $event['event'] ?? 'Event TBC');
    }

    private function score(array $event): ?string
    {
        $home = $event['intHomeScore'] ?? $event['home_score'] ?? null;
        $away = $event['intAwayScore'] ?? $event['away_score'] ?? null;
        if ($home === null && $away === null) {
            return null;
        }

        return (string) $home . ' - ' . (string) $away;
    }

    private function tvSummary(array $tv): ?string
    {
        $channels = [];
        foreach (array_slice($tv, 0, 5) as $row) {
            $channel = trim((string) ($row['strChannel'] ?? $row['strChannelName'] ?? ''));
            if ($channel !== '') {
                $channels[] = $channel;
            }
        }

        return $channels !== [] ? implode(', ', $channels) : null;
    }

    private function timelineSummary(array $timeline): ?string
    {
        if ($timeline === []) {
            return null;
        }

        $items = [];
        foreach (array_slice($timeline, 0, 5) as $row) {
            $items[] = trim((string) ($row['intTime'] ?? $row['strTime'] ?? '')) . "' " . trim((string) ($row['strPlayer'] ?? $row['strTimeline'] ?? $row['strDetail'] ?? 'Update'));
        }

        return '<b>Timeline</b>: ' . e(implode(' · ', array_filter($items)));
    }

    private function statsSummary(array $stats): ?string
    {
        if ($stats === []) {
            return null;
        }

        return '<b>Stats available</b>: tap Match Stats.';
    }

    private function line(string $label, mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return '<b>' . e($label) . '</b>: ' . e($value);
    }

    /**
     * @param array<int, string|null> $lines
     */
    private function caption(array $lines): string
    {
        return implode("\n", array_values(array_filter($lines, static fn ($line): bool => is_string($line) && trim($line) !== '')));
    }

    private function tryCard(callable $callback): ?array
    {
        if (
            !((bool) $this->settings->get('cards_enabled', config('plugins.SportsBot.cards.enabled', true)))
            || !((bool) $this->settings->get('rich_cards_enabled', config('plugins.SportsBot.features.rich_cards', true)))
        ) {
            return null;
        }

        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
