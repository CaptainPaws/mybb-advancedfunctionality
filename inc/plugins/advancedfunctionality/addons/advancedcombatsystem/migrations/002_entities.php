<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
// Exact Architecture §5 DDL; no encounters, participants, turns or AP.
return ['version'=>'002_entities', 'tables'=>[
'control_groups'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_control_groups` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  owner_uid int unsigned NULL,
  kind varchar(24) NOT NULL,
  group_version bigint unsigned NOT NULL,
  config_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY session_owner (session_id,owner_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'entities'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_entities` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  control_group_id bigint unsigned NULL,
  kind varchar(24) NOT NULL,
  owner_uid int unsigned NULL,
  sheet_id bigint unsigned NULL,
  template_snapshot_id bigint unsigned NOT NULL,
  source_entity_id bigint unsigned NULL,
  spawn_key varchar(191) NULL,
  name varchar(191) NOT NULL,
  entity_version bigint unsigned NOT NULL,
  definition_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY spawn_once (spawn_key),
  KEY session_group (session_id,control_group_id),
  KEY owner_sheet (owner_uid,sheet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'entity_states'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_entity_states` (
  entity_id bigint unsigned NOT NULL,
  life_state varchar(24) NOT NULL,
  hp bigint NOT NULL,
  hp_max bigint NOT NULL,
  state_version bigint unsigned NOT NULL,
  stats_json longtext NOT NULL,
  layers_json longtext NOT NULL,
  PRIMARY KEY (entity_id),
  KEY life (life_state,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'resources'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_resources` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint unsigned NOT NULL,
  resource_key varchar(64) NOT NULL,
  current_value double NOT NULL,
  maximum_value double NOT NULL,
  is_integer tinyint NOT NULL,
  resource_version bigint unsigned NOT NULL,
  definition_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY entity_resource (entity_id,resource_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'controllers'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_controllers` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  controller_uid int unsigned NULL,
  mode varchar(24) NOT NULL,
  status varchar(24) NOT NULL,
  assignment_version bigint unsigned NOT NULL,
  valid_from bigint NOT NULL,
  valid_until bigint NULL,
  permissions_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY entity_active (entity_id,status),
  KEY user_control (controller_uid,status,session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
]];
