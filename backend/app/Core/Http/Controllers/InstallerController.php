<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\User;
use App\Core\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InstallerController extends Controller
{
    /**
     * Check if installation is already complete
     */
    protected function isInstalled()
    {
        // Preview mode only allowed in local environment
        if (app()->environment('local') && request()->has('preview')) {
            return false;
        }
        return File::exists(storage_path('installed'));
    }

    /**
     * Welcome page
     */
    public function index()
    {
        if ($this->isInstalled()) {
            return response()->json(['installed' => true, 'status' => 'already_installed']);
        }

        return response()->json(['installed' => false, 'status' => 'ready']);
    }

    /**
     * Check system requirements
     */
    public function requirements()
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        $requirements = [
            'php' => [
                'version' => PHP_VERSION,
                'required' => '8.2.0',
                'status' => version_compare(PHP_VERSION, '8.2.0', '>=')
            ],
            'extensions' => [
                'PDO' => extension_loaded('pdo'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'mbstring' => extension_loaded('mbstring'),
                'openssl' => extension_loaded('openssl'),
                'tokenizer' => extension_loaded('tokenizer'),
                'json' => extension_loaded('json'),
                'curl' => extension_loaded('curl'),
                'zip' => extension_loaded('zip'),
                'gd' => extension_loaded('gd'),
            ]
        ];

        $permissions = [
            'storage/app' => is_writable(storage_path('app')),
            'storage/framework' => is_writable(storage_path('framework')),
            'storage/logs' => is_writable(storage_path('logs')),
            'bootstrap/cache' => is_writable(base_path('bootstrap/cache')),
        ];

        $allRequirementsMet = $requirements['php']['status'] &&
                              !in_array(false, $requirements['extensions']) &&
                              !in_array(false, $permissions);

        return response()->json(compact('requirements', 'permissions', 'allRequirementsMet'));
    }

    /**
     * Show database configuration form
     */
    public function database()
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        return response()->json(['status' => 'ready']);
    }

    /**
     * Test database connection and save configuration
     */
    public function databaseStore(Request $request)
    {
        $data = $request->validate([
            'db_host' => 'required',
            'db_port' => 'required|numeric',
            'db_name' => 'required',
            'db_username' => 'required',
            'db_password' => 'nullable',
        ]);

        // Test connection
        try {
            $pdo = new \PDO(
                "mysql:host={$data['db_host']};port={$data['db_port']};dbname={$data['db_name']}",
                $data['db_username'],
                $data['db_password'] ?? ''
            );
            $pdo = null;
        } catch (\Throwable $e) {
            return $this->handleGameException($e, 422);
        }

        // Update .env file
        $this->updateEnvFile([
            'DB_HOST' => $data['db_host'],
            'DB_PORT' => $data['db_port'],
            'DB_DATABASE' => $data['db_name'],
            'DB_USERNAME' => $data['db_username'],
            'DB_PASSWORD' => $data['db_password'] ?? '',
        ]);

        return response()->json(['success' => true, 'message' => 'Database configuration saved']);
    }

    /**
     * Show application settings form
     */
    public function settings()
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        return response()->json(['status' => 'ready']);
    }

    /**
     * Save application settings
     */
    public function settingsStore(Request $request)
    {
        $request->validate([
            'app_name' => 'required|string|max:255',
            'app_url' => 'required|url',
            'app_env' => 'required|in:production,local',
        ]);

        $this->updateEnvFile([
            'APP_NAME' => $request->app_name,
            'APP_URL' => $request->app_url,
            'APP_ENV' => $request->app_env,
            'APP_DEBUG' => $request->app_env === 'local' ? 'true' : 'false',
        ]);

        // Generate app key if not exists
        if (empty(config('app.key'))) {
            Artisan::call('key:generate', ['--force' => true]);
        }

        return response()->json(['success' => true, 'message' => 'Settings saved successfully']);
    }

    /**
     * Run installation
     */
    public function install()
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        return response()->json(['status' => 'ready']);
    }

    /**
     * Execute installation steps (legacy - all at once)
     */
    public function installProcess(Request $request)
    {
        try {
            // Clear config cache
            Artisan::call('config:clear');

            // Run migrations
            Artisan::call('migrate', ['--force' => true]);

            // Create storage link
            if (!File::exists(public_path('storage'))) {
                Artisan::call('storage:link');
            }

            // Seed database with game data
            Artisan::call('db:seed', ['--force' => true]);

            return response()->json(['success' => true, 'message' => 'Installation completed successfully']);
        } catch (\Throwable $e) {
            return $this->handleGameException($e, 500);
        }
    }

    /**
     * Step 1: Clear configuration cache
     */
    public function stepClearCache()
    {
        if ($this->isInstalled()) {
            abort(403, 'Installer is disabled after installation.');
        }
        try {
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
            Artisan::call('route:clear');
            return response()->json([
                'success' => true,
                'message' => 'Configuration cache cleared',
                'output' => Artisan::output()
            ]);
        } catch (\Throwable $e) {
            return $this->handleGameException($e, 500);
        }
    }

    /**
     * Step 2: Run database migrations
     */
    public function stepMigrate()
    {
        if ($this->isInstalled()) {
            abort(403, 'Installer is disabled after installation.');
        }
        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();
            // Count migrations run
            preg_match_all('/Migrating/', $output, $matches);
            $count = count($matches[0]);
            return response()->json([
                'success' => true,
                'message' => $count > 0 ? "Ran {$count} migrations" : "Database is up to date",
                'output' => $output
            ]);
        } catch (\Throwable $e) {
            return $this->handleGameException($e, 500);
        }
    }

    /**
     * Step 3: Seed the database
     */
    public function stepSeed()
    {
        if ($this->isInstalled()) {
            abort(403, 'Installer is disabled after installation.');
        }
        try {
            Artisan::call('db:seed', ['--force' => true]);
            return response()->json([
                'success' => true,
                'message' => 'Game data seeded successfully',
                'output' => Artisan::output()
            ]);
        } catch (\Throwable $e) {
            return $this->handleGameException($e, 500);
        }
    }

    /**
     * Step 4: Create storage symbolic link
     */
    public function stepStorageLink()
    {
        if ($this->isInstalled()) {
            abort(403, 'Installer is disabled after installation.');
        }
        try {
            if (!File::exists(public_path('storage'))) {
                Artisan::call('storage:link');
                $message = 'Storage link created';
            } else {
                $message = 'Storage link already exists';
            }
            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Throwable $e) {
            return $this->handleGameException($e, 500);
        }
    }

    /**
     * Step 5: Finalize installation
     */
    public function stepFinalize()
    {
        if ($this->isInstalled()) {
            abort(403, 'Installer is disabled after installation.');
        }
        try {
            // Optimize for production
            Artisan::call('config:cache');
            Artisan::call('route:cache');

            // Remove installer directory for security
            $installerPath = public_path('install');
            if (is_dir($installerPath)) {
                $this->deleteDirectory($installerPath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Application optimized for production and installer removed'
            ]);
        } catch (\Throwable $e) {
            return $this->handleGameException($e, 500);
        }
    }

    /**
     * Recursively delete a directory
     */
    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        return rmdir($dir);
    }

    /**
     * Show admin account creation form
     */
    public function admin()
    {
        if ($this->isInstalled()) {
            return redirect('/');
        }

        // Check if migrations have been run
        try {
            DB::table('roles')->count();
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Database tables not found. Please run migrations first.'
            ], 422);
        }

        // Check if roles are seeded
        $adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')->first();
        if (!$adminRole) {
            return response()->json([
                'status' => 'error',
                'message' => 'Admin role not found. Please run database seeders first.'
            ], 422);
        }

        return response()->json(['status' => 'ready']);
    }

    /**
     * Create admin account
     */
    public function adminStore(Request $request)
    {
        if ($this->isInstalled()) {
            abort(403, 'Installer is disabled after installation.');
        }
        try {
            $request->validate([
                'username' => 'required|string|max:255|unique:users',
                'email' => 'required|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            // Check if admin role exists
            $adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')
                ->where('guard_name', 'sanctum')
                ->first();
            if (!$adminRole) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin role not found. Please run database seeders first (php artisan db:seed).'
                ], 422);
            }

            // Look up first rank and location dynamically — never hardcode IDs
            $firstRank     = DB::table('ranks')->orderBy('required_exp')->first();
            $firstLocation = DB::table('locations')->orderBy('id')->first();

            // Wrap user creation + role assignment in a transaction so a partial
            // failure (e.g. role table write) doesn't leave an orphaned user row.
            $user = DB::transaction(function () use ($request, $firstRank, $firstLocation) {
                // Only identity/auth fields go into User — game stats live on PlayerProfile
                $u = User::create([
                    'name'                  => $request->username,
                    'username'              => $request->username,
                    'email'                 => $request->email,
                    'password'              => $request->password,
                    'email_verified_at'     => now(),
                    'force_password_change' => true,
                ]);

                // Set starting game stats on the profile (auto-created by User::booted())
                $u->profile->update([
                    'rank_id'     => $firstRank?->id,
                    'rank'        => $firstRank?->name ?? 'Thug',
                    'location_id' => $firstLocation?->id,
                    'location'    => $firstLocation?->name ?? 'Detroit',
                    'health'      => $firstRank?->max_health ?? 100,
                    'max_health'  => $firstRank?->max_health ?? 100,
                    'cash'        => 1000,
                    'bullets'     => 50,
                ]);

                // Assign admin role (highest level access)
                $u->assignRole('admin');

                if (!$u->hasRole('admin')) {
                    throw new \Exception('Failed to assign admin role to user. Please check your database permissions.');
                }

                return $u;
            });

            Log::info('Admin user created successfully', [
                'username' => $user->username,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->toArray()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Admin account created successfully',
                'user' => [
                    'username' => $user->username,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . collect($e->errors())->flatten()->first()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Admin creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create admin account: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Installation complete
     */
    public function complete()
    {
        if ($this->isInstalled()) {
            abort(403, 'Installer is disabled after installation.');
        }
        // Mark as installed
        File::put(storage_path('installed'), now()->toDateTimeString());

        // Clear all caches
        Artisan::call('optimize:clear');

        return response()->json(['success' => true, 'message' => 'Installation complete']);
    }

    /**
     * Validate and store a license key
     */
    public function licenseStore(Request $request)
    {
        if ($this->isInstalled()) {
            abort(403, 'Installer is disabled after installation.');
        }
        $request->validate([
            'license_key' => 'required|string|min:20',
        ]);

        $key = trim($request->license_key);
        $result = LicenseService::validate($key);

        if (!$result || !$result['valid']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Invalid license key. Please check your key and try again.',
            ], 422);
        }


        // Store the license key
        LicenseService::store($key);

        // Also save to .env
        $this->updateEnvFile(['LARAVEL_CP_LICENSE' => $key]);

        // Send activation callback to your server
        LicenseService::sendActivationCallback($key, $result['payload']);

        $payload = $result['payload'];

        return response()->json([
            'success' => true,
            'message' => 'License key validated and activated.',
            'license' => [
                'tier' => $payload['tier'],
                'domain' => $payload['domain'],
                'expires' => $payload['expires'],
                'customer' => $payload['customer'] ?? '',
            ],
        ]);
    }

    /**
     * Get current license status
     */
    public function licenseCheck()
    {
        return response()->json(LicenseService::getDetails());
    }

    /**
     * Update .env file with new values.
     *
     * Security notes:
     *  - A backup is written to .env.backup before any modification.
     *  - preg_replace_callback is used instead of preg_replace so that
     *    values containing $ or \ are never interpreted as backreferences.
     *  - Newlines are stripped to prevent line-injection attacks.
     */
    protected function updateEnvFile(array $data): void
    {
        $envFile = base_path('.env');

        if (!File::exists($envFile)) {
            File::copy(base_path('.env.example'), $envFile);
        }

        // Backup before modification so a corrupt write can be recovered
        File::copy($envFile, $envFile . '.backup');

        $envContent = File::get($envFile);

        foreach ($data as $key => $value) {
            // Strip newlines (prevent line-injection) and escape double-quotes
            $value = str_replace(["\n", "\r"], '', $value);
            $value = str_replace('"', '\"', $value);

            $quotedLine = "{$key}=\"{$value}\"";

            if (preg_match("/^{$key}=/m", $envContent)) {
                // Use a callback so $value is returned as a literal string —
                // avoids preg_replace treating $1 / \1 in $value as backreferences
                $envContent = preg_replace_callback(
                    "/^{$key}=.*/m",
                    fn() => $quotedLine,
                    $envContent
                );
            } else {
                $envContent .= "\n{$quotedLine}";
            }
        }

        File::put($envFile, $envContent);
    }
}
