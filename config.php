<?php
/**
 * Zentrale Konfiguration für Kanban Light PolarisNova.
 *
 * Diese Datei enthält nur technische Einstellungen. Sie wird von der
 * Storage-Schicht (`lib/storage.php`) geladen und nicht direkt vom Frontend.
 */

return [
    // -------------------------------------------------------------------------
    // MySQL-/PDO-Konfiguration
    // -------------------------------------------------------------------------
    'db' => [
        // true = MySQL/PDO aktivieren, false = nur JSON-Fallback nutzen.
        'enabled' => true,

        // Lokale XAMPP-/MariaDB-Standardwerte.
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'polarisnova',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',

        // Versucht die Datenbank automatisch anzulegen, sofern der DB-User Rechte hat.
        'auto_create_database' => true,
    ],

    // -------------------------------------------------------------------------
    // JSON-Konfiguration
    // -------------------------------------------------------------------------
    'json' => [
        // Primäre JSON-Datei für Offline-Fallback und Erstimport.
        'file' => __DIR__ . '/data/data.json',

        // Zusätzliche Sicherungskopie nach erfolgreichen Speicherprozessen.
        'backup_file' => __DIR__ . '/data/data.backup.json',

        // Wenn MySQL nicht erreichbar ist, wird JSON weiter als Offline-Datenhaltung genutzt.
        'fallback_when_db_unavailable' => true,

        // Wenn MySQL leer ist, wird data.json einmalig nach MySQL importiert.
        'import_json_when_db_empty' => true,

        // Nach erfolgreichem MySQL-Lesen/-Schreiben wird JSON als Backup aktualisiert.
        'sync_mysql_to_json' => true,
    ],
];
