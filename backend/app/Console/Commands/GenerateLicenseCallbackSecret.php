<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class GenerateLicenseCallbackSecret extends Command
{
    protected $signature = 'license:generate-secret';
    protected $description = 'Generate a secure LICENSE_CALLBACK_SECRET and set it in your .env file';

    public function handle()
    {
        $secret = Str::random(64);
        $envPath = base_path('.env');

        if (!File::exists($envPath)) {
            $this->error('.env file not found.');
            return 1;
        }

        // Backup .env before modification
        File::copy($envPath, $envPath . '.backup');

        $envContent = File::get($envPath);
        $pattern = '/^LICENSE_CALLBACK_SECRET=.*/m';
        $line = 'LICENSE_CALLBACK_SECRET=' . $secret;

        if (preg_match($pattern, $envContent)) {
            // Use preg_replace_callback so the replacement is treated as a literal string
            $envContent = preg_replace_callback($pattern, fn() => $line, $envContent);
        } else {
            $envContent .= "\n$line";
        }

        File::put($envPath, $envContent);
        $this->info('LICENSE_CALLBACK_SECRET generated and set in your .env file.');
        $this->line($secret);
        return 0;
    }
}
