<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
return ['version'=>'017_awareness','tables'=>['awareness'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_awareness` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  encounter_id bigint unsigned NOT NULL,
  subject_kind varchar(24) NOT NULL,
  subject_id bigint unsigned NOT NULL,
  audience_kind varchar(24) NOT NULL,
  audience_id bigint unsigned NOT NULL,
  level varchar(24) NOT NULL,
  awareness_version bigint unsigned NOT NULL,
  details_json longtext NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY audience_subject (encounter_id,subject_kind,subject_id,audience_kind,audience_id),
  KEY audience_view (encounter_id,audience_kind,audience_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
