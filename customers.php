<?php
/**
 * Eigenständige Kundenverwaltung für PolarisNova.
 *
 * Diese Seite ist absichtlich nicht als Menüpunkt im Kanban eingebunden.
 * Sie nutzt nur die bestehende Anmeldung und liest PolarisNova-Projekte, Boards,
 * Aufgabenstände und Mitarbeiterinformationen zur Einordnung der Kunden.
 */

session_start();

require_once __DIR__ . '/lib/storage.php';

function customers_page_load_data(): array
{
    return storage_load();
}

function customers_page_current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $data = customers_page_load_data();

    foreach ($data['users'] ?? [] as $user) {
        if ((int)$user['id'] === (int)$_SESSION['user_id']) {
            if (isset($user['is_active']) && !$user['is_active']) {
                $_SESSION = [];
                session_destroy();
                return null;
            }

            return $user;
        }
    }

    return null;
}

$user = customers_page_current_user();

if (!$user) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kundenverwaltung · PolarisNova</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/customers.css">
<link rel="icon" href="icon.png" sizes="192x192" />
<link rel="apple-touch-icon" href="icon.png" />
<meta name="msapplication-TileImage" content="icon.png" />
</head>
<body class="customers-page">

<header class="topbar">
    <div>
        <strong>Kundenverwaltung PolarisNova</strong>
        <span class="muted">eigenständig · SQL-Tabellen kv_* · liest PolarisNova</span>
    </div>

    <nav>
        <a class="ghost" href="index.php">Kanban öffnen</a>
        <a class="ghost" href="accounting.php">Rechnungen/EÜR</a>
        <a class="ghost" href="pm.php">Nachrichten</a>
        <a class="ghost" href="tickets.php">Tickets</a>
        <a href="logout.php">Logout (<?= htmlspecialchars($user['username']) ?>)</a>
    </nav>
</header>

<main class="customer-layout">
    <aside class="customer-sidebar">
        <section class="panel">
            <h2>Kunden</h2>
            <p class="muted no-margin-left">Diese Verwaltung läuft getrennt vom Kanban. Kundendaten werden in eigenen SQL-Tabellen mit Präfix kv_ gespeichert; PolarisNova wird hier nur gelesen.</p>
            <div id="customerStats" class="customer-stats">Lade Daten …</div>
        </section>

        <section class="panel">
            <h2>Filter</h2>
            <label>
                Suche
                <input id="customerSearch" placeholder="Firma, Kontakt, Ort, Projekt …">
            </label>
            <label>
                Status
                <select id="customerStatusFilter">
                    <option value="">Alle Status</option>
                    <option value="lead">Lead</option>
                    <option value="active">Aktiv</option>
                    <option value="paused">Pausiert</option>
                    <option value="archived">Archiviert</option>
                </select>
            </label>
            <label>
                Projekt aus PolarisNova
                <select id="customerProjectFilter">
                    <option value="">Alle Projekte</option>
                </select>
            </label>
        </section>

        <section class="panel">
            <h2>PolarisNova-Datenquelle</h2>
            <p><b>Richtung:</b> Kundenverwaltung liest PolarisNova-Projekte und verlinkt zurück ins Kanban.</p>
            <p><b>Kanban:</b> ist per Projekt-/Board-Link direkt erreichbar.</p>
            <p><b>Speicher:</b> eigene SQL-Tabellen <code>kv_*</code> mit JSON-Fallback.</p>
        </section>

        <section class="panel version-panel">
            <h2>Systemstand</h2>
	    <img src="icon.png" style="width:100%;">
            <p><b>Version:</b> <span id="appVersion">Weiterentwicklung v1.8.2</span></p>
            <p><b>Letztes Update:</b> <span id="appLastUpdate">23.06.2026</span></p>
            <p class="small muted no-margin-left" id="appVersionNote">
                Projekt-/Boardverwaltung, Projektverantwortliche, JSON-Fallback,
                JSON-nach-MySQL-Wiederherstellung, Auto-Sortierung, scrollbare Spalten und UI-State-Fix, Kundenverwaltung.
            </p>
        </section>
    </aside>

    <section class="customer-main">
        <div class="customer-toolbar panel">
            <div>
                <h1>Kundenübersicht</h1>
                <p class="muted no-margin-left">Kunden mit zugeordneten PolarisNova-Projekten, Aufgabenständen und Verantwortlichen.</p>
            </div>
            <button type="button" id="newCustomerBtn" hidden>+ Kunde</button>
        </div>

        <section id="customerGrid" class="customer-grid"></section>

        <section class="panel">
            <h2>Projektstände aus PolarisNova</h2>
            <div id="projectSummaryGrid" class="project-summary-grid"></div>
        </section>
    </section>
</main>

<div class="modal" id="customerModal" aria-hidden="true">
    <div class="modal-card wide">
        <button class="x" type="button" data-close="customerModal">×</button>
        <h2 id="customerModalTitle">Kunde</h2>

        <input type="hidden" id="customerId">

        <div class="grid2">
            <label>
                Firma / Kunde
                <input id="customerCompany" required>
            </label>
            <label>
                Kontaktperson
                <input id="customerContact">
            </label>
            <label>
                E-Mail
                <input id="customerEmail" type="email">
            </label>
            <label>
                Telefon
                <input id="customerPhone">
            </label>
            <label>
                Website
                <input id="customerWebsite">
            </label>
            <label>
                Ort
                <input id="customerCity">
            </label>
            <label>
                Typ
                <select id="customerType">
                    <option value="customer">Kunde</option>
                    <option value="prospect">Interessent</option>
                    <option value="partner">Partner</option>
                    <option value="internal">Intern</option>
                </select>
            </label>
            <label>
                Status
                <select id="customerStatus">
                    <option value="lead">Lead</option>
                    <option value="active">Aktiv</option>
                    <option value="paused">Pausiert</option>
                    <option value="archived">Archiviert</option>
                </select>
            </label>
        </div>

        <label>
            Adresse
            <input id="customerAddress">
        </label>

        <label>
            Quelle / Herkunft
            <input id="customerSource" placeholder="z. B. Empfehlung, Website, Bestandskunde">
        </label>

        <label>
            Zugeordnete PolarisNova-Projekte
            <div id="customerProjectChecks" class="project-check-list"></div>
        </label>

        <label>
            Notizen
            <textarea id="customerNotes" rows="4"></textarea>
        </label>

        <div class="actions">
            <button type="button" id="saveCustomerBtn">Speichern</button>
            <button type="button" class="ghost" data-close="customerModal">Abbrechen</button>
        </div>
    </div>
</div>

<script src="assets/js/customers.js"></script>
</body>
</html>
