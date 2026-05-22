<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotEpgChannelAlias;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SportsBotEpgGuideService
{
    /**
     * @var array<int, string>
     */
    private array $ukSportsChannels = [
        'sky_sports_main_event',
        'sky_sports_premier_league',
        'sky_sports_football',
        'sky_sports_action',
        'sky_sports_arena',
        'sky_sports_cricket',
        'sky_sports_golf',
        'sky_sports_f1',
        'sky_sports_racing',
        'sky_sports_tennis',
        'sky_sports_mix',
        'sky_sports_news',
        'tnt_sports_1',
        'tnt_sports_2',
        'tnt_sports_3',
        'tnt_sports_4',
        'eurosport_1',
        'eurosport_2',
        'premier_sports_1',
        'premier_sports_2',
        'bbc_one',
        'bbc_two',
        'itv_1',
        'channel_4',
        'channel_5',
    ];

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function day(string $date, array $filters = []): array
    {
        $day = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        $end = $day->copy()->addDay();
        $region = $this->region($filters['region'] ?? null);
        $ukSports = (bool) ($filters['uk_sports'] ?? false);
        $search = trim((string) ($filters['search'] ?? ''));
        $channelLimit = max(25, min(1000, (int) ($filters['channel_limit'] ?? 400)));

        $query = $this->programmeQuery($day, $end, $region, $ukSports, $search);
        $channelIds = (clone $query)
            ->select('canonical_channel_id')
            ->distinct()
            ->orderBy('canonical_channel_id')
            ->limit($channelLimit + 1)
            ->pluck('canonical_channel_id')
            ->filter()
            ->values()
            ->all();

        $truncated = count($channelIds) > $channelLimit;
        $channelIds = array_slice($channelIds, 0, $channelLimit);
        $aliases = $this->aliases($channelIds, $region);
        $priorities = SportsBotEpgSource::query()->pluck('priority', 'id');

        $rows = $channelIds === []
            ? collect()
            : (clone $query)
                ->whereIn('canonical_channel_id', $channelIds)
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
                ->orderBy('canonical_channel_id')
                ->orderBy('start_time')
                ->orderBy('id')
                ->get();

        $groups = [];
        foreach ($rows as $row) {
            $canonicalId = trim((string) $row->canonical_channel_id);
            $start = $this->dateString($row->start_time ?? null);
            if ($canonicalId === '' || $start === null) {
                continue;
            }

            $programme = [
                'id' => (int) $row->id,
                'canonical_channel_id' => $canonicalId,
                'channel' => (string) $row->channel,
                'title' => (string) $row->title,
                'description' => (string) ($row->description ?? ''),
                'start_time' => $start,
                'end_time' => $this->dateString($row->end_time ?? null),
                'fixture_id' => $row->fixture_id !== null ? (int) $row->fixture_id : null,
                'confidence' => (float) ($row->confidence ?? 0),
                'source_url' => (string) ($row->source_url ?? ''),
            ];
            $sourceKey = $row->source_id !== null
                ? 'source:' . (string) $row->source_id
                : 'url:' . (string) ($row->source_url ?: $row->id);
            $score = $this->representativeScore($row, (int) ($priorities[(int) ($row->source_id ?? 0)] ?? 100));
            $key = $this->dedupeKey($programme);

            $groups[$canonicalId] ??= [];
            if (! isset($groups[$canonicalId][$key])) {
                $groups[$canonicalId][$key] = [
                    'row' => $programme,
                    'score' => $score,
                    'source_keys' => [$sourceKey],
                    'source_urls' => array_filter([$programme['source_url']]),
                    'raw_count' => 1,
                ];
                continue;
            }

            $group = &$groups[$canonicalId][$key];
            $group['raw_count']++;
            if (! in_array($sourceKey, $group['source_keys'], true)) {
                $group['source_keys'][] = $sourceKey;
            }
            if ($programme['source_url'] !== '' && ! in_array($programme['source_url'], $group['source_urls'], true)) {
                $group['source_urls'][] = $programme['source_url'];
            }

            if ($score > $group['score']) {
                $group['row'] = $programme;
                $group['score'] = $score;
            } elseif ($group['row']['description'] === '' && $programme['description'] !== '') {
                $group['row']['description'] = $programme['description'];
            }
            unset($group);
        }

        $channels = [];
        $programmeCount = 0;
        foreach ($groups as $canonicalId => $programmes) {
            $alias = $aliases->get($canonicalId);
            $items = [];
            $fallbackName = '';

            foreach ($programmes as $programme) {
                $item = $programme['row'];
                $fallbackName = $fallbackName ?: $item['channel'];
                $item['source_count'] = count($programme['source_keys']);
                $item['source_urls'] = array_values($programme['source_urls']);
                $item['raw_count'] = (int) $programme['raw_count'];
                $items[] = $item;
            }

            usort($items, fn (array $left, array $right): int => [$left['start_time'], $left['title']] <=> [$right['start_time'], $right['title']]);
            $programmeCount += count($items);
            $channels[] = [
                'canonical_channel_id' => $canonicalId,
                'name' => trim((string) ($alias?->display_name ?: $fallbackName ?: $this->canonicalName($canonicalId))),
                'alias' => (string) ($alias?->alias ?? $fallbackName),
                'region' => $alias?->region ?: $region,
                'logo_url' => trim((string) ($alias?->logo_url ?? '')) ?: null,
                'programme_count' => count($items),
                'programmes' => $items,
            ];
        }

        usort($channels, fn (array $left, array $right): int => [$left['name'], $left['canonical_channel_id']] <=> [$right['name'], $right['canonical_channel_id']]);

        return [
            'date' => $day->toDateString(),
            'timezone' => (string) config('app.timezone', 'UTC'),
            'window' => [
                'start' => $day->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
            'filters' => [
                'region' => $region,
                'uk_sports' => $ukSports,
                'search' => $search,
                'channel_limit' => $channelLimit,
            ],
            'regions' => $this->regions(),
            'truncated' => $truncated,
            'channel_count' => count($channels),
            'programme_count' => $programmeCount,
            'raw_programme_count' => $rows->count(),
            'channels' => $channels,
        ];
    }

    private function programmeQuery(Carbon $day, Carbon $end, ?string $region, bool $ukSports, string $search): Builder
    {
        $query = SportsBotXmltvProgramme::query()
            ->whereNotNull('canonical_channel_id')
            ->where('start_time', '>=', $day->copy()->subHours(12))
            ->where('start_time', '<', $end)
            ->where(function (Builder $query) use ($day): void {
                $query->where(function (Builder $query) use ($day): void {
                    $query->whereNull('end_time')
                        ->where('start_time', '>=', $day);
                })->orWhere('end_time', '>', $day);
            });

        if ($region !== null) {
            $sourceIds = SportsBotEpgSource::query()
                ->whereIn('region', $region === 'UK' ? ['UK', 'GB'] : [$region])
                ->pluck('id')
                ->all();

            $sourceIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('source_id', $sourceIds);
        }

        if ($ukSports) {
            $query->whereIn('canonical_channel_id', $this->ukSportsChannels);
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function (Builder $query) use ($like): void {
                $query->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('channel', 'like', $like)
                    ->orWhere('canonical_channel_id', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param array<int, string> $channelIds
     * @return Collection<string, SportsBotEpgChannelAlias>
     */
    private function aliases(array $channelIds, ?string $region): Collection
    {
        if ($channelIds === []) {
            return collect();
        }

        $aliases = SportsBotEpgChannelAlias::query()
            ->whereIn('canonical_channel_id', $channelIds)
            ->where('accepted', true)
            ->when($region !== null, function (Builder $query) use ($region): void {
                $query->where(function (Builder $query) use ($region): void {
                    $query->whereNull('region')
                        ->orWhereIn('region', $region === 'UK' ? ['UK', 'GB'] : [$region]);
                });
            })
            ->orderByDesc('confidence')
            ->get();

        return $aliases
            ->groupBy('canonical_channel_id')
            ->map(function (Collection $items) use ($region): SportsBotEpgChannelAlias {
                if ($region !== null) {
                    $preferred = $items->first(fn (SportsBotEpgChannelAlias $alias): bool => in_array((string) $alias->region, $region === 'UK' ? ['UK', 'GB'] : [$region], true));
                    if ($preferred instanceof SportsBotEpgChannelAlias) {
                        return $preferred;
                    }
                }

                return $items->first();
            });
    }

    /**
     * @return array<int, string>
     */
    private function regions(): array
    {
        $regions = SportsBotEpgSource::query()
            ->whereNotNull('region')
            ->distinct()
            ->orderBy('region')
            ->pluck('region')
            ->map(fn (mixed $region): string => strtoupper(trim((string) $region)))
            ->filter()
            ->values()
            ->all();

        if (! in_array('UK', $regions, true)) {
            array_unshift($regions, 'UK');
        }

        return array_values(array_unique($regions));
    }

    private function region(mixed $value): ?string
    {
        $region = strtoupper(trim((string) $value));

        return $region === '' || $region === 'ALL' ? null : $region;
    }

    private function dedupeKey(array $programme): string
    {
        $title = $this->programmeSignature((string) ($programme['title'] ?? ''));
        if ($title === '') {
            $title = 'programme:' . (string) ($programme['id'] ?? '');
        }

        return implode('|', [
            (string) ($programme['canonical_channel_id'] ?? ''),
            substr((string) ($programme['start_time'] ?? ''), 0, 16),
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

    private function representativeScore(object $programme, int $priority): int
    {
        $score = (int) round(((float) ($programme->confidence ?? 0)) * 100);
        $score += $programme->fixture_id ? 120 : 0;
        $score += trim((string) ($programme->description ?? '')) !== '' ? 25 : 0;

        return $score + max(0, 100 - min(100, $priority));
    }

    private function canonicalName(string $canonicalId): string
    {
        return ucwords(str_replace('_', ' ', $canonicalId));
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
