<?php

declare(strict_types=1);

namespace App\Domain\Customer;

trait CustomerAccessTrait
{
    private function customer_api_current_user(array $polarisnova): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
    
        foreach ($polarisnova['users'] ?? [] as $user) {
            if ((int)$user['id'] === (int)$_SESSION['user_id']) {
                return $user;
            }
        }
    
        return null;
    }

    private function customer_api_user_name(array $polarisnova, ?int $userId): string
    {
        if (!$userId) {
            return '—';
        }
    
        foreach ($polarisnova['users'] ?? [] as $user) {
            if ((int)$user['id'] === $userId) {
                return (string)($user['username'] ?? ('User #' . $userId));
            }
        }
    
        return 'User #' . $userId;
    }

    private function customer_api_project_by_id(array $polarisnova, int $projectId): ?array
    {
        foreach ($polarisnova['projects'] ?? [] as $project) {
            if ((int)$project['id'] === $projectId) {
                return $project;
            }
        }
    
        return null;
    }

    /**
     * Erstellt eine schnelle Prüfliste aller aktuell vorhandenen PolarisNova-Projekte.
     */
    private function customer_api_existing_project_map(array $polarisnova): array
    {
        $map = [];
    
        foreach ($polarisnova['projects'] ?? [] as $project) {
            $id = (int)($project['id'] ?? 0);
    
            if ($id > 0) {
                $map[$id] = true;
            }
        }
    
        return $map;
    }

    /**
     * Trennt Projekt-IDs in gültige und verwaiste Bezüge.
     *
     * Verwaist bedeutet: Die Kundenverwaltung kennt noch eine Projekt-ID, aber das
     * zugehörige PolarisNova-Projekt existiert nicht mehr. Der Kunde bleibt bestehen;
     * im Frontend wird dann keine Projektzuordnung angezeigt.
     */
    private function customer_api_split_project_ids(array $polarisnova, array $projectIds, array $existingOrphans = []): array
    {
        $existingProjects = $this->customer_api_existing_project_map($polarisnova);
        $valid = [];
        $orphans = array_values(array_filter(array_map('intval', $existingOrphans)));
    
        foreach ($projectIds as $projectId) {
            $projectId = (int)$projectId;
    
            if ($projectId <= 0) {
                continue;
            }
    
            if (isset($existingProjects[$projectId])) {
                $valid[] = $projectId;
            } else {
                $orphans[] = $projectId;
            }
        }
    
        return [
            'valid' => array_values(array_unique($valid)),
            'orphan' => array_values(array_unique($orphans)),
        ];
    }

    /**
     * Bereitet einen Kunden für Rechteprüfung und Frontend vor.
     */
    private function customer_api_normalize_customer_project_state(array $polarisnova, array $customer): array
    {
        $split = $this->customer_api_split_project_ids(
            $polarisnova,
            $customer['project_ids'] ?? [],
            $customer['orphan_project_ids'] ?? []
        );
    
        $customer['project_ids'] = $split['valid'];
        $customer['orphan_project_ids'] = $split['orphan'];
        $customer['has_orphan_projects'] = !empty($split['orphan']);
    
        if ($customer['has_orphan_projects']) {
            $customer['project_warning'] = 'Eine frühere Projektzuordnung existiert nicht mehr. Der Kunde bleibt erhalten und wird als nicht zugeordnet angezeigt.';
        }
    
        return $customer;
    }

    private function customer_api_board_ids_for_project(array $polarisnova, int $projectId): array
    {
        $ids = [];
    
        foreach ($polarisnova['boards'] ?? [] as $board) {
            if ((int)($board['project_id'] ?? 0) === $projectId) {
                $ids[] = (int)$board['id'];
            }
        }
    
        return $ids;
    }

    private function customer_api_column_project_map(array $polarisnova): array
    {
        $boardToProject = [];
    
        foreach ($polarisnova['boards'] ?? [] as $board) {
            $boardToProject[(int)$board['id']] = (int)$board['project_id'];
        }
    
        $columnToProject = [];
    
        foreach ($polarisnova['columns'] ?? [] as $column) {
            $boardId = (int)($column['board_id'] ?? 0);
            $columnToProject[(int)$column['id']] = $boardToProject[$boardId] ?? 0;
        }
    
        return $columnToProject;
    }

    private function customer_api_accessible_project_ids(array $polarisnova, array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return array_values(array_map(fn($project) => (int)$project['id'], $polarisnova['projects'] ?? []));
        }
    
        $ids = [];
        $userId = (int)$user['id'];
    
        foreach ($polarisnova['projects'] ?? [] as $project) {
            if ((int)($project['owner_id'] ?? 0) === $userId || (int)($project['responsible_id'] ?? 0) === $userId) {
                $ids[] = (int)$project['id'];
            }
        }
    
        foreach ($polarisnova['project_members'] ?? [] as $member) {
            if ((int)($member['user_id'] ?? 0) === $userId) {
                $ids[] = (int)$member['project_id'];
            }
        }
    
        $columnToProject = $this->customer_api_column_project_map($polarisnova);
    
        foreach ($polarisnova['tasks'] ?? [] as $task) {
            if ((int)($task['assigned_to'] ?? 0) === $userId) {
                $projectId = $columnToProject[(int)($task['column_id'] ?? 0)] ?? 0;
    
                if ($projectId > 0) {
                    $ids[] = $projectId;
                }
            }
        }
    
        return array_values(array_unique(array_filter($ids)));
    }

    private function customer_api_is_project_responsible(array $polarisnova, array $user, int $projectId): bool
    {
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
    
        $project = $this->customer_api_project_by_id($polarisnova, $projectId);
    
        return $project && (int)($project['responsible_id'] ?? 0) === (int)$user['id'];
    }

    private function customer_api_can_manage_customer(array $polarisnova, array $user, array $customer): bool
    {
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
    
        if (($user['role'] ?? '') === 'guest') {
            return false;
        }
    
        $customer = $this->customer_api_normalize_customer_project_state($polarisnova, $customer);
        $projectIds = array_values(array_filter(array_map('intval', $customer['project_ids'] ?? [])));
    
        if (!$projectIds) {
            return false;
        }
    
        foreach ($projectIds as $projectId) {
            if (!$this->customer_api_is_project_responsible($polarisnova, $user, $projectId)) {
                return false;
            }
        }
    
        return true;
    }

    private function customer_api_can_create_for_projects(array $polarisnova, array $user, array $projectIds): bool
    {
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
    
        if (($user['role'] ?? '') === 'guest' || !$projectIds) {
            return false;
        }
    
        foreach ($projectIds as $projectId) {
            if (!$this->customer_api_is_project_responsible($polarisnova, $user, (int)$projectId)) {
                return false;
            }
        }
    
        return true;
    }
}
