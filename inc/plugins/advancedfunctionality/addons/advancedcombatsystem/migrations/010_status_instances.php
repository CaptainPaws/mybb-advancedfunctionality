<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'010_status_instances','tables'=>['effects'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_effects` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NULL,
  source_entity_id bigint unsigned NOT NULL,
  target_entity_id bigint unsigned NOT NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  effect_key varchar(96) NOT NULL,
  scope varchar(24) NOT NULL,
  status varchar(24) NOT NULL,
  stacks int NOT NULL,
  remaining_duration int NOT NULL,
  duration_unit varchar(24) NOT NULL,
  applied_order bigint unsigned NOT NULL,
  effect_version bigint unsigned NOT NULL,
  parent_effect_id bigint unsigned NULL,
  payload_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY target_status (target_entity_id,status),
  KEY encounter_order (encounter_id,status,applied_order),
  KEY source_scope (source_entity_id,effect_key,status),
  UNIQUE KEY session_order (session_id,applied_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
