<?php

declare(strict_types=1);

namespace App\Core;

final class Clock
{
    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
