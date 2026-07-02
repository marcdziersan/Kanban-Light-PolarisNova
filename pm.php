<?php
/**
 * PolarisNova PM-System.
 *
 * Nachrichtenmodul für:
 * - private Mitarbeiter-Nachrichten
 * - Projektgruppenchat
 * - Gesamt-/Unternehmenschat
 * - Admin-Pinnwand
 */

session_start();
require_once __DIR__ . '/lib/storage.php';

function pm_page_current_user(): ?array
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

$user = pm_page_current_user();
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
    <title>PolarisNova · PM-System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/pm.css">
    <link rel="icon" href="icon.png" sizes="192x192">
    <link rel="apple-touch-icon" href="icon.png">
</head>
<body>
<header class="topbar">
    <div>
        <strong>PolarisNova PM-System</strong>
        <span class="muted">Nachrichten · Projektchat · Gesamtchat · Admin-Pinnwand</span>
    </div>

    <nav>
        <a class="ghost" href="index.php">Kanban</a>
        <a class="ghost" href="customers.php">Kunden</a>
        <a class="ghost" href="accounting.php">Rechnungen/EÜR</a>
        <a class="ghost" href="tickets.php">Tickets</a>
        <a href="logout.php">Logout (<?= htmlspecialchars($user['username']) ?>)</a>
    </nav>
</header>

<main class="pm-layout">
    <aside class="pm-sidebar">
        <section class="panel">
            <h2>Postfach</h2>
            <p class="muted no-margin-left">
                Projektbezogene Kommunikation bleibt beim Projekt. Private Nachrichten bleiben beim Empfänger.
                Admin-Infos landen auf der Pinnwand.
            </p>
            <p><b>Benutzer:</b> <?= htmlspecialchars($user['username']) ?></p>
            <p><b>Rolle:</b> <?= htmlspecialchars($user['role']) ?></p>
            <p><b>Ungelesen:</b> <span id="pmUnreadTotal">0</span></p>
        </section>

        <section class="panel">
            <h2>Ansicht</h2>
            <button type="button" class="pm-tab active" data-tab="inbox">Posteingang</button>
            <button type="button" class="pm-tab" data-tab="direct">Private Nachricht</button>
            <button type="button" class="pm-tab" data-tab="project">Projektchat</button>
            <button type="button" class="pm-tab" data-tab="company">Gesamtchat</button>
            <button type="button" class="pm-tab" data-tab="pinboard">Pinnwand</button>
            <?php if ($user['role'] === 'admin'): ?>
                <button type="button" class="pm-tab" data-tab="admin-pin">Pinnwand verwalten</button>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Filter</h2>
            <label>Suche
                <input id="pmSearch" placeholder="Text, Betreff, Benutzer, Projekt …">
            </label>
            <label>Projekt
                <select id="pmProjectFilter">
                    <option value="">Alle sichtbaren Projekte</option>
                </select>
            </label>
            <label>Status
                <select id="pmReadFilter">
                    <option value="">Alle Nachrichten</option>
                    <option value="unread">Nur ungelesen</option>
                    <option value="read">Nur gelesen</option>
                </select>
            </label>
        </section>
    </aside>

    <section class="pm-main">
        <div class="pm-headline">
            <div>
                <h1>Nachrichten</h1>
                <p class="muted no-margin-left">
                    Einzelne Mitarbeiter, Projektgruppen und das gesamte Unternehmen direkt aus PolarisNova heraus informieren.
                </p>
            </div>
            <div class="actions">
                <button type="button" id="pmReloadBtn">Neu laden</button>
                <button type="button" id="pmPrintBtn">Drucken</button>
            </div>
        </div>

        <section class="panel pm-panel" data-view="inbox">
            <h2>Posteingang und Verlauf</h2>
            <div id="pmInboxList" class="pm-list">Lade Nachrichten …</div>
        </section>

        <section class="panel pm-panel" data-view="direct" hidden>
            <h2>Private Nachricht an Mitarbeiter</h2>
            <form id="pmDirectForm" class="pm-form">
                <label>Empfänger
                    <select name="recipient_id" id="pmDirectRecipient"></select>
                </label>
                <label>Betreff
                    <input name="subject" maxlength="160" placeholder="Kurzer Betreff">
                </label>
                <label>Nachricht
                    <textarea name="content" placeholder="Nachricht an einen Mitarbeiter …" required></textarea>
                </label>
                <button type="submit">Private Nachricht senden</button>
            </form>
        </section>

        <section class="panel pm-panel" data-view="project" hidden>
            <h2>Projektinterner Gruppenchat</h2>
            <form id="pmProjectForm" class="pm-form">
                <label>Projekt
                    <select name="project_id" id="pmProjectSelect"></select>
                </label>
                <label>Betreff
                    <input name="subject" maxlength="160" placeholder="Optionaler Betreff">
                </label>
                <label>Nachricht
                    <textarea name="content" placeholder="Nachricht in den Projektchat …" required></textarea>
                </label>
                <button type="submit">In Projektchat senden</button>
            </form>
        </section>

        <section class="panel pm-panel" data-view="company" hidden>
            <h2>Gesamtchat / Unternehmensnachricht</h2>
            <form id="pmCompanyForm" class="pm-form">
                <label>Betreff
                    <input name="subject" maxlength="160" placeholder="Optionaler Betreff">
                </label>
                <label>Nachricht
                    <textarea name="content" placeholder="Nachricht an alle aktiven Benutzer …" required></textarea>
                </label>
                <button type="submit">An Gesamtchat senden</button>
            </form>
        </section>

        <section class="panel pm-panel" data-view="pinboard" hidden>
            <h2>Admin-Pinnwand</h2>
            <p class="muted no-margin-left">Wichtige Informationen vom Admin für alle Benutzer.</p>
            <div id="pmPinboardList" class="pm-list">Lade Pinnwand …</div>
        </section>

        <?php if ($user['role'] === 'admin'): ?>
        <section class="panel pm-panel" data-view="admin-pin" hidden>
            <h2>Pinnwand-Eintrag erstellen</h2>
            <form id="pmPinForm" class="pm-form">
                <input type="hidden" name="id" value="">
                <label>Titel
                    <input name="title" maxlength="160" placeholder="Wichtige Information" required>
                </label>
                <label>Priorität
                    <select name="priority">
                        <option value="normal">Normal</option>
                        <option value="important">Wichtig</option>
                        <option value="urgent">Dringend</option>
                    </select>
                </label>
                <label>Aktiv
                    <select name="is_active">
                        <option value="1">Ja, anzeigen</option>
                        <option value="0">Nein, Entwurf</option>
                    </select>
                </label>
                <label>Ablaufdatum optional
                    <input name="expires_at" type="date">
                </label>
                <label>Nachricht
                    <textarea name="content" placeholder="Nachricht für die Pinnwand …" required></textarea>
                </label>
                <button type="submit">Pinnwand speichern</button>
                <button type="button" class="ghost" id="pmPinResetBtn">Formular leeren</button>
            </form>
        </section>
        <?php endif; ?>
    </section>
</main>

<script>
window.POLARIS_USER = <?= json_encode([
    'id' => (int)$user['id'],
    'username' => $user['username'],
    'role' => $user['role'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/js/pm.js"></script>
</body>
</html>
