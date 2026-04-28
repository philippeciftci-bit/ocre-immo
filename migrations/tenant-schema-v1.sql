SET FOREIGN_KEY_CHECKS=0;
-- M/2026/04/28/17 — Schéma canonique tenant Ocre. Source : ocre_wsp_mehdi_test
-- (DB tenant complète saine au moment du dump). Idempotent (CREATE TABLE IF NOT
-- EXISTS partout). Appliqué par /opt/ocre-app/scripts/provision-tenant.sh sur
-- chaque nouvelle DB ocre_wsp_<slug> et ocre_wsp_<slug>_test.
--
-- Tables : _migrations, audit_log_local, clients, countries_config,
-- custom_fields_enabled, dossier_origin, match_preferences, matches, presence,
-- published_to_vitrine, settings_branding, suivi_events, suivi_journal,
-- suivi_todos, users (FK target local minimale, password en meta).

/*M!999999\- enable the sandbox mode */ 
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `_migrations` (
  `name` varchar(120) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `audit_log_local` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(64) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `target_type` varchar(64) DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_action_date` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `projet` varchar(40) NOT NULL DEFAULT 'Acheteur',
  `is_investisseur` tinyint(1) NOT NULL DEFAULT 0,
  `archived` tinyint(1) NOT NULL DEFAULT 0,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_public_slug` (`public_slug`),
  UNIQUE KEY `uniq_user_seed` (`user_id`,`seed_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_archived` (`user_id`,`archived`),
  KEY `idx_user_draft` (`user_id`,`is_draft`),
  KEY `idx_projet` (`projet`),
  KEY `idx_staged` (`user_id`,`is_staged`),
  KEY `idx_is_published` (`is_published`,`public_visible`),
  KEY `idx_deleted` (`deleted_at`),
  CONSTRAINT `fk_clients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=113 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `custom_fields_enabled` (
  `field_key` varchar(64) NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 0,
  `label_override` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `dossier_origin` (
  `dossier_id` int(10) unsigned NOT NULL,
  `original_workspace_slug` varchar(64) NOT NULL,
  `shared_at` datetime NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  `archived_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`dossier_id`),
  KEY `idx_origin` (`original_workspace_slug`),
  KEY `idx_archived` (`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `match_preferences` (
  `user_id` int(11) NOT NULL,
  `seuil_min_pct` tinyint(3) unsigned NOT NULL DEFAULT 70,
  `tolerance_budget_pct` int(10) unsigned NOT NULL DEFAULT 10,
  `tolerance_surface_pct` int(10) unsigned NOT NULL DEFAULT 25,
  `tolerance_terrain_pct` int(10) unsigned NOT NULL DEFAULT 50,
  `tolerance_chambres` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `matches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dossier_a_id` int(11) NOT NULL,
  `dossier_b_id` int(11) NOT NULL,
  `score_pct` tinyint(3) unsigned NOT NULL,
  `source` enum('interne','archive','externe') NOT NULL DEFAULT 'interne',
  `status` enum('non_vu','vu','pertinent','surveiller','ecarte') NOT NULL DEFAULT 'non_vu',
  `owner_user_ids` text NOT NULL,
  `criteres_matched` text DEFAULT NULL,
  `source_externe_url` text DEFAULT NULL,
  `source_externe_site` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `seen_at` datetime DEFAULT NULL,
  `classified_at` datetime DEFAULT NULL,
  `classified_by_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pair` (`dossier_a_id`,`dossier_b_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_owners` (`owner_user_ids`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `presence` (
  `workspace_user_id` int(10) unsigned NOT NULL,
  `dossier_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `user_display` varchar(120) DEFAULT NULL,
  `last_seen` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`dossier_id`,`user_id`),
  KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `published_to_vitrine` (
  `bien_id` int(10) unsigned NOT NULL,
  `published_at` datetime NOT NULL DEFAULT current_timestamp(),
  `public_slug` varchar(120) NOT NULL,
  PRIMARY KEY (`bien_id`),
  UNIQUE KEY `uniq_public_slug` (`public_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `settings_branding` (
  `k` varchar(64) NOT NULL,
  `v` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `suivi_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('rdv','appel','visite','email','autre') NOT NULL DEFAULT 'rdv',
  `title` varchar(255) NOT NULL,
  `when_at` datetime NOT NULL,
  `duration_min` int(11) NOT NULL DEFAULT 60,
  `location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('planned','done','cancelled') NOT NULL DEFAULT 'planned',
  `reminder_min_before` int(11) NOT NULL DEFAULT 60,
  `notified` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_when` (`user_id`,`when_at`),
  KEY `idx_client` (`client_id`),
  KEY `idx_pending` (`status`,`when_at`,`notified`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `suivi_journal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ts` datetime NOT NULL,
  `kind` enum('note','appel_entrant','appel_sortant','email_envoye','email_recu','visite','sms') NOT NULL DEFAULT 'note',
  `content` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client_ts` (`client_id`,`ts`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `suivi_todos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `due_at` datetime DEFAULT NULL,
  `done` tinyint(4) NOT NULL DEFAULT 0,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `notified` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_due` (`user_id`,`due_at`),
  KEY `idx_client` (`client_id`),
  KEY `idx_pending` (`done`,`due_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(191) DEFAULT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET FOREIGN_KEY_CHECKS=1;
