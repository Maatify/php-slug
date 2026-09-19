<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Schema;

use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaVerifier;

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

}
