<?php
/**
 * Eigenständige Datenhaltung der Kundenverwaltung.
 *
 * Architektur:
 * - Kundendaten liegen in derselben MySQL-Datenbank wie PolarisNova, aber in
 *   eindeutig getrennten Tabellen mit dem Präfix `kv_`.
 * - Die PolarisNova-/Kanban-Tabellen werden nur gelesen, z. B. für Projektbezüge.
 * - Die Kundenverwaltung nutzt dieselben Benutzer/Sessions wie PolarisNova.
 * - Wenn MySQL nicht erreichbar ist, bleibt data/customers.json als
 *   lokaler JSON-Fallback erhalten.
 *
 * Öffentliche Schnittstelle für api/customers.php:
 * - customer_load()
 * - customer_save()
 * - customer_next_id()
 * - customer_now()
 * - customer_event()
 */

// -----------------------------------------------------------------------------
// Pfade, Tabellenpräfix und Standardstruktur
// -----------------------------------------------------------------------------

/**
 * Tabellenpräfix der eigenständigen Kundenverwaltung.
 */
function customer_table_prefix(): string
{
    return 'kv_';
}

/**
 * Pfad zur JSON-Fallback-Datei der Kundenverwaltung.
 */
function customer_storage_file(): string
{
    return __DIR__ . '/../data/customers.json';
}

/**
 * Pfad zum Backup-Ordner der Kundenverwaltung.
 */
function customer_backup_dir(): string
{
    return __DIR__ . '/../data/customer_backups';
}

/**
 * Grundstruktur, die API und Frontend erwarten.
 */
function customer_default_data(): array
{
    return [
        'meta' => [
            'app' => 'PolarisNova Kundenverwaltung',
            'version' => 'Kundenverwaltung v0.2.1',
            'last_update' => '23.06.2026',
            'storage' => 'MySQL/PDO mit JSON-Fallback',
            'storage_mode' => 'unknown',
            'table_prefix' => customer_table_prefix(),
            'relation' => 'liest PolarisNova-Projektinformationen, schreibt aber nicht ins Kanban',
        ],
        'customers' => [],
        'events' => [],
    ];
}

// -----------------------------------------------------------------------------
// Normalisierung und JSON-Fallback
// -----------------------------------------------------------------------------

/**
 * Ergänzt fehlende Hauptschlüssel und bereinigt Pflichtfelder.
 */
function customer_prepare_data(array $data): array
{
    $defaults = customer_default_data();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    if (!is_array($data['meta'])) {
        $data['meta'] = $defaults['meta'];
    }

    foreach ($defaults['meta'] as $key => $value) {
        if (!array_key_exists($key, $data['meta'])) {
            $data['meta'][$key] = $value;
        }
    }

    if (!is_array($data['customers'])) {
        $data['customers'] = [];
    }

    if (!is_array($data['events'])) {
        $data['events'] = [];
    }

    foreach ($data['customers'] as &$customer) {
        if (!isset($customer['project_ids']) || !is_array($customer['project_ids'])) {
            $customer['project_ids'] = [];
        }

        if (!isset($customer['orphan_project_ids']) || !is_array($customer['orphan_project_ids'])) {
            $customer['orphan_project_ids'] = [];
        }

        $customer['id'] = (int)($customer['id'] ?? 0);
        $customer['project_ids'] = array_values(array_unique(array_map('intval', $customer['project_ids'])));
        $customer['orphan_project_ids'] = array_values(array_unique(array_map('intval', $customer['orphan_project_ids'])));
        $customer['has_orphan_projects'] = !empty($customer['orphan_project_ids']) || !empty($customer['has_orphan_projects']);
    }
    unset($customer);

    foreach ($data['events'] as &$event) {
        $event['id'] = (int)($event['id'] ?? 0);

        $rawCustomerId = $event['customer_id'] ?? null;
        $eventCustomerId = ($rawCustomerId === null || $rawCustomerId === '') ? null : (int)$rawCustomerId;
        $event['customer_id'] = $eventCustomerId !== null && $eventCustomerId > 0 ? $eventCustomerId : null;

        $rawUserId = $event['user_id'] ?? null;
        $eventUserId = ($rawUserId === null || $rawUserId === '') ? null : (int)$rawUserId;
        $event['user_id'] = $eventUserId !== null && $eventUserId > 0 ? $eventUserId : null;
    }
    unset($event);

    return $data;
}

