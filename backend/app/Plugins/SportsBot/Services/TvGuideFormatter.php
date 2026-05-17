<?php

namespace App\Plugins\SportsBot\Services;

class TvGuideFormatter
{
    /**
     * @param array<string, mixed> $summary
     */
    public function format(array $summary): string
    {
        $parts = [
            '📺 TV Sports Guide',
            '🕒 Next ' . (int) ($summary['hours_ahead'] ?? 24) . ' hours, local timezone',
        ];

        $sports = array_values(array_filter(array_map('strval', (array) ($summary['sports_grouped'] ?? []))));
        if ($sports !== []) {
            $parts[] = '🏅 ' . implode(', ', $sports);
        }
        $parts[] = '';

        $channels = (array) ($summary['channels'] ?? []);
        $grouped = (array) ($summary['grouped'] ?? []);
        $maxPerChannel = max(1, (int) config('plugins.SportsBot.tv.max_per_channel', 8));
        $showEmptyChannels = (bool) config('plugins.SportsBot.tv.show_empty_channels', false);
        $shown = 0;

        if ($channels === []) {
            $parts[] = 'No TV channels are configured yet.';
            $parts[] = '';
        }

        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            $slug = (string) ($channel['slug'] ?? '');
            $label = (string) ($channel['label'] ?? $slug);
            $events = array_values(array_filter((array) ($grouped[$slug] ?? []), 'is_array'));

            if ($events === [] && !$showEmptyChannels) {
                continue;
            }

            $parts[] = '📡 ' . ($label !== '' ? $label : 'Channel');

            if ($events === []) {
                $parts[] = 'No listed events.';
                $parts[] = '';
                continue;
            }

            foreach (array_slice($events, 0, $maxPerChannel) as $event) {
                $shown++;
                $time = trim((string) ($event['time_label'] ?? 'TBC'));
                $sport = trim((string) ($event['sport'] ?? 'Sport'));
                $title = trim((string) ($event['event'] ?? 'TV event'));
                $league = trim((string) ($event['league'] ?? ''));

                $parts[] = '🕐 ' . $time . ' — ' . $title;
                $parts[] = '🏅 ' . $sport . ($league !== '' ? ' · ' . $league : '');
            }

            $parts[] = '';
        }

        if ($shown === 0 && $channels !== []) {
            $parts[] = 'No TV events found in the current window.';
            $parts[] = '';
        }

        $parts[] = sprintf('Showing %d of %d TV events.', $shown, (int) ($summary['events_total'] ?? 0));

        return $this->fitTelegramLimit(implode("\n", $parts), $summary);
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

            foreach (array_reverse(array_keys($grouped)) as $channel) {
                if (!empty($grouped[$channel]) && is_array($grouped[$channel])) {
                    array_pop($grouped[$channel]);
                    $trimmed = true;
                    break;
                }
            }

            if (!$trimmed) {
                return "📺 TV Sports Guide\n🕒 Next " . (int) ($summary['hours_ahead'] ?? 24) . " hours, local timezone\n\nNo TV events found in the current window.\n\nShowing 0 of " . (int) ($summary['events_total'] ?? 0) . ' TV events.';
            }

            $reduced['grouped'] = $grouped;
            $message = $this->format($reduced);
        }

        return $message;
    }
}
