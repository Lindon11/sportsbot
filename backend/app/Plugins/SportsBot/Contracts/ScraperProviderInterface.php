<?php

namespace App\Plugins\SportsBot\Contracts;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;

interface ScraperProviderInterface
{
    public function key(): string;

    public function supports(SportsBotFixtureQueue $entry, string $action): bool;

    /**
     * @return array<string, mixed>
     */
    public function scrape(SportsBotFixtureQueue $entry, string $action): array;
}
