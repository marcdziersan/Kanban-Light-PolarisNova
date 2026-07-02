<?php
/**
 * PolarisNova Rechnungen & EÜR.
 *
 * Eigenständige Moduloberfläche innerhalb der Anwendung:
 * - Rechnungen aus bestehenden Zeitbuchungen erzeugen.
 * - Rechnungsstatus pflegen.
 * - Vorbereitende EÜR-Auswertung je Mitarbeiter und gesamt anzeigen.
 */

session_start();
require_once __DIR__ . '/lib/storage.php';

function accounting_page_current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $d = storage_load();
    foreach ($d['users'] ?? [] as $u) {
        if ((int)$u['id'] === (int)$_SESSION['user_id']) {
            if (isset($u['is_active']) && !$u['is_active']) {
                $_SESSION = [];
                session_destroy();
                return null;
            }
            return $u;
        }
    }

    return null;
}

$user = accounting_page_current_user();
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
    <title>PolarisNova · Rechnungen & EÜR</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/accounting.css">
    <link rel="icon" href="icon.png" sizes="192x192">
    <link rel="apple-touch-icon" href="icon.png">
</head>
<body>
<header class="topbar">
    <div>
        <strong>PolarisNova Rechnungen & EÜR</strong>
        <span class="muted">Rechnungen · Zeiten · vorbereitende EÜR</span>
    </div>

    <nav>
        <a class="ghost" href="index.php">Kanban</a>
        <a class="ghost" href="customers.php">Kunden</a>
        <a class="ghost" href="pm.php">Nachrichten</a>
        <a class="ghost" href="tickets.php">Tickets</a>
        <a href="logout.php">Logout (<?= htmlspecialchars($user['username']) ?>)</a>
    </nav>
</header>

