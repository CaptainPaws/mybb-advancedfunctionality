<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'012_barrier_pools','tables'=>['barrier_pools'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_barrier_pools` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  effect_id bigint unsigned NOT NULL,
  pool_kind varchar(24) NOT NULL,
  strength bigint NOT NULL,
  maximum_strength bigint NOT NULL,
  barrier_priority int NOT NULL,
  applied_order bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  pool_version bigint unsigned NOT NULL,
  absorbs_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY effect_pool (effect_id),
  KEY absorb_order (status,barrier_priority,applied_order,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
,'barrier_targets'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_barrier_targets` (
  pool_id bigint unsigned NOT NULL,
  entity_id bigint unsigned NOT NULL,
  PRIMARY KEY (pool_id,entity_id),
  KEY entity_pools (entity_id,pool_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
