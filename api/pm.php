<?php
/**
 * PolarisNova PM-/Nachrichten-API.
 *
 * Funktionen:
 * - private Mitarbeiter-Nachrichten
 * - projektbezogene Gruppenchats
 * - Unternehmens-/Gesamtchat
 * - Admin-Pinnwand
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function pm_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pm_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        pm_out(['ok' => false, 'error' => 'Ungültiger JSON-Request'], 400);
    }

    return $data;
}

function pm_next_id(array $rows): int
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (int)($row['id'] ?? 0));
    }
    return $max + 1;
}

function pm_now(): string
{
    return date('Y-m-d H:i:s');
}

function pm_current_user(array $d): ?array
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

function pm_is_admin(array $u): bool
{
    return ($u['role'] ?? '') === 'admin';
}

function pm_can_write(array $u): bool
{
    return in_array(($u['role'] ?? ''), ['admin', 'user'], true) && !empty($u['is_active']);
}

function pm_index_by_id(array $rows): array
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

function pm_project_ids_for_user(array $d, int $userId): array
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

function pm_can_access_project(array $d, array $u, int $projectId): bool
{
    if ($projectId <= 0) {
        return false;
    }

    if (pm_is_admin($u) || ($u['role'] ?? '') === 'guest') {
        return true;
    }

    return in_array($projectId, pm_project_ids_for_user($d, (int)($u['id'] ?? 0)), true);
}

function pm_visible_projects(array $d, array $u): array
{
    $projects = [];
    foreach ($d['projects'] ?? [] as $project) {
        if (pm_can_access_project($d, $u, (int)($project['id'] ?? 0))) {
            $projects[] = [
                'id' => (int)($project['id'] ?? 0),
                'name' => (string)($project['name'] ?? ('Projekt #' . (int)($project['id'] ?? 0))),
                'description' => (string)($project['description'] ?? ''),
            ];
        }
    }
    return $projects;
}

function pm_message_visible(array $d, array $u, array $message): bool
{
    $uid = (int)($u['id'] ?? 0);
    $type = (string)($message['type'] ?? 'direct');

    if (pm_is_admin($u)) {
        return true;
    }

    if ($type === 'direct') {
        return (int)($message['sender_id'] ?? 0) === $uid || (int)($message['recipient_id'] ?? 0) === $uid;
    }

    if ($type === 'company') {
        return true;
    }

    if ($type === 'project') {
        return pm_can_access_project($d, $u, (int)($message['project_id'] ?? 0));
    }

    return false;
}

function pm_clean_text(string $text, int $max): string
{
    $text = trim($text);
    if (mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max);
    }
    return $text;
}

function pm_read_key(string $type, int $id, int $userId): string
{
    return $type . ':' . $id . ':' . $userId;
}

function pm_payload(array $d, array $u): array
{
    foreach (['pm_messages', 'pm_message_reads', 'pm_pinboard', 'pm_pinboard_reads'] as $key) {
        if (!isset($d[$key]) || !is_array($d[$key])) {
            $d[$key] = [];
        }
    }

    $uid = (int)($u['id'] ?? 0);
    $usersById = pm_index_by_id($d['users'] ?? []);
    $projectsById = pm_index_by_id($d['projects'] ?? []);

    $readMessages = [];
    foreach ($d['pm_message_reads'] ?? [] as $read) {
        if ((int)($read['user_id'] ?? 0) === $uid) {
            $readMessages[(int)($read['message_id'] ?? 0)] = true;
        }
    }

    $readPins = [];
    foreach ($d['pm_pinboard_reads'] ?? [] as $read) {
        if ((int)($read['user_id'] ?? 0) === $uid) {
            $readPins[(int)($read['pin_id'] ?? 0)] = true;
        }
    }

    $messages = [];
    foreach ($d['pm_messages'] ?? [] as $message) {
        if (!pm_message_visible($d, $u, $message)) {
            continue;
        }

        $id = (int)($message['id'] ?? 0);
        $senderId = (int)($message['sender_id'] ?? 0);
        $recipientId = ($message['recipient_id'] ?? null) === null ? null : (int)$message['recipient_id'];
        $projectId = ($message['project_id'] ?? null) === null ? null : (int)$message['project_id'];

        $messages[] = [
            'id' => $id,
            'type' => (string)($message['type'] ?? 'direct'),
            'subject' => (string)($message['subject'] ?? ''),
            'content' => (string)($message['content'] ?? ''),
            'sender_id' => $senderId,
            'sender_name' => $usersById[$senderId]['username'] ?? ('Benutzer #' . $senderId),
            'recipient_id' => $recipientId,
            'recipient_name' => $recipientId ? ($usersById[$recipientId]['username'] ?? ('Benutzer #' . $recipientId)) : null,
            'project_id' => $projectId,
            'project_name' => $projectId ? ($projectsById[$projectId]['name'] ?? ('Projekt #' . $projectId)) : null,
            'created_at' => (string)($message['created_at'] ?? ''),
            'updated_at' => $message['updated_at'] ?? null,
            'is_read' => isset($readMessages[$id]) || $senderId === $uid,
            'can_delete' => pm_is_admin($u) || $senderId === $uid,
        ];
    }

    usort($messages, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

    $pinboard = [];
    $now = time();
    foreach ($d['pm_pinboard'] ?? [] as $pin) {
        $active = !empty($pin['is_active']);
        $expires = trim((string)($pin['expires_at'] ?? ''));
        $expired = $expires !== '' && strtotime($expires . ' 23:59:59') !== false && strtotime($expires . ' 23:59:59') < $now;

        if (!$active && !pm_is_admin($u)) {
            continue;
        }
        if ($expired && !pm_is_admin($u)) {
            continue;
        }

        $id = (int)($pin['id'] ?? 0);
        $creatorId = (int)($pin['created_by'] ?? 0);
        $pinboard[] = [
            'id' => $id,
            'title' => (string)($pin['title'] ?? ''),
            'content' => (string)($pin['content'] ?? ''),
            'priority' => (string)($pin['priority'] ?? 'normal'),
            'is_active' => $active,
            'expires_at' => $pin['expires_at'] ?? null,
            'created_by' => $creatorId,
            'created_by_name' => $usersById[$creatorId]['username'] ?? ('Admin #' . $creatorId),
            'created_at' => (string)($pin['created_at'] ?? ''),
            'updated_at' => $pin['updated_at'] ?? null,
            'is_read' => isset($readPins[$id]),
        ];
    }

    usort($pinboard, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

    $safeUsers = [];
    foreach ($d['users'] ?? [] as $user) {
        if (empty($user['is_active'])) {
            continue;
        }
        $safeUsers[] = [
            'id' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'role' => (string)($user['role'] ?? 'user'),
        ];
    }

    $unreadMessages = 0;
    foreach ($messages as $message) {
        if (empty($message['is_read'])) {
            $unreadMessages++;
        }
    }

    $unreadPins = 0;
    foreach ($pinboard as $pin) {
        if (empty($pin['is_read'])) {
            $unreadPins++;
        }
    }

    return [
        'user' => [
            'id' => $uid,
            'username' => (string)($u['username'] ?? ''),
            'role' => (string)($u['role'] ?? 'user'),
        ],
        'users' => $safeUsers,
        'projects' => pm_visible_projects($d, $u),
        'messages' => $messages,
        'pinboard' => $pinboard,
        'unread' => [
            'messages' => $unreadMessages,
            'pinboard' => $unreadPins,
            'total' => $unreadMessages + $unreadPins,
        ],
    ];
}

function pm_mark_message_read(array &$d, int $messageId, int $userId): void
{
    if ($messageId <= 0 || $userId <= 0) {
        return;
    }

    foreach ($d['pm_message_reads'] ?? [] as $read) {
        if ((int)($read['message_id'] ?? 0) === $messageId && (int)($read['user_id'] ?? 0) === $userId) {
            return;
        }
    }

    $d['pm_message_reads'][] = [
        'id' => pm_next_id($d['pm_message_reads'] ?? []),
        'message_id' => $messageId,
        'user_id' => $userId,
        'read_at' => pm_now(),
    ];
}

function pm_mark_pin_read(array &$d, int $pinId, int $userId): void
{
    if ($pinId <= 0 || $userId <= 0) {
        return;
    }

    foreach ($d['pm_pinboard_reads'] ?? [] as $read) {
        if ((int)($read['pin_id'] ?? 0) === $pinId && (int)($read['user_id'] ?? 0) === $userId) {
            return;
        }
    }

    $d['pm_pinboard_reads'][] = [
        'id' => pm_next_id($d['pm_pinboard_reads'] ?? []),
        'pin_id' => $pinId,
        'user_id' => $userId,
        'read_at' => pm_now(),
    ];
}

try {
    $data = storage_load();
    foreach (['pm_messages', 'pm_message_reads', 'pm_pinboard', 'pm_pinboard_reads'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    $user = pm_current_user($data);
    if (!$user) {
        pm_out(['ok' => false, 'error' => 'Nicht angemeldet'], 401);
    }
    if (empty($user['is_active'])) {
        pm_out(['ok' => false, 'error' => 'Benutzer ist gesperrt'], 403);
    }

    $action = $_GET['action'] ?? 'bootstrap';

    switch ($action) {
        case 'bootstrap':
            pm_out(['ok' => true, 'data' => pm_payload($data, $user)]);
            break;

        case 'send_message':
            if (!pm_can_write($user)) {
                pm_out(['ok' => false, 'error' => 'Keine Schreibrechte für Nachrichten'], 403);
            }

            $in = pm_body();
            $type = (string)($in['type'] ?? 'direct');
            if (!in_array($type, ['direct', 'project', 'company'], true)) {
                pm_out(['ok' => false, 'error' => 'Ungültiger Nachrichtentyp'], 422);
            }

            $subject = pm_clean_text((string)($in['subject'] ?? ''), 160);
            $content = pm_clean_text((string)($in['content'] ?? ''), 10000);
            if ($content === '') {
                pm_out(['ok' => false, 'error' => 'Nachrichtentext fehlt'], 422);
            }

            $recipientId = null;
            $projectId = null;

            if ($type === 'direct') {
                $recipientId = (int)($in['recipient_id'] ?? 0);
                $usersById = pm_index_by_id($data['users'] ?? []);
                if ($recipientId <= 0 || empty($usersById[$recipientId]) || empty($usersById[$recipientId]['is_active'])) {
                    pm_out(['ok' => false, 'error' => 'Empfänger nicht gefunden oder gesperrt'], 422);
                }
            }

            if ($type === 'project') {
                $projectId = (int)($in['project_id'] ?? 0);
                if (!pm_can_access_project($data, $user, $projectId)) {
                    pm_out(['ok' => false, 'error' => 'Kein Zugriff auf diesen Projektchat'], 403);
                }
            }

            $messageId = pm_next_id($data['pm_messages']);
            $data['pm_messages'][] = [
                'id' => $messageId,
                'type' => $type,
                'project_id' => $projectId,
                'sender_id' => (int)$user['id'],
                'recipient_id' => $recipientId,
                'subject' => $subject,
                'content' => $content,
                'created_at' => pm_now(),
                'updated_at' => null,
            ];
            pm_mark_message_read($data, $messageId, (int)$user['id']);

            storage_save($data);
            $fresh = storage_load();
            pm_out(['ok' => true, 'data' => pm_payload($fresh, $user), 'message_id' => $messageId]);
            break;

        case 'mark_message_read':
            $in = pm_body();
            $messageId = (int)($in['id'] ?? 0);
            $found = null;
            foreach ($data['pm_messages'] ?? [] as $message) {
                if ((int)($message['id'] ?? 0) === $messageId) {
                    $found = $message;
                    break;
                }
            }
            if (!$found || !pm_message_visible($data, $user, $found)) {
                pm_out(['ok' => false, 'error' => 'Nachricht nicht gefunden'], 404);
            }
            pm_mark_message_read($data, $messageId, (int)$user['id']);
            storage_save($data);
            $fresh = storage_load();
            pm_out(['ok' => true, 'data' => pm_payload($fresh, $user)]);
            break;

        case 'delete_message':
            $in = pm_body();
            $messageId = (int)($in['id'] ?? 0);
            $canDelete = false;
            foreach ($data['pm_messages'] ?? [] as $message) {
                if ((int)($message['id'] ?? 0) === $messageId) {
                    $canDelete = pm_is_admin($user) || (int)($message['sender_id'] ?? 0) === (int)$user['id'];
                    break;
                }
            }
            if (!$canDelete) {
                pm_out(['ok' => false, 'error' => 'Nachricht darf nicht gelöscht werden'], 403);
            }
            $data['pm_messages'] = array_values(array_filter($data['pm_messages'] ?? [], static fn($message) => (int)($message['id'] ?? 0) !== $messageId));
            $data['pm_message_reads'] = array_values(array_filter($data['pm_message_reads'] ?? [], static fn($read) => (int)($read['message_id'] ?? 0) !== $messageId));
            storage_save($data);
            $fresh = storage_load();
            pm_out(['ok' => true, 'data' => pm_payload($fresh, $user)]);
            break;

        case 'save_pin':
            if (!pm_is_admin($user)) {
                pm_out(['ok' => false, 'error' => 'Nur Admins dürfen die Pinnwand verwalten'], 403);
            }

            $in = pm_body();
            $id = (int)($in['id'] ?? 0);
            $title = pm_clean_text((string)($in['title'] ?? ''), 160);
            $content = pm_clean_text((string)($in['content'] ?? ''), 12000);
            $priority = in_array(($in['priority'] ?? 'normal'), ['normal', 'important', 'urgent'], true) ? (string)$in['priority'] : 'normal';
            $expiresAt = trim((string)($in['expires_at'] ?? ''));
            if ($expiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
                $expiresAt = '';
            }
            if ($title === '' || $content === '') {
                pm_out(['ok' => false, 'error' => 'Titel und Nachricht der Pinnwand sind Pflicht'], 422);
            }

            $row = [
                'id' => $id > 0 ? $id : pm_next_id($data['pm_pinboard']),
                'title' => $title,
                'content' => $content,
                'priority' => $priority,
                'is_active' => empty($in['is_active']) ? false : true,
                'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                'created_by' => (int)$user['id'],
                'created_at' => pm_now(),
                'updated_at' => pm_now(),
            ];

            $updated = false;
            foreach ($data['pm_pinboard'] as &$pin) {
                if ((int)($pin['id'] ?? 0) === $id && $id > 0) {
                    $row['created_at'] = $pin['created_at'] ?? $row['created_at'];
                    $row['created_by'] = (int)($pin['created_by'] ?? $user['id']);
                    $pin = $row;
                    $updated = true;
                    break;
                }
            }
            unset($pin);
            if (!$updated) {
                $data['pm_pinboard'][] = $row;
            }

            storage_save($data);
            $fresh = storage_load();
            pm_out(['ok' => true, 'data' => pm_payload($fresh, $user), 'pin_id' => $row['id']]);
            break;

        case 'mark_pin_read':
            $in = pm_body();
            $pinId = (int)($in['id'] ?? 0);
            $found = false;
            foreach ($data['pm_pinboard'] ?? [] as $pin) {
                if ((int)($pin['id'] ?? 0) === $pinId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                pm_out(['ok' => false, 'error' => 'Pinnwand-Eintrag nicht gefunden'], 404);
            }
            pm_mark_pin_read($data, $pinId, (int)$user['id']);
            storage_save($data);
            $fresh = storage_load();
            pm_out(['ok' => true, 'data' => pm_payload($fresh, $user)]);
            break;

        case 'delete_pin':
            if (!pm_is_admin($user)) {
                pm_out(['ok' => false, 'error' => 'Nur Admins dürfen Pinnwand-Einträge löschen'], 403);
            }
            $in = pm_body();
            $pinId = (int)($in['id'] ?? 0);
            $data['pm_pinboard'] = array_values(array_filter($data['pm_pinboard'] ?? [], static fn($pin) => (int)($pin['id'] ?? 0) !== $pinId));
            $data['pm_pinboard_reads'] = array_values(array_filter($data['pm_pinboard_reads'] ?? [], static fn($read) => (int)($read['pin_id'] ?? 0) !== $pinId));
            storage_save($data);
            $fresh = storage_load();
            pm_out(['ok' => true, 'data' => pm_payload($fresh, $user)]);
            break;

        default:
            pm_out(['ok' => false, 'error' => 'Unbekannte PM-Action'], 404);
    }
} catch (Throwable $e) {
    pm_out(['ok' => false, 'error' => 'PM-Fehler: ' . $e->getMessage()], 500);
}
