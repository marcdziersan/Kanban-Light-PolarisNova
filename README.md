# PolarisNova

**PolarisNova** ist eine selbst hostbare Projekt-, Kunden-, Aufgaben-, Zeit-, Rechnungs-, EÜR-, Nachrichten- und Ticketverwaltung für Einzelpersonen, Selbstständige und kleine Teams.

Die Anwendung wurde bewusst schlank, nachvollziehbar und ohne Framework-Overhead aufgebaut. Sie nutzt **PHP**, **MySQL/MariaDB**, **HTML5**, **CSS3** und **JavaScript/AJAX**. Ziel ist ein System, das auf normalem PHP-Webhosting betrieben werden kann, ohne Cloud-Zwang, ohne externe Plattformbindung und ohne überladene Enterprise-Suite.

> **Hinweis zur Nutzung:** Dieses Repository wird zu Portfolio-, Dokumentations- und Präsentationszwecken bereitgestellt. PolarisNova ist **kein Open-Source-Projekt**. Nutzung, Veränderung, Weitergabe, Veröffentlichung, Hosting, kommerzielle Verwendung oder Ableitung eigener Projekte ist ohne vorherige schriftliche Zustimmung des Rechteinhabers nicht erlaubt. Details stehen in [`LICENSE.md`](./LICENSE.md).

---

## Inhaltsverzeichnis

