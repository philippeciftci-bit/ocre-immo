-- M/2026/05/14/1 — V001 baseline schema wsp Ocre.
-- Cible: état minimum partagé par tous les wsp prod (10 tables noyau).
-- Vocab: ce schéma sert un "wsp" (espace de travail agent immo). Ne pas employer "tenant".
-- Idempotent: CREATE TABLE IF NOT EXISTS partout.

SET FOREIGN_KEY_CHECKS=0;

-- Table de suivi migrations elle-meme (créée AVANT les autres pour que ocre-migrate puisse logger).
CREATE TABLE IF NOT EXISTS `_schema_migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `record_id` bigint(20) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE','RESTORE') NOT NULL,
  `before_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_state`)),
  `after_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_state`)),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_record` (`table_name`,`record_id`),
  KEY `idx_user_date` (`user_id`,`created_at`),
  KEY `idx_table_date` (`table_name`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `projet` varchar(40) NOT NULL DEFAULT 'Acheteur',
  `is_investisseur` tinyint(1) NOT NULL DEFAULT 0,
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `statut` enum('brouillon','enregistre','archive') NOT NULL DEFAULT 'enregistre',
  `currency_rates` longtext DEFAULT NULL,
  `country_checklist` longtext DEFAULT NULL,
  `is_draft` tinyint(1) NOT NULL DEFAULT 1,
  `prenom` varchar(100) DEFAULT NULL,
  `nom` varchar(100) DEFAULT NULL,
  `societe_nom` varchar(150) DEFAULT NULL,
  `tel` varchar(40) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_staged` tinyint(4) NOT NULL DEFAULT 0,
  `promoted_at` datetime DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `published_at` timestamp NULL DEFAULT NULL,
  `public_slug` varchar(191) DEFAULT NULL,
  `public_title` varchar(255) DEFAULT NULL,
  `public_description` text DEFAULT NULL,
  `public_visible` tinyint(1) NOT NULL DEFAULT 1,
  `public_views_count` int(10) unsigned NOT NULL DEFAULT 0,
  `public_contacts_count` int(10) unsigned NOT NULL DEFAULT 0,
  `vertical` enum('vente','location_longue','sejour_court') DEFAULT NULL,
  `payment_plan` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payment_plan`)),
  `received_payments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`received_payments`)),
  `is_demo` tinyint(4) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `seed_id` varchar(64) DEFAULT NULL,
  `is_promoteur` tinyint(1) NOT NULL DEFAULT 0,
  `is_marchand_de_biens` tinyint(1) NOT NULL DEFAULT 0,
  `phone_country` varchar(2) DEFAULT NULL,
  `phone_e164` varchar(20) DEFAULT NULL,
  `id_country` varchar(2) DEFAULT NULL,
  `id_type` varchar(20) DEFAULT NULL,
  `id_number` varchar(50) DEFAULT NULL,
  `bien_country` varchar(2) DEFAULT NULL,
  `status_final` varchar(20) DEFAULT 'brouillon',
  `validated_at` datetime DEFAULT NULL,
  `validated_by` int(11) DEFAULT NULL,
  `photos_uuids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`photos_uuids`)),
  `destinataire_nom` varchar(200) DEFAULT NULL,
  `destinataire_email` varchar(200) DEFAULT NULL,
  `exchange_rate_snapshot` decimal(14,6) DEFAULT NULL,
  `exchange_rate_source` varchar(64) DEFAULT NULL,
  `exchange_rate_fetched_at` datetime DEFAULT NULL,
  `custom_exchange_rate` decimal(14,6) DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_public_slug` (`public_slug`),
  UNIQUE KEY `uniq_user_seed` (`user_id`,`seed_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_archived` (`user_id`,`archived`),
  KEY `idx_user_draft` (`user_id`,`is_draft`),
  KEY `idx_projet` (`projet`),
  KEY `idx_staged` (`user_id`,`is_staged`),
  KEY `idx_is_published` (`is_published`,`public_visible`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `countries_config` (
  `code` char(2) NOT NULL,
  `name` varchar(60) DEFAULT NULL,
  `flag_emoji` varchar(10) DEFAULT NULL,
  `currency` varchar(4) DEFAULT NULL,
  `devise_symbol` varchar(10) DEFAULT NULL,
  `phone_prefix` varchar(6) DEFAULT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `sort_order` int(11) DEFAULT 100,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `google_places_quota` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `count` int(11) NOT NULL DEFAULT 0,
  `daily_limit` int(11) NOT NULL DEFAULT 10,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_agent_date` (`agent_id`,`date`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `google_quota_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `motif` text DEFAULT NULL,
  `status` enum('pending','approved','denied') NOT NULL DEFAULT 'pending',
  `responded_at` datetime DEFAULT NULL,
  `responder_id` int(11) DEFAULT NULL,
  `response_message` text DEFAULT NULL,
  `granted_extra` int(11) NOT NULL DEFAULT 10,
  PRIMARY KEY (`id`),
  KEY `idx_agent_status` (`agent_id`,`status`),
  KEY `idx_status_date` (`status`,`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(80) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `type` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `link_path` varchar(255) DEFAULT NULL,
  `ref_type` varchar(64) DEFAULT NULL,
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_unread` (`user_id`,`is_read`,`created_at`),
  KEY `idx_ref` (`ref_type`,`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ocre_sync_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `dossier_id` int(11) DEFAULT NULL,
  `action` varchar(20) NOT NULL DEFAULT 'upsert',
  `created_at` datetime DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `error` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_pending` (`processed_at`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` varchar(100) NOT NULL,
  `value` longtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
