<?php
/**
 * Logout-Endpunkt.
 *
 * Beendet die Session vollständig und leitet zurück zur Login-Seite.
 */

session_start();

// Sessiondaten leeren und Session-Cookie/serverseitige Session entfernen.
$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
