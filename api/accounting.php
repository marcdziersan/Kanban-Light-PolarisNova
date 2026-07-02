<?php
/**
 * PolarisNova Rechnungen/EÜR API.
 *
 * Zweck:
 * - Rechnungsentwürfe aus vorhandenen Zeitbuchungen erzeugen.
 * - Rechnungsstatus pflegen.
 * - Vorbereitende EÜR-Auswertungen je Mitarbeiter und gesamt bereitstellen.
 * - Manuelle EÜR-Einnahmen/Ausgaben speichern.
 *
 * Hinweis: Das Modul erzeugt eine interne vorbereitende Auswertung. Es ersetzt
 * keine steuerliche Beratung und keine amtliche Anlage EÜR.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function accounting_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function accounting_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        accounting_out(['ok' => false, 'error' => 'Ungültiger JSON-Request'], 400);
    }

    return $data;
}

function accounting_next_id(array $rows): int
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (int)($row['id'] ?? 0));
    }
    return $max + 1;
}

function accounting_money($value): float
{
    return round((float)str_replace(',', '.', (string)$value), 2);
}

function accounting_now(): string
{
    return date('Y-m-d H:i:s');
}

function accounting_today(): string
{
    return date('Y-m-d');
}

function accounting_user(array $d): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    foreach ($d['users'] ?? [] as $u) {
        if ((int)$u['id'] === (int)$_SESSION['user_id']) {
            return $u;
        }
    }

    return null;
}

function accounting_is_admin(array $u): bool
{
    return ($u['role'] ?? '') === 'admin';
}

function accounting_require_admin(array $u): void
{
    if (!accounting_is_admin($u)) {
        accounting_out(['ok' => false, 'error' => 'Nur Admins dürfen Rechnungen und EÜR-Buchungen verwalten'], 403);
    }
}

function accounting_index_by_id(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $out[$id] = $row;
        }
    }
    return $out;
}

function accounting_customer_by_project(array $customers): array
{
    $map = [];
    foreach ($customers as $customer) {
        foreach (($customer['project_ids'] ?? []) as $projectId) {
            $pid = (int)$projectId;
            if ($pid > 0 && !isset($map[$pid])) {
                $map[$pid] = $customer;
            }
        }
    }
    return $map;
}

function accounting_invoice_number(array $invoices, string $date): string
{
    $year = substr($date, 0, 4) ?: date('Y');
    $max = 0;

    foreach ($invoices as $invoice) {
        $number = (string)($invoice['number'] ?? '');
        if (preg_match('/^RE-' . preg_quote($year, '/') . '-(\d+)$/', $number, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }

    return 'RE-' . $year . '-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function accounting_enriched_time_rows(array $d, array $customers, array $u): array
{
    $users = accounting_index_by_id($d['users'] ?? []);
    $projects = accounting_index_by_id($d['projects'] ?? []);
    $boards = accounting_index_by_id($d['boards'] ?? []);
    $columns = accounting_index_by_id($d['columns'] ?? []);
    $tasks = accounting_index_by_id($d['tasks'] ?? []);
    $customerByProject = accounting_customer_by_project($customers);

    $invoiceStatusById = [];
    foreach ($d['invoices'] ?? [] as $invoice) {
        $invoiceStatusById[(int)($invoice['id'] ?? 0)] = (string)($invoice['status'] ?? 'draft');
    }

    $invoicedByTime = [];
    foreach ($d['invoice_items'] ?? [] as $item) {
        $timeId = (int)($item['time_entry_id'] ?? 0);
        $invoiceId = (int)($item['invoice_id'] ?? 0);
        if ($timeId > 0 && ($invoiceStatusById[$invoiceId] ?? '') !== 'cancelled') {
            $invoicedByTime[$timeId] = $invoiceId;
        }
    }

    $rows = [];
    foreach ($d['time_entries'] ?? [] as $entry) {
        $entryUserId = (int)($entry['user_id'] ?? 0);
        if (!accounting_is_admin($u) && $entryUserId !== (int)$u['id']) {
            continue;
        }

        $taskId = (int)($entry['task_id'] ?? 0);
        $task = $tasks[$taskId] ?? null;
        if (!$task) {
            continue;
        }

        $column = $columns[(int)($task['column_id'] ?? 0)] ?? null;
        $board = $column ? ($boards[(int)($column['board_id'] ?? 0)] ?? null) : null;
        $project = $board ? ($projects[(int)($board['project_id'] ?? 0)] ?? null) : null;
        if (!$project) {
            continue;
        }

        $projectId = (int)$project['id'];
        $customer = $customerByProject[$projectId] ?? null;
        $seconds = (int)($entry['seconds'] ?? 0);
        if ($seconds <= 0 && !empty($entry['started_at']) && empty($entry['stopped_at'])) {
            $start = strtotime((string)$entry['started_at']);
            if ($start !== false) {
                $seconds = max(0, time() - $start);
            }
        }

        $timeId = (int)($entry['id'] ?? 0);
        $rows[] = [
            'id' => $timeId,
            'task_id' => $taskId,
            'task_title' => $task['title'] ?? ('Aufgabe #' . $taskId),
            'user_id' => $entryUserId,
            'user_name' => $users[$entryUserId]['username'] ?? ('Benutzer #' . $entryUserId),
            'project_id' => $projectId,
            'project_name' => $project['name'] ?? ('Projekt #' . $projectId),
            'board_id' => (int)($board['id'] ?? 0),
            'board_name' => $board['name'] ?? '',
            'customer_id' => $customer ? (int)$customer['id'] : null,
            'customer_name' => $customer['company'] ?? null,
            'started_at' => $entry['started_at'] ?? null,
            'stopped_at' => $entry['stopped_at'] ?? null,
            'work_date' => substr((string)($entry['started_at'] ?? ''), 0, 10),
            'seconds' => $seconds,
            'hours' => round($seconds / 3600, 2),
            'invoiced' => isset($invoicedByTime[$timeId]),
            'invoice_id' => $invoicedByTime[$timeId] ?? null,
        ];
    }

    usort($rows, static fn($a, $b) => strcmp((string)($b['started_at'] ?? ''), (string)($a['started_at'] ?? '')));
    return $rows;
}

function accounting_filter_payload_for_user(array $d, array $customers, array $u): array
{
    $isAdmin = accounting_is_admin($u);
    $uid = (int)$u['id'];

    $invoiceIdsForUser = [];
    foreach ($d['invoice_items'] ?? [] as $item) {
        if ($isAdmin || (int)($item['user_id'] ?? 0) === $uid) {
            $invoiceIdsForUser[(int)($item['invoice_id'] ?? 0)] = true;
        }
    }

    $invoices = array_values(array_filter($d['invoices'] ?? [], static function ($invoice) use ($isAdmin, $invoiceIdsForUser): bool {
        return $isAdmin || isset($invoiceIdsForUser[(int)($invoice['id'] ?? 0)]);
    }));

    $items = array_values(array_filter($d['invoice_items'] ?? [], static function ($item) use ($isAdmin, $uid): bool {
        return $isAdmin || (int)($item['user_id'] ?? 0) === $uid;
    }));

    $eur = array_values(array_filter($d['eur_entries'] ?? [], static function ($entry) use ($isAdmin, $uid): bool {
        return $isAdmin || (int)($entry['user_id'] ?? 0) === $uid;
    }));

    $safeUsers = array_values(array_map(static fn($user) => [
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'role' => (string)($user['role'] ?? 'user'),
        'is_active' => (bool)($user['is_active'] ?? true),
    ], $d['users'] ?? []));

    return [
        'user' => [
            'id' => $uid,
            'username' => $u['username'] ?? '',
            'role' => $u['role'] ?? 'user',
        ],
        'users' => $isAdmin ? $safeUsers : array_values(array_filter($safeUsers, static fn($row) => (int)$row['id'] === $uid)),
        'customers' => $isAdmin ? ($customers ?? []) : [],
        'projects' => $d['projects'] ?? [],
        'invoices' => $invoices,
        'invoice_items' => $items,
        'eur_entries' => $eur,
        'time_rows' => accounting_enriched_time_rows($d, $customers, $u),
    ];
}

try {
    $data = storage_load();
    foreach (['invoices', 'invoice_items', 'eur_entries'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    $user = accounting_user($data);
    if (!$user) {
        accounting_out(['ok' => false, 'error' => 'Nicht angemeldet'], 401);
    }
    if (!empty($user['is_active']) === false) {
        accounting_out(['ok' => false, 'error' => 'Benutzer ist gesperrt'], 403);
    }

    $customersData = customer_load();
    $customers = $customersData['customers'] ?? [];

    $action = $_GET['action'] ?? 'bootstrap';

    switch ($action) {
        case 'bootstrap':
            accounting_out(['ok' => true, 'data' => accounting_filter_payload_for_user($data, $customers, $user)]);
            break;

        case 'create_invoice_from_times':
            accounting_require_admin($user);
            $in = accounting_body();
            $timeIds = array_values(array_unique(array_map('intval', $in['time_entry_ids'] ?? [])));
            if (!$timeIds) {
                accounting_out(['ok' => false, 'error' => 'Keine Zeiten ausgewählt'], 422);
            }

            $timeRowsById = [];
            foreach (accounting_enriched_time_rows($data, $customers, $user) as $row) {
                $timeRowsById[(int)$row['id']] = $row;
            }

            $selected = [];
            foreach ($timeIds as $timeId) {
                if (!isset($timeRowsById[$timeId])) {
                    continue;
                }
                if (!empty($timeRowsById[$timeId]['invoiced'])) {
                    continue;
                }
                $selected[] = $timeRowsById[$timeId];
            }

            if (!$selected) {
                accounting_out(['ok' => false, 'error' => 'Die ausgewählten Zeiten sind nicht verfügbar oder bereits abgerechnet'], 422);
            }

            $invoiceDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['invoice_date'] ?? '')) ? (string)$in['invoice_date'] : accounting_today();
            $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['due_date'] ?? '')) ? (string)$in['due_date'] : date('Y-m-d', strtotime($invoiceDate . ' +14 days'));
            $vatRate = accounting_money($in['vat_rate'] ?? 19);
            $hourlyRate = accounting_money($in['hourly_rate'] ?? 60);
            $customerId = (int)($in['customer_id'] ?? ($selected[0]['customer_id'] ?? 0));
            $projectId = (int)($in['project_id'] ?? ($selected[0]['project_id'] ?? 0));

            $customersById = accounting_index_by_id($customers);
            $projectsById = accounting_index_by_id($data['projects'] ?? []);
            $customerName = $customersById[$customerId]['company'] ?? ($selected[0]['customer_name'] ?? null);
            $projectName = $projectsById[$projectId]['name'] ?? ($selected[0]['project_name'] ?? null);

            $invoiceId = accounting_next_id($data['invoices']);
            $itemId = accounting_next_id($data['invoice_items']);
            $netTotal = 0.0;
            $position = 1;

            foreach ($selected as $row) {
                $hours = round(max(0, (int)$row['seconds']) / 3600, 2);
                $net = round($hours * $hourlyRate, 2);
                $netTotal += $net;
                $data['invoice_items'][] = [
                    'id' => $itemId++,
                    'invoice_id' => $invoiceId,
                    'position' => $position++,
                    'time_entry_id' => (int)$row['id'],
                    'task_id' => (int)$row['task_id'],
                    'task_title' => $row['task_title'],
                    'user_id' => (int)$row['user_id'],
                    'user_name' => $row['user_name'],
                    'project_id' => (int)$row['project_id'],
                    'project_name' => $row['project_name'],
                    'work_date' => $row['work_date'],
                    'description' => trim(($row['task_title'] ?? 'Arbeitszeit') . ' · ' . ($row['user_name'] ?? '') . ' · ' . ($row['work_date'] ?? '')),
                    'quantity_hours' => $hours,
                    'unit_price' => $hourlyRate,
                    'net_amount' => $net,
                ];
            }

            $vatAmount = round($netTotal * ($vatRate / 100), 2);
            $grossTotal = round($netTotal + $vatAmount, 2);

            $data['invoices'][] = [
                'id' => $invoiceId,
                'number' => accounting_invoice_number($data['invoices'], $invoiceDate),
                'customer_id' => $customerId > 0 ? $customerId : null,
                'customer_name' => $customerName,
                'project_id' => $projectId > 0 ? $projectId : null,
                'project_name' => $projectName,
                'title' => trim((string)($in['title'] ?? 'Leistungsabrechnung')),
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'status' => 'draft',
                'net_amount' => $netTotal,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'gross_amount' => $grossTotal,
                'paid_at' => null,
                'notes' => trim((string)($in['notes'] ?? '')),
                'created_by' => (int)$user['id'],
                'created_at' => accounting_now(),
                'updated_at' => accounting_now(),
            ];

            storage_save($data);
            $fresh = storage_load();
            accounting_out(['ok' => true, 'data' => accounting_filter_payload_for_user($fresh, $customers, $user), 'invoice_id' => $invoiceId]);
            break;

        case 'save_invoice_status':
            accounting_require_admin($user);
            $in = accounting_body();
            $id = (int)($in['id'] ?? 0);
            $status = (string)($in['status'] ?? 'draft');
            if (!in_array($status, ['draft', 'sent', 'paid', 'cancelled'], true)) {
                accounting_out(['ok' => false, 'error' => 'Ungültiger Rechnungsstatus'], 422);
            }

            $found = false;
            foreach ($data['invoices'] as &$invoice) {
                if ((int)$invoice['id'] !== $id) {
                    continue;
                }
                $found = true;
                $invoice['status'] = $status;
                $invoice['updated_at'] = accounting_now();
                if ($status === 'paid') {
                    $invoice['paid_at'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['paid_at'] ?? '')) ? (string)$in['paid_at'] : accounting_today();
                } elseif ($status !== 'paid') {
                    $invoice['paid_at'] = null;
                }
                $invoice['notes'] = array_key_exists('notes', $in) ? trim((string)$in['notes']) : ($invoice['notes'] ?? null);
                break;
            }
            unset($invoice);

            if (!$found) {
                accounting_out(['ok' => false, 'error' => 'Rechnung nicht gefunden'], 404);
            }

            storage_save($data);
            $fresh = storage_load();
            accounting_out(['ok' => true, 'data' => accounting_filter_payload_for_user($fresh, $customers, $user)]);
            break;

        case 'delete_invoice':
            accounting_require_admin($user);
            $in = accounting_body();
            $id = (int)($in['id'] ?? 0);
            $data['invoice_items'] = array_values(array_filter($data['invoice_items'] ?? [], static fn($item) => (int)($item['invoice_id'] ?? 0) !== $id));
            $data['invoices'] = array_values(array_filter($data['invoices'] ?? [], static fn($invoice) => (int)($invoice['id'] ?? 0) !== $id));
            storage_save($data);
            $fresh = storage_load();
            accounting_out(['ok' => true, 'data' => accounting_filter_payload_for_user($fresh, $customers, $user)]);
            break;

        case 'save_eur_entry':
            accounting_require_admin($user);
            $in = accounting_body();
            $id = (int)($in['id'] ?? 0);
            $type = in_array(($in['type'] ?? 'expense'), ['income', 'expense'], true) ? $in['type'] : 'expense';
            $net = accounting_money($in['amount_net'] ?? 0);
            $vatRate = accounting_money($in['vat_rate'] ?? 19);
            $vat = round($net * ($vatRate / 100), 2);
            $gross = round($net + $vat, 2);
            $userId = ($in['user_id'] ?? '') === '' ? null : (int)$in['user_id'];
            $usersById = accounting_index_by_id($data['users'] ?? []);

            $row = [
                'id' => $id > 0 ? $id : accounting_next_id($data['eur_entries']),
                'entry_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['entry_date'] ?? '')) ? (string)$in['entry_date'] : accounting_today(),
                'type' => $type,
                'category' => trim((string)($in['category'] ?? 'Sonstiges')),
                'description' => trim((string)($in['description'] ?? '')),
                'user_id' => $userId,
                'user_name' => $userId ? ($usersById[$userId]['username'] ?? ('Benutzer #' . $userId)) : null,
                'invoice_id' => ($in['invoice_id'] ?? '') === '' ? null : (int)$in['invoice_id'],
                'amount_net' => $net,
                'vat_rate' => $vatRate,
                'vat_amount' => $vat,
                'amount_gross' => $gross,
                'created_by' => (int)$user['id'],
                'created_at' => accounting_now(),
            ];

            $updated = false;
            foreach ($data['eur_entries'] as &$entry) {
                if ((int)$entry['id'] === $id && $id > 0) {
                    $entry = $row;
                    $updated = true;
                    break;
                }
            }
            unset($entry);
            if (!$updated) {
                $data['eur_entries'][] = $row;
            }

            storage_save($data);
            $fresh = storage_load();
            accounting_out(['ok' => true, 'data' => accounting_filter_payload_for_user($fresh, $customers, $user)]);
            break;

        case 'delete_eur_entry':
            accounting_require_admin($user);
            $in = accounting_body();
            $id = (int)($in['id'] ?? 0);
            $data['eur_entries'] = array_values(array_filter($data['eur_entries'] ?? [], static fn($entry) => (int)($entry['id'] ?? 0) !== $id));
            storage_save($data);
            $fresh = storage_load();
            accounting_out(['ok' => true, 'data' => accounting_filter_payload_for_user($fresh, $customers, $user)]);
            break;

        default:
            accounting_out(['ok' => false, 'error' => 'Unbekannte Accounting-Action'], 404);
    }
} catch (Throwable $e) {
    accounting_out(['ok' => false, 'error' => 'Accounting-Fehler: ' . $e->getMessage()], 500);
}
