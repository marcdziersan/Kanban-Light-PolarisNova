# PolarisNova

**PolarisNova** ist eine webbasierte Projekt- und Aufgabenverwaltung mit Kanban-Board, Kundenverwaltung und API-basierter Datenhaltung. Das Projekt wurde bewusst schlank, nachvollziehbar und ohne Framework-Overhead aufgebaut. Es nutzt PHP, MySQL/MariaDB, HTML, CSS und JavaScript/AJAX.

> **Hinweis zur Nutzung:** Dieses Repository wird zu Portfolio-, Dokumentations- und Präsentationszwecken bereitgestellt. PolarisNova ist **kein Open-Source-Projekt**. Nutzung, Veränderung, Weitergabe, Veröffentlichung, Hosting, kommerzielle Verwendung oder Ableitung eigener Projekte ist ohne vorherige schriftliche Zustimmung des Rechteinhabers nicht erlaubt. Details stehen in [`LICENSE.md`](./LICENSE.md).

---

## Inhaltsverzeichnis

1. [Projektüberblick](#projektüberblick)
2. [Funktionsumfang](#funktionsumfang)
3. [Technischer Stack](#technischer-stack)
4. [Projektstruktur](#projektstruktur)
5. [Installation](#installation)
6. [Konfiguration](#konfiguration)
7. [Setup ausführen](#setup-ausführen)
8. [Demo-Zugangsdaten](#demo-zugangsdaten)
9. [Bedienung](#bedienung)
10. [API- und OOP-Struktur](#api--und-oop-struktur)
11. [Datenhaltung](#datenhaltung)
12. [Sicherheitshinweise](#sicherheitshinweise)
13. [Weitergabe und GitHub-Hinweis](#weitergabe-und-github-hinweis)
14. [Lizenz](#lizenz)

---

## Projektüberblick

PolarisNova verbindet ein Kanban-System mit einer eigenständigen Kundenverwaltung. Projekte, Boards und Aufgaben können organisiert, bearbeitet und über eine API gespeichert werden. Die Kundenverwaltung ist mit dem Kanban-Bereich verknüpft, bleibt aber fachlich ein eigener Bereich.

Das Projekt eignet sich als:

- Portfolio-Projekt für Webentwicklung mit PHP und JavaScript
- Demonstration einer einfachen OOP/API-Struktur ohne Framework
- Beispiel für eine schlanke Projektverwaltung
- technische Grundlage für spätere Erweiterungen

Ziel war eine verständliche, wartbare und weitergabefähige Anwendung, die lokal oder auf einem klassischen PHP-Webhosting betrieben werden kann.

---

## Funktionsumfang

### Kanban-Modul

- Projekt- und Boardverwaltung
- Aufgabenverwaltung über Kanban-Spalten
- Aufgaben erstellen, bearbeiten, verschieben und löschen
- Kommentare zu Aufgaben
- Zeiterfassung an Aufgaben
- Sperr-/Lock-Funktionen für Projektbereiche
- JSON-Export und JSON-Import
- Restore-Vorschau und Wiederherstellung
- Verknüpfung von Kanban-Projekten mit der Kundenansicht

### Kundenverwaltung

- Kunden anlegen, bearbeiten und löschen
- Kundenstatus verwalten
- Kundendaten speichern
- Kunden mit Projekten verbinden
- Projektbezug aus der Kundenansicht öffnen
- Entkopplung bei gelöschten Projekten
- FK-sicheres Löschen von Kunden

### Benutzer und Rollen

- Login-System
- Demo-Admin
- Demo-Nutzer
- rollenabhängige Bedienung
- Nutzerverwaltung für administrative Bereiche

### Weitergabe-Version

Die Weitergabe-Version enthält keine echten Kunden-, Projekt-, Board-, Aufgaben- oder Verlaufsdaten. Enthalten sind nur:

- Tabellenstruktur
- Primärschlüssel
- Fremdschlüssel
- Indizes
- Demo-Benutzer
- leere JSON-Fallback-Dateien
- eine zentrale SQL-Datei: `polarisnova.sql`

---

## Technischer Stack

| Bereich | Technologie |
|---|---|
| Backend | PHP 8.x |
| Datenbank | MySQL / MariaDB |
| Frontend | HTML5, CSS3, JavaScript |
| Kommunikation | AJAX / JSON |
| Architektur | OOP-Struktur mit Controller-, Core- und Storage-Klassen |
| Persistenz | MySQL/MariaDB mit JSON-Fallback-Dateien |
| Setup | `setup_status.php` + `polarisnova.sql` |

Das Projekt nutzt bewusst keine großen Frameworks wie Laravel, Symfony, React, Vue oder Angular.

---

## Projektstruktur

```txt
polarisnova/
├── api/
│   ├── api.php
│   └── customers.php
│
├── app/
│   ├── Controller/
│   │   ├── CustomerApiController.php
│   │   └── KanbanApiController.php
│   │
│   ├── Core/
│   │   ├── ArrayHelper.php
│   │   ├── Clock.php
│   │   ├── JsonResponse.php
│   │   └── Request.php
│   │
│   ├── Storage/
│   │   ├── CustomerStorageAdapter.php
│   │   └── KanbanStorageAdapter.php
│   │
│   ├── .htaccess
│   └── bootstrap.php
│
├── assets/
│   ├── css/
│   │   ├── customers.css
│   │   └── style.css
│   │
│   └── js/
│       ├── app.js
│       └── customers.js
│
├── data/
│   ├── customers.json
│   ├── data.backup.json
│   └── data.json
│
├── docs/
│   └── api-oop-struktur.md
│
├── config.php
├── customers.php
├── icon.png
├── index.php
├── lib/
│   ├── customer_storage.php
│   └── storage.php
│
├── login.php
├── logout.php
├── polarisnova.sql
├── setup_status.php
├── README.md
└── LICENSE.md
```

---

## Installation

### Voraussetzungen

Benötigt wird ein Webserver mit PHP und MySQL/MariaDB.

Geeignete Umgebungen sind zum Beispiel:

- XAMPP
- Laragon
- MAMP
- klassisches PHP-Webhosting
- lokaler Apache/Nginx mit PHP-FPM

Empfohlen:

- PHP 8.x
- MySQL 8.x oder MariaDB 10.x
- aktivierte PHP-PDO-Erweiterung für MySQL
- Schreibrechte im Projektverzeichnis für `data/`

---

## Konfiguration

Nach dem Entpacken muss die Datei `config.php` angepasst werden.

Beispiel für XAMPP:

```php
$dbHost = 'localhost';
$dbName = 'polarisnova';
$dbUser = 'root';
$dbPass = '';
```

Beispiel für Webhosting:

```php
$dbHost = 'localhost';
$dbName = 'web123_polarisnova';
$dbUser = 'web123_user';
$dbPass = 'DEIN_DATENBANK_PASSWORT';
```

Die Datenbank muss vorhanden sein. Tabellen und Basisdaten werden über `setup_status.php` eingerichtet.

---

## Setup ausführen

1. ZIP-Datei in das Webroot-Verzeichnis legen.
2. ZIP-Datei entpacken.
3. `config.php` bearbeiten.
4. Datenbank anlegen, zum Beispiel `polarisnova`.
5. Setup im Browser starten:

```txt
http://localhost/polarisnova/setup_status.php
```

6. Nach erfolgreichem Setup Anwendung öffnen:

```txt
http://localhost/polarisnova/index.php
```

Bei einem Webhosting entsprechend:

```txt
https://deine-domain.de/polarisnova/setup_status.php
https://deine-domain.de/polarisnova/index.php
```

---

## Demo-Zugangsdaten

Die Demo-Zugangsdaten werden zusätzlich in der Loginmaske angezeigt.

```txt
Admin
Benutzername: admin
Passwort: admin123
```

```txt
Nutzer
Benutzername: demo
Passwort: demo123
```

Für eine produktive oder öffentlich erreichbare Installation müssen diese Zugangsdaten geändert werden.

---

## Bedienung

### Login

Der Einstieg erfolgt über `index.php`. Nicht angemeldete Benutzer werden zur Loginmaske geführt.

### Kanban

Nach dem Login kann ein Projekt beziehungsweise Board erstellt und geöffnet werden. Aufgaben werden innerhalb des Boards angelegt, bearbeitet, verschoben und kommentiert.

### Kundenverwaltung

Die Kundenverwaltung ist über `customers.php` erreichbar. Kunden können angelegt, bearbeitet und mit Projekten verbunden werden.

### Verknüpfung Kanban / Kunden

- Aus dem Kanban-Bereich kann die Kundenverwaltung geöffnet werden.
- Aus der Kundenansicht kann ein zugeordnetes Board direkt geöffnet werden.
- Wird ein Projekt gelöscht, werden Kunden nicht gelöscht, sondern nur von diesem Projekt entkoppelt.
- Wird ein Kunde gelöscht, bleiben technische Events konsistent und verweisen nicht mehr auf ungültige Kunden-IDs.

---

## API- und OOP-Struktur

Die API-Einstiegspunkte sind bewusst schlank gehalten:

```txt
api/api.php
api/customers.php
```

Die eigentliche Logik liegt in der OOP-Struktur unter `app/`.

### Controller

```txt
app/Controller/KanbanApiController.php
app/Controller/CustomerApiController.php
```

Die Controller nehmen Requests entgegen, prüfen Aktionen und delegieren an Storage- und Hilfsklassen.

### Core

```txt
app/Core/Request.php
app/Core/JsonResponse.php
app/Core/Clock.php
app/Core/ArrayHelper.php
```

Diese Klassen kapseln wiederkehrende Basislogik wie Request-Zugriff, JSON-Ausgaben, Zeitfunktionen und Array-Hilfen.

### Storage

```txt
app/Storage/KanbanStorageAdapter.php
app/Storage/CustomerStorageAdapter.php
```

Die Storage-Adapter verbinden die neue OOP-Struktur mit der vorhandenen Datenhaltung.

---

## Datenhaltung

PolarisNova nutzt MySQL/MariaDB als primäre Datenhaltung. Zusätzlich existieren JSON-Dateien als Fallback beziehungsweise Export-/Backup-Grundlage.

Wichtige Dateien:

```txt
polarisnova.sql
config.php
lib/storage.php
lib/customer_storage.php
data/data.json
data/customers.json
data/data.backup.json
```

Die zentrale SQL-Datei `polarisnova.sql` enthält:

- Tabellenstruktur
- Primärschlüssel
- Fremdschlüssel
- Indizes
- Demo-Benutzer

Sie enthält keine echten Kunden-, Projekt-, Board- oder Aufgabendaten.

---

## Sicherheitshinweise

Vor einem öffentlichen Upload oder einer produktiven Nutzung sollten folgende Punkte geprüft werden:

- keine echten Datenbankpasswörter in `config.php` committen
- Demo-Passwörter ändern
- `setup_status.php` nach erfolgreicher Installation löschen, umbenennen oder schützen
- Schreibrechte auf `data/` nur so weit wie nötig vergeben
- Webserver so konfigurieren, dass sensible Dateien nicht direkt ausgeliefert werden
- Backups nicht öffentlich im Repository ablegen
- keine personenbezogenen Testdaten veröffentlichen

Empfehlung für GitHub:

- nur lokale Beispielkonfiguration veröffentlichen
- echte Zugangsdaten niemals committen
- bei Bedarf eine zusätzliche `config.example.php` pflegen
- Repository nicht als Open Source deklarieren, wenn keine freie Nutzung gewünscht ist

---

## Weitergabe und GitHub-Hinweis

Dieses Projekt kann technisch auf GitHub abgelegt werden, ist dadurch aber nicht automatisch frei nutzbar. Das Repository dient der Ansicht, Dokumentation und Präsentation.

Wichtig:

- Keine Nutzung ohne schriftliche Zustimmung
- Keine Änderung ohne schriftliche Zustimmung
- Keine Weitergabe ohne schriftliche Zustimmung
- Keine kommerzielle Nutzung ohne schriftliche Zustimmung
- Kein Hosting eigener Kopien ohne schriftliche Zustimmung
- Kein Einbau in fremde Projekte ohne schriftliche Zustimmung

Pull Requests, Forks, Issues oder Codevorschläge begründen keine Nutzungserlaubnis am Projekt.

---

### Bilder

<img src="Screenshot 2026-07-01 164259.png">
<img src="Screenshot 2026-07-01 164412.png">
<img src="Screenshot 2026-07-01 164428.png">
---

## Lizenz

PolarisNova steht unter einer proprietären Lizenz mit vollständigem Rechtevorbehalt.

Siehe:

```txt
LICENSE.md
```

Kurzfassung:

```txt
Copyright (c) 2026 Marcus Dziersan.
Alle Rechte vorbehalten.
Keine Nutzung, Änderung, Weitergabe oder Veröffentlichung ohne vorherige schriftliche Zustimmung.
```

---

## Autor

**Marcus Dziersan**

Projekt: **PolarisNova**

---

## Status

Weitergabe-Version mit leerer Datenbasis, Demo-Benutzern und vollständiger Setup-Struktur.
