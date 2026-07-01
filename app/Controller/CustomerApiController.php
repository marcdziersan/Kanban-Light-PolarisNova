<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Domain\Customer\CustomerAccessTrait;
use App\Domain\Customer\CustomerInputTrait;
use App\Domain\Customer\CustomerProjectionTrait;
use App\Storage\CustomerStorageAdapter;
use App\Storage\KanbanStorageAdapter;
use Throwable;

final class CustomerApiController
{
    use CustomerAccessTrait;
    use CustomerInputTrait;
    use CustomerProjectionTrait;

    private KanbanStorageAdapter $kanbanStorage;
    private CustomerStorageAdapter $customerStorage;
    private Request $request;

    public function __construct(?KanbanStorageAdapter $kanbanStorage = null, ?CustomerStorageAdapter $customerStorage = null, ?Request $request = null)
    {
        $this->kanbanStorage = $kanbanStorage ?? new KanbanStorageAdapter();
        $this->customerStorage = $customerStorage ?? new CustomerStorageAdapter();
        $this->request = $request ?? new Request();
    }

private function customer_api_out(array $data, int $code = 200): void
    {
        JsonResponse::send($data, $code);
    }
    
    private function customer_api_body(): array
    {
        return $this->request->jsonBody();
    }
    
    private function customer_api_load_polarisnova(): array
    {
        try {
            return $this->kanbanStorage->load();
        } catch (Throwable $e) {
            $this->customer_api_out(['ok' => false, 'error' => 'PolarisNova-Daten konnten nicht geladen werden: ' . $e->getMessage()], 500);
        }
    }
    
    private function customer_api_load_customers(): array
    {
        try {
            return $this->customerStorage->load();
        } catch (Throwable $e) {
            $this->customer_api_out(['ok' => false, 'error' => 'Kundendaten konnten nicht geladen werden: ' . $e->getMessage()], 500);
        }
    }
    
    private function customer_api_save_customers(array $data): void
    {
        try {
            $this->customerStorage->save($data);
        } catch (Throwable $e) {
            $this->customer_api_out(['ok' => false, 'error' => 'Kundendaten konnten nicht gespeichert werden: ' . $e->getMessage()], 500);
        }
    }
    
    // -----------------------------------------------------------------------------
    // PolarisNova-Zugriff und Sichtbarkeit
    // -----------------------------------------------------------------------------
    
    
    
    
    
    
    
    
    
    
    
    
    
    // -----------------------------------------------------------------------------
    // Aus PolarisNova abgeleitete Projektübersicht für die Kundenverwaltung
    // -----------------------------------------------------------------------------
    
    
    
    // -----------------------------------------------------------------------------
    // CRUD-Hilfsfunktionen für Kundendaten
    // -----------------------------------------------------------------------------
    
    
    
    
    // -----------------------------------------------------------------------------
    // Request-Verarbeitung
    // -----------------------------------------------------------------------------

