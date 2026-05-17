<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Log;

/**
 * Formal hook registry.
 * Stores schema definitions for all named hooks, enabling validation and documentation.
 * GameHooks::define() delegates to this class.
 */
class HookRegistry
{
    protected static array $definitions = [];

    /**
     * Define a hook with its payload schema, version, and stability.
     *
     * @param string $hook       Hook name
     * @param array  $schema     Associative array of field => expected type (e.g. ['user' => 'object', 'amount' => 'int'])
     * @param string $version    Semantic version of this hook definition
     * @param string $stability  'stable' | 'experimental' | 'deprecated'
     */
    public static function define(
        string $hook,
        array $schema = [],
        string $version = '1.0',
        string $stability = 'stable'
    ): void {
        static::$definitions[$hook] = [
            'schema'    => $schema,
            'version'   => $version,
            'stability' => $stability,
        ];
    }

    /**
     * Get the full definition for a hook, or null if undefined.
     */
    public static function get(string $hook): ?array
    {
        return static::$definitions[$hook] ?? null;
    }

    /**
     * Check if a hook has been defined.
     */
    public static function isDefined(string $hook): bool
    {
        return isset(static::$definitions[$hook]);
    }

    /**
     * Validate a payload against a hook's schema.
     * Returns an array of error strings (empty = valid).
     * Should only be called in non-production environments.
     */
    public static function validatePayload(string $hook, mixed $payload): array
    {
        $definition = static::$definitions[$hook] ?? null;
        if (!$definition || empty($definition['schema'])) {
            return [];
        }

        if ($definition['stability'] === 'deprecated') {
            Log::warning("HookRegistry: Hook '{$hook}' is deprecated and will be removed in a future version.");
        }

        $errors = [];
        $schema = $definition['schema'];

        if (!is_array($payload)) {
            return ["Payload must be an array, got " . gettype($payload)];
        }

        foreach ($schema as $field => $expectedType) {
            if (!array_key_exists($field, $payload)) {
                $errors[] = "Missing required field '{$field}' (expected {$expectedType})";
                continue;
            }

            $actualType = gettype($payload[$field]);
            $actualType = $actualType === 'double' ? 'float' : $actualType;

            // Normalise: 'object' also matches class instances
            if ($expectedType === 'object' && is_object($payload[$field])) {
                continue;
            }
            if ($expectedType !== 'object' && $actualType !== $expectedType) {
                $errors[] = "Field '{$field}' expected {$expectedType}, got {$actualType}";
            }
        }

        return $errors;
    }

    /**
     * Return all hook definitions (for introspection, docs endpoint, admin panel).
     */
    public static function all(): array
    {
        return static::$definitions;
    }

