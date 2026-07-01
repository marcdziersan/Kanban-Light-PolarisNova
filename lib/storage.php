<?php
/**
 * PolarisNova Storage Layer.
 *
 * Aufgabe dieser Datei:
 * - Einheitliche Lade-/Speicherfunktionen für die Anwendung bereitstellen.
 * - MySQL/PDO als primäre Datenhaltung verwenden.
 * - JSON als Backup, Erstimport und Offline-Fallback weiter unterstützen.
 * - Die alte Array-/JSON-Struktur der Anwendung in relationale Tabellen übersetzen.
 *
 * Wichtig:
 * Die öffentliche Schnittstelle für den Rest des Projekts bleibt `storage_load()`
 * und `storage_save()`. Dadurch muss die API nicht wissen, ob gerade MySQL oder
 * JSON verwendet wird.
 */

// -----------------------------------------------------------------------------
// Konfiguration und Dateipfade
// -----------------------------------------------------------------------------

/**
 * Lädt config.php genau einmal und hält die Werte im Speicher.
 */
function storage_config(): array
{
    static $cfg = null;

    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
    }

    return $cfg;
}

/**
 * Pfad zur primären JSON-Datei.
 */
function storage_json_file(): string
{
    $cfg = storage_config();

    return $cfg['json']['file'];
}

/**
 * Pfad zur JSON-Backup-Datei.
 */
function storage_backup_file(): string
{
    $cfg = storage_config();

    return $cfg['json']['backup_file'] ?? storage_json_file();
}


/**
 * Pfad zum Import-Protokoll der JSON-Offline-Synchronisation.
 */
function storage_import_log_file(): string
{
    return dirname(storage_json_file()) . '/import_log.json';
}

/**
 * Dateisicherer Zeitstempel für Backup-Dateinamen.
 */
function storage_file_timestamp(): string
{
    return date('Ymd_His');
}

// -----------------------------------------------------------------------------
// Standarddatenstruktur
// -----------------------------------------------------------------------------

/**
 * Liefert die Grundstruktur, die Frontend und API erwarten.
 */
function storage_default_data(): array
{
    return [
        'meta' => [
            'app' => 'Kanban Light PolarisNova',
            'storage' => 'MySQL PDO + JSON Backup/Offline',
            'version' => '1.8.0',
            'version_label' => 'Weiterentwicklung v1.8.4',
            'last_update' => '24.06.2026',
            'release_notes' => 'Projekt-/Boardverwaltung, Projektverantwortliche, JSON-Fallback, JSON-nach-MySQL-Wiederherstellung, Auto-Sortierung, scrollbare Spalten, UI-State-Fix und erweiterte Zeiterfassungs-/Abrechnungsreports.',
        ],
        'users' => [],
        'projects' => [],
        'boards' => [],
        'project_members' => [],
        'columns' => [],
        'tasks' => [],
        'comments' => [],
        'time_entries' => [],
        'events' => [],
        'history' => [],
    ];
}

// -----------------------------------------------------------------------------
// JSON-Lesen und JSON-Schreiben
// -----------------------------------------------------------------------------

/**
 * Lädt Daten aus JSON und ergänzt fehlende Hauptschlüssel mit Standardwerten.
 */
function storage_load_json(): array
{
    $file = storage_json_file();

    if (!file_exists($file)) {
        return storage_default_data();
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return storage_default_data();
    }

    foreach (storage_default_data() as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    foreach ($data['projects'] ?? [] as &$project) {
        if (!array_key_exists('responsible_id', $project)) {
            $project['responsible_id'] = $project['owner_id'] ?? null;
        }
    }
    unset($project);

    return $data;
}

/**
 * Schreibt eine JSON-Datei atomar.
 *
 * Ablauf:
 * - JSON wird zuerst in eine temporäre Datei geschrieben.
 * - Danach wird per `rename()` auf die Zieldatei gewechselt.
 * - Ein kaputter Schreibvorgang ersetzt dadurch nicht die letzte gültige Datei.
 */
function storage_atomic_write_json_file(string $file, array $data): void
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }

    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        throw new RuntimeException('JSON konnte nicht erzeugt werden: ' . json_last_error_msg());
    }

    $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

    if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Temporäre JSON-Datei konnte nicht geschrieben werden.');
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Atomarer JSON-Dateitausch ist fehlgeschlagen.');
    }
}

/**
 * Speichert Daten als JSON und schreibt optional eine Backup-Datei.
 */
function storage_save_json(array $data): void
{
    $file = storage_json_file();
    $backup = storage_backup_file();

    storage_atomic_write_json_file($file, $data);

    if ($backup && $backup !== $file) {
        storage_atomic_write_json_file($backup, $data);
    }
}

// -----------------------------------------------------------------------------
// JSON-Import/-Export mit Sicherheitsnetz
// -----------------------------------------------------------------------------

/**
 * Ergänzt fehlende Hauptschlüssel vor Validierung oder Import.
 */
function storage_prepare_app_data(array $data): array
{
    foreach (storage_default_data() as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    foreach ($data['projects'] ?? [] as &$project) {
        if (!array_key_exists('responsible_id', $project)) {
            $project['responsible_id'] = $project['owner_id'] ?? null;
        }
    }
    unset($project);

    return $data;
}

/**
 * Prüft, ob ein Tabellenarray gültige eindeutige IDs besitzt.
 */
function storage_validate_ids(array $rows, string $table, array &$errors): array
{
    $ids = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            $errors[] = "{$table}[{$index}] ist kein Objekt/Array.";
            continue;
        }

        $id = (int)($row['id'] ?? 0);

        if ($id <= 0) {
            $errors[] = "{$table}[{$index}] besitzt keine gültige ID.";
            continue;
        }

        if (isset($ids[$id])) {
            $errors[] = "{$table} enthält die ID {$id} mehrfach.";
        }

        $ids[$id] = true;
    }

    return array_keys($ids);
}

/**
 * Validiert die JSON-Struktur und wichtige Beziehungen vor einem Import.
 *
 * Es wird nicht blind überschrieben: Erst wenn diese Prüfung erfolgreich ist,
 * darf die Datei in die aktive Datenhaltung übernommen werden.
 */
