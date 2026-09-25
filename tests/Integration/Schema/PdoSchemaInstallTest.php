<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Schema;

use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaVerifier;
use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaInstaller;

final class PdoSchemaInstallTest extends MySqlIntegrationTestCase
{
    public function testCleanSchemaInstallUsesExactPackageTableSet(): void
    {
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();

        $statement = $this->pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maa_slug_operations' AND COLUMN_NAME = 'result_snapshot'");
        self::assertNotFalse($statement);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('utf8mb4_bin', $row['COLLATION_NAME']);

        $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%' AND (COLUMN_COMMENT IS NULL OR CHAR_LENGTH(TRIM(COLUMN_COMMENT)) < 8)");
        self::assertNotFalse($statement);
        self::assertSame('0', (string) $statement->fetchColumn(), 'Every package column must carry a meaningful schema comment.');
    }

    public function testSchemaInstallAndCleanupAreRepeatableTwice(): void
    {
        for ($iteration = 1; $iteration <= 2; $iteration++) {
            $this->reinstallPackageSchema();
            (new PdoSchemaVerifier($this->pdo))->assertInstalled();

            $this->pdo->exec('DROP TABLE maa_slug_history, maa_slug_registry, maa_slug_operation_bindings, maa_slug_operations, maa_slug_bindings, maa_slug_scopes');
            $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%'");
            self::assertNotFalse($statement);
            self::assertSame('0', (string) $statement->fetchColumn(), 'cleanup iteration ' . $iteration);
        }

        $this->reinstallPackageSchema();
    }

    public function testPublishedRc1ThenAdditiveOperationalAssetPassesVerification(): void
    {
        $this->dropSchemaForUpgradeProof();
        $base = file_get_contents(dirname(__DIR__, 3) . '/schema/mysql/001_slug_rc1.sql');
        $additive = file_get_contents(dirname(__DIR__, 3) . '/schema/mysql/002_operational_reporting_indexes.sql');
        self::assertIsString($base);
        self::assertIsString($additive);

        $installer = new PdoSchemaInstaller($this->pdo);
        $installer->installSql($base);
        $installer->installSql($additive);
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();
    }

    public function testMissingOperationalIndexFailsVerification(): void
    {
        $this->pdo->exec('ALTER TABLE maa_slug_history DROP INDEX ix_history_occurred_id');

        $this->expectException(\Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException::class);
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();
    }

    public function testEachRequiredOperationalIndexIsIndividuallyVerified(): void
    {
        $indexes = [
            ['maa_slug_bindings', 'ix_binding_scope_status_id', '(scope_id, status, id)'],
            ['maa_slug_history', 'ix_history_occurred_id', '(occurred_at, id)'],
            ['maa_slug_history', 'ix_history_scope_occurred_id', '(scope_namespace_snapshot, scope_locale_snapshot, scope_context_snapshot, occurred_at, id)'],
        ];
        foreach ($indexes as [$table, $index, $definition]) {
            $this->pdo->exec(sprintf('ALTER TABLE %s DROP INDEX %s', $table, $index));
            try {
                (new PdoSchemaVerifier($this->pdo))->assertInstalled();
                self::fail(sprintf('Verifier accepted missing index %s.%s.', $table, $index));
            } catch (\Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException) {
                self::addToAssertionCount(1);
            }
            $this->pdo->exec(sprintf('ALTER TABLE %s ADD INDEX %s %s', $table, $index, $definition));
        }
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();
    }

    private function dropSchemaForUpgradeProof(): void
    {
        foreach (['maa_slug_history', 'maa_slug_registry', 'maa_slug_operation_bindings', 'maa_slug_operations', 'maa_slug_bindings', 'maa_slug_scopes'] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

}
