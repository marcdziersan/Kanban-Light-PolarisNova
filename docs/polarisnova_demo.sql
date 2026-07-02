-- PolarisNova Demo-Installation
-- Stand: 01.07.2026
-- Inhalt: Tabellenstruktur, Primärschlüssel, Fremdschlüssel, Demo-Logins und vollständige Beispieldaten.
-- Enthalten: Kunden, Projekte, Boards, Spalten, Aufgaben, Kommentare, Zeiten, Events und Historie.

CREATE DATABASE IF NOT EXISTS `polarisnova` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `polarisnova`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `kv_events`;
DROP TABLE IF EXISTS `kv_customer_projects`;
DROP TABLE IF EXISTS `kv_customers`;
DROP TABLE IF EXISTS `history`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `time_entries`;
DROP TABLE IF EXISTS `comments`;
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `kanban_columns`;
DROP TABLE IF EXISTS `boards`;
DROP TABLE IF EXISTS `project_members`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','user','guest') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `projects` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `owner_id` int UNSIGNED DEFAULT NULL,
  `responsible_id` int UNSIGNED DEFAULT NULL,
  `created_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_projects_owner` (`owner_id`),
  KEY `idx_projects_responsible` (`responsible_id`),
  CONSTRAINT `fk_projects_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_projects_responsible` FOREIGN KEY (`responsible_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_members` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `created_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_project_user` (`project_id`, `user_id`),
  KEY `idx_project_members_project` (`project_id`),
  KEY `idx_project_members_user` (`user_id`),
  CONSTRAINT `fk_project_members_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_project_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `boards` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` int UNSIGNED NOT NULL,
  `name` varchar(190) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_boards_project` (`project_id`),
  CONSTRAINT `fk_boards_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kanban_columns` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `board_id` int UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `position` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_columns_board` (`board_id`),
  KEY `idx_columns_position` (`position`),
  CONSTRAINT `fk_columns_board` FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tasks` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `column_id` int UNSIGNED NOT NULL,
  `title` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `assigned_to` int UNSIGNED DEFAULT NULL,
  `position` int NOT NULL DEFAULT 0,
  `locked_by` int UNSIGNED DEFAULT NULL,
  `locked_at` varchar(32) DEFAULT NULL,
  `due_at` varchar(32) DEFAULT NULL,
  `created_at` varchar(32) DEFAULT NULL,
  `updated_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_column` (`column_id`),
  KEY `idx_tasks_assigned` (`assigned_to`),
  KEY `idx_tasks_priority` (`priority`),
  KEY `idx_tasks_due` (`due_at`),
  KEY `idx_tasks_locked` (`locked_by`),
  CONSTRAINT `fk_tasks_column` FOREIGN KEY (`column_id`) REFERENCES `kanban_columns` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tasks_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tasks_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `comments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `content` text NOT NULL,
  `created_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comments_task` (`task_id`),
  KEY `idx_comments_user` (`user_id`),
  CONSTRAINT `fk_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `time_entries` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `started_at` varchar(32) DEFAULT NULL,
  `stopped_at` varchar(32) DEFAULT NULL,
  `seconds` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_time_task` (`task_id`),
  KEY `idx_time_user` (`user_id`),
  CONSTRAINT `fk_time_entries_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_time_entries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `events` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` int UNSIGNED DEFAULT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `type` varchar(80) NOT NULL,
  `message` text DEFAULT NULL,
  `created_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_events_task` (`task_id`),
  KEY `idx_events_user` (`user_id`),
  KEY `idx_events_type` (`type`),
  CONSTRAINT `fk_events_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `history` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `field` varchar(80) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_history_task` (`task_id`),
  KEY `idx_history_user` (`user_id`),
  KEY `idx_history_action` (`action`),
  CONSTRAINT `fk_history_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kv_customers` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `company` varchar(190) NOT NULL,
  `contact_name` varchar(190) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `website` varchar(190) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `type` enum('customer','prospect','partner','internal') NOT NULL DEFAULT 'customer',
  `status` enum('lead','active','paused','archived') NOT NULL DEFAULT 'lead',
  `source` varchar(190) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` varchar(32) DEFAULT NULL,
  `updated_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kv_customers_company` (`company`),
  KEY `idx_kv_customers_status` (`status`),
  KEY `idx_kv_customers_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kv_customer_projects` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` int UNSIGNED NOT NULL,
  `project_id` int UNSIGNED NOT NULL,
  `created_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kv_customer_project` (`customer_id`, `project_id`),
  KEY `idx_kv_cp_customer` (`customer_id`),
  KEY `idx_kv_cp_project` (`project_id`),
  CONSTRAINT `fk_kv_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `kv_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kv_events` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `type` varchar(80) NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  `created_at` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kv_events_customer` (`customer_id`),
  KEY `idx_kv_events_user` (`user_id`),
  KEY `idx_kv_events_type` (`type`),
  CONSTRAINT `fk_kv_events_customer` FOREIGN KEY (`customer_id`) REFERENCES `kv_customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_kv_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET NAMES utf8mb4;
START TRANSACTION;

-- --------------------------------------------------------
-- Demo-Benutzer
-- --------------------------------------------------------
-- Login:
--   admin / admin123
--   demo  / demo123

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `is_active`, `created_at`) VALUES
(1, 'admin', 'admin@example.local', '$2y$10$NjLbet8SaYZrFBjNnL1iQOSHDU8AT7soJRedklAthx8HdSfPBXQoi', 'admin', 1, '2026-07-01 09:00:00'),
(2, 'demo', 'demo@example.local', '$2y$10$XLt0moOZTbXyo7oAdyosvuwm8XrP6jhGnfYCALN.wP15M5NWMu1LG', 'user', 1, '2026-07-01 09:05:00');

-- --------------------------------------------------------
-- Beispiel-Projekte
-- --------------------------------------------------------

INSERT INTO `projects` (`id`, `name`, `description`, `owner_id`, `responsible_id`, `created_at`) VALUES
(1, 'polarisnova.de', 'Relaunch einer kleinen Agentur-Website inklusive Kundenbereich, Startseite, Kontaktformular und technischer Grundstruktur.', 1, 2, '2026-07-01 09:15:00'),
(2, 'Interne Organisation', 'Internes Board für Aufgaben rund um Dokumentation, Installation, GitHub-Veröffentlichung und Übergabe.', 1, 1, '2026-07-01 09:20:00'),
(3, 'Kundenportal MVP', 'Konzept und erster Prototyp für ein schlankes Kundenportal mit Login, Tickets und Statusübersicht.', 1, 2, '2026-07-01 09:25:00');

INSERT INTO `project_members` (`id`, `project_id`, `user_id`, `created_at`) VALUES
(1, 1, 1, '2026-07-01 09:16:00'),
(2, 1, 2, '2026-07-01 09:16:00'),
(3, 2, 1, '2026-07-01 09:21:00'),
(4, 2, 2, '2026-07-01 09:21:00'),
(5, 3, 1, '2026-07-01 09:26:00'),
(6, 3, 2, '2026-07-01 09:26:00');

-- --------------------------------------------------------
-- Beispiel-Boards und Kanban-Spalten
-- --------------------------------------------------------

INSERT INTO `boards` (`id`, `project_id`, `name`) VALUES
(1, 1, 'Web Agency'),
(2, 2, 'Organisation'),
(3, 3, 'Portal MVP');

INSERT INTO `kanban_columns` (`id`, `board_id`, `name`, `position`) VALUES
(1, 1, 'Offen', 1),
(2, 1, 'In Arbeit', 2),
(3, 1, 'Review', 3),
(4, 1, 'Erledigt', 4),
(5, 2, 'Offen', 1),
(6, 2, 'In Arbeit', 2),
(7, 2, 'Review', 3),
(8, 2, 'Erledigt', 4),
(9, 3, 'Offen', 1),
(10, 3, 'In Arbeit', 2),
(11, 3, 'Review', 3),
(12, 3, 'Erledigt', 4);

-- --------------------------------------------------------
-- Beispiel-Aufgaben
-- --------------------------------------------------------

INSERT INTO `tasks` (`id`, `column_id`, `title`, `description`, `priority`, `assigned_to`, `position`, `locked_by`, `locked_at`, `due_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'Startseiten-Texte strukturieren', 'Hero-Bereich, Nutzenversprechen und klare Call-to-Actions für die Startseite ausarbeiten.', 'high', 2, 1, NULL, NULL, '2026-07-05 17:00:00', '2026-07-01 10:00:00', '2026-07-01 10:00:00'),
(2, 2, 'Responsive Navigation einbauen', 'Mobile-first Navigation mit Hamburger-Menü, aktiven Links und stabiler Desktop-Darstellung.', 'medium', 2, 1, NULL, NULL, '2026-07-04 18:00:00', '2026-07-01 10:10:00', '2026-07-01 11:00:00'),
(3, 3, 'Kontaktformular prüfen', 'Validierung, Fehlermeldungen und Sendebestätigung fachlich und technisch testen.', 'critical', 1, 1, NULL, NULL, '2026-07-03 16:00:00', '2026-07-01 10:20:00', '2026-07-01 12:00:00'),
(4, 4, 'Impressum und Datenschutz ergänzen', 'Pflichtseiten mit Platzhaltern anlegen und im Footer verlinken.', 'low', 1, 1, NULL, NULL, '2026-07-02 12:00:00', '2026-07-01 10:30:00', '2026-07-01 13:00:00'),
(5, 5, 'README für GitHub finalisieren', 'Projektbeschreibung, Installationsschritte, Demo-Login und Lizenzhinweise dokumentieren.', 'high', 1, 1, NULL, NULL, '2026-07-06 12:00:00', '2026-07-01 10:40:00', '2026-07-01 10:40:00'),
(6, 6, 'Technische Dokumentation gegenlesen', 'Architektur, API, Datenbankmodell und Erweiterungspunkte auf Vollständigkeit prüfen.', 'medium', 2, 1, NULL, NULL, '2026-07-07 12:00:00', '2026-07-01 10:50:00', '2026-07-01 11:40:00'),
(7, 8, 'Installationsanleitung erstellen', 'config.php, setup_status.php und index.php als einfachen Installationsweg dokumentieren.', 'medium', 1, 1, NULL, NULL, '2026-07-01 18:00:00', '2026-07-01 11:00:00', '2026-07-01 14:00:00'),
(8, 9, 'Login-Maske für Kundenportal planen', 'Felder, Fehlermeldungen, Session-Verhalten und Weiterleitung nach Login definieren.', 'high', 2, 1, NULL, NULL, '2026-07-08 16:00:00', '2026-07-01 11:10:00', '2026-07-01 11:10:00'),
(9, 10, 'Ticketübersicht prototypisch bauen', 'Liste mit Status, Priorität, Kunde, Fälligkeit und Detailansicht vorbereiten.', 'critical', 2, 1, NULL, NULL, '2026-07-09 16:00:00', '2026-07-01 11:20:00', '2026-07-01 12:15:00'),
(10, 11, 'Statusfarben fachlich prüfen', 'Statuslogik für offen, in Arbeit, wartend, erledigt und archiviert mit UI abgleichen.', 'medium', 1, 1, NULL, NULL, '2026-07-10 16:00:00', '2026-07-01 11:30:00', '2026-07-01 12:30:00'),
(11, 12, 'Grundlayout Kundenportal abschließen', 'Basislayout mit Header, Sidebar, Contentbereich und leerem Dashboard fertigstellen.', 'low', 1, 1, NULL, NULL, '2026-07-05 12:00:00', '2026-07-01 11:40:00', '2026-07-01 13:30:00'),
(12, 2, 'Bildmaterial austauschen', 'Platzhalterbilder durch neutrale Projektgrafiken ersetzen und Alt-Texte ergänzen.', 'low', NULL, 2, NULL, NULL, '2026-07-11 12:00:00', '2026-07-01 11:50:00', '2026-07-01 11:50:00');

