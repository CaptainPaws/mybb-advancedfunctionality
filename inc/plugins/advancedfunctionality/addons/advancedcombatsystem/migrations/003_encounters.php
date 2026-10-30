<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
// Architecture §5, preparation only. 001/002 remain immutable.
return ['version'=>'003_encounters','tables'=>[
'encounters'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_encounters` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  encounter_version bigint unsigned NOT NULL,
  next_event_sequence bigint unsigned NOT NULL DEFAULT 1,
  round_number int NOT NULL DEFAULT 0,
  start_operation_id varchar(96) NULL,
  visibility varchar(24) NOT NULL,
  config_snapshot_id bigint unsigned NOT NULL,
  config_json longtext NOT NULL,
  paused_from varchar(24) NULL,
  created_at bigint NOT NULL,
  completed_at bigint NULL,
  PRIMARY KEY (id),
  UNIQUE KEY start_once (start_operation_id),
  KEY session_state (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'sides'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_sides` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  name varchar(191) NOT NULL,
  side_version bigint unsigned NOT NULL,
  policy_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY encounter_side (encounter_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'side_relationships'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_side_relationships` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  side_a bigint unsigned NOT NULL,
  side_b bigint unsigned NOT NULL,
  relationship varchar(24) NOT NULL,
  relation_version bigint unsigned NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY side_pair (encounter_id,side_a,side_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'participants'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_participants` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  side_id bigint unsigned NULL,
  participation varchar(24) NOT NULL,
  registered_sequence bigint unsigned NOT NULL,
  participant_version bigint unsigned NOT NULL,
  visibility_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY encounter_entity (encounter_id,entity_id),
  KEY encounter_side_state (encounter_id,side_id,participation),
  KEY entity_history (entity_id,encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
]];