1. [Projektüberblick](#projektüberblick)
2. [Zielgruppe](#zielgruppe)
3. [Funktionsumfang](#funktionsumfang)
4. [Technischer Stack](#technischer-stack)
5. [Projektstruktur](#projektstruktur)
6. [Installation](#installation)
7. [Konfiguration](#konfiguration)
8. [Setup ausführen](#setup-ausführen)
9. [Demo-Zugangsdaten](#demo-zugangsdaten)
10. [Bedienung](#bedienung)
11. [Module im Detail](#module-im-detail)
12. [API- und OOP-Struktur](#api--und-oop-struktur)
13. [Datenhaltung](#datenhaltung)
14. [Datenbank und Tabellen](#datenbank-und-tabellen)
15. [Rollen und Rechte](#rollen-und-rechte)
16. [Export, Druck und Auswertungen](#export-druck-und-auswertungen)
17. [Sicherheitshinweise](#sicherheitshinweise)
18. [Weitergabe und GitHub-Hinweis](#weitergabe-und-github-hinweis)
19. [Roadmap](#roadmap)
20. [Lizenz](#lizenz)
21. [Autor](#autor)
22. [Status](#status)

---

## Projektüberblick

PolarisNova verbindet mehrere Arbeitsbereiche in einer kompakten Anwendung:

- Kanban-Board
- Projektverwaltung
- Aufgabenverwaltung
- Kundenverwaltung
- Zeiterfassung
- Monatszettel je Mitarbeiter
- Rechnungsmodul
- vorbereitende EÜR-Auswertung
- internes PM-/Nachrichtensystem
- Admin-Pinnwand
- internes Ticketsystem
- JSON-Export, Import und Restore
- MySQL-Datenhaltung mit JSON-Fallback

Das Projekt ist nicht als Jira-, Asana- oder Trello-Klon gedacht, sondern als schlanke Arbeitszentrale für kleine Teams und Selbstständige, die ihre Daten selbst hosten möchten.

Der Grundsatz lautet:

```txt
Eigener Webspace.
Eigene Daten.
Keine Cloudpflicht.
Keine Anbieterbindung.
Keine überladene Konzernsoftware.
```

---

## Zielgruppe

PolarisNova eignet sich besonders für:

- Einzelpersonen mit mehreren Kundenprojekten
- Selbstständige
- Freelancer
- kleine Web- und IT-Dienstleister
- kleine Agenturen
- kleine Handwerks- und Dienstleistungsbetriebe
- Vereine
- interne Projektgruppen
- Ausbildungs- und Lernprojekte
- kleine Teams, die Kunden, Aufgaben und Zeiten zusammen verwalten möchten

PolarisNova ist bewusst **nicht** als Enterprise-System für Konzerne, Behörden oder hochregulierte Branchen ausgelegt.

---

## Funktionsumfang

### Übersicht

PolarisNova enthält aktuell folgende Hauptmodule:

| Modul | Zweck |
|---|---|
| Login & Benutzer | Anmeldung, Rollen, Benutzerverwaltung |
| Projekte & Boards | Projektverwaltung mit Kanban-Boards |
| Aufgaben | Aufgaben, Prioritäten, Status, Kommentare |
| Zeiterfassung | Start/Stop-Zeiten an Aufgaben |
| Monatszettel | Monatsauswertung je Mitarbeiter |
| Kundenverwaltung | Kunden, Kontakte, Status und Projektbezüge |
| Rechnungen | Rechnungen aus Kunden-, Projekt-, Aufgaben- und Zeitdaten |
| EÜR | vorbereitende Einnahmen-/Überschuss-Auswertung |
| PM-System | private Nachrichten, Projektchat, Gesamtchat |
| Admin-Pinnwand | zentrale Mitteilungen durch Admin |
| Ticketsystem | Fehler-/Aufgabenmeldungen als internes Admin-Kanban |
| JSON Sync | Export, Import, Restore-Vorschau |
| Setup | SQL-Struktur, Demo-Benutzer, Installationsprüfung |

---

## Technischer Stack

| Bereich | Technologie |
|---|---|
| Backend | PHP 8.x |
| Datenbank | MySQL / MariaDB |
| Frontend | HTML5, CSS3, JavaScript |
| Kommunikation | AJAX / JSON |
| Architektur | OOP-Struktur mit Controller-, Core-, Domain- und Storage-Klassen |
| Persistenz | MySQL/MariaDB mit JSON-Fallback-Dateien |
| Setup | `setup_status.php` + `polarisnova.sql` |
| Export | JSON, CSV, Browserdruck/PDF |
| Frameworks | keine großen Frameworks |

Das Projekt nutzt bewusst keine großen Frameworks wie Laravel, Symfony, React, Vue oder Angular.

---

## Projektstruktur

Die konkrete Struktur kann je nach Version leicht erweitert sein. Der aktuelle Stand enthält unter anderem:

```txt
polarisnova/
├── api/
│   ├── api.php
│   ├── customers.php
│   ├── accounting.php
│   ├── pm.php
│   └── tickets.php
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
│   ├── Domain/
│   │   ├── Kanban/
│   │   └── Customer/
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
│   │   ├── style.css
│   │   ├── customers.css
│   │   ├── accounting.css
│   │   ├── pm.css
│   │   └── tickets.css
│   │
│   └── js/
│       ├── app.js
│       ├── customers.js
│       ├── accounting.js
│       ├── pm.js
│       └── tickets.js
│
├── data/
│   ├── customers.json
│   ├── data.backup.json
│   └── data.json
│
├── docs/
│   └── api-oop-struktur.md
│
├── lib/
│   ├── customer_storage.php
│   └── storage.php
│
├── accounting.php
├── config.php
├── customers.php
├── icon.png
├── index.php
├── login.php
├── logout.php
├── pm.php
├── tickets.php
├── polarisnova.sql
├── setup_status.php
├── README.md
├── LICENSE.md
└── license.md
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
- aktivierte Sessions in PHP
- Zugriff auf phpMyAdmin oder eine vergleichbare Datenbankverwaltung

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

Bei Webhosting entsprechend:

```txt
https://deine-domain.de/polarisnova/setup_status.php
https://deine-domain.de/polarisnova/index.php
```

Nach erfolgreicher Installation sollte `setup_status.php` gelöscht, umbenannt oder geschützt werden.

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

### Hauptbereiche

Nach dem Login stehen je nach Rolle verschiedene Bereiche zur Verfügung:

- Projekte / Kanban
- Kunden
- Monatszettel
- Rechnungen / EÜR
- Nachrichten
- Tickets
- Reports
- JSON Sync
- Benutzerverwaltung

### Kanban

Nach dem Login kann ein Projekt beziehungsweise Board erstellt und geöffnet werden. Aufgaben werden innerhalb des Boards angelegt, bearbeitet, verschoben und kommentiert.

### Kundenverwaltung

Die Kundenverwaltung ist über `customers.php` erreichbar. Kunden können angelegt, bearbeitet und mit Projekten verbunden werden.

### Rechnungen und EÜR

Das Rechnungs- und EÜR-Modul ist über `accounting.php` erreichbar.

### Nachrichten

Das PM-System ist über `pm.php` erreichbar.

### Tickets

Das interne Ticketsystem ist über `tickets.php` erreichbar.

---

## Module im Detail

## 1. Login und Benutzerverwaltung

Funktionen:

- Login über `login.php`
- Logout über `logout.php`
- Session-basierter Zugriffsschutz
- Demo-Zugangsdaten in der Loginmaske
- Admin-, Mitarbeiter- und Gastrollen
- Benutzer anlegen
- Benutzer bearbeiten
- Benutzer löschen
- Benutzer sperren / entsperren
- Benutzername verwalten
- E-Mail verwalten
- Passwort setzen / ändern
- Projektzuordnung von Mitarbeitern
- unterschiedliche Sichtbarkeit je nach Rolle

---

## 2. Projekt- und Boardverwaltung

Funktionen:

- Projekt anlegen
- Projekt bearbeiten
- Projekt löschen
- Boardname festlegen
- Projektbeschreibung pflegen
- Projektverantwortlichen auswählen
- Mitarbeiter einem Projekt zuordnen
- Projekt direkt aus Kundenverwaltung öffnen
- Kundenverwaltung direkt aus Projektkarte öffnen
- Projektkarten mit Projektinformationen
- Such- und Filterfunktionen
- Admins sehen alle Projekte
- Mitarbeiter sehen zugewiesene Projekte

Beim Löschen eines Projekts werden sauber entfernt:

- Projekt
- Board
- Spalten
- Aufgaben
- Kommentare
- Zeiteinträge
- Events zu Aufgaben
- Historie zu Aufgaben
- Projektmitglieder-Zuordnungen

Kunden-Projekt-Verknüpfungen werden gelöst. Kunden selbst bleiben erhalten.

---

## 3. Kanban-Board

Funktionen:

- Board je Projekt öffnen
- Aufgaben nach Spalten/Status verwalten
- Aufgaben erstellen
- Aufgaben bearbeiten
- Aufgaben löschen
- Aufgaben verschieben
- Filter nach Priorität
- Filter nach zugewiesenem Benutzer
- Suchfunktion innerhalb des Boards
- kompakte Darstellung
- direkter Rücksprung zur Projektübersicht

---

## 4. Aufgabenverwaltung

Funktionen:

- Titel erfassen
- Beschreibung erfassen
- Priorität setzen
- Aufgabe einem Benutzer zuweisen
- Fälligkeitsdatum setzen
- Aufgabe einer Spalte zuordnen
- Kommentare erfassen
- Zeit starten und stoppen
- Änderungsverlauf anzeigen
- Aufgabe sperren / freigeben
- Aufgabe löschen

Prioritäten:

- Niedrig
- Mittel
- Hoch
- Kritisch

Beim Löschen einer Aufgabe werden sauber entfernt:

- Kommentare der Aufgabe
- Zeiteinträge der Aufgabe
- Events der Aufgabe
- Historie der Aufgabe
- danach die Aufgabe selbst

Das Lösch-Event bleibt erhalten, verweist aber nicht mehr auf eine gelöschte Aufgaben-ID.

---

## 5. Kommentare und Historie

Funktionen:

- Kommentare pro Aufgabe anzeigen
- Kommentare pro Aufgabe schreiben
- kommentierenden Benutzer speichern
- Änderungsverlauf pro Aufgabe
- Protokollierung wichtiger Aktionen
- automatische Historieneinträge

Typische Historien-/Event-Aktionen:

- Aufgabe erstellt
- Aufgabe geändert
- Aufgabe verschoben
- Aufgabe gelöscht
- Kommentar hinzugefügt
- Zeit gestartet
- Zeit gestoppt
- Projekt geändert
- Benutzeraktion

---

## 6. Zeiterfassung

Funktionen:

- Zeit an Aufgabe starten
- Zeit an Aufgabe stoppen
- laufende Zeitbuchungen anzeigen
- abgeschlossene Zeitbuchungen anzeigen
- Startzeit speichern
- Endzeit speichern
- Dauer berechnen
- Zuordnung zu Benutzer und Aufgabe
- Nutzung in Reports, Monatszetteln und Rechnungen

---

## 7. Monatszettel je Mitarbeiter

Funktionen:

- eigener Menüpunkt `Monatszettel`
- Monatsauswahl
- Mitarbeiterauswahl für Admins
- normale Nutzer sehen nur den eigenen Monatszettel
- einzelne Zeitbuchungen je Mitarbeiter
- Monatsgesamtzeit
- Zusammenfassung nach Tag
- Zusammenfassung nach Projekt
- Zusammenfassung nach Aufgabe
- CSV-Export
- Druckansicht
- PDF-Erzeugung über Browserdruck

Der Monatszettel zeigt je Zeitbuchung:

- Datum
- Startzeit
- Endzeit
- Dauer
- Projekt
- Board
- Aufgabe
- Aufgaben-ID

Die Zeiten werden über folgende Kette aufgelöst:

```txt
time_entries → tasks → columns → boards → projects
```

API-Action:

```txt
monthly_timesheet
```

---

## 8. Kundenverwaltung

Funktionen:

- Kundenübersicht
- Kunde anlegen
- Kunde bearbeiten
- Kunde löschen
- Kundensuche
- Filter nach Kundenstatus
- Filter nach zugeordnetem Projekt
- Kundenstatistik
- Projektstände aus PolarisNova anzeigen
- direkte Links ins Kanban
- direkte Verknüpfung von Kunden mit Projekten
- JSON-Fallback für Kundendaten
- FK-sicheres Löschen von Kunden

Kundenfelder:

- Firma / Kunde
- Kontaktperson
- E-Mail
- Telefon
- Website
- Ort
- Typ
- Status
- Adresse
- Quelle / Herkunft
- zugeordnete PolarisNova-Projekte
- Notizen

Kundentypen:

- Kunde
- Interessent
- Partner
- Intern

Kundenstatus:

- Lead
- Aktiv
- Pausiert
- Archiviert

---

## 9. Verbindung Kundenverwaltung und Kanban

Funktionen:

- Topbar-Link von Kanban zu Kunden
- Link von Kundenverwaltung zurück zum Kanban
- Projektkarten haben Link zur Kundenansicht
- Kunden können PolarisNova-Projekten zugeordnet werden
- Kunden-Projektboxen haben Button `Board öffnen`
- Projektstände in der Kundenverwaltung haben Link `Board im Kanban öffnen`

Aufruf mit Projektbezug:

```txt
customers.php?project_id=...
```

Board-Aufruf mit Board-ID:

```txt
index.php?board_id=...
```

---

## 10. Rechnungsmodul

Funktionen:

- eigene Seite `accounting.php`
- eigene API `api/accounting.php`
- Rechnungen aus vorhandenen Zeitbuchungen erzeugen
- Rechnung nach Kunde, Projekt, Aufgabe, Mitarbeiter und Zeit aufschlüsseln
- Rechnungspositionen mit Snapshots speichern
- Rechnung auch nach späterer Projekt-/Aufgabenänderung nachvollziehbar halten
- Statusverwaltung für Rechnungen
- Druckansicht / PDF über Browserdruck

Rechnungspositionen enthalten:

- Datum
- Mitarbeiter
- Projekt
- Aufgabe
- gebuchte Zeit
- Stundensatz
- Netto-Betrag
- Umsatzsteuer
- Brutto-Betrag

Rechnungsstatus:

- Entwurf
- Gesendet
- Bezahlt
- Storniert

---

## 11. EÜR-Modul

Funktionen:

- vorbereitende interne EÜR-Auswertung
- manuelle Einnahmen und Ausgaben erfassen
- bezahlte Rechnungen als Einnahmen berücksichtigen
- Auswertung für Chef / Gesamt
- Auswertung je Mitarbeiter
- Einnahmen netto
- Ausgaben netto
- Überschuss netto
- Umsatzsteuer aus Einnahmen
- Vorsteuer aus Ausgaben
- Umsatzsteuer-Saldo
- CSV-Export
- Druckansicht / PDF über Browserdruck

Hinweis:

> Das EÜR-Modul ist eine vorbereitende interne Auswertung. Es ersetzt keine Steuerberatung, keine amtliche Steuererklärung und keinen ELSTER-Prozess.

---

## 12. PM- und Nachrichtensystem

Funktionen:

- eigene Seite `pm.php`
- eigene API `api/pm.php`
- Topbar-Link `Nachrichten`
- private Nachrichten zwischen Mitarbeitern
- projektinterner Gruppenchat
- Gesamt-/Unternehmenschat
- Admin-Pinnwand
- Lesestatus für Nachrichten
- Lesestatus für Pinnwand
- Suche nach Text, Betreff, Benutzer und Projekt
- Filter nach Projekt
- Filter nach gelesen / ungelesen
- Drucken über Browserdruck

Nachrichtentypen:

- private Nachricht
- Projektchat
- Gesamtchat

Admin-Pinnwand:

- Admin kann Pinnwand-Einträge erstellen
- Admin kann Pinnwand-Einträge bearbeiten
- Admin kann Pinnwand-Einträge löschen
- Mitarbeiter können Pinnwand-Einträge lesen
- Lesestatus wird gespeichert

---

## 13. Ticketsystem

Funktionen:

- eigene Seite `tickets.php`
- eigene API `api/tickets.php`
- Topbar-Link `Tickets`
- Mitarbeiter/Nutzer können Tickets erstellen
- Auswahl des betroffenen Bereichs
- optionaler Projektbezug
- optionaler Aufgabenbezug
- Admin-internes Ticketboard im Kanban-Stil
- nur Admins sehen das vollständige Ticketboard
- Ticketersteller sehen eigene Tickets
- Admins können Tickets annehmen, bearbeiten, priorisieren, zuweisen und abschließen
- Admin-Rückmeldungen können automatisch als private PM-Nachricht an den Ticketersteller gesendet werden
- Ticketersteller können erledigte Tickets bestätigen
- Admin-interne Kommentare sind möglich
- Gäste haben keine Ticket-Schreibrechte

Ticketbereiche:

- Kanban / Aufgaben
- Kundenverwaltung
- Rechnungen / EÜR
- PM-System / Nachrichten
- Monatszettel / Zeiten
- Setup / Installation
- Benutzer / Rechte
- Oberfläche / Bedienung
- Sonstiges

Ticketfelder:

- Titel
- Priorität
- Bereich
- Projektbezug
- Aufgabenbezug
- Was sollte passieren?
- Was ist passiert?
- Schritte zur Reproduktion / Kontext

Ticketstatus:

- Neu
- Angenommen
- In Bearbeitung
- Rückfrage
- Erledigt
- Bestätigt
- Abgelehnt

---

## 14. Reports

Funktionen:

- Report-Modal im Board
- Auswertung vorhandener Projektdaten
- Auswertung von Aufgabenständen
- Auswertung von Zeitdaten
- Zusammenfassungen für Projekt- und Arbeitsstände
- Reportansicht über API
- nur sichtbar, wenn ein Board geöffnet ist

API-Action:

```txt
reports
```

---

## 15. JSON Export, Import und Restore

Funktionen:

- vollständigen JSON-Export erzeugen
- JSON-Datei importieren
- Import prüft Daten vor Übernahme
- automatische Backup-Erstellung vor Import
- Merge-Logik statt stumpfem Überschreiben
- Import im Offline-/Fallback-Kontext
- Admin kann JSON nach MySQL zurückspielen
- Vorschau vor Wiederherstellung
- Erkennung neuer, aktualisierter und konflikthafter Datensätze
- kontrollierte Rückführung aus JSON-Fallback nach MySQL

API-Actions:

```txt
json_export
json_import
json_mysql_restore_preview
json_mysql_restore_commit
```

---

## API- und OOP-Struktur

Die API-Einstiegspunkte sind bewusst schlank gehalten. Die eigentliche Logik liegt in der OOP-Struktur unter `app/`.

### Haupt-Endpunkte

```txt
api/api.php
api/customers.php
api/accounting.php
api/pm.php
api/tickets.php
```

### Controller

```txt
app/Controller/KanbanApiController.php
app/Controller/CustomerApiController.php
```

### Core

```txt
app/Core/Request.php
app/Core/JsonResponse.php
app/Core/Clock.php
app/Core/ArrayHelper.php
```

### Storage

```txt
app/Storage/KanbanStorageAdapter.php
app/Storage/CustomerStorageAdapter.php
```

### Kanban-API-Actions

```txt
bootstrap
tasks_only
save_task
delete_task
move_task
add_comment
start_time
stop_time
save_project
delete_project
toggle_lock
save_user
delete_user
toggle_user_lock
reports
monthly_timesheet
json_export
json_import
json_mysql_restore_preview
json_mysql_restore_commit
```

### Kunden-API-Actions

```txt
bootstrap
save_customer
delete_customer
```

### Rechnungen/EÜR-API

```txt
bootstrap
create_invoice
update_invoice_status
delete_invoice
save_eur_entry
delete_eur_entry
eur_report
csv_export
```

### PM-API

```txt
bootstrap
send_message
mark_read
save_pinboard
delete_pinboard
mark_pinboard_read
```

### Ticket-API

```txt
bootstrap
create_ticket
update_ticket
add_comment
confirm_ticket
delete_ticket
```

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
- Basisdaten

Die Clean-Distribution enthält keine echten Kunden-, Projekt-, Board-, Aufgaben-, Zeit-, Rechnungs-, Nachrichten- oder Ticketdaten.

---

## Datenbank und Tabellen

Die Anwendung nutzt unter anderem Tabellen für:

### Kernsystem

- Benutzer
- Projekte
- Projektmitglieder
- Boards
- Spalten
- Aufgaben
- Kommentare
- Zeiteinträge
- Events
- Historie

### Kundenverwaltung

- Kunden
- Kunden-Projekt-Verknüpfungen
- Kunden-Events

### Rechnungen und EÜR

- Rechnungen
- Rechnungspositionen
- EÜR-Einträge

### PM-System

- Nachrichten
- Nachrichten-Lesestatus
- Pinnwand-Einträge
- Pinnwand-Lesestatus

### Ticketsystem

- Support-Tickets
- Ticket-Kommentare

Wichtige Eigenschaften:

- Primärschlüssel vorhanden
- Fremdschlüssel vorhanden
- Indizes vorhanden
- FK-sicheres Löschen von Kunden, Aufgaben und Projekten
- Rechnungspositionen speichern Snapshots
- Tickets und Nachrichten sind benutzerbezogen
- Projektchats und Tickets können projektbezogen arbeiten

---

## Rollen und Rechte

### Admin

Admins können:

- alle Projekte sehen
- Projekte anlegen
- Projekte bearbeiten
- Projekte löschen
- Benutzer verwalten
- alle Monatszettel ansehen
- JSON nach MySQL wiederherstellen
- Kunden verwalten
- Rechnungen und EÜR verwalten
- Nachrichten und Pinnwand nutzen
- Pinnwand-Einträge erstellen, bearbeiten und löschen
- Ticketboard vollständig sehen
- Tickets annehmen, bearbeiten, priorisieren und abschließen

### Mitarbeiter / Nutzer

Mitarbeiter können je nach Projektzuordnung:

- zugewiesene Projekte sehen
- Aufgaben bearbeiten
- Kommentare schreiben
- Zeiten erfassen
- eigenen Monatszettel ansehen
- Kundenansicht nutzen, sofern freigegeben
- Nachrichten senden und empfangen
- Projektchats zu sichtbaren Projekten nutzen
- Gesamtchat nutzen
- Tickets erstellen
- eigene Tickets verfolgen
- erledigte eigene Tickets bestätigen

### Gast

Gäste haben eingeschränkte Leserechte und keine kritischen Schreib- oder Verwaltungsfunktionen.

---

## Export, Druck und Auswertungen

Vorhanden:

- JSON-Export
- JSON-Import
- JSON-nach-MySQL-Restore
- CSV-Export für Monatszettel
- CSV-Export für EÜR
- Druckansicht für Monatszettel
- Druckansicht für EÜR
- Rechnungsdruck über Browserdruck
- PDF über Browserdruck
- Demo-SQL
- Clean-SQL
- Installationsanleitung
- technische Dokumentation

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
- Rechnungs-, Kunden-, Nachrichten- und Ticketdaten nicht in öffentlichen Repositories ablegen
- Fehlerausgaben im Produktivbetrieb nicht ungefiltert anzeigen
- Adminzugänge nicht mit Standarddaten betreiben
- Datenbank-Backups getrennt und geschützt speichern

Empfehlung für GitHub:

- `config.example.php` statt echter `config.php` veröffentlichen
- echte Zugangsdaten niemals committen
- bei Bedarf `.gitignore` für sensible Dateien nutzen
- Repository nicht als Open Source deklarieren, wenn keine freie Nutzung gewünscht ist

---

## Weitergabe und GitHub-Hinweis

Dieses Projekt kann technisch auf GitHub abgelegt werden, ist dadurch aber nicht automatisch frei nutzbar. Das Repository dient der Ansicht, Dokumentation und Präsentation.

Wichtig:

- keine Nutzung ohne schriftliche Zustimmung
- keine Änderung ohne schriftliche Zustimmung
- keine Weitergabe ohne schriftliche Zustimmung
- keine kommerzielle Nutzung ohne schriftliche Zustimmung
- kein Hosting eigener Kopien ohne schriftliche Zustimmung
- kein Einbau in fremde Projekte ohne schriftliche Zustimmung
- keine Ableitung eigener Produkte ohne schriftliche Zustimmung

Pull Requests, Forks, Issues oder Codevorschläge begründen keine Nutzungserlaubnis am Projekt.

---

## Screenshots

<img src="Screenshot 2026-07-01 164259.png" alt="PolarisNova Projektübersicht">
<img src="Screenshot 2026-07-01 164412.png" alt="PolarisNova Kanban-Board">
<img src="Screenshot 2026-07-01 164428.png" alt="PolarisNova Kundenverwaltung">

---

## Roadmap

Mögliche spätere Erweiterungen:

- echtes Mehrmandantensystem
- Mandantenverwaltung auf Plattformebene
- automatischer Webinstaller
- Update-/Migrationssystem mit Versionsverwaltung
- Passwort-zurücksetzen per E-Mail
- E-Mail-Einladungen
- 2-Faktor-Authentifizierung
- automatische Backups per Cron
- serverseitiger PDF-Export
- DATEV-/Steuerberater-Export
- Rechnungsvorlagen mit Logo und Nummernkreisen
- Urlaubs-/Krankheitsverwaltung
- Freigabeprozess für Monatszettel
- Rollenrechte feiner abstufen
- API-Dokumentation erweitern
- Testsuite ergänzen

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

Aktueller Stand:

```txt
Self-hosted Projekt-, Kunden-, Aufgaben-, Zeit-, Rechnungs-, EÜR-, Nachrichten- und Ticketverwaltung.
```

Die Anwendung ist als weitergabefähige Self-hosted-Version mit Setup-Struktur, Demo-Benutzern, SQL-Datei, Dokumentation, README und proprietärer Lizenz vorbereitet.