-- --------------------------------------------------------
-- Beispiel-Kommentare
-- --------------------------------------------------------

INSERT INTO `comments` (`id`, `task_id`, `user_id`, `content`, `created_at`) VALUES
(1, 1, 1, 'Bitte den Nutzen für kleine Unternehmen klarer herausstellen. Nicht nur Technik erklären.', '2026-07-01 10:35:00'),
(2, 1, 2, 'Ich ergänze einen Abschnitt zu schneller Einrichtung und persönlicher Betreuung.', '2026-07-01 10:45:00'),
(3, 2, 2, 'Navigation ist eingebaut. Desktop passt, mobile Abstände prüfe ich noch.', '2026-07-01 11:05:00'),
(4, 3, 1, 'Fehlertexte bitte nicht technisch, sondern kundenfreundlich formulieren.', '2026-07-01 12:05:00'),
(5, 5, 1, 'Lizenzhinweis muss deutlich sichtbar bleiben.', '2026-07-01 11:15:00'),
(6, 6, 2, 'API-Abschnitt ist fast fertig, Datenbankmodell fehlt noch als Kurzreferenz.', '2026-07-01 12:10:00'),
(7, 8, 1, 'Bitte Login zuerst einfach halten, später Rollen erweitern.', '2026-07-01 12:20:00'),
(8, 9, 2, 'Ticketliste steht als Rohfassung. Filter kommen danach.', '2026-07-01 12:45:00'),
(9, 10, 1, 'Statusfarben sind nachvollziehbar, bitte Kontrast auf mobilen Geräten prüfen.', '2026-07-01 13:00:00'),
(10, 12, 2, 'Ich suche neutrale Grafiken, damit keine externen Rechte im Demo-Projekt hängen.', '2026-07-01 13:15:00');

