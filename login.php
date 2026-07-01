<?php
/**
 * Login-Seite für Kanban Light PolarisNova.
 *
 * Aufgabe der Datei:
 * - Benutzername und Passwort entgegennehmen.
 * - Benutzer gegen gespeicherten Passwort-Hash prüfen.
 * - Alte Demo-Kennwörter mit Prefix "plain:" automatisch in BCrypt migrieren.
 * - Gesperrte Benutzer blockieren.
 * - Erfolgreiche Logins in die Session schreiben.
 */

session_start();

require_once __DIR__ . '/lib/storage.php';

// -----------------------------------------------------------------------------
// Initiale Anzeigezustände
// -----------------------------------------------------------------------------

$error = '';
$blocked = false;

// -----------------------------------------------------------------------------
// Login-Verarbeitung
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = storage_load();
    $name = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    foreach ($d['users'] as $i => $u) {
        // Nur der Benutzer mit passendem Benutzernamen wird geprüft.
        if (($u['username'] ?? '') !== $name) {
            continue;
        }

        $hash = $u['password_hash'] ?? '';

        // Unterstützt alte Demo-Passwörter mit "plain:" und moderne Passwort-Hashes.
        $ok = str_starts_with($hash, 'plain:')
            ? hash_equals(substr($hash, 6), $pass)
            : password_verify($pass, $hash);

        if ($ok) {
            // Gesperrte Benutzer dürfen sich nicht anmelden.
            if (isset($u['is_active']) && !$u['is_active']) {
                $blocked = true;
                break;
            }

            // Plaintext-Demo-Passwörter werden nach erfolgreichem Login direkt ersetzt.
            if (str_starts_with($hash, 'plain:')) {
                $d['users'][$i]['password_hash'] = password_hash($pass, PASSWORD_BCRYPT);
                storage_save($d);
            }

            // Neue Session-ID verhindert Session-Fixation nach erfolgreichem Login.
            session_regenerate_id(true);
            $_SESSION['user_id'] = $u['id'];

            header('Location:index.php');
            exit;
        }
    }

    if (!$blocked) {
        $error = 'Login fehlgeschlagen.';
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">

<form class="login-card" method="post">
    <h1>Kanban Light</h1>
    <p>PolarisNova · MySQL/PDO + JSON Backup</p>

    <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <label>
        Benutzername
        <input name="username" required autofocus>
    </label>

    <label>
        Passwort
        <input name="password" type="password" required>
    </label>

    <button>Einloggen</button>

    <div class="demo-login-box" aria-label="Demo-Zugangsdaten">
        <strong>Demo-Zugangsdaten</strong>
        <p>Admin: <code>admin</code> / <code>admin123</code></p>
        <p>Nutzer: <code>demo</code> / <code>demo123</code></p>
    </div>
</form>

<?php if (!empty($blocked)): ?>
    <div class="login-modal-backdrop">
        <div class="login-modal">
            <h2>Zugang gesperrt</h2>
            <p>Dieser Benutzer wurde durch einen Administrator gesperrt.</p>
            <p>Bitte wenden Sie sich an den Admin, um den Zugang wieder freischalten zu lassen.</p>
            <button type="button" onclick="document.querySelector('.login-modal-backdrop').remove()">Verstanden</button>
        </div>
    </div>
<?php endif; ?>

</body>
</html>
