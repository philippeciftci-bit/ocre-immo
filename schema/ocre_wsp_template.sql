-- M/2026/05/07/7 — Template DB workspace agent (ocre_wsp_<slug>).
-- Fige par mission Provisioning workspace agent. Mode unique M84 : 1 agent = 1 DB.
-- Charset utf8mb4_unicode_ci. PAS de seed clients (prod direct, agent commence vide).
-- 4 tables strictes : clients, settings, sessions, logs.
-- Aucune table 'users' : les users vivent dans ocre_meta uniquement.
-- FK fk_clients_user retiree (FK cross-database non supportee + coherence applicatif user_id session).

-- Table clients : extrait du schema ocre_wsp_ozkan (M84 baseline) sans FK cross-DB.
CREATE TABLE `clients` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table settings : preferences workspace key/value.
CREATE TABLE `settings` (
  `key_name` varchar(100) NOT NULL,
  `value` longtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table sessions : tokens auth tenant (au cas ou besoin de session-locale, sinon meta gere).
CREATE TABLE `sessions` (
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

-- Table logs : audit local workspace (events application metier).
CREATE TABLE `logs` (
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

-- Settings par defaut (langue/devise/fuseau).
INSERT INTO `settings` (`key_name`, `value`) VALUES
  ('lang', 'fr'),
  ('default_currency_left', 'EUR'),
  ('default_currency_right', 'MAD'),
  ('default_currency_rate', '10.84'),
  ('timezone', 'Europe/Paris'),
  ('schema_version', '1.0');
