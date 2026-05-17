<?php

namespace App\Plugins\SportsBot\Services;

use Carbon\Carbon;

class ScraperResultNormalizer
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'event_poster',
        'event_name',
        'home_team',
        'away_team',
        'date_label',
        'kickoff_label',
        'time',
        'venue',
        'tv_channel',
        'tv_channels',
        'f1_sessions',
    ];

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    public function normalize(array $results): array
    {
        $fields = [];
        $fieldSources = [];
        $sourceUrls = [];
        $providers = [];
        $bestConfidence = 0.0;

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $provider = (string) ($result['provider'] ?? 'unknown');
            $confidence = max(0.0, min(1.0, (float) ($result['confidence'] ?? 0.0)));
            $sourceUrl = trim((string) ($result['source_url'] ?? ''));
            $candidateFields = $this->sanitizeFields((array) ($result['fields'] ?? []));

            if ($candidateFields === []) {
                continue;
            }

            $providers[$provider] = [
                'confidence' => max((float) ($providers[$provider]['confidence'] ?? 0.0), $confidence),
                'source_url' => $sourceUrl,
                'fields_found' => array_keys($candidateFields),
            ];

            if ($sourceUrl !== '') {
                $sourceUrls[$sourceUrl] = $sourceUrl;
            }

            foreach ($candidateFields as $field => $value) {
                $existingConfidence = (float) ($fieldSources[$field]['confidence'] ?? -1);
                if ($confidence >= $existingConfidence && !$this->isEmptyValue($value)) {
                    $fields[$field] = $value;
                    $fieldSources[$field] = [
                        'provider' => $provider,
                        'source_url' => $sourceUrl,
                        'confidence' => $confidence,
                    ];
                }
            }

            $bestConfidence = max($bestConfidence, $confidence);
        }

        return [
            'fields' => $fields,
            'field_sources' => $fieldSources,
            'fields_found' => array_keys($fields),
            'source_urls' => array_values($sourceUrls),
            'providers' => array_values($providers),
            'confidence' => $bestConfidence,
            'normalized_at' => now()->toISOString(),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function sanitizeFields(array $fields): array
    {
        $clean = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }

            $value = $fields[$field];
            if (in_array($field, ['tv_channels', 'f1_sessions'], true)) {
                $value = $this->sanitizeArrayValue($value);
            } else {
                $value = $this->sanitizeStringValue($value);
            }

            if (!$this->isEmptyValue($value)) {
                $clean[$field] = $value;
            }
        }

        if (isset($clean['event_poster']) && !$this->isPublicImageUrl((string) $clean['event_poster'])) {
            unset($clean['event_poster']);
        }

        if (isset($clean['date_time'])) {
            unset($clean['date_time']);
        }

        return $clean;
    }

    /**
     * @return array<string, string>
     */
    public function dateTimeFields(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        try {
            $date = Carbon::parse($value, config('plugins.SportsBot.fixtures_today.timezone', 'Europe/London'));

            return [
                'date_label' => $date->format('l j F Y'),
                'kickoff_label' => $date->format('H:i T'),
                'time' => $date->format('H:i'),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    private function sanitizeStringValue(mixed $value): string
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_substr($value, 0, 500);
    }

    /**
     * @return array<int, mixed>
     */
    private function sanitizeArrayValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $row = [];
                foreach ($item as $key => $rowValue) {
                    $row[(string) $key] = $this->sanitizeStringValue($rowValue);
                }
                $clean[] = array_filter($row, static fn (mixed $rowValue): bool => $rowValue !== '');
            } else {
                $string = $this->sanitizeStringValue($item);
                if ($string !== '') {
                    $clean[] = $string;
                }
            }
        }

        return array_slice(array_values(array_filter($clean)), 0, 20);
    }

    private function isPublicImageUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(png|jpe?g|webp)(\?.*)?$/', $path) === 1 || str_contains($path, '/image');
    }

    private function isEmptyValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }
}
