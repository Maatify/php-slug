<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Schema;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaInstaller;
use Maatify\Slug\Lifecycle\Repository\Pdo\Schema\PdoSchemaVerifier;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

abstract class MySqlIntegrationTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_mysql')) {
            self::fail('WU-03 Integration requires the pdo_mysql extension.');
        }
        $envFile = dirname(__DIR__, 3) . '/.env.test';
        if (! is_file($envFile)) {
            self::fail('WU-03 Integration requires the local .env.test file. Copy .env.test.example and configure a dedicated *_test database.');
        }

        try {
            $this->pdo = $this->newTestConnection();
            $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_bin");
            $this->reinstallPackageSchema();
        } catch (Throwable $throwable) {
            self::fail('Configured integration database is unavailable or invalid: ' . $throwable::class . ': ' . $throwable->getMessage());
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

    protected function newTestConnection(): PDO
    {
        $configuration = $this->testConfiguration();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $configuration['SLUG_TEST_DB_HOST'],
            (int) $configuration['SLUG_TEST_DB_PORT'],
            $configuration['SLUG_TEST_DB_NAME'],
        );

        $pdo = new PDO(
            $dsn,
            $configuration['SLUG_TEST_DB_USER'],
            $configuration['SLUG_TEST_DB_PASSWORD'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_bin");

        return $pdo;
    }

    protected function reinstallPackageSchema(): void
    {
        $this->dropSchema();
        $capabilities = new PdoCapabilityGuard($this->pdo);
        (new PdoSchemaInstaller($this->pdo, $capabilities))->installPackageSchema();
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();
        $capabilities->assertInstalledSchemaSupported();
    }

    /** @return array<string, string> */
    private function testConfiguration(): array
    {
        $envFile = dirname(__DIR__, 3) . '/.env.test';
        if (! is_file($envFile)) {
            throw new RuntimeException('Required .env.test file is missing.');
        }

        $configuration = [];
        foreach ([
            'SLUG_TEST_DB_HOST',
            'SLUG_TEST_DB_PORT',
            'SLUG_TEST_DB_NAME',
            'SLUG_TEST_DB_USER',
            'SLUG_TEST_DB_PASSWORD',
        ] as $variable) {
            $value = getenv($variable);
            if (! is_string($value) || $value === '') {
                throw new RuntimeException(sprintf('Required .env.test variable %s is missing.', $variable));
            }
            $configuration[$variable] = $value;
        }

        if (! str_ends_with($configuration['SLUG_TEST_DB_NAME'], '_test')) {
            throw new RuntimeException('SLUG_TEST_DB_NAME must end with _test; production or staging databases are forbidden.');
        }
        if (filter_var($configuration['SLUG_TEST_DB_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new RuntimeException('SLUG_TEST_DB_PORT must be a valid TCP port.');
        }

        return $configuration;
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
