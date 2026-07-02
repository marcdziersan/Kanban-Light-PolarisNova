<?php
/**
 * PolarisNova Ticketsystem-API.
 *
 * Funktionen:
 * - Nutzer/Mitarbeiter melden Fehler oder Änderungsbedarf.
 * - Auswahl von Bereich, Projekt und optional Aufgabe.
 * - Admins verwalten Tickets in einem internen Kanban-Board.
 * - Bearbeitungsstatus erzeugt Rückmeldung an den Ticketersteller über PM.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function tickets_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tickets_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        tickets_out(['ok' => false, 'error' => 'Ungültiger JSON-Request'], 400);
    }
    return $data;
}

function tickets_now(): string
{
    return date('Y-m-d H:i:s');
}

function tickets_next_id(array $rows): int
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (int)($row['id'] ?? 0));
    }
    return $max + 1;
}

function tickets_clean_text(string $text, int $max): string
{
    $text = trim($text);
    if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
        return mb_substr($text, 0, $max);
    }
    if (strlen($text) > $max) {
        return substr($text, 0, $max);
    }
    return $text;
}

function tickets_index_by_id(array $rows): array
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

function tickets_current_user(array $d): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    foreach ($d['users'] ?? [] as $u) {
        if ((int)($u['id'] ?? 0) === (int)$_SESSION['user_id']) {
            return $u;
        }
    }
    return null;
}

function tickets_is_admin(array $u): bool
{
    return ($u['role'] ?? '') === 'admin';
}

function tickets_can_create(array $u): bool
{
    return in_array(($u['role'] ?? ''), ['admin', 'user'], true) && !empty($u['is_active']);
}

function tickets_project_ids_for_user(array $d, int $userId): array
{
    $ids = [];
    foreach ($d['project_members'] ?? [] as $member) {
        if ((int)($member['user_id'] ?? 0) === $userId) {
            $ids[(int)($member['project_id'] ?? 0)] = true;
        }
    }
    foreach ($d['projects'] ?? [] as $project) {
        if ((int)($project['owner_id'] ?? 0) === $userId || (int)($project['responsible_id'] ?? 0) === $userId) {
            $ids[(int)($project['id'] ?? 0)] = true;
        }
    }
    unset($ids[0]);
    return array_keys($ids);
}

function tickets_can_access_project(array $d, array $u, int $projectId): bool
{
    if ($projectId <= 0) {
        return false;
    }
    $projects = tickets_index_by_id($d['projects'] ?? []);
    if (empty($projects[$projectId])) {
        return false;
    }
    if (tickets_is_admin($u) || ($u['role'] ?? '') === 'guest') {
        return true;
    }
    return in_array($projectId, tickets_project_ids_for_user($d, (int)($u['id'] ?? 0)), true);
}

function tickets_task_project_id(array $d, int $taskId): ?int
{
    $tasks = tickets_index_by_id($d['tasks'] ?? []);
    $columns = tickets_index_by_id($d['columns'] ?? []);
    $boards = tickets_index_by_id($d['boards'] ?? []);

    if (empty($tasks[$taskId])) {
        return null;
    }
    $columnId = (int)($tasks[$taskId]['column_id'] ?? 0);
    $boardId = (int)($columns[$columnId]['board_id'] ?? 0);
    $projectId = (int)($boards[$boardId]['project_id'] ?? 0);

    return $projectId > 0 ? $projectId : null;
}

function tickets_can_access_task(array $d, array $u, int $taskId): bool
{
    $projectId = tickets_task_project_id($d, $taskId);
    if (!$projectId) {
        return false;
    }
    return tickets_can_access_project($d, $u, $projectId);
}

function tickets_visible_projects(array $d, array $u): array
{
    $projects = [];
    foreach ($d['projects'] ?? [] as $project) {
        $id = (int)($project['id'] ?? 0);
        if ($id > 0 && tickets_can_access_project($d, $u, $id)) {
            $projects[] = [
                'id' => $id,
                'name' => (string)($project['name'] ?? ('Projekt #' . $id)),
            ];
        }
    }
    return $projects;
}

function tickets_visible_tasks(array $d, array $u): array
{
    $columns = tickets_index_by_id($d['columns'] ?? []);
    $boards = tickets_index_by_id($d['boards'] ?? []);
    $projects = tickets_index_by_id($d['projects'] ?? []);
    $out = [];

    foreach ($d['tasks'] ?? [] as $task) {
        $taskId = (int)($task['id'] ?? 0);
        if ($taskId <= 0 || !tickets_can_access_task($d, $u, $taskId)) {
            continue;
        }
        $columnId = (int)($task['column_id'] ?? 0);
        $boardId = (int)($columns[$columnId]['board_id'] ?? 0);
        $projectId = (int)($boards[$boardId]['project_id'] ?? 0);
        $out[] = [
            'id' => $taskId,
            'title' => (string)($task['title'] ?? ('Aufgabe #' . $taskId)),
            'project_id' => $projectId,
            'project_name' => $projects[$projectId]['name'] ?? null,
        ];
    }
    return $out;
}

function tickets_area_options(): array
{
    return [
        ['value' => 'kanban', 'label' => 'Kanban / Aufgaben'],
        ['value' => 'customers', 'label' => 'Kundenverwaltung'],
        ['value' => 'accounting', 'label' => 'Rechnungen / EÜR'],
        ['value' => 'pm', 'label' => 'PM-System / Nachrichten'],
        ['value' => 'timesheet', 'label' => 'Monatszettel / Zeiten'],
        ['value' => 'setup', 'label' => 'Setup / Installation'],
        ['value' => 'users', 'label' => 'Benutzer / Rechte'],
        ['value' => 'ui', 'label' => 'Oberfläche / Bedienung'],
        ['value' => 'other', 'label' => 'Sonstiges'],
    ];
}

function tickets_area_label(string $area): string
{
    foreach (tickets_area_options() as $option) {
        if ($option['value'] === $area) {
            return $option['label'];
        }
    }
    return 'Sonstiges';
}

function tickets_status_labels(): array
{
    return [
        'new' => 'Neu',
        'accepted' => 'Angenommen',
        'in_progress' => 'In Bearbeitung',
        'waiting' => 'Rückfrage',
        'done' => 'Erledigt',
        'confirmed' => 'Bestätigt',
        'rejected' => 'Abgelehnt',
    ];
}

function tickets_status_columns(): array
{
    return [
        ['value' => 'new', 'label' => 'Neu'],
        ['value' => 'accepted', 'label' => 'Angenommen'],
        ['value' => 'in_progress', 'label' => 'In Bearbeitung'],
        ['value' => 'waiting', 'label' => 'Rückfrage'],
        ['value' => 'done', 'label' => 'Erledigt'],
        ['value' => 'confirmed', 'label' => 'Bestätigt'],
        ['value' => 'rejected', 'label' => 'Abgelehnt'],
    ];
}

function tickets_create_number(int $id): string
{
    return 'PN-T-' . date('Ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function tickets_visible_ticket(array $u, array $ticket): bool
{
    return tickets_is_admin($u) || (int)($ticket['created_by'] ?? 0) === (int)($u['id'] ?? 0);
}

function tickets_notify_creator(array &$d, array $sender, array $ticket, string $subject, string $content): void
{
    $creatorId = (int)($ticket['created_by'] ?? 0);
    $senderId = (int)($sender['id'] ?? 0);
    if ($creatorId <= 0 || $senderId <= 0 || $creatorId === $senderId) {
        return;
    }

    if (!isset($d['pm_messages']) || !is_array($d['pm_messages'])) {
        $d['pm_messages'] = [];
    }
    if (!isset($d['pm_message_reads']) || !is_array($d['pm_message_reads'])) {
        $d['pm_message_reads'] = [];
    }

    $messageId = tickets_next_id($d['pm_messages']);
    $d['pm_messages'][] = [
        'id' => $messageId,
        'type' => 'direct',
        'project_id' => null,
        'sender_id' => $senderId,
        'recipient_id' => $creatorId,
        'subject' => $subject,
        'content' => $content,
        'created_at' => tickets_now(),
        'updated_at' => null,
    ];

    $d['pm_message_reads'][] = [
        'id' => tickets_next_id($d['pm_message_reads']),
        'message_id' => $messageId,
        'user_id' => $senderId,
        'read_at' => tickets_now(),
    ];
}

function tickets_payload(array $d, array $u): array
{
    foreach (['support_tickets', 'support_ticket_comments', 'pm_messages', 'pm_message_reads'] as $key) {
        if (!isset($d[$key]) || !is_array($d[$key])) {
            $d[$key] = [];
        }
    }

    $users = tickets_index_by_id($d['users'] ?? []);
    $projects = tickets_index_by_id($d['projects'] ?? []);
    $tasks = tickets_index_by_id($d['tasks'] ?? []);
    $statusLabels = tickets_status_labels();

    $commentsByTicket = [];
    foreach ($d['support_ticket_comments'] ?? [] as $comment) {
        $ticketId = (int)($comment['ticket_id'] ?? 0);
        if ($ticketId <= 0) {
            continue;
        }
        if (($comment['visibility'] ?? 'public') === 'admin' && !tickets_is_admin($u)) {
            continue;
        }
        $userId = (int)($comment['user_id'] ?? 0);
        $commentsByTicket[$ticketId][] = [
            'id' => (int)($comment['id'] ?? 0),
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'user_name' => $users[$userId]['username'] ?? ('Benutzer #' . $userId),
            'visibility' => (string)($comment['visibility'] ?? 'public'),
            'content' => (string)($comment['content'] ?? ''),
            'created_at' => (string)($comment['created_at'] ?? ''),
        ];
    }

    $tickets = [];
    foreach ($d['support_tickets'] ?? [] as $ticket) {
        if (!tickets_visible_ticket($u, $ticket)) {
            continue;
        }

        $id = (int)($ticket['id'] ?? 0);
        $creatorId = (int)($ticket['created_by'] ?? 0);
        $adminId = ($ticket['assigned_admin_id'] ?? null) === null ? null : (int)$ticket['assigned_admin_id'];
        $projectId = ($ticket['project_id'] ?? null) === null ? null : (int)$ticket['project_id'];
        $taskId = ($ticket['task_id'] ?? null) === null ? null : (int)$ticket['task_id'];
        $status = (string)($ticket['status'] ?? 'new');
        $area = (string)($ticket['area'] ?? 'other');

        $tickets[] = [
            'id' => $id,
            'ticket_number' => (string)($ticket['ticket_number'] ?? ('PN-T-' . $id)),
            'created_by' => $creatorId,
            'created_by_name' => $users[$creatorId]['username'] ?? ('Benutzer #' . $creatorId),
            'assigned_admin_id' => $adminId,
            'assigned_admin_name' => $adminId ? ($users[$adminId]['username'] ?? ('Admin #' . $adminId)) : null,
            'project_id' => $projectId,
            'project_name' => $projectId ? ($projects[$projectId]['name'] ?? ('Projekt #' . $projectId)) : null,
            'task_id' => $taskId,
            'task_title' => $taskId ? ($tasks[$taskId]['title'] ?? ('Aufgabe #' . $taskId)) : null,
            'area' => $area,
            'area_label' => tickets_area_label($area),
            'priority' => (string)($ticket['priority'] ?? 'normal'),
            'status' => $status,
            'status_label' => $statusLabels[$status] ?? $status,
            'title' => (string)($ticket['title'] ?? ''),
            'expected_result' => (string)($ticket['expected_result'] ?? ''),
            'actual_result' => (string)($ticket['actual_result'] ?? ''),
            'steps' => (string)($ticket['steps'] ?? ''),
            'admin_note' => (string)($ticket['admin_note'] ?? ''),
            'created_at' => (string)($ticket['created_at'] ?? ''),
            'updated_at' => $ticket['updated_at'] ?? null,
            'accepted_at' => $ticket['accepted_at'] ?? null,
            'resolved_at' => $ticket['resolved_at'] ?? null,
            'confirmed_at' => $ticket['confirmed_at'] ?? null,
            'comments' => $commentsByTicket[$id] ?? [],
            'can_admin_update' => tickets_is_admin($u),
            'can_confirm' => !tickets_is_admin($u) && $creatorId === (int)$u['id'] && $status === 'done',
        ];
    }

    usort($tickets, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

    $admins = [];
    foreach ($d['users'] ?? [] as $user) {
        if (($user['role'] ?? '') === 'admin' && !empty($user['is_active'])) {
            $admins[] = ['id' => (int)$user['id'], 'username' => (string)$user['username']];
        }
    }

    return [
        'user' => [
            'id' => (int)$u['id'],
            'username' => (string)($u['username'] ?? ''),
            'role' => (string)($u['role'] ?? 'user'),
            'is_admin' => tickets_is_admin($u),
        ],
        'tickets' => $tickets,
        'projects' => tickets_visible_projects($d, $u),
        'tasks' => tickets_visible_tasks($d, $u),
        'admins' => $admins,
        'areas' => tickets_area_options(),
        'status_columns' => tickets_status_columns(),
        'priorities' => [
            ['value' => 'low', 'label' => 'Niedrig'],
            ['value' => 'normal', 'label' => 'Normal'],
            ['value' => 'high', 'label' => 'Hoch'],
            ['value' => 'critical', 'label' => 'Kritisch'],
        ],
    ];
}

try {
    $data = storage_load();
    foreach (['support_tickets', 'support_ticket_comments', 'pm_messages', 'pm_message_reads'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    $user = tickets_current_user($data);
    if (!$user) {
        tickets_out(['ok' => false, 'error' => 'Nicht angemeldet'], 401);
    }
    if (empty($user['is_active'])) {
        tickets_out(['ok' => false, 'error' => 'Benutzer ist gesperrt'], 403);
    }

    $action = $_GET['action'] ?? 'bootstrap';

    switch ($action) {
        case 'bootstrap':
            tickets_out(['ok' => true, 'data' => tickets_payload($data, $user)]);
            break;

        case 'create_ticket':
            if (!tickets_can_create($user)) {
                tickets_out(['ok' => false, 'error' => 'Keine Rechte zum Erstellen von Tickets'], 403);
            }

            $in = tickets_body();
            $validAreas = array_column(tickets_area_options(), 'value');
            $area = in_array(($in['area'] ?? 'other'), $validAreas, true) ? (string)$in['area'] : 'other';
            $priority = in_array(($in['priority'] ?? 'normal'), ['low', 'normal', 'high', 'critical'], true) ? (string)$in['priority'] : 'normal';
            $title = tickets_clean_text((string)($in['title'] ?? ''), 190);
            $expected = tickets_clean_text((string)($in['expected_result'] ?? ''), 6000);
            $actual = tickets_clean_text((string)($in['actual_result'] ?? ''), 6000);
            $steps = tickets_clean_text((string)($in['steps'] ?? ''), 8000);

            if ($title === '' || $actual === '') {
                tickets_out(['ok' => false, 'error' => 'Titel und Ist-Beschreibung sind Pflicht'], 422);
            }

            $projectId = (int)($in['project_id'] ?? 0);
            if ($projectId > 0 && !tickets_can_access_project($data, $user, $projectId)) {
                tickets_out(['ok' => false, 'error' => 'Kein Zugriff auf dieses Projekt'], 403);
            }
            $taskId = (int)($in['task_id'] ?? 0);
            if ($taskId > 0) {
                if (!tickets_can_access_task($data, $user, $taskId)) {
                    tickets_out(['ok' => false, 'error' => 'Kein Zugriff auf diese Aufgabe'], 403);
                }
                $taskProjectId = tickets_task_project_id($data, $taskId);
                if ($projectId <= 0 && $taskProjectId) {
                    $projectId = $taskProjectId;
                }
            }

            $id = tickets_next_id($data['support_tickets']);
            $now = tickets_now();
            $ticket = [
                'id' => $id,
                'ticket_number' => tickets_create_number($id),
                'created_by' => (int)$user['id'],
                'assigned_admin_id' => null,
                'project_id' => $projectId > 0 ? $projectId : null,
                'task_id' => $taskId > 0 ? $taskId : null,
                'area' => $area,
                'priority' => $priority,
                'status' => 'new',
                'title' => $title,
                'expected_result' => $expected,
                'actual_result' => $actual,
                'steps' => $steps,
                'admin_note' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'accepted_at' => null,
                'resolved_at' => null,
                'confirmed_at' => null,
            ];
            $data['support_tickets'][] = $ticket;
            $data['support_ticket_comments'][] = [
                'id' => tickets_next_id($data['support_ticket_comments']),
                'ticket_id' => $id,
                'user_id' => (int)$user['id'],
                'visibility' => 'public',
                'content' => "Ticket erstellt.\n\nIst-Zustand:\n" . $actual . ($expected !== '' ? "\n\nSoll-Zustand:\n" . $expected : '') . ($steps !== '' ? "\n\nSchritte:\n" . $steps : ''),
                'created_at' => $now,
            ];

            storage_save($data);
            $fresh = storage_load();
            tickets_out(['ok' => true, 'data' => tickets_payload($fresh, $user), 'ticket_id' => $id]);
            break;

        case 'update_ticket':
            if (!tickets_is_admin($user)) {
                tickets_out(['ok' => false, 'error' => 'Nur Admins dürfen Tickets bearbeiten'], 403);
            }

            $in = tickets_body();
            $ticketId = (int)($in['id'] ?? 0);
            $newStatus = in_array(($in['status'] ?? ''), array_keys(tickets_status_labels()), true) ? (string)$in['status'] : null;
            $newPriority = in_array(($in['priority'] ?? ''), ['low', 'normal', 'high', 'critical'], true) ? (string)$in['priority'] : null;
            $adminNote = tickets_clean_text((string)($in['admin_note'] ?? ''), 8000);
            $assignedAdminId = (int)($in['assigned_admin_id'] ?? 0);
            $comment = tickets_clean_text((string)($in['comment'] ?? ''), 8000);

            $users = tickets_index_by_id($data['users'] ?? []);
            if ($assignedAdminId > 0 && (empty($users[$assignedAdminId]) || ($users[$assignedAdminId]['role'] ?? '') !== 'admin')) {
                tickets_out(['ok' => false, 'error' => 'Zugewiesener Admin existiert nicht'], 422);
            }

            $changed = false;
            $updatedTicket = null;
            foreach ($data['support_tickets'] as &$ticket) {
                if ((int)($ticket['id'] ?? 0) !== $ticketId) {
                    continue;
                }

                $oldStatus = (string)($ticket['status'] ?? 'new');
                if ($newStatus !== null && $newStatus !== $oldStatus) {
                    $ticket['status'] = $newStatus;
                    if ($newStatus === 'accepted' && empty($ticket['accepted_at'])) {
                        $ticket['accepted_at'] = tickets_now();
                    }
                    if ($newStatus === 'done' && empty($ticket['resolved_at'])) {
                        $ticket['resolved_at'] = tickets_now();
                    }
                    $changed = true;
                }
                if ($newPriority !== null && $newPriority !== ($ticket['priority'] ?? 'normal')) {
                    $ticket['priority'] = $newPriority;
                    $changed = true;
                }
                if ($assignedAdminId > 0 && $assignedAdminId !== (int)($ticket['assigned_admin_id'] ?? 0)) {
                    $ticket['assigned_admin_id'] = $assignedAdminId;
                    $changed = true;
                } elseif ($assignedAdminId <= 0 && !empty($ticket['assigned_admin_id'])) {
                    $ticket['assigned_admin_id'] = null;
                    $changed = true;
                }
                if ($adminNote !== (string)($ticket['admin_note'] ?? '')) {
                    $ticket['admin_note'] = $adminNote !== '' ? $adminNote : null;
                    $changed = true;
                }

                $ticket['updated_at'] = tickets_now();
                $updatedTicket = $ticket;

                if ($comment !== '') {
                    $data['support_ticket_comments'][] = [
                        'id' => tickets_next_id($data['support_ticket_comments']),
                        'ticket_id' => $ticketId,
                        'user_id' => (int)$user['id'],
                        'visibility' => empty($in['admin_only']) ? 'public' : 'admin',
                        'content' => $comment,
                        'created_at' => tickets_now(),
                    ];
                }
                break;
            }
            unset($ticket);

            if (!$updatedTicket) {
                tickets_out(['ok' => false, 'error' => 'Ticket nicht gefunden'], 404);
            }

            if ($newStatus !== null || $comment !== '') {
                $labels = tickets_status_labels();
                $subject = 'Ticket ' . ($updatedTicket['ticket_number'] ?? ('#' . $ticketId)) . ': ' . ($labels[$updatedTicket['status']] ?? $updatedTicket['status']);
                $content = 'Dein Ticket "' . ($updatedTicket['title'] ?? '') . '" wurde bearbeitet.\n\nStatus: ' . ($labels[$updatedTicket['status']] ?? $updatedTicket['status']);
                if ($comment !== '' && empty($in['admin_only'])) {
                    $content .= "\n\nKommentar:\n" . $comment;
                }
                if (($updatedTicket['status'] ?? '') === 'done') {
                    $content .= "\n\nBitte prüfe das Ergebnis und bestätige das Ticket im Ticketsystem.";
                }
                tickets_notify_creator($data, $user, $updatedTicket, $subject, $content);
            }

            storage_save($data);
            $fresh = storage_load();
            tickets_out(['ok' => true, 'data' => tickets_payload($fresh, $user), 'changed' => $changed]);
            break;

        case 'add_comment':
            $in = tickets_body();
            $ticketId = (int)($in['id'] ?? 0);
            $content = tickets_clean_text((string)($in['content'] ?? ''), 8000);
            if ($content === '') {
                tickets_out(['ok' => false, 'error' => 'Kommentartext fehlt'], 422);
            }

            $found = null;
            foreach ($data['support_tickets'] ?? [] as $ticket) {
                if ((int)($ticket['id'] ?? 0) === $ticketId) {
                    $found = $ticket;
                    break;
                }
            }
            if (!$found || !tickets_visible_ticket($user, $found)) {
                tickets_out(['ok' => false, 'error' => 'Ticket nicht gefunden'], 404);
            }

            $visibility = tickets_is_admin($user) && !empty($in['admin_only']) ? 'admin' : 'public';
            $data['support_ticket_comments'][] = [
                'id' => tickets_next_id($data['support_ticket_comments']),
                'ticket_id' => $ticketId,
                'user_id' => (int)$user['id'],
                'visibility' => $visibility,
                'content' => $content,
                'created_at' => tickets_now(),
            ];

            if (tickets_is_admin($user) && $visibility === 'public') {
                tickets_notify_creator($data, $user, $found, 'Antwort zu Ticket ' . ($found['ticket_number'] ?? ('#' . $ticketId)), $content);
            }

            storage_save($data);
            $fresh = storage_load();
            tickets_out(['ok' => true, 'data' => tickets_payload($fresh, $user)]);
            break;

        case 'confirm_ticket':
            $in = tickets_body();
            $ticketId = (int)($in['id'] ?? 0);
            $note = tickets_clean_text((string)($in['note'] ?? ''), 4000);
            $updated = false;
            foreach ($data['support_tickets'] as &$ticket) {
                if ((int)($ticket['id'] ?? 0) === $ticketId && (int)($ticket['created_by'] ?? 0) === (int)$user['id']) {
                    if (($ticket['status'] ?? '') !== 'done') {
                        tickets_out(['ok' => false, 'error' => 'Nur erledigte Tickets können bestätigt werden'], 422);
                    }
                    $ticket['status'] = 'confirmed';
                    $ticket['confirmed_at'] = tickets_now();
                    $ticket['updated_at'] = tickets_now();
                    $updated = true;
                    if ($note !== '') {
                        $data['support_ticket_comments'][] = [
                            'id' => tickets_next_id($data['support_ticket_comments']),
                            'ticket_id' => $ticketId,
                            'user_id' => (int)$user['id'],
                            'visibility' => 'public',
                            'content' => 'Ticket bestätigt. ' . $note,
                            'created_at' => tickets_now(),
                        ];
                    }
                    break;
                }
            }
            unset($ticket);
            if (!$updated) {
                tickets_out(['ok' => false, 'error' => 'Ticket nicht gefunden oder nicht bestätigbar'], 404);
            }
            storage_save($data);
            $fresh = storage_load();
            tickets_out(['ok' => true, 'data' => tickets_payload($fresh, $user)]);
            break;

        default:
            tickets_out(['ok' => false, 'error' => 'Unbekannte Ticket-Action'], 404);
    }
} catch (Throwable $e) {
    tickets_out(['ok' => false, 'error' => 'Ticket-Fehler: ' . $e->getMessage()], 500);
}