function storage_validate_app_data(array $data): array
{
    $data = storage_prepare_app_data($data);
    $errors = [];
    $tables = ['users', 'projects', 'boards', 'project_members', 'columns', 'tasks', 'comments', 'time_entries', 'events', 'history'];

    foreach ($tables as $table) {
        if (!isset($data[$table]) || !is_array($data[$table])) {
            $errors[] = "Hauptbereich '{$table}' fehlt oder ist kein Array.";
            $data[$table] = [];
        }
    }

    $userIds = storage_validate_ids($data['users'], 'users', $errors);
    $projectIds = storage_validate_ids($data['projects'], 'projects', $errors);
    $boardIds = storage_validate_ids($data['boards'], 'boards', $errors);
    storage_validate_ids($data['project_members'], 'project_members', $errors);
    $columnIds = storage_validate_ids($data['columns'], 'columns', $errors);
    $taskIds = storage_validate_ids($data['tasks'], 'tasks', $errors);
    storage_validate_ids($data['comments'], 'comments', $errors);
    storage_validate_ids($data['time_entries'], 'time_entries', $errors);
    storage_validate_ids($data['events'], 'events', $errors);
    storage_validate_ids($data['history'], 'history', $errors);

    $userSet = array_flip($userIds);
    $projectSet = array_flip($projectIds);
    $boardSet = array_flip($boardIds);
    $columnSet = array_flip($columnIds);
    $taskSet = array_flip($taskIds);

    if (!$userIds) {
        $errors[] = 'Es muss mindestens ein Benutzer vorhanden sein.';
    }

    foreach ($data['users'] as $row) {
        $role = $row['role'] ?? 'user';
        if (!in_array($role, ['admin', 'user', 'guest'], true)) {
            $errors[] = "Benutzer #" . (int)($row['id'] ?? 0) . " hat eine ungültige Rolle.";
        }
    }

    foreach ($data['projects'] as $row) {
        if (trim((string)($row['name'] ?? '')) === '') {
            $errors[] = "Projekt #" . (int)($row['id'] ?? 0) . " hat keinen Namen.";
        }
        foreach (['owner_id', 'responsible_id'] as $field) {
            $value = $row[$field] ?? null;
            if ($value !== null && $value !== '' && !isset($userSet[(int)$value])) {
                $errors[] = "Projekt #" . (int)($row['id'] ?? 0) . " verweist bei {$field} auf einen unbekannten Benutzer.";
            }
        }
    }

    foreach ($data['boards'] as $row) {
        if (!isset($projectSet[(int)($row['project_id'] ?? 0)])) {
            $errors[] = "Board #" . (int)($row['id'] ?? 0) . " verweist auf ein unbekanntes Projekt.";
        }
    }

    $projectMemberPairs = [];
    foreach ($data['project_members'] as $row) {
        if (!isset($projectSet[(int)($row['project_id'] ?? 0)])) {
            $errors[] = "Projektmitglied #" . (int)($row['id'] ?? 0) . " verweist auf ein unbekanntes Projekt.";
        }
        if (!isset($userSet[(int)($row['user_id'] ?? 0)])) {
            $errors[] = "Projektmitglied #" . (int)($row['id'] ?? 0) . " verweist auf einen unbekannten Benutzer.";
        }

        $pairKey = (int)($row['project_id'] ?? 0) . ':' . (int)($row['user_id'] ?? 0);
        if (isset($projectMemberPairs[$pairKey])) {
            $errors[] = "Projektmitglied-Zuordnung {$pairKey} ist mehrfach vorhanden.";
        }
        $projectMemberPairs[$pairKey] = true;
    }

    foreach ($data['columns'] as $row) {
        if (!isset($boardSet[(int)($row['board_id'] ?? 0)])) {
            $errors[] = "Spalte #" . (int)($row['id'] ?? 0) . " verweist auf ein unbekanntes Board.";
        }
    }

    foreach ($data['tasks'] as $row) {
        if (!isset($columnSet[(int)($row['column_id'] ?? 0)])) {
            $errors[] = "Aufgabe #" . (int)($row['id'] ?? 0) . " verweist auf eine unbekannte Spalte.";
        }
        foreach (['assigned_to', 'locked_by'] as $field) {
            $value = $row[$field] ?? null;
            if ($value !== null && $value !== '' && !isset($userSet[(int)$value])) {
                $errors[] = "Aufgabe #" . (int)($row['id'] ?? 0) . " verweist bei {$field} auf einen unbekannten Benutzer.";
            }
        }
    }

    foreach ($data['comments'] as $row) {
        if (!isset($taskSet[(int)($row['task_id'] ?? 0)])) {
            $errors[] = "Kommentar #" . (int)($row['id'] ?? 0) . " verweist auf eine unbekannte Aufgabe.";
        }
        if (!isset($userSet[(int)($row['user_id'] ?? 0)])) {
            $errors[] = "Kommentar #" . (int)($row['id'] ?? 0) . " verweist auf einen unbekannten Benutzer.";
        }
    }

    foreach ($data['time_entries'] as $row) {
        if (!isset($taskSet[(int)($row['task_id'] ?? 0)])) {
            $errors[] = "Zeiteintrag #" . (int)($row['id'] ?? 0) . " verweist auf eine unbekannte Aufgabe.";
        }
        if (!isset($userSet[(int)($row['user_id'] ?? 0)])) {
            $errors[] = "Zeiteintrag #" . (int)($row['id'] ?? 0) . " verweist auf einen unbekannten Benutzer.";
        }
    }

    foreach ($data['events'] as $row) {
        $userId = $row['user_id'] ?? null;
        if ($userId !== null && $userId !== '' && !isset($userSet[(int)$userId])) {
            $errors[] = "Event #" . (int)($row['id'] ?? 0) . " verweist auf einen unbekannten Benutzer.";
        }
    }

    foreach ($data['history'] as $row) {
        $userId = $row['user_id'] ?? null;
        if ($userId !== null && $userId !== '' && !isset($userSet[(int)$userId])) {
            $errors[] = "Historie #" . (int)($row['id'] ?? 0) . " verweist auf einen unbekannten Benutzer.";
        }
    }

    return $errors;
}

/**
 * Ermittelt einen groben Änderungszeitpunkt eines Datensatzes für Merge-Konflikte.
 */
function storage_row_timestamp(array $row): int
{
    $fields = ['updated_at', 'created_at', 'stopped_at', 'started_at', 'locked_at'];
    $max = 0;

    foreach ($fields as $field) {
        if (!empty($row[$field])) {
            $time = strtotime((string)$row[$field]);
            if ($time !== false) {
                $max = max($max, $time);
            }
        }
    }

    return $max;
}

/**
 * Führt zwei Tabellen anhand der ID zusammen, ohne vorhandene Datensätze zu löschen.
 */
