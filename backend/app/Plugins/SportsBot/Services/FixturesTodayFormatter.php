<?php

namespace App\Plugins\SportsBot\Services;

class FixturesTodayFormatter
{
    /**
     * @var array<string, string>
     */
    private const SPORT_EMOJI = [
        'Football' => '⚽',
        'Basketball' => '🏀',
        'Baseball' => '⚾',
        'MMA' => '🥊',
        'Tennis' => '🎾',
    ];

    /**
     * @var array<string, string>
     */
    private const SHORT_LEAGUES = [
        'English Premier League' => 'Premier League',
        'Scottish Premiership' => 'Premiership',
    ];

    /**
     * @param array<string, mixed> $summary
     */
    public function format(array $summary): string
    {
        $parts = [
            "📋 Today's Fixtures",
            '🕒 Times shown in local timezone',
            '',
        ];

        $grouped = (array) ($summary['grouped'] ?? []);
        $order = (array) ($summary['sport_order'] ?? array_keys($grouped));
        $shownCount = 0;
        $maxPerSport = max(1, (int) config('plugins.SportsBot.fixtures_today.max_per_sport', 5));

        foreach ($order as $sport) {
            $sportName = (string) $sport;
            $fixtures = array_values(array_filter((array) ($grouped[$sportName] ?? []), 'is_array'));

            if ($fixtures === []) {
                continue;
            }

            $parts[] = (self::SPORT_EMOJI[$sportName] ?? '🏅') . ' ' . $sportName;
            $parts[] = '';

            foreach (array_slice($fixtures, 0, $maxPerSport) as $fixture) {
                $shownCount++;
                $time = trim((string) ($fixture['time'] ?? 'TBC'));
                $league = $this->shortLeague(trim((string) ($fixture['league'] ?? 'Competition TBC')));
                $homeTeam = trim((string) ($fixture['home_team'] ?? ''));
                $awayTeam = trim((string) ($fixture['away_team'] ?? ''));
                $eventName = trim((string) ($fixture['event_name'] ?? ''));
                $tvChannel = trim((string) ($fixture['tv_channel'] ?? ''));

                $fixtureTitle = $homeTeam !== '' && $awayTeam !== '' ? ($homeTeam . ' vs ' . $awayTeam) : ($eventName !== '' ? $eventName : 'Fixture TBC');

                $parts[] = '🕐 ' . $time . ' — ' . $fixtureTitle;
                $parts[] = '🏆 ' . $league;

                if ($tvChannel !== '') {
                    $parts[] = '📺 ' . $tvChannel;
                }

                $parts[] = '';
            }
        }

        if ($shownCount === 0) {
            $parts[] = 'No fixtures found today.';
            $parts[] = '';
        }

        $parts[] = sprintf('Showing %d of %d fixtures.', $shownCount, (int) ($summary['fixtures_total'] ?? 0));

        return $this->fitTelegramLimit(implode("\n", $parts), $summary);
    }

    private function shortLeague(string $league): string
    {
        return self::SHORT_LEAGUES[$league] ?? ($league !== '' ? $league : 'Competition TBC');
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function fitTelegramLimit(string $message, array $summary): string
    {
        $limit = 4096;
        $length = static fn (string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        if ($length($message) <= $limit) {
            return $message;
        }

        $reduced = $summary;
        $grouped = (array) ($summary['grouped'] ?? []);

        while ($length($message) > $limit) {
            $trimmed = false;

            foreach (array_reverse(array_keys($grouped)) as $sport) {
                if (!empty($grouped[$sport]) && is_array($grouped[$sport])) {
                    array_pop($grouped[$sport]);
                    $trimmed = true;
                    break;
                }
            }

            if (!$trimmed) {
                return "📋 Today's Fixtures\n🕒 Times shown in local timezone\n\nNo fixtures found today.\n\nShowing 0 of " . (int) ($summary['fixtures_total'] ?? 0) . ' fixtures.';
            }

            $reduced['grouped'] = $grouped;
            $message = $this->format($reduced);
        }

        return $message;
    }
}
