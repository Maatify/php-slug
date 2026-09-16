-- Maatify Slug RC1 package-owned schema.
-- Package FKs and uniqueness constraints protect package-owned identity and idempotency.
-- Exact strings use binary collations; timestamps require DATETIME(6); writes rely on caller transactions/savepoints.
-- Host-owned tables are intentionally not referenced: Host FKs are nonapplicable to this package schema.
-- The application validates enum and cross-field invariants before writes.

CREATE TABLE maa_slug_scopes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate identity for the package-owned Scope row',
    namespace VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Opaque exact Scope namespace identity',
    locale_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'Nullable Scope locale encoded as empty storage value',
    context_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'Nullable Scope context encoded as empty storage value',
    profile_key VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Locked built-in slug profile key for the Scope',
    created_at DATETIME(6) NOT NULL COMMENT 'UTC creation timestamp with six microseconds',
    updated_at DATETIME(6) NOT NULL COMMENT 'UTC last-update timestamp with six microseconds',
    PRIMARY KEY (id),
    CONSTRAINT uk_scope_identity UNIQUE (namespace, locale_key, context_key),
    INDEX ix_scope_profile (profile_key)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_bin
  COMMENT='Canonical slug scopes owned by the package';

CREATE TABLE maa_slug_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate identity for the package-owned Binding row',
    scope_id BIGINT UNSIGNED NOT NULL COMMENT 'Owning package Scope identity',
    entity_type VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Opaque Host entity type',
    entity_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Opaque Host entity key',
    current_registry_id BIGINT UNSIGNED NULL COMMENT 'Current canonical registry claim identity when present',
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Binding lifecycle status token',
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Optimistic binding revision incremented by lifecycle mutation',
    history_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Next append-only history sequence for the Binding',
    created_at DATETIME(6) NOT NULL COMMENT 'UTC creation timestamp with six microseconds',
    updated_at DATETIME(6) NOT NULL COMMENT 'UTC last-update timestamp with six microseconds',
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
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate identity for the keyed operation record',
    operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Exact idempotent operation identity key',
    operation_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Operation command type token',
    request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Exact request contract fingerprint',
    result_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Result Snapshot family token',
    result_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Version of the stored Result Snapshot contract',
    result_snapshot LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Canonical immutable Result Snapshot v1 payload',
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Operation reservation or committed status token',
    created_at DATETIME(6) NOT NULL COMMENT 'UTC reservation timestamp with six microseconds',
    completed_at DATETIME(6) NULL COMMENT 'UTC final snapshot commit timestamp when committed',
    PRIMARY KEY (id),
    CONSTRAINT uk_operation_key UNIQUE (operation_key),
    INDEX ix_operation_type_created_id (operation_type, created_at, id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_bin
  COMMENT='Immutable keyed operation and Result Snapshot evidence';

CREATE TABLE maa_slug_operation_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate identity for an operation participant row',
    idempotency_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Opaque per-Binding idempotency authority key',
    operation_id BIGINT UNSIGNED NOT NULL COMMENT 'Owning keyed operation identity',
    binding_id BIGINT UNSIGNED NOT NULL COMMENT 'Participating package Binding identity',
    participant_role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Operation participant role token',
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
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate identity for a slug registry claim',
    scope_id BIGINT UNSIGNED NOT NULL COMMENT 'Owning package Scope identity',
    binding_id BIGINT UNSIGNED NOT NULL COMMENT 'Owning package Binding identity',
    slug VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Exact canonical or historical slug value',
    claim_role VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Registry claim role token',
    claimed_at DATETIME(6) NOT NULL COMMENT 'UTC first-claim timestamp with six microseconds',
    updated_at DATETIME(6) NOT NULL COMMENT 'UTC last-claim-update timestamp with six microseconds',
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
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate identity for an append-only history event',
    binding_id BIGINT UNSIGNED NOT NULL COMMENT 'Binding whose lifecycle event is recorded',
    sequence_no BIGINT UNSIGNED NOT NULL COMMENT 'Monotonic history sequence within the Binding',
    event_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'History event type token',
    scope_namespace_snapshot VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Exact Scope namespace at event time',
    scope_locale_snapshot VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Scope locale snapshot with empty nullable encoding',
    scope_context_snapshot VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Scope context snapshot with empty nullable encoding',
    entity_type_snapshot VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Opaque Host entity type at event time',
    entity_key_snapshot VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Opaque Host entity key at event time',
    slug_snapshot VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Slug value observed by the history event',
    previous_slug_snapshot VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Previous slug value observed by the history event',
    claim_role_snapshot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Claim role observed by the history event',
    previous_claim_role_snapshot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Previous claim role observed by the history event',
    related_namespace VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Related participant Scope namespace when applicable',
    related_locale VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Related participant Scope locale when applicable',
    related_context VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Related participant Scope context when applicable',
    related_entity_type VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Related participant Host entity type when applicable',
    related_entity_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Related participant Host entity key when applicable',
    operation_id BIGINT UNSIGNED NULL COMMENT 'Related keyed operation identity when available',
    operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Related exact operation key when available',
    actor_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Opaque actor identity from the audit context',
    reason VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Preserved audit reason text without normalization',
    correlation_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Opaque correlation identity from the audit context',
    occurred_at DATETIME(6) NOT NULL COMMENT 'UTC event timestamp with six microseconds',
    original_occurred_at DATETIME(6) NULL COMMENT 'Original UTC event timestamp for replayed evidence',
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