function storage_merge_rows_by_id(array $currentRows, array $incomingRows, string $table, array &$report): array
{
    $map = [];

    foreach ($currentRows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = $row;
        }
    }

    foreach ($incomingRows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        if (!isset($map[$id])) {
            $map[$id] = $row;
            $report[$table]['added']++;
            continue;
        }

        if ($map[$id] == $row) {
            $report[$table]['unchanged']++;
            continue;
        }

        $currentTime = storage_row_timestamp($map[$id]);
        $incomingTime = storage_row_timestamp($row);

        if ($incomingTime >= $currentTime) {
            $map[$id] = array_replace($map[$id], $row);
            $report[$table]['updated']++;
        } else {
            $report[$table]['kept_current']++;
        }
    }

    ksort($map, SORT_NUMERIC);

    return array_values($map);
}

/**
 * Merge-Import: neue Datensätze werden ergänzt, bestehende werden nur bei neuerem
 * Zeitstempel aktualisiert. Fehlende Datensätze aus der Importdatei löschen die
 * aktuelle Datei bewusst nicht.
 */
function storage_merge_app_data(array $current, array $incoming, array &$report): array
{
    $current = storage_prepare_app_data($current);
    $incoming = storage_prepare_app_data($incoming);
    $merged = $current;
    $tables = ['users', 'projects', 'boards', 'project_members', 'columns', 'tasks', 'comments', 'time_entries', 'events', 'history'];

    foreach ($tables as $table) {
        $report[$table] = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'kept_current' => 0];
        $merged[$table] = storage_merge_rows_by_id($current[$table] ?? [], $incoming[$table] ?? [], $table, $report);
    }

    $merged['meta'] = array_replace($current['meta'] ?? [], $incoming['meta'] ?? []);

    return $merged;
}

/**
 * Einheitliche Liste aller fachlichen Datenbereiche für JSON- und MySQL-Sync.
 */
function storage_sync_tables(): array
{
    return ['users', 'projects', 'boards', 'project_members', 'columns', 'tasks', 'comments', 'time_entries', 'events', 'history'];
}

/**
 * Normalisiert Daten für einen stabilen Vergleich. Meta-Daten werden bewusst
 * ignoriert, weil sie sich bei Export, Import und Speichermodus unterscheiden.
 */
function storage_normalize_data_for_compare(array $data): array
{
    $data = storage_prepare_app_data($data);
    $normalized = [];

    foreach (storage_sync_tables() as $table) {
        $rows = [];

        foreach ($data[$table] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            ksort($row);
            $id = (int)($row['id'] ?? 0);
            $rows[$id > 0 ? $id : count($rows) + 1000000] = $row;
        }

        ksort($rows, SORT_NUMERIC);
        $normalized[$table] = array_values($rows);
    }

    return $normalized;
}

/**
 * Fingerprint für fachliche Daten. Wird genutzt, um ausstehende JSON-Offline-
 * Änderungen vor automatischem Überschreiben durch MySQL zu schützen.
 */
function storage_data_fingerprint(array $data): string
{
    return hash('sha256', json_encode(storage_normalize_data_for_compare($data), JSON_UNESCAPED_UNICODE));
}

/**
 * Prüft, ob in data.json noch ein Offline-Stand liegt, der von MySQL abweicht.
 * Dann darf MySQL data.json nicht automatisch überschreiben, bis ein Admin die
 * Wiederherstellung oder bewusste Verwerfung entschieden hat.
 */
function storage_json_has_pending_mysql_restore(array $mysqlData): bool
{
    $file = storage_json_file();

    if (!file_exists($file)) {
        return false;
    }

    $jsonData = storage_load_json();
    $jsonMode = (string)($jsonData['meta']['storage_mode'] ?? '');

    if ($jsonMode !== 'json_offline') {
        return false;
    }

    return storage_data_fingerprint($jsonData) !== storage_data_fingerprint($mysqlData);
}

/**
 * Erzeugt einen kurzen lesbaren Namen für Konfliktlisten in der Vorschau.
 */
function storage_restore_row_label(array $row): string
{
    foreach (['title', 'username', 'name', 'content', 'type', 'action'] as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') {
            return mb_substr($value, 0, 80);
        }
    }

    return 'Datensatz #' . (int)($row['id'] ?? 0);
}

/**
 * Vergleicht JSON-Stand gegen MySQL und erzeugt eine Vorschau:
 * - added: Datensatz existiert nur in JSON.
 * - updated: JSON ist nach Zeitstempel neuer und würde MySQL aktualisieren.
 * - conflicts: gleicher Datensatz unterscheidet sich, aber MySQL ist neuer oder
 *   der Zeitstempel ist nicht eindeutig.
 * - kept_mysql: Datensatz existiert nur in MySQL und bleibt erhalten.
 */
function storage_preview_json_to_mysql_restore(array $jsonData, array $mysqlData): array
{
    $jsonData = storage_prepare_app_data($jsonData);
    $mysqlData = storage_prepare_app_data($mysqlData);

    $errors = storage_validate_app_data($jsonData);
    if ($errors) {
        throw new RuntimeException('JSON-Stand ist ungültig: ' . implode(' | ', array_slice($errors, 0, 8)));
    }

    $report = [];
    $conflicts = [];
    $totals = ['added' => 0, 'updated' => 0, 'conflicts' => 0, 'unchanged' => 0, 'kept_mysql' => 0];

    foreach (storage_sync_tables() as $table) {
        $report[$table] = ['added' => 0, 'updated' => 0, 'conflicts' => 0, 'unchanged' => 0, 'kept_mysql' => 0];
        $mysqlMap = [];
        $jsonMap = [];

        foreach ($mysqlData[$table] ?? [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $mysqlMap[$id] = $row;
            }
        }

        foreach ($jsonData[$table] ?? [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $jsonMap[$id] = $row;
            }
        }

        foreach ($jsonMap as $id => $jsonRow) {
            if (!isset($mysqlMap[$id])) {
                $report[$table]['added']++;
                $totals['added']++;
                continue;
            }

            if ($mysqlMap[$id] == $jsonRow) {
                $report[$table]['unchanged']++;
                $totals['unchanged']++;
                continue;
            }

            $mysqlTime = storage_row_timestamp($mysqlMap[$id]);
            $jsonTime = storage_row_timestamp($jsonRow);

            if ($jsonTime > $mysqlTime) {
                $report[$table]['updated']++;
                $totals['updated']++;
                continue;
            }

            $report[$table]['conflicts']++;
            $totals['conflicts']++;

            if (count($conflicts) < 50) {
                $conflicts[] = [
                    'table' => $table,
                    'id' => $id,
                    'label' => storage_restore_row_label($jsonRow),
                    'mysql_time' => $mysqlTime ? date('Y-m-d H:i:s', $mysqlTime) : 'unbekannt',
                    'json_time' => $jsonTime ? date('Y-m-d H:i:s', $jsonTime) : 'unbekannt',
                    'reason' => $mysqlTime > $jsonTime ? 'MySQL ist neuer' : 'Zeitstempel unklar oder gleich',
                ];
            }
        }

        foreach ($mysqlMap as $id => $mysqlRow) {
            if (!isset($jsonMap[$id])) {
                $report[$table]['kept_mysql']++;
                $totals['kept_mysql']++;
            }
        }
    }

    return [
        'report' => $report,
        'totals' => $totals,
        'conflicts' => $conflicts,
        'has_conflicts' => $totals['conflicts'] > 0,
    ];
}

