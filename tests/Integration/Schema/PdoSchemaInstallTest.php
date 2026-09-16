<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Schema;

use Maatify\Slug\Infrastructure\Persistence\PDO\Schema\PdoSchemaVerifier;

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
    }

}
