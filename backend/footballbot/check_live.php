<?php

declare(strict_types=1);

require_once __DIR__ . '/runner.php';

$config = fb_config();
$dryRun = in_array('--dry-run', $argv, true);

try {
    $summary = fb_run_live_check($config, $dryRun);

    if ($dryRun) {
        echo sprintf("Live scores returned: %d\n", $summary['total_live_scores']);
        echo sprintf("Allowed live matches: %d\n", $summary['allowed_matches']);
        echo sprintf("Generated alerts: %d\n", $summary['generated_alerts']);

        foreach ($summary['messages'] as $message) {
            echo $message . PHP_EOL;
        }
    }
} catch (Throwable $error) {
    fb_log('error', 'check_live.php failed', [
        'error' => $error->getMessage(),
        'file' => $error->getFile(),
        'line' => $error->getLine(),
    ]);

    fb_telegram_send_error_alert($config, 'check_live.php failed: ' . $error->getMessage(), [
        'file' => $error->getFile(),
        'line' => $error->getLine(),
    ]);

    if ($dryRun) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
    }

    exit(1);
}
