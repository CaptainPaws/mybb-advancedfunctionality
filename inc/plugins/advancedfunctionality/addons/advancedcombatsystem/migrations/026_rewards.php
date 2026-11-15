<?php
if (!defined('IN_MYBB')) {die('No direct access');}
return ['version'=>'026_rewards','tables'=>['rewards'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_rewards` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 session_id bigint unsigned NOT NULL,
 encounter_id bigint unsigned NOT NULL,
 reward_key varchar(191) NOT NULL,
 recipient_key varchar(96) NOT NULL,
 reward_type varchar(32) NOT NULL,
 status varchar(24) NOT NULL,
 reward_version bigint unsigned NOT NULL,
 operation_key varchar(191) NOT NULL,
 payload_json longtext NOT NULL,
 payload_hash char(64) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY recipient_reward (reward_key,recipient_key,reward_type),
 UNIQUE KEY reward_operation (operation_key),
 KEY pending_rewards (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
