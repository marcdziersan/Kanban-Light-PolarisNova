-- PolarisNova Weitergabe-Installation
-- Stand: 01.07.2026
-- Inhalt: Tabellenstruktur, Primärschlüssel, Fremdschlüssel und Demo-Logins.
-- Keine Kunden, Projekte, Boards, Aufgaben, Kommentare, Zeiten, Events oder Historie.

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

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `is_active`, `created_at`) VALUES
(1, 'admin', 'admin@example.local', '$2y$10$NjLbet8SaYZrFBjNnL1iQOSHDU8AT7soJRedklAthx8HdSfPBXQoi', 'admin', 1, '2026-07-01 00:00:00'),
(2, 'demo', 'demo@example.local', '$2y$10$XLt0moOZTbXyo7oAdyosvuwm8XrP6jhGnfYCALN.wP15M5NWMu1LG', 'user', 1, '2026-07-01 00:00:00');

ALTER TABLE `users` AUTO_INCREMENT = 3;
ALTER TABLE `projects` AUTO_INCREMENT = 1;
ALTER TABLE `project_members` AUTO_INCREMENT = 1;
ALTER TABLE `boards` AUTO_INCREMENT = 1;
ALTER TABLE `kanban_columns` AUTO_INCREMENT = 1;
ALTER TABLE `tasks` AUTO_INCREMENT = 1;
ALTER TABLE `comments` AUTO_INCREMENT = 1;
ALTER TABLE `time_entries` AUTO_INCREMENT = 1;
ALTER TABLE `events` AUTO_INCREMENT = 1;
ALTER TABLE `history` AUTO_INCREMENT = 1;
ALTER TABLE `kv_customers` AUTO_INCREMENT = 1;
ALTER TABLE `kv_customer_projects` AUTO_INCREMENT = 1;
ALTER TABLE `kv_events` AUTO_INCREMENT = 1;
