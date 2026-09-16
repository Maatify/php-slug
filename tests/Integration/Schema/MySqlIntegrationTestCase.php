<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Schema;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\Schema\PdoSchemaInstaller;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

abstract class MySqlIntegrationTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is not loaded.');
        }
        $dsn = getenv('SLUG_TEST_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set SLUG_TEST_DSN to run MySQL-compatible integration tests.');
        }

        try {
            $this->pdo = new PDO(
                $dsn,
                (string) (getenv('SLUG_TEST_USER') ?: ''),
                (string) (getenv('SLUG_TEST_PASSWORD') ?: ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
            $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_bin");
            $this->dropSchema();
            (new PdoSchemaInstaller($this->pdo, new PdoCapabilityGuard($this->pdo)))->installPackageSchema();
        } catch (PDOException $exception) {
            self::fail('Configured integration database is unavailable: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
        parent::tearDown();
    }

    protected function clock(): ClockInterface
    {
        return new FrozenClock();
    }

    private function dropSchema(): void
    {
        foreach ([
            'maa_slug_history',
            'maa_slug_registry',
            'maa_slug_operation_bindings',
            'maa_slug_operations',
            'maa_slug_bindings',
            'maa_slug_scopes',
        ] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }
}

final class FrozenClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
