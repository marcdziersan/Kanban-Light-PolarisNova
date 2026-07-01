<?php

declare(strict_types=1);

namespace App\Domain\Kanban;

trait KanbanAuditTrait
{
    /**
     * Schreibt einen Eintrag in das Änderungsprotokoll einer Aufgabe.
     */
    private function history_add(&$d, $task_id, $user_id, $action, $field = null, $old = null, $new = null, $message = '')
    {
        if (!isset($d['history']) || !is_array($d['history'])) {
            $d['history'] = [];
        }
    
        $d['history'][] = [
            'id' => $this->nid($d['history']),
            'task_id' => (int)$task_id,
            'user_id' => (int)$user_id,
            'action' => $action,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'message' => $message,
            'created_at' => $this->now(),
        ];
    }

    /**
     * Vergleicht alte und neue Aufgabendaten und protokolliert geänderte Felder.
     */
    private function history_diff_task(&$d, $old, $new, $user_id)
    {
        $fields = [
            'title' => 'Titel',
            'description' => 'Beschreibung',
            'priority' => 'Priorität',
            'assigned_to' => 'Zuweisung',
            'column_id' => 'Spalte',
            'due_at' => 'Stichtag',
        ];
    
        foreach ($fields as $field => $label) {
            $ov = $old[$field] ?? null;
            $nv = $new[$field] ?? null;
    
            if ((string)$ov !== (string)$nv) {
                $this->history_add($d, $new['id'], $user_id, 'task_changed', $field, $ov, $nv, $label . ' geändert');
            }
        }
    }

    /**
     * Schreibt ein Ereignis für Timeline/Systemmeldungen.
     */
    private function event(&$d, $tid, $uid, $type, $msg)
    {
        $d['events'][] = [
            'id' => $this->nid($d['events'] ?? []),
            'task_id' => (int)$tid,
            'user_id' => (int)$uid,
            'type' => $type,
            'message' => $msg,
            'created_at' => $this->now(),
        ];
    }
}
