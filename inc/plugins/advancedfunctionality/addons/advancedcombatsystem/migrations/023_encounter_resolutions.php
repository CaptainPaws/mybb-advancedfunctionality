<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'023_encounter_resolutions','tables'=>[
'encounter_resolutions'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_encounter_resolutions` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 session_id bigint unsigned NOT NULL,
 encounter_id bigint unsigned NOT NULL,
 trigger_event_id bigint unsigned NOT NULL,
 pending_event_id bigint unsigned NOT NULL,
 config_snapshot_id bigint unsigned NOT NULL,
 candidate_json longtext NOT NULL,
 candidate_hash char(64) NOT NULL,
 status varchar(24) NOT NULL,
 terminal_event_id bigint unsigned NULL,
 completed_encounter_id bigint unsigned NULL,
 receipt_json longtext NULL,
 receipt_hash char(64) NULL,
 PRIMARY KEY (id),
 UNIQUE KEY trigger_once (trigger_event_id),
 UNIQUE KEY pending_event_once (pending_event_id),
 UNIQUE KEY completion_once (completed_encounter_id),
 KEY encounter_status (encounter_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
