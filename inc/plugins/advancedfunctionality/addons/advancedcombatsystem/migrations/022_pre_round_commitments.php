<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'022_pre_round_commitments','tables'=>[
'pre_round_commitments'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_pre_round_commitments` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 session_id bigint unsigned NOT NULL,
 encounter_id bigint unsigned NOT NULL,
 entity_id bigint unsigned NOT NULL,
 control_group_id bigint unsigned NOT NULL,
 prepared_action_id bigint unsigned NOT NULL,
 source_sealed_action_id bigint unsigned NOT NULL,
 source_event_id bigint unsigned NULL,
 operation_key varchar(191) NOT NULL,
 paid_ap int NOT NULL,
 ability_pin_json longtext NOT NULL,
 applied_round_id bigint unsigned NULL,
 PRIMARY KEY (id),
 UNIQUE KEY prepared_once (prepared_action_id),
 UNIQUE KEY seal_once (source_sealed_action_id),
 UNIQUE KEY commitment_operation (operation_key),
 KEY encounter_group (encounter_id,control_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
