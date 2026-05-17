# Plugin Hook System

The hook system is the primary mechanism for plugins to listen for, modify, and react to game events without directly importing core classes. It provides a decoupled way to extend and customize game behavior.

---

## Table of Contents

1. [Overview](#overview)
2. [Registration API](#registration-api)
3. [Priority](#priority)
4. [Transform vs Side-Effect Hooks](#transform-vs-side-effect-hooks)
5. [Declaring Hooks in plugin.json](#declaring-hooks-in-pluginjson)
6. [Core Hooks Catalogue](#core-hooks-catalogue)
7. [Creating Custom Hooks](#creating-custom-hooks)
8. [Best Practices](#best-practices)
9. [Exception Handling](#exception-handling)
10. [Examples](#examples)

---

## Overview

Hooks are event-driven callbacks that plugins register to respond to specific events in the game. When an event occurs, the system fires the corresponding hook, and all registered listeners execute in priority order.

```
Game Event → Hook System → Registered Listeners → Response
```

The hook system supports:
- **Transform hooks**: Modify data as it passes through
- **Side-effect hooks**: Observe events without modification
- **Priority ordering**: Control execution sequence
- **Exception isolation**: One failing listener doesn't break others

---

## Registration API

Plugins register hook listeners in their `hooks.php` file, which is automatically loaded by `AutoPluginHookLoader` when the plugin is enabled.

### 1. Declarative Format (Recommended for Simple Hooks)

Return an associative array from `hooks.php`. The loader registers each key/value pair automatically.

```php
// app/Plugins/MyPlugin/hooks.php
return [
    'economy.credit' => function (array $data): array {
        $data['amount'] *= 1.10; // +10% bonus
        return $data;
    },
    'OnCrimeCommit' => function (array $data): void {
        \Log::info('Crime committed', ['player' => $data['player']->id]);
    },
];
```

> **Note**: Declarative format does **not** support priority — all declarative listeners run at the default priority (10).

### 2. Side-Effect Format (Direct Registration)

Call `Hook::register()` directly in the body of `hooks.php` for priority control or multiple listeners per hook.

```php
use App\Facades\Hook;

// Register a hook with custom priority
Hook::register('economy.credit', function (array $data): array {
    $data['amount'] *= 1.10;
    return $data;
}, priority: 50);

// Register multiple listeners for the same hook
Hook::register('economy.credit', function (array $data): array {
    $data['amount'] = max(0, $data['amount']); // Safety floor
    return $data;
}, priority: 10);
```

---

## Priority

Each listener has a numeric priority (default: `10`). **Higher numbers run first.**

| Priority | Execution Order |
|----------|-----------------|
| 100 | Runs first |
| 50 | Runs second |
| 10 | Default (runs last among equals) |
| 1 | Runs last |

### How Priority Works

```php
// Listener A - runs first
Hook::register('some.hook', function ($data) {
    \Log::info('A runs first');
    return $data;
}, priority: 100);

// Listener B - runs second
Hook::register('some.hook', function ($data) {
    \Log::info('B runs second');
    return $data;
}, priority: 50);

// Listener C - runs last
Hook::register('some.hook', function ($data) {
    \Log::info('C runs last');
    return $data;
}, priority: 10); // or no priority specified
```

### When to Use Custom Priority

| Priority | Use Case |
|----------|----------|
| **High (50-100)** | Data modifiers that must run before observers |
| **Default (10)** | Most listeners, observers, logging |
| **Low (1-5)** | Final processing after all modifications |

Examples:
- A plugin that **modifies** crime rewards should run before plugins that only **log** crimes → use priority `50` or `100`
- An audit/logging listener should run after all mutations → leave at default priority (`10`)
- Two plugins that both modify the same field must agree on order → assign distinct priority values

### Lazy Sorting

Listeners are sorted the first time a hook fires, then cached for performance. Listeners with equal priority run in registration order.

---

## Transform vs Side-Effect Hooks

### Transform Hook — Listeners Return Modified Data

The return value of each listener replaces the payload for the next listener in the chain.

```php
// Core fires:
$result = GameHooks::fire('economy.credit', ['user' => $user, 'amount' => 500]);

// Listener 1 (priority 100): +10% bonus
$data['amount'] = 550;

// Listener 2 (priority 50): Round to nearest 10
$data['amount'] = 550;

// Listener 3 (priority 10): Apply tax
$data['amount'] = 605; // Final result
```

If a listener throws, the chain is aborted and the **original payload** is returned.

### Side-Effect Hook — Listeners Observe But Do Not Modify

Return `null` or `void` and the payload passes through unchanged.

```php
Hook::register('OnCrimeCommit', function (array $data): void {
    // Just log - doesn't affect the crime result
    \Log::info('Crime committed', [
        'player' => $data['player']->id,
        'crime' => $data['crime'],
    ]);
});
```

Use side-effect hooks for:
- Logging and auditing
- Notifications
- Analytics
- Triggering other systems

---

## Declaring Hooks in plugin.json

List every hook your plugin **fires** or **listens to** in `plugin.json`. This is used by the admin panel and documentation tools.

```json
{
    "hooks": {
        "OnCrimeCommit": true,
        "alterCrimeRewards": true,
        "economy.credit": true,
        "customMenus": true
    }
}
```

---

## Core Hooks Catalogue

### Economy Hooks

| Hook Name | Type | Payload Keys | Description |
|-----------|------|--------------|-------------|
| `economy.credit` | transform | `user`, `amount`, `reason` | Player receives cash |
| `economy.debit` | transform | `user`, `amount`, `reason` | Player spends cash |
| `economy.transfer` | transform | `from`, `to`, `amount` | Transfer between players |

### User Hooks

| Hook Name | Type | Payload Keys | Description |
|-----------|------|--------------|-------------|
| `user.created` | side-effect | `user` | New user registered |
| `user.login` | side-effect | `user`, `ip` | User logged in |
| `user.logout` | side-effect | `user` | User logged out |
| `user.profile.widgets` | transform | `widgets`, `user` | Add widgets to profile |

### Stats Hooks

| Hook Name | Type | Payload Keys | Description |
|-----------|------|--------------|-------------|
| `stats.display` | transform | `stats`, `user` | Modify stats display |
| `player.experience.gained` | transform | `player`, `amount`, `source` | XP awarded |
| `player.level.up` | side-effect | `player`, `new_level` | Player leveled up |

### Crime Hooks

| Hook Name | Type | Payload Keys | Description |
|-----------|------|--------------|-------------|
| `OnCrimeCommit` | side-effect | `player`, `crime`, `result` | Crime attempt resolved |
| `alterCrimeRewards` | transform | `cash`, `experience`, `player` | Modify crime reward values |
| `crime.before` | transform | `player`, `crime` | Before crime attempt |
| `crime.after` | side-effect | `player`, `crime`, `result` | After crime attempt |

### Combat Hooks

| Hook Name | Type | Payload Keys | Description |
|-----------|------|--------------|-------------|
| `afterCombat` | side-effect | `attacker`, `defender`, `result` | PvP/NPC combat resolved |
| `alterCombatTarget` | transform | `target`, `attacker` | Modify combat target data |
| `modifyCombatPower` | transform | `power`, `player` | Modify combat power value |
| `combat.start` | transform | `attacker`, `defender` | Combat initiated |
| `combat.end` | side-effect | `winner`, `loser`, `rewards` | Combat ended |

### Inventory Hooks

| Hook Name | Type | Payload Keys | Description |
|-----------|------|--------------|-------------|
| `inventory.change` | side-effect | `player`, `item`, `change_type` | Inventory add/remove/use |
| `OnItemBought` | side-effect | `player`, `item`, `quantity`, `cost` | Item purchased |
| `OnItemSold` | side-effect | `player`, `item`, `quantity`, `earnings` | Item sold |
| `OnItemEquipped` | side-effect | `player`, `item` | Item equipped |
| `OnItemUnequipped` | side-effect | `player`, `item` | Item unequipped |
| `OnItemUsed` | side-effect | `player`, `item` | Item used |

### UI Hooks

| Hook Name | Type | Payload Keys | Description |
|-----------|------|--------------|-------------|
| `customMenus` | transform | `user` | Plugins contribute sidebar menu sections |
| `navigation.menu` | transform | `items`, `user` | Modify navigation |
| `header.link` | transform | `links`, `user` | Add header links |
| `dashboard.widget` | transform | `widgets`, `user` | Add dashboard widgets |

### Admin Hooks

| Hook Name | Type | Payload Keys | Description |
|-----------|------|--------------|-------------|
| `admin.dashboard.widgets` | transform | `widgets` | Add admin dashboard widgets |
| `admin.settings` | transform | `settings` | Modify admin settings |

---

## Creating Custom Hooks

### Firing a Hook from Your Plugin

To fire a custom hook from your plugin:

```php
use App\Facades\Hook;

// Fire a side-effect hook
Hook::fire('my-plugin.custom_event', [
    'player' => $player,
    'data' => $customData,
]);

// Fire a transform hook and get results
$result = Hook::fire('my-plugin.process_data', [
    'input' => $someData,
    'player' => $player,
]);
```

### Declaring Custom Hooks

Always declare your custom hooks in `plugin.json`:

```json
{
    "hooks": {
        "my-plugin.event_one": true,
        "my-plugin.event_two": true
    }
}
```

---

## Best Practices

### 1. Namespace Your Hooks

Use plugin-prefixed hook names to avoid collisions:

```php
// ✅ Good - unique to your plugin
Hook::register('my-plugin.onPurchase', function ($data) { ... });

// ❌ Bad - too generic, could conflict
Hook::register('onPurchase', function ($data) { ... });
```

### 2. Use Appropriate Priority

- **High priority (50-100)**: Modifiers that must run first
- **Default priority (10)**: Observers and logging

```php
// Modifier - high priority
Hook::register('alterCrimeRewards', function ($data): array {
    $data['cash'] = applyBonus($data['cash'], $data['player']);
    return $data;
}, priority: 50);

// Observer - default priority
Hook::register('OnCrimeCommit', function ($data): void {
    logCrime($data['player'], $data['crime']);
});
```

### 3. Always Return Data for Transform Hooks

```php
// ✅ Correct
Hook::register('alterCrimeRewards', function ($data): array {
    $data['cash'] += 100;
    return $data; // Must return!
});

// ❌ Wrong - breaks the chain
Hook::register('alterCrimeRewards', function ($data): array {
    $data['cash'] += 100;
    // Missing return - chain breaks!
});
```

### 4. Use Side-Effect for Non-Modifying Logic

```php
// ✅ Good - side-effect for logging
Hook::register('OnCrimeCommit', function ($data): void {
    \App\Plugins\MyPlugin\Models\CrimeLog::create([
        'user_id' => $data['player']->id,
        'crime' => $data['crime'],
    ]);
});
```

---

## Exception Handling

### Exception Isolation

If a listener throws an exception:
1. The exception is caught and logged to Laravel's error log
2. **The next listener still runs**
3. For transform hooks, the chain continues with the last successfully returned value

```php
// Listener 1 - throws exception
Hook::register('economy.credit', function ($data): array {
    throw new \Exception('Something went wrong');
}, priority: 100);

// Listener 2 - still runs!
Hook::register('economy.credit', function ($data): array {
    $data['amount'] *= 1.05;
    return $data;
}, priority: 50);
```

### Safety Wrapper Pattern

For risky operations, wrap in try-catch:

```php
Hook::register('alterCombatRewards', function (array $data): array {
    try {
        $bonus = calculateDynamicBonus($data['player']);
        $data['cash'] += $bonus;
    } catch (\Exception $e) {
        \Log::error('Bonus calculation failed: ' . $e->getMessage());
        // Don't rethrow - let the chain continue
    }
    return $data;
});
```

---

## Examples

### Example 1: Adding a Crime Bonus

```php
// app/Plugins/VipBonus/hooks.php
use App\Facades\Hook;

Hook::register('alterCrimeRewards', function (array $data): array {
    $player = $data['player'];
    
    // VIP players get 25% bonus
    if ($player->hasPermission('vip')) {
        $data['cash'] = (int) ($data['cash'] * 1.25);
    }
    
    return $data;
}, priority: 50); // High priority - runs before default listeners
```

### Example 2: Logging All Purchases

```php
// app/Plugins/AuditLog/hooks.php
use App\Facades\Hook;

Hook::register('OnItemBought', function (array $data): void {
    \App\Plugins\AuditLog\Models\PurchaseLog::create([
        'user_id' => $data['player']->id,
        'item_id' => $data['item']->id,
        'quantity' => $data['quantity'],
        'total_cost' => $data['cost'],
    ]);
});
```

### Example 3: Adding Custom Menu Items

```php
// app/Plugins/Rpg/hooks.php
use App\Facades\Hook;

Hook::register('customMenus', function ($user) {
    if (!$user) return [];
    
    return [
        'rpg' => [
            'title' => 'RPG',
            'items' => [
                [
                    'url' => route('rpg.dashboard'),
                    'text' => 'Dashboard',
                    'icon' => '⚔️',
                    'sort' => 10,
                ],
                [
                    'url' => route('rpg.combat'),
                    'text' => 'Combat',
                    'icon' => '🗡️',
                    'sort' => 20,
                ],
            ],
        ],
    ];
}, 10);
```

### Example 4: Full hooks.php with Multiple Listeners

```php
<?php
// app/Plugins/MyPlugin/hooks.php

use App\Facades\Hook;

// Self-register service binding (runs once when hooks.php loads)
if (! app()->bound('myplugin')) {
    app()->singleton('myplugin', fn ($app) => $app->make(\App\Plugins\MyPlugin\Services\MyPluginService::class));
}

// Transform hook with high priority — runs before default listeners
Hook::register('alterCrimeRewards', function (array $data): array {
    if ($data['player']->hasActiveBoost('crime_bonus')) {
        $data['cash'] = (int) ($data['cash'] * 1.25);
    }
    return $data;
}, priority: 50);

// Side-effect observer — uses default priority, runs after high-priority transforms
Hook::register('OnCrimeCommit', function (array $data): void {
    \App\Plugins\MyPlugin\Models\CrimeLog::record($data['player'], $data['crime']);
});

// Declarative format (simplest for basic hooks)
return [
    'stats.display' => function (array $stats) {
        $user = auth()->user();
        if ($user) {
            $stats['my_plugin'] = [
                'score' => $user->getPluginMeta('my_plugin', 'score', 0),
            ];
        }
        return $stats;
    },
];
```

---

## Rules Summary

1. **Never remove or rename an existing hook** — this breaks all listeners registered against it
2. **New hooks require review** — add them to this catalogue and to `HookRegistry::defineCoreHooks()`
3. **Exception safety** — if your listener throws, the exception is caught, logged, and the next listener still runs
4. **No I/O blocking** — listeners run synchronously on the request cycle. Use queued jobs for slow work
5. **Declare your hooks** — always list hooks in plugin.json for documentation and admin tools