/**
 * Lädt Kundendaten direkt aus JSON, ohne MySQL zu berühren.
 */
function customer_load_json(): array
{
    $file = customer_storage_file();

    if (!file_exists($file)) {
        return customer_default_data();
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return customer_default_data();
    }

    return customer_prepare_data($data);
}

/**
 * Schreibt JSON atomar: erst temporäre Datei, danach rename().
 */
function customer_atomic_write(string $file, array $data): void
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }

    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        throw new RuntimeException('Kundendaten konnten nicht als JSON erzeugt werden: ' . json_last_error_msg());
    }

    $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

    if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Temporäre Kundendatei konnte nicht geschrieben werden.');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Kundendatei konnte nicht atomar ersetzt werden.');
    }
}

/**
 * Erstellt vor JSON-Fallback-Schreibvorgängen eine Sicherung der letzten Datei.
 */
function customer_backup_current_file(): void
{
    $file = customer_storage_file();

    if (!file_exists($file)) {
        return;
    }

    $dir = customer_backup_dir();

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $target = $dir . '/customers.before_change_' . date('Ymd_His') . '.json';
    copy($file, $target);
}

/**
 * Speichert Kundendaten in den JSON-Fallback.
 */
function customer_save_json(array $data, bool $withBackup = true): void
{
    $data = customer_prepare_data($data);
    $data['meta']['storage'] = 'JSON-Fallback';
    $data['meta']['storage_mode'] = 'json_fallback';
    $data['meta']['table_prefix'] = customer_table_prefix();

    if ($withBackup) {
        customer_backup_current_file();
    }

    customer_atomic_write(customer_storage_file(), $data);
}

// -----------------------------------------------------------------------------
// MySQL-Verbindung und First-Run-Setup der Kundenverwaltung
// -----------------------------------------------------------------------------

/**
 * Holt die bestehende PolarisNova-PDO-Verbindung, wenn MySQL erreichbar ist.
 */
function customer_pdo(): ?PDO
{
    if (!function_exists('storage_pdo')) {
        return null;
    }

    return storage_pdo(false);
}

/**
 * Prüft, ob eine Spalte in einer Tabelle existiert.
 */
function customer_db_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
    $stmt->execute([':column' => $column]);

    return (bool)$stmt->fetch();
}

/**
 * Prüft, ob ein Foreign-Key-Constraint existiert.
 */
function customer_db_constraint_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*)
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND CONSTRAINT_NAME = :constraint");
    $stmt->execute([
        ':table' => $table,
        ':constraint' => $constraint,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Entfernt die alte harte Projekt-FK-Kopplung, falls sie aus v0.2.0 existiert.
 *
 * Begründung:
 * Die Kundenverwaltung ist eine eigenständige Erweiterung. Eine Projektlöschung
 * in PolarisNova darf niemals Kundendaten gefährden. Projektbezüge werden deshalb
 * bewusst nur noch als lose Referenz gespeichert und beim Laden validiert.
 */
function customer_db_drop_constraint_if_exists(PDO $pdo, string $table, string $constraint): void
{
    if (!customer_db_constraint_exists($pdo, $table, $constraint)) {
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
}

/**
 * Legt die eigenständigen Kundenverwaltungs-Tabellen beim ersten Aufruf an.
 */
function customer_db_init(PDO $pdo): void
{
    $prefix = customer_table_prefix();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}customers` (
        `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
        `company` varchar(190) NOT NULL,
        `contact_name` varchar(190) DEFAULT NULL,
        `email` varchar(190) DEFAULT NULL,
        `phone` varchar(100) DEFAULT NULL,
        `website` varchar(190) DEFAULT NULL,
        `address` text DEFAULT NULL,
        `city` varchar(120) DEFAULT NULL,
        `type` enum('customer','prospect','partner','internal') NOT NULL DEFAULT 'customer',
        `status` enum('lead','active','paused','archived') NOT NULL DEFAULT 'lead',
        `source` varchar(190) DEFAULT NULL,
        `notes` text DEFAULT NULL,
        `created_at` varchar(32) DEFAULT NULL,
        `updated_at` varchar(32) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_kv_customers_company` (`company`),
        KEY `idx_kv_customers_status` (`status`),
        KEY `idx_kv_customers_type` (`type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}customer_projects` (
        `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
        `customer_id` int UNSIGNED NOT NULL,
        `project_id` int UNSIGNED NOT NULL,
        `created_at` varchar(32) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_kv_customer_project` (`customer_id`, `project_id`),
        KEY `idx_kv_cp_customer` (`customer_id`),
        KEY `idx_kv_cp_project` (`project_id`),
        CONSTRAINT `fk_kv_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$prefix}customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}events` (
        `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
        `customer_id` int UNSIGNED DEFAULT NULL,
        `user_id` int UNSIGNED DEFAULT NULL,
        `type` varchar(80) NOT NULL,
        `message` varchar(255) DEFAULT NULL,
        `created_at` varchar(32) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_kv_events_customer` (`customer_id`),
        KEY `idx_kv_events_user` (`user_id`),
        KEY `idx_kv_events_type` (`type`),
        CONSTRAINT `fk_kv_events_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$prefix}customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_kv_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration aus v0.2.0: Die Projektzuordnung darf nicht mehr hart per
    // Foreign Key an PolarisNova-Projekte gekoppelt sein. Sonst kann eine
    // Projektlöschung in PolarisNova Nebeneffekte in der Kundenverwaltung auslösen.
    customer_db_drop_constraint_if_exists($pdo, $prefix . 'customer_projects', 'fk_kv_cp_project');

    // Kleine Zukunftssicherung: falls eine ältere Tabelle ohne Projektlink-Felder existiert.
    if (!customer_db_column_exists($pdo, $prefix . 'customers', 'source')) {
        $pdo->exec("ALTER TABLE `{$prefix}customers` ADD `source` varchar(190) DEFAULT NULL AFTER `status`");
    }
}

