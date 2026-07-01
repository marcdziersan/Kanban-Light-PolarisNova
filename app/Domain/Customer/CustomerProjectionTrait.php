<?php

declare(strict_types=1);

namespace App\Domain\Customer;

trait CustomerProjectionTrait
{
    private function customer_api_project_summaries(array $polarisnova, array $user): array
    {
        $accessible = $this->customer_api_accessible_project_ids($polarisnova, $user);
        $accessibleMap = array_flip($accessible);
    
        $boardByProject = [];
        foreach ($polarisnova['boards'] ?? [] as $board) {
            $projectId = (int)($board['project_id'] ?? 0);
            $boardByProject[$projectId][] = $board;
        }
    
        $columnById = [];
        $columnProject = [];
        foreach ($polarisnova['columns'] ?? [] as $column) {
            $columnById[(int)$column['id']] = $column;
            $boardId = (int)($column['board_id'] ?? 0);
    
            foreach ($polarisnova['boards'] ?? [] as $board) {
                if ((int)$board['id'] === $boardId) {
                    $columnProject[(int)$column['id']] = (int)$board['project_id'];
                    break;
                }
            }
        }
    
        $membersByProject = [];
        foreach ($polarisnova['project_members'] ?? [] as $member) {
            $projectId = (int)($member['project_id'] ?? 0);
            $membersByProject[$projectId][] = $this->customer_api_user_name($polarisnova, (int)$member['user_id']);
        }
    
        $summaries = [];
    
        foreach ($polarisnova['projects'] ?? [] as $project) {
            $projectId = (int)$project['id'];
    
            if (!isset($accessibleMap[$projectId])) {
                continue;
            }
    
            $counts = [
                'all' => 0,
                'open' => 0,
                'in_progress' => 0,
                'review' => 0,
                'done' => 0,
            ];
            $lastUpdate = '';
    
            foreach ($polarisnova['tasks'] ?? [] as $task) {
                $taskProjectId = $columnProject[(int)($task['column_id'] ?? 0)] ?? 0;
    
                if ($taskProjectId !== $projectId) {
                    continue;
                }
    
                $counts['all']++;
                $column = $columnById[(int)$task['column_id']] ?? [];
                $columnName = strtolower((string)($column['name'] ?? ''));
    
                if (str_contains($columnName, 'erledigt') || str_contains($columnName, 'done') || str_contains($columnName, 'fertig')) {
                    $counts['done']++;
                } elseif (str_contains($columnName, 'review') || str_contains($columnName, 'prüfung')) {
                    $counts['review']++;
                } elseif (str_contains($columnName, 'arbeit') || str_contains($columnName, 'progress')) {
                    $counts['in_progress']++;
                } else {
                    $counts['open']++;
                }
    
                $candidate = (string)($task['updated_at'] ?? $task['created_at'] ?? '');
                if ($candidate > $lastUpdate) {
                    $lastUpdate = $candidate;
                }
            }
    
            $boards = $boardByProject[$projectId] ?? [];
    
            $summaries[] = [
                'id' => $projectId,
                'name' => $project['name'] ?? ('Projekt #' . $projectId),
                'description' => $project['description'] ?? '',
                'responsible_id' => $project['responsible_id'] ?? null,
                'responsible_name' => $this->customer_api_user_name($polarisnova, isset($project['responsible_id']) ? (int)$project['responsible_id'] : null),
                'is_responsible' => $this->customer_api_is_project_responsible($polarisnova, $user, $projectId),
                'boards' => array_values(array_map(fn($board) => [
                    'id' => (int)$board['id'],
                    'name' => $board['name'] ?? ('Board #' . $board['id']),
                ], $boards)),
                'members' => array_values(array_unique($membersByProject[$projectId] ?? [])),
                'task_counts' => $counts,
                'last_task_update' => $lastUpdate,
            ];
        }
    
        return $summaries;
    }

    private function customer_api_visible_customers(array $polarisnova, array $customerData, array $user): array
    {
        $accessible = array_flip($this->customer_api_accessible_project_ids($polarisnova, $user));
        $visible = [];
    
        foreach ($customerData['customers'] ?? [] as $customer) {
            $customer = $this->customer_api_normalize_customer_project_state($polarisnova, $customer);
            $projectIds = array_values(array_filter(array_map('intval', $customer['project_ids'] ?? [])));
            $show = ($user['role'] ?? '') === 'admin';
    
            if (!$show) {
                foreach ($projectIds as $projectId) {
                    if (isset($accessible[$projectId])) {
                        $show = true;
                        break;
                    }
                }
            }
    
            if (!$show) {
                continue;
            }
    
            $customer['can_manage'] = $this->customer_api_can_manage_customer($polarisnova, $user, $customer);
            $visible[] = $customer;
        }
    
        usort($visible, fn($a, $b) => strcasecmp((string)($a['company'] ?? ''), (string)($b['company'] ?? '')));
    
        return $visible;
    }
}
