<?php
if(!defined('IN_MYBB')){die('No direct access');}
return ['version'=>'018_cooperation','tables'=>[
'objectives'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_objectives` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  object_entity_id bigint unsigned NOT NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  objective_version bigint unsigned NOT NULL,
  completed_event_id bigint unsigned NULL,
  progress_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY object_objective (object_entity_id),
  KEY encounter_objectives (encounter_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'contributions'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_contributions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  objective_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  source_event_id bigint unsigned NOT NULL,
  contribution_key varchar(191) NOT NULL,
  payload_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY contribution_once (contribution_key),
  KEY objective_history (objective_id,id),
  KEY source_contributions (source_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
