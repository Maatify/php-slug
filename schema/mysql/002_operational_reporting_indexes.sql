-- Additive operational-read indexes for the published RC1 schema.
-- This asset is applied after 001_slug_rc1.sql during fresh installation or upgrade.

ALTER TABLE maa_slug_bindings
    ADD INDEX ix_binding_scope_status_id (scope_id, status, id);

ALTER TABLE maa_slug_history
    ADD INDEX ix_history_occurred_id (occurred_at, id),
    ADD INDEX ix_history_scope_occurred_id (
        scope_namespace_snapshot,
        scope_locale_snapshot,
        scope_context_snapshot,
        occurred_at,
        id
    );
