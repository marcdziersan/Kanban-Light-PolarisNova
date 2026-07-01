<?php

declare(strict_types=1);

namespace App\Domain\Kanban;

trait KanbanTaskRulesTrait
{
    /**
     * Liest eine führende Nummer aus dem Aufgabentitel.
     * Dadurch werden Titel wie "03 Pflichtenheft erstellen" korrekt vor "10 Test" einsortiert.
     */
    private function task_number_from_title($task)
    {
        $title = (string)($task['title'] ?? '');
    
        if (preg_match('/^\s*(\d{1,6})(?=[\s.)\-:_]|$)/u', $title, $match)) {
            return (int)$match[1];
        }
    
        return null;
    }

    /**
     * Vergleichsfunktion für die automatische Sortierung innerhalb einer Spalte.
     * Nummerierte Aufgaben stehen zuerst und werden numerisch aufsteigend sortiert.
     * Aufgaben ohne führende Nummer folgen nach ihrer gespeicherten Position.
     */
    private function compare_tasks_auto($a, $b)
    {
        $aNumber = $this->task_number_from_title($a);
        $bNumber = $this->task_number_from_title($b);
        $aHasNumber = $aNumber !== null;
        $bHasNumber = $bNumber !== null;
    
        if ($aHasNumber && $bHasNumber && $aNumber !== $bNumber) {
            return $aNumber <=> $bNumber;
        }
    
        if ($aHasNumber !== $bHasNumber) {
            return $aHasNumber ? -1 : 1;
        }
    
        $aPosition = (int)($a['position'] ?? 999);
        $bPosition = (int)($b['position'] ?? 999);
    
        if ($aPosition !== $bPosition) {
            return $aPosition <=> $bPosition;
        }
    
        $titleCompare = strnatcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    
        if ($titleCompare !== 0) {
            return $titleCompare;
        }
    
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    }

    /**
     * Normalisiert die Positionswerte einer Spalte nach der automatischen Sortierung.
     * Damit bleibt die Sortierung auch nach Reload, Drag & Drop und Datenbank-Speicherung stabil.
     */
    private function normalize_task_positions_for_column(&$d, $columnId)
    {
        $columnId = (int)$columnId;
    
        if ($columnId <= 0) {
            return;
        }
    
        $indexes = [];
    
        foreach ($d['tasks'] ?? [] as $index => $task) {
            if ((int)($task['column_id'] ?? 0) === $columnId) {
                $indexes[] = $index;
            }
        }
    
        usort($indexes, function ($leftIndex, $rightIndex) use (&$d) {
            return $this->compare_tasks_auto($d['tasks'][$leftIndex], $d['tasks'][$rightIndex]);
        });
    
        $position = 1;
    
        foreach ($indexes as $index) {
            $d['tasks'][$index]['position'] = $position++;
        }
    }

    /**
     * Normalisiert mehrere Spalten nach einer Änderung, z. B. Quell- und Zielspalte bei Drag & Drop.
     */
    private function normalize_task_positions_for_columns(&$d, $columnIds)
    {
        $columnIds = array_values(array_unique(array_map('intval', (array)$columnIds)));
    
        foreach ($columnIds as $columnId) {
            $this->normalize_task_positions_for_column($d, $columnId);
        }
    }

    /**
     * Prüft, ob der aktuelle Benutzer eine Aufgabe ändern darf.
     * Admin darf alles, Gast darf nichts ändern, User nur eigene Aufgaben.
     */
    private function task_access($d, $u, $task)
    {
        if ($this->can_modify_task($d, $u, $task)) {
            return;
        }
    
        if (($u['role'] ?? '') === 'guest') {
            $this->out(['ok' => false, 'error' => 'Gäste dürfen nicht ändern'], 403);
        }
    
        $this->out(['ok' => false, 'error' => 'Aufgabe gehört nicht zu einem Projekt, dem Sie zugeordnet sind'], 403);
    }

