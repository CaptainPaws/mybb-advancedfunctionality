<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
// ACS-only terminal storage. Previous DDL and MyBB engines remain unchanged; logical links, no FK.
return ['version'=>'009_combat_commit','tables'=>[
'post_links'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_post_links` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  submission_id varchar(96) NOT NULL,
  session_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  turn_id bigint unsigned NOT NULL,
  pid int unsigned NOT NULL,
  tid int unsigned NOT NULL,
  fid int unsigned NOT NULL,
  author_uid int unsigned NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY submission_once (submission_id),
  UNIQUE KEY post_once (pid),
  UNIQUE KEY turn_once (turn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'combat_results'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_combat_results` (
  submission_id varchar(96) NOT NULL,
  turn_id bigint unsigned NOT NULL,
  event_id bigint unsigned NOT NULL,
  engine_version varchar(64) NOT NULL,
  result_hash char(64) NOT NULL,
  payload_json longtext NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (submission_id),
  UNIQUE KEY result_turn (turn_id),
  UNIQUE KEY result_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'combat_receipts'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_combat_receipts` (
  submission_id varchar(96) NOT NULL,
  turn_id bigint unsigned NOT NULL,
  pid int unsigned NOT NULL,
  request_hash char(64) NOT NULL,
  sealed_hash char(64) NOT NULL,
  receipt_hash char(64) NOT NULL,
  receipt_json longtext NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (submission_id),
  UNIQUE KEY receipt_turn (turn_id),
  UNIQUE KEY receipt_post (pid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'outbox'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_outbox` (
  message_key varchar(191) NOT NULL,
  submission_id varchar(96) NOT NULL,
  kind varchar(64) NOT NULL,
  payload_hash char(64) NOT NULL,
  payload_json longtext NOT NULL,
  status varchar(24) NOT NULL,
  attempts int NOT NULL DEFAULT 0,
  available_at bigint NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (message_key),
  UNIQUE KEY submission_kind (submission_id,kind),
  KEY pending_messages (status,available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
