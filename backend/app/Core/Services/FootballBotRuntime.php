<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use SQLite3;
use Throwable;

class FootballBotRuntime
{
    private bool $booted = false;

    public function __construct(private readonly ?string $runtimePath = null)
    {
    }

    public function runLiveCheck(bool $dryRun = false): array
    {
        $this->boot();

        try {
            return fb_run_live_check(fb_config(true), $dryRun);
        } catch (Throwable $error) {
            $this->logFailure('footballbot.live_check.failed', $error);
            throw $error;
        }
    }

    public function processTelegramWebhook(array $update, ?string $providedSecret): array
    {
        $this->boot();

        $config = fb_config(true);

        if (empty($config['telegram']['webhook_enabled'])) {
            throw new RuntimeException('Webhook mode is disabled.');
        }

        $expectedSecret = trim((string) ($config['telegram']['webhook_secret_token'] ?? ''));
        if ($expectedSecret !== '' && !hash_equals($expectedSecret, trim((string) $providedSecret))) {
            throw new RuntimeException('Invalid webhook secret token.');
        }

        try {
            /** @var SQLite3 $db */
            $db = fb_open_db($config);
            return fb_process_telegram_webhook_update($config, $db, $update);
        } catch (Throwable $error) {
            $this->logFailure('footballbot.webhook.failed', $error);
            throw $error;
        }
    }

    public function health(): array
    {
        $this->boot();

        $config = fb_config(true);
        $requiredExtensions = ['curl', 'gd', 'json', 'sqlite3'];
        $missingExtensions = array_values(array_filter(
            $requiredExtensions,
            static fn (string $extension): bool => !extension_loaded($extension)
        ));

        $missingEnv = [];
        foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'THESPORTSDB_API_KEY'] as $name) {
            if (empty(getenv($name))) {
                $missingEnv[] = $name;
            }
        }

        return [
            'ok' => $missingExtensions === [] && $missingEnv === [],
            'runtime_path' => $this->path(),
            'missing_extensions' => $missingExtensions,
            'missing_env' => $missingEnv,
            'state_db' => $config['paths']['state_db'] ?? null,
            'log_file' => $config['app']['log_file'] ?? null,
            'webhook_enabled' => (bool) ($config['telegram']['webhook_enabled'] ?? false),
        ];
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $path = $this->path();
        $runner = $path . '/runner.php';

        if (!is_file($runner)) {
            throw new RuntimeException("Football bot runtime not found at {$runner}");
        }

        require_once $runner;

        $this->booted = true;
    }

    private function path(): string
    {
        return rtrim($this->runtimePath ?: (string) config('footballbot.runtime_path'), '/');
    }

    private function logFailure(string $message, Throwable $error): void
    {
        Log::error($message, [
            'error' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ]);
    }
}
