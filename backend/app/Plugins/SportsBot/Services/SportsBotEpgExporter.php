<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

class SportsBotEpgExporter
{
    public function __construct(
        private readonly SportsBotEpgChannelNormalizer $channels = new SportsBotEpgChannelNormalizer(),
    ) {
    }

    public function exportXmltv(int $hours = 72): string
    {
        return $this->xmlFromGuide($this->guide($hours));
    }

    /**
     * @return array<string, mixed>
     */
    public function exportJson(int $hours = 72): array
    {
        return $this->jsonFromGuide($this->guide($hours));
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(int $hours = 72): array
    {
        return (array) ($this->guide($hours)['stats'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function writeCachedExports(int $hours = 72): array
    {
        $guide = $this->guide($hours);
        $xmlPath = storage_path('app/sportsbot/epg/guide.xml');
        $jsonPath = storage_path('app/sportsbot/epg/guide.json');

        File::ensureDirectoryExists(dirname($xmlPath));
        $xmlBytes = $this->writeXmlFile($xmlPath, $guide);
        $jsonBytes = $this->writeJsonFile($jsonPath, $guide);

        return [
            'written' => true,
            'xml_path' => $xmlPath,
            'json_path' => $jsonPath,
            'xml_bytes' => $xmlBytes,
            'json_bytes' => $jsonBytes,
            'stats' => $guide['stats'],
        ];
    }

    /**
     * @param array<string, mixed> $guide
     */
    private function xmlFromGuide(array $guide): string
    {
        $lines = [];
        $this->appendXmlHeader($lines, (array) ($guide['channels'] ?? []));
        foreach ((array) ($guide['programmes'] ?? []) as $programme) {
            $lines = array_merge($lines, $this->xmlProgrammeLines($programme));
        }
        $lines[] = '</tv>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $guide
     */
    private function jsonFromGuide(array $guide): array
    {
        $rows = [];
        foreach ((array) ($guide['programmes'] ?? []) as $programme) {
            $rows[] = $this->jsonProgramme($programme);
        }

        return [
            'generated_at' => $guide['generated_at'],
            'hours' => $guide['hours'],
            'channels' => $guide['channels'],
            'programmes' => $rows,
            'stats' => $guide['stats'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guide(int $hours): array
    {
        $hours = max(1, min(336, $hours));
        $sourceMap = SportsBotEpgSource::query()->get()->keyBy('id');
        $channels = [];
        $groups = [];
        $rawProgrammeCount = 0;

        $this->programmeQuery($hours)->chunkById(
            max(250, (int) config('plugins.SportsBot.epg.export_chunk_size', 2000)),
            function ($programmes) use (&$channels, &$groups, &$rawProgrammeCount, $sourceMap): void {
                foreach ($programmes as $programme) {
                    $rawProgrammeCount++;
                    $canonical = trim((string) ($programme->canonical_channel_id ?: $this->channels->canonicalIdFor((string) $programme->channel)));
                    $start = $this->dateString($programme->start_time ?? null);
                    if ($canonical === '' || $start === null) {
                        continue;
                    }

                    $channels[$canonical] ??= [
                        'id' => $canonical,
                        'name' => $this->channels->displayNameForCanonical($canonical, (string) $programme->channel),
                    ];

                    $row = $this->guideRow($programme, $canonical, $start);
                    $key = $this->dedupeKey($row);
                    $score = $this->representativeScore($programme, $sourceMap->get((int) ($programme->source_id ?? 0)));
                    $sourceKey = $programme->source_id
                        ? 'source:' . $programme->source_id
                        : 'url:' . (string) ($programme->source_url ?: $programme->id);
                    $sourceUrl = trim((string) ($programme->source_url ?? ''));

                    if (! isset($groups[$key])) {
                        $groups[$key] = [
                            'row' => $row,
                            'score' => $score,
                            'source_key' => $sourceKey,
                            'source_url' => $sourceUrl,
                            'source_count' => 1,
                            'raw_count' => 1,
                        ];
                        continue;
                    }

                    $groups[$key]['raw_count']++;
                    $this->rememberGroupSource($groups[$key], $sourceKey, $sourceUrl);

                    if ($score > $groups[$key]['score']) {
                        $groups[$key]['row'] = $row;
                        $groups[$key]['score'] = $score;
                    } elseif ((string) ($groups[$key]['row']['description'] ?? '') === '' && (string) ($row['description'] ?? '') !== '') {
                        $groups[$key]['row']['description'] = $row['description'];
                    }
                }
            },
            'id',
        );

        $programmes = [];
        foreach ($groups as $group) {
            $row = $group['row'];
            $row['source_urls'] = $this->groupSourceUrls($group);
            $row['source_count'] = (int) ($group['source_count'] ?? 1);
            $row['raw_count'] = (int) ($group['raw_count'] ?? 1);
            $programmes[] = $row;
        }

        uasort($channels, fn (array $left, array $right): int => [$left['name'], $left['id']] <=> [$right['name'], $right['id']]);

        return [
            'generated_at' => now()->toIso8601String(),
            'hours' => $hours,
            'channels' => array_values($channels),
            'programmes' => $programmes,
            'stats' => [
                'raw_programme_count' => $rawProgrammeCount,
                'canonical_programme_count' => count($programmes),
                'duplicates_removed' => max(0, $rawProgrammeCount - count($programmes)),
                'canonical_channel_count' => count($channels),
                'export_chunk_size' => max(250, (int) config('plugins.SportsBot.epg.export_chunk_size', 2000)),
            ],
        ];
    }

    private function programmeQuery(int $hours)
    {
        return SportsBotXmltvProgramme::query()
            ->toBase()
            ->select([
                'id',
                'source_id',
                'source_url',
                'channel',
                'canonical_channel_id',
                'title',
                'description',
                'start_time',
                'end_time',
                'fixture_id',
                'confidence',
            ])
            ->where('start_time', '>=', now()->subHours(6))
            ->where('start_time', '<=', now()->addHours($hours))
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function guideRow(object $programme, string $canonical, string $start): array
    {
        return [
            'id' => (int) $programme->id,
            'channel_id' => $canonical,
            'channel' => (string) $programme->channel,
            'title' => (string) $programme->title,
            'description' => (string) ($programme->description ?? ''),
            'start_time' => $start,
            'end_time' => $this->dateString($programme->end_time ?? null),
            'fixture_id' => $programme->fixture_id !== null ? (int) $programme->fixture_id : null,
            'confidence' => (float) ($programme->confidence ?? 0),
            'source_url' => (string) ($programme->source_url ?? ''),
        ];
    }

    private function dedupeKey(array $programme): string
    {
        $title = $this->programmeSignature((string) ($programme['title'] ?? ''));
        if ($title === '') {
            $title = 'programme:' . (string) ($programme['id'] ?? '');
        }

        return implode('|', [
            (string) ($programme['channel_id'] ?? ''),
            substr((string) ($programme['start_time'] ?? ''), 0, 16),
            $title,
        ]);
    }

    private function rememberGroupSource(array &$group, string $sourceKey, string $sourceUrl): void
    {
        if ($sourceKey !== (string) ($group['source_key'] ?? '')
            && ! in_array($sourceKey, (array) ($group['extra_source_keys'] ?? []), true)) {
            $group['extra_source_keys'][] = $sourceKey;
            $group['source_count'] = (int) ($group['source_count'] ?? 1) + 1;
        }

        if ($sourceUrl !== ''
            && $sourceUrl !== (string) ($group['source_url'] ?? '')
            && ! in_array($sourceUrl, (array) ($group['extra_source_urls'] ?? []), true)) {
            $group['extra_source_urls'][] = $sourceUrl;
        }
    }

    /**
     * @return array<int, string>
     */
    private function groupSourceUrls(array $group): array
    {
        return array_values(array_unique(array_filter([
            (string) ($group['source_url'] ?? ''),
            ...array_map(fn (mixed $url): string => (string) $url, (array) ($group['extra_source_urls'] ?? [])),
        ])));
    }

    private function programmeSignature(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = mb_strtolower($value);
        $value = preg_replace('/\b(?:live|coverage|hd|uhd)\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/\b(?:v|vs)\.?\b/u', ' vs ', $value) ?? $value;
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function representativeScore(object $programme, ?SportsBotEpgSource $source): int
    {
        $score = (int) round(((float) ($programme->confidence ?? 0)) * 100);
        $score += $programme->fixture_id ? 120 : 0;
        $score += trim((string) ($programme->description ?? '')) !== '' ? min(30, (int) floor(mb_strlen((string) $programme->description) / 20)) : 0;

        if ($source) {
            $score += (string) $source->status === 'working' ? 30 : 0;
            $score += $source->stale ? 0 : 15;
            $score += max(0, 100 - min(100, (int) $source->priority));
        }

        return $score;
    }

    private function writeXmlFile(string $path, array $guide): int
    {
        $handle = $this->openWriteHandle($path);
        try {
            $lines = [];
            $this->appendXmlHeader($lines, (array) ($guide['channels'] ?? []));
            foreach ($lines as $line) {
                fwrite($handle, $line . "\n");
            }
            foreach ((array) ($guide['programmes'] ?? []) as $programme) {
                foreach ($this->xmlProgrammeLines($programme) as $line) {
                    fwrite($handle, $line . "\n");
                }
            }
            fwrite($handle, "</tv>\n");
        } finally {
            fclose($handle);
        }

        return (int) @filesize($path);
    }

    private function writeJsonFile(string $path, array $guide): int
    {
        $handle = $this->openWriteHandle($path);
        try {
            fwrite($handle, "{\n");
            fwrite($handle, '  "generated_at": ' . $this->json((string) ($guide['generated_at'] ?? now()->toIso8601String())) . ",\n");
            fwrite($handle, '  "hours": ' . (int) ($guide['hours'] ?? 72) . ",\n");
            fwrite($handle, '  "channels": ' . $this->json((array) ($guide['channels'] ?? [])) . ",\n");
            fwrite($handle, "  \"programmes\": [\n");

            $first = true;
            foreach ((array) ($guide['programmes'] ?? []) as $programme) {
                if (! $first) {
                    fwrite($handle, ",\n");
                }
                fwrite($handle, '    ' . $this->json($this->jsonProgramme($programme)));
                $first = false;
            }

            fwrite($handle, "\n  ],\n");
            fwrite($handle, '  "stats": ' . $this->json((array) ($guide['stats'] ?? [])) . "\n");
            fwrite($handle, "}\n");
        } finally {
            fclose($handle);
        }

        return (int) @filesize($path);
    }

    /**
     * @param array<int, array<string, mixed>> $channels
     * @param array<int, string> $lines
     */
    private function appendXmlHeader(array &$lines, array $channels): void
    {
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<tv generator-info-name="SportsBot EPG Provider">';

        foreach ($channels as $channel) {
            $id = (string) ($channel['id'] ?? '');
            $name = (string) ($channel['name'] ?? $id);
            if ($id === '') {
                continue;
            }
            $lines[] = '  <channel id="' . $this->xml($id) . '">';
            $lines[] = '    <display-name>' . $this->xml($name) . '</display-name>';
            $lines[] = '  </channel>';
        }
    }

    /**
     * @return array<int, string>
     */
    private function xmlProgrammeLines(array $programme): array
    {
        $canonical = (string) ($programme['channel_id'] ?? '');
        $start = $this->date((string) ($programme['start_time'] ?? ''));
        if ($canonical === '' || ! $start) {
            return [];
        }

        $attrs = [
            'start="' . $this->xml($start->format('YmdHis O')) . '"',
            'channel="' . $this->xml($canonical) . '"',
        ];
        $end = $this->date((string) ($programme['end_time'] ?? ''));
        if ($end) {
            $attrs[] = 'stop="' . $this->xml($end->format('YmdHis O')) . '"';
        }

        $lines = [
            '  <programme ' . implode(' ', $attrs) . '>',
            '    <title>' . $this->xml((string) ($programme['title'] ?? '')) . '</title>',
        ];
        if ((string) ($programme['description'] ?? '') !== '') {
            $lines[] = '    <desc>' . $this->xml((string) $programme['description']) . '</desc>';
        }
        $lines[] = '  </programme>';

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonProgramme(array $programme): array
    {
        return [
            'id' => (int) ($programme['id'] ?? 0),
            'channel_id' => (string) ($programme['channel_id'] ?? ''),
            'channel' => (string) ($programme['channel'] ?? ''),
            'title' => (string) ($programme['title'] ?? ''),
            'description' => (string) ($programme['description'] ?? ''),
            'start_time' => $this->date((string) ($programme['start_time'] ?? ''))?->toIso8601String(),
            'end_time' => $this->date((string) ($programme['end_time'] ?? ''))?->toIso8601String(),
            'fixture_id' => $programme['fixture_id'] ?? null,
            'confidence' => (float) ($programme['confidence'] ?? 0),
            'source_url' => (string) ($programme['source_url'] ?? ''),
            'source_urls' => array_values((array) ($programme['source_urls'] ?? [])),
            'source_count' => (int) ($programme['source_count'] ?? 1),
        ];
    }

    private function dateString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function date(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function openWriteHandle(string $path)
    {
        $handle = fopen($path, 'wb');
        if (! $handle) {
            throw new \RuntimeException('Unable to write EPG export file: ' . $path);
        }

        return $handle;
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : 'null';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
