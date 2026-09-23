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
    /**
     * Shared real-MySQL harness for integration tests that exercise the package schema.
     *
     * Each test receives a deterministic connection and a freshly installed schema;
     * teardown removes only tables from a database whose name is explicitly suffixed
     * with `_test`.
     */
    protected PDO $pdo;

    /** Fails clearly when MySQL support or the configured isolated test database is unavailable. */
    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_mysql')) {
            self::fail('WU-03 Integration requires the pdo_mysql extension.');
        }
        try {
            $this->pdo = $this->newTestConnection();
            $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_bin");
            $this->reinstallPackageSchema();
        } catch (Throwable $throwable) {
            self::fail('Configured integration database is unavailable or invalid: ' . $throwable::class . ': ' . $throwable->getMessage());
        }
    }

    /** Drops the package schema after the test, then lets PHPUnit finish teardown. */
    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
        parent::tearDown();
    }

    /** Supplies the fixed UTC clock used by assertions that inspect persisted timestamps. */
    protected function clock(): ClockInterface
    {
        return new FrozenClock();
    }

    /** Creates the native-prepared, binary-collation PDO connection used by this integration test. */
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

    /** Recreates, verifies, and capability-checks the package schema in a clean test database. */
    protected function reinstallPackageSchema(): void
    {
        $this->dropSchema();
        $capabilities = new PdoCapabilityGuard($this->pdo);
        (new PdoSchemaInstaller($this->pdo, $capabilities))->installPackageSchema();
        (new PdoSchemaVerifier($this->pdo))->assertInstalled();
        $capabilities->assertInstalledSchemaSupported();
    }

    /** @return array<string, string> */
    /** @return array{SLUG_TEST_DB_HOST: string, SLUG_TEST_DB_PORT: string, SLUG_TEST_DB_NAME: string, SLUG_TEST_DB_USER: string, SLUG_TEST_DB_PASSWORD: string} */
    private function testConfiguration(): array
    {
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
                throw new RuntimeException(sprintf('Required Integration environment variable %s is missing.', $variable));
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

    /** Removes package tables in reverse dependency order; callers must use a `_test` database. */
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

/** Fixed UTC clock for deterministic integration history timestamps. */
final class FrozenClock implements ClockInterface
{
    /** Returns the stable microsecond timestamp used by integration assertions. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    /** Returns UTC, the timezone required by the persistence contract. */
    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
