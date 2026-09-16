-- Maatify Slug RC1 package-owned schema.
-- The application validates enum and cross-field invariants before writes.

CREATE TABLE maa_slug_scopes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    namespace VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    locale_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
    context_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
    profile_key VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uk_scope_identity UNIQUE (namespace, locale_key, context_key),
    INDEX ix_scope_profile (profile_key)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_bin
  COMMENT='Canonical slug scopes owned by the package';

CREATE TABLE maa_slug_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    entity_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    current_registry_id BIGINT UNSIGNED NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    history_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uk_binding_identity UNIQUE (scope_id, entity_type, entity_key),
    CONSTRAINT fk_binding_scope FOREIGN KEY (scope_id) REFERENCES maa_slug_scopes (id) ON DELETE RESTRICT,
    CONSTRAINT uk_binding_id_scope UNIQUE (id, scope_id),
    INDEX ix_binding_current_registry (current_registry_id),
    INDEX ix_binding_status (status)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_bin
  COMMENT='Package-owned entity bindings and current-claim pointer';

CREATE TABLE maa_slug_operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    result_snapshot LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uk_operation_key UNIQUE (operation_key),
    INDEX ix_operation_type_created_id (operation_type, created_at, id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_bin
  COMMENT='Immutable keyed operation and Result Snapshot evidence';

CREATE TABLE maa_slug_operation_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idempotency_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    binding_id BIGINT UNSIGNED NOT NULL,
    participant_role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_operation_binding_operation FOREIGN KEY (operation_id) REFERENCES maa_slug_operations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_operation_binding_binding FOREIGN KEY (binding_id) REFERENCES maa_slug_bindings (id) ON DELETE RESTRICT,
    CONSTRAINT uk_operation_binding_idempotency UNIQUE (binding_id, idempotency_key),
    CONSTRAINT uk_operation_binding_pair UNIQUE (operation_id, binding_id),
    CONSTRAINT uk_operation_binding_role UNIQUE (operation_id, participant_role),
    INDEX ix_operation_binding_role (operation_id, participant_role)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_bin
  COMMENT='Operation participants and per-binding idempotency authority';

CREATE TABLE maa_slug_registry (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_id BIGINT UNSIGNED NOT NULL,
    binding_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    claim_role VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    claimed_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_registry_scope FOREIGN KEY (scope_id) REFERENCES maa_slug_scopes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_registry_binding_scope FOREIGN KEY (binding_id, scope_id) REFERENCES maa_slug_bindings (id, scope_id) ON DELETE RESTRICT,
    CONSTRAINT uk_registry_scope_slug UNIQUE (scope_id, slug),
    CONSTRAINT uk_registry_binding_slug UNIQUE (binding_id, slug),
    INDEX ix_registry_binding_role (binding_id, claim_role),
    INDEX ix_registry_scope_role_id (scope_id, claim_role, id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_bin
  COMMENT='Live canonical, historical, alias and retired slug claims';

CREATE TABLE maa_slug_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    binding_id BIGINT UNSIGNED NOT NULL,
    sequence_no BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_namespace_snapshot VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_locale_snapshot VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    scope_context_snapshot VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    entity_type_snapshot VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    entity_key_snapshot VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    slug_snapshot VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    previous_slug_snapshot VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    claim_role_snapshot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_claim_role_snapshot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    related_namespace VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NULL,
    related_locale VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    related_context VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    related_entity_type VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    related_entity_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    operation_id BIGINT UNSIGNED NULL,
    operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    actor_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    reason VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    correlation_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    occurred_at DATETIME(6) NOT NULL,
    original_occurred_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_history_binding FOREIGN KEY (binding_id) REFERENCES maa_slug_bindings (id) ON DELETE RESTRICT,
    CONSTRAINT fk_history_operation FOREIGN KEY (operation_id) REFERENCES maa_slug_operations (id) ON DELETE RESTRICT,
    CONSTRAINT uk_history_binding_sequence UNIQUE (binding_id, sequence_no),
    INDEX ix_history_binding_occurred_id (binding_id, occurred_at, id),
    INDEX ix_history_event_occurred_id (event_type, occurred_at, id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_bin
  COMMENT='Append-only binding history snapshots';