/**
 * Baut den neuen MySQL-Datenstand aus aktuellem MySQL und JSON.
 * Konflikte werden je nach Richtlinie entweder in MySQL behalten oder durch den
 * JSON-Stand ersetzt. Gelöscht wird beim Restore bewusst nichts.
 */
function storage_merge_json_into_mysql_data(array $mysqlData, array $jsonData, string $conflictPolicy, array &$report): array
{
    $mysqlData = storage_prepare_app_data($mysqlData);
    $jsonData = storage_prepare_app_data($jsonData);
    $merged = $mysqlData;
    $conflictPolicy = in_array($conflictPolicy, ['keep_mysql', 'json_wins'], true) ? $conflictPolicy : 'keep_mysql';

    foreach (storage_sync_tables() as $table) {
        $report[$table] = [
            'added' => 0,
            'updated' => 0,
            'conflicts' => 0,
            'resolved_json' => 0,
            'kept_mysql' => 0,
            'unchanged' => 0,
        ];

        $map = [];
        foreach ($mysqlData[$table] ?? [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = $row;
            }
        }

        foreach ($jsonData[$table] ?? [] as $jsonRow) {
            $id = (int)($jsonRow['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!isset($map[$id])) {
                $map[$id] = $jsonRow;
                $report[$table]['added']++;
                continue;
            }

            if ($map[$id] == $jsonRow) {
                $report[$table]['unchanged']++;
                continue;
            }

            $mysqlTime = storage_row_timestamp($map[$id]);
            $jsonTime = storage_row_timestamp($jsonRow);

            if ($jsonTime > $mysqlTime) {
                $map[$id] = array_replace($map[$id], $jsonRow);
                $report[$table]['updated']++;
                continue;
            }

            $report[$table]['conflicts']++;

            if ($conflictPolicy === 'json_wins') {
                $map[$id] = array_replace($map[$id], $jsonRow);
                $report[$table]['resolved_json']++;
            } else {
                $report[$table]['kept_mysql']++;
            }
        }

        ksort($map, SORT_NUMERIC);
        $merged[$table] = array_values($map);
    }

    $merged['meta'] = array_replace($mysqlData['meta'] ?? [], $jsonData['meta'] ?? []);
    $merged['meta']['storage'] = 'MySQL PDO';
    $merged['meta']['storage_mode'] = 'mysql_pdo';
    $merged['meta']['json_restore_pending'] = false;
    $merged['meta']['last_json_to_mysql_restore'] = [
        'at' => date('Y-m-d H:i:s'),
        'conflict_policy' => $conflictPolicy,
    ];

    return $merged;
}

/**
 * Vorschau für den Admin-Button "JSON nach MySQL wiederherstellen".
 */
function storage_preview_json_file_to_mysql(): array
{
    $pdo = storage_pdo(true);
    $mysqlData = storage_load_db($pdo);
    $jsonData = storage_load_json();
    $preview = storage_preview_json_to_mysql_restore($jsonData, $mysqlData);
    $preview['json_file'] = basename(storage_json_file());
    $preview['mysql_available'] = true;
    $preview['pending_restore'] = storage_json_has_pending_mysql_restore($mysqlData);

    return $preview;
}

/**
 * Sicherer Restore von data.json zurück nach MySQL.
 * - Admin-only wird in der API geprüft.
 * - MySQL wird vorab als JSON in data/backups gesichert.
 * - Der neue Datenstand wird validiert.
 * - Speicherung erfolgt über storage_save_db() innerhalb einer PDO-Transaktion.
 */
function storage_restore_json_file_to_mysql_safely(array $actor, string $conflictPolicy = 'keep_mysql'): array
{
    $pdo = storage_pdo(true);
    $mysqlData = storage_load_db($pdo);
    $jsonData = storage_load_json();

    $preview = storage_preview_json_to_mysql_restore($jsonData, $mysqlData);
    $report = [];
    $next = storage_merge_json_into_mysql_data($mysqlData, $jsonData, $conflictPolicy, $report);
    $next = storage_prepare_app_data($next);
    $next['meta']['last_json_to_mysql_restore']['by_user_id'] = (int)($actor['id'] ?? 0);
    $next['meta']['last_json_to_mysql_restore']['by_username'] = $actor['username'] ?? '';

    $errors = storage_validate_app_data($next);
    if ($errors) {
        throw new RuntimeException('Wiederherstellung abgebrochen: Zusammengeführter Datenstand ist ungültig: ' . implode(' | ', array_slice($errors, 0, 8)));
    }

    $backupDir = dirname(storage_json_file()) . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }

    $backupFile = $backupDir . '/mysql.before_json_restore_' . storage_file_timestamp() . '.json';
    storage_atomic_write_json_file($backupFile, $mysqlData);

    storage_save_db($pdo, $next);

    $fresh = storage_load_db($pdo);
    $fresh['meta']['storage'] = 'MySQL PDO';
    $fresh['meta']['storage_mode'] = 'mysql_pdo';
    $fresh['meta']['json_backup'] = 'enabled';
    $fresh['meta']['json_restore_pending'] = false;
    storage_save_json($fresh);

    return [
        'backup_file' => basename($backupFile),
        'preview' => $preview,
        'report' => $report,
        'conflict_policy' => $conflictPolicy,
    ];
}

/**
 * Protokolliert einen JSON-Import zusätzlich außerhalb der Hauptdatei.
 */
function storage_append_import_log(array $entry): void
{
    $file = storage_import_log_file();
    $log = [];

    if (file_exists($file)) {
        $raw = file_get_contents($file);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $log = $decoded;
        }
    }

    $log[] = $entry;

    if (count($log) > 100) {
        $log = array_slice($log, -100);
    }

    storage_atomic_write_json_file($file, $log);
}

