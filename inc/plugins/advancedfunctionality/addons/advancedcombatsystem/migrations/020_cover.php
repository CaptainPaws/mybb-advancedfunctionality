<?php
if(!defined('IN_MYBB')){die('No direct access');}
return ['version'=>'020_cover','tables'=>['cover_assignments'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_cover_assignments` (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 encounter_id bigint unsigned NOT NULL,
 cover_entity_id bigint unsigned NOT NULL,
 protected_entity_id bigint unsigned NOT NULL,
 status varchar(24) NOT NULL,
 assignment_version bigint unsigned NOT NULL,
 policy_json longtext NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY cover_target (encounter_id,cover_entity_id,protected_entity_id),
 KEY entity_cover (protected_entity_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
