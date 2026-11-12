<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
// Existing entity_id is the only object identity. Cross-session/event scope is checked by the reader.
return ['version'=>'016_environment_objects','tables'=>['objects'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_objects` (
  entity_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  object_version bigint unsigned NOT NULL,
  durability bigint NULL,
  interaction_json longtext NOT NULL,
  progress_json longtext NOT NULL,
  PRIMARY KEY (entity_id),
  KEY encounter_objects (encounter_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
