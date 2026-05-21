<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

class SportsBotEpgImportCommand extends Command
{
    protected $signature = 'sportsbot:epg-import
        {--url= : XMLTV feed URL (overrides config)}
        {--match : Run fixture matching after import}
        {--days=3 : Number of days of EPG data to keep}';

    protected $description = 'Download XMLTV EPG feed and import TV programme data';

    public function handle(): int
    {
        $url = $this->option('url') ?: config('plugins.SportsBot.epg.feed_url', '');
        if ($url === '') {
            $this->error('No EPG feed URL configured');
            return Command::FAILURE;
        }

        $days = max(1, min(14, (int) $this->option('days')));

        $this->info("Downloading EPG feed: {$url}");
        $response = Http::timeout(60)
            ->withHeaders(['User-Agent' => 'SportsBot/1.0'])
            ->get($url);

        if (!$response->successful()) {
            $this->error("Failed to download feed: HTTP {$response->status()}");
            return Command::FAILURE;
        }

        $xml = $this->decompress($response->body());
        if ($xml === null) {
            $this->error('Failed to decompress feed');
            return Command::FAILURE;
        }

        $this->info('Parsing XMLTV data...');
        $programmes = $this->parseXml($xml);
        if ($programmes === []) {
            $this->warn('No programmes found in feed');
            return Command::SUCCESS;
        }

        $this->info("Found {$programmes['total']} programmes, importing...");

        $cutoff = now()->subDays(1);
        SportsBotXmltvProgramme::where('created_at', '<', $cutoff)->delete();

        $imported = 0;
        foreach (array_chunk($programmes['rows'], 500) as $chunk) {
            SportsBotXmltvProgramme::upsert(
                $chunk,
                ['channel', 'title', 'start_time'],
                ['description', 'end_time', 'raw_data', 'updated_at']
            );
            $imported += count($chunk);
        }

        $this->info("Imported {$imported} programmes");

        if ($this->option('match')) {
            $this->matchFixtures();
        }

        return Command::SUCCESS;
    }

    private function decompress(string $body): ?string
    {
        if (substr($body, 0, 3) === "\x1f\x8b\x08") {
            $decompressed = gzdecode($body);
            return $decompressed !== false ? $decompressed : null;
        }

        if (str_starts_with($body, '<?xml') || str_starts_with($body, '<tv')) {
            return $body;
        }

        return null;
    }

    private function parseXml(string $xml): ?array
    {
        $element = simplexml_load_string($xml);
        if (!$element instanceof SimpleXMLElement) {
            return null;
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
        $endCutoff = now()->addDays((int) $this->option('days'));

        foreach ($element->programme as $prog) {
            $channelId = trim((string) $prog['channel']);
            $channel = $channelMap[$channelId] ?? $channelId;

            $startTime = $this->parseXmltvTime(trim((string) $prog['start']));
            $endTime = $this->parseXmltvTime(trim((string) $prog['stop']));

            if ($startTime === null || $startTime < $startCutoff || $startTime > $endCutoff) {
                continue;
            }

            $title = trim((string) $prog->title);
            $desc = trim((string) $prog->desc);

            if ($channel === '' || $title === '') {
                continue;
            }

            $rows[] = [
                'channel' => $channel,
                'title' => $title,
                'description' => $desc,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'raw_data' => [
                    'channel_id' => $channelId,
                    'category' => trim((string) $prog->category),
                    'sub_title' => trim((string) $prog->{'sub-title'}),
                ],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return [
            'total' => count($rows),
            'rows' => $rows,
        ];
    }

    private function parseXmltvTime(string $time): ?Carbon
    {
        if ($time === '') {
            return null;
        }

        try {
            $time = preg_replace('/\s*[+-]\d{4}\s*$/', '', $time);
            return Carbon::parse($time);
        } catch (Throwable) {
            return null;
        }
    }

    private function matchFixtures(): void
    {
        $this->info('Matching fixtures to EPG programmes...');

        $fixtures = SportsBotFixtureQueue::query()
            ->whereIn('status', [SportsBotFixtureQueue::STATUS_DRAFT, SportsBotFixtureQueue::STATUS_READY])
            ->whereBetween('publish_date', [
                Carbon::today()->subDay()->toDateString(),
                Carbon::today()->addDays(2)->toDateString(),
            ])
            ->get();

        $matched = 0;
        foreach ($fixtures as $fixture) {
            $data = (array) ($fixture->fixture_data ?? []);
            $home = trim((string) ($data['home_team'] ?? ''));
            $away = trim((string) ($data['away_team'] ?? ''));
            $eventName = trim((string) ($data['event_name'] ?? ''));

            if ($home === '' && $away === '' && $eventName === '') {
                continue;
            }

            $programme = $this->findProgramme($fixture, $home, $away, $eventName);
            if ($programme === null) {
                continue;
            }

            $fixtureData = $data;
            $fixtureData['tv_channel'] = $programme->channel;
            $fixtureData['epg_programme_id'] = $programme->id;

            $fixture->fixture_data = $fixtureData;
            $fixture->save();

            $programme->fixture_id = $fixture->id;
            $programme->save();

            $matched++;
            $this->line("#{$fixture->id} {$fixture->event_name} -> {$programme->channel}");
        }

        $this->info("Matched {$matched} fixtures to EPG programmes");
    }

    private function findProgramme(SportsBotFixtureQueue $fixture, string $home, string $away, string $eventName): ?SportsBotXmltvProgramme
    {
        $publishDate = $fixture->publish_date;
        $windowStart = Carbon::parse($publishDate->toDateString())->subHours(6);
        $windowEnd = Carbon::parse($publishDate->toDateString())->addHours(30);

        $homeVariants = $this->nameVariants($home);
        $awayVariants = $this->nameVariants($away);
        $eventVariants = $this->nameVariants($eventName);

        $programmes = SportsBotXmltvProgramme::query()
            ->whereNull('fixture_id')
            ->where('start_time', '>=', $windowStart)
            ->where('start_time', '<=', $windowEnd)
            ->orderBy('start_time')
            ->get();

        $best = null;
        $bestScore = 0;

        foreach ($programmes as $prog) {
            $text = strtolower($prog->title . ' ' . ($prog->description ?? ''));
            $score = 0;

            $hasHome = $this->textContainsAny($text, $homeVariants);
            $hasAway = $this->textContainsAny($text, $awayVariants);

            if ($hasHome && $hasAway) {
                $score = 0.9;
            } elseif ($hasHome || $hasAway) {
                $score = 0.5;
            } elseif ($eventName !== '' && $this->textContainsAny($text, $eventVariants)) {
                $score = 0.4;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $prog;
            }
        }

        return $bestScore >= 0.5 ? $best : null;
    }

    private function textContainsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function nameVariants(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $lower = strtolower($name);
        $variants = [$lower];

        $noBrackets = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $lower) ?? $lower);
        if ($noBrackets !== $lower && $noBrackets !== '') {
            $variants[] = $noBrackets;
        }

        $short = trim(preg_replace('/\b(fc|afc|cf|club|the|united|city|athletic)\b/', '', $noBrackets) ?? '');
        $short = trim(preg_replace('/\s+/', ' ', $short) ?? $short);
        if ($short !== '' && $short !== $noBrackets) {
            $variants[] = $short;
        }

        return array_values(array_unique($variants));
    }
}
