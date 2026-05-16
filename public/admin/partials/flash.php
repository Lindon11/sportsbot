<?php

declare(strict_types=1);

/**
 * Admin flash messages partial.
 *
 * Expected variables: $flash
 */

?>
<?php if ($flash !== []): ?>
    <div class="flash">
        <?php foreach ($flash as $message): ?>
            <?php $type = htmlspecialchars((string) $message['type']); ?>
            <div class="notice <?= $type ?>">
                <?php if (($message['type'] ?? '') === 'html'): ?>
                    <?= $message['message'] ?>
                <?php else: ?>
                    <?= htmlspecialchars((string) $message['message']) ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>