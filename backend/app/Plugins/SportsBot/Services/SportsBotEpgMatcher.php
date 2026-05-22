<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotEpgCorrection;
use App\Plugins\SportsBot\Models\SportsBotEpgFixtureMatch;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use App\Plugins\SportsBot\Support\SportsBotFixtureReadiness;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SportsBotEpgMatcher
{
    public const AUTO_CONFIDENCE = 0.85;
    public const REVIEW_CONFIDENCE = 0.55;

    /**
     * @var array<int, SportsBotEpgSource|null>
     */
    private array $sourceCache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $verificationCache = [];

    public function __construct(
        private readonly SportsBotEpgChannelNormalizer $channels = new SportsBotEpgChannelNormalizer(),
        private readonly SportsBotEpgScheduleVerifier $scheduleVerifier = new SportsBotEpgScheduleVerifier(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function matchFixtures(int $days = 3, int $limit = 200, bool $apply = true, array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        $from = Carbon::today()->subDay()->toDateString();
        $to = Carbon::today()->addDays(max(1, min(14, $days)))->toDateString();

        $fixtures = SportsBotFixtureQueue::query()
            ->whereIn('status', [
                SportsBotFixtureQueue::STATUS_DRAFT,
                SportsBotFixtureQueue::STATUS_READY,
                SportsBotFixtureQueue::STATUS_FAILED,
            ])
            ->whereBetween('publish_date', [$from, $to])
            ->orderBy('publish_date')
            ->limit(max(1, $limit))
            ->get();

        $fixtures = $fixtures
            ->filter(fn (SportsBotFixtureQueue $fixture): bool => $this->needsMatch($fixture, $force))
            ->values();

        $globalWindow = $this->globalProgrammeWindow($fixtures);
        $programmes = $globalWindow === null
            ? collect()
            : SportsBotXmltvProgramme::query()
                ->where('start_time', '>=', $globalWindow[0])
                ->where('start_time', '<=', $globalWindow[1])
                ->orderBy('start_time')
                ->get();

        $summary = [
            'checked' => 0,
            'auto_applied' => 0,
            'needs_review' => 0,
            'ignored' => 0,
            'no_candidate' => 0,
            'rows' => [],
        ];

        foreach ($fixtures as $fixture) {
            $result = $this->matchFixtureAgainstProgrammes($fixture, $programmes, $apply);
            $status = (string) ($result['status'] ?? 'no_candidate');
            $summary['checked']++;
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
            $summary['rows'][] = $result;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function matchFixture(SportsBotFixtureQueue $entry, bool $apply = true): array
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        [$windowStart, $windowEnd] = $this->programmeWindow($entry, $fixture);
        $programmes = SportsBotXmltvProgramme::query()
            ->where('start_time', '>=', $windowStart)
            ->where('start_time', '<=', $windowEnd)
            ->orderBy('start_time')
            ->limit(2000)
            ->get();

        return $this->matchFixtureAgainstProgrammes($entry, $programmes, $apply);
    }

    /**
     * @param Collection<int, SportsBotXmltvProgramme> $programmes
     * @return array<string, mixed>
     */
    private function matchFixtureAgainstProgrammes(SportsBotFixtureQueue $entry, Collection $programmes, bool $apply = true): array
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $kickoff = $this->fixtureKickoff($entry, $fixture);
        [$windowStart, $windowEnd] = $this->programmeWindow($entry, $fixture);
        $programmes = $programmes
            ->filter(fn (SportsBotXmltvProgramme $programme): bool => $programme->start_time !== null && $programme->start_time >= $windowStart && $programme->start_time <= $windowEnd)
            ->values();

        if ($programmes->isEmpty()) {
            return [
                'fixture_queue_id' => $entry->id,
                'event_id' => $entry->event_id,
                'status' => 'no_candidate',
                'confidence' => 0,
            ];
        }

        $corrections = $this->correctionsFor($entry);
        $sourceAgreement = $this->sourceAgreement($programmes, $fixture);
        $candidates = [];

        foreach ($programmes as $programme) {
            $candidate = $this->scoreProgramme($entry, $fixture, $programme, $kickoff, $sourceAgreement, $corrections);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, fn (array $a, array $b): int => ($b['confidence'] <=> $a['confidence']));
        $best = $candidates[0] ?? null;

        if ($best === null) {
            return [
                'fixture_queue_id' => $entry->id,
                'event_id' => $entry->event_id,
                'status' => 'no_candidate',
                'confidence' => 0,
            ];
        }

        $status = $best['confidence'] >= self::AUTO_CONFIDENCE
            ? 'auto_applied'
            : ($best['confidence'] >= self::REVIEW_CONFIDENCE ? 'needs_review' : 'ignored');

        $match = $this->recordMatch($entry, $best, $status, $apply);

        if ($apply && $status === 'auto_applied') {
            $this->applyMatchToFixture($entry, $best, $match);
        } elseif ($apply && $status === 'needs_review') {
            $this->storeReviewPayload($entry, $best, $match);
        }

        return [
            'fixture_queue_id' => $entry->id,
            'event_id' => $entry->event_id,
            'status' => $status,
            'confidence' => $best['confidence'],
            'channel' => $best['channel'],
            'canonical_channel_id' => $best['canonical_channel_id'],
            'programme_id' => $best['programme_id'],
            'match_id' => $match->id,
            'evidence' => $best['evidence'],
            'candidate_count' => count($candidates),
        ];
    }

    private function needsMatch(SportsBotFixtureQueue $entry, bool $force): bool
    {
        if ($force) {
            return true;
        }

        $payload = (array) ($entry->payload ?? []);
        $epg = (array) ($payload['epg_match'] ?? []);
        if (in_array((string) ($epg['status'] ?? ''), ['auto_applied', 'accepted'], true)
            && (float) ($epg['confidence'] ?? 0) >= self::AUTO_CONFIDENCE) {
            return false;
        }

        return ! SportsBotFixtureReadiness::hasTv((array) ($entry->fixture_data ?? []));
    }

    /**
     * @param Collection<int, SportsBotFixtureQueue> $fixtures
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function globalProgrammeWindow(Collection $fixtures): ?array
    {
        $start = null;
        $end = null;
        foreach ($fixtures as $entry) {
            [$windowStart, $windowEnd] = $this->programmeWindow($entry, (array) ($entry->fixture_data ?? []));
            $start = $start === null || $windowStart < $start ? $windowStart : $start;
            $end = $end === null || $windowEnd > $end ? $windowEnd : $end;
        }

        return $start && $end ? [$start, $end] : null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function programmeWindow(SportsBotFixtureQueue $entry, array $fixture): array
    {
        $kickoff = $this->fixtureKickoff($entry, $fixture);

        return [
            $kickoff ? $kickoff->copy()->subHours(4) : Carbon::parse($entry->publish_date)->subHours(6),
            $kickoff ? $kickoff->copy()->addHours(5) : Carbon::parse($entry->publish_date)->addHours(30),
        ];
    }

    public function acceptMatch(SportsBotEpgFixtureMatch $match, ?int $userId = null): array
    {
        $entry = SportsBotFixtureQueue::query()->find($match->fixture_queue_id);
        $programme = SportsBotXmltvProgramme::query()->find($match->programme_id);

        if (! $entry || ! $programme) {
            return ['accepted' => false, 'error' => 'Match fixture or programme no longer exists'];
        }

        $candidate = [
            'programme_id' => $programme->id,
            'channel' => (string) ($match->channel ?: $programme->channel),
            'canonical_channel_id' => (string) ($match->canonical_channel_id ?: $programme->canonical_channel_id),
            'confidence' => max((float) $match->confidence, self::AUTO_CONFIDENCE),
            'source_urls' => (array) ($match->source_urls ?? []),
            'evidence' => (array) ($match->evidence ?? []),
        ];

        $this->applyMatchToFixture($entry, $candidate, $match);
        $this->channels->rememberAlias($candidate['channel'], $candidate['canonical_channel_id'], null, 'correction', $candidate['channel'], 1.0);

        $match->fill([
            'status' => 'accepted',
            'reviewed_at' => now(),
            'reviewed_by' => $userId,
            'applied_at' => now(),
        ])->save();

        SportsBotEpgCorrection::query()->create([
            'fixture_queue_id' => $entry->id,
            'event_id' => $entry->event_id,
            'canonical_channel_id' => $candidate['canonical_channel_id'],
            'channel' => $candidate['channel'],
            'action' => 'accepted',
            'created_by' => $userId,
            'payload' => $candidate,
        ]);

        return ['accepted' => true, 'match_id' => $match->id, 'fixture_queue_id' => $entry->id];
    }

    public function rejectMatch(SportsBotEpgFixtureMatch $match, ?int $userId = null): array
    {
        $match->fill([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by' => $userId,
        ])->save();

        SportsBotEpgCorrection::query()->create([
            'fixture_queue_id' => $match->fixture_queue_id,
            'event_id' => $match->event_id,
            'canonical_channel_id' => $match->canonical_channel_id,
            'channel' => $match->channel,
            'action' => 'rejected',
            'created_by' => $userId,
            'payload' => [
                'match_id' => $match->id,
                'confidence' => $match->confidence,
            ],
        ]);

        return ['rejected' => true, 'match_id' => $match->id];
    }

    /**
     * @param Collection<int, SportsBotXmltvProgramme> $programmes
     * @return array<string, int>
     */
    private function sourceAgreement(Collection $programmes, array $fixture): array
    {
        $agreement = [];
        foreach ($programmes as $programme) {
            $parts = [];
            $base = $this->textScore($fixture, (string) $programme->title, (string) ($programme->description ?? ''), $parts);
            if ($base < 0.35) {
                continue;
            }

            $canonical = (string) ($programme->canonical_channel_id ?: $this->channels->canonicalIdFor((string) $programme->channel));
            if ($canonical === '') {
                continue;
            }

            $sourceKey = (string) ($programme->source_id ?: $programme->source_url ?: $programme->id);
            $agreement[$canonical][$sourceKey] = true;
        }

        $counts = [];
        foreach ($agreement as $canonical => $sources) {
            $counts[$canonical] = count($sources);
        }

        return $counts;
    }

    /**
     * @param array<string, SportsBotEpgCorrection> $corrections
     * @param array<string, int> $sourceAgreement
     * @return array<string, mixed>|null
     */
    private function scoreProgramme(SportsBotFixtureQueue $entry, array $fixture, SportsBotXmltvProgramme $programme, ?Carbon $kickoff, array $sourceAgreement, array $corrections): ?array
    {
        $channel = (string) $programme->channel;
        $canonical = (string) ($programme->canonical_channel_id ?: $this->channels->canonicalIdFor($channel));
        $correctionKey = $canonical !== '' ? 'channel:' . $canonical : '';
        $correction = $correctionKey !== '' ? ($corrections[$correctionKey] ?? null) : null;

        if (($correction?->action ?? null) === 'rejected') {
            return null;
        }

        $parts = [];
        $score = $this->textScore($fixture, (string) $programme->title, (string) ($programme->description ?? ''), $parts);
        $score += $this->timeScore($kickoff, $programme, $parts);
        $score += $this->sourceScore($programme, $parts);
        $verification = $this->scheduleVerification($entry, $canonical, $channel);
        if (($verification['verified'] ?? false) === true) {
            $score += (float) ($verification['boost'] ?? 0.0);
            $parts['public_schedule_verifier'] = (float) ($verification['boost'] ?? 0.0);
        }

        $agreement = $sourceAgreement[$canonical] ?? 0;
        if ($agreement >= 2) {
            $score += 0.15;
            $parts['multi_source_agreement'] = min(0.15, 0.08 + ($agreement * 0.02));
        }

        if (($correction?->action ?? null) === 'accepted') {
            $score += 0.20;
            $parts['accepted_correction'] = 0.20;
        }

        $score = max(0, min(0.98, $score));

        $deltaMinutes = null;
        if ($kickoff && $programme->start_time) {
            $deltaMinutes = abs($programme->start_time->diffInMinutes($kickoff, false));
        }

        return [
            'fixture_queue_id' => $entry->id,
            'event_id' => $entry->event_id,
            'programme_id' => $programme->id,
            'channel' => $channel,
            'canonical_channel_id' => $canonical,
            'confidence' => round($score, 2),
            'source_urls' => array_values(array_unique(array_filter([
                (string) ($programme->source_url ?? ''),
                ...((array) ($verification['source_urls'] ?? [])),
            ]))),
            'evidence' => [
                'programme_title' => (string) $programme->title,
                'programme_description' => (string) ($programme->description ?? ''),
                'programme_start' => $programme->start_time?->toIso8601String(),
                'programme_end' => $programme->end_time?->toIso8601String(),
                'fixture_kickoff' => $kickoff?->toIso8601String(),
                'kickoff_delta_minutes' => $deltaMinutes,
                'score_parts' => $parts,
                'source_agreement' => $agreement,
                'schedule_verifier' => $verification,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleVerification(SportsBotFixtureQueue $entry, string $canonicalChannelId, string $channel): array
    {
        $key = $entry->id . ':' . ($canonicalChannelId !== '' ? $canonicalChannelId : $channel);

        return $this->verificationCache[$key] ??= $this->scheduleVerifier->evidenceForChannel($entry, $canonicalChannelId, $channel);
    }

    /**
     * @param array<string, float>|null $parts
     */
    private function textScore(array $fixture, string $title, string $description, array &$parts): float
    {
        $text = $this->normalizeText($title . ' ' . $description);
        $home = $this->nameVariants((string) ($fixture['home_team'] ?? $fixture['strHomeTeam'] ?? ''));
        $away = $this->nameVariants((string) ($fixture['away_team'] ?? $fixture['strAwayTeam'] ?? ''));
        $event = $this->nameVariants((string) ($fixture['event_name'] ?? $fixture['strEvent'] ?? ''));
        $league = $this->nameVariants((string) ($fixture['league'] ?? $fixture['strLeague'] ?? ''));

        $score = 0.0;
        $hasHome = $this->textContainsAny($text, $home);
        $hasAway = $this->textContainsAny($text, $away);

        if ($hasHome && $hasAway) {
            $score += 0.45;
            $parts['both_teams'] = 0.45;
        } elseif ($hasHome || $hasAway) {
            $score += 0.20;
            $parts['one_team'] = 0.20;
        }

        if ($this->textContainsAny($text, $event)) {
            $score += 0.20;
            $parts['event_name'] = 0.20;
        }

        if ($this->textContainsAny($text, $league)) {
            $score += 0.10;
            $parts['league'] = 0.10;
        }

        if (str_contains($text, 'live') || str_contains($text, 'coverage')) {
            $score += 0.05;
            $parts['broadcast_language'] = 0.05;
        }

        return $score;
    }

    private function timeScore(?Carbon $kickoff, SportsBotXmltvProgramme $programme, array &$parts): float
    {
        if (! $kickoff || ! $programme->start_time) {
            return 0.04;
        }

        $delta = abs($programme->start_time->diffInMinutes($kickoff, false));
        if ($delta <= 30) {
            $parts['time_delta'] = 0.15;
            return 0.15;
        }
        if ($delta <= 90) {
            $parts['time_delta'] = 0.08;
            return 0.08;
        }
        if ($delta <= 180) {
            $parts['time_delta'] = 0.03;
            return 0.03;
        }

        return 0;
    }

    private function sourceScore(SportsBotXmltvProgramme $programme, array &$parts): float
    {
        $source = $programme->source_id
            ? ($this->sourceCache[$programme->source_id] ??= SportsBotEpgSource::query()->find($programme->source_id))
            : null;
        if (! $source) {
            $parts['source_quality'] = 0.02;
            return 0.02;
        }

        $score = 0.03;
        if ((string) $source->status === 'working') {
            $score += 0.04;
        }
        if (! $source->stale) {
            $score += 0.02;
        }
        if ((int) $source->priority <= 30) {
            $score += 0.02;
        }

        $parts['source_quality'] = min(0.10, $score);

        return min(0.10, $score);
    }

    private function recordMatch(SportsBotFixtureQueue $entry, array $candidate, string $status, bool $apply): SportsBotEpgFixtureMatch
    {
        return SportsBotEpgFixtureMatch::query()->updateOrCreate(
            [
                'fixture_queue_id' => $entry->id,
                'programme_id' => $candidate['programme_id'],
            ],
            [
                'event_id' => $entry->event_id,
                'canonical_channel_id' => $candidate['canonical_channel_id'],
                'channel' => $candidate['channel'],
                'confidence' => $candidate['confidence'],
                'status' => $status,
                'evidence' => $candidate['evidence'],
                'source_urls' => $candidate['source_urls'],
                'applied_at' => $apply && $status === 'auto_applied' ? now() : null,
            ]
        );
    }

    private function applyMatchToFixture(SportsBotFixtureQueue $entry, array $candidate, SportsBotEpgFixtureMatch $match): void
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $payload = (array) ($entry->payload ?? []);
        $channel = (string) $candidate['channel'];

        $fixture['tv_channel'] = $channel;
        $fixture['tv_channels'] = array_values(array_unique(array_filter([
            $channel,
            ...((array) ($fixture['tv_channels'] ?? [])),
        ])));
        $fixture['epg_programme_id'] = $candidate['programme_id'];
        $fixture['epg_channel_id'] = $candidate['canonical_channel_id'];
        $fixture['epg_confidence'] = $candidate['confidence'];

        $payload['epg_match'] = [
            'status' => 'auto_applied',
            'match_id' => $match->id,
            'programme_id' => $candidate['programme_id'],
            'channel' => $channel,
            'canonical_channel_id' => $candidate['canonical_channel_id'],
            'confidence' => $candidate['confidence'],
            'source_urls' => $candidate['source_urls'],
            'evidence' => $candidate['evidence'],
            'updated_at' => now()->toIso8601String(),
        ];
        unset($payload['epg_review']);

        $entry->fixture_data = $fixture;
        $entry->payload = $payload;
        if ($entry->status !== SportsBotFixtureQueue::STATUS_SENT) {
            $entry->status = SportsBotFixtureQueue::STATUS_DRAFT;
            $entry->asset_status = SportsBotFixtureQueue::ASSET_PENDING;
            $entry->card_path = null;
            $entry->caption = null;
            $entry->error = null;
        }
        $entry->save();

        SportsBotXmltvProgramme::query()
            ->where('id', $candidate['programme_id'])
            ->update([
                'fixture_id' => $entry->id,
                'confidence' => $candidate['confidence'],
            ]);
    }

    private function storeReviewPayload(SportsBotFixtureQueue $entry, array $candidate, SportsBotEpgFixtureMatch $match): void
    {
        $payload = (array) ($entry->payload ?? []);
        $payload['epg_review'] = [
            'status' => 'needs_review',
            'match_id' => $match->id,
            'programme_id' => $candidate['programme_id'],
            'channel' => $candidate['channel'],
            'canonical_channel_id' => $candidate['canonical_channel_id'],
            'confidence' => $candidate['confidence'],
            'source_urls' => $candidate['source_urls'],
            'evidence' => $candidate['evidence'],
            'updated_at' => now()->toIso8601String(),
        ];
        $entry->payload = $payload;
        $entry->save();
    }

    /**
     * @return array<string, SportsBotEpgCorrection>
     */
    private function correctionsFor(SportsBotFixtureQueue $entry): array
    {
        $rows = SportsBotEpgCorrection::query()
            ->where(function ($query) use ($entry): void {
                $query->where('fixture_queue_id', $entry->id);
                if ($entry->event_id) {
                    $query->orWhere('event_id', $entry->event_id);
                }
            })
            ->latest('id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            if ($row->canonical_channel_id) {
                $map['channel:' . $row->canonical_channel_id] ??= $row;
            }
        }

        return $map;
    }

    private function fixtureKickoff(SportsBotFixtureQueue $entry, array $fixture): ?Carbon
    {
        foreach (['kickoff_at', 'start_time', 'date_time', 'datetime'] as $key) {
            if (! empty($fixture[$key])) {
                return $this->parseDateTime((string) $fixture[$key]);
            }
        }

        $date = (string) ($fixture['dateEvent'] ?? $fixture['date'] ?? $entry->publish_date?->toDateString() ?? '');
        $time = (string) ($fixture['strTime'] ?? $fixture['time'] ?? '');
        if ($date !== '') {
            return $this->parseDateTime(trim($date . ' ' . $time));
        }

        return null;
    }

    private function parseDateTime(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function nameVariants(string $name): array
    {
        $name = $this->normalizeText($name);
        if ($name === '') {
            return [];
        }

        $variants = [$name];
        $withoutBrackets = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $name) ?? $name);
        if ($withoutBrackets !== '' && $withoutBrackets !== $name) {
            $variants[] = $withoutBrackets;
        }

        $short = trim(preg_replace('/\b(fc|afc|cf|club|the|united|city|athletic|women|wfc|rlfc|rfc)\b/', '', $withoutBrackets) ?? '');
        $short = trim(preg_replace('/\s+/', ' ', $short) ?? $short);
        if ($short !== '' && mb_strlen($short) >= 3 && $short !== $withoutBrackets) {
            $variants[] = $short;
        }

        return array_values(array_unique($variants));
    }

    private function textContainsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_strlen($needle) >= 3 && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
