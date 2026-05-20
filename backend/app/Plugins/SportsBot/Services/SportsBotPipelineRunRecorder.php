<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotPipelineRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SportsBotPipelineRunRecorder
{
    public function record(string $stage, array $options, callable $callback): mixed
    {
        $run = $this->start($stage, $options);

        try {
            $result = $callback();
            $this->finish($run, $result);

            return $result;
        } catch (Throwable $error) {
            $this->fail($run, $error);

            throw $error;
        }
    }

    public function start(string $stage, array $options = []): ?SportsBotPipelineRun
    {
        if (!$this->available()) {
            return null;
        }

        try {
            return SportsBotPipelineRun::query()->create([
                'stage' => $this->normalizeStage($stage),
                'status' => 'running',
                'options' => $options,
                'counts' => [],
                'started_at' => now(),
            ]);
        } catch (Throwable $error) {
            Log::debug('sportsbot.pipeline_run.start_failed', [
                'stage' => $stage,
                'error' => $error->getMessage(),
            ]);

            return null;
        }
    }

    public function finish(?SportsBotPipelineRun $run, mixed $result, ?string $status = null): void
    {
        if (!$run) {
            return;
        }

        $counts = $this->countsFromResult($result);
        $status ??= $this->statusFromCounts($counts);

        try {
            $run->fill([
                'status' => $status,
                'counts' => $counts,
                'error_summary' => $this->errorSummary($result),
                'finished_at' => now(),
                'duration_ms' => max(0, (int) round($run->started_at?->diffInMilliseconds(now()) ?? 0)),
            ])->save();
        } catch (Throwable $error) {
            Log::debug('sportsbot.pipeline_run.finish_failed', [
                'stage' => $run->stage,
                'error' => $error->getMessage(),
            ]);
        }
    }

    public function fail(?SportsBotPipelineRun $run, Throwable $error): void
    {
        if (!$run) {
            return;
        }

        try {
            $run->fill([
                'status' => 'failed',
                'error_summary' => mb_substr($error->getMessage(), 0, 2000),
                'finished_at' => now(),
                'duration_ms' => max(0, (int) round($run->started_at?->diffInMilliseconds(now()) ?? 0)),
            ])->save();
        } catch (Throwable) {
            // Pipeline telemetry must never break the real stage.
        }
    }

    /**
     * @return array<string, int>
     */
    public function countsFromResult(mixed $result): array
    {
        $counts = [];
        $collect = function (array $row) use (&$counts): void {
            foreach ($row as $key => $value) {
                if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                    $counts[(string) $key] = ($counts[(string) $key] ?? 0) + (int) $value;
                }
            }
        };

        if (is_array($result)) {
            $hasNestedRows = false;
            foreach ($result as $value) {
                if (is_array($value)) {
                    $hasNestedRows = true;
                    $collect($value);
                }
            }

            if (!$hasNestedRows) {
                $collect($result);
            }
        }

        return $counts;
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('sportsbot_pipeline_runs');
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizeStage(string $stage): string
    {
        return in_array($stage, ['prefetch', 'enrich', 'render', 'publish'], true) ? $stage : 'unknown';
    }

    /**
     * @param array<string, int> $counts
     */
    private function statusFromCounts(array $counts): string
    {
        if (($counts['failed'] ?? 0) > 0) {
            return 'failed';
        }

        if (($counts['blocked'] ?? 0) > 0 || ($counts['scraper_error'] ?? 0) > 0) {
            return 'warning';
        }

        return 'success';
    }

    private function errorSummary(mixed $result): ?string
    {
        if (!is_array($result)) {
            return null;
        }

        $errors = [];
        $scan = function (array $row) use (&$errors): void {
            foreach (['error', 'error_summary'] as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $errors[] = $value;
                }
            }
            foreach ((array) ($row['errors'] ?? []) as $error) {
                $errors[] = is_array($error) ? (string) ($error['error'] ?? '') : (string) $error;
            }
        };

        foreach ($result as $value) {
            if (is_array($value)) {
                $scan($value);
            }
        }
        $scan($result);

        $errors = array_values(array_unique(array_filter(array_map('trim', $errors))));

        return $errors === [] ? null : mb_substr(implode(' | ', array_slice($errors, 0, 5)), 0, 2000);
    }
}
