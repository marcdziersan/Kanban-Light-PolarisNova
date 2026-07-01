<?php
/**
 * Hauptseite für Kanban Light PolarisNova.
 *
 * Aufgabe der Datei:
 * - Session prüfen.
 * - Aktuellen Benutzer laden.
 * - Nach dem Login zuerst die Projekt-/Boardübersicht anzeigen.
 * - Nach Klick auf ein Projekt die bestehende Kanban-Boardansicht einblenden.
 * - Admin-Modale für Benutzer- und Projektverwaltung bereitstellen.
 *
 * Die dynamischen Daten kommen über assets/js/app.js aus api/api.php.
 */

session_start();

require_once __DIR__ . '/lib/storage.php';

// -----------------------------------------------------------------------------
// Daten- und Session-Hilfsfunktionen
// -----------------------------------------------------------------------------

/**
 * Lädt die Anwendungsdaten über die zentrale Storage-Schicht.
 */
function load_data()
{
    return storage_load();
}

/**
 * Ermittelt den aktuell eingeloggten Benutzer.
 *
 * Wird ein gesperrter Benutzer gefunden, wird die Session beendet und der User
 * muss sich erneut anmelden.
 */
function current_user()
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $d = load_data();

    foreach ($d['users'] as $u) {
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

// -----------------------------------------------------------------------------
// Zugriffsschutz
// -----------------------------------------------------------------------------

$user = current_user();

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
    <title>Kanban Light PolarisNova</title>
    <link rel="stylesheet" href="assets/css/style.css">
<link rel="icon" href="icon.png" sizes="192x192" />
<link rel="apple-touch-icon" href="icon.png" />
<meta name="msapplication-TileImage" content="icon.png" />
</head>

<body>

<!-- -----------------------------------------------------------------------
     Obere Navigationsleiste
     -------------------------------------------------------------------- -->
<header class="topbar">
    <div>
        <strong>Kanban Light PolarisNova</strong>
        <span class="muted">Projekte · Boards · MySQL/PDO · JSON Backup</span>
    </div>

    <nav>
        <button class="ghost" type="button" id="backToProjects" hidden>Projektübersicht</button>
        <button class="ghost" type="button" data-open="taskModal" id="newTaskBtn" hidden>+ Aufgabe</button>

        <?php if ($user['role'] === 'admin'): ?>
            <button class="ghost" type="button" data-open="projectModal">+ Projekt / Board</button>
            <button class="ghost" type="button" data-open="userModal">+ Benutzer</button>
        <?php endif; ?>

        <button class="ghost" type="button" id="openReports" hidden>Reports</button>

        <a class="ghost" href="customers.php">Kunden</a>

        <?php if ($user['role'] !== 'guest'): ?>
            <button class="ghost" type="button" data-open="jsonSyncModal">JSON Sync</button>
        <?php endif; ?>

        <a href="logout.php">
            Logout (<?= htmlspecialchars($user['username']) ?>)
        </a>
    </nav>
</header>

<!-- -----------------------------------------------------------------------
     Projektübersicht nach dem Login
     -------------------------------------------------------------------- -->
<section class="project-overview" id="projectOverview">
    <aside class="overview-sidebar">
        <section class="panel">
            <h2>Projekt</h2>
            <p class="muted no-margin-left">Bitte ein Projekt auswählen.</p>
            <p><b>Board:</b> —</p>
            <p><b>Globale Rolle:</b> <?= htmlspecialchars($user['role']) ?></p>
            <p><b>Datenhaltung:</b> MySQL via PDO</p>
            <p><b>Modus:</b> <?= htmlspecialchars(storage_mode_label()) ?></p>
        </section>

        <section class="panel">
            <h2>Filter</h2>

            <input id="projectSearch" placeholder="Projekt suchen...">

            <select id="projectMemberFilter">
                <option value="">Alle Mitarbeiter</option>
            </select>

            <select id="projectResponsibleFilter">
                <option value="">Alle Verantwortlichen</option>
            </select>
        </section>

        <section class="panel version-panel">
            <h2>Systemstand</h2>
	    <img src="icon.png" style="width:100%;">
            <p><b>Version:</b> <span id="appVersion">Weiterentwicklung v1.8.4</span></p>
            <p><b>Letztes Update:</b> <span id="appLastUpdate">24.06.2026</span></p>
            <p class="small muted no-margin-left" id="appVersionNote">
                UI-State-Fix und erweiterte Zeiterfassungs-/Abrechnungsreports.
            </p>
        </section>
    </aside>

    <section class="project-main">
        <div class="project-headline">
            <div>
                <h1>Projektübersicht</h1>
                <p class="muted no-margin-left">
                    Admins sehen alle Projekte. Mitarbeiter sehen nur Projektboards,
                    denen sie zugeordnet wurden.
                </p>
            </div>

            <?php if ($user['role'] === 'admin'): ?>
                <button type="button" data-open="projectModal">+ Neues Projekt</button>
            <?php endif; ?>
        </div>

        <div class="project-grid" id="projectGrid"></div>
    </section>
</section>

<!-- -----------------------------------------------------------------------
     Board-Arbeitsbereich: wird erst nach Auswahl eines Projekts angezeigt
     -------------------------------------------------------------------- -->
<main class="layout" id="boardWorkspace" hidden>
    <aside class="sidebar">

        <section class="panel">
            <h2 id="activeProjectTitle">Projekt</h2>
            <p id="activeProjectDescription" class="muted">Bitte ein Projekt auswählen.</p>
            <p><b>Board:</b> <span id="activeBoardName">—</span></p>
            <p><b>Globale Rolle:</b> <?= htmlspecialchars($user['role']) ?></p>
            <p><b>Projektrolle:</b> <span id="activeProjectRole"><?= htmlspecialchars($user['role']) ?></span></p>
            <p><b>Datenhaltung:</b> MySQL via PDO</p>
            <p><b>Modus:</b> <?= htmlspecialchars(storage_mode_label()) ?></p>
        </section>

        <section class="panel">
            <h2>Filter</h2>

            <input id="search" placeholder="Suche Aufgabe...">

            <select id="priorityFilter">
                <option value="">Alle Prioritäten</option>
                <option value="low">Niedrig</option>
                <option value="medium">Mittel</option>
                <option value="high">Hoch</option>
                <option value="critical">Kritisch</option>
            </select>

            <select id="assigneeFilter">
                <option value="">Alle Benutzer</option>
            </select>
        </section>
    </aside>

    <!-- Das Board wird per JavaScript aus API-Daten gerendert. -->
    <section class="board" id="board"></section>
</main>

<!-- -----------------------------------------------------------------------
     Aufgaben-Modal: Neu anlegen, bearbeiten, kommentieren, Zeit erfassen
     -------------------------------------------------------------------- -->
<div class="modal" id="taskModal">
    <form class="modal-card" id="taskForm">
        <button type="button" class="x" data-close>×</button>

        <h2>Aufgabe</h2>

        <input type="hidden" name="id">

        <div id="lockStatus" class="lock-status" hidden></div>

        <label>
            Titel
            <input name="title" required maxlength="120">
        </label>

        <label>
            Beschreibung
            <textarea name="description" rows="4"></textarea>
        </label>

        <div class="grid2">
            <label>
                Priorität
                <select name="priority">
                    <option value="low">Niedrig</option>
                    <option value="medium" selected>Mittel</option>
                    <option value="high">Hoch</option>
                    <option value="critical">Kritisch</option>
                </select>
            </label>

            <label>
                Zuweisen
                <select name="assigned_to"></select>
            </label>
        </div>

        <label>
            Stichtag / Fällig bis
            <input name="due_at" type="datetime-local">
        </label>

        <label>
            Spalte
            <select name="column_id"></select>
        </label>

        <div class="actions">
            <button type="button" class="lock-btn" id="lockTask">
                Bearbeitung sperren
            </button>

            <button type="submit">
                Speichern
            </button>

            <button type="button" class="danger" id="deleteTask" hidden>
                Löschen
            </button>
        </div>

        <section id="commentBox" hidden>
            <h3>Kommentare</h3>

            <div id="comments"></div>

            <div class="inline">
                <input id="commentText" placeholder="Kommentar schreiben...">
                <button type="button" id="addComment">Senden</button>
            </div>
        </section>

        <section id="historyBox" hidden>
            <h3>Historie / Änderungsprotokoll</h3>
            <div id="historyList"></div>
        </section>

        <section id="timeBox" hidden>
            <h3>Zeiterfassung</h3>

            <div class="inline">
                <button type="button" id="startTime">Start</button>
                <button type="button" id="stopTime">Stop</button>
            </div>

            <div id="timeList"></div>
        </section>
    </form>
</div>

<!-- -----------------------------------------------------------------------
     Projekt-Modal: Admins und Projektverantwortliche pflegen Projekte/Boards und Zuordnungen
     -------------------------------------------------------------------- -->
<?php if ($user['role'] !== 'guest'): ?>
    <div class="modal" id="projectModal">
        <form class="modal-card wide" id="projectForm">
            <button type="button" class="x" data-close>×</button>

            <h2>Projekt / Board</h2>

            <input type="hidden" name="id">

            <div class="grid2">
                <label>
                    Projektname
                    <input name="name" required maxlength="160">
                </label>

                <label>
                    Boardname
                    <input name="board_name" maxlength="160" placeholder="z. B. Kanban Board">
                </label>
            </div>

            <label>
                Projektinformationen
                <textarea name="description" rows="4" placeholder="Kurzbeschreibung, Ziel, Rahmen oder Kunde..."></textarea>
            </label>

            <label>
                Projektverantwortlicher
                <select name="responsible_id" id="projectResponsible"></select>
            </label>

            <section class="member-box">
                <h3>Mitarbeiter zuordnen</h3>
                <div id="projectMemberList"></div>
            </section>

            <div class="actions">
                <button type="submit">Projekt speichern</button>
                <button type="button" class="ghost" id="resetProjectForm">Neu leeren</button>
                <button type="button" class="danger" id="deleteProject" hidden>Projekt / Board löschen</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($user['role'] === 'admin'): ?>
    <!-- -------------------------------------------------------------------
         Benutzer-Modal: Nur für Admin sichtbar
         ---------------------------------------------------------------- -->
    <div class="modal" id="userModal">
        <form class="modal-card" id="userForm">
            <button type="button" class="x" data-close>×</button>

            <h2>Benutzer</h2>

            <input type="hidden" name="id">

            <label>
                Benutzername
                <input name="username" required>
            </label>

            <label>
                E-Mail
                <input name="email" type="email">
            </label>

            <label>
                Passwort
                <input
                    name="password"
                    type="password"
                    placeholder="leer lassen = unverändert"
                >
            </label>

            <label>
                Rolle
                <select name="role">
                    <option value="admin">Admin</option>
                    <option value="user">Mitarbeiter</option>
                    <option value="guest">Gast</option>
                </select>
            </label>

            <label>
                Status
                <select name="is_active">
                    <option value="1">Aktiv</option>
                    <option value="0">Gesperrt</option>
                </select>
            </label>

            <div class="actions">
                <button type="submit">Speichern</button>

                <button type="button" class="danger" id="deleteUser" hidden>
                    Benutzer löschen
                </button>
            </div>

            <div id="userList"></div>
        </form>
    </div>
<?php endif; ?>

<?php if ($user['role'] !== 'guest'): ?>
    <!-- -------------------------------------------------------------------
         JSON-Sync-Modal: Export und sicherer Offline-Import
         ---------------------------------------------------------------- -->
    <div class="modal" id="jsonSyncModal">
        <section class="modal-card wide">
            <button type="button" class="x" data-close>×</button>

            <h2>JSON Export / Import</h2>

            <div class="sync-box">
                <p>
                    <b>Aktueller Speichermodus:</b>
                    <span id="jsonStorageMode"><?= htmlspecialchars(storage_mode_label()) ?></span>
                </p>

                <p class="muted">
                    Der Export erstellt eine vollständige Sicherung. Der Import ist für Mitarbeiter
                    nur im JSON-Offline-Modus freigegeben, also wenn MySQL/PDO nicht erreichbar ist.
                    Beim Import wird zuerst geprüft, dann gesichert, dann atomar übernommen.
                </p>

                <div class="actions">
                    <button type="button" id="jsonExportBtn">JSON exportieren</button>
                </div>
            </div>

            <div class="sync-box danger-zone">
                <h3>JSON sicher importieren</h3>
                <p class="muted">
                    Der Import arbeitet standardmäßig als Merge: vorhandene Datensätze werden nicht
                    einfach gelöscht. Vorher wird automatisch ein Zeitstempel-Backup angelegt.
                </p>

                <label>
                    JSON-Datei auswählen
                    <input type="file" id="jsonImportFile" accept="application/json,.json">
                </label>

                <div class="actions">
                    <button type="button" id="jsonImportBtn">JSON importieren</button>
                </div>

                <pre id="jsonImportResult" class="sync-result" hidden></pre>
            </div>

            <?php if ($user['role'] === 'admin'): ?>
                <div class="sync-box mysql-restore-zone">
                    <h3>JSON nach MySQL wiederherstellen</h3>
                    <p class="muted">
                        Wenn im JSON-Offline-Fallback weitergearbeitet wurde und MySQL später
                        wieder erreichbar ist, kann der Admin den JSON-Stand kontrolliert zurück
                        in MySQL übernehmen. Vor dem Schreiben wird eine Vorschau mit neuen,
                        aktualisierten und konfliktbehafteten Datensätzen erstellt.
                    </p>

                    <div class="actions">
                        <button type="button" id="jsonMysqlPreviewBtn">Vorschau erstellen</button>
                    </div>

                    <label class="checkline">
                        <input type="checkbox" id="jsonMysqlOverwriteConflicts">
                        Konflikte mit JSON überschreiben statt MySQL zu behalten
                    </label>

                    <p class="muted">
                        Ohne Haken bleiben Konflikte sicherheitshalber in MySQL erhalten.
                        Neue JSON-Daten und eindeutig neuere JSON-Datensätze werden trotzdem übernommen.
                    </p>

                    <div class="actions">
                        <button type="button" id="jsonMysqlRestoreBtn" disabled>JSON nach MySQL schreiben</button>
                    </div>

                    <pre id="jsonMysqlRestoreResult" class="sync-result" hidden></pre>
                </div>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>

<!-- -----------------------------------------------------------------------
     Report-Modal
     -------------------------------------------------------------------- -->
<div class="modal" id="reportModal">
    <section class="modal-card wide">
        <button type="button" class="x" data-close>×</button>

        <h2>Reports</h2>

        <div id="reports"></div>
    </section>
</div>

<!-- -----------------------------------------------------------------------
     Übergabe des eingeloggten Benutzers an JavaScript
     -------------------------------------------------------------------- -->
<script>
    window.APP_USER = <?= json_encode(
        [
            'id'       => $user['id'],
            'role'     => $user['role'],
            'username' => $user['username'],
        ],
        JSON_UNESCAPED_UNICODE
    ) ?>;
    window.APP_STORAGE_MODE_LABEL = <?= json_encode(storage_mode_label(), JSON_UNESCAPED_UNICODE) ?>;
    window.APP_VERSION_LABEL = 'Weiterentwicklung v1.8.4';
    window.APP_LAST_UPDATE = '24.06.2026';
    window.APP_VERSION_NOTE = 'Projekt-/Boardverwaltung, Projektverantwortliche, JSON-Fallback, JSON-nach-MySQL-Wiederherstellung, Auto-Sortierung, scrollbare Spalten, UI-State-Fix und erweiterte Zeiterfassungs-/Abrechnungsreports.';
</script>

<script src="assets/js/app.js"></script>

</body>
</html>
