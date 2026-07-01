<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function action(): string
    {
        return (string)($_GET['action'] ?? '');
    }

    public function intQuery(string $key, int $default = 0): int
    {
        return (int)($_GET[$key] ?? $default);
    }

    public function jsonBody(): array
    {
        $raw = file_get_contents('php://input');

        if (!$raw) {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            JsonResponse::send(['ok' => false, 'error' => 'Ungültiges JSON'], 400);
        }

        return $data;
    }
}
