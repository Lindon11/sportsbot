<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotEpgImportRun;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

class SportsBotEpgSourceImporter
{
    public function __construct(
        private readonly SportsBotEpgChannelNormalizer $channels = new SportsBotEpgChannelNormalizer(),
    ) {
    }

    /**
     * @param array<int, string> $onlyUrls
     * @return array<string, mixed>
     */
    public function importAll(array $onlyUrls = [], int $days = 3): array
    {
        $this->seedConfiguredSources();

        $urls = array_values(array_filter(array_map('trim', $onlyUrls)));
        foreach ($urls as $url) {
            SportsBotEpgSource::query()->firstOrCreate(
                ['url' => $url],
                [
                    'name' => $this->sourceName($url),
                    'type' => 'xmltv',
                    'region' => $this->guessRegion($url),
                    'priority' => 100,
                    'enabled' => true,
                    'status' => 'unchecked',
                ]
            );
        }

        $query = SportsBotEpgSource::query()
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id');

        if ($urls !== []) {
            $query->whereIn('url', $urls);
        }

        $sources = $query->get();
        $results = [];
        $imported = 0;
        $channels = 0;

        foreach ($sources as $source) {
            $result = $this->importSource($source, $days);
            $results[] = $result;
            $imported += (int) ($result['programme_count'] ?? 0);
            $channels += (int) ($result['channel_count'] ?? 0);
        }

        return [
            'sources' => $results,
            'source_count' => count($results),
            'programme_count' => $imported,
            'channel_count' => $channels,
        ];
    }

    public function importSource(SportsBotEpgSource $source, int $days = 3): array
    {
        $started = microtime(true);
        $startedAt = now();
        $status = 'failed';
        $error = null;
        $programmeCount = 0;
        $channelCount = 0;
        $metadata = [];

        try {
            $response = Http::timeout(120)
                ->withHeaders(['User-Agent' => 'SportsBot EPG Provider/1.0'])
                ->get($source->url);

            if (in_array($response->status(), [401, 403, 429], true)) {
                $status = 'blocked';
                throw new \RuntimeException('HTTP ' . $response->status());
            }

            if (! $response->successful()) {
                throw new \RuntimeException('HTTP ' . $response->status());
            }

            $xml = $this->decompress($response->body());
            if ($xml === null) {
                $status = 'blocked';
                throw new \RuntimeException('Feed was not valid XMLTV data');
            }

            $parsed = $this->parseXml($xml, $source, $days);
            $rows = $parsed['rows'];
            $channelCount = count($parsed['channels']);
            $programmeCount = count($rows);

            if ($programmeCount === 0) {
                $status = 'empty';
            } else {
                $this->replaceSourceProgrammes($source, $rows, $days);
                $status = $this->isStale($rows) ? 'stale' : 'working';
            }

            $metadata = [
                'channels' => $channelCount,
                'future_programmes' => $programmeCount,
            ];
        } catch (Throwable $caught) {
            $error = $caught->getMessage();
            if ($status === 'failed') {
                $status = 'failed';
            }
        }

        $finishedAt = now();
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $stale = $status === 'stale';
        $success = in_array($status, ['working', 'stale', 'empty'], true);

        $source->fill([
            'status' => $status,
            'stale' => $stale,
            'programme_count' => $programmeCount,
            'channel_count' => $channelCount,
            'last_checked_at' => $finishedAt,
            'last_success_at' => $success ? $finishedAt : $source->last_success_at,
            'last_failure_at' => $success ? $source->last_failure_at : $finishedAt,
            'last_error' => $error,
            'metadata' => array_merge((array) ($source->metadata ?? []), $metadata),
        ])->save();

        SportsBotEpgImportRun::query()->create([
            'source_id' => $source->id,
            'source_url' => $source->url,
            'status' => $status,
            'programme_count' => $programmeCount,
            'channel_count' => $channelCount,
            'duration_ms' => $durationMs,
            'error' => $error,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'metadata' => $metadata,
        ]);

        return [
            'source_id' => $source->id,
            'url' => $source->url,
            'status' => $status,
            'stale' => $stale,
            'programme_count' => $programmeCount,
            'channel_count' => $channelCount,
            'duration_ms' => $durationMs,
            'error' => $error,
        ];
    }

    public function seedConfiguredSources(): int
    {
        $urls = $this->configuredUrls();
        $created = 0;
        $priority = 10;

        foreach ($urls as $url) {
            $source = SportsBotEpgSource::query()->firstOrCreate(
                ['url' => $url],
                [
                    'name' => $this->sourceName($url),
                    'type' => 'xmltv',
                    'region' => $this->guessRegion($url),
                    'priority' => $priority,
                    'enabled' => true,
                    'status' => 'unchecked',
                ]
            );

            if ($source->wasRecentlyCreated) {
                $created++;
            }

            $priority += 10;
        }

        return $created;
    }

