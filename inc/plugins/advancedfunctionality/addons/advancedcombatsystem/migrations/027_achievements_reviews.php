<?php
if (!defined('IN_MYBB')) {die('No direct access');}
return ['version'=>'027_achievements_reviews','tables'=>[
'achievement_progress'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_achievement_progress` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 achievement_key varchar(96) NOT NULL,
 definition_snapshot_id bigint unsigned NOT NULL,
 subject_kind varchar(24) NOT NULL,
 subject_key varchar(96) NOT NULL,
 progress_version bigint unsigned NOT NULL,
 visibility varchar(32) NOT NULL,
 progress_json longtext NOT NULL,
 progress_hash char(64) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY achievement_subject (achievement_key,subject_kind,subject_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'achievement_events'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_achievement_events` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 progress_id bigint unsigned NOT NULL,
 session_id bigint unsigned NOT NULL,
 encounter_id bigint unsigned NOT NULL,
 source_event_id bigint unsigned NOT NULL,
 definition_snapshot_id bigint unsigned NOT NULL,
 progress_key varchar(191) NOT NULL,
 event_id bigint unsigned NULL,
 result_json longtext NOT NULL,
 result_hash char(64) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY achievement_once (progress_key),
 UNIQUE KEY contribution_once (progress_id,source_event_id),
 KEY progress_events (progress_id,id),
 KEY session_events (session_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'correction_reviews'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_correction_reviews` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 session_id bigint unsigned NOT NULL,
 encounter_id bigint unsigned NOT NULL,
 original_event_id bigint unsigned NOT NULL,
 review_version bigint unsigned NOT NULL,
 disposition varchar(24) NOT NULL,
 event_id bigint unsigned NULL,
 result_json longtext NOT NULL,
 result_hash char(64) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY source_revision (original_event_id,review_version),
 KEY session_reviews (session_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
