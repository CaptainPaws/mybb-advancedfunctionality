<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
// ACS tables only. No MyBB engine conversion, core columns or cross-engine FK.
return ['version'=>'008_publication_journal','tables'=>[
'submissions'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_submissions` (
  submission_id varchar(96) NOT NULL,
  session_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  turn_id bigint unsigned NOT NULL,
  draft_id bigint unsigned NOT NULL,
  author_uid int unsigned NOT NULL,
  tid int unsigned NOT NULL,
  pid int unsigned NULL,
  active_turn_id bigint unsigned NULL,
  status varchar(32) NOT NULL,
  last_phase varchar(32) NOT NULL,
  state_version bigint unsigned NOT NULL,
  request_hash char(64) NOT NULL,
  request_json longtext NOT NULL,
  sealed_json longtext NOT NULL,
  evidence_json longtext NOT NULL,
  result_json longtext NOT NULL,
  created_at bigint NOT NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (submission_id),
  UNIQUE KEY turn_claim (active_turn_id),
  UNIQUE KEY confirmed_pid (pid),
  KEY session_pending (session_id,status),
  KEY encounter_pending (encounter_id,status),
  KEY draft_submission (draft_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'publication_audit'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_publication_audit` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  submission_id varchar(96) NOT NULL,
  state_version bigint unsigned NOT NULL,
  from_status varchar(32) NULL,
  to_status varchar(32) NOT NULL,
  actor_uid int unsigned NOT NULL,
  reason varchar(64) NOT NULL,
  evidence_json longtext NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY transition_once (submission_id,state_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