-- --------------------------------------------------------
-- Beispiel-Zeiterfassung
-- --------------------------------------------------------

INSERT INTO `time_entries` (`id`, `task_id`, `user_id`, `started_at`, `stopped_at`, `seconds`) VALUES
(1, 1, 2, '2026-07-01 10:00:00', '2026-07-01 10:45:00', 2700),
(2, 2, 2, '2026-07-01 10:50:00', '2026-07-01 11:35:00', 2700),
(3, 3, 1, '2026-07-01 11:30:00', '2026-07-01 12:15:00', 2700),
(4, 4, 1, '2026-07-01 12:20:00', '2026-07-01 12:50:00', 1800),
(5, 5, 1, '2026-07-01 13:00:00', '2026-07-01 13:40:00', 2400),
(6, 6, 2, '2026-07-01 13:10:00', '2026-07-01 14:05:00', 3300),
(7, 7, 1, '2026-07-01 14:10:00', '2026-07-01 14:45:00', 2100),
(8, 8, 2, '2026-07-01 14:30:00', '2026-07-01 15:05:00', 2100),
(9, 9, 2, '2026-07-01 15:10:00', '2026-07-01 16:25:00', 4500),
(10, 10, 1, '2026-07-01 15:30:00', '2026-07-01 16:00:00', 1800),
(11, 11, 1, '2026-07-01 16:05:00', '2026-07-01 16:40:00', 2100),
(12, 12, 2, '2026-07-01 16:10:00', '2026-07-01 16:35:00', 1500);