<main class="accounting-layout">
    <aside class="accounting-sidebar">
        <section class="panel">
            <h2>Modul</h2>
            <p class="muted no-margin-left">
                Rechnungsentwürfe werden aus vorhandenen Zeitbuchungen erzeugt.
                Die EÜR-Auswertung ist eine vorbereitende interne Übersicht.
            </p>
            <p><b>Benutzer:</b> <?= htmlspecialchars($user['username']) ?></p>
            <p><b>Rolle:</b> <?= htmlspecialchars($user['role']) ?></p>
        </section>

        <section class="panel">
            <h2>Ansicht</h2>
            <button type="button" class="accounting-tab active" data-tab="dashboard">Übersicht</button>
            <button type="button" class="accounting-tab" data-tab="invoices">Rechnungen</button>
            <?php if ($user['role'] === 'admin'): ?>
                <button type="button" class="accounting-tab" data-tab="builder">Rechnung aus Zeiten</button>
                <button type="button" class="accounting-tab" data-tab="eur-entry">EÜR-Buchung</button>
            <?php endif; ?>
            <button type="button" class="accounting-tab" data-tab="eur">EÜR-Auswertung</button>
        </section>
    </aside>

    <section class="accounting-main">
        <div class="accounting-headline">
            <div>
                <h1>Rechnungen & EÜR</h1>
                <p class="muted no-margin-left">
                    Kunden, Projekte, Aufgaben und Zeitbuchungen abrechnungsfähig zusammenführen.
                </p>
            </div>
            <div class="actions">
                <button type="button" id="accReloadBtn">Neu laden</button>
                <button type="button" id="accCsvBtn">EÜR CSV</button>
                <button type="button" id="accPrintBtn">Drucken / PDF</button>
            </div>
        </div>

        <section class="panel accounting-panel" data-view="dashboard">
            <h2>Übersicht</h2>
            <div id="accountingDashboard" class="accounting-grid"></div>
        </section>

        <section class="panel accounting-panel" data-view="invoices" hidden>
            <h2>Rechnungen</h2>
            <div class="accounting-controls">
                <label>Status
                    <select id="invoiceStatusFilter">
                        <option value="">Alle</option>
                        <option value="draft">Entwurf</option>
                        <option value="sent">Gesendet</option>
                        <option value="paid">Bezahlt</option>
                        <option value="cancelled">Storniert</option>
                    </select>
                </label>
                <label>Suche
                    <input id="invoiceSearch" placeholder="Rechnung, Kunde, Projekt...">
                </label>
            </div>
            <div id="invoiceList"></div>
        </section>

        <?php if ($user['role'] === 'admin'): ?>
        <section class="panel accounting-panel" data-view="builder" hidden>
            <h2>Rechnung aus Zeiten erzeugen</h2>
            <p class="muted no-margin-left">
                Es werden nur abgeschlossene, noch nicht abgerechnete Zeitbuchungen angezeigt.
            </p>

            <form id="invoiceBuilderForm" class="accounting-form">
                <div class="grid2">
                    <label>Kunde
                        <select id="invoiceCustomer" name="customer_id"></select>
                    </label>
                    <label>Projekt
                        <select id="invoiceProject" name="project_id"></select>
                    </label>
                </div>
                <div class="grid2">
                    <label>Rechnungstitel
                        <input name="title" value="Leistungsabrechnung">
                    </label>
                    <label>Stundensatz netto
                        <input name="hourly_rate" type="number" step="0.01" min="0" value="60.00">
                    </label>
                </div>
                <div class="grid3">
                    <label>Rechnungsdatum
                        <input name="invoice_date" type="date">
                    </label>
                    <label>Fällig am
                        <input name="due_date" type="date">
                    </label>
                    <label>USt. %
                        <input name="vat_rate" type="number" step="0.01" min="0" value="19.00">
                    </label>
                </div>
                <label>Notiz
                    <textarea name="notes" rows="3" placeholder="Zahlungsziel, Leistungszeitraum, interne Notiz..."></textarea>
                </label>

                <div class="actions">
                    <button type="submit">Rechnung aus ausgewählten Zeiten erzeugen</button>
                    <button type="button" class="ghost" id="selectAllTimesBtn">alle sichtbaren Zeiten markieren</button>
                </div>
            </form>

            <h3>Abrechenbare Zeiten</h3>
            <div id="billableTimeList" class="accounting-table-wrap"></div>
        </section>

        <section class="panel accounting-panel" data-view="eur-entry" hidden>
            <h2>Manuelle EÜR-Buchung</h2>
            <p class="muted no-margin-left">
                Für Ausgaben, private Nachträge oder allgemeine Einnahmen außerhalb der automatischen Rechnungslogik.
            </p>

            <form id="eurEntryForm" class="accounting-form">
                <input type="hidden" name="id">
                <div class="grid3">
                    <label>Datum
                        <input name="entry_date" type="date" required>
                    </label>
                    <label>Art
                        <select name="type">
                            <option value="expense">Ausgabe</option>
                            <option value="income">Einnahme</option>
                        </select>
                    </label>
                    <label>Mitarbeiter / allgemein
                        <select name="user_id" id="eurEntryUser"></select>
                    </label>
                </div>
                <div class="grid3">
                    <label>Kategorie
                        <input name="category" placeholder="Hosting, Software, Fahrtkosten...">
                    </label>
                    <label>Betrag netto
                        <input name="amount_net" type="number" step="0.01" min="0" required>
                    </label>
                    <label>USt. %
                        <input name="vat_rate" type="number" step="0.01" min="0" value="19.00">
                    </label>
                </div>
                <label>Beschreibung
                    <textarea name="description" rows="3"></textarea>
                </label>
                <div class="actions">
                    <button type="submit">Buchung speichern</button>
                    <button type="reset" class="ghost">Formular leeren</button>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <section class="panel accounting-panel" data-view="eur" hidden>
            <h2>EÜR-Auswertung</h2>
            <div class="accounting-controls">
                <label>Monat
                    <input id="eurMonth" type="month">
                </label>
                <label>Mitarbeiter
                    <select id="eurUser"></select>
                </label>
                <button type="button" id="eurApplyBtn">Auswertung aktualisieren</button>
            </div>
            <div id="eurReport"></div>
        </section>
    </section>
</main>

<script>
    window.APP_USER = <?= json_encode([
        'id' => $user['id'],
        'role' => $user['role'],
        'username' => $user['username'],
    ], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/accounting.js"></script>
</body>
</html>
