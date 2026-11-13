<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'021_prepared_actions','tables'=>[
'prepared_actions'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_prepared_actions` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 session_id bigint unsigned NOT NULL,
 encounter_id bigint unsigned NOT NULL,
 entity_id bigint unsigned NOT NULL,
 control_group_id bigint unsigned NOT NULL,
 owner_uid bigint unsigned NULL,
 source_sealed_action_id bigint unsigned NOT NULL,
 source_entity_turn_id bigint unsigned NOT NULL,
 source_event_id bigint unsigned NULL,
 status varchar(24) NOT NULL,
 prepared_version bigint unsigned NOT NULL,
 expires_boundary varchar(191) NOT NULL,
 cost_state varchar(24) NOT NULL,
 contract_hash char(64) NOT NULL,
 contract_json longtext NOT NULL,
 terminal_boundary varchar(191) NULL,
 PRIMARY KEY (id),
 UNIQUE KEY preparation_once (source_sealed_action_id),
 KEY encounter_triggers (encounter_id,status),
 KEY source_lifetime (entity_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
,'prepared_executions'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_prepared_executions` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 prepared_action_id bigint unsigned NOT NULL,
 trigger_event_id bigint unsigned NOT NULL,
 operation_key varchar(191) NOT NULL,
 status varchar(24) NOT NULL,
 result_json longtext NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY trigger_once (prepared_action_id,trigger_event_id),
 UNIQUE KEY prepared_operation (operation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
