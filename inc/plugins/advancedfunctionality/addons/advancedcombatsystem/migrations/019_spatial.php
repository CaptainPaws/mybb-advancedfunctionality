<?php
if(!defined('IN_MYBB')){die('No direct access');}
return ['version'=>'019_spatial','tables'=>[
'zones'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_zones` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  zone_key varchar(64) NOT NULL,
  zone_version bigint unsigned NOT NULL,
  name varchar(191) NOT NULL,
  policy_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY encounter_zone_key (encounter_id,zone_key),
  KEY encounter_zones (encounter_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'zone_connections'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_zone_connections` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  from_zone_id bigint unsigned NOT NULL,
  to_zone_id bigint unsigned NOT NULL,
  connection_version bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  policy_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY directed_edge (from_zone_id,to_zone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'positions'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_positions` (
  entity_id bigint unsigned NOT NULL,
  encounter_id bigint unsigned NOT NULL,
  zone_id bigint unsigned NULL,
  position_version bigint unsigned NOT NULL,
  policy_json longtext NOT NULL,
  PRIMARY KEY (entity_id,encounter_id),
  KEY zone_entities (zone_id,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL,
'engagements'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_engagements` (
  encounter_id bigint unsigned NOT NULL,
  entity_a bigint unsigned NOT NULL,
  entity_b bigint unsigned NOT NULL,
  engagement_version bigint unsigned NOT NULL,
  status varchar(24) NOT NULL,
  PRIMARY KEY (encounter_id,entity_a,entity_b),
  KEY target_contacts (entity_b,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
