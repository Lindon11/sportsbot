<?php

namespace Database\Seeders;

use App\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This creates a test user for development/testing purposes.
     *
     * ⚠️ SECURITY WARNING:
     * Only use this in development or testing environments.
     * Remove or change these credentials in production!
     *
     * Default Credentials:
     * Username: testuser
     * Password: testpass123
     */
    public function run(): void
    {
        // Check if test user already exists
        if (User::where('username', 'testuser')->exists()) {
            $this->command->warn('Test user already exists. Skipping...');
            return;
        }

        // Check if user role exists
        $userRole = \Spatie\Permission\Models\Role::where('name', 'user')
            ->where('guard_name', 'sanctum')
            ->first();
        if (!$userRole) {
            $this->command->error('User role not found. Run RolePermissionSeeder first.');
            return;
        }

        // Look up first rank and location dynamically — never hardcode IDs
        // Ranks are optional (provided by Progression plugin)
        $firstRank = null;
        if (DB::getSchemaBuilder()->hasTable('ranks')) {
            $firstRank = DB::table('ranks')->orderBy('required_exp')->first();
        }
        $firstLocation = null;
        if (DB::getSchemaBuilder()->hasTable('locations')) {
            $firstLocation = DB::table('locations')->orderBy('id')->first();
        }

        $this->command->info('Creating test user...');

        // Create user with identity fields only (game stats go to PlayerProfile)
        $user = User::create([
            'name'                  => 'Test User',
            'username'              => 'testuser',
            'email'                 => 'testuser@example.com',
            'password'              => Hash::make('testpass123'),
            'email_verified_at'     => now(),
            'force_password_change' => false,
        ]);

        // User::booted() auto-creates a profile with defaults.
        // Update the profile with proper game stats if ranks/locations exist.
        $profileData = [
            'level'       => 1,
            'experience'  => 0,
            'energy'      => 100,
            'max_energy'  => 100,
            'health'      => 100,
            'max_health'  => 100,
            'cash'        => 1000,
            'bank'        => 0,
            'bullets'     => 50,
            'respect'     => 0,
        ];

        if ($firstRank) {
            $profileData['rank_id'] = $firstRank->id;
            $profileData['rank'] = $firstRank->name;
            $profileData['health'] = $firstRank->max_health ?? 100;
            $profileData['max_health'] = $firstRank->max_health ?? 100;
        }

        if ($firstLocation) {
            $profileData['location_id'] = $firstLocation->id;
            $profileData['location'] = $firstLocation->name;
        }

        $user->profile()->update($profileData);

        // Assign default user role
        $user->assignRole('user');

        $this->command->info('✅ Test user created successfully!');
        $this->command->newLine();
        $this->command->warn('⚠️  TEST USER CREDENTIALS:');
        $this->command->line('   Username: testuser');
        $this->command->line('   Email: testuser@example.com');
        $this->command->line('   Password: testpass123');
    }
}
