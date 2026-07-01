<?php

declare(strict_types=1);

namespace App\Domain\Kanban;

trait KanbanBoardPayloadTrait
{
    /**
     * Liefert nur Projekte, die der aktuelle Benutzer sehen darf.
     */
    private function visible_projects($d, $u)
    {
        return array_values(array_filter($d['projects'] ?? [], fn($project) => $this->can_access_project($d, $u, (int)$project['id'])));
    }

    /**
     * Liefert nur Boards, die der aktuelle Benutzer sehen darf.
     */
    private function visible_boards($d, $u)
    {
        $projectIds = array_map(fn($project) => (int)$project['id'], $this->visible_projects($d, $u));
    
        return array_values(array_filter($d['boards'] ?? [], fn($board) => in_array((int)$board['project_id'], $projectIds, true)));
    }

    /**
     * Liefert nur Zuordnungen, die zu sichtbaren Projekten gehören.
     */
    private function visible_project_members($d, $u)
    {
        $projectIds = array_map(fn($project) => (int)$project['id'], $this->visible_projects($d, $u));
    
        return array_values(array_filter($d['project_members'] ?? [], fn($member) => in_array((int)$member['project_id'], $projectIds, true)));
    }

    /**
     * Liefert alle Spalten eines Boards.
     */
    private function columns_for_board($d, $boardId)
    {
        return array_values(array_filter($d['columns'] ?? [], fn($column) => (int)$column['board_id'] === (int)$boardId));
    }

    /**
     * Liefert alle Aufgaben, Kommentare, Zeiten, Events und Historie für ein Board.
     */
    private function board_payload($d, $u, $boardId)
    {
        if ($boardId <= 0) {
            return [[], [], [], [], [], []];
        }
    
        $this->require_board_access($d, $u, $boardId);
    
        $columns = $this->columns_for_board($d, $boardId);
        $columnIds = array_map(fn($column) => (int)$column['id'], $columns);
    
        $tasks = array_values(array_filter($d['tasks'] ?? [], fn($task) => in_array((int)$task['column_id'], $columnIds, true)));
        usort($tasks, function ($a, $b) {
            $columnCompare = (int)($a['column_id'] ?? 0) <=> (int)($b['column_id'] ?? 0);
            return $columnCompare !== 0 ? $columnCompare : $this->compare_tasks_auto($a, $b);
        });
        $taskIds = array_map(fn($task) => (int)$task['id'], $tasks);
    
        $comments = array_values(array_filter($d['comments'] ?? [], fn($comment) => in_array((int)$comment['task_id'], $taskIds, true)));
        $times = array_values(array_filter($d['time_entries'] ?? [], fn($time) => in_array((int)$time['task_id'], $taskIds, true)));
        $events = array_values(array_filter($d['events'] ?? [], fn($event) => in_array((int)$event['task_id'], $taskIds, true)));
        $history = array_values(array_filter($d['history'] ?? [], fn($entry) => in_array((int)$entry['task_id'], $taskIds, true)));
    
        return [$columns, $tasks, $comments, $times, $events, $history];
    }

    /**
     * Erstellt Standardspalten für ein neues Projektboard.
     */
    private function create_default_columns_for_board(&$d, $boardId)
    {
        $names = ['Offen', 'In Arbeit', 'Review', 'Erledigt'];
        $position = 1;
    
        foreach ($names as $name) {
            $d['columns'][] = [
                'id' => $this->nid($d['columns'] ?? []),
                'board_id' => (int)$boardId,
                'name' => $name,
                'position' => $position++,
            ];
        }
    }
}
