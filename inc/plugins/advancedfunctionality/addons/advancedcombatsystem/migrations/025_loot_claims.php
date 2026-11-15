<?php
if (!defined('IN_MYBB')) {die('No direct access');}
return ['version'=>'025_loot_claims','tables'=>['loot_claims'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_loot_claims` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 loot_entry_id bigint unsigned NOT NULL,
 control_group_id bigint unsigned NOT NULL,
 claim varchar(24) NOT NULL,
 claim_version bigint unsigned NOT NULL,
 closed_at bigint NULL,
 PRIMARY KEY (id),
 UNIQUE KEY group_claim (loot_entry_id,control_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
