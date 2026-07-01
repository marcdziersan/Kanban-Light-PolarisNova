<?php

declare(strict_types=1);

namespace App\Core;

final class ArrayHelper
{
    public static function nextId(array $rows): int
    {
        $max = 0;

        foreach ($rows as $row) {
            $max = max($max, (int)($row['id'] ?? 0));
        }

        return $max + 1;
    }

    public static function indexById(array $rows, int $id): int
    {
        foreach ($rows as $index => $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                return (int)$index;
            }
        }

        return -1;
    }
}
