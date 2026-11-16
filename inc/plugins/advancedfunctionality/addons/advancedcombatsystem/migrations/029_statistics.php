<?php
if (!defined('IN_MYBB')) {die('No direct access');}
// ACS source projection plus IC-STATS consumer projection/receipts; no combat journal copy.
return ['version'=>'029_statistics','tables'=>[
'statistics'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_statistics` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 encounter_id bigint unsigned NOT NULL,
 subject_kind varchar(24) NOT NULL,
 subject_key varchar(96) NOT NULL,
 aggregate_version bigint unsigned NOT NULL,
 visibility varchar(24) NOT NULL,
 source_sequence bigint unsigned NOT NULL,
 payload_json longtext NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY encounter_subject (encounter_id,subject_kind,subject_key),
 KEY subject_history (subject_kind,subject_key,encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'statistics_consumers'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_statistics_consumers` (
 encounter_id bigint unsigned NOT NULL,
 aggregate_version bigint unsigned NOT NULL,
 payload_hash char(64) NOT NULL,
 payload_json longtext NOT NULL,
 visibility varchar(24) NOT NULL,
 PRIMARY KEY (encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'statistics_receipts'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_statistics_receipts` (
 operation_key varchar(191) NOT NULL,
 encounter_id bigint unsigned NOT NULL,
 aggregate_version bigint unsigned NOT NULL,
 payload_hash char(64) NOT NULL,
 receipt_json longtext NOT NULL,
 PRIMARY KEY (operation_key),
 KEY encounter_version (encounter_id,aggregate_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
