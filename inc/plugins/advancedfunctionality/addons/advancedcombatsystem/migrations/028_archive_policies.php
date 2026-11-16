<?php
if (!defined('IN_MYBB')) {die('No direct access');}
return ['version'=>'028_archive_policies','tables'=>['archive_policies'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_archive_policies` (
 encounter_id bigint unsigned NOT NULL,
 session_id bigint unsigned NOT NULL,
 mode varchar(24) NOT NULL,
 policy_version bigint unsigned NOT NULL,
 event_id bigint unsigned NULL,
 PRIMARY KEY (encounter_id),
 KEY session_policy (session_id,encounter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