/**
 * Sicherer JSON-Import für den Offline-Fallback.
 *
 * ACID-ähnlich im Dateisystem:
 * - Atomicity: Import landet erst nach erfolgreicher Prüfung per rename() in data.json.
 * - Consistency: Struktur und Beziehungen werden vorher und nach dem Merge validiert.
 * - Isolation: Während des Schreibens wird mit temporären Dateien gearbeitet.
 * - Durability: Vorheriger Stand wird als Zeitstempel-Backup erhalten.
 */
function storage_import_json_safely(array $incoming, array $current, array $actor, string $mode = 'merge'): array
{
    $incoming = storage_prepare_app_data($incoming);
    $current = storage_prepare_app_data($current);

    $incomingErrors = storage_validate_app_data($incoming);
    if ($incomingErrors) {
        throw new RuntimeException('Import abgebrochen: ' . implode(' | ', array_slice($incomingErrors, 0, 8)));
    }

    $report = [];
    $next = $mode === 'replace'
        ? $incoming
        : storage_merge_app_data($current, $incoming, $report);

    $next = storage_prepare_app_data($next);
    $next['meta']['storage'] = 'JSON Offline-Fallback';
    $next['meta']['storage_mode'] = 'json_offline';
    $next['meta']['last_json_import'] = [
        'at' => date('Y-m-d H:i:s'),
        'by_user_id' => (int)($actor['id'] ?? 0),
        'by_username' => $actor['username'] ?? '',
        'mode' => $mode,
    ];

    $finalErrors = storage_validate_app_data($next);
    if ($finalErrors) {
        throw new RuntimeException('Import abgebrochen: Zusammengeführter Datenstand ist ungültig: ' . implode(' | ', array_slice($finalErrors, 0, 8)));
    }

    $file = storage_json_file();
    $backupDir = dirname($file) . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }

    $backupFile = $backupDir . '/data.before_import_' . storage_file_timestamp() . '.json';
    if (file_exists($file) && !copy($file, $backupFile)) {
        throw new RuntimeException('Import abgebrochen: Vorheriges JSON-Backup konnte nicht angelegt werden.');
    }

    storage_save_json($next);

    $entry = [
        'id' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'user_id' => (int)($actor['id'] ?? 0),
        'username' => $actor['username'] ?? '',
        'mode' => $mode,
        'backup_file' => basename($backupFile),
        'report' => $report,
    ];
    storage_append_import_log($entry);

    return [
        'data' => $next,
        'backup_file' => basename($backupFile),
        'report' => $report,
    ];
}

// -----------------------------------------------------------------------------
// MySQL-/PDO-Verbindung und Initialisierung
// -----------------------------------------------------------------------------

/**
 * Prüft, ob die MySQL-/PDO-Datenhaltung erreichbar ist.
 */
function storage_db_available(): bool
{
    return storage_pdo(false) instanceof PDO;
}

/**
 * Liefert eine lesbare Anzeige für den aktuellen Speichermodus.
 */
function storage_mode_label(): string
{
    return storage_db_available()
        ? 'MySQL/PDO aktiv · JSON Backup/Sync'
        : 'JSON Offline-Fallback aktiv';
}

/**
 * Baut die PDO-Verbindung auf und initialisiert bei Bedarf die Datenbanktabellen.
 */
