<?php

declare(strict_types=1);

/**
 * Admin logs view.
 *
 * Expected variables: $botLog, $cronLog
 */
?>
<div class="card span-6" id="logs">
    <h2>Bot Log</h2>
    <pre><?= htmlspecialchars($botLog !== '' ? $botLog : 'No bot log yet.') ?></pre>
</div>

<div class="card span-6">
    <h2>Cron Log</h2>
    <pre><?= htmlspecialchars($cronLog !== '' ? $cronLog : 'No cron log yet.') ?></pre>
</div>