<?php

declare(strict_types=1);

namespace App\Storage;

final class KanbanStorageAdapter
{
    public function load(): array
    {
        return storage_load();
    }

    public function save(array $data): void
    {
        storage_save($data);
    }

    public function isDatabaseAvailable(): bool
    {
        return storage_db_available();
    }

    public function importJsonSafely(array $incoming, array $current, array $user, string $mode): array
    {
        return storage_import_json_safely($incoming, $current, $user, $mode);
    }

    public function previewJsonFileToMysql(): array
    {
        return storage_preview_json_file_to_mysql();
    }

    public function restoreJsonFileToMysqlSafely(array $user, string $policy): array
    {
        return storage_restore_json_file_to_mysql_safely($user, $policy);
    }
}
