<?php
/**
 * PolarisNova Ticketsystem.
 *
 * Nutzer melden Fehler/Änderungsbedarf. Admins nehmen Tickets in einem
 * internen Kanban-Board entgegen und geben Rückmeldung an den Ersteller.
 */

session_start();
require_once __DIR__ . '/lib/storage.php';

function tickets_page_current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $d = storage_load();
    foreach ($d['users'] ?? [] as $u) {
        if ((int)($u['id'] ?? 0) === (int)$_SESSION['user_id']) {
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

$user = tickets_page_current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}
$isAdmin = ($user['role'] ?? '') === 'admin';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PolarisNova · Ticketsystem</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/tickets.css">
    <link rel="icon" href="icon.png" sizes="192x192">
    <link rel="apple-touch-icon" href="icon.png">
</head>
<body>
<header class="topbar">
    <div>
        <strong>PolarisNova Ticketsystem</strong>
        <span class="muted">Fehler · Wünsche · Admin-Kanban · Rückmeldung</span>
    </div>

    <nav>
        <a class="ghost" href="index.php">Kanban</a>
        <a class="ghost" href="customers.php">Kunden</a>
        <a class="ghost" href="accounting.php">Rechnungen/EÜR</a>
        <a class="ghost" href="pm.php">Nachrichten</a>
        <a href="logout.php">Logout (<?= htmlspecialchars($user['username']) ?>)</a>
    </nav>
</header>

<main class="ticket-layout">
    <aside class="ticket-sidebar">
        <section class="panel">
            <h2>Tickets</h2>
            <p class="muted no-margin-left">
                Melde Fehler, Änderungsbedarf oder Rückfragen mit Bereich, Soll-Zustand und Ist-Zustand.
                Admins bearbeiten die Tickets im internen Kanban-Board.
            </p>
            <p><b>Benutzer:</b> <?= htmlspecialchars($user['username']) ?></p>
            <p><b>Rolle:</b> <?= htmlspecialchars($user['role']) ?></p>
        </section>

        <section class="panel">
            <h2>Ansicht</h2>
            <button type="button" class="ticket-tab active" data-tab="create">Ticket erstellen</button>
            <button type="button" class="ticket-tab" data-tab="mine">Meine Tickets</button>
            <?php if ($isAdmin): ?>
                <button type="button" class="ticket-tab" data-tab="board">Admin-Board</button>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Filter</h2>
            <label>Suche
                <input id="ticketSearch" placeholder="Titel, Nummer, Bereich, Text …">
            </label>
            <label>Status
                <select id="ticketStatusFilter">
                    <option value="">Alle Status</option>
                </select>
            </label>
            <label>Bereich
                <select id="ticketAreaFilter">
                    <option value="">Alle Bereiche</option>
                </select>
            </label>
            <label>Priorität
                <select id="ticketPriorityFilter">
                    <option value="">Alle Prioritäten</option>
                    <option value="critical">Kritisch</option>
                    <option value="high">Hoch</option>
                    <option value="normal">Normal</option>
                    <option value="low">Niedrig</option>
                </select>
            </label>
        </section>
    </aside>

    <section class="ticket-main">
        <div class="ticket-headline">
            <div>
                <h1>Ticketsystem</h1>
                <p class="muted no-margin-left">
                    Fehler und Änderungsbedarf strukturiert erfassen, intern priorisieren und sauber an den Ersteller zurückmelden.
                </p>
            </div>
            <div class="actions">
                <button type="button" id="ticketReloadBtn">Neu laden</button>
                <button type="button" id="ticketPrintBtn">Drucken</button>
            </div>
        </div>

        <section class="panel ticket-panel" data-view="create">
            <h2>Ticket erstellen</h2>
            <?php if (($user['role'] ?? '') === 'guest'): ?>
                <p class="muted no-margin-left">Gäste dürfen keine Tickets erstellen.</p>
            <?php else: ?>
            <form id="ticketCreateForm" class="ticket-form">
                <div class="ticket-grid-form">
                    <label>Bereich
                        <select name="area" id="ticketAreaSelect" required></select>
                    </label>
                    <label>Priorität
                        <select name="priority" id="ticketPrioritySelect" required>
                            <option value="normal">Normal</option>
                            <option value="high">Hoch</option>
                            <option value="critical">Kritisch</option>
                            <option value="low">Niedrig</option>
                        </select>
                    </label>
                    <label>Projekt optional
                        <select name="project_id" id="ticketProjectSelect">
                            <option value="">Ohne Projektbezug</option>
                        </select>
                    </label>
                    <label>Aufgabe optional
                        <select name="task_id" id="ticketTaskSelect">
                            <option value="">Ohne Aufgabenbezug</option>
                        </select>
                    </label>
                </div>

                <label>Titel
                    <input name="title" maxlength="190" placeholder="Kurzer, eindeutiger Titel" required>
                </label>
                <label>Was sollte passieren? / Soll-Zustand
                    <textarea name="expected_result" placeholder="Beschreibe, was fachlich oder technisch erwartet wurde."></textarea>
                </label>
                <label>Was ist passiert? / Ist-Zustand
                    <textarea name="actual_result" placeholder="Beschreibe den Fehler, das Verhalten oder den Änderungsbedarf." required></textarea>
                </label>
                <label>Schritte zur Reproduktion / Kontext
                    <textarea name="steps" placeholder="Welche Klicks, welches Projekt, welche Aufgabe, welcher Browser, welche Daten?"></textarea>
                </label>
                <button type="submit">Ticket absenden</button>
            </form>
            <?php endif; ?>
        </section>

        <section class="panel ticket-panel" data-view="mine" hidden>
            <h2><?= $isAdmin ? 'Alle Tickets als Liste' : 'Meine Tickets' ?></h2>
            <div id="ticketList" class="ticket-list">Lade Tickets …</div>
        </section>

        <?php if ($isAdmin): ?>
        <section class="panel ticket-panel" data-view="board" hidden>
            <h2>Admin-Ticketboard</h2>
            <p class="muted no-margin-left">
                Nur Admins sehen dieses Board. Statusänderungen erzeugen eine Rückmeldung an den Ticketersteller im Nachrichtensystem.
            </p>
            <div id="ticketBoard" class="ticket-board">Lade Board …</div>
        </section>
        <?php endif; ?>
    </section>
</main>

<div id="ticketModal" class="ticket-modal" hidden>
    <div class="ticket-modal-box">
        <header>
            <div>
                <strong id="ticketModalTitle">Ticket</strong>
                <span id="ticketModalMeta" class="muted"></span>
            </div>
            <button type="button" class="ghost" id="ticketModalClose">Schließen</button>
        </header>
        <div id="ticketModalBody" class="ticket-modal-body"></div>
    </div>
</div>

<script>
window.POLARIS_USER = <?= json_encode([
    'id' => (int)$user['id'],
    'username' => (string)$user['username'],
    'role' => (string)$user['role'],
    'is_admin' => $isAdmin,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/js/tickets.js"></script>
</body>
</html>
