<?php

declare(strict_types=1);

namespace App\Domain\Kanban;

trait KanbanAccessTrait
{
    /**
     * Liefert ein Projekt anhand seiner ID oder null.
     */
    private function project_by_id($d, $projectId)
    {
        foreach ($d['projects'] ?? [] as $project) {
            if ((int)$project['id'] === (int)$projectId) {
                return $project;
            }
        }
    
        return null;
    }

    /**
     * Liefert ein Board anhand seiner ID oder null.
     */
    private function board_by_id($d, $boardId)
    {
        foreach ($d['boards'] ?? [] as $board) {
            if ((int)$board['id'] === (int)$boardId) {
                return $board;
            }
        }
    
        return null;
    }

    /**
     * Ermittelt die Board-ID einer Spalte.
     */
    private function board_id_by_column_id($d, $columnId)
    {
        foreach ($d['columns'] ?? [] as $column) {
            if ((int)$column['id'] === (int)$columnId) {
                return (int)$column['board_id'];
            }
        }
    
        return 0;
    }

    /**
     * Ermittelt die Projekt-ID eines Boards.
     */
    private function project_id_by_board_id($d, $boardId)
    {
        $board = $this->board_by_id($d, $boardId);
    
        return $board ? (int)$board['project_id'] : 0;
    }

    /**
     * Ermittelt die Board-ID einer Aufgabe über ihre Spalte.
     */
    private function board_id_by_task($d, $task)
    {
        return $this->board_id_by_column_id($d, (int)($task['column_id'] ?? 0));
    }

    /**
     * Ermittelt alle Projekt-IDs, denen ein Mitarbeiter zugeordnet ist.
     */
    private function project_ids_for_user($d, $userId)
    {
        $ids = [];
    
        foreach ($d['project_members'] ?? [] as $member) {
            if ((int)($member['user_id'] ?? 0) === (int)$userId) {
                $ids[] = (int)$member['project_id'];
            }
        }
    
        return array_values(array_unique($ids));
    }

    /**
     * Liefert den projektverantwortlichen Benutzer eines Projekts oder 0.
     */
    private function project_responsible_id($d, $projectId)
    {
        $project = $this->project_by_id($d, $projectId);
    
        return $project ? (int)($project['responsible_id'] ?? 0) : 0;
    }

    /**
     * Prüft, ob der aktuelle Benutzer für ein Projekt verantwortlich ist.
     * Ein Projektverantwortlicher erhält Admin-Rechte nur innerhalb dieses Projekts.
     */
    private function is_project_responsible($d, $u, $projectId)
    {
        return (int)($u['id'] ?? 0) > 0
            && $this->project_responsible_id($d, $projectId) === (int)$u['id'];
    }

    /**
     * Prüft projektbezogene Admin-Rechte.
     * Globale Admins dürfen alles, Projektverantwortliche nur ihr jeweiliges Projekt.
     */
    private function can_manage_project($d, $u, $projectId)
    {
        if (($u['role'] ?? '') === 'admin') {
            return true;
        }
    
        return $this->is_project_responsible($d, $u, $projectId);
    }

    /**
     * Prüft projektbezogene Admin-Rechte für ein Board.
     */
    private function can_manage_board($d, $u, $boardId)
    {
        $projectId = $this->project_id_by_board_id($d, $boardId);
    
        return $projectId > 0 && $this->can_manage_project($d, $u, $projectId);
    }

    /**
     * Prüft projektbezogene Admin-Rechte für eine Aufgabe.
     */
    private function can_manage_task($d, $u, $task)
    {
        $boardId = $this->board_id_by_task($d, $task);
    
        return $boardId > 0 && $this->can_manage_board($d, $u, $boardId);
    }

    /**
     * Prüft, ob ein Benutzer als Mitarbeiter einem Projekt zugeordnet ist.
     */
    private function is_project_member($d, $u, $projectId)
    {
        if ((int)($u['id'] ?? 0) <= 0 || (int)$projectId <= 0) {
            return false;
        }
    
        return in_array((int)$projectId, $this->project_ids_for_user($d, (int)$u['id']), true);
    }

    /**
     * Prüft, ob ein Benutzer eine Aufgabe fachlich bearbeiten darf.
     *
     * Wichtig: Projektmitgliedschaft berechtigt zur Mitarbeit an Aufgaben innerhalb
     * des Projekts. Globale Admins und Projektverantwortliche bleiben darüber
     * hinaus verwaltende Rollen. Gäste bleiben reine Ansicht.
     */
    private function can_modify_task($d, $u, $task)
    {
        if ($this->can_manage_task($d, $u, $task)) {
            return true;
        }
    
        if (($u['role'] ?? '') === 'guest') {
            return false;
        }
    
        $boardId = $this->board_id_by_task($d, $task);
        $projectId = $this->project_id_by_board_id($d, $boardId);
    
        if ($projectId > 0 && $this->is_project_member($d, $u, $projectId)) {
            return true;
        }
    
        return (int)($task['assigned_to'] ?? 0) === (int)($u['id'] ?? 0);
    }

    /**
     * Prüft, ob der aktuelle Benutzer ein Projekt sehen darf.
     * Admin und Gast sehen alle Projekte, Mitarbeiter nur zugeordnete Projekte.
     */
    private function can_access_project($d, $u, $projectId)
    {
        if (($u['role'] ?? '') === 'admin' || ($u['role'] ?? '') === 'guest') {
            return true;
        }
    
        if ($this->is_project_responsible($d, $u, $projectId)) {
            return true;
        }
    
        return in_array((int)$projectId, $this->project_ids_for_user($d, (int)$u['id']), true);
    }

    /**
     * Prüft, ob der aktuelle Benutzer ein Board sehen darf.
     */
    private function can_access_board($d, $u, $boardId)
    {
        $projectId = $this->project_id_by_board_id($d, $boardId);
    
        return $projectId > 0 && $this->can_access_project($d, $u, $projectId);
    }

    /**
     * Bricht ab, wenn der Benutzer keinen Zugriff auf ein Board besitzt.
     */
    private function require_board_access($d, $u, $boardId)
    {
        if ($boardId <= 0 || !$this->can_access_board($d, $u, $boardId)) {
            $this->out(['ok' => false, 'error' => 'Kein Zugriff auf dieses Projektboard'], 403);
        }
    }

    /**
     * Bricht ab, wenn eine Zielspalte nicht zum sichtbaren Board gehört.
     */
    private function require_column_access($d, $u, $columnId)
    {
        $boardId = $this->board_id_by_column_id($d, $columnId);
    
        if ($boardId <= 0) {
            $this->out(['ok' => false, 'error' => 'Spalte nicht gefunden'], 404);
        }
    
        $this->require_board_access($d, $u, $boardId);
    }
}