-- --------------------------------------------------------
-- Beispiel-Events im Kanban-Modul
-- --------------------------------------------------------

INSERT INTO `events` (`id`, `task_id`, `user_id`, `type`, `message`, `created_at`) VALUES
(1, 1, 1, 'task_created', 'Aufgabe erstellt: Startseiten-Texte strukturieren', '2026-07-01 10:00:00'),
(2, 2, 2, 'task_created', 'Aufgabe erstellt: Responsive Navigation einbauen', '2026-07-01 10:10:00'),
(3, 3, 1, 'task_created', 'Aufgabe erstellt: Kontaktformular prüfen', '2026-07-01 10:20:00'),
(4, 4, 1, 'task_done', 'Aufgabe abgeschlossen: Impressum und Datenschutz ergänzen', '2026-07-01 13:00:00'),
(5, 5, 1, 'task_created', 'Aufgabe erstellt: README für GitHub finalisieren', '2026-07-01 10:40:00'),
(6, 6, 2, 'task_updated', 'Aufgabe aktualisiert: Technische Dokumentation gegenlesen', '2026-07-01 11:40:00'),
(7, 7, 1, 'task_done', 'Aufgabe abgeschlossen: Installationsanleitung erstellen', '2026-07-01 14:00:00'),
(8, 8, 2, 'task_created', 'Aufgabe erstellt: Login-Maske für Kundenportal planen', '2026-07-01 11:10:00'),
(9, 9, 2, 'task_updated', 'Aufgabe aktualisiert: Ticketübersicht prototypisch bauen', '2026-07-01 12:15:00'),
(10, 10, 1, 'task_review', 'Aufgabe in Review verschoben: Statusfarben fachlich prüfen', '2026-07-01 12:30:00'),
(11, 11, 1, 'task_done', 'Aufgabe abgeschlossen: Grundlayout Kundenportal abschließen', '2026-07-01 13:30:00'),
(12, 12, 2, 'task_created', 'Aufgabe erstellt: Bildmaterial austauschen', '2026-07-01 11:50:00'),
(13, NULL, 1, 'project_created', 'Projekt erstellt: polarisnova.de', '2026-07-01 09:15:00'),
(14, NULL, 1, 'project_created', 'Projekt erstellt: Interne Organisation', '2026-07-01 09:20:00'),
(15, NULL, 1, 'project_created', 'Projekt erstellt: Kundenportal MVP', '2026-07-01 09:25:00');

