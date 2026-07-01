<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\ArrayHelper;
use App\Core\Clock;
use App\Core\JsonResponse;
use App\Core\Request;
use App\Domain\Kanban\KanbanAccessTrait;
use App\Domain\Kanban\KanbanAuditTrait;
use App\Domain\Kanban\KanbanBoardPayloadTrait;
use App\Domain\Kanban\KanbanTaskRulesTrait;
use App\Storage\KanbanStorageAdapter;
use Throwable;

final class KanbanApiController
{
    use KanbanAccessTrait;
    use KanbanAuditTrait;
    use KanbanBoardPayloadTrait;
    use KanbanTaskRulesTrait;

    private KanbanStorageAdapter $storage;
    private Request $request;
    private Clock $clock;

    public function __construct(?KanbanStorageAdapter $storage = null, ?Request $request = null, ?Clock $clock = null)
    {
        $this->storage = $storage ?? new KanbanStorageAdapter();
        $this->request = $request ?? new Request();
        $this->clock = $clock ?? new Clock();
    }

/**
     * Sendet eine JSON-Antwort an das Frontend und beendet den Request.
     */
    private function out($d, $c = 200): void
    {
        JsonResponse::send(is_array($d) ? $d : ['data' => $d], (int)$c);
    }
    
    /**
     * Liest den JSON-Request-Body ein und wandelt ihn in ein Array um.
     * Bei ungültigem JSON wird direkt ein Fehler an das Frontend gesendet.
     */
    private function body(): array
    {
        return $this->request->jsonBody();
    }
    
