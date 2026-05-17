<?php

namespace App\Core\Console\Commands;

use App\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class InstallCommand extends Command
{
    protected $signature = 'app:install
                            {--force : Re-run installation even if already installed}
                            {--skip-seed : Skip database seeding}
                            {--admin-username=admin : Admin username}
                            {--admin-email=admin@example.com : Admin email}
                            {--admin-password=admin123 : Admin password}';

    protected $description = 'Install LaravelCP — migrates, seeds, and creates admin account';

    public function handle(): int
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════╗');
        $this->line('║        LaravelCP Installer v1.0          ║');
        $this->line('╚══════════════════════════════════════════╝');
        $this->newLine();

        // ── Check if already installed ──────────────────────────
        if (File::exists(storage_path('installed')) && ! $this->option('force')) {
            $this->warn('⚠  LaravelCP is already installed.');
            $this->line('   Use --force to re-run the installer.');
            return self::SUCCESS;
        }

        // ── Step 1: Environment ─────────────────────────────────
        $this->info('Step 1/6: Checking environment...');
        $this->checkEnvironment();

        // ── Step 2: App key ─────────────────────────────────────
        $this->info('Step 2/6: Generating application key...');
        if (empty(config('app.key'))) {
            Artisan::call('key:generate', ['--force' => true]);
            $this->line('   ✓ Application key generated');
        } else {
            $this->line('   ✓ Application key already set');
        }

        // ── Step 3: Database connection ─────────────────────────
        $this->info('Step 3/6: Testing database connection...');
        if (! $this->testDatabase()) {
            return self::FAILURE;
        }

        // ── Step 4: Migrations ──────────────────────────────────
        $this->info('Step 4/6: Running database migrations...');
        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();
            preg_match_all('/Migrating/', $output, $matches);
            $count = count($matches[0]);
            $this->line($count > 0
                ? "   ✓ Ran {$count} migrations"
                : '   ✓ Database is up to date');
        } catch (\Exception $e) {
            $this->error('   ✗ Migration failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        // ── Step 5: Seed ────────────────────────────────────────
        if (! $this->option('skip-seed')) {
            $this->info('Step 5/6: Seeding game data...');
            try {
                Artisan::call('db:seed', ['--force' => true]);
                $this->line('   ✓ Game data seeded (ranks, locations, crimes, items, etc.)');
            } catch (\Exception $e) {
                $this->error('   ✗ Seeding failed: ' . $e->getMessage());
                return self::FAILURE;
            }
        } else {
            $this->line('Step 5/6: Skipping seed (--skip-seed)');
        }

        // ── Step 6: Admin account ───────────────────────────────
        $this->info('Step 6/6: Creating admin account...');
        if (! $this->createAdmin()) {
            return self::FAILURE;
        }

        // ── Finalize ────────────────────────────────────────────
        $this->finalize();

        return self::SUCCESS;
    }

    /**
     * Check PHP extensions and directory permissions.
     */
    protected function checkEnvironment(): void
    {
        $extensions = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'json', 'curl'];
        $missing = [];

        foreach ($extensions as $ext) {
            if (! extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        if (count($missing) > 0) {
            $this->warn('   ⚠ Missing PHP extensions: ' . implode(', ', $missing));
        } else {
            $this->line('   ✓ All required PHP extensions loaded');
        }

        // Check writable directories
        $dirs = ['storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'];
        foreach ($dirs as $dir) {
            if (! is_writable(base_path($dir))) {
                $this->warn("   ⚠ {$dir} is not writable");
            }
        }

        $this->line('   ✓ PHP ' . PHP_VERSION);
    }

    /**
     * Test database connectivity.
     */
    protected function testDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            $this->line('   ✓ Connected to ' . config('database.connections.' . config('database.default') . '.database'));
            return true;
        } catch (\Exception $e) {
            $this->error('   ✗ Database connection failed: ' . $e->getMessage());
            $this->newLine();
            $this->warn('   Check your .env file:');
            $this->line('   DB_HOST=' . config('database.connections.' . config('database.default') . '.host'));
            $this->line('   DB_PORT=' . config('database.connections.' . config('database.default') . '.port'));
            $this->line('   DB_DATABASE=' . config('database.connections.' . config('database.default') . '.database'));
            return false;
        }
    }

    /**
     * Create the admin user with proper role, rank, and location.
     */
    protected function createAdmin(): bool
    {
        $username = $this->option('admin-username');
        $email    = $this->option('admin-email');
        $password = $this->option('admin-password');

        // Check if admin already exists
        if (User::where('username', $username)->orWhere('email', $email)->exists()) {
            $this->line('   ✓ Admin user already exists — skipping');
            return true;
        }

        // Verify roles have been seeded
        $adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')
            ->where('guard_name', 'sanctum')
            ->first();

        if (! $adminRole) {
            $this->error('   ✗ Admin role not found. Seeding may have failed.');
            return false;
        }

        // Look up first rank and location dynamically (never hardcode IDs)
        $firstRank    = DB::table('ranks')->orderBy('required_exp', 'asc')->first();
        $firstLocation = DB::table('locations')->orderBy('id', 'asc')->first();

        if (! $firstRank) {
            $this->error('   ✗ No ranks found in database. Seeding may have failed.');
            return false;
        }

        if (! $firstLocation) {
            $this->error('   ✗ No locations found in database. Seeding may have failed.');
            return false;
        }

        try {
            $user = DB::transaction(function () use ($username, $email, $password, $firstRank, $firstLocation) {
                // Identity-only — game stats live on the profile
                $user = User::create([
                    'name'                  => $username,
                    'username'              => $username,
                    'email'                 => $email,
                    'password'              => Hash::make($password),
                    'email_verified_at'     => now(),
                    'force_password_change' => true,
                ]);

                // User::booted() auto-creates the profile; seed starting game values
                $user->profile()->update([
                    'rank_id'     => $firstRank->id,
                    'rank'        => $firstRank->name,
                    'location_id' => $firstLocation->id,
                    'location'    => $firstLocation->name,
                    'level'       => 1,
                    'experience'  => 0,
                    'energy'      => 100,
                    'max_energy'  => 100,
                    'health'      => $firstRank->max_health ?? 100,
                    'max_health'  => $firstRank->max_health ?? 100,
                    'cash'        => 1000,
                    'bank'        => 0,
                    'bullets'     => 50,
                    'respect'     => 0,
                ]);

                // Assign admin role
                $user->assignRole('admin');

                if (! $user->hasRole('admin')) {
                    throw new \RuntimeException('Failed to assign admin role.');
                }

                return $user;
            });

            $this->line('   ✓ Admin account created');
            $this->newLine();
            $this->warn('   ╔═══════════════════════════════════════╗');
            $this->warn('   ║     DEFAULT ADMIN CREDENTIALS         ║');
            $this->warn('   ╠═══════════════════════════════════════╣');
            $this->warn("   ║  Username: {$username}");
            $this->warn("   ║  Email:    {$email}");
            $this->warn("   ║  Password: {$password}");
            $this->warn('   ╠═══════════════════════════════════════╣');
            $this->warn('   ║  ⚠ CHANGE PASSWORD ON FIRST LOGIN!   ║');
            $this->warn('   ╚═══════════════════════════════════════╝');

            return true;
        } catch (\Exception $e) {
            $this->error('   ✗ Failed to create admin: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Final steps: storage link, caching, mark as installed.
     */
    protected function finalize(): void
    {
        // Storage link
        if (! File::exists(public_path('storage'))) {
            try {
                Artisan::call('storage:link');
                $this->line('   ✓ Storage link created');
            } catch (\Exception $e) {
                $this->warn('   ⚠ Could not create storage link: ' . $e->getMessage());
            }
        }

        // Cache in production
        if (! app()->environment('local')) {
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');
            $this->line('   ✓ Configuration cached for production');
        }

        // Mark installed
        File::put(storage_path('installed'), now()->toDateTimeString());

        $this->newLine();
        $this->info('══════════════════════════════════════════');
        $this->info('  ✅  LaravelCP installed successfully!');
        $this->info('══════════════════════════════════════════');
        $this->newLine();
    }
}