-- --------------------------------------------------------
-- Beispiel-Historie / Änderungsprotokoll
-- --------------------------------------------------------

INSERT INTO `history` (`id`, `task_id`, `user_id`, `action`, `field`, `old_value`, `new_value`, `message`, `created_at`) VALUES
(1, 1, 1, 'create', NULL, NULL, NULL, 'Aufgabe wurde angelegt.', '2026-07-01 10:00:00'),
(2, 1, 2, 'update', 'description', 'Hero-Bereich definieren.', 'Hero-Bereich, Nutzenversprechen und klare Call-to-Actions für die Startseite ausarbeiten.', 'Beschreibung wurde erweitert.', '2026-07-01 10:30:00'),
(3, 2, 2, 'move', 'column_id', '1', '2', 'Aufgabe wurde nach In Arbeit verschoben.', '2026-07-01 11:00:00'),
(4, 3, 1, 'move', 'column_id', '2', '3', 'Aufgabe wurde in Review verschoben.', '2026-07-01 12:00:00'),
(5, 4, 1, 'move', 'column_id', '3', '4', 'Aufgabe wurde erledigt.', '2026-07-01 13:00:00'),
(6, 5, 1, 'create', NULL, NULL, NULL, 'Aufgabe wurde angelegt.', '2026-07-01 10:40:00'),
(7, 6, 2, 'update', 'priority', 'low', 'medium', 'Priorität wurde angepasst.', '2026-07-01 11:40:00'),
(8, 7, 1, 'move', 'column_id', '7', '8', 'Aufgabe wurde erledigt.', '2026-07-01 14:00:00'),
(9, 8, 2, 'create', NULL, NULL, NULL, 'Aufgabe wurde angelegt.', '2026-07-01 11:10:00'),
(10, 9, 2, 'move', 'column_id', '9', '10', 'Aufgabe wurde nach In Arbeit verschoben.', '2026-07-01 12:15:00'),
(11, 10, 1, 'move', 'column_id', '10', '11', 'Aufgabe wurde in Review verschoben.', '2026-07-01 12:30:00'),
(12, 11, 1, 'move', 'column_id', '11', '12', 'Aufgabe wurde erledigt.', '2026-07-01 13:30:00');

-- --------------------------------------------------------
-- Beispiel-Kundenverwaltung
-- --------------------------------------------------------