function storage_pdo(bool $throw = false): ?PDO
{
    static $pdo = null;
    static $failed = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($failed && !$throw) {
        return null;
    }

    $cfg = storage_config();

    if (empty($cfg['db']['enabled'])) {
        return null;
    }

    $host = $cfg['db']['host'];
    $port = (int)($cfg['db']['port'] ?? 3306);
    $db = $cfg['db']['database'];
    $user = $cfg['db']['username'];
    $pass = $cfg['db']['password'];
    $charset = $cfg['db']['charset'] ?? 'utf8mb4';

    try {
        // Optional: Datenbank automatisch anlegen, falls der Benutzer Rechte hat.
        if (!empty($cfg['db']['auto_create_database'])) {
            $serverDsn = "mysql:host={$host};port={$port};charset={$charset}";
            $server = new PDO($serverDsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        storage_db_init($pdo);

        return $pdo;
    } catch (Throwable $e) {
        $failed = true;

        if ($throw) {
            throw $e;
        }

        return null;
    }
}

/**
 * Prüft, ob alle benötigten Tabellen bereits vorhanden sind.
 */
function storage_db_tables_ready(PDO $pdo): bool
{
    $requiredTables = [
        'users',
        'projects',
        'boards',
        'project_members',
        'kanban_columns',
        'tasks',
        'comments',
        'time_entries',
        'events',
        'history',
    ];

    $stmt = $pdo->query('SHOW TABLES');
    $existingTables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $existingTables = array_map('strtolower', $existingTables);

    foreach ($requiredTables as $table) {
        if (!in_array(strtolower($table), $existingTables, true)) {
            return false;
        }
    }

    return true;
}

/**
 * Führt kleine, nachträgliche Schema-Erweiterungen aus, ohne bestehende Daten zu löschen.
 *
 * Diese Migration ist wichtig für die Projekt-/Board-Erweiterung: Ältere
 * Installationen kennen die Tabelle `project_members` noch nicht. Sie wird
 * hier gezielt ergänzt, statt den kompletten SQL-Dump erneut auszuführen.
 */
function storage_db_migrate(PDO $pdo): void
{
    // Spalte für Projektverantwortliche nachrüsten, ohne vorhandene Projekte zu löschen.
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM `projects`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('responsible_id', $columns, true)) {
            $pdo->exec("ALTER TABLE `projects` ADD `responsible_id` int UNSIGNED DEFAULT NULL AFTER `owner_id`");
            $pdo->exec("UPDATE `projects` SET `responsible_id` = `owner_id` WHERE `responsible_id` IS NULL AND `owner_id` IS NOT NULL");
        } else {
            $pdo->exec("UPDATE `projects` SET `responsible_id` = `owner_id` WHERE `responsible_id` IS NULL AND `owner_id` IS NOT NULL");
        }
    } catch (Throwable $e) {
        // Wenn projects noch nicht existiert, wird die Tabelle später über polarisnova.sql angelegt.
    }

    try {
        $pdo->exec("ALTER TABLE `projects` ADD KEY `idx_projects_responsible` (`responsible_id`)");
    } catch (Throwable $e) {
        // Index existiert bereits oder projects ist noch nicht vorhanden.
    }

    try {
        $pdo->exec("ALTER TABLE `projects`
            ADD CONSTRAINT `fk_projects_responsible`
            FOREIGN KEY (`responsible_id`) REFERENCES `users` (`id`)
            ON DELETE SET NULL ON UPDATE CASCADE");
    } catch (Throwable $e) {
        // Constraint existiert bereits oder wird bei Erstinstallation später gesetzt.
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `project_members` (
        `id` int UNSIGNED NOT NULL,
        `project_id` int UNSIGNED NOT NULL,
        `user_id` int UNSIGNED NOT NULL,
        `created_at` varchar(32) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_project_user` (`project_id`, `user_id`),
        KEY `idx_project_members_project` (`project_id`),
        KEY `idx_project_members_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Foreign Keys werden nur ergänzt, wenn sie nicht bereits vorhanden sind.
    try {
        $pdo->exec("ALTER TABLE `project_members`
            ADD CONSTRAINT `fk_project_members_project`
            FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE");
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') === false && stripos($e->getMessage(), 'already exists') === false) {
            // In teilinitialisierten Datenbanken kann der FK später erneut gesetzt werden.
        }
    }

    try {
        $pdo->exec("ALTER TABLE `project_members`
            ADD CONSTRAINT `fk_project_members_user`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE");
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') === false && stripos($e->getMessage(), 'already exists') === false) {
            // In teilinitialisierten Datenbanken kann der FK später erneut gesetzt werden.
        }
    }
}

/**
 * Legt eine Start-Zuordnung an, falls eine bestehende Installation noch keine
 * Projektmitglieder kennt. Dadurch sieht der vorhandene Demo-Mitarbeiter nach
 * dem Update weiterhin mindestens das Standardprojekt.
 */
function storage_seed_project_members(PDO $pdo): void
{
    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM project_members')->fetchColumn();
    } catch (Throwable $e) {
        return;
    }

    if ($count > 0) {
        return;
    }

    try {
        $projects = $pdo->query('SELECT id FROM projects ORDER BY id')->fetchAll();
        $users = $pdo->query("SELECT id FROM users WHERE role = 'user' AND is_active = 1 ORDER BY id")->fetchAll();
    } catch (Throwable $e) {
        return;
    }

    if (!$projects || !$users) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO project_members (id, project_id, user_id, created_at) VALUES (:id, :project_id, :user_id, :created_at)');
    $id = 1;
    $now = date('Y-m-d H:i:s');

    foreach ($projects as $project) {
        foreach ($users as $user) {
            $stmt->execute([
                ':id' => $id++,
                ':project_id' => (int)$project['id'],
                ':user_id' => (int)$user['id'],
                ':created_at' => $now,
            ]);
        }
    }
}

/**
 * Initialisiert die Datenbank nur, wenn Tabellen fehlen.
 *
 * Der Hotfix ist hier bewusst enthalten:
 * Der SQL-Dump darf nicht bei jedem Request komplett ausgeführt werden. Außerdem
 * werden START TRANSACTION, COMMIT und DROP TABLE aus der automatischen Laufzeit-
 * Initialisierung entfernt, damit keine laufende Transaktion hängen bleibt und
 * keine produktiven Daten gelöscht werden.
 */
function storage_db_init(PDO $pdo): void
{
    if (!storage_db_tables_ready($pdo)) {
        $sql = file_get_contents(__DIR__ . '/../polarisnova.sql');

        // CREATE DATABASE / USE sind bei bestehender Verbindung mit dbname nicht auf jedem Host erlaubt.
        $sql = preg_replace('/CREATE DATABASE.*?;\s*/is', '', $sql);
        $sql = preg_replace('/USE\s+`?polarisnova`?;\s*/is', '', $sql);

        // Runtime-Initialisierung darf keine offene Dump-Transaktion hinterlassen.
        $sql = preg_replace('/START\s+TRANSACTION\s*;\s*/is', '', $sql);
        $sql = preg_replace('/COMMIT\s*;\s*/is', '', $sql);

        // Eine vorhandene Demo-Datenbank darf nicht automatisch gelöscht werden.
        $sql = preg_replace('/DROP\s+TABLE\s+IF\s+EXISTS\s+`?[^`;]+`?\s*;\s*/is', '', $sql);
        $sql = preg_replace('/SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\s*;\s*/is', '', $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '') {
                continue;
            }

            try {
                $pdo->exec($stmt);
            } catch (Throwable $e) {
                // Bereits vorhandene Constraints/Tables blockieren eine teilweise vorhandene DB nicht.
                $msg = $e->getMessage();

                if (stripos($msg, 'Duplicate foreign key constraint name') !== false ||
                    stripos($msg, 'Duplicate key name') !== false ||
                    stripos($msg, 'already exists') !== false) {
                    continue;
                }

                throw $e;
            }
        }
    }

    storage_db_migrate($pdo);
    storage_seed_project_members($pdo);
}

/**
 * Zählt Benutzer in MySQL. Wird genutzt, um einen einmaligen JSON-Import zu erkennen.
 */
function storage_db_count_users(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

/**
 * Importiert JSON-Daten einmalig nach MySQL, wenn die Datenbank noch leer ist.
 */
function storage_import_json_if_needed(PDO $pdo): void
{
    $cfg = storage_config();

    if (empty($cfg['json']['import_json_when_db_empty'])) {
        return;
    }

    if (storage_db_count_users($pdo) === 0) {
        $jsonData = storage_load_json();

        if (!empty($jsonData['users'])) {
            storage_save_db($pdo, $jsonData);
        }
    }
}

// -----------------------------------------------------------------------------
// Öffentliche Lade-/Speicherfunktionen für die Anwendung
// -----------------------------------------------------------------------------

/**
 * Lädt Daten aus MySQL oder, falls nötig, aus dem JSON-Fallback.
 */
function storage_load(): array
{
    $pdo = storage_pdo(false);

    if ($pdo instanceof PDO) {
        storage_import_json_if_needed($pdo);
        $data = storage_load_db($pdo);

        $data['meta']['storage'] = 'MySQL PDO';
        $data['meta']['json_backup'] = 'enabled';
        $data['meta']['storage_mode'] = 'mysql_pdo';

        $cfg = storage_config();

        $pendingRestore = storage_json_has_pending_mysql_restore($data);
        $data['meta']['json_restore_pending'] = $pendingRestore;

        if (!empty($cfg['json']['sync_mysql_to_json']) && !$pendingRestore) {
            storage_save_json($data);
        }

        return $data;
    }

    $cfg = storage_config();

    if (!empty($cfg['json']['fallback_when_db_unavailable'])) {
        $data = storage_load_json();
        $data['meta']['storage_mode'] = 'json_offline';
        $data['meta']['storage'] = 'JSON Offline-Fallback';

        return $data;
    }

    throw new RuntimeException('MySQL/PDO ist nicht erreichbar und JSON-Fallback ist deaktiviert.');
}

/**
 * Speichert Daten nach MySQL oder, falls nötig, in den JSON-Fallback.
 */
function storage_save(array $data): void
{
    $pdo = storage_pdo(false);

    if ($pdo instanceof PDO) {
        storage_save_db($pdo, $data);

        $cfg = storage_config();

        if (!empty($cfg['json']['sync_mysql_to_json'])) {
            $fresh = storage_load_db($pdo);
            $fresh['meta']['storage'] = 'MySQL PDO';
            $fresh['meta']['json_backup'] = 'enabled';
            $fresh['meta']['storage_mode'] = 'mysql_pdo';

            if (!storage_json_has_pending_mysql_restore($fresh)) {
                storage_save_json($fresh);
            }
        }

        return;
    }

    $cfg = storage_config();

    if (!empty($cfg['json']['fallback_when_db_unavailable'])) {
        $data['meta']['storage_mode'] = 'json_offline';
        $data['meta']['storage'] = 'JSON Offline-Fallback';
        storage_save_json($data);

        return;
    }

    throw new RuntimeException('Speichern fehlgeschlagen: MySQL nicht erreichbar und JSON-Fallback deaktiviert.');
}

// -----------------------------------------------------------------------------
// MySQL lesen: Tabellen in die gewohnte Array-Struktur übersetzen
// -----------------------------------------------------------------------------

/**
 * Lädt alle Tabellen aus MySQL und formt sie in die alte Anwendungsstruktur um.
 */
function storage_load_db(PDO $pdo): array
{
    $data = storage_default_data();

    $data['users'] = $pdo->query('SELECT id, username, email, password_hash, role, is_active, created_at FROM users ORDER BY id')->fetchAll();
    foreach ($data['users'] as &$u) {
        $u['id'] = (int)$u['id'];
        $u['is_active'] = (bool)$u['is_active'];
    }

    $data['projects'] = $pdo->query('SELECT id, name, description, owner_id, responsible_id, created_at FROM projects ORDER BY id')->fetchAll();
    foreach ($data['projects'] as &$p) {
        $p['id'] = (int)$p['id'];
        $p['owner_id'] = $p['owner_id'] === null ? null : (int)$p['owner_id'];
        $p['responsible_id'] = $p['responsible_id'] === null ? null : (int)$p['responsible_id'];
    }

    $data['boards'] = $pdo->query('SELECT id, project_id, name FROM boards ORDER BY id')->fetchAll();
    foreach ($data['boards'] as &$b) {
        $b['id'] = (int)$b['id'];
        $b['project_id'] = (int)$b['project_id'];
    }

    $data['project_members'] = $pdo->query('SELECT id, project_id, user_id, created_at FROM project_members ORDER BY project_id, user_id, id')->fetchAll();
    foreach ($data['project_members'] as &$pm) {
        $pm['id'] = (int)$pm['id'];
        $pm['project_id'] = (int)$pm['project_id'];
        $pm['user_id'] = (int)$pm['user_id'];
    }

    $data['columns'] = $pdo->query('SELECT id, board_id, name, position FROM kanban_columns ORDER BY position, id')->fetchAll();
    foreach ($data['columns'] as &$c) {
        $c['id'] = (int)$c['id'];
        $c['board_id'] = (int)$c['board_id'];
        $c['position'] = (int)$c['position'];
    }

    $data['tasks'] = $pdo->query('SELECT id, column_id, title, description, priority, assigned_to, position, locked_by, locked_at, due_at, created_at, updated_at FROM tasks ORDER BY position, id')->fetchAll();
    foreach ($data['tasks'] as &$t) {
        $t['id'] = (int)$t['id'];
        $t['column_id'] = (int)$t['column_id'];
        $t['assigned_to'] = $t['assigned_to'] === null ? null : (int)$t['assigned_to'];
        $t['position'] = (int)$t['position'];
        $t['locked_by'] = $t['locked_by'] === null ? null : (int)$t['locked_by'];
    }

    $data['comments'] = $pdo->query('SELECT id, task_id, user_id, content, created_at FROM comments ORDER BY id')->fetchAll();
    foreach ($data['comments'] as &$c) {
        $c['id'] = (int)$c['id'];
        $c['task_id'] = (int)$c['task_id'];
        $c['user_id'] = (int)$c['user_id'];
    }

    $data['time_entries'] = $pdo->query('SELECT id, task_id, user_id, started_at, stopped_at, seconds FROM time_entries ORDER BY id')->fetchAll();
    foreach ($data['time_entries'] as &$te) {
        $te['id'] = (int)$te['id'];
        $te['task_id'] = (int)$te['task_id'];
        $te['user_id'] = (int)$te['user_id'];
        $te['seconds'] = (int)$te['seconds'];
    }

    $data['events'] = $pdo->query('SELECT id, task_id, user_id, type, message, created_at FROM events ORDER BY id')->fetchAll();
    foreach ($data['events'] as &$e) {
        $e['id'] = (int)$e['id'];
        $e['task_id'] = $e['task_id'] === null ? null : (int)$e['task_id'];
        $e['user_id'] = $e['user_id'] === null ? null : (int)$e['user_id'];
    }

    $data['history'] = $pdo->query('SELECT id, task_id, user_id, action, field, old_value, new_value, message, created_at FROM history ORDER BY id')->fetchAll();
    foreach ($data['history'] as &$h) {
        $h['id'] = (int)$h['id'];
        $h['task_id'] = (int)$h['task_id'];
        $h['user_id'] = $h['user_id'] === null ? null : (int)$h['user_id'];
    }

    return $data;
}

// -----------------------------------------------------------------------------
// MySQL speichern: Array-Struktur zurück in Tabellen schreiben
// -----------------------------------------------------------------------------

/**
 * Speichert den kompletten aktuellen Datenstand in MySQL.
 *
 * Vorgehen:
 * - alte Tabelleninhalte in FK-sicherer Reihenfolge löschen,
 * - alle Arrays wieder in ihre Tabellen schreiben,
 * - alles innerhalb einer Transaktion ausführen.
 */
function storage_save_db(PDO $pdo, array $data): void
{
    // Sicherheitsnetz gegen hängengebliebene Transaktionen aus alten SQL-Dump-Initialisierungen.
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    $pdo->beginTransaction();

    try {
        // Kindtabellen zuerst löschen, damit Foreign Keys nicht blockieren.
        foreach (['history', 'events', 'time_entries', 'comments', 'tasks', 'kanban_columns', 'boards', 'project_members', 'projects', 'users'] as $table) {
            $pdo->exec("DELETE FROM {$table}");
        }

        // Benutzer speichern.
        $stmt = $pdo->prepare('INSERT INTO users (id, username, email, password_hash, role, is_active, created_at)
            VALUES (:id, :username, :email, :password_hash, :role, :is_active, :created_at)');
        foreach ($data['users'] ?? [] as $u) {
            $stmt->execute([
                ':id' => (int)$u['id'],
                ':username' => $u['username'] ?? '',
                ':email' => $u['email'] ?? null,
                ':password_hash' => $u['password_hash'] ?? '',
                ':role' => $u['role'] ?? 'user',
                ':is_active' => empty($u['is_active']) ? 0 : 1,
                ':created_at' => $u['created_at'] ?? null,
            ]);
        }

        // Projekte speichern.
        $stmt = $pdo->prepare('INSERT INTO projects (id, name, description, owner_id, responsible_id, created_at)
            VALUES (:id, :name, :description, :owner_id, :responsible_id, :created_at)');
        foreach ($data['projects'] ?? [] as $p) {
            $stmt->execute([
                ':id' => (int)$p['id'],
                ':name' => $p['name'] ?? '',
                ':description' => $p['description'] ?? null,
                ':owner_id' => $p['owner_id'] ?? null,
                ':responsible_id' => $p['responsible_id'] ?? null,
                ':created_at' => $p['created_at'] ?? null,
            ]);
        }

        // Projektmitglieder speichern.
        $stmt = $pdo->prepare('INSERT INTO project_members (id, project_id, user_id, created_at)
            VALUES (:id, :project_id, :user_id, :created_at)');
        foreach ($data['project_members'] ?? [] as $pm) {
            $stmt->execute([
                ':id' => (int)$pm['id'],
                ':project_id' => (int)$pm['project_id'],
                ':user_id' => (int)$pm['user_id'],
                ':created_at' => $pm['created_at'] ?? null,
            ]);
        }

        // Boards speichern.
        $stmt = $pdo->prepare('INSERT INTO boards (id, project_id, name)
            VALUES (:id, :project_id, :name)');
        foreach ($data['boards'] ?? [] as $b) {
            $stmt->execute([
                ':id' => (int)$b['id'],
                ':project_id' => (int)($b['project_id'] ?? 1),
                ':name' => $b['name'] ?? '',
            ]);
        }

        // Kanban-Spalten speichern.
        $stmt = $pdo->prepare('INSERT INTO kanban_columns (id, board_id, name, position)
            VALUES (:id, :board_id, :name, :position)');
        foreach ($data['columns'] ?? [] as $c) {
            $stmt->execute([
                ':id' => (int)$c['id'],
                ':board_id' => (int)($c['board_id'] ?? 1),
                ':name' => $c['name'] ?? '',
                ':position' => (int)($c['position'] ?? 0),
            ]);
        }

        // Aufgaben speichern.
        $stmt = $pdo->prepare('INSERT INTO tasks (id, column_id, title, description, priority, assigned_to, position, locked_by, locked_at, due_at, created_at, updated_at)
            VALUES (:id, :column_id, :title, :description, :priority, :assigned_to, :position, :locked_by, :locked_at, :due_at, :created_at, :updated_at)');
        foreach ($data['tasks'] ?? [] as $t) {
            $stmt->execute([
                ':id' => (int)$t['id'],
                ':column_id' => (int)($t['column_id'] ?? 1),
                ':title' => $t['title'] ?? '',
                ':description' => $t['description'] ?? null,
                ':priority' => $t['priority'] ?? 'medium',
                ':assigned_to' => $t['assigned_to'] ?? null,
                ':position' => (int)($t['position'] ?? 999),
                ':locked_by' => $t['locked_by'] ?? null,
                ':locked_at' => $t['locked_at'] ?? null,
                ':due_at' => $t['due_at'] ?? null,
                ':created_at' => $t['created_at'] ?? null,
                ':updated_at' => $t['updated_at'] ?? null,
            ]);
        }

        // Kommentare speichern.
        $stmt = $pdo->prepare('INSERT INTO comments (id, task_id, user_id, content, created_at)
            VALUES (:id, :task_id, :user_id, :content, :created_at)');
        foreach ($data['comments'] ?? [] as $c) {
            $stmt->execute([
                ':id' => (int)$c['id'],
                ':task_id' => (int)$c['task_id'],
                ':user_id' => (int)$c['user_id'],
                ':content' => $c['content'] ?? '',
                ':created_at' => $c['created_at'] ?? null,
            ]);
        }

        // Zeiterfassungen speichern.
        $stmt = $pdo->prepare('INSERT INTO time_entries (id, task_id, user_id, started_at, stopped_at, seconds)
            VALUES (:id, :task_id, :user_id, :started_at, :stopped_at, :seconds)');
        foreach ($data['time_entries'] ?? [] as $te) {
            $stmt->execute([
                ':id' => (int)$te['id'],
                ':task_id' => (int)$te['task_id'],
                ':user_id' => (int)$te['user_id'],
                ':started_at' => $te['started_at'] ?? null,
                ':stopped_at' => $te['stopped_at'] ?? null,
                ':seconds' => (int)($te['seconds'] ?? 0),
            ]);
        }

        // Ereignisse speichern.
        $stmt = $pdo->prepare('INSERT INTO events (id, task_id, user_id, type, message, created_at)
            VALUES (:id, :task_id, :user_id, :type, :message, :created_at)');
        foreach ($data['events'] ?? [] as $e) {
            $stmt->execute([
                ':id' => (int)$e['id'],
                ':task_id' => $e['task_id'] ?? null,
                ':user_id' => $e['user_id'] ?? null,
                ':type' => $e['type'] ?? '',
                ':message' => $e['message'] ?? null,
                ':created_at' => $e['created_at'] ?? null,
            ]);
        }

        // Änderungsprotokoll speichern.
        $stmt = $pdo->prepare('INSERT INTO history (id, task_id, user_id, action, field, old_value, new_value, message, created_at)
            VALUES (:id, :task_id, :user_id, :action, :field, :old_value, :new_value, :message, :created_at)');
        foreach ($data['history'] ?? [] as $h) {
            $stmt->execute([
                ':id' => (int)$h['id'],
                ':task_id' => (int)$h['task_id'],
                ':user_id' => $h['user_id'] ?? null,
                ':action' => $h['action'] ?? '',
                ':field' => $h['field'] ?? null,
                ':old_value' => $h['old_value'] ?? null,
                ':new_value' => $h['new_value'] ?? null,
                ':message' => $h['message'] ?? null,
                ':created_at' => $h['created_at'] ?? null,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
