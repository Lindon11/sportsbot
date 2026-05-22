<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotEpgImportRun;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;
use XMLReader;

class SportsBotEpgSourceImporter
{
    public function __construct(
        private readonly SportsBotEpgChannelNormalizer $channels = new SportsBotEpgChannelNormalizer(),
    ) {
    }

    /**
     * @param array<int, string> $onlyUrls
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function importAll(array $onlyUrls = [], int $days = 3, array $options = []): array
    {
        $options = $this->normaliseOptions($options);
        $this->seedConfiguredSources();

        if ((string) config('plugins.SportsBot.epg.source_policy', 'uk_sports_first') === 'uk_sports_first') {
            $this->applyUkSportsPolicy();
        }

        $urls = array_values(array_filter(array_map('trim', $onlyUrls)));
        foreach ($urls as $url) {
            SportsBotEpgSource::query()->firstOrCreate(
                ['url' => $url],
                [
                    'name' => $this->sourceName($url),
                    'type' => str_starts_with($url, 'file://') ? 'grabber_output' : 'xmltv',
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

        if (($options['region'] ?? null) !== null) {
            $query->where(function ($query) use ($options): void {
                $query->whereNull('region')
                    ->orWhere('region', strtoupper((string) $options['region']));
            });
        }

        if ((int) $options['source_limit'] > 0) {
            $query->limit((int) $options['source_limit']);
        }

        $results = [];
        $imported = 0;
        $channels = 0;
        $skipped = 0;

        foreach ($query->get() as $source) {
            $result = $this->importSource($source, $days, $options);
            $results[] = $result;
            $imported += (int) ($result['programme_count'] ?? 0);
            $channels += (int) ($result['channel_count'] ?? 0);
            if (($result['status'] ?? null) === 'skipped_unchanged') {
                $skipped++;
            }
        }

        return [
            'sources' => $results,
            'source_count' => count($results),
            'programme_count' => $imported,
            'channel_count' => $channels,
            'skipped_unchanged' => $skipped,
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function importSource(SportsBotEpgSource $source, int $days = 3, array $options = []): array
    {
        $options = $this->normaliseOptions($options);
        $started = microtime(true);
        $startedAt = now();
        $status = 'failed';
        $error = null;
        $programmeCount = 0;
        $channelCount = 0;
        $metadata = [];
        $download = null;

        try {
            $download = $this->downloadToXmlFile($source, (bool) $options['skip_unchanged']);
            if (($download['status'] ?? null) === 'skipped_unchanged') {
                $status = 'skipped_unchanged';
                $programmeCount = (int) $source->programme_count;
                $channelCount = (int) $source->channel_count;
                $metadata = $download;
            } else {
                $import = $this->streamXmlIntoDatabase(
                    (string) $download['xml_path'],
                    $source,
                    $days,
                    (int) $options['max_programmes'],
                    (int) $options['chunk_size'],
                );

                $programmeCount = (int) $import['programme_count'];
                $channelCount = (int) $import['channel_count'];
                $status = $programmeCount === 0 ? 'empty' : ((bool) $import['stale'] ? 'stale' : 'working');
                $metadata = array_merge($download, $import);
            }
        } catch (Throwable $caught) {
            $error = $caught->getMessage();
            if (preg_match('/HTTP (401|403|429)/', $error) === 1) {
                $status = 'blocked';
            }
            if (in_array($status, ['failed', 'blocked'], true) === false) {
                $status = 'failed';
            }
        } finally {
            if (is_array($download)) {
                foreach (['download_path', 'xml_path'] as $pathKey) {
                    $path = (string) ($download[$pathKey] ?? '');
                    if ($path !== '' && str_starts_with($path, $this->tmpDir()) && is_file($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        $finishedAt = now();
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $success = in_array($status, ['working', 'stale', 'empty', 'skipped_unchanged'], true);
        $sourceStatus = $status === 'skipped_unchanged' ? ((string) ($source->status ?: 'working')) : $status;

        $source->fill([
            'status' => $sourceStatus,
            'stale' => $sourceStatus === 'stale',
            'programme_count' => $programmeCount,
            'channel_count' => $channelCount,
            'last_checked_at' => $finishedAt,
            'last_success_at' => $success ? $finishedAt : $source->last_success_at,
            'last_failure_at' => $success ? $source->last_failure_at : $finishedAt,
            'last_error' => $error,
            'etag' => $metadata['etag'] ?? $source->etag,
            'last_modified_header' => $metadata['last_modified_header'] ?? $source->last_modified_header,
            'content_hash' => $metadata['content_hash'] ?? $source->content_hash,
            'bytes_downloaded' => (int) ($metadata['bytes_downloaded'] ?? $source->bytes_downloaded ?? 0),
            'metadata' => array_merge((array) ($source->metadata ?? []), $metadata, [
                'last_import_options' => $options,
                'duration_ms' => $durationMs,
            ]),
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
            'stale' => $sourceStatus === 'stale',
            'programme_count' => $programmeCount,
            'channel_count' => $channelCount,
            'duration_ms' => $durationMs,
            'bytes_downloaded' => (int) ($metadata['bytes_downloaded'] ?? 0),
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
                    'type' => str_starts_with($url, 'file://') ? 'grabber_output' : 'xmltv',
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

    public function applyUkSportsPolicy(): array
    {
        $disabled = SportsBotEpgSource::query()
            ->where('url', 'like', '%epgshare01%')
            ->whereNotIn('region', ['UK', 'GB'])
            ->update(['enabled' => false]);

        $enabled = SportsBotEpgSource::query()
            ->where('url', 'like', '%epgshare01%')
            ->whereIn('region', ['UK', 'GB'])
            ->update(['enabled' => true, 'priority' => 10]);

        return [
            'policy' => 'uk_sports_first',
            'enabled_uk_sources' => $enabled,
            'disabled_non_uk_epgshare_sources' => $disabled,
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function normaliseOptions(array $options): array
    {
        return [
            'region' => isset($options['region']) && trim((string) $options['region']) !== '' ? strtoupper(trim((string) $options['region'])) : null,
            'source_limit' => max(0, (int) ($options['source_limit'] ?? 0)),
            'max_programmes' => max(0, (int) ($options['max_programmes'] ?? config('plugins.SportsBot.epg.max_programmes', 80000))),
            'chunk_size' => max(100, min(10000, (int) ($options['chunk_size'] ?? config('plugins.SportsBot.epg.import_chunk_size', 2000)))),
            'skip_unchanged' => array_key_exists('skip_unchanged', $options)
                ? (bool) $options['skip_unchanged']
                : (bool) config('plugins.SportsBot.epg.skip_unchanged', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadToXmlFile(SportsBotEpgSource $source, bool $skipUnchanged): array
    {
        $isLocalFile = str_starts_with((string) $source->url, 'file://');
        if (! $isLocalFile) {
            $this->assertAllowedRemoteUrl((string) $source->url);
        }

        File::ensureDirectoryExists($this->tmpDir());
        $downloadPath = tempnam($this->tmpDir(), 'epg-download-');
        $xmlPath = tempnam($this->tmpDir(), 'epg-xml-');

        if ($downloadPath === false || $xmlPath === false) {
            throw new \RuntimeException('Unable to create EPG temp files');
        }

        if ($isLocalFile) {
            $path = substr((string) $source->url, 7);
            if (! is_file($path)) {
                throw new \RuntimeException('Local EPG output file does not exist');
            }
            copy($path, $downloadPath);
            $bytes = filesize($downloadPath) ?: 0;
            $hash = hash_file('sha256', $downloadPath) ?: null;

            if ($skipUnchanged && $hash !== null && $source->content_hash === $hash) {
                return [
                    'status' => 'skipped_unchanged',
                    'download_path' => $downloadPath,
                    'xml_path' => $xmlPath,
                    'bytes_downloaded' => $bytes,
                    'content_hash' => $hash,
                ];
            }

            $this->normaliseDownloadedFile($downloadPath, $xmlPath);

            return [
                'status' => 'downloaded',
                'download_path' => $downloadPath,
                'xml_path' => $xmlPath,
                'bytes_downloaded' => $bytes,
                'content_hash' => $hash,
            ];
        }

        $headers = ['User-Agent' => 'SportsBot EPG Provider/1.0'];
        if ($skipUnchanged && trim((string) $source->etag) !== '') {
            $headers['If-None-Match'] = (string) $source->etag;
        }
        if ($skipUnchanged && trim((string) $source->last_modified_header) !== '') {
            $headers['If-Modified-Since'] = (string) $source->last_modified_header;
        }

        $response = Http::timeout(120)
            ->withHeaders($headers)
            ->sink($downloadPath)
            ->get((string) $source->url);

        if ($response->status() === 304) {
            return [
                'status' => 'skipped_unchanged',
                'download_path' => $downloadPath,
                'xml_path' => $xmlPath,
                'etag' => $source->etag,
                'last_modified_header' => $source->last_modified_header,
                'content_hash' => $source->content_hash,
                'bytes_downloaded' => 0,
            ];
        }

        if (in_array($response->status(), [401, 403, 429], true)) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }

        $bytes = filesize($downloadPath) ?: 0;
        $hash = hash_file('sha256', $downloadPath) ?: null;
        if ($skipUnchanged && $hash !== null && $source->content_hash === $hash) {
            return [
                'status' => 'skipped_unchanged',
                'download_path' => $downloadPath,
                'xml_path' => $xmlPath,
                'etag' => $response->header('ETag') ?: $source->etag,
                'last_modified_header' => $response->header('Last-Modified') ?: $source->last_modified_header,
                'content_hash' => $hash,
                'bytes_downloaded' => $bytes,
            ];
        }

        $this->normaliseDownloadedFile($downloadPath, $xmlPath);

        return [
            'status' => 'downloaded',
            'download_path' => $downloadPath,
            'xml_path' => $xmlPath,
            'etag' => $response->header('ETag'),
            'last_modified_header' => $response->header('Last-Modified'),
            'content_hash' => $hash,
            'bytes_downloaded' => $bytes,
        ];
    }

    private function normaliseDownloadedFile(string $downloadPath, string $xmlPath): void
    {
        if ($this->isGzipFile($downloadPath)) {
            $this->gunzipFile($downloadPath, $xmlPath);
            return;
        }

        $start = file_get_contents($downloadPath, false, null, 0, 256);
        if ($start === false || (str_contains(ltrim($start), '<tv') === false && str_starts_with(ltrim($start), '<?xml') === false)) {
            throw new \RuntimeException('Feed was not valid XMLTV data');
        }

        copy($downloadPath, $xmlPath);
    }

    private function isGzipFile(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return false;
        }
        $bytes = fread($handle, 3);
        fclose($handle);

        return $bytes === "\x1f\x8b\x08";
    }

    private function gunzipFile(string $from, string $to): void
    {
        $input = gzopen($from, 'rb');
        $output = fopen($to, 'wb');
        if (! $input || ! $output) {
            throw new \RuntimeException('Unable to decompress EPG feed');
        }

        while (! gzeof($input)) {
            fwrite($output, (string) gzread($input, 1024 * 1024));
        }

        gzclose($input);
        fclose($output);
    }

    /**
     * @return array<string, mixed>
     */
    private function streamXmlIntoDatabase(string $xmlPath, SportsBotEpgSource $source, int $days, int $maxProgrammes, int $chunkSize): array
    {
        if (! class_exists(XMLReader::class)) {
            throw new \RuntimeException('PHP XMLReader extension is required for streaming EPG imports');
        }

        $from = now()->subHours(6);
        $to = now()->addDays(max(1, min(14, $days)));
        SportsBotXmltvProgramme::query()
            ->where('source_id', $source->id)
            ->whereBetween('start_time', [$from, $to])
            ->delete();

        $reader = new XMLReader();
        if (! $reader->open($xmlPath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \RuntimeException('Unable to open XMLTV data');
        }

        $channelMap = [];
        $rows = [];
        $programmeCount = 0;
        $hasFreshProgramme = false;
        $now = now();
        $freshCutoff = now()->addHours(18);

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'channel') {
                $channel = $this->xmlNode($reader);
                if ($channel instanceof SimpleXMLElement) {
                    $id = trim((string) $channel['id']);
                    $name = trim((string) $channel->{'display-name'});
                    $logoUrl = trim((string) ($channel->icon['src'] ?? ''));
                    if ($id !== '') {
                        $channelMap[$id] = [
                            'name' => $name ?: $id,
                            'logo_url' => $logoUrl,
                        ];
                    }
                }
                continue;
            }

            if ($reader->name !== 'programme') {
                continue;
            }

            if ($maxProgrammes > 0 && $programmeCount >= $maxProgrammes) {
                break;
            }

            $programme = $this->xmlNode($reader);
            if (! $programme instanceof SimpleXMLElement) {
                continue;
            }

            $row = $this->programmeRow($programme, $channelMap, $source, $days, $now);
            if ($row === null) {
                continue;
            }

            $programmeCount++;
            $start = $row['start_time'] ?? null;
            if ($start instanceof Carbon && $start <= $freshCutoff && $start >= now()->subHour()) {
                $hasFreshProgramme = true;
            }

            $rows[] = $row;
            if (count($rows) >= $chunkSize) {
                SportsBotXmltvProgramme::query()->insert($rows);
                $rows = [];
            }
        }

