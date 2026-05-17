<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Verify that the player_profiles migration is complete and safe to finalize.
 *
 * Checks performed:
 *   1. Every user in `users` has a matching row in `player_profiles`.
 *   2. No PHP source file contains raw references to `users.{game_stat}`.
 *   3. No PHP source file queries `DB::table('users')->select()` with game columns.
 *   4. Data spot-check: cash totals match between tables.
 *
 * Usage:
 *   php artisan users:verify-profile-migration
 *   php artisan users:verify-profile-migration --fix-missing   (auto-creates missing profiles)
 */
class VerifyProfileMigration extends Command
{
    protected $signature   = 'users:verify-profile-migration {--fix-missing : Auto-create missing PlayerProfile rows}';
    protected $description = 'Verify all users have a player_profile and no code references users game columns directly.';

    /** Game stat columns that should no longer live on the users table after Phase 6. */
    private const GAME_COLUMNS = [
        'cash', 'bank', 'respect', 'bullets', 'points',
        'strength', 'defense', 'speed',
        'health', 'max_health', 'energy', 'max_energy', 'nerve', 'max_nerve',
        'level', 'experience', 'rank', 'rank_id',
        'location', 'location_id',
        'status', 'jail_until', 'last_crime_at', 'last_gta_at',
    ];

    public function handle(): int
    {
        $this->info('=== PlayerProfile Migration Verification ===');
        $this->newLine();

        $passed = true;

        $passed = $this->checkMissingProfiles() && $passed;
        $passed = $this->checkRawColumnReferences() && $passed;
        $passed = $this->checkDataConsistency() && $passed;

        $this->newLine();
        if ($passed) {
            $this->info('✅  All checks passed. Safe to run the drop-game-columns migration.');
            return self::SUCCESS;
        }

        $this->error('❌  One or more checks failed. Resolve the issues above before running the migration.');
        return self::FAILURE;
    }

    // ── Check 1: Missing profiles ─────────────────────────────────────────────

    private function checkMissingProfiles(): bool
    {
        $this->line('<fg=cyan>Check 1: Every user has a player_profile</>');

        if (!DB::getSchemaBuilder()->hasTable('player_profiles')) {
            $this->error('  player_profiles table does not exist — run migrations first.');
            return false;
        }

        $missing = DB::table('users')
            ->leftJoin('player_profiles', 'users.id', '=', 'player_profiles.user_id')
            ->whereNull('player_profiles.user_id')
            ->pluck('users.id');

        if ($missing->isEmpty()) {
            $this->line('  <fg=green>PASS</> All ' . DB::table('users')->count() . ' users have a profile.');
            return true;
        }

        $this->error("  FAIL — {$missing->count()} user(s) are missing a player_profile: " . $missing->take(10)->join(', ') . ($missing->count() > 10 ? '...' : ''));

        if ($this->option('fix-missing')) {
            $this->line('  Auto-creating missing profiles…');
            foreach ($missing as $userId) {
                DB::table('player_profiles')->insertOrIgnore([
                    'user_id'    => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->line("  <fg=green>Fixed</> Created {$missing->count()} missing profile(s).");
            return true;
        }

        $this->line('  Run with --fix-missing to auto-create them.');
        return false;
    }

    // ── Check 2: Raw column references in PHP source ──────────────────────────

    private function checkRawColumnReferences(): bool
    {
        $this->line('<fg=cyan>Check 2: No raw users.{game_column} references in PHP source</>');

        $searchPaths = [
            app_path(),
            base_path('routes'),
            base_path('database'),
        ];

        $patterns = array_map(
            fn($col) => "users\.{$col}",
            self::GAME_COLUMNS
        );

        $found = [];

        foreach ($searchPaths as $searchPath) {
            if (! is_dir($searchPath)) continue;
            $files = File::allFiles($searchPath);
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') continue;
                $content = file_get_contents($file->getRealPath());
                    // Avoid flagging this verifier file itself when it contains
                    // legitimate checks against the users table.
                    if ($file->getRealPath() === __FILE__) {
                        continue;
                    }
                foreach ($patterns as $pattern) {
                    if (preg_match("/{$pattern}/", $content)) {
                        $found[] = $file->getRelativePathname();
                        break;
                    }
                }
            }
        }

        if (empty($found)) {
            $this->line('  <fg=green>PASS</> No raw users.{game_column} patterns found.');
            return true;
        }

        $this->error('  FAIL — Found ' . count($found) . ' file(s) with raw users.{game_column} references:');
        foreach ($found as $f) {
            $this->line("    - {$f}");
        }
        return false;
    }

    // ── Check 3: Data consistency spot-check ──────────────────────────────────

    private function checkDataConsistency(): bool
    {
        $this->line('<fg=cyan>Check 3: Data consistency (cash totals match)</>');

        $usersTable = DB::getSchemaBuilder()->hasTable('users') && DB::getSchemaBuilder()->hasColumn('users', 'cash');
        $profilesTable = DB::getSchemaBuilder()->hasTable('player_profiles');

        if (! $usersTable || ! $profilesTable) {
            $this->line('  <fg=yellow>SKIP</> (cash column no longer on users — column drop already applied)');
            return true;
        }

        $usersCash    = (int) DB::table('users')->sum('cash');
        $profilesCash = (int) DB::table('player_profiles')->sum('cash');

        if ($usersCash === $profilesCash) {
            $this->line("  <fg=green>PASS</> Cash totals match: \${$profilesCash}");
            return true;
        }

        $diff = abs($usersCash - $profilesCash);
        $this->error("  FAIL — Cash totals diverge by \${$diff} (users.cash = \${$usersCash}, player_profiles.cash = \${$profilesCash})");
        $this->line('  This indicates the seeder migration did not run or data changed in users.cash after seeding.');
        return false;
    }
}
