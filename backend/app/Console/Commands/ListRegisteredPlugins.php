<?php

namespace App\Console\Commands;

use App\Core\Models\InstalledPlugin;
use Illuminate\Console\Command;

class ListRegisteredPlugins extends Command
{
    protected $signature = 'plugins:list-registered';
    protected $description = 'List all registered plugins and their status';

    public function handle()
    {
        $plugins = InstalledPlugin::all();
        if ($plugins->isEmpty()) {
            $this->info('No plugins registered in the database.');
            return 0;
        }
        $this->table(
            ['Name', 'Slug', 'Version', 'Enabled', 'Installed At'],
            $plugins->map(function ($plugin) {
                return [
                    $plugin->name,
                    $plugin->slug,
                    $plugin->version,
                    $plugin->enabled ? 'Yes' : 'No',
                    $plugin->installed_at,
                ];
            })
        );
        return 0;
    }
}
