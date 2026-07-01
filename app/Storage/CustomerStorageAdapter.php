<?php

declare(strict_types=1);

namespace App\Storage;

final class CustomerStorageAdapter
{
    public function load(): array
    {
        return customer_load();
    }

    public function save(array $data): void
    {
        customer_save($data);
    }
}
