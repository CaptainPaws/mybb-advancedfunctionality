<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
// Architecture §5: roster foundation. Previous migration checksums are immutable.
return ['version'=>'004_round_roster','tables'=>[
'rounds'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_rounds` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  round_number int NOT NULL,
  status varchar(24) NOT NULL,
  round_version bigint unsigned NOT NULL,
  opened_at bigint NOT NULL,
  closed_at bigint NULL,
  PRIMARY KEY (id),
  UNIQUE KEY encounter_round (encounter_id,round_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'turns'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_turns` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  round_id bigint unsigned NOT NULL,
  control_group_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  ap_budget int NOT NULL,
  ap_spent int NOT NULL,
  seal_quota_spent int NOT NULL,
  pre_round_ap_commitment int NOT NULL,
  turn_version bigint unsigned NOT NULL,
  deadline_at bigint NULL,
  remaining_deadline_seconds bigint NULL,
  closed_by_submission varchar(96) NULL,
  roster_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY group_round (round_id,control_group_id),
  KEY due_turn (status,deadline_at),
  KEY group_turns (control_group_id,round_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'entity_turns'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_entity_turns` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  turn_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  start_done tinyint NOT NULL DEFAULT 0,
  end_done tinyint NOT NULL DEFAULT 0,
  reaction_used int NOT NULL DEFAULT 0,
  entity_turn_version bigint unsigned NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY individual_boundary (turn_id,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
]];
