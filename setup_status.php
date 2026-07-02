<?php
/**
 * Diagnose-Seite für die Datenhaltung.
 *
 * Diese Seite zeigt schnell, ob MySQL/PDO erreichbar ist oder ob das System
 * gerade im JSON-Offline-Fallback läuft. Sie ist für Entwicklung, Prüfung und
 * Fehlersuche gedacht.
 */

require_once __DIR__ . '/lib/storage.php';

header('Content-Type: text/html; charset=utf-8');

// -----------------------------------------------------------------------------
// Statusdaten laden
// -----------------------------------------------------------------------------

$mode = storage_mode_label();
$dbOk = storage_db_available();
$data = storage_load();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>PolarisNova Storage Status</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            padding: 24px;
            background: #f4f6f8;
        }

        .status-card {
            max-width: 850px;
            background: white;
            border: 1px solid #dde3ea;
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
        }

        pre {
            background: #111827;
            color: #e5e7eb;
            border-radius: 12px;
            padding: 14px;
            overflow: auto;
        }
    </style>
</head>
<body>

<section class="status-card">
    <h1>PolarisNova Storage Status</h1>

    <p><b>Modus:</b> <?= htmlspecialchars($mode) ?></p>
    <p><b>MySQL/PDO:</b> <?= $dbOk ? 'verbunden' : 'nicht erreichbar – JSON Offline-Fallback aktiv' ?></p>
    <p><b>Benutzer:</b> <?= count($data['users'] ?? []) ?></p>
    <p><b>Aufgaben:</b> <?= count($data['tasks'] ?? []) ?></p>
    <p><b>Historie:</b> <?= count($data['history'] ?? []) ?></p>
    <p><b>Rechnungen:</b> <?= count($data['invoices'] ?? []) ?></p>
    <p><b>EÜR-Buchungen:</b> <?= count($data['eur_entries'] ?? []) ?></p>
    <p><b>PM-Nachrichten:</b> <?= count($data['pm_messages'] ?? []) ?></p>
    <p><b>Pinnwand:</b> <?= count($data['pm_pinboard'] ?? []) ?></p>
    <p><b>Support-Tickets:</b> <?= count($data['support_tickets'] ?? []) ?></p>

    <h2>Hinweis</h2>
    <p>
        Beim ersten Start wird <code>data/data.json</code> automatisch nach MySQL importiert,
        wenn die MySQL-Tabellen leer sind. Danach wird MySQL primär genutzt und JSON als
        Backup synchronisiert.
    </p>

    <h2>config.php</h2>
    <pre><?= htmlspecialchars(file_get_contents(__DIR__ . '/config.php')) ?></pre>
</section>

</body>
</html>