    /**
     * @return array<int, string>
     */
    private function configuredUrls(): array
    {
        $urls = [];
        $single = trim((string) config('plugins.SportsBot.epg.feed_url', ''));
        if ($single !== '') {
            $urls[] = $single;
        }

        $configured = config('plugins.SportsBot.epg.feed_urls', []);
        if (is_array($configured)) {
            foreach ($configured as $url) {
                $url = trim((string) $url);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function decompress(string $body): ?string
    {
        if (substr($body, 0, 3) === "\x1f\x8b\x08") {
            $decompressed = gzdecode($body);
            return $decompressed !== false ? $decompressed : null;
        }

        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<tv')) {
            return $body;
        }

        if (stripos($trimmed, '<html') !== false || stripos($trimmed, 'cloudflare') !== false) {
            return null;
        }

        return null;
    }

    /**
     * @return array{channels: array<string, string>, rows: array<int, array<string, mixed>>}
     */
    private function parseXml(string $xml, SportsBotEpgSource $source, int $days): array
    {
        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (! $element instanceof SimpleXMLElement) {
            return ['channels' => [], 'rows' => []];
        }

        $channelMap = [];
        foreach ($element->channel as $channel) {
            $id = trim((string) $channel['id']);
            $name = trim((string) $channel->{'display-name'});
            if ($id !== '') {
                $channelMap[$id] = $name ?: $id;
            }
        }

        $rows = [];
        $now = now();
        $startCutoff = now()->subHours(6);
        $endCutoff = now()->addDays(max(1, min(14, $days)));

        foreach ($element->programme as $prog) {
            $channelId = trim((string) $prog['channel']);
            $channel = $channelMap[$channelId] ?? $channelId;
            $startTime = $this->parseXmltvTime(trim((string) $prog['start']));
            $endTime = $this->parseXmltvTime(trim((string) $prog['stop']));

            if ($startTime === null || $startTime < $startCutoff || $startTime > $endCutoff) {
                continue;
            }

            $title = trim((string) $prog->title);
            if ($channel === '' || $title === '') {
                continue;
            }

            $canonical = $this->channels->canonicalIdFor($channel, $source->region);
            $this->channels->rememberAlias($channel, $canonical, $source->region, 'import', $channel, 0.85);

            $rows[] = [
                'source_id' => $source->id,
                'source_url' => $source->url,
                'channel' => mb_substr($channel, 0, 120),
                'canonical_channel_id' => $canonical,
                'title' => mb_substr($title, 0, 255),
                'description' => trim((string) $prog->desc),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'fixture_id' => null,
                'confidence' => 0,
                'raw_data' => json_encode([
                    'channel_id' => $channelId,
                    'category' => trim((string) $prog->category),
                    'sub_title' => trim((string) $prog->{'sub-title'}),
                    'source_name' => $source->name,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return [
            'channels' => $channelMap,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function replaceSourceProgrammes(SportsBotEpgSource $source, array $rows, int $days): void
    {
        $from = now()->subHours(6);
        $to = now()->addDays(max(1, min(14, $days)));

        SportsBotXmltvProgramme::query()
            ->where('source_id', $source->id)
            ->whereBetween('start_time', [$from, $to])
            ->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            SportsBotXmltvProgramme::query()->insert($chunk);
        }

        SportsBotXmltvProgramme::query()
            ->where('source_id', $source->id)
            ->where('start_time', '<', now()->subDay())
            ->delete();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function isStale(array $rows): bool
    {
        $futureCutoff = now()->addHours(18);
        foreach ($rows as $row) {
            $start = $row['start_time'] ?? null;
            if ($start instanceof Carbon && $start <= $futureCutoff && $start >= now()->subHour()) {
                return false;
            }
        }

        return true;
    }

    private function parseXmltvTime(string $time): ?Carbon
    {
        if ($time === '') {
            return null;
        }

        try {
            return Carbon::parse($time);
        } catch (Throwable) {
            try {
                $time = preg_replace('/\s*[+-]\d{4}\s*$/', '', $time) ?? $time;
                return Carbon::parse($time);
            } catch (Throwable) {
                return null;
            }
        }
    }

    private function sourceName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'Public XMLTV';
        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');

        return $path !== '' ? $host . '/' . basename($path) : $host;
    }

    private function guessRegion(string $url): ?string
    {
        $value = strtolower($url);
        foreach (['uk', 'gb', 'ie', 'us', 'ca', 'au', 'de', 'fr', 'es', 'it'] as $region) {
            if (preg_match('/(?:_|-|\/|\.)' . preg_quote($region, '/') . '(?:_|-|\.|\/|$)/', $value) === 1) {
                return strtoupper($region === 'gb' ? 'UK' : $region);
            }
        }

        return null;
    }
}