INSERT INTO `kv_customers` (`id`, `company`, `contact_name`, `email`, `phone`, `website`, `address`, `city`, `type`, `status`, `source`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'PolarisNova GmbH', 'Alexander Grass', 'alexander.grass@polarisnova.de', '+49 231 000000-10', 'https://polarisnova.de', 'Musterstraße 12', 'Dortmund', 'customer', 'active', 'Bestandskontakt', 'Demo-Kunde für das Website-Relaunch-Projekt.', '2026-07-01 09:30:00', '2026-07-01 09:30:00'),
(2, 'Muster Bäckerei Lünen', 'Sabine Krume', 'kontakt@musterbaeckerei.example', '+49 2306 000000', 'https://baeckerei.example', 'Backweg 4', 'Lünen', 'prospect', 'lead', 'Empfehlung', 'Interessent für ein kleines Kundenportal mit einfacher Ticketübersicht.', '2026-07-01 09:35:00', '2026-07-01 09:35:00'),
(3, 'Nordlicht Outdoor', 'Mara Jensen', 'mara.jensen@nordlicht.example', '+49 431 000000', 'https://nordlicht.example', 'Hafenstraße 8', 'Kiel', 'partner', 'active', 'Netzwerk', 'Partnerkontakt für UI-Testdaten und Beispieltexte.', '2026-07-01 09:40:00', '2026-07-01 09:40:00'),
(4, 'Demo Verein e.V.', 'Thomas Beispiel', 'vorstand@demo-verein.example', '+49 211 000000', 'https://verein.example', 'Vereinsallee 1', 'Düsseldorf', 'customer', 'paused', 'Demo-Datensatz', 'Ruhender Beispielkunde ohne aktuell verknüpftes Projekt.', '2026-07-01 09:45:00', '2026-07-01 09:45:00');

INSERT INTO `kv_customer_projects` (`id`, `customer_id`, `project_id`, `created_at`) VALUES
(1, 1, 1, '2026-07-01 09:50:00'),
(2, 2, 3, '2026-07-01 09:52:00'),
(3, 3, 2, '2026-07-01 09:54:00');

INSERT INTO `kv_events` (`id`, `customer_id`, `user_id`, `type`, `message`, `created_at`) VALUES
(1, 1, 1, 'customer_created', 'Kunde angelegt: PolarisNova GmbH', '2026-07-01 09:30:00'),
(2, 2, 1, 'customer_created', 'Kunde angelegt: Muster Bäckerei Lünen', '2026-07-01 09:35:00'),
(3, 3, 1, 'customer_created', 'Kunde angelegt: Nordlicht Outdoor', '2026-07-01 09:40:00'),
(4, 4, 1, 'customer_created', 'Kunde angelegt: Demo Verein e.V.', '2026-07-01 09:45:00'),
(5, 1, 1, 'project_linked', 'Projekt verknüpft: polarisnova.de', '2026-07-01 09:50:00'),
(6, 2, 1, 'project_linked', 'Projekt verknüpft: Kundenportal MVP', '2026-07-01 09:52:00'),
(7, 3, 1, 'project_linked', 'Projekt verknüpft: Interne Organisation', '2026-07-01 09:54:00'),
(8, 2, 2, 'note', 'Erstgespräch geplant: Anforderungen an das Kundenportal sammeln.', '2026-07-01 10:05:00'),
(9, 1, 2, 'note', 'Website-Relaunch priorisiert. Kontaktformular zuerst testen.', '2026-07-01 10:15:00');

ALTER TABLE `users` AUTO_INCREMENT = 3;
ALTER TABLE `projects` AUTO_INCREMENT = 4;
ALTER TABLE `project_members` AUTO_INCREMENT = 7;
ALTER TABLE `boards` AUTO_INCREMENT = 4;
ALTER TABLE `kanban_columns` AUTO_INCREMENT = 13;
ALTER TABLE `tasks` AUTO_INCREMENT = 13;
ALTER TABLE `comments` AUTO_INCREMENT = 11;
ALTER TABLE `time_entries` AUTO_INCREMENT = 13;
ALTER TABLE `events` AUTO_INCREMENT = 16;
ALTER TABLE `history` AUTO_INCREMENT = 13;
ALTER TABLE `kv_customers` AUTO_INCREMENT = 5;
ALTER TABLE `kv_customer_projects` AUTO_INCREMENT = 4;
ALTER TABLE `kv_events` AUTO_INCREMENT = 10;

COMMIT;

