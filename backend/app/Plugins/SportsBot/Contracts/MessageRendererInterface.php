<?php

namespace App\Plugins\SportsBot\Contracts;

interface MessageRendererInterface
{
    public function render(array $alert): string;
}
