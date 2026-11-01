<?php
if(!defined('IN_MYBB')){die('No direct access');}
return ['version'=>'007_sealed_actions','tables'=>['sealed_actions'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_sealed_actions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  draft_id bigint unsigned NOT NULL,
  action_index int NOT NULL,
  actor_entity_id bigint unsigned NOT NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  seal_operation_key varchar(191) NOT NULL,
  ap_cost int NOT NULL,
  zero_ap_limit_key varchar(96) NULL,
  status varchar(24) NOT NULL,
  sealed_hash char(64) NOT NULL,
  payload_json longtext NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY seal_once (seal_operation_key),
  UNIQUE KEY immutable_position (draft_id,action_index),
  KEY actor_actions (actor_entity_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
,'rolls'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_rolls` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  sealed_action_id bigint unsigned NULL,
  causal_key varchar(191) NOT NULL,
  check_kind varchar(32) NOT NULL,
  roll_index int NOT NULL,
  rng_version varchar(64) NOT NULL,
  value_json longtext NOT NULL,
  created_at bigint NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY check_once (causal_key,check_kind,roll_index),
  KEY action_rolls (sealed_action_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
