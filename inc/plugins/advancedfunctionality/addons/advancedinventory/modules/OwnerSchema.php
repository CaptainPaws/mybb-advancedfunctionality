<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Explicit owner DDL plan. Not run by install/activate or an ordinary request. */
final class AF_ADVINV_OwnerSchema
{
    public static function statements(string $prefix): array
    {
        if (!preg_match('/\A[A-Za-z0-9_]{0,30}\z/D', $prefix)) throw new InvalidArgumentException('INV_INVALID_PREFIX');
        return [
            "CREATE TABLE `{$prefix}af_advinv_grant_sources` (
                source_system VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                source_key VARBINARY(191) NOT NULL,
                recipient_uid INT UNSIGNED NOT NULL,
                operation_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                result_json LONGTEXT NOT NULL,
                result_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                PRIMARY KEY(source_system,source_key), UNIQUE KEY source_operation(operation_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            <<<SQL
CREATE TABLE `{$prefix}af_advinv_owner_state` (
    uid INT UNSIGNED NOT NULL,
    inventory_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (uid),
    CONSTRAINT `{$prefix}advinv_owner_version` CHECK (inventory_version BETWEEN 1 AND 9007199254740991)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
            <<<SQL
CREATE TABLE `{$prefix}af_advinv_external_operations` (
    operation_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_uid INT UNSIGNED NOT NULL,
    context_system VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    context_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    owner_uid INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    intent_json LONGTEXT COLLATE utf8mb4_bin NOT NULL,
    result_json LONGTEXT COLLATE utf8mb4_bin NOT NULL,
    result_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (operation_key),
    KEY owner_operations (owner_uid, status),
    KEY item_operations (item_id, status),
    CONSTRAINT `{$prefix}advinv_operation_kind` CHECK (operation_type IN ('reserve','consume','release','transfer','grant')),
    CONSTRAINT `{$prefix}advinv_operation_status` CHECK (status IN ('prepared','committed','released','rejected')),
    CONSTRAINT `{$prefix}advinv_operation_intent_json` CHECK (JSON_VALID(intent_json)),
    CONSTRAINT `{$prefix}advinv_operation_result_json` CHECK (JSON_VALID(result_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL,
            <<<SQL
CREATE TABLE `{$prefix}af_advinv_reservations` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_uid INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    initial_qty INT UNSIGNED NOT NULL,
    remaining_qty INT UNSIGNED NOT NULL,
    context_system VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    context_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reservation_version BIGINT UNSIGNED NOT NULL,
    prepared_binding_json LONGTEXT NULL,
    prepared_binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY reservation_once (operation_key),
    KEY item_active (item_id, status),
    KEY owner_context (owner_uid, context_system, context_key, status),
    CONSTRAINT `{$prefix}advinv_reservation_quantities` CHECK (initial_qty BETWEEN 1 AND 2147483647 AND remaining_qty <= initial_qty),
    CONSTRAINT `{$prefix}advinv_reservation_state` CHECK ((status = 'active' AND remaining_qty > 0) OR (status IN ('consumed','released') AND remaining_qty = 0)),
    CONSTRAINT `{$prefix}advinv_reservation_version` CHECK (reservation_version BETWEEN 1 AND 9007199254740991)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL,
        ];
    }
    /** Explicit operator upgrade, resumable by column; never invoked by a request/activation. */
    public static function upgradePrepared(mysqli $db,string $prefix):void
    {
        if(!preg_match('/\A[A-Za-z0-9_]{0,30}\z/D',$prefix))throw new InvalidArgumentException('INV_INVALID_PREFIX');
        require_once __DIR__.'/OwnerProtocol.php';(new AF_ADVINV_OwnerProtocol($db))->assertExternalBoundary();
        $table=$prefix.'af_advinv_reservations';
        foreach(['prepared_binding_json'=>'LONGTEXT NULL','prepared_binding_hash'=>'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL'] as $name=>$definition){
            $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->bind_param('ss',$table,$name);$s->execute();$exists=$s->get_result()->fetch_assoc();$s->close();
            if(!$exists)$db->query('ALTER TABLE `'.$table.'` ADD COLUMN `'.$name.'` '.$definition);
        }
    }

}
