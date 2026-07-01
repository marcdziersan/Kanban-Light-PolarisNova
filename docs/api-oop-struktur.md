# PolarisNova API-/OOP-Struktur

## Ziel

Die großen prozeduralen API-Dateien wurden in eine OOP-Struktur überführt. Die bestehenden Frontend-Endpunkte bleiben kompatibel:

- `api/api.php?action=...`
- `api/customers.php?action=...`

Die Einstiegspunkte sind jetzt bewusst dünn und delegieren an Controller-Klassen.

## Struktur

```text
app/
  bootstrap.php
  .htaccess
  Core/
    ArrayHelper.php
    Clock.php
    JsonResponse.php
    Request.php
  Controller/
    KanbanApiController.php
    CustomerApiController.php
  Domain/
    Kanban/
      KanbanAccessTrait.php
      KanbanAuditTrait.php
      KanbanBoardPayloadTrait.php
      KanbanTaskRulesTrait.php
    Customer/
      CustomerAccessTrait.php
      CustomerInputTrait.php
      CustomerProjectionTrait.php
  Storage/
    KanbanStorageAdapter.php
    CustomerStorageAdapter.php
api/
  api.php
  customers.php
lib/
  storage.php
  customer_storage.php
```

## Verantwortlichkeiten

- `app/bootstrap.php` lädt Autoloader, Storage-Funktionen und startet die Session.
- `Core/Request.php` kapselt Query-Parameter und JSON-Request-Body.
- `Core/JsonResponse.php` kapselt JSON-Antworten und JSON-Downloads.
- `Core/Clock.php` kapselt Zeitstempel.
- `Core/ArrayHelper.php` kapselt wiederkehrende Array-Helfer wie nächste ID und Indexsuche.
- `Storage/*Adapter.php` kapseln die vorhandenen Storage-Funktionen, damit Controller nicht direkt gegen prozedurale Storage-Funktionen arbeiten.
- `Controller/KanbanApiController.php` enthält das Routing der Board-, Aufgaben-, User-, JSON-Sync- und Report-API.
- `Domain/Kanban/*` enthält Zugriffsregeln, Board-Payload, Aufgabenregeln und Audit-/History-Logik.
- `Controller/CustomerApiController.php` enthält das Routing der eigenständigen Kundenverwaltungs-API.
- `Domain/Customer/*` enthält Kundenrechte, Projektionen für das Frontend und Eingabe-/Normalisierungslogik.

## Kompatibilität

Die Dateinamen der öffentlichen API-Endpunkte wurden nicht geändert. Dadurch müssen `assets/js/app.js` und `assets/js/customers.js` nicht angepasst werden.

## Prüfung

Alle PHP-Dateien wurden per Syntaxcheck geprüft:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

Zusätzlich wurden beide API-Einstiegspunkte per CLI-Fallback geprüft. Ohne Session liefern sie erwartbar `Nicht angemeldet` statt eines PHP-Fehlers.
