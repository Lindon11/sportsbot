<?php

namespace App\Plugins\SportsBot\Contracts;

interface SportsDataProviderInterface
{
    public function fetchLiveScores(): array;
}