    /**
     * Lädt die vollständige Datenstruktur über die Storage-Schicht.
     */
    private function load(): array
    {
        try {
            return $this->storage->load();
        } catch (Throwable $e) {
            $this->out(['ok' => false, 'error' => 'Datenhaltung nicht verfügbar: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Speichert die vollständige Datenstruktur über die Storage-Schicht.
     */
    private function save(array $d): void
    {
        try {
            $this->storage->save($d);
        } catch (Throwable $e) {
            $this->out(['ok' => false, 'error' => 'Speichern fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }
    
    
    /**
     * Sendet einen JSON-Datenstand als Download-Datei.
     */
    private function download_json(array $data, string $filename): void
    {
        JsonResponse::download($data, $filename);
    }
    
    /**
     * Nur aktive, angemeldete Nicht-Gäste dürfen JSON-Sync verwenden.
     * Import ist zusätzlich nur im Offline-Modus erlaubt.
     */
    private function require_json_sync_user($u)
    {
        if (($u['role'] ?? '') === 'guest') {
            $this->out(['ok' => false, 'error' => 'Gäste dürfen JSON-Import/Export nicht verwenden'], 403);
        }
    }
    
    /**
     * Nur globale Admins dürfen JSON-Daten wieder nach MySQL zurückschreiben.
     * Projektverantwortliche haben Adminrechte im Projekt, aber nicht auf Systemebene.
     */
    private function require_global_admin($u)
    {
        if (($u['role'] ?? '') !== 'admin') {
            $this->out(['ok' => false, 'error' => 'Nur globale Admins dürfen JSON nach MySQL wiederherstellen'], 403);
        }
    }
    
    /**
     * Ermittelt die nächste freie numerische ID innerhalb eines Arrays.
     */
    private function nid(array $a): int
    {
        return ArrayHelper::nextId($a);
    }
    
    /**
     * Einheitlicher Zeitstempel für neue und geänderte Datensätze.
     */
    private function now(): string
    {
        return $this->clock->now();
    }
    
    /**
     * Ermittelt den aktuell eingeloggten Benutzer aus der Session.
     */
    private function user($d)
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
    
        foreach ($d['users'] as $u) {
            if ((int)$u['id'] === (int)$_SESSION['user_id']) {
                return $u;
            }
        }
    
        return null;
    }
    
    /**
     * Sucht den Index eines Datensatzes anhand der ID in einem Array.
     */
    private function idx(array &$a, int $id): int
    {
        return ArrayHelper::indexById($a, $id);
    }
    
    // -----------------------------------------------------------------------------
    // Projekt-, Board- und Sichtbarkeits-Hilfsfunktionen
    // -----------------------------------------------------------------------------
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    // -----------------------------------------------------------------------------
    // Automatische Aufgaben-Sortierung
    // -----------------------------------------------------------------------------
    
    
    
    
    
    // -----------------------------------------------------------------------------
    // Rechteprüfung und Sperrlogik für Aufgaben
    // -----------------------------------------------------------------------------
    
    
    
    
    // -----------------------------------------------------------------------------
    // Spalten- und Board-Regeln
    // -----------------------------------------------------------------------------
    
    
    
    
    
    
    // -----------------------------------------------------------------------------
    // Historie und Ereignisse
    // -----------------------------------------------------------------------------
    
    
    
    public function handle(): void
    {
    $d = $this->load();
    $u = $this->user($d);
    
    if (!$u) {
        $this->out(['ok' => false, 'error' => 'Nicht angemeldet'], 401);
    }
    
    if (isset($u['is_active']) && !$u['is_active']) {
        session_destroy();
        $this->out(['ok' => false, 'error' => 'Benutzer ist gesperrt. Bitte wenden Sie sich an den Admin.'], 403);
    }
    
    $a = $_GET['action'] ?? '';
    
    // -----------------------------------------------------------------------------
    // API-Routing
    // -----------------------------------------------------------------------------
    
    switch ($a) {
        case 'bootstrap':
            // Lädt Projektübersicht und optional die Daten eines ausgewählten Boards.
            $boardId = (int)($_GET['board_id'] ?? 0);
    
            $safeUsers = array_map(fn($x) => [
                'id' => $x['id'],
                'username' => $x['username'],
                'email' => $x['email'] ?? '',
                'role' => $x['role'],
                'is_active' => ($x['is_active'] ?? true),
            ], $d['users']);
    
            $visibleProjects = $this->visible_projects($d, $u);
            $visibleBoards = $this->visible_boards($d, $u);
            $visibleMembers = $this->visible_project_members($d, $u);
    
            if ($boardId > 0) {
                [$visibleColumns, $visibleTasks, $visibleComments, $visibleTimes, $visibleEvents, $visibleHistory] = $this->board_payload($d, $u, $boardId);
            } else {
                // In der Projektübersicht werden alle sichtbaren Boards grob mitgezählt.
                // Kommentare, Zeiten und Historie werden erst beim Öffnen eines Boards geladen.
                $visibleBoardIds = array_map(fn($board) => (int)$board['id'], $visibleBoards);
                $visibleColumns = array_values(array_filter($d['columns'] ?? [], fn($column) => in_array((int)$column['board_id'], $visibleBoardIds, true)));
                $visibleColumnIds = array_map(fn($column) => (int)$column['id'], $visibleColumns);
                $visibleTasks = array_values(array_filter($d['tasks'] ?? [], fn($task) => in_array((int)$task['column_id'], $visibleColumnIds, true)));
                usort($visibleTasks, function ($a, $b) {
                    $columnCompare = (int)($a['column_id'] ?? 0) <=> (int)($b['column_id'] ?? 0);
                    return $columnCompare !== 0 ? $columnCompare : $this->compare_tasks_auto($a, $b);
                });
                $visibleComments = [];
                $visibleTimes = [];
                $visibleEvents = [];
                $visibleHistory = [];
            }
    
            $this->out(['ok' => true, 'data' => [
                'meta' => $d['meta'] ?? [],
                'user' => [
                    'id' => $u['id'],
                    'username' => $u['username'],
                    'role' => $u['role'],
                    'is_active' => ($u['is_active'] ?? true),
                ],
                'users' => $safeUsers,
                'projects' => $visibleProjects,
                'boards' => $visibleBoards,
                'project_members' => $visibleMembers,
                'current_board_id' => $boardId,
                'columns' => $visibleColumns,
                'tasks' => $visibleTasks,
                'comments' => $visibleComments,
                'time_entries' => $visibleTimes,
                'events' => $visibleEvents,
                'history' => $visibleHistory,
            ]]);
            break;
    
        case 'tasks_only':
            // Asynchroner Teil-Reload für das aktuell ausgewählte Projektboard.
            $boardId = (int)($_GET['board_id'] ?? 0);
    
            [$visibleColumns, $visibleTasks, $visibleComments, $visibleTimes, $visibleEvents, $visibleHistory] = $this->board_payload($d, $u, $boardId);
    
            $this->out(['ok' => true, 'data' => [
                'meta' => $d['meta'] ?? [],
                'current_board_id' => $boardId,
                'columns' => $visibleColumns,
                'tasks' => $visibleTasks,
                'comments' => $visibleComments,
                'time_entries' => $visibleTimes,
                'events' => $visibleEvents,
                'history' => $visibleHistory,
                'server_time' => $this->now(),
            ]]);
            break;
    
        case 'toggle_lock':
            // Sperrt oder entsperrt eine Aufgabe für die Bearbeitung.
            if ($u['role'] === 'guest') {
                $this->out(['ok' => false, 'error' => 'Gäste dürfen Aufgaben nicht sperren'], 403);
            }
    
            $in = $this->body();
            $id = (int)($in['task_id'] ?? 0);
            $i = $this->idx($d['tasks'], $id);
    
            if ($i < 0) {
                $this->out(['ok' => false, 'error' => 'Aufgabe nicht gefunden'], 404);
            }
    
            $this->require_board_access($d, $u, $this->board_id_by_task($d, $d['tasks'][$i]));
            $this->task_access($d, $u, $d['tasks'][$i]);
    
            if (!empty($d['tasks'][$i]['locked_by'])) {
                if ((int)$d['tasks'][$i]['locked_by'] !== (int)$u['id']) {
                    $this->out(['ok' => false, 'error' => 'Diese Aufgabe ist durch einen anderen Benutzer gesperrt'], 423);
                }
    
                $d['tasks'][$i]['locked_by'] = null;
                $d['tasks'][$i]['locked_at'] = null;
    
                $this->history_add($d, $id, $u['id'], 'task_unlocked', 'locked_by', $u['id'], null, 'Bearbeitung freigegeben');
                $this->event($d, $id, $u['id'], 'task_unlocked', 'Bearbeitung freigegeben');
            } else {
                $d['tasks'][$i]['locked_by'] = (int)$u['id'];
                $d['tasks'][$i]['locked_at'] = $this->now();
    
                $this->history_add($d, $id, $u['id'], 'task_locked', 'locked_by', null, $u['id'], 'Bearbeitung gesperrt');
                $this->event($d, $id, $u['id'], 'task_locked', 'Bearbeitung gesperrt');
            }
    
            $this->save($d);
            $this->out(['ok' => true, 'task' => $d['tasks'][$i]]);
            break;
    
        case 'save_task':
            // Erstellt eine neue Aufgabe oder aktualisiert eine vorhandene Aufgabe.
            if ($u['role'] === 'guest') {
                $this->out(['ok' => false, 'error' => 'Gäste dürfen nicht ändern'], 403);
            }
    
            $in = $this->body();
            $title = trim($in['title'] ?? '');
    
            if ($title === '') {
                $this->out(['ok' => false, 'error' => 'Titel erforderlich'], 422);
            }
    
            $prio = in_array($in['priority'] ?? 'medium', ['low', 'medium', 'high', 'critical'], true)
                ? $in['priority']
                : 'medium';
    
            $ass = ($in['assigned_to'] ?? '') === '' ? null : (int)$in['assigned_to'];
            $col = (int)($in['column_id'] ?? 1);
            $due = trim($in['due_at'] ?? '');
    
            $this->require_column_access($d, $u, $col);
    
            // Zuweisungen werden bewusst erst im jeweiligen Zweig behandelt:
            // - beim Bearbeiten behalten normale Projektmitglieder die bisherige Zuweisung bei
            // - beim Neuanlegen wird eine normale User-Aufgabe dem aktuellen Benutzer zugewiesen
    
            if (!empty($in['id'])) {
                $i = $this->idx($d['tasks'], (int)$in['id']);
    
                if ($i < 0) {
                    $this->out(['ok' => false, 'error' => 'Aufgabe nicht gefunden'], 404);
                }
    
                $this->require_board_access($d, $u, $this->board_id_by_task($d, $d['tasks'][$i]));
                $this->task_access($d, $u, $d['tasks'][$i]);
                $this->require_not_locked($u, $d['tasks'][$i]);
                $this->enforce_column_rules($d, $u, $d['tasks'][$i], $col);
    
                $oldTask = $d['tasks'][$i];
    
                // Normale Projektmitglieder dürfen die Aufgabe bearbeiten, aber keine
                // Zuweisung anderer Aufgaben verändern. Das verhindert, dass ein
                // Mitarbeiter beim Speichern ungewollt als Bearbeiter eingetragen wird.
                if (($u['role'] ?? '') === 'user' && !$this->can_manage_task($d, $u, $oldTask)) {
                    $ass = $oldTask['assigned_to'] ?? null;
                }
    
                $d['tasks'][$i] = array_merge($d['tasks'][$i], [
                    'title' => $title,
                    'description' => trim($in['description'] ?? ''),
                    'priority' => $prio,
                    'assigned_to' => $ass,
                    'column_id' => $col,
                    'due_at' => $due,
                    'updated_at' => $this->now(),
                ]);
    
                $this->history_diff_task($d, $oldTask, $d['tasks'][$i], $u['id']);
                $this->event($d, $d['tasks'][$i]['id'], $u['id'], 'task_updated', 'Aufgabe bearbeitet');
                $this->normalize_task_positions_for_columns($d, [$oldTask['column_id'] ?? 0, $d['tasks'][$i]['column_id'] ?? 0]);
    
                $this->save($d);
                $this->out(['ok' => true, 'task' => $d['tasks'][$i]]);
            }
    
            // Neue Aufgaben von normalen Mitarbeitern werden immer dem Ersteller
            // zugewiesen. Admins und Projektverantwortliche dürfen frei zuweisen.
            if (($u['role'] ?? '') === 'user' && !$this->can_manage_board($d, $u, $this->board_id_by_column_id($d, $col))) {
                $ass = (int)$u['id'];
            }
    
            $this->enforce_column_rules($d, $u, ['id' => 0, 'assigned_to' => $ass], $col);
    
            $t = [
                'id' => $this->nid($d['tasks']),
                'column_id' => $col,
                'title' => $title,
                'description' => trim($in['description'] ?? ''),
                'priority' => $prio,
                'assigned_to' => $ass,
                'due_at' => $due,
                'position' => 999,
                'locked_by' => null,
                'locked_at' => null,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ];
    
            $d['tasks'][] = $t;
    
            $this->history_add($d, $t['id'], $u['id'], 'task_created', null, null, $t['title'], 'Aufgabe erstellt');
            $this->event($d, $t['id'], $u['id'], 'task_created', 'Aufgabe erstellt');
            $this->normalize_task_positions_for_column($d, $col);
    
            $this->save($d);
            $this->out(['ok' => true, 'task' => $t], 201);
            break;
    
        case 'move_task':
            // Wird durch Drag & Drop ausgelöst und verschiebt eine Aufgabe in eine Spalte.
            if ($u['role'] === 'guest') {
                $this->out(['ok' => false, 'error' => 'Gäste dürfen nicht ändern'], 403);
            }
    
            $in = $this->body();
            $i = $this->idx($d['tasks'], (int)($in['task_id'] ?? 0));
    
            if ($i < 0) {
                $this->out(['ok' => false, 'error' => 'Aufgabe nicht gefunden'], 404);
            }
    
            $this->require_board_access($d, $u, $this->board_id_by_task($d, $d['tasks'][$i]));
            $this->require_column_access($d, $u, (int)$in['column_id']);
            $this->task_access($d, $u, $d['tasks'][$i]);
            $this->require_not_locked($u, $d['tasks'][$i]);
            $this->enforce_column_rules($d, $u, $d['tasks'][$i], (int)$in['column_id']);
    
            $oldCol = (int)$d['tasks'][$i]['column_id'];
            $newCol = (int)$in['column_id'];
            $d['tasks'][$i]['column_id'] = $newCol;
            $d['tasks'][$i]['position'] = (int)($in['position'] ?? 999);
            $d['tasks'][$i]['updated_at'] = $this->now();
    
            $this->history_add($d, $d['tasks'][$i]['id'], $u['id'], 'task_moved', 'column_id', $oldCol, $d['tasks'][$i]['column_id'], 'Aufgabe verschoben');
            $this->event($d, $d['tasks'][$i]['id'], $u['id'], 'task_moved', 'Aufgabe verschoben');
            $this->normalize_task_positions_for_columns($d, [$oldCol, $newCol]);
    
            $this->save($d);
            $this->out(['ok' => true]);
            break;
    
        case 'delete_task':
            // Löscht eine Aufgabe inklusive abhängiger Kommentare und Zeiteinträge.
            // Erlaubt für globale Admins und Projektverantwortliche des jeweiligen Projekts.
            $in = $this->body();
            $id = (int)($in['id'] ?? 0);
            $i = $this->idx($d['tasks'], $id);
    
            if ($i < 0) {
                $this->out(['ok' => false, 'error' => 'Aufgabe nicht gefunden'], 404);
            }
    
            $this->require_board_access($d, $u, $this->board_id_by_task($d, $d['tasks'][$i]));
    
            if (!$this->can_manage_task($d, $u, $d['tasks'][$i])) {
                $this->out(['ok' => false, 'error' => 'Nur Admins oder Projektverantwortliche dürfen Aufgaben löschen'], 403);
            }
    
            $this->history_add($d, $id, $u['id'], 'task_deleted', null, $d['tasks'][$i]['title'] ?? null, null, 'Aufgabe gelöscht');
            array_splice($d['tasks'], $i, 1);
    
            $d['comments'] = array_values(array_filter($d['comments'] ?? [], fn($c) => (int)$c['task_id'] !== $id));
            $d['time_entries'] = array_values(array_filter($d['time_entries'] ?? [], fn($t) => (int)$t['task_id'] !== $id));
    
            $this->event($d, $id, $u['id'], 'task_deleted', 'Aufgabe gelöscht');
            $this->save($d);
            $this->out(['ok' => true]);
            break;
    
        case 'add_comment':
            // Fügt einer Aufgabe einen Kommentar hinzu.
            if ($u['role'] === 'guest') {
                $this->out(['ok' => false, 'error' => 'Gäste dürfen nicht kommentieren'], 403);
            }
    
            $in = $this->body();
            $txt = trim($in['content'] ?? '');
    
            if ($txt === '') {
                $this->out(['ok' => false, 'error' => 'Kommentar leer'], 422);
            }
    
            $taskIndex = $this->idx($d['tasks'], (int)($in['task_id'] ?? 0));
            if ($taskIndex < 0) {
                $this->out(['ok' => false, 'error' => 'Aufgabe nicht gefunden'], 404);
            }
            $this->require_board_access($d, $u, $this->board_id_by_task($d, $d['tasks'][$taskIndex]));
            $this->task_access($d, $u, $d['tasks'][$taskIndex]);
    
            $c = [
                'id' => $this->nid($d['comments'] ?? []),
                'task_id' => (int)$in['task_id'],
                'user_id' => $u['id'],
                'content' => $txt,
                'created_at' => $this->now(),
            ];
    
            $d['comments'][] = $c;
    
            $this->save($d);
            $this->out(['ok' => true, 'comment' => $c], 201);
            break;
    
        case 'start_time':
            // Startet eine neue Zeitmessung für eine Aufgabe.
            if ($u['role'] === 'guest') {
                $this->out(['ok' => false, 'error' => 'Gäste dürfen keine Zeit erfassen'], 403);
            }
    
            $in = $this->body();
    
            $taskIndex = $this->idx($d['tasks'], (int)($in['task_id'] ?? 0));
            if ($taskIndex < 0) {
                $this->out(['ok' => false, 'error' => 'Aufgabe nicht gefunden'], 404);
            }
            $this->require_board_access($d, $u, $this->board_id_by_task($d, $d['tasks'][$taskIndex]));
            $this->task_access($d, $u, $d['tasks'][$taskIndex]);
    
            foreach ($d['time_entries'] as $t) {
                if ((int)$t['user_id'] === (int)$u['id'] && empty($t['stopped_at'])) {
                    $this->out(['ok' => false, 'error' => 'Es läuft bereits eine Zeitmessung'], 409);
                }
            }
    
            $e = [
                'id' => $this->nid($d['time_entries'] ?? []),
                'task_id' => (int)$in['task_id'],
                'user_id' => $u['id'],
                'started_at' => $this->now(),
                'stopped_at' => null,
                'seconds' => 0,
            ];
    
            $d['time_entries'][] = $e;
    
            $this->history_add($d, $e['task_id'], $u['id'], 'time_started', 'time_entries', null, $e['started_at'], 'Zeiterfassung gestartet');
            $this->event($d, $e['task_id'], $u['id'], 'time_started', 'Zeiterfassung gestartet');
    
            $this->save($d);
            $this->out(['ok' => true, 'entry' => $e], 201);
            break;
    
        case 'stop_time':
            // Stoppt eine laufende Zeitmessung für die angegebene Aufgabe.
            $in = $this->body();
    
            $taskIndex = $this->idx($d['tasks'], (int)($in['task_id'] ?? 0));
            if ($taskIndex < 0) {
                $this->out(['ok' => false, 'error' => 'Aufgabe nicht gefunden'], 404);
            }
            $this->require_board_access($d, $u, $this->board_id_by_task($d, $d['tasks'][$taskIndex]));
            $this->task_access($d, $u, $d['tasks'][$taskIndex]);
    
            foreach ($d['time_entries'] as $i => $t) {
                if ((int)$t['task_id'] === (int)$in['task_id'] && (int)$t['user_id'] === (int)$u['id'] && empty($t['stopped_at'])) {
                    $sec = max(1, time() - strtotime($t['started_at']));
                    $d['time_entries'][$i]['stopped_at'] = $this->now();
                    $d['time_entries'][$i]['seconds'] = $sec;
    
                    $this->history_add($d, $d['time_entries'][$i]['task_id'], $u['id'], 'time_stopped', 'time_entries', $t['started_at'] ?? null, $d['time_entries'][$i]['stopped_at'], 'Zeiterfassung gestoppt: ' . gmdate('H:i:s', $sec));
                    $this->event($d, $d['time_entries'][$i]['task_id'], $u['id'], 'time_stopped', 'Zeiterfassung gestoppt');
    
                    $this->save($d);
                    $this->out(['ok' => true, 'entry' => $d['time_entries'][$i]]);
                }
            }
    
            $this->out(['ok' => false, 'error' => 'Keine laufende Zeitmessung gefunden'], 404);
            break;
    
        case 'save_project':
            // Erstellt oder aktualisiert ein Projekt inklusive Board, Verantwortlichem und Mitarbeiterzuordnung.
            $in = $this->body();
            $isNewProject = empty($in['id']);
    
            // Neue Projekte dürfen nur globale Admins anlegen.
            // Bestehende Projekte dürfen globale Admins oder der jeweilige Projektverantwortliche bearbeiten.
            if ($isNewProject) {
                if (($u['role'] ?? '') !== 'admin') {
                    $this->out(['ok' => false, 'error' => 'Nur globale Admins dürfen neue Projekte anlegen'], 403);
                }
            } else {
                $checkProjectId = (int)$in['id'];
                if (!$this->can_manage_project($d, $u, $checkProjectId)) {
                    $this->out(['ok' => false, 'error' => 'Nur Admins oder der Projektverantwortliche dürfen dieses Projekt verwalten'], 403);
                }
            }
    
            $name = trim($in['name'] ?? '');
            $description = trim($in['description'] ?? '');
            $boardName = trim($in['board_name'] ?? '');
            $memberIds = $in['member_ids'] ?? [];
            $responsibleId = ($in['responsible_id'] ?? '') === '' ? null : (int)$in['responsible_id'];
    
            if ($name === '') {
                $this->out(['ok' => false, 'error' => 'Projektname erforderlich'], 422);
            }
    
            if ($boardName === '') {
                $boardName = $name . ' Board';
            }
    
            if (!is_array($memberIds)) {
                $memberIds = [];
            }
    
            // Projektverantwortliche können Admins oder aktive Mitarbeiter sein, aber keine Gäste.
            $allowedResponsibleIds = array_map(
                fn($user) => (int)$user['id'],
                array_filter($d['users'] ?? [], fn($user) => ($user['role'] ?? '') !== 'guest' && ($user['is_active'] ?? true))
            );
            if ($responsibleId !== null && !in_array($responsibleId, $allowedResponsibleIds, true)) {
                $responsibleId = null;
            }
    
            // Mitarbeiterliste: nur aktive User-Rollen werden als Projektmitglieder gespeichert.
            $allowedMemberIds = array_map(
                fn($user) => (int)$user['id'],
                array_filter($d['users'] ?? [], fn($user) => ($user['role'] ?? '') === 'user' && ($user['is_active'] ?? true))
            );
            $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), fn($id) => in_array($id, $allowedMemberIds, true))));
    
            // Wenn ein normaler Mitarbeiter Projektverantwortlicher ist, wird er automatisch auch Projektmitglied.
            if ($responsibleId !== null && in_array($responsibleId, $allowedMemberIds, true) && !in_array($responsibleId, $memberIds, true)) {
                $memberIds[] = $responsibleId;
            }
    
            if (!$isNewProject) {
                $projectId = (int)$in['id'];
                $projectIndex = $this->idx($d['projects'], $projectId);
    
                if ($projectIndex < 0) {
                    $this->out(['ok' => false, 'error' => 'Projekt nicht gefunden'], 404);
                }
    
                $d['projects'][$projectIndex]['name'] = $name;
                $d['projects'][$projectIndex]['description'] = $description;
                $d['projects'][$projectIndex]['owner_id'] = $d['projects'][$projectIndex]['owner_id'] ?? (int)$u['id'];
                $d['projects'][$projectIndex]['responsible_id'] = $responsibleId;
    
                $projectBoards = array_values(array_filter($d['boards'] ?? [], fn($board) => (int)$board['project_id'] === $projectId));
                if ($projectBoards) {
                    $boardIndex = $this->idx($d['boards'], (int)$projectBoards[0]['id']);
                    if ($boardIndex >= 0) {
                        $d['boards'][$boardIndex]['name'] = $boardName;
                    }
                } else {
                    $boardId = $this->nid($d['boards'] ?? []);
                    $d['boards'][] = [
                        'id' => $boardId,
                        'project_id' => $projectId,
                        'name' => $boardName,
                    ];
                    $this->create_default_columns_for_board($d, $boardId);
                }
            } else {
                $projectId = $this->nid($d['projects'] ?? []);
                $boardId = $this->nid($d['boards'] ?? []);
    
                if ($responsibleId === null) {
                    $responsibleId = (int)$u['id'];
                }
    
                $d['projects'][] = [
                    'id' => $projectId,
                    'name' => $name,
                    'description' => $description,
                    'owner_id' => (int)$u['id'],
                    'responsible_id' => $responsibleId,
                    'created_at' => $this->now(),
                ];
    
                $d['boards'][] = [
                    'id' => $boardId,
                    'project_id' => $projectId,
                    'name' => $boardName,
                ];
    
                $this->create_default_columns_for_board($d, $boardId);
            }
    
            // Zuordnung ersetzen: Admin/Projektverantwortlicher entscheidet pro Projekt, welche Mitarbeiter Zugriff erhalten.
            $d['project_members'] = array_values(array_filter($d['project_members'] ?? [], fn($member) => (int)$member['project_id'] !== (int)$projectId));
    
            foreach ($memberIds as $memberId) {
                $d['project_members'][] = [
                    'id' => $this->nid($d['project_members'] ?? []),
                    'project_id' => (int)$projectId,
                    'user_id' => (int)$memberId,
                    'created_at' => $this->now(),
                ];
            }
    
            $this->save($d);
            $this->out(['ok' => true, 'project_id' => $projectId]);
            break;
    
        case 'delete_project':
            // Löscht ein Projekt inklusive seiner Boards, Spalten, Aufgaben und Arbeitsdaten.
            // Kundendaten bleiben erhalten; Verknüpfungen auf das gelöschte Projekt werden in der Kundenverwaltung gelöst.
            $in = $this->body();
            $projectId = (int)($in['id'] ?? 0);
            $boardIdFromInput = (int)($in['board_id'] ?? 0);

            if ($projectId <= 0 && $boardIdFromInput > 0) {
                $projectId = $this->project_id_by_board_id($d, $boardIdFromInput);
            }

            if ($projectId <= 0) {
                $this->out(['ok' => false, 'error' => 'Projekt/Board-ID fehlt'], 422);
            }

            $projectIndex = $this->idx($d['projects'], $projectId);
            if ($projectIndex < 0) {
                $this->out(['ok' => false, 'error' => 'Projekt nicht gefunden'], 404);
            }

            if (!$this->can_manage_project($d, $u, $projectId)) {
                $this->out(['ok' => false, 'error' => 'Nur Admins oder der Projektverantwortliche dürfen dieses Projekt/Board löschen'], 403);
            }

            $projectName = (string)($d['projects'][$projectIndex]['name'] ?? ('Projekt #' . $projectId));

            $boardIds = array_values(array_map(
                fn($board) => (int)$board['id'],
                array_filter($d['boards'] ?? [], fn($board) => (int)($board['project_id'] ?? 0) === $projectId)
            ));
            $boardIdMap = array_fill_keys($boardIds, true);

            $columnIds = array_values(array_map(
                fn($column) => (int)$column['id'],
                array_filter($d['columns'] ?? [], fn($column) => isset($boardIdMap[(int)($column['board_id'] ?? 0)]))
            ));
            $columnIdMap = array_fill_keys($columnIds, true);

            $taskIds = array_values(array_map(
                fn($task) => (int)$task['id'],
                array_filter($d['tasks'] ?? [], fn($task) => isset($columnIdMap[(int)($task['column_id'] ?? 0)]))
            ));
            $taskIdMap = array_fill_keys($taskIds, true);

            $d['comments'] = array_values(array_filter($d['comments'] ?? [], fn($comment) => !isset($taskIdMap[(int)($comment['task_id'] ?? 0)])));
            $d['time_entries'] = array_values(array_filter($d['time_entries'] ?? [], fn($time) => !isset($taskIdMap[(int)($time['task_id'] ?? 0)])));
            $d['events'] = array_values(array_filter($d['events'] ?? [], fn($event) => !isset($taskIdMap[(int)($event['task_id'] ?? 0)])));
            $d['history'] = array_values(array_filter($d['history'] ?? [], fn($entry) => !isset($taskIdMap[(int)($entry['task_id'] ?? 0)])));
            $d['tasks'] = array_values(array_filter($d['tasks'] ?? [], fn($task) => !isset($columnIdMap[(int)($task['column_id'] ?? 0)])));
            $d['columns'] = array_values(array_filter($d['columns'] ?? [], fn($column) => !isset($boardIdMap[(int)($column['board_id'] ?? 0)])));
            $d['boards'] = array_values(array_filter($d['boards'] ?? [], fn($board) => (int)($board['project_id'] ?? 0) !== $projectId));
            $d['project_members'] = array_values(array_filter($d['project_members'] ?? [], fn($member) => (int)($member['project_id'] ?? 0) !== $projectId));
            array_splice($d['projects'], $projectIndex, 1);

            $d['events'][] = [
                'id' => $this->nid($d['events'] ?? []),
                'task_id' => null,
                'user_id' => (int)$u['id'],
                'type' => 'project_deleted',
                'message' => 'Projekt/Board gelöscht: ' . $projectName . ' (#' . $projectId . ')',
                'created_at' => $this->now(),
            ];

            $this->save($d);

            $customerCleanupWarning = null;
            $unlinkedCustomers = 0;

            try {
                if (function_exists('customer_load') && function_exists('customer_save')) {
                    $customerData = \customer_load();
                    foreach ($customerData['customers'] ?? [] as &$customer) {
                        $beforeProjectIds = array_values(array_map('intval', $customer['project_ids'] ?? []));
                        $beforeOrphanIds = array_values(array_map('intval', $customer['orphan_project_ids'] ?? []));
                        $afterProjectIds = array_values(array_filter($beforeProjectIds, fn($id) => (int)$id !== $projectId));
                        $afterOrphanIds = array_values(array_filter($beforeOrphanIds, fn($id) => (int)$id !== $projectId));

                        if ($beforeProjectIds !== $afterProjectIds || $beforeOrphanIds !== $afterOrphanIds) {
                            $customer['project_ids'] = $afterProjectIds;
                            $customer['orphan_project_ids'] = $afterOrphanIds;
                            $customer['has_orphan_projects'] = !empty($afterOrphanIds);
                            $customer['updated_at'] = function_exists('customer_now') ? \customer_now() : $this->now();
                            $unlinkedCustomers++;

                            if (function_exists('customer_event')) {
                                \customer_event(
                                    $customerData,
                                    (int)$u['id'],
                                    'project_unlinked',
                                    'Projekt/Board gelöscht und Kundenlink entfernt: ' . $projectName . ' (#' . $projectId . ')',
                                    (int)($customer['id'] ?? 0)
                                );
                            }
                        }
                    }
                    unset($customer);

                    \customer_save($customerData);
                }
            } catch (Throwable $e) {
                $customerCleanupWarning = $e->getMessage();
            }

            $this->out([
                'ok' => true,
                'deleted_project_id' => $projectId,
                'deleted_board_ids' => $boardIds,
                'deleted_task_count' => count($taskIds),
                'unlinked_customers' => $unlinkedCustomers,
                'customer_cleanup_warning' => $customerCleanupWarning,
            ]);
            break;

        case 'save_user':
            // Erstellt oder aktualisiert Benutzer. Nur Admin darf diese Aktion ausführen.
            if ($u['role'] !== 'admin') {
                $this->out(['ok' => false, 'error' => 'Keine Berechtigung'], 403);
            }
    
            $in = $this->body();
            $name = trim($in['username'] ?? '');
    
            if ($name === '') {
                $this->out(['ok' => false, 'error' => 'Benutzername erforderlich'], 422);
            }
    
            $role = in_array($in['role'] ?? 'user', ['admin', 'user', 'guest'], true)
                ? $in['role']
                : 'user';
    
            if (!empty($in['id'])) {
                $i = $this->idx($d['users'], (int)$in['id']);
    
                if ($i < 0) {
                    $this->out(['ok' => false, 'error' => 'Benutzer nicht gefunden'], 404);
                }
    
                $d['users'][$i]['username'] = $name;
                $d['users'][$i]['email'] = trim($in['email'] ?? '');
                $d['users'][$i]['role'] = $role;
    
                if (isset($in['is_active'])) {
                    $d['users'][$i]['is_active'] = (bool)(int)$in['is_active'];
                }
    
                if (!empty($in['password'])) {
                    $d['users'][$i]['password_hash'] = password_hash($in['password'], PASSWORD_BCRYPT);
                }
            } else {
                if (empty($in['password'])) {
                    $this->out(['ok' => false, 'error' => 'Passwort erforderlich'], 422);
                }
    
                $d['users'][] = [
                    'id' => $this->nid($d['users']),
                    'username' => $name,
                    'email' => trim($in['email'] ?? ''),
                    'password_hash' => password_hash($in['password'], PASSWORD_BCRYPT),
                    'role' => $role,
                    'is_active' => isset($in['is_active']) ? (bool)(int)$in['is_active'] : true,
                    'created_at' => $this->now(),
                ];
            }
    
            $this->save($d);
            $this->out(['ok' => true]);
            break;
    
        case 'delete_user':
            // Löscht Benutzer nur, wenn keine abhängigen Aufgaben/Kommentare/Zeiten existieren.
            if (($u['role'] ?? '') !== 'admin') {
                $this->out(['ok' => false, 'error' => 'Nur Admin darf Benutzer löschen'], 403);
            }
    
            $in = $this->body();
            $id = (int)($in['id'] ?? 0);
    
            if ($id <= 0) {
                $this->out(['ok' => false, 'error' => 'Benutzer-ID fehlt'], 422);
            }
    
            if ($id === (int)$u['id']) {
                $this->out(['ok' => false, 'error' => 'Der eigene Admin-Benutzer kann nicht gelöscht werden'], 409);
            }
    
            $i = $this->idx($d['users'], $id);
    
            if ($i < 0) {
                $this->out(['ok' => false, 'error' => 'Benutzer nicht gefunden'], 404);
            }
    
            foreach ($d['tasks'] ?? [] as $t) {
                if ((int)($t['assigned_to'] ?? 0) === $id) {
                    $this->out(['ok' => false, 'error' => 'Benutzer hat noch zugewiesene Aufgaben. Bitte zuerst Aufgaben umweisen oder Benutzer sperren.'], 409);
                }
            }
    
            foreach ($d['comments'] ?? [] as $c) {
                if ((int)($c['user_id'] ?? 0) === $id) {
                    $this->out(['ok' => false, 'error' => 'Benutzer hat Kommentare. Aus Nachvollziehbarkeit besser sperren statt löschen.'], 409);
                }
            }
    
            foreach ($d['time_entries'] ?? [] as $te) {
                if ((int)($te['user_id'] ?? 0) === $id) {
                    $this->out(['ok' => false, 'error' => 'Benutzer hat Zeiterfassungen. Aus Nachvollziehbarkeit besser sperren statt löschen.'], 409);
                }
            }
    
            foreach ($d['projects'] ?? [] as $project) {
                if ((int)($project['responsible_id'] ?? 0) === $id) {
                    $this->out(['ok' => false, 'error' => 'Benutzer ist noch Projektverantwortlicher. Bitte zuerst einen anderen Verantwortlichen setzen.'], 409);
                }
            }
    
            $d['project_members'] = array_values(array_filter($d['project_members'] ?? [], fn($member) => (int)($member['user_id'] ?? 0) !== $id));
    
            array_splice($d['users'], $i, 1);
    
            $this->save($d);
            $this->out(['ok' => true]);
            break;
    
        case 'toggle_user_lock':
            // Sperrt oder entsperrt einen Benutzer. Der eigene Admin darf nicht gesperrt werden.
            if (($u['role'] ?? '') !== 'admin') {
                $this->out(['ok' => false, 'error' => 'Nur Admin darf Benutzer sperren/freigeben'], 403);
            }
    
            $in = $this->body();
            $id = (int)($in['id'] ?? 0);
    
            if ($id === (int)$u['id']) {
                $this->out(['ok' => false, 'error' => 'Der eigene Admin-Benutzer kann nicht gesperrt werden'], 409);
            }
    
            $i = $this->idx($d['users'], $id);
    
            if ($i < 0) {
                $this->out(['ok' => false, 'error' => 'Benutzer nicht gefunden'], 404);
            }
    
            $d['users'][$i]['is_active'] = !(bool)($d['users'][$i]['is_active'] ?? true);
    
            $this->save($d);
            $this->out(['ok' => true, 'user' => $d['users'][$i]]);
            break;
    
        case 'json_export':
            // Exportiert den aktuellen vollständigen Datenstand als JSON-Datei.
            $this->require_json_sync_user($u);
    
            $export = $d;
            $export['meta']['exported_at'] = $this->now();
            $export['meta']['exported_by'] = $u['username'] ?? '';
            $export['meta']['export_type'] = 'polarisnova_full_backup';
    
            $this->download_json($export, 'polarisnova_backup_' . date('Ymd_His') . '.json');
            break;
    
        case 'json_import':
            // Importiert JSON nur im Offline-Fallback, damit eine erreichbare SQL-Datenbank nicht versehentlich überschrieben wird.
            $this->require_json_sync_user($u);
    
            if ($this->storage->isDatabaseAvailable()) {
                $this->out(['ok' => false, 'error' => 'JSON-Import ist nur erlaubt, wenn MySQL/PDO nicht erreichbar ist und der JSON-Offline-Fallback aktiv ist.'], 409);
            }
    
            $in = $this->body();
            $incoming = $in['data'] ?? null;
            $mode = ($in['mode'] ?? 'merge') === 'replace' && ($u['role'] ?? '') === 'admin' ? 'replace' : 'merge';
    
            if (!is_array($incoming)) {
                $this->out(['ok' => false, 'error' => 'Keine gültigen JSON-Daten im Import gefunden'], 422);
            }
    
            try {
                $result = $this->storage->importJsonSafely($incoming, $d, $u, $mode);
            } catch (Throwable $e) {
                $this->out(['ok' => false, 'error' => $e->getMessage()], 422);
            }
    
            $this->out([
                'ok' => true,
                'message' => 'JSON wurde sicher importiert. Vorheriger Stand wurde gesichert.',
                'backup_file' => $result['backup_file'] ?? '',
                'report' => $result['report'] ?? [],
            ]);
            break;
    
        case 'json_mysql_restore_preview':
            // Admin-Vorschau: data.json gegen aktuellen MySQL-Stand vergleichen.
            $this->require_global_admin($u);
    
            if (!$this->storage->isDatabaseAvailable()) {
                $this->out(['ok' => false, 'error' => 'MySQL/PDO ist nicht erreichbar. Eine Wiederherstellung nach MySQL ist erst möglich, wenn die Datenbank wieder online ist.'], 409);
            }
    
            try {
                $preview = $this->storage->previewJsonFileToMysql();
            } catch (Throwable $e) {
                $this->out(['ok' => false, 'error' => $e->getMessage()], 422);
            }
    
            $this->out([
                'ok' => true,
                'message' => 'Vorschau erstellt. Es wurde noch nichts in MySQL geschrieben.',
                'preview' => $preview,
            ]);
            break;
    
        case 'json_mysql_restore_commit':
            // Admin-Commit: geprüften JSON-Stand ACID-artig nach MySQL übernehmen.
            $this->require_global_admin($u);
    
            if (!$this->storage->isDatabaseAvailable()) {
                $this->out(['ok' => false, 'error' => 'MySQL/PDO ist nicht erreichbar. Wiederherstellung abgebrochen.'], 409);
            }
    
            $in = $this->body();
            $policy = ($in['conflict_policy'] ?? 'keep_mysql') === 'json_wins' ? 'json_wins' : 'keep_mysql';
    
            try {
                $result = $this->storage->restoreJsonFileToMysqlSafely($u, $policy);
            } catch (Throwable $e) {
                $this->out(['ok' => false, 'error' => $e->getMessage()], 422);
            }
    
            $this->out([
                'ok' => true,
                'message' => 'JSON-Stand wurde geprüft und sicher nach MySQL wiederhergestellt.',
                'backup_file' => $result['backup_file'] ?? '',
                'conflict_policy' => $result['conflict_policy'] ?? $policy,
                'preview' => $result['preview'] ?? [],
                'report' => $result['report'] ?? [],
            ]);
            break;
    
        case 'reports':
            // Erstellt abrechnungsfähige Reportdaten für das aktuell ausgewählte Projektboard.
            // Neben der Aufgaben-Summe werden Buchungen nach Mitarbeiter, Tag, Monat und Jahr
            // gruppiert. Dadurch ist nachvollziehbar, wer welche Zeit gestartet hat.
            $boardId = (int)($_GET['board_id'] ?? 0);
            [$visibleColumns, $visibleTasks, $visibleComments, $visibleTimes, $visibleEvents, $visibleHistory] = $this->board_payload($d, $u, $boardId);
    
            $ids = array_map(fn($t) => (int)$t['id'], $visibleTasks);
            $tasksById = [];
            $usersById = [];
            $r = [];
    
            foreach ($visibleTasks as $t) {
                $taskId = (int)$t['id'];
                $tasksById[$taskId] = $t;
                $r[$taskId] = ['task' => $t, 'seconds' => 0];
            }
    
            foreach ($d['users'] ?? [] as $userRow) {
                $usersById[(int)$userRow['id']] = $userRow;
            }
    
            $details = [];
            $byUser = [];
            $byDay = [];
            $byMonth = [];
            $byYear = [];
            $byUserDay = [];
            $byUserMonth = [];
            $byUserYear = [];
            $totalSeconds = 0;
    
            $addSummary = function (&$bucket, $key, $label, $seconds, $extra = []) {
                if (!isset($bucket[$key])) {
                    $bucket[$key] = array_merge(['key' => $key, 'label' => $label, 'seconds' => 0, 'entries' => 0], $extra);
                }
    
                $bucket[$key]['seconds'] += $seconds;
                $bucket[$key]['entries']++;
            };
    
            foreach ($visibleTimes as $te) {
                $taskId = (int)($te['task_id'] ?? 0);
                $userId = (int)($te['user_id'] ?? 0);
                $startTime = strtotime((string)($te['started_at'] ?? '')) ?: time();
                $stopTime = !empty($te['stopped_at']) ? strtotime((string)$te['stopped_at']) : false;
                $seconds = (int)($te['seconds'] ?? 0);
    
                if ($seconds <= 0) {
                    $seconds = $stopTime
                        ? max(1, $stopTime - $startTime)
                        : max(1, time() - $startTime);
                }
    
                $seconds = max(0, $seconds);
                $totalSeconds += $seconds;
    
                if (isset($r[$taskId])) {
                    $r[$taskId]['seconds'] += $seconds;
                }
    
                $dateKey = date('Y-m-d', $startTime);
                $monthKey = date('Y-m', $startTime);
                $yearKey = date('Y', $startTime);
                $userName = $usersById[$userId]['username'] ?? 'Benutzer #' . $userId;
                $taskTitle = $tasksById[$taskId]['title'] ?? 'Aufgabe #' . $taskId;
    
                $details[] = [
                    'id' => (int)($te['id'] ?? 0),
                    'task_id' => $taskId,
                    'task_title' => $taskTitle,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'started_at' => $te['started_at'] ?? null,
                    'stopped_at' => $te['stopped_at'] ?? null,
                    'seconds' => $seconds,
                    'running' => empty($te['stopped_at']),
                    'day' => $dateKey,
                    'month' => $monthKey,
                    'year' => $yearKey,
                ];
    
                $addSummary($byUser, (string)$userId, $userName, $seconds, ['user_id' => $userId, 'user_name' => $userName]);
                $addSummary($byDay, $dateKey, date('d.m.Y', $startTime), $seconds, ['day' => $dateKey]);
                $addSummary($byMonth, $monthKey, date('m.Y', $startTime), $seconds, ['month' => $monthKey]);
                $addSummary($byYear, $yearKey, $yearKey, $seconds, ['year' => $yearKey]);
                $addSummary($byUserDay, $dateKey . '|' . $userId, date('d.m.Y', $startTime) . ' · ' . $userName, $seconds, ['day' => $dateKey, 'user_id' => $userId, 'user_name' => $userName]);
                $addSummary($byUserMonth, $monthKey . '|' . $userId, date('m.Y', $startTime) . ' · ' . $userName, $seconds, ['month' => $monthKey, 'user_id' => $userId, 'user_name' => $userName]);
                $addSummary($byUserYear, $yearKey . '|' . $userId, $yearKey . ' · ' . $userName, $seconds, ['year' => $yearKey, 'user_id' => $userId, 'user_name' => $userName]);
            }
    
            usort($details, fn($a, $b) => strcmp((string)($b['started_at'] ?? ''), (string)($a['started_at'] ?? '')));
    
            $sortSummary = function ($summary) {
                uasort($summary, fn($a, $b) => strcmp((string)$a['key'], (string)$b['key']));
    
                return array_values($summary);
            };
    
            $byUser = $sortSummary($byUser);
            $byDay = $sortSummary($byDay);
            $byMonth = $sortSummary($byMonth);
            $byYear = $sortSummary($byYear);
            $byUserDay = $sortSummary($byUserDay);
            $byUserMonth = $sortSummary($byUserMonth);
            $byUserYear = $sortSummary($byUserYear);
    
            $hist = array_values(array_filter($visibleHistory, fn($h) => in_array((int)$h['task_id'], $ids, true)));
            usort($hist, fn($a, $b) => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
    
            $this->out([
                'ok' => true,
                'reports' => array_values($r),
                'time_details' => $details,
                'time_summary' => [
                    'total_seconds' => $totalSeconds,
                    'by_user' => $byUser,
                    'by_day' => $byDay,
                    'by_month' => $byMonth,
                    'by_year' => $byYear,
                    'by_user_day' => $byUserDay,
                    'by_user_month' => $byUserMonth,
                    'by_user_year' => $byUserYear,
                ],
                'history' => array_slice($hist, 0, 50),
            ]);
            break;
    
        default:
            // Fallback für unbekannte oder falsch geschriebene Aktionen.
            $this->out(['ok' => false, 'error' => 'Unbekannte Aktion'], 404);
    }
    
    }
}
