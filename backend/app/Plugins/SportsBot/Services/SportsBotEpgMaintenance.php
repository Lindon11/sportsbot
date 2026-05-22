<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotEpgGrabberOutput;
use App\Plugins\SportsBot\Models\SportsBotEpgGrabberRun;
use App\Plugins\SportsBot\Models\SportsBotEpgImportRun;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;

class SportsBotEpgMaintenance
{
    /**
     * @return array<string, mixed>
     */
    public function cleanup(?int $outputDays = null, ?int $historyDays = null, ?int $programmeDays = null): array
    {
        $outputDays ??= max(1, (int) config('plugins.SportsBot.epg.retention.output_days', 3));
        $historyDays ??= max(1, (int) config('plugins.SportsBot.epg.retention.history_days', 21));
        $programmeDays ??= max(1, (int) config('plugins.SportsBot.epg.retention.programme_past_days', 2));

        $outputCutoff = now()->subDays($outputDays);
        $historyCutoff = now()->subDays($historyDays);
        $programmeCutoff = now()->subDays($programmeDays);
        $latestOutputIds = SportsBotEpgGrabberOutput::query()
            ->selectRaw('max(id) as id')
            ->whereNotNull('grabber_id')
            ->groupBy('grabber_id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $summary = [
            'output_days' => $outputDays,
            'history_days' => $historyDays,
            'programme_past_days' => $programmeDays,
            'grabber_outputs_deleted' => 0,
            'grabber_output_files_deleted' => 0,
            'grabber_output_bytes_deleted' => 0,
            'local_sources_deleted' => 0,
            'local_programmes_deleted' => 0,
            'past_programmes_deleted' => 0,
            'import_runs_deleted' => 0,
            'grabber_runs_deleted' => 0,
        ];

        SportsBotEpgGrabberOutput::query()
            ->where('generated_at', '<', $outputCutoff)
            ->when($latestOutputIds !== [], fn ($query) => $query->whereNotIn('id', $latestOutputIds))
            ->orderBy('id')
            ->chunkById(100, function ($outputs) use (&$summary): void {
                foreach ($outputs as $output) {
                    $path = (string) $output->path;
                    $sourceUrl = trim((string) ($output->source_url ?: ($path !== '' ? 'file://' . $path : '')));
                    $source = $sourceUrl !== '' ? SportsBotEpgSource::query()->where('url', $sourceUrl)->first() : null;

                    if ($source) {
                        $summary['local_programmes_deleted'] += SportsBotXmltvProgramme::query()
                            ->where('source_id', $source->id)
                            ->delete();
                        $source->delete();
                        $summary['local_sources_deleted']++;
                    }

                    if ($this->deletableOutputPath($path) && is_file($path)) {
                        $summary['grabber_output_bytes_deleted'] += (int) @filesize($path);
                        if (@unlink($path)) {
                            $summary['grabber_output_files_deleted']++;
                        }
                    }

                    $output->delete();
                    $summary['grabber_outputs_deleted']++;
                }
            });

        $summary['past_programmes_deleted'] = SportsBotXmltvProgramme::query()
            ->where('start_time', '<', $programmeCutoff)
            ->delete();
        $summary['import_runs_deleted'] = SportsBotEpgImportRun::query()
            ->where('created_at', '<', $historyCutoff)
            ->delete();
        $summary['grabber_runs_deleted'] = SportsBotEpgGrabberRun::query()
            ->where('created_at', '<', $historyCutoff)
            ->delete();

        return $summary;
    }

    private function deletableOutputPath(string $path): bool
    {
        $root = realpath((string) config(
            'plugins.SportsBot.epg.grabbers.output_path',
            storage_path('app/sportsbot/epg/grabber-output')
        ));
        $realPath = $path !== '' ? realpath($path) : false;

        return $root !== false
            && $realPath !== false
            && is_file($realPath)
            && str_starts_with($realPath . DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
