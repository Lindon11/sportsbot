<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotEpgChannelAlias;

class SportsBotEpgChannelNormalizer
{
    /**
     * @var array<string, string>
     */
    private array $knownChannels = [
        'skysportsmainevent' => 'sky_sports_main_event',
        'skysportsmaineventuk' => 'sky_sports_main_event',
        'skysportsmain' => 'sky_sports_main_event',
        'skysportsmainhd' => 'sky_sports_main_event',
        'skyspmainevent' => 'sky_sports_main_event',
        'skyspmain' => 'sky_sports_main_event',
        'skysportspremierleague' => 'sky_sports_premier_league',
        'skysportsfootball' => 'sky_sports_football',
        'skysportsaction' => 'sky_sports_action',
        'skysportsarena' => 'sky_sports_arena',
        'skysportscricket' => 'sky_sports_cricket',
        'skysportsgolf' => 'sky_sports_golf',
        'skysportsf1' => 'sky_sports_f1',
        'skysportsformula1' => 'sky_sports_f1',
        'skysportsracing' => 'sky_sports_racing',
        'skysportstennis' => 'sky_sports_tennis',
        'skysportsmix' => 'sky_sports_mix',
        'skysportsnews' => 'sky_sports_news',
        'tntsports1' => 'tnt_sports_1',
        'tntsports2' => 'tnt_sports_2',
        'tntsports3' => 'tnt_sports_3',
        'tntsports4' => 'tnt_sports_4',
        'btsport1' => 'tnt_sports_1',
        'btsport2' => 'tnt_sports_2',
        'btsport3' => 'tnt_sports_3',
        'btsport4' => 'tnt_sports_4',
        'eurosport1' => 'eurosport_1',
        'eurosport2' => 'eurosport_2',
        'premiersports1' => 'premier_sports_1',
        'premiersports2' => 'premier_sports_2',
        'bbc1' => 'bbc_one',
        'bbcone' => 'bbc_one',
        'bbconeuk' => 'bbc_one',
        'bbc2' => 'bbc_two',
        'bbctwo' => 'bbc_two',
        'itv1' => 'itv_1',
        'itv' => 'itv_1',
        'channel4' => 'channel_4',
        'c4' => 'channel_4',
        'channel5' => 'channel_5',
        'five' => 'channel_5',
    ];

    public function canonicalIdFor(string $channel, ?string $region = null): string
    {
        $normalized = $this->normalizeChannel($channel);
        if ($normalized === '') {
            return '';
        }

        $alias = SportsBotEpgChannelAlias::query()
            ->where('normalized_alias', $normalized)
            ->where('accepted', true)
            ->where(function ($query) use ($region): void {
                $query->whereNull('region');
                if ($region !== null && $region !== '') {
                    $query->orWhere('region', $region);
                }
            })
            ->orderByDesc('region')
            ->first();

        return (string) ($alias?->canonical_channel_id ?: $normalized);
    }

    public function normalizeChannel(string $channel): string
    {
        $value = strtolower(html_entity_decode(strip_tags($channel), ENT_QUOTES | ENT_HTML5));
        $value = preg_replace('/\[[^\]]+\]|\([^\)]+\)/', ' ', $value) ?? $value;
        $value = str_replace(['&', '+'], [' and ', ' plus '], $value);
        $value = preg_replace('/\b(uk|gb|ie|hd|uhd|fhd|sd|live|tv|channel)\b/', ' ', $value) ?? $value;
        $value = preg_replace('/\.(uk|gb|ie)$/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if ($value === '') {
            return '';
        }

        $compact = str_replace(' ', '', $value);
        if (isset($this->knownChannels[$compact])) {
            return $this->knownChannels[$compact];
        }

        return trim(str_replace(' ', '_', $value), '_');
    }

    public function displayNameForCanonical(string $canonicalChannelId, string $fallback = ''): string
    {
        $alias = SportsBotEpgChannelAlias::query()
            ->where('canonical_channel_id', $canonicalChannelId)
            ->where('accepted', true)
            ->whereNotNull('display_name')
            ->orderByDesc('confidence')
            ->first();

        if ($alias?->display_name) {
            return (string) $alias->display_name;
        }

        if (trim($fallback) !== '') {
            return trim($fallback);
        }

        return ucwords(str_replace('_', ' ', $canonicalChannelId));
    }

    public function rememberAlias(
        string $alias,
        string $canonicalChannelId,
        ?string $region = null,
        string $source = 'system',
        ?string $displayName = null,
        float $confidence = 1.0,
        ?string $logoUrl = null,
    ): SportsBotEpgChannelAlias
    {
        $normalized = $this->normalizeChannel($alias);
        $values = [
            'canonical_channel_id' => $canonicalChannelId,
            'alias' => $alias,
            'display_name' => $displayName ?: $alias,
            'source' => $source,
            'confidence' => max(0, min(1, $confidence)),
            'accepted' => true,
        ];

        if (trim((string) $logoUrl) !== '') {
            $values['logo_url'] = trim((string) $logoUrl);
        }

        return SportsBotEpgChannelAlias::query()->updateOrCreate(
            [
                'normalized_alias' => $normalized,
                'region' => $region,
            ],
            $values
        );
    }
}
