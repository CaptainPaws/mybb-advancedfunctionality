<?php
if (!defined('IN_MYBB')) {die('No direct access');}
return ['version'=>'014_custody','tables'=>['custody'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_custody` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  inventory_item_id bigint unsigned NOT NULL,
  owner_uid int unsigned NOT NULL,
  custodian_entity_id bigint unsigned NULL,
  active_user_entity_id bigint unsigned NULL,
  status varchar(24) NOT NULL,
  custody_version bigint unsigned NOT NULL,
  operation_key varchar(191) NOT NULL,
  payload_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY session_item (session_id,inventory_item_id),
  UNIQUE KEY custody_once (operation_key),
  KEY custody_obligations (session_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
