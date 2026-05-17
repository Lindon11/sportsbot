<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates a polymorphic metadata table that allows plugins to store
     * custom data on any model (User, Player, etc.) without modifying
     * the core schema.
     *
     * Usage:
     *   $user->setPluginMeta('rpg', 'gold', 100);
     *   $user->getPluginMeta('rpg', 'gold', 0);
     */
    public function up(): void
    {
        Schema::create('plugin_metadata', function (Blueprint $table) {
            $table->id();

            // Polymorphic relationship - allows attaching to any model
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->index(['owner_type', 'owner_id'], 'plugin_metadata_owner_index');

            // Plugin identifier and key-value storage
            $table->string('plugin_id', 100)->comment('Plugin slug (e.g., rpg, crimes)');
            $table->string('key', 191)->comment('Metadata key (e.g., gold, level)');
            $table->json('value')->nullable()->comment('JSON-encoded value');

            $table->timestamps();

            // Unique constraint: one value per plugin/key/owner combination
            $table->unique(
                ['owner_type', 'owner_id', 'plugin_id', 'key'],
                'plugin_metadata_unique'
            );

            // Index for quick plugin lookups
            $table->index(['plugin_id', 'key'], 'plugin_metadata_plugin_key_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plugin_metadata');
    }
};