    public function handle(): void
    {
    $polarisnova = $this->customer_api_load_polarisnova();
    $user = $this->customer_api_current_user($polarisnova);
    
    if (!$user) {
        $this->customer_api_out(['ok' => false, 'error' => 'Nicht angemeldet'], 401);
    }
    
    if (isset($user['is_active']) && !$user['is_active']) {
        session_destroy();
        $this->customer_api_out(['ok' => false, 'error' => 'Benutzer ist gesperrt'], 403);
    }
    
    $action = $_GET['action'] ?? 'bootstrap';
    $customerData = $this->customer_api_load_customers();
    
    switch ($action) {
        case 'bootstrap':
            $projects = $this->customer_api_project_summaries($polarisnova, $user);
            $customers = $this->customer_api_visible_customers($polarisnova, $customerData, $user);
            $canCreate = ($user['role'] ?? '') === 'admin';
    
            if (!$canCreate) {
                foreach ($projects as $project) {
                    if (!empty($project['is_responsible'])) {
                        $canCreate = true;
                        break;
                    }
                }
            }
    
            $orphanCount = 0;
            foreach ($customers as $customer) {
                if (!empty($customer['has_orphan_projects'])) {
                    $orphanCount++;
                }
            }
            $customerData['meta']['orphan_customer_project_links'] = $orphanCount;
    
            $this->customer_api_out([
                'ok' => true,
                'data' => [
                    'user' => [
                        'id' => (int)$user['id'],
                        'username' => $user['username'] ?? '',
                        'role' => $user['role'] ?? 'user',
                    ],
                    'meta' => $customerData['meta'] ?? [],
                    'customers' => $customers,
                    'projects' => $projects,
                    'can_create_customer' => $canCreate,
                ],
            ]);
            break;
    
        case 'save_customer':
            if (($user['role'] ?? '') === 'guest') {
                $this->customer_api_out(['ok' => false, 'error' => 'Gäste dürfen keine Kundendaten ändern'], 403);
            }
    
            $input = $this->customer_api_body();
            $id = (int)($input['id'] ?? 0);
            $index = $id > 0 ? $this->customer_api_find_customer_index($customerData['customers'], $id) : -1;
            $old = $index >= 0 ? $customerData['customers'][$index] : [];
    
            if ($id > 0 && $index < 0) {
                $this->customer_api_out(['ok' => false, 'error' => 'Kunde nicht gefunden'], 404);
            }
    
            if ($id > 0 && !$this->customer_api_can_manage_customer($polarisnova, $user, $old)) {
                $this->customer_api_out(['ok' => false, 'error' => 'Keine Berechtigung für diesen Kunden'], 403);
            }
    
            $customer = $this->customer_api_build_customer_from_input($input, $old);
            $customer['project_ids'] = $this->customer_api_clean_project_ids($polarisnova, $customer['project_ids']);
    
            if (!$this->customer_api_can_create_for_projects($polarisnova, $user, $customer['project_ids'])) {
                $this->customer_api_out(['ok' => false, 'error' => 'Kunden dürfen nur von Admins oder Projektverantwortlichen für eigene Projekte gespeichert werden'], 403);
            }
    
            if ($id > 0) {
                $customerData['customers'][$index] = $customer;
                customer_event($customerData, (int)$user['id'], 'customer_updated', 'Kunde aktualisiert', $customer['id']);
            } else {
                $customer['id'] = customer_next_id($customerData['customers']);
                $customerData['customers'][] = $customer;
                customer_event($customerData, (int)$user['id'], 'customer_created', 'Kunde angelegt', $customer['id']);
            }
    
            $this->customer_api_save_customers($customerData);
            $this->customer_api_out(['ok' => true, 'customer' => $customer], $id > 0 ? 200 : 201);
            break;
    
        case 'delete_customer':
            if (($user['role'] ?? '') === 'guest') {
                $this->customer_api_out(['ok' => false, 'error' => 'Gäste dürfen keine Kundendaten löschen'], 403);
            }
    
            $input = $this->customer_api_body();
            $id = (int)($input['id'] ?? 0);
            $index = $this->customer_api_find_customer_index($customerData['customers'], $id);
    
            if ($index < 0) {
                $this->customer_api_out(['ok' => false, 'error' => 'Kunde nicht gefunden'], 404);
            }
    
            $customer = $customerData['customers'][$index];
    
            if (!$this->customer_api_can_manage_customer($polarisnova, $user, $customer)) {
                $this->customer_api_out(['ok' => false, 'error' => 'Keine Berechtigung für diesen Kunden'], 403);
            }
    
            array_splice($customerData['customers'], $index, 1);

            // Der gelöschte Kunde darf im Event nicht mehr als FK referenziert
            // werden, sonst scheitert der Vollspeicherlauf gegen kv_events.
            $deletedCompany = trim((string)($customer['company'] ?? ''));
            $deletedMessage = $deletedCompany !== ''
                ? 'Kunde gelöscht: ' . $deletedCompany . ' (#' . $id . ')'
                : 'Kunde gelöscht (#' . $id . ')';
            customer_event($customerData, (int)$user['id'], 'customer_deleted', $deletedMessage, null);

            $this->customer_api_save_customers($customerData);
            $this->customer_api_out(['ok' => true]);
            break;
    
        default:
            $this->customer_api_out(['ok' => false, 'error' => 'Unbekannte Aktion'], 404);
    }
    
    }
}
