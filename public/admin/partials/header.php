<?php

declare(strict_types=1);

/**
 * Admin header partial.
 *
 * Expected variables: $loggedIn, $csrf
 */
?>
<header>
    <div>
        <h1>Sports Alert Bot</h1>
        <p class="muted">Football alerts, TV listings, testing, and monitoring</p>
    </div>
    <?php if ($loggedIn): ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="secondary" type="submit">Sign Out</button>
        </form>
    <?php endif; ?>
</header>