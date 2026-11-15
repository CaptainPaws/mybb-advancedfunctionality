<?php
if (!defined('IN_MYBB')) {die('No direct access');}
return ['version'=>'024_loot_pools','tables'=>[
'loot_pools'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_loot_pools` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 session_id bigint unsigned NOT NULL,
 encounter_id bigint unsigned NOT NULL,
 generation_key varchar(191) NOT NULL,
 outcome_id varchar(191) NOT NULL,
 source_key varchar(191) NOT NULL,
 definition_snapshot_id bigint unsigned NOT NULL,
 status varchar(24) NOT NULL,
 pool_version bigint unsigned NOT NULL,
 mode varchar(24) NOT NULL,
 event_id bigint unsigned NULL,
 result_json longtext NOT NULL,
 result_hash char(64) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY generation_once (generation_key),
 UNIQUE KEY outcome_source_once (encounter_id,source_key),
 KEY session_open_loot (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
,'loot_entries'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_loot_entries` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 pool_id bigint unsigned NOT NULL,
 item_snapshot_id bigint unsigned NOT NULL,
 qty bigint NOT NULL,
 status varchar(24) NOT NULL,
 recipient_group_id bigint unsigned NULL,
 recipient_uid int unsigned NULL,
 allocation_version bigint unsigned NOT NULL,
 issue_operation_key varchar(191) NULL,
 result_json longtext NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY loot_issue_once (issue_operation_key),
 KEY pool_entries (pool_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
