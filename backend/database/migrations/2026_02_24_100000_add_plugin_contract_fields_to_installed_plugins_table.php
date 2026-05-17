<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration adds fields from the PLUGIN_CONTRACT.md to the installed_plugins table,
     * consolidating the plugin tracking to a single model.
     */
    public function up(): void
    {
        Schema::table('installed_plugins', function (Blueprint $table) {
            // Core plugin metadata from plugin.json
            $table->string('author')->nullable()->after('description');
            $table->boolean('license_required')->default(false)->after('dependencies');

            // UI/Navigation settings (from settings object in plugin.json)
            $table->string('icon')->nullable()->after('config');
            $table->string('color')->nullable()->after('icon');
            $table->string('route_name')->nullable()->after('color');
            $table->integer('order')->default(100)->after('route_name');

            // Permissions and hooks (stored as JSON)
            $table->json('permissions')->nullable()->after('order');
            $table->json('hooks')->nullable()->after('permissions');

            // Frontend integration
            $table->json('frontend_slots')->nullable()->after('hooks');
            $table->json('frontend_routes')->nullable()->after('frontend_slots');

            // Route configuration
            $table->boolean('has_web_routes')->default(false)->after('frontend_routes');
            $table->boolean('has_api_routes')->default(false)->after('has_web_routes');
            $table->boolean('has_admin_routes')->default(false)->after('has_api_routes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('installed_plugins', function (Blueprint $table) {
            $table->dropColumn([
                'author',
                'license_required',
                'icon',
                'color',
                'route_name',
                'order',
                'permissions',
                'hooks',
                'frontend_slots',
                'frontend_routes',
                'has_web_routes',
                'has_api_routes',
                'has_admin_routes',
            ]);
        });
    }
};
