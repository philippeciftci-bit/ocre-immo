-- M/2026/05/14/1 — V008 ajout table `events`.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `owner_user_id` int(10) unsigned NOT NULL,
  `type` enum('appel','rdv','document','note') NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `status` enum('prevu','en_attente','reporte','fait','annule') NOT NULL DEFAULT 'prevu',
  `reminder_offset_minutes` int(11) DEFAULT NULL,
  `reminder_sent` tinyint(1) NOT NULL DEFAULT 0,
  `document_recipient` varchar(255) DEFAULT NULL,
  `document_attachment_path` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client_scheduled` (`client_id`,`scheduled_at`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_reminder` (`reminder_sent`,`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
