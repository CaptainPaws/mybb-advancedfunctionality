-- PROPOSAL ONLY. Execute solely inside a new empty disposable database.
-- Base table copied from Inventory upgrade_schema at fcf973c8; no production migration.
CREATE TABLE af_advinv_items (
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid INT UNSIGNED NOT NULL,
            entity VARCHAR(32) NOT NULL DEFAULT 'equipment',
            slot VARCHAR(32) NOT NULL DEFAULT 'stash',
            subtype VARCHAR(32) NOT NULL DEFAULT '',
            kb_type VARCHAR(32) NOT NULL DEFAULT '',
            kb_key VARCHAR(64) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            icon VARCHAR(255) NOT NULL DEFAULT '',
            qty INT NOT NULL DEFAULT 1,
            meta_json MEDIUMTEXT NULL,
            created_at INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            KEY uid_entity (uid, entity),
            KEY uid_entity_subtype (uid, entity, subtype),
            KEY uid_slot (uid, slot),
            KEY uid_slot_subtype (uid, slot, subtype),
            KEY uid_kb (uid, kb_type, kb_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE af_advinv_owner_state (
    uid INT UNSIGNED NOT NULL,
    inventory_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (uid),
    CONSTRAINT owner_version CHECK (inventory_version BETWEEN 1 AND 9007199254740991)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE af_advinv_external_operations (
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
    CONSTRAINT operation_kind CHECK (operation_type IN ('reserve','consume','release','transfer','grant')),
    CONSTRAINT operation_status CHECK (status IN ('prepared','committed','released','rejected')),
    CONSTRAINT operation_intent_json CHECK (JSON_VALID(intent_json)),
    CONSTRAINT operation_result_json CHECK (JSON_VALID(result_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE af_advinv_reservations (
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
    created_at BIGINT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY reservation_once (operation_key),
    KEY item_active (item_id, status),
    KEY owner_context (owner_uid, context_system, context_key, status),
    CONSTRAINT reservation_quantities CHECK (initial_qty BETWEEN 1 AND 2147483647 AND remaining_qty <= initial_qty),
    CONSTRAINT reservation_state CHECK ((status = 'active' AND remaining_qty > 0) OR (status IN ('consumed','released') AND remaining_qty = 0)),
    CONSTRAINT reservation_version CHECK (reservation_version BETWEEN 1 AND 9007199254740991)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
