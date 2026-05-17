<?php

namespace App\Plugins\SportsBot\Services;

class LiveNowFormatter
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
        'Rugby' => '🏉',
    ];

    /**
     * @param array<string, mixed> $summary
     */
    public function format(array $summary): string
    {
        $parts = [
            '🔴 Live Now',
            '🕒 Scores from the live feed',
            '',
        ];

        $grouped = (array) ($summary['grouped'] ?? []);
        $order = (array) ($summary['sport_order'] ?? array_keys($grouped));
        $shownCount = 0;
        $maxPerSport = max(1, (int) config('plugins.SportsBot.live_now.max_per_sport', 8));

        foreach ($order as $sport) {
            $sportName = (string) $sport;
            $matches = array_values(array_filter((array) ($grouped[$sportName] ?? []), 'is_array'));

            if ($matches === []) {
                continue;
            }

            $parts[] = (self::SPORT_EMOJI[$sportName] ?? '🏅') . ' ' . $sportName;
            $parts[] = '';

            foreach (array_slice($matches, 0, $maxPerSport) as $match) {
                $shownCount++;
                $parts[] = $this->matchLine($match);

                $league = trim((string) ($match['league'] ?? ''));
                if ($league !== '') {
                    $parts[] = '🏆 ' . $league;
                }

                $status = trim((string) ($match['status'] ?? ''));
                $progress = trim((string) ($match['progress'] ?? ''));
                $statusLine = $progress !== '' ? $progress : $status;

                if ($statusLine !== '') {
                    $parts[] = '⏱ ' . $statusLine;
                }

                $parts[] = '';
            }
        }

        if ($shownCount === 0) {
            $parts[] = 'No live matches found right now.';
            $parts[] = '';
        }

        $parts[] = sprintf('Showing %d of %d live matches.', $shownCount, (int) ($summary['live_total'] ?? 0));

        return $this->fitTelegramLimit(implode("\n", $parts), $summary);
    }

    /**
     * @param array<string, mixed> $match
     */
    private function matchLine(array $match): string
    {
        $homeTeam = trim((string) ($match['home_team'] ?? ''));
        $awayTeam = trim((string) ($match['away_team'] ?? ''));
        $event = trim((string) ($match['event'] ?? 'Live event'));
        $homeScore = $match['home_score'] ?? null;
        $awayScore = $match['away_score'] ?? null;

        if ($homeTeam !== '' && $awayTeam !== '') {
            if ($homeScore !== null && $awayScore !== null) {
                return sprintf('🔴 %s %s-%s %s', $homeTeam, (string) $homeScore, (string) $awayScore, $awayTeam);
            }

            return sprintf('🔴 %s vs %s', $homeTeam, $awayTeam);
        }

        return '🔴 ' . ($event !== '' ? $event : 'Live event');
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
                return "🔴 Live Now\n🕒 Scores from the live feed\n\nNo live matches found right now.\n\nShowing 0 of " . (int) ($summary['live_total'] ?? 0) . ' live matches.';
            }

            $reduced['grouped'] = $grouped;
            $message = $this->format($reduced);
        }

        return $message;
    }
}
