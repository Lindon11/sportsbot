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
        'Fights' => '🥊',
        'MMA' => '🥊',
        'Tennis' => '🎾',
        'Rugby' => '🏉',
        'Motorsport' => '🏎',
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
        $title = trim((string) ($summary['title'] ?? "Today's Fixtures"));
        $date = trim((string) ($summary['date'] ?? ''));
        $dateTo = trim((string) ($summary['date_to'] ?? ''));
        $parts = [
            '📋 ' . ($title !== '' ? $title : "Today's Fixtures"),
        ];
        if ($date !== '' && $dateTo !== '' && $dateTo !== $date) {
            $parts[] = '📅 ' . $date . ' to ' . $dateTo;
        } elseif ($date !== '') {
            $parts[] = '📅 ' . $date;
        }
        $parts[] = '🕒 Times shown in local timezone';
        $parts[] = '';

        $grouped = (array) ($summary['grouped'] ?? []);
        $order = (array) ($summary['sport_order'] ?? array_keys($grouped));
        $shownCount = 0;
        $maxPerSport = max(1, (int) ($summary['max_per_sport'] ?? config('plugins.SportsBot.fixtures_today.max_per_sport', 5)));

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
                $time = $this->displayTimeLabel((string) ($fixture['time'] ?? 'TBC'));
                $league = $this->shortLeague(trim((string) ($fixture['league'] ?? 'Competition TBC')));
                $homeTeam = trim((string) ($fixture['home_team'] ?? ''));
                $awayTeam = trim((string) ($fixture['away_team'] ?? ''));
                $eventName = trim((string) ($fixture['event_name'] ?? ''));
                $tvChannel = trim((string) ($fixture['tv_channel'] ?? ''));
                $fixtureTitle = $homeTeam !== '' && $awayTeam !== '' ? ($homeTeam . ' vs ' . $awayTeam) : ($eventName !== '' ? $eventName : 'Fixture TBC');

                $parts[] = '🕐 ' . $time . ' — ' . $fixtureTitle;
                $parts[] = '🏆 ' . $league;
                $parts[] = '📺 UK TV: ' . ($tvChannel !== '' ? $tvChannel : 'Not listed');

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

    private function displayTimeLabel(string $time): string
    {
        $time = trim(preg_replace('/\s+/', ' ', $time) ?? $time);
        if ($time === '') {
            return '';
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?\s*([A-Z]{2,5})?$/i', $time, $matches) !== 1) {
            return $time;
        }

        $hour = (int) $matches[1];
        $minute = (string) $matches[2];
        $meridiem = strtoupper((string) ($matches[3] ?? ''));
        $timezone = strtoupper((string) ($matches[4] ?? ''));

        if ($meridiem === '') {
            if ($hour > 23) {
                return $time;
            }

            $meridiem = $hour >= 12 ? 'PM' : 'AM';
            $hour = $hour % 12;
            if ($hour === 0) {
                $hour = 12;
            }
        }

        $label = sprintf('%d:%s %s', $hour, $minute, $meridiem);

        return trim($label . ($timezone !== '' ? ' ' . $timezone : ''));
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