    /**
     * Register all built-in core hooks.
     * Called from AppServiceProvider::boot().
     */
    public static function defineCoreHooks(): void
    {
        // ── Navigation ──────────────────────────────────────────────────────
        // admin.sidebar listeners receive a plain array of sections and return it.
        // Plugins expect the listener signature: function(array $sections) { ... return $sections; }
        // Skip strict field-level validation to avoid false-positives from plugin listeners.
        static::define('admin.sidebar', [], '1.0');
        static::define('customMenus', ['user' => 'object'], '1.0');
        static::define('moduleLoad', ['user' => 'object'], '1.0');
        static::define('alterModuleData', ['data' => 'array'], '1.0');

        // ── Economy ──────────────────────────────────────────────────────────
        static::define('economy.credit', ['user' => 'object', 'amount' => 'integer', 'reason' => 'string'], '1.0');
        static::define('economy.debit',  ['user' => 'object', 'amount' => 'integer', 'reason' => 'string'], '1.0');

        // ── Crimes ───────────────────────────────────────────────────────────
        static::define('afterCrimeAttempt', ['player' => 'object', 'crime' => 'object', 'success' => 'boolean', 'result' => 'array'], '1.0');
        static::define('modifyCrimeSuccessRate', ['player' => 'object', 'crime' => 'object', 'rate' => 'integer'], '1.0');
        static::define('alterCrimeData', ['crimes' => 'array', 'player' => 'object'], '1.0');
        static::define('OnCrimeCommit', ['player' => 'object', 'crime_type' => 'string', 'success' => 'boolean', 'cash_earned' => 'integer', 'respect_earned' => 'integer'], '1.0');

        // ── Combat ───────────────────────────────────────────────────────────
        static::define('afterCombat', ['attacker' => 'object', 'defender' => 'object', 'result' => 'array'], '1.0');
        static::define('modifyCombatPower', ['user' => 'object', 'power' => 'integer'], '1.0');
        static::define('alterCombatTarget', ['attacker' => 'object', 'defender' => 'object'], '1.0');
        static::define('OnCombat', ['attacker' => 'object', 'defender' => 'object', 'result' => 'array'], '1.0');

        // ── Employment ───────────────────────────────────────────────────────
        static::define('OnJobApplied', ['user' => 'object', 'position' => 'object', 'company' => 'object'], '1.0');
        static::define('OnWorkCompleted', ['user' => 'object', 'earnings' => 'integer', 'exp' => 'integer'], '1.0');
        static::define('OnJobQuit', ['user' => 'object', 'position' => 'object'], '1.0');
        static::define('alterEmploymentCompanies', ['companies' => 'array', 'user' => 'object'], '1.0');

        // ── Inventory ────────────────────────────────────────────────────────
        static::define('OnItemBought', ['player' => 'object', 'item' => 'object', 'quantity' => 'integer', 'cost' => 'integer'], '1.0');
        static::define('OnItemSold', ['player' => 'object', 'item' => 'object', 'quantity' => 'integer', 'earnings' => 'integer'], '1.0');
        static::define('OnItemEquipped', ['player' => 'object', 'item' => 'object'], '1.0');
        static::define('OnItemUnequipped', ['player' => 'object', 'item' => 'object'], '1.0');
        static::define('OnItemUsed', ['player' => 'object', 'item' => 'object'], '1.0');
        static::define('alterInventoryItemData', ['item' => 'array', 'player' => 'object'], '1.0');
        static::define('alterInventoryValue', ['value' => 'integer', 'player' => 'object'], '1.0');
        static::define('alterEquipmentBonuses', ['bonuses' => 'array', 'player' => 'object'], '1.0');

        // ── Bank ─────────────────────────────────────────────────────────────
        static::define('OnBankDeposit', ['player' => 'object', 'amount' => 'integer', 'tax' => 'integer'], '1.0');
        static::define('OnBankWithdraw', ['player' => 'object', 'amount' => 'integer'], '1.0');
        static::define('OnBankTransfer', ['sender' => 'object', 'recipient' => 'object', 'amount' => 'integer'], '1.0');
        static::define('afterBankDeposit', ['player' => 'object', 'amount' => 'integer', 'tax' => 'integer'], '1.0');
        static::define('afterBankWithdraw', ['player' => 'object', 'amount' => 'integer'], '1.0');
        static::define('afterBankTransfer', ['sender' => 'object', 'recipient' => 'object', 'amount' => 'integer'], '1.0');

        // ── Generic lifecycle ─────────────────────────────────────────────────
        static::define('OnLevelUp', ['player' => 'object', 'old_level' => 'integer', 'new_level' => 'integer'], '1.0');
        static::define('OnPlayerLogin', ['player' => 'object', 'ip_address' => 'string'], '1.0');
        static::define('OnPurchase', ['player' => 'object', 'item_type' => 'string', 'item' => 'object', 'cost' => 'integer'], '1.0');
        static::define('OnTravel', ['player' => 'object', 'from_location' => 'string', 'to_location' => 'string'], '1.0');

        // ── Admin dashboard ───────────────────────────────────────────────────
        // Plugins append their own stats widget to the dashboard response.
        // Each listener receives the current $widgets array and must return it with new keys added.
        // Example: $widgets['crimes'] = ['crimesToday' => 42, ...]; return $widgets;
        static::define('admin.dashboard.widgets', ['widgets' => 'array'], '1.0');

        // ── ActionPipeline hooks ──────────────────────────────────────────────
        //
        // Every mutation routed through ActionPipeline fires a before and after hook.
        //
        // Before-hook payload shape (same for all):
        //   ['player' => User, 'action' => string, 'payload' => array]
        //   Return the full array (with a modified 'payload' key) to alter input.
        //
        // After-hook payload shape (same for all):
        //   ['player' => User, 'action' => string, 'result' => mixed, 'success' => bool]
        //   Return the full array (with a modified 'result' key) to alter output.
        //
        // 'result' is intentionally excluded from schema validation because its
        // type is action-specific (array or null depending on execution outcome).

        $beforeSchema = ['player' => 'object', 'action' => 'string', 'payload' => 'array'];
        $afterSchema  = ['player' => 'object', 'action' => 'string', 'success' => 'boolean'];

        // Crimes
        static::define('before.crime.commit', $beforeSchema, '1.0');
        static::define('after.crime.commit',  $afterSchema,  '1.0');

        // Combat — PvP
        static::define('before.combat.attack', $beforeSchema, '1.0');
        static::define('after.combat.attack',  $afterSchema,  '1.0');

        // Combat — NPC
        static::define('before.combat.hunt',            $beforeSchema, '1.0');
        static::define('after.combat.hunt',             $afterSchema,  '1.0');
        static::define('before.combat.attack_npc',      $beforeSchema, '1.0');
        static::define('after.combat.attack_npc',       $afterSchema,  '1.0');
        static::define('before.combat.auto_attack_npc', $beforeSchema, '1.0');
        static::define('after.combat.auto_attack_npc',  $afterSchema,  '1.0');

        // Bank
        static::define('before.bank.deposit',  $beforeSchema, '1.0');
        static::define('after.bank.deposit',   $afterSchema,  '1.0');
        static::define('before.bank.withdraw', $beforeSchema, '1.0');
        static::define('after.bank.withdraw',  $afterSchema,  '1.0');
        static::define('before.bank.transfer', $beforeSchema, '1.0');
        static::define('after.bank.transfer',  $afterSchema,  '1.0');

        // Inventory
        static::define('before.inventory.buy',     $beforeSchema, '1.0');
        static::define('after.inventory.buy',      $afterSchema,  '1.0');
        static::define('before.inventory.sell',    $beforeSchema, '1.0');
        static::define('after.inventory.sell',     $afterSchema,  '1.0');
        static::define('before.inventory.equip',   $beforeSchema, '1.0');
        static::define('after.inventory.equip',    $afterSchema,  '1.0');
        static::define('before.inventory.unequip', $beforeSchema, '1.0');
        static::define('after.inventory.unequip',  $afterSchema,  '1.0');
        static::define('before.inventory.use',     $beforeSchema, '1.0');
        static::define('after.inventory.use',      $afterSchema,  '1.0');
    }
}
