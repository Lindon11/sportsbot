<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;

class SportsBotEpgScheduleVerifier
{
    public function __construct(
        private readonly SportsBotEpgChannelNormalizer $channels = new SportsBotEpgChannelNormalizer(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evidenceForChannel(SportsBotFixtureQueue $entry, string $canonicalChannelId, string $channel): array
    {
        $canonicalChannelId = trim($canonicalChannelId);
        if ($canonicalChannelId === '') {
            $canonicalChannelId = $this->channels->canonicalIdFor($channel, 'UK');
        }

        if ($canonicalChannelId === '') {
            return ['verified' => false, 'boost' => 0.0];
        }

        $minimum = max(0.0, min(1.0, (float) config('plugins.SportsBot.epg.schedule_verifier_min_confidence', 0.8)));
        foreach ($this->scheduleEvidence($entry) as $evidence) {
            $confidence = (float) ($evidence['confidence'] ?? 0.0);
            if ($confidence < $minimum) {
                continue;
            }

            $verifiedChannels = $this->canonicalChannels((array) ($evidence['channels'] ?? []));
            if (! in_array($canonicalChannelId, $verifiedChannels, true)) {
                continue;
            }

            $boost = max(0.0, min(0.12, (float) config('plugins.SportsBot.epg.schedule_verifier_boost', 0.08)));

            return [
                'verified' => true,
                'boost' => $boost,
                'source' => (string) ($evidence['source'] ?? 'public_schedule'),
                'confidence' => round($confidence, 2),
                'source_urls' => array_values(array_filter((array) ($evidence['source_urls'] ?? []))),
                'channels' => array_values(array_filter((array) ($evidence['channels'] ?? []))),
            ];
        }

        return ['verified' => false, 'boost' => 0.0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scheduleEvidence(SportsBotFixtureQueue $entry): array
    {
        $payload = (array) ($entry->payload ?? []);
        $accepted = (array) ($payload['accepted_scraped_data'] ?? []);
        $normalized = (array) ($payload['scraper']['normalized'] ?? []);
        $rows = [];

        if ($accepted !== []) {
            $rows[] = $this->evidenceRow($accepted, 'accepted_public_schedule');
        }

        if ($normalized !== []) {
            $rows[] = $this->evidenceRow($normalized, 'public_schedule_scraper');
        }

        return array_values(array_filter($rows, fn (array $row): bool => ($row['channels'] ?? []) !== []));
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceRow(array $payload, string $source): array
    {
        $fields = (array) ($payload['fields'] ?? []);
        $channels = array_values(array_filter([
            trim((string) ($fields['tv_channel'] ?? '')),
            ...array_map(fn (mixed $value): string => trim((string) $value), (array) ($fields['tv_channels'] ?? [])),
        ]));

        return [
            'source' => $source,
            'confidence' => (float) ($payload['confidence'] ?? 0.0),
            'source_urls' => (array) ($payload['source_urls'] ?? []),
            'channels' => array_values(array_unique($channels)),
        ];
    }

    /**
     * @param array<int, string> $channels
     * @return array<int, string>
     */
    private function canonicalChannels(array $channels): array
    {
        $ids = [];
        foreach ($channels as $channel) {
            $canonical = $this->channels->canonicalIdFor((string) $channel, 'UK');
            if ($canonical !== '') {
                $ids[$canonical] = $canonical;
            }
        }

        return array_values($ids);
    }
}
