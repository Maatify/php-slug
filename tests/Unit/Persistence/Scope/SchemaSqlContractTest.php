<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Persistence\Scope;

use PHPUnit\Framework\TestCase;

final class SchemaSqlContractTest extends TestCase
{
    public function testSingleSchemaContainsPublishedTablesAndConstraintsWithoutModernFeatureDependencies(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 4) . '/schema/mysql/001_slug_rc1.sql');
        self::assertIsString($sql);
        foreach ([
            'maa_slug_scopes',
            'maa_slug_bindings',
            'maa_slug_operations',
            'maa_slug_operation_bindings',
            'maa_slug_registry',
            'maa_slug_history',
            'uk_scope_identity',
            'uk_binding_identity',
            'uk_registry_scope_slug',
            'uk_registry_binding_slug',
            'uk_history_binding_sequence',
            'uk_operation_key',
            'uk_operation_binding_idempotency',
            'uk_operation_binding_pair',
            'uk_operation_binding_role',
            'ENGINE=InnoDB',
            'utf8mb4_bin',
            'ascii_bin',
            'DATETIME(6)',
        ] as $required) {
            self::assertStringContainsString($required, $sql);
        }
        self::assertStringNotContainsString('GENERATED ALWAYS', $sql);
        self::assertDoesNotMatchRegularExpression('/\bCHECK\s*\(/i', $sql);
        self::assertStringNotContainsString('JSON', $sql);
    }
}
