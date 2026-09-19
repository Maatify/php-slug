<?php

declare(strict_types=1);

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingCriteria;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ExampleReservedSlugPolicy implements ReservedSlugPolicyInterface
{
    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        return false;
    }
}

final class ExampleUtcClock implements ClockInterface
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

$pdo = null;

try {
    $configuration = databaseConfiguration();
    $schemaFile = dirname(__DIR__) . '/schema/mysql/001_slug_rc1.sql';
    if (! is_file($schemaFile)) {
        throw new RuntimeException('The package schema asset is missing.');
    }

    $pdo = connectToDatabase($configuration);
    installPackageSchema($pdo, $schemaFile);

    $profileKey = new SlugProfileKey('ascii-v1');
    $profiles = SlugProfileRegistryFactory::createBuiltIn();
    $scopeProfile = new ScopeProfileRequestDTO(new SlugScope('example', null, null), $profileKey);
    $identity = new BindingIdentityDTO(
        $scopeProfile,
        new EntityReference('article', 'example-1'),
    );
    $engine = SlugEngineFactory::create($pdo, $profiles, new ExampleReservedSlugPolicy(), new ExampleUtcClock());

    $assigned = $engine->assignExact(new AssignExactCommand(
        $identity,
        'hello-world',
        null,
        new AuditContextDTO(
            actorKey: 'examples',
            reason: 'persisted lifecycle example',
            correlationKey: 'example-persisted-lifecycle',
        ),
    ));
    expect($assigned->after->state->currentSlug?->value === 'hello-world', 'Assignment did not persist hello-world.');

    $resolved = $engine->resolve(new ResolutionCriteria($scopeProfile, 'hello-world'));
    expect($resolved->matchedSlug?->value === 'hello-world', 'Resolution did not match hello-world.');
    expect($resolved->currentSlug?->value === 'hello-world', 'Resolution returned an unexpected current slug.');
    expect($resolved->entity?->entityType === 'article', 'Resolution returned an unexpected entity type.');
    expect($resolved->entity?->entityKey === 'example-1', 'Resolution returned an unexpected entity key.');

    $binding = $engine->getBinding(new BindingCriteria($identity));
    if (! $binding instanceof BindingDTO) {
        throw new RuntimeException('Management read did not return the binding.');
    }
    expect($binding->state->currentSlug?->value === 'hello-world', 'Management read returned an unexpected current slug.');

    echo "PERSISTED_LIFECYCLE_EXAMPLE=hello-world PASS\n";
} finally {
    if ($pdo instanceof PDO) {
        dropPackageSchema($pdo);
    }
}

/** @return array{SLUG_TEST_DB_HOST: string, SLUG_TEST_DB_PORT: string, SLUG_TEST_DB_NAME: string, SLUG_TEST_DB_USER: string, SLUG_TEST_DB_PASSWORD: string} */
function databaseConfiguration(): array
{
    $configuration = [];
    foreach (['SLUG_TEST_DB_HOST', 'SLUG_TEST_DB_PORT', 'SLUG_TEST_DB_NAME', 'SLUG_TEST_DB_USER', 'SLUG_TEST_DB_PASSWORD'] as $variable) {
        $value = getenv($variable);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Required environment variable %s is missing.', $variable));
        }
        $configuration[$variable] = $value;
    }

    return $configuration;
}

/** @param array{SLUG_TEST_DB_HOST: string, SLUG_TEST_DB_PORT: string, SLUG_TEST_DB_NAME: string, SLUG_TEST_DB_USER: string, SLUG_TEST_DB_PASSWORD: string} $configuration */
function connectToDatabase(array $configuration): PDO
{
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $configuration['SLUG_TEST_DB_HOST'],
            $configuration['SLUG_TEST_DB_PORT'],
            $configuration['SLUG_TEST_DB_NAME'],
        ),
        $configuration['SLUG_TEST_DB_USER'],
        $configuration['SLUG_TEST_DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_bin');

    return $pdo;
}

function installPackageSchema(PDO $pdo, string $schemaFile): void
{
    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        throw new RuntimeException('The package-owned schema could not be read.');
    }
    $statements = preg_split('/;\s*(?:\r?\n|\z)/', $sql);
    if ($statements === false) {
        throw new RuntimeException('The package-owned schema could not be split.');
    }

    $executed = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
        $executed++;
    }
    if ($executed !== 6) {
        throw new RuntimeException(sprintf('Expected six schema statements, executed %d.', $executed));
    }
}

function dropPackageSchema(PDO $pdo): void
{
    foreach (['maa_slug_history', 'maa_slug_registry', 'maa_slug_operation_bindings', 'maa_slug_operations', 'maa_slug_bindings', 'maa_slug_scopes'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
}

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
