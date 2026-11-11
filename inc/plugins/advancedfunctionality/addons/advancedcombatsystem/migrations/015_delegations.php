<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
// Architecture §5: typed scope/start/actions/reason/audit refs reside in scope_json.
return ['version'=>'015_delegations','tables'=>['delegations'=><<<'SQL'
CREATE TABLE `{prefix}af_acs_delegations` (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint unsigned NOT NULL,
  owner_uid int unsigned NOT NULL,
  delegate_uid int unsigned NOT NULL,
  status varchar(24) NOT NULL,
  delegation_version bigint unsigned NOT NULL,
  valid_until bigint NULL,
  scope_json longtext NOT NULL,
  PRIMARY KEY (id),
  KEY delegate_scope (session_id,delegate_uid,status),
  KEY owner_scope (owner_uid,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL
]];
