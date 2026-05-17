<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\MessageRendererInterface;
use Illuminate\Support\Str;

class DefaultMessageRenderer implements MessageRendererInterface
{
    public function render(array $alert): string
    {
        $match = $alert['match'] ?? [];
        $type = (string) ($alert['type'] ?? 'update');
        $title = match ($type) {
            'score_update' => 'Score update',
            'full_time' => 'Full time',
            'match_started' => 'Match started',
            default => Str::headline($type),
        };

        $score = $this->scoreLine($match);
        $status = trim((string) ($match['status'] ?? ''));
        $progress = trim((string) ($match['progress'] ?? ''));
        $statusLine = $status !== '' ? "Status: {$this->escape($status)}" : null;

        if ($progress !== '' && $progress !== $status) {
            $statusLine .= $statusLine ? " ({$this->escape($progress)})" : "Progress: {$this->escape($progress)}";
        }

        return implode("\n", array_values(array_filter([
            '<b>' . $this->escape($title) . '</b>',
            $this->escape((string) ($match['league_name'] ?? $match['sport'] ?? 'Sports')),
            $score,
            $statusLine,
        ])));
    }

    private function scoreLine(array $match): string
    {
        $home = $this->escape((string) ($match['home_team'] ?? 'Home'));
        $away = $this->escape((string) ($match['away_team'] ?? 'Away'));
        $homeScore = $match['home_score'];
        $awayScore = $match['away_score'];

        if ($homeScore === null || $awayScore === null) {
            return "{$home} vs {$away}";
        }

        return "{$home} {$homeScore}-{$awayScore} {$away}";
    }

    private function escape(string $value): string
    {
        return e($value, false);
    }
}
