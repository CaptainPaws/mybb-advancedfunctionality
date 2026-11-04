<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'011_reaction_registry','tables'=>['reactions'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_reactions` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  definition_snapshot_id bigint unsigned NOT NULL,
  phase varchar(24) NOT NULL,
  priority int NOT NULL,
  registered_order bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  reaction_version bigint unsigned NOT NULL,
  config_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY phase_queue (encounter_id,phase,status,priority,registered_order),
  UNIQUE KEY encounter_order (encounter_id,registered_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
