<?php

declare(strict_types=1);

namespace App\Domain\Customer;

trait CustomerInputTrait
{
    private function customer_api_find_customer_index(array $customers, int $id): int
    {
        foreach ($customers as $index => $customer) {
            if ((int)($customer['id'] ?? 0) === $id) {
                return $index;
            }
        }
    
        return -1;
    }

    private function customer_api_clean_project_ids(array $polarisnova, array $projectIds): array
    {
        return $this->customer_api_split_project_ids($polarisnova, $projectIds)['valid'];
    }

    private function customer_api_build_customer_from_input(array $input, array $old = []): array
    {
        $company = trim((string)($input['company'] ?? ''));
    
        if ($company === '') {
            $this->customer_api_out(['ok' => false, 'error' => 'Kundenname/Firma ist erforderlich'], 422);
        }
    
        $status = (string)($input['status'] ?? 'lead');
        if (!in_array($status, ['lead', 'active', 'paused', 'archived'], true)) {
            $status = 'lead';
        }
    
        $type = (string)($input['type'] ?? 'customer');
        if (!in_array($type, ['customer', 'prospect', 'partner', 'internal'], true)) {
            $type = 'customer';
        }
    
        return [
            'id' => (int)($old['id'] ?? 0),
            'company' => $company,
            'contact_name' => trim((string)($input['contact_name'] ?? '')),
            'email' => trim((string)($input['email'] ?? '')),
            'phone' => trim((string)($input['phone'] ?? '')),
            'website' => trim((string)($input['website'] ?? '')),
            'address' => trim((string)($input['address'] ?? '')),
            'city' => trim((string)($input['city'] ?? '')),
            'type' => $type,
            'status' => $status,
            'source' => trim((string)($input['source'] ?? '')),
            'notes' => trim((string)($input['notes'] ?? '')),
            'project_ids' => array_values(array_map('intval', $input['project_ids'] ?? [])),
            'created_at' => $old['created_at'] ?? customer_now(),
            'updated_at' => customer_now(),
        ];
    }
}
