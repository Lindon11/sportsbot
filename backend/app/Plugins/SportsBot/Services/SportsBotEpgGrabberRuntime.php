<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotEpgGrabber;
use App\Plugins\SportsBot\Models\SportsBotEpgGrabberOutput;
use App\Plugins\SportsBot\Models\SportsBotEpgGrabberRun;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

class SportsBotEpgGrabberRuntime
{
    public function __construct(
        private readonly SportsBotEpgSourceImporter $importer = new SportsBotEpgSourceImporter(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function discover(?string $region = 'UK'): array
    {
        $region = $region !== null && $region !== '' ? strtoupper($region) : null;
        $this->importer->seedConfiguredSources();
        $feedGrabbers = $this->discoverPublicXmltvFeeds($region);
        $xmltvGrabbers = $this->discoverXmltvCommands($region);
        $iptvOrg = $this->discoverIptvOrg($region);
        $webgrab = $this->discoverWebGrabPlus($region);

        return [
            'public_xmltv_feed' => $feedGrabbers,
            'xmltv_command' => $xmltvGrabbers,
            'iptv_org_epg' => $iptvOrg,
            'webgrabplus_command' => $webgrab,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?string $region = 'UK', ?string $only = null, bool $import = true, bool $export = false, array $importOptions = []): array
    {
        $region = $region !== null && $region !== '' ? strtoupper($region) : null;
        $this->discover($region);

        $query = SportsBotEpgGrabber::query()
            ->where('enabled', true)
            ->orderBy('type')
            ->orderBy('name');

        if ($region !== null) {
            $query->where(function ($query) use ($region): void {
                $query->whereNull('region')->orWhere('region', $region);
            });
        }

        if ($only !== null && trim($only) !== '') {
            $needle = trim($only);
            $query->where(function ($query) use ($needle): void {
                $query->where('type', $needle)->orWhere('name', $needle);
            });
        }

        $results = [];
        foreach ($query->get() as $grabber) {
            $results[] = $this->runGrabber($grabber, $import, $importOptions);
        }

        $successfulImports = ['working', 'stale', 'empty', 'skipped_unchanged'];
        $hasSuccessfulImport = collect($results)->contains(fn (array $result): bool => in_array(
            (string) data_get($result, 'import.status'),
            $successfulImports,
            true,
        ));

        $exportResult = null;
        if ($export && $hasSuccessfulImport) {
            $exportResult = app(SportsBotEpgExporter::class)->writeCachedExports();
        }

        return [
            'region' => $region,
            'run_count' => count($results),
            'runs' => $results,
            'export' => $exportResult,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrapIptvOrg(): array
    {
        $path = $this->iptvOrgPath();
        if (is_dir($path . '/.git')) {
            return ['bootstrapped' => true, 'already_exists' => true, 'path' => $path];
        }

        File::ensureDirectoryExists(dirname($path));
        $process = new Process(['git', 'clone', '--depth', '1', 'https://github.com/iptv-org/epg.git', $path]);
        $process->setTimeout(600);
        $process->run();

        return [
            'bootstrapped' => $process->isSuccessful(),
            'path' => $path,
            'exit_code' => $process->getExitCode(),
            'output' => mb_substr($process->getOutput(), 0, 2000),
            'error' => mb_substr($process->getErrorOutput(), 0, 2000),
        ];
    }

    public function applyUkSportsPolicy(): array
    {
        return $this->importer->applyUkSportsPolicy();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function missingUkSportsChannels(): array
    {
        $expected = [
            'sky_sports_main_event' => 'Sky Sports Main Event',
            'sky_sports_premier_league' => 'Sky Sports Premier League',
            'sky_sports_football' => 'Sky Sports Football',
            'sky_sports_action' => 'Sky Sports Action',
            'sky_sports_arena' => 'Sky Sports Arena',
            'sky_sports_cricket' => 'Sky Sports Cricket',
            'sky_sports_f1' => 'Sky Sports F1',
            'tnt_sports_1' => 'TNT Sports 1',
            'tnt_sports_2' => 'TNT Sports 2',
            'tnt_sports_3' => 'TNT Sports 3',
            'tnt_sports_4' => 'TNT Sports 4',
            'premier_sports_1' => 'Premier Sports 1',
            'premier_sports_2' => 'Premier Sports 2',
            'bbc_one' => 'BBC One',
            'bbc_two' => 'BBC Two',
            'itv_1' => 'ITV1',
            'channel_4' => 'Channel 4',
        ];

        $present = \App\Plugins\SportsBot\Models\SportsBotXmltvProgramme::query()
            ->where('start_time', '>=', now())
            ->whereIn('canonical_channel_id', array_keys($expected))
            ->selectRaw('canonical_channel_id, count(*) as total')
            ->groupBy('canonical_channel_id')
            ->pluck('total', 'canonical_channel_id')
            ->all();

        $missing = [];
        foreach ($expected as $id => $name) {
            if ((int) ($present[$id] ?? 0) === 0) {
                $missing[] = ['canonical_channel_id' => $id, 'name' => $name];
            }
        }

        return $missing;
    }

    private function runGrabber(SportsBotEpgGrabber $grabber, bool $import, array $importOptions): array
    {
        $started = microtime(true);
        $startedAt = now();
        $status = 'failed';
        $error = null;
        $outputPath = null;
        $outputBytes = 0;
        $metadata = [];

        try {
            $result = match ($grabber->type) {
                'public_xmltv_feed' => $this->runPublicFeedGrabber($grabber, $import, $importOptions),
                'xmltv_command', 'webgrabplus_command', 'iptv_org_epg' => $this->runCommandGrabber($grabber),
                default => throw new \RuntimeException("Unsupported EPG grabber type: {$grabber->type}"),
            };

            $status = (string) ($result['status'] ?? 'success');
            $outputPath = $result['output_path'] ?? null;
            $outputBytes = (int) ($result['output_bytes'] ?? 0);
            $metadata = $result;

            if ($import && $outputPath !== null && is_file((string) $outputPath)) {
                $metadata['import'] = $this->importOutputFile($grabber, (string) $outputPath, $importOptions);
            }
        } catch (Throwable $caught) {
            $error = $caught->getMessage();
        }

        $finishedAt = now();
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $success = $error === null && in_array($status, ['success', 'working', 'stale', 'empty', 'skipped_unchanged'], true);

        $run = SportsBotEpgGrabberRun::query()->create([
            'grabber_id' => $grabber->id,
            'type' => $grabber->type,
            'region' => $grabber->region,
            'status' => $success ? $status : 'failed',
            'duration_ms' => $durationMs,
            'output_bytes' => $outputBytes,
            'output_path' => $outputPath,
            'error' => $error,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'metadata' => $metadata,
        ]);

        if ($outputPath !== null && is_file((string) $outputPath)) {
            SportsBotEpgGrabberOutput::query()->create([
                'grabber_id' => $grabber->id,
                'run_id' => $run->id,
                'region' => $grabber->region,
                'path' => (string) $outputPath,
                'source_url' => 'file://' . $outputPath,
                'bytes' => $outputBytes,
                'content_hash' => hash_file('sha256', (string) $outputPath) ?: null,
                'generated_at' => now(),
                'metadata' => $metadata,
            ]);
        }

        $grabber->fill([
            'status' => $success ? $status : 'failed',
            'installed' => $grabber->installed,
            'last_run_at' => $finishedAt,
            'last_success_at' => $success ? $finishedAt : $grabber->last_success_at,
            'last_failure_at' => $success ? $grabber->last_failure_at : $finishedAt,
            'last_error' => $error,
            'output_path' => $outputPath ?: $grabber->output_path,
            'metadata' => array_merge((array) ($grabber->metadata ?? []), ['last_run' => $metadata]),
        ])->save();

        return [
            'grabber_id' => $grabber->id,
            'name' => $grabber->name,
            'type' => $grabber->type,
            'status' => $success ? $status : 'failed',
            'duration_ms' => $durationMs,
            'output_path' => $outputPath,
            'output_bytes' => $outputBytes,
            'error' => $error,
            'import' => $metadata['import'] ?? null,
        ];
    }

    private function runPublicFeedGrabber(SportsBotEpgGrabber $grabber, bool $import, array $importOptions): array
    {
        $url = (string) ($grabber->command ?? '');
        $source = SportsBotEpgSource::query()->firstOrCreate(
            ['url' => $url],
            [
                'name' => $grabber->name,
                'type' => 'xmltv',
                'region' => $grabber->region,
                'priority' => 10,
                'enabled' => true,
                'status' => 'unchecked',
            ]
        );

        $importResult = $import ? $this->importer->importSource($source, 3, $importOptions) : null;

        return [
            'status' => (string) ($importResult['status'] ?? 'success'),
            'output_path' => null,
            'output_bytes' => 0,
            'import' => $importResult,
        ];
    }

    private function runCommandGrabber(SportsBotEpgGrabber $grabber): array
    {
        if (! $grabber->installed || trim((string) $grabber->command) === '') {
            throw new \RuntimeException('Grabber is not installed or has no command configured');
        }

        File::ensureDirectoryExists($this->outputDir());
        $outputPath = $this->outputDir() . '/' . $this->safeFileName($grabber->type . '-' . $grabber->name) . '-' . now()->format('Ymd-His') . '.xml';
        $args = (array) ($grabber->arguments ?? []);
        $command = $this->commandFor($grabber, $outputPath, $args);

        $process = new Process($command, $grabber->working_directory ?: null);
        $process->setTimeout((int) config('plugins.SportsBot.epg.grabbers.external_timeout', 900));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Grabber command failed');
        }

        if (! is_file($outputPath) || (filesize($outputPath) ?: 0) === 0) {
            throw new \RuntimeException('Grabber produced no XMLTV output');
        }

        return [
            'status' => 'success',
            'output_path' => $outputPath,
            'output_bytes' => filesize($outputPath) ?: 0,
            'stdout' => mb_substr($process->getOutput(), 0, 2000),
            'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
        ];
    }

    private function importOutputFile(SportsBotEpgGrabber $grabber, string $outputPath, array $importOptions): array
    {
        $source = SportsBotEpgSource::query()->updateOrCreate(
            ['url' => 'file://' . $outputPath],
            [
                'name' => $grabber->name . ' output',
                'type' => 'grabber_output',
                'region' => $grabber->region,
                'priority' => 5,
                'enabled' => false,
                'status' => 'unchecked',
            ]
        );

        return $this->importer->importSource($source, 3, $importOptions);
    }

    /**
     * @return array<int, mixed>
     */
    private function commandFor(SportsBotEpgGrabber $grabber, string $outputPath, array $args): array
    {
        if ($grabber->type === 'iptv_org_epg') {
            $sites = trim((string) ($args['sites'] ?? config('plugins.SportsBot.epg.grabbers.iptv_org_sites', '')));
            $command = ['npm', 'run', 'grab', '--', '--output=' . $outputPath];
            if ($sites !== '') {
                $command[] = '--sites=' . $sites;
            }
            return $command;
        }

        $command = [(string) $grabber->command, '--output', $outputPath];
        foreach ($args as $key => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            $command[] = is_string($key) ? '--' . $key : (string) $value;
            if (is_string($key) && $value !== true) {
                $command[] = (string) $value;
            }
        }

        return $command;
    }

    private function discoverPublicXmltvFeeds(?string $region): array
    {
        $created = 0;
        $sources = SportsBotEpgSource::query()
            ->where('enabled', true)
            ->when($region !== null, fn ($query) => $query->where(function ($query) use ($region): void {
                $query->whereNull('region')->orWhere('region', $region);
            }))
            ->get();

        foreach ($sources as $source) {
            $grabber = SportsBotEpgGrabber::query()->updateOrCreate(
                ['type' => 'public_xmltv_feed', 'name' => (string) ($source->name ?: $source->url), 'region' => $source->region],
                [
                    'command' => $source->url,
                    'enabled' => true,
                    'installed' => true,
                    'status' => 'available',
                    'metadata' => ['source_id' => $source->id],
                ]
            );
            if ($grabber->wasRecentlyCreated) {
                $created++;
            }
        }

        return ['found' => $sources->count(), 'created' => $created];
    }

    private function discoverXmltvCommands(?string $region): array
    {
        $commands = $this->commandsMatching('/^tv_grab_/', $region);
        foreach ($commands as $command) {
            SportsBotEpgGrabber::query()->updateOrCreate(
                ['type' => 'xmltv_command', 'name' => basename($command), 'region' => $region],
                [
                    'command' => $command,
                    'enabled' => false,
                    'installed' => true,
                    'status' => 'available',
                ]
            );
        }

        return ['found' => count($commands), 'commands' => array_map('basename', $commands)];
    }

    private function discoverIptvOrg(?string $region): array
    {
        $path = $this->iptvOrgPath();
        $installed = is_file($path . '/package.json');
        SportsBotEpgGrabber::query()->updateOrCreate(
            ['type' => 'iptv_org_epg', 'name' => 'iptv-org/epg', 'region' => $region],
            [
                'command' => 'npm',
                'arguments' => ['sites' => config('plugins.SportsBot.epg.grabbers.iptv_org_sites', '')],
                'working_directory' => $path,
                'enabled' => false,
                'installed' => $installed,
                'status' => $installed ? 'available' : 'missing',
                'metadata' => ['path' => $path],
            ]
        );

        return ['installed' => $installed, 'path' => $path];
    }

    private function discoverWebGrabPlus(?string $region): array
    {
        $commands = array_merge(
            $this->commandsMatching('/^WebGrab\+Plus$/', $region),
            $this->commandsMatching('/^webgrabplus$/', $region),
        );
        $command = $commands[0] ?? null;
        SportsBotEpgGrabber::query()->updateOrCreate(
            ['type' => 'webgrabplus_command', 'name' => 'WebGrab+Plus', 'region' => $region],
            [
                'command' => $command,
                'enabled' => false,
                'installed' => $command !== null,
                'status' => $command !== null ? 'available' : 'missing',
            ]
        );

        return ['installed' => $command !== null, 'command' => $command];
    }

    /**
     * @return array<int, string>
     */
    private function commandsMatching(string $pattern, ?string $region): array
    {
        $found = [];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '' || ! @is_dir($dir)) {
                continue;
            }
            $entries = @scandir($dir);
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $file) {
                if (preg_match($pattern, $file) === 1) {
                    $path = $dir . DIRECTORY_SEPARATOR . $file;
                    if (is_executable($path)) {
                        $found[$path] = $path;
                    }
                }
            }
        }

        return array_values($found);
    }

    private function outputDir(): string
    {
        return (string) config('plugins.SportsBot.epg.grabbers.output_path', storage_path('app/sportsbot/epg/grabber-output'));
    }

    private function iptvOrgPath(): string
    {
        return (string) config('plugins.SportsBot.epg.grabbers.iptv_org_path', storage_path('app/sportsbot/epg/tools/iptv-org-epg'));
    }

    private function safeFileName(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), '-') ?: 'grabber';
    }
}
