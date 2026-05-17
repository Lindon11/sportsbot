<?php

namespace App\Plugins\SportsBot\Contracts;

interface SportsBotContentModuleInterface
{
    public function key(): string;

    public function label(): string;

    public function routeKey(): string;

    /**
     * @return array<string, mixed>
     */
    public function buildSummary(): array;

    /**
     * @param array<string, mixed> $summary
     */
    public function format(array $summary): string;

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public function telegramOptions(array $summary): array;
}
