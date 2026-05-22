<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use Illuminate\Support\Facades\Storage;

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
        $xml = $this->xmlFromGuide($guide);
        $json = json_encode($this->jsonFromGuide($guide), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        Storage::disk('local')->put('sportsbot/epg/guide.xml', $xml);
        Storage::disk('local')->put('sportsbot/epg/guide.json', $json !== false ? $json : '{}');

        return [
            'written' => true,
            'xml_path' => storage_path('app/sportsbot/epg/guide.xml'),
            'json_path' => storage_path('app/sportsbot/epg/guide.json'),
            'xml_bytes' => strlen($xml),
            'json_bytes' => strlen($json !== false ? $json : '{}'),
            'stats' => $guide['stats'],
        ];
    }

    /**
     * @param array<string, mixed> $guide
     */
    private function xmlFromGuide(array $guide): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<tv generator-info-name="SportsBot EPG Provider">',
        ];

        foreach ((array) ($guide['channels'] ?? []) as $channel) {
            $id = (string) ($channel['id'] ?? '');
            $name = (string) ($channel['name'] ?? $id);
            if ($id === '') {
                continue;
            }
            $lines[] = '  <channel id="' . $this->xml($id) . '">';
            $lines[] = '    <display-name>' . $this->xml($name) . '</display-name>';
            $lines[] = '  </channel>';
        }

        foreach ((array) ($guide['programmes'] ?? []) as $programme) {
            $canonical = (string) ($programme['channel_id'] ?? '');
            $start = $programme['start_time'] ?? null;
            if ($canonical === '' || ! $start) {
                continue;
            }

            $attrs = [
                'start="' . $this->xml($start->format('YmdHis O')) . '"',
                'channel="' . $this->xml($canonical) . '"',
            ];
            if ($programme['end_time'] ?? null) {
                $attrs[] = 'stop="' . $this->xml($programme['end_time']->format('YmdHis O')) . '"';
            }

            $lines[] = '  <programme ' . implode(' ', $attrs) . '>';
            $lines[] = '    <title>' . $this->xml((string) ($programme['title'] ?? '')) . '</title>';
            if ((string) ($programme['description'] ?? '') !== '') {
                $lines[] = '    <desc>' . $this->xml((string) $programme['description']) . '</desc>';
            }
            $lines[] = '  </programme>';
        }

        $lines[] = '</tv>';

        return implode("\n", $lines) . "\n";
    }

    private function jsonFromGuide(array $guide): array
    {
        $rows = [];
        foreach ((array) ($guide['programmes'] ?? []) as $programme) {
            $rows[] = [
                'id' => $programme['id'],
                'channel_id' => $programme['channel_id'],
                'channel' => $programme['channel'],
                'title' => $programme['title'],
                'description' => $programme['description'],
                'start_time' => $programme['start_time']?->toIso8601String(),
                'end_time' => $programme['end_time']?->toIso8601String(),
                'fixture_id' => $programme['fixture_id'],
                'confidence' => $programme['confidence'],
                'source_url' => $programme['source_url'],
                'source_urls' => $programme['source_urls'],
                'source_count' => $programme['source_count'],
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
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
        $rawProgrammes = $this->programmes($hours);
        $sourceMap = SportsBotEpgSource::query()
            ->whereIn('id', $rawProgrammes->pluck('source_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');
        $groups = [];
        $channels = [];

        foreach ($rawProgrammes as $programme) {
            $canonical = (string) ($programme->canonical_channel_id ?: $this->channels->canonicalIdFor((string) $programme->channel));
            if ($canonical === '' || ! $programme->start_time) {
                continue;
            }

            $channels[$canonical] ??= [
                'id' => $canonical,
                'name' => $this->channels->displayNameForCanonical($canonical, (string) $programme->channel),
            ];

            $row = $this->guideRow($programme, $canonical);
            $key = $this->dedupeKey($row);
            $score = $this->representativeScore($programme, $sourceMap->get($programme->source_id));
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'row' => $row,
                    'score' => $score,
                    'source_urls' => [],
                    'source_keys' => [],
                    'raw_count' => 0,
                ];
            }

            $sourceKey = $programme->source_id
                ? 'source:' . $programme->source_id
                : 'url:' . (string) ($programme->source_url ?: $programme->id);
            $groups[$key]['source_keys'][$sourceKey] = true;
            if ((string) ($programme->source_url ?? '') !== '') {
                $groups[$key]['source_urls'][(string) $programme->source_url] = (string) $programme->source_url;
            }
            $groups[$key]['raw_count']++;

            if ($score > $groups[$key]['score']) {
                $groups[$key]['row'] = $row;
                $groups[$key]['score'] = $score;
            } elseif ((string) ($groups[$key]['row']['description'] ?? '') === '' && (string) ($row['description'] ?? '') !== '') {
                $groups[$key]['row']['description'] = $row['description'];
            }
        }

        $programmes = [];
        foreach ($groups as $group) {
            $row = $group['row'];
            $row['source_urls'] = array_values($group['source_urls']);
            $row['source_count'] = count($group['source_keys']);
            $row['raw_count'] = (int) $group['raw_count'];
            $programmes[] = $row;
        }

        usort($programmes, fn (array $left, array $right): int => [
            $left['start_time']?->getTimestamp() ?? 0,
            $left['channel_id'],
            $left['title'],
        ] <=> [
            $right['start_time']?->getTimestamp() ?? 0,
            $right['channel_id'],
            $right['title'],
        ]);
        uasort($channels, fn (array $left, array $right): int => [$left['name'], $left['id']] <=> [$right['name'], $right['id']]);

        return [
            'hours' => $hours,
            'channels' => array_values($channels),
            'programmes' => $programmes,
            'stats' => [
                'raw_programme_count' => $rawProgrammes->count(),
                'canonical_programme_count' => count($programmes),
                'duplicates_removed' => max(0, $rawProgrammes->count() - count($programmes)),
                'canonical_channel_count' => count($channels),
            ],
        ];
    }

    private function programmes(int $hours)
    {
        return SportsBotXmltvProgramme::query()
            ->where('start_time', '>=', now()->subHours(6))
            ->where('start_time', '<=', now()->addHours(max(1, min(336, $hours))))
            ->orderBy('start_time')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function guideRow(SportsBotXmltvProgramme $programme, string $canonical): array
    {
        return [
            'id' => $programme->id,
            'channel_id' => $canonical,
            'channel' => (string) $programme->channel,
            'title' => (string) $programme->title,
            'description' => (string) ($programme->description ?? ''),
            'start_time' => $programme->start_time,
            'end_time' => $programme->end_time,
            'fixture_id' => $programme->fixture_id,
            'confidence' => (float) $programme->confidence,
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
            $programme['start_time']?->format('YmdHi') ?? '',
            $title,
        ]);
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

    private function representativeScore(SportsBotXmltvProgramme $programme, ?SportsBotEpgSource $source): int
    {
        $score = (int) round(((float) $programme->confidence) * 100);
        $score += $programme->fixture_id ? 120 : 0;
        $score += trim((string) ($programme->description ?? '')) !== '' ? min(30, (int) floor(mb_strlen((string) $programme->description) / 20)) : 0;

        if ($source) {
            $score += (string) $source->status === 'working' ? 30 : 0;
            $score += $source->stale ? 0 : 15;
            $score += max(0, 100 - min(100, (int) $source->priority));
        }

        return $score;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
