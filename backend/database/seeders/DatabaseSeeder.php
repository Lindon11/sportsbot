<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Core seeders only - gaming content seeders are provided by plugins.
     */
    public function run(): void
    {
        // Core seeders (always run)
        $this->call([
            RolePermissionSeeder::class,
            SettingsTableSeeder::class,
            PluginSeeder::class,
            ModuleSeeder::class,
            // TicketCategorySeeder::class, // Moved to Tickets plugin

            // User accounts for development/testing
            DefaultAdminSeeder::class,
            TestUserSeeder::class,
        ]);

        // NOTE: Gaming content seeders have been moved to plugins.
        // The following seeders are now provided by the gaming plugin bundle:
        // - CrimeSeeder::class (Crimes plugin)
        // - TheftSeeder::class (Theft plugin)
        // - DrugSeeder::class (Drugs plugin)
        // - ItemSeeder::class (Inventory plugin)
        // - PropertySeeder::class (Properties plugin)
        // - OrganizedCrimeSeeder::class (OrganizedCrime plugin)
        // - MissionSeeder::class (Missions plugin)
        // - AchievementSeeder::class (Achievements plugin)
        // - ChatChannelSeeder::class (Chat plugin)
        // - ForumSeeder::class (Forum plugin)
        // - JobsAndCompaniesSeeder::class (Employment plugin)
        // - EducationCoursesSeeder::class (Education plugin)
        // - StockMarketSeeder::class (Stocks plugin)
        // - CasinoGamesSeeder::class (Casino plugin)
        // - CombatLocationsSeeder::class (Combat plugin)
    }
}
