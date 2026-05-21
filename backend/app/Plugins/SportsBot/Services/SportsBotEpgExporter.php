<?php

namespace App\Plugins\SportsBot\Services;

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
        $programmes = $this->programmes($hours);
        $channels = [];

        foreach ($programmes as $programme) {
            $canonical = (string) ($programme->canonical_channel_id ?: $this->channels->canonicalIdFor((string) $programme->channel));
            if ($canonical === '') {
                continue;
            }
            $channels[$canonical] ??= $this->channels->displayNameForCanonical($canonical, (string) $programme->channel);
        }

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<tv generator-info-name="SportsBot EPG Provider">',
        ];

        foreach ($channels as $id => $name) {
            $lines[] = '  <channel id="' . $this->xml($id) . '">';
            $lines[] = '    <display-name>' . $this->xml($name) . '</display-name>';
            $lines[] = '  </channel>';
        }

        foreach ($programmes as $programme) {
            $canonical = (string) ($programme->canonical_channel_id ?: $this->channels->canonicalIdFor((string) $programme->channel));
            if ($canonical === '' || ! $programme->start_time) {
                continue;
            }

            $attrs = [
                'start="' . $this->xml($programme->start_time->format('YmdHis O')) . '"',
                'channel="' . $this->xml($canonical) . '"',
            ];
            if ($programme->end_time) {
                $attrs[] = 'stop="' . $this->xml($programme->end_time->format('YmdHis O')) . '"';
            }

            $lines[] = '  <programme ' . implode(' ', $attrs) . '>';
            $lines[] = '    <title>' . $this->xml((string) $programme->title) . '</title>';
            if ((string) ($programme->description ?? '') !== '') {
                $lines[] = '    <desc>' . $this->xml((string) $programme->description) . '</desc>';
            }
            $lines[] = '  </programme>';
        }

        $lines[] = '</tv>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function exportJson(int $hours = 72): array
    {
        $programmes = $this->programmes($hours);
        $channels = [];
        $rows = [];

        foreach ($programmes as $programme) {
            $canonical = (string) ($programme->canonical_channel_id ?: $this->channels->canonicalIdFor((string) $programme->channel));
            if ($canonical === '') {
                continue;
            }

            $channels[$canonical] ??= [
                'id' => $canonical,
                'name' => $this->channels->displayNameForCanonical($canonical, (string) $programme->channel),
            ];

            $rows[] = [
                'id' => $programme->id,
                'channel_id' => $canonical,
                'channel' => (string) $programme->channel,
                'title' => (string) $programme->title,
                'description' => (string) ($programme->description ?? ''),
                'start_time' => $programme->start_time?->toIso8601String(),
                'end_time' => $programme->end_time?->toIso8601String(),
                'fixture_id' => $programme->fixture_id,
                'confidence' => (float) $programme->confidence,
                'source_url' => $programme->source_url,
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'hours' => $hours,
            'channels' => array_values($channels),
            'programmes' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function writeCachedExports(int $hours = 72): array
    {
        $xml = $this->exportXmltv($hours);
        $json = json_encode($this->exportJson($hours), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        Storage::disk('local')->put('sportsbot/epg/guide.xml', $xml);
        Storage::disk('local')->put('sportsbot/epg/guide.json', $json !== false ? $json : '{}');

        return [
            'written' => true,
            'xml_path' => storage_path('app/sportsbot/epg/guide.xml'),
            'json_path' => storage_path('app/sportsbot/epg/guide.json'),
            'xml_bytes' => strlen($xml),
            'json_bytes' => strlen($json !== false ? $json : '{}'),
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

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