    /**
     * Prüft, ob eine Aufgabe durch einen anderen Benutzer gesperrt ist.
     */
    private function task_locked_by_other($u, $task)
    {
        return !empty($task['locked_by']) && (int)$task['locked_by'] !== (int)$u['id'];
    }

    /**
     * Bricht Änderungen ab, wenn die Aufgabe durch einen anderen Benutzer gesperrt ist.
     */
    private function require_not_locked($u, $task)
    {
        if ($this->task_locked_by_other($u, $task)) {
            $this->out(['ok' => false, 'error' => 'Aufgabe ist aktuell durch einen anderen Benutzer gesperrt'], 423);
        }
    }

    /**
     * Liefert den Namen einer Spalte anhand ihrer ID.
     */
    private function column_name_by_id($d, $id)
    {
        foreach ($d['columns'] ?? [] as $c) {
            if ((int)$c['id'] === (int)$id) {
                return strtolower($c['name'] ?? '');
            }
        }
    
        return '';
    }

    /**
     * Erkennt eine "In Arbeit"-Spalte über den Spaltennamen.
     */
    private function is_in_progress_column($d, $id)
    {
        $n = $this->column_name_by_id($d, $id);
    
        return str_contains($n, 'arbeit') || str_contains($n, 'progress');
    }

    /**
     * Erkennt eine erledigte Spalte über den Spaltennamen.
     */
    private function is_done_column($d, $id)
    {
        $n = $this->column_name_by_id($d, $id);
    
        return str_contains($n, 'erledigt') || str_contains($n, 'done') || str_contains($n, 'fertig');
    }

    /**
     * Zählt, wie viele Aufgaben ein Benutzer aktuell in Arbeit hat.
     */
    private function count_user_in_progress($d, $user_id, $ignore_task_id = 0)
    {
        $cnt = 0;
    
        foreach ($d['tasks'] ?? [] as $t) {
            if ((int)($t['id'] ?? 0) === (int)$ignore_task_id) {
                continue;
            }
    
            if ((int)($t['assigned_to'] ?? 0) === (int)$user_id && $this->is_in_progress_column($d, (int)($t['column_id'] ?? 0))) {
                $cnt++;
            }
        }
    
        return $cnt;
    }

    /**
     * Erzwingt fachliche Board-Regeln beim Speichern und Verschieben von Aufgaben.
     */
    private function enforce_column_rules($d, $u, $task, $target_column_id)
    {
        // Globale Admins und Projektverantwortliche dürfen alle Spaltenwechsel im Projekt ausführen.
        if ($this->can_manage_board($d, $u, $this->board_id_by_column_id($d, $target_column_id))) {
            return;
        }
    
        // Normale Nutzer dürfen nicht selbst nach "Erledigt" verschieben.
        if ($this->is_done_column($d, $target_column_id)) {
            $this->out(['ok' => false, 'error' => 'Nur Admins oder Projektverantwortliche dürfen Aufgaben nach Erledigt verschieben'], 403);
        }
    
        // Pro Nutzer dürfen maximal zwei Aufgaben gleichzeitig in Arbeit stehen.
        if ($this->is_in_progress_column($d, $target_column_id)) {
            $assigned = (int)($task['assigned_to'] ?? 0);
    
            if ($assigned <= 0) {
                $assigned = (int)$u['id'];
            }
    
            if ($assigned !== (int)$u['id']) {
                $this->out(['ok' => false, 'error' => 'Aufgabe ist nicht Ihnen zugewiesen'], 403);
            }
    
            if ($this->count_user_in_progress($d, $assigned, (int)($task['id'] ?? 0)) >= 2) {
                $this->out(['ok' => false, 'error' => 'Limit erreicht: Pro Nutzer dürfen maximal 2 Aufgaben gleichzeitig in Arbeit stehen'], 409);
            }
        }
    }
}
