<?php

declare(strict_types=1);

namespace App\Core;

final class JsonResponse
{
    public static function prepare(): void
    {
        header('Content-Type: application/json; charset=utf-8');
    }

    public static function send(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function download(array $data, string $filename): void
    {
        header_remove('Content-Type');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