/**
 * Zählt vorhandene SQL-Kunden. Wird für den einmaligen JSON-Erstimport genutzt.
 */
function customer_db_count_customers(PDO $pdo): int
{
    $prefix = customer_table_prefix();

    return (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}customers`")->fetchColumn();
}

/**
 * Importiert vorhandene JSON-Kundendaten einmalig in die SQL-Tabellen.
 */
function customer_import_json_if_needed(PDO $pdo): void
{
    if (customer_db_count_customers($pdo) > 0) {
        return;
    }

    $jsonData = customer_load_json();

    if (empty($jsonData['customers']) && empty($jsonData['events'])) {
        return;
    }

    customer_save_db($pdo, $jsonData);
}

// -----------------------------------------------------------------------------
// MySQL lesen und schreiben
// -----------------------------------------------------------------------------


/**
 * Liefert alle aktuell gültigen PolarisNova-Projekt-IDs aus der Hauptanwendung.
 */
function customer_db_valid_project_ids(PDO $pdo): array
{
    try {
        $ids = $pdo->query('SELECT id FROM `projects`')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }

    return array_fill_keys(array_map('intval', $ids), true);
}

/**
 * Lädt Kundendaten aus den prefixed SQL-Tabellen.
 */
function customer_load_db(PDO $pdo): array
{
    $prefix = customer_table_prefix();
    $data = customer_default_data();

    $customers = $pdo->query("SELECT id, company, contact_name, email, phone, website, address, city, type, status, source, notes, created_at, updated_at
        FROM `{$prefix}customers`
        ORDER BY company, id")->fetchAll();

    $validProjectIds = customer_db_valid_project_ids($pdo);
    $projectRows = $pdo->query("SELECT customer_id, project_id FROM `{$prefix}customer_projects` ORDER BY customer_id, project_id")->fetchAll();
    $projectsByCustomer = [];
    $orphanProjectsByCustomer = [];

    foreach ($projectRows as $row) {
        $customerId = (int)$row['customer_id'];
        $projectId = (int)$row['project_id'];

        if ($projectId > 0 && isset($validProjectIds[$projectId])) {
            $projectsByCustomer[$customerId][] = $projectId;
        } else {
            $orphanProjectsByCustomer[$customerId][] = $projectId;
        }
    }

    foreach ($customers as $customer) {
        $id = (int)$customer['id'];
        $customer['id'] = $id;
        $customer['project_ids'] = array_values(array_unique($projectsByCustomer[$id] ?? []));
        $customer['orphan_project_ids'] = array_values(array_unique($orphanProjectsByCustomer[$id] ?? []));
        $customer['has_orphan_projects'] = !empty($customer['orphan_project_ids']);
        $data['customers'][] = $customer;
    }

    $events = $pdo->query("SELECT id, customer_id, user_id, type, message, created_at
        FROM `{$prefix}events`
        ORDER BY id")->fetchAll();

    foreach ($events as $event) {
        $event['id'] = (int)$event['id'];
        $event['customer_id'] = $event['customer_id'] === null ? null : (int)$event['customer_id'];
        $event['user_id'] = $event['user_id'] === null ? null : (int)$event['user_id'];
        $data['events'][] = $event;
    }

    $data['meta']['storage'] = 'MySQL/PDO';
    $data['meta']['storage_mode'] = 'mysql_pdo';
    $data['meta']['table_prefix'] = customer_table_prefix();

    return customer_prepare_data($data);
}

/**
 * Speichert den kompletten Kundenverwaltungsstand transaktional in SQL.
 */
function customer_save_db(PDO $pdo, array $data): void
{
    $prefix = customer_table_prefix();
    $data = customer_prepare_data($data);
    $validProjectIds = customer_db_valid_project_ids($pdo);

    // Wichtig für Löschvorgänge:
    // Die Kundenverwaltung speichert den kompletten Zustand neu. Ein Ereignis wie
    // "Kunde gelöscht" darf deshalb nicht mehr per FK auf genau diesen bereits
    // entfernten Kunden zeigen. Historische Events bleiben erhalten, werden bei
    // fehlendem Kunden aber sauber entkoppelt.
    $existingCustomerIds = [];
    foreach ($data['customers'] ?? [] as $customer) {
        $customerId = (int)($customer['id'] ?? 0);
        if ($customerId > 0) {
            $existingCustomerIds[$customerId] = true;
        }
    }

    foreach ($data['events'] ?? [] as &$event) {
        $eventCustomerId = isset($event['customer_id']) && $event['customer_id'] !== null
            ? (int)$event['customer_id']
            : null;

        if ($eventCustomerId === null || $eventCustomerId <= 0 || !isset($existingCustomerIds[$eventCustomerId])) {
            $event['customer_id'] = null;
        }
    }
    unset($event);

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    $pdo->beginTransaction();

    try {
        $pdo->exec("DELETE FROM `{$prefix}events`");
        $pdo->exec("DELETE FROM `{$prefix}customer_projects`");
        $pdo->exec("DELETE FROM `{$prefix}customers`");

        $stmtCustomer = $pdo->prepare("INSERT INTO `{$prefix}customers`
            (id, company, contact_name, email, phone, website, address, city, type, status, source, notes, created_at, updated_at)
            VALUES (:id, :company, :contact_name, :email, :phone, :website, :address, :city, :type, :status, :source, :notes, :created_at, :updated_at)");

        $stmtProject = $pdo->prepare("INSERT INTO `{$prefix}customer_projects`
            (customer_id, project_id, created_at)
            VALUES (:customer_id, :project_id, :created_at)");

        foreach ($data['customers'] ?? [] as $customer) {
            $customerId = (int)$customer['id'];

            if ($customerId <= 0) {
                continue;
            }

            $stmtCustomer->execute([
                ':id' => $customerId,
                ':company' => $customer['company'] ?? '',
                ':contact_name' => $customer['contact_name'] ?? null,
                ':email' => $customer['email'] ?? null,
                ':phone' => $customer['phone'] ?? null,
                ':website' => $customer['website'] ?? null,
                ':address' => $customer['address'] ?? null,
                ':city' => $customer['city'] ?? null,
                ':type' => $customer['type'] ?? 'customer',
                ':status' => $customer['status'] ?? 'lead',
                ':source' => $customer['source'] ?? null,
                ':notes' => $customer['notes'] ?? null,
                ':created_at' => $customer['created_at'] ?? null,
                ':updated_at' => $customer['updated_at'] ?? null,
            ]);

            foreach (array_values(array_unique(array_map('intval', $customer['project_ids'] ?? []))) as $projectId) {
                if ($projectId <= 0 || !isset($validProjectIds[$projectId])) {
                    continue;
                }

                $stmtProject->execute([
                    ':customer_id' => $customerId,
                    ':project_id' => $projectId,
                    ':created_at' => $customer['updated_at'] ?? customer_now(),
                ]);
            }
        }

        $stmtEvent = $pdo->prepare("INSERT INTO `{$prefix}events`
            (id, customer_id, user_id, type, message, created_at)
            VALUES (:id, :customer_id, :user_id, :type, :message, :created_at)");

        foreach ($data['events'] ?? [] as $event) {
            $eventId = (int)($event['id'] ?? 0);

            if ($eventId <= 0) {
                continue;
            }

            $eventCustomerId = isset($event['customer_id']) && $event['customer_id'] !== null
                ? (int)$event['customer_id']
                : null;

            if ($eventCustomerId !== null && ($eventCustomerId <= 0 || !isset($existingCustomerIds[$eventCustomerId]))) {
                $eventCustomerId = null;
            }

            $eventUserId = isset($event['user_id']) && $event['user_id'] !== null
                ? (int)$event['user_id']
                : null;

            $stmtEvent->bindValue(':id', $eventId, PDO::PARAM_INT);

            if ($eventCustomerId === null) {
                $stmtEvent->bindValue(':customer_id', null, PDO::PARAM_NULL);
            } else {
                $stmtEvent->bindValue(':customer_id', $eventCustomerId, PDO::PARAM_INT);
            }

            if ($eventUserId === null || $eventUserId <= 0) {
                $stmtEvent->bindValue(':user_id', null, PDO::PARAM_NULL);
            } else {
                $stmtEvent->bindValue(':user_id', $eventUserId, PDO::PARAM_INT);
            }

            $stmtEvent->bindValue(':type', (string)($event['type'] ?? ''), PDO::PARAM_STR);

            if (($event['message'] ?? null) === null) {
                $stmtEvent->bindValue(':message', null, PDO::PARAM_NULL);
            } else {
                $stmtEvent->bindValue(':message', (string)$event['message'], PDO::PARAM_STR);
            }

            if (($event['created_at'] ?? null) === null) {
                $stmtEvent->bindValue(':created_at', null, PDO::PARAM_NULL);
            } else {
                $stmtEvent->bindValue(':created_at', (string)$event['created_at'], PDO::PARAM_STR);
            }

            $stmtEvent->execute();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

// -----------------------------------------------------------------------------
// Öffentliche Lade-/Speicherfunktionen
// -----------------------------------------------------------------------------

/**
 * Lädt Kundendaten primär aus SQL; bei DB-Ausfall aus JSON.
 */
function customer_load(): array
{
    $pdo = customer_pdo();

    if ($pdo instanceof PDO) {
        customer_db_init($pdo);
        customer_import_json_if_needed($pdo);
        $data = customer_load_db($pdo);

        // SQL ist führend; JSON bleibt als lesbares Backup/Fallback erhalten.
        customer_save_json($data, false);

        return $data;
    }

    $data = customer_load_json();
    $data['meta']['storage'] = 'JSON-Fallback';
    $data['meta']['storage_mode'] = 'json_fallback';
    $data['meta']['table_prefix'] = customer_table_prefix();

    return customer_prepare_data($data);
}

/**
 * Speichert Kundendaten primär in SQL; bei DB-Ausfall in JSON.
 */
function customer_save(array $data): void
{
    $pdo = customer_pdo();

    if ($pdo instanceof PDO) {
        customer_db_init($pdo);
        customer_save_db($pdo, $data);

        $fresh = customer_load_db($pdo);
        customer_save_json($fresh, false);

        return;
    }

    customer_save_json($data, true);
}

// -----------------------------------------------------------------------------
// Kleine Hilfsfunktionen für CRUD und Ereignisse
// -----------------------------------------------------------------------------

/**
 * Ermittelt die nächste freie numerische ID.
 */
function customer_next_id(array $rows): int
{
    $max = 0;

    foreach ($rows as $row) {
        $max = max($max, (int)($row['id'] ?? 0));
    }

    return $max + 1;
}

/**
 * Einheitlicher Zeitstempel für neue und geänderte Kundendaten.
 */
function customer_now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Schreibt ein Ereignisprotokoll für Änderungen an Kundendaten.
 */
function customer_event(array &$data, int $userId, string $type, string $message, ?int $customerId = null): void
{
    if (!isset($data['events']) || !is_array($data['events'])) {
        $data['events'] = [];
    }

    $data['events'][] = [
        'id' => customer_next_id($data['events']),
        'customer_id' => $customerId,
        'user_id' => $userId,
        'type' => $type,
        'message' => $message,
        'created_at' => customer_now(),
    ];
}
