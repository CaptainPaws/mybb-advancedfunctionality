<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'013_equipment','tables'=>['equipment'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_equipment` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  slot_key varchar(64) NOT NULL,
  inventory_item_id bigint unsigned NULL,
  item_snapshot_id bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  equipment_version bigint unsigned NOT NULL,
  source_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY combat_slot (session_id,entity_id,slot_key),
  KEY item_usage (inventory_item_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