        $reader->close();

        if ($rows !== []) {
            SportsBotXmltvProgramme::query()->insert($rows);
        }

        SportsBotXmltvProgramme::query()
            ->where('source_id', $source->id)
            ->where('start_time', '<', now()->subDay())
            ->delete();

        return [
            'programme_count' => $programmeCount,
            'channel_count' => count($channelMap),
            'stale' => ! $hasFreshProgramme,
            'max_programmes_hit' => $maxProgrammes > 0 && $programmeCount >= $maxProgrammes,
        ];
    }

    private function xmlNode(XMLReader $reader): ?SimpleXMLElement
    {
        $xml = $reader->readOuterXml();
        if ($xml === '') {
            return null;
        }

        $node = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return $node instanceof SimpleXMLElement ? $node : null;
    }

    /**
     * @param array<string, array{name: string, logo_url: string}> $channelMap
     * @return array<string, mixed>|null
     */
    private function programmeRow(SimpleXMLElement $prog, array $channelMap, SportsBotEpgSource $source, int $days, Carbon $now): ?array
    {
        $channelId = trim((string) $prog['channel']);
        $channelData = $channelMap[$channelId] ?? ['name' => $channelId, 'logo_url' => ''];
        $channel = (string) ($channelData['name'] ?? $channelId);
        $startTime = $this->parseXmltvTime(trim((string) $prog['start']));
        $endTime = $this->parseXmltvTime(trim((string) $prog['stop']));
        $startCutoff = now()->subHours(6);
        $endCutoff = now()->addDays(max(1, min(14, $days)));

        if ($startTime === null || $startTime < $startCutoff || $startTime > $endCutoff) {
            return null;
        }

        $title = trim((string) $prog->title);
        if ($channel === '' || $title === '') {
            return null;
        }

        $canonical = $this->channels->canonicalIdFor($channel, $source->region);
        $this->channels->rememberAlias($channel, $canonical, $source->region, 'import', $channel, 0.85, (string) ($channelData['logo_url'] ?? ''));

        return [
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
        if (str_starts_with($url, 'file://')) {
            return 'Grabber output/' . basename(substr($url, 7));
        }

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

        return (string) config('plugins.SportsBot.epg.default_region', 'UK') ?: null;
    }

    private function assertAllowedRemoteUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('EPG feed URL must use public HTTP or HTTPS');
        }

        if ((bool) config('plugins.SportsBot.epg.allow_private_feed_urls', false)) {
            return;
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            throw new \RuntimeException('Private EPG feed host is not allowed');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Private EPG feed address is not allowed');
            }
        }
    }

    private function tmpDir(): string
    {
        return storage_path('app/sportsbot/epg/tmp');
    }
}
