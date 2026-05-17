<?php

namespace App\Plugins\SportsBot\Contracts;

interface NotifierInterface
{
    public function send(string $message, array $options = []): array;
}
