-- LaraBrain CI4 schema (MySQL/MariaDB)
-- Creates the two required tables for the CodeIgniter branch.

CREATE TABLE IF NOT EXISTS `app_brain_entities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ulid` CHAR(26) NOT NULL,
  `type` VARCHAR(64) NOT NULL,
  `key` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `metadata` JSON NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_brain_entities_ulid_unique` (`ulid`),
  KEY `app_brain_entities_type_idx` (`type`),
  KEY `app_brain_entities_key_idx` (`key`),
  KEY `app_brain_entities_name_idx` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_brain_relations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_entity_id` BIGINT UNSIGNED NOT NULL,
  `target_entity_id` BIGINT UNSIGNED NOT NULL,
  `relation_type` VARCHAR(64) NOT NULL,
  `metadata` JSON NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `app_brain_relations_source_idx` (`source_entity_id`),
  KEY `app_brain_relations_target_idx` (`target_entity_id`),
  KEY `app_brain_relations_type_idx` (`relation_type`),
  CONSTRAINT `app_brain_relations_source_fk`
    FOREIGN KEY (`source_entity_id`) REFERENCES `app_brain_entities` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `app_brain_relations_target_fk`
    FOREIGN KEY (`target_entity_id`) REFERENCES `app_brain_entities` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
