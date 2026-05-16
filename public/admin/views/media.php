<?php

declare(strict_types=1);

/**
 * Admin media view.
 *
 * Expected variables: $latestImages
 */
?>
<div class="card">
    <h2>Latest Images</h2>
    <?php if ($latestImages === []): ?>
        <p class="muted">No generated images yet.</p>
    <?php else: ?>
        <div class="images">
            <?php foreach ($latestImages as $image): ?>
                <?php $url = admin_public_image_url($image); ?>
                <?php if ($url): ?>
                    <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noreferrer">
                        <img src="<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars(basename($image)) ?>">
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>