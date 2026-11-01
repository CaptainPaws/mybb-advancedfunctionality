<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'006_drafts','tables'=>['drafts'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_drafts` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  turn_id bigint unsigned NOT NULL,
  author_uid int unsigned NOT NULL,
  status varchar(24) NOT NULL,
  draft_version bigint unsigned NOT NULL,
  mechanics_json longtext NOT NULL,
  prose longtext NOT NULL,
  dependency_json longtext NOT NULL,
  confirmation_hash char(64) NULL,
  pid int unsigned NULL,
  updated_at bigint NOT NULL,
  PRIMARY KEY (id),
  KEY turn_drafts (turn_id,status),
  KEY author_drafts (author_uid,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
