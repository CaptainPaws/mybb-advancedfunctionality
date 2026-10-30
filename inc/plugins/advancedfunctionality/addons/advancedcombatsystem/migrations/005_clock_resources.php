<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'005_clock_resources','tables'=>['cooldowns'=> <<<'SQL'
CREATE TABLE `{prefix}af_acs_cooldowns` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint unsigned NOT NULL,
  ability_key varchar(96) NOT NULL,
  group_key varchar(96) NOT NULL,
  scope varchar(24) NOT NULL,
  remaining_turns int NOT NULL,
  last_boundary_key varchar(191) NULL,
  cooldown_version bigint unsigned NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY entity_ability_group (entity_id,ability_key,group_key),
  KEY entity_scope (entity_id,scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
