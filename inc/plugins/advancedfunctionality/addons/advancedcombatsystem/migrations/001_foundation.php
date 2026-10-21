<?php

if (!defined('IN_MYBB')) { die('No direct access'); }

// Architecture §5.1: eight stage-1 tables; nullable future links stay NULL.
return [
    'version' => '001_foundation',
    'tables' => [
        'schema_migrations' => <<<'SQL'
CREATE TABLE `{prefix}af_acs_schema_migrations` (
  version varchar(64) NOT NULL,
  status varchar(24) NOT NULL,
  step int NOT NULL,
  checksum char(64) NOT NULL,
  updated_at bigint NOT NULL,
  error_id varchar(64) NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
        'sessions' => <<<'SQL'
CREATE TABLE `{prefix}af_acs_sessions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tid int unsigned NOT NULL,
  owner_uid int unsigned NOT NULL,
  status varchar(24) NOT NULL,
  session_version bigint unsigned NOT NULL DEFAULT 1,
  next_sequence bigint unsigned NOT NULL DEFAULT 1,
  schema_version int NOT NULL,
  config_json longtext NOT NULL,
  created_at bigint NOT NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (id),
  KEY thread_status (tid,status),
  KEY owner_status (owner_uid,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
        'session_roles' => <<<'SQL'
CREATE TABLE `{prefix}af_acs_session_roles` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  uid int unsigned NOT NULL,
  role varchar(24) NOT NULL,
  permissions_json longtext NOT NULL,
  role_version bigint unsigned NOT NULL,
  expires_at bigint NULL,
  PRIMARY KEY (id),
  UNIQUE KEY member_role (session_id,uid,role),
  KEY user_sessions (uid,session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
        'snapshots' => <<<'SQL'
CREATE TABLE `{prefix}af_acs_snapshots` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  source_system varchar(32) NOT NULL,
  source_key varchar(191) NOT NULL,
  contract_id varchar(96) NOT NULL,
  contract_version varchar(64) NOT NULL,
  schema_version int NOT NULL,
  content_hash char(64) NOT NULL,
  payload_json longtext NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  KEY source_version (source_system,contract_id,contract_version),
  KEY session_hash (session_id,content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
        'operations' => <<<'SQL'
CREATE TABLE `{prefix}af_acs_operations` (
  operation_key varchar(191) NOT NULL,
  session_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NULL,
  kind varchar(64) NOT NULL,
  external_system varchar(32) NULL,
  external_ref varchar(191) NULL,
  payload_hash char(64) NOT NULL,
  status varchar(32) NOT NULL,
  operation_version bigint unsigned NOT NULL,
  request_json longtext NOT NULL,
  result_json longtext NOT NULL,
  retry_at bigint NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (operation_key),
  KEY pending_operations (status,retry_at),
  KEY external_lookup (external_system,external_ref),
  KEY session_operations (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
        'events' => <<<'SQL'
CREATE TABLE `{prefix}af_acs_events` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  session_sequence bigint unsigned NOT NULL,
  encounter_id bigint unsigned NULL,
  event_sequence bigint unsigned NULL,
  operation_key varchar(191) NOT NULL,
  event_type varchar(64) NOT NULL,
  source_entity_id bigint unsigned NULL,
  target_entity_id bigint unsigned NULL,
  actor_uid int unsigned NOT NULL,
  submission_id varchar(96) NULL,
  parent_event_id bigint unsigned NULL,
  cause_event_id bigint unsigned NULL,
  correction_of_event_id bigint unsigned NULL,
  engine_version varchar(64) NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY session_order (session_id,session_sequence),
  UNIQUE KEY encounter_order (encounter_id,event_sequence),
  UNIQUE KEY operation_once (operation_key),
  KEY entity_events (target_entity_id,id),
  KEY submission_events (submission_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
        'event_details' => <<<'SQL'
CREATE TABLE `{prefix}af_acs_event_details` (
  event_id bigint unsigned NOT NULL,
  schema_version int NOT NULL,
  numeric_policy varchar(64) NOT NULL,
  payload_json longtext NOT NULL,
  visibility_json longtext NOT NULL,
  content_hash char(64) NOT NULL,
  PRIMARY KEY (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
        'diagnostics' => <<<'SQL'
CREATE TABLE `{prefix}af_acs_diagnostics` (
  error_id varchar(64) NOT NULL,
  request_id varchar(96) NOT NULL,
  submission_id varchar(96) NULL,
  encounter_id bigint unsigned NULL,
  category varchar(32) NOT NULL,
  operation varchar(64) NOT NULL,
  phase varchar(64) NOT NULL,
  created_at bigint NOT NULL,
  details_json longtext NOT NULL,
  PRIMARY KEY (error_id),
  KEY request_errors (request_id),
  KEY encounter_errors (encounter_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
    ],
];
