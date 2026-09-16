<?php

declare(strict_types=1);

$packageRoot = realpath(dirname(__DIR__, 2));
if ($packageRoot === false) {
    throw new RuntimeException('Unable to resolve the package root.');
}

$configuration = databaseConfiguration();
assertRequiredExtensions();

for ($run = 1; $run <= 2; $run++) {
    $consumerRoot = createTemporaryDirectory();
    $database = null;

    try {
        $database = connectToDatabase($configuration);
        dropPackageSchema($database);
        assertNoPackageTables($database);

        writeConsumerComposerJson($consumerRoot, $packageRoot);
        writeConsumerWorkflow($consumerRoot);

        $composerHome = $consumerRoot . '/composer-home';
        $composerCache = $consumerRoot . '/composer-cache';
        $environment = childEnvironment([
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_CACHE_DIR' => $composerCache,
            'SLUG_HARNESS_RUN' => (string) $run,
        ]);
        mkdirOrFail($composerHome);
        mkdirOrFail($composerCache);

        runProcess(
            ['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress'],
            $consumerRoot,
            $environment,
            'Composer dependency resolution/install',
        );
        if (! is_file($consumerRoot . '/vendor/autoload.php')) {
            throw new RuntimeException('Composer did not create the consumer production autoload.');
        }

        runProcess(
            [PHP_BINARY, $consumerRoot . '/consumer.php'],
            $consumerRoot,
            $environment,
            'consumer public workflow',
        );

        dropPackageSchema($database);
        assertNoPackageTables($database);
        echo sprintf("CONSUMER_HARNESS_RUN=%d PASS\n", $run);
    } finally {
        try {
            if ($database instanceof PDO) {
                dropPackageSchema($database);
                assertNoPackageTables($database);
            }
        } finally {
            $database = null;
            removeTemporaryDirectory($consumerRoot);
        }
    }
}

echo "CONSUMER_HARNESS PASS (2 clean consumer and database runs)\n";

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

    if (! str_ends_with($configuration['SLUG_TEST_DB_NAME'], '_test')) {
        throw new RuntimeException('SLUG_TEST_DB_NAME must end with _test.');
    }
    if (filter_var($configuration['SLUG_TEST_DB_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
        throw new RuntimeException('SLUG_TEST_DB_PORT must be a valid TCP port.');
    }

    return $configuration;
}

function assertRequiredExtensions(): void
{
    foreach (['intl', 'mbstring', 'pdo', 'pdo_mysql'] as $extension) {
        if (! extension_loaded($extension)) {
            throw new RuntimeException(sprintf('Consumer Harness requires the %s extension.', $extension));
        }
    }
    if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Consumer Harness requires the PDO MySQL driver.');
    }
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
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_bin');

    return $pdo;
}

function dropPackageSchema(PDO $pdo): void
{
    foreach (['maa_slug_history', 'maa_slug_registry', 'maa_slug_operation_bindings', 'maa_slug_operations', 'maa_slug_bindings', 'maa_slug_scopes'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
}

function assertNoPackageTables(PDO $pdo): void
{
    $statement = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%'");
    if ($statement === false || (int) $statement->fetchColumn() !== 0) {
        throw new RuntimeException('Consumer Harness cleanup left package tables behind.');
    }
}

function writeConsumerComposerJson(string $consumerRoot, string $packageRoot): void
{
    $composer = [
        'name' => 'maatify/php-slug-consumer-harness',
        'description' => 'Temporary external consumer used by the php-slug verification harness.',
        'repositories' => [[
            'type' => 'path',
            'url' => $packageRoot,
            'canonical' => true,
            'options' => ['symlink' => false],
        ]],
        'require' => ['maatify/php-slug' => '*'],
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
        'config' => [
            'optimize-autoloader' => true,
            'sort-packages' => true,
        ],
    ];
    writeFile($consumerRoot . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function writeConsumerWorkflow(string $consumerRoot): void
{
    $workflow = <<<'PHP'
<?php

declare(strict_types=1);

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Command\AssignExactCommand;
use Maatify\Slug\Command\ChangeExactCommand;
use Maatify\Slug\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Criteria\CurrentSlugCriteria;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\CanonicalSlugDTO;
use Maatify\Slug\DTO\CurrentSlugDTO;
use Maatify\Slug\DTO\GeneratedSlugDTO;
use Maatify\Slug\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\DTO\SlugResolutionDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Enum\ChangeTypeEnum;
use Maatify\Slug\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Enum\MatchKindEnum;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Factory\SlugTextServiceFactory;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Scope\Value\SlugScope;
require __DIR__ . '/vendor/autoload.php';

expect(! class_exists('Maatify\\Slug\\Tests\\Integration\\Schema\\MySqlIntegrationTestCase'), 'The package test namespace was loaded into the consumer.');

final class AcceptAllReservedSlugPolicy implements ReservedSlugPolicyInterface
{
    public function isReserved(SlugScope $scope, \Maatify\Slug\Identity\Slug $slug): bool
    {
        return false;
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

if (($argv[1] ?? '') === '--consumer-race-worker') {
    runRaceWorker($argv[2] ?? '', $argv[3] ?? '');
    exit(0);
}

$configuration = databaseConfiguration();
$run = getenv('SLUG_HARNESS_RUN');
$schemaFile = installedPackageSchema();
if (! is_string($run) || $run === '') {
    throw new RuntimeException('Consumer Harness runtime configuration is incomplete.');
}
echo sprintf("CONSUMER_SCHEMA_PATH=%s PASS\n", $schemaFile);

$pdo = null;
try {
    $pdo = connectToDatabase($configuration);
    installPackageSchema($pdo, $schemaFile);

    $profileKey = new SlugProfileKey('ascii-v1');
    $profiles = SlugProfileRegistryFactory::createBuiltIn();
    $text = SlugTextServiceFactory::create($profiles);

    $generated = $text->generateFromSource($profileKey, 'Consumer Product');
    expect($generated instanceof GeneratedSlugDTO, 'Source generation did not return the public GeneratedSlugDTO.');
    expect($generated->slug->value === 'consumer-product', 'Source generation returned an unexpected slug.');

    $canonical = $text->canonicalizeClaim($profileKey, 'Consumer-Page');
    expect($canonical instanceof CanonicalSlugDTO, 'Claim canonicalization did not return the public CanonicalSlugDTO.');
    expect($canonical->slug->value === 'consumer-page', 'Claim canonicalization returned an unexpected slug.');

    $lookup = $text->canonicalizeLookup($profileKey, 'CONSUMER-PAGE');
    expect($lookup instanceof LookupCanonicalizationDTO, 'Lookup canonicalization did not return the public LookupCanonicalizationDTO.');
    expect($lookup->canonicality === InputFormCanonicalityEnum::NON_CANONICAL, 'Lookup canonicalization did not classify non-canonical input.');

    $scopeProfile = new ScopeProfileRequestDTO(new SlugScope('consumer-harness-' . $run, null, null), $profileKey);
    $identity = new BindingIdentityDTO($scopeProfile, new EntityReference('product', 'consumer-entity-' . $run));
    $engine = SlugEngineFactory::create($pdo, $profiles, new AcceptAllReservedSlugPolicy(), new FrozenClock());

    $assigned = $engine->assignExact(new AssignExactCommand(
        $identity,
        'Consumer-Page',
        null,
        new AuditContextDTO(actorKey: 'consumer-harness', reason: 'consumer verification', idempotencyKey: 'consumer-assign-' . $run),
    ));
    expect($assigned instanceof SlugMutationResultDTO, 'Assignment did not return the public SlugMutationResultDTO.');
    expect(! $assigned->replayed, 'The clean assignment unexpectedly replayed.');
    expect($assigned->after->state->currentSlug?->value === 'consumer-page', 'Assignment did not persist the canonical current slug.');
    expect($assigned->after->state->revision === 1, 'Assignment did not produce revision 1.');
    expect($assigned->changeType === ChangeTypeEnum::ASSIGNED, 'Assignment returned an unexpected change type.');

    $changed = $engine->changeExact(new ChangeExactCommand(
        $identity,
        'Updated-Page',
        1,
        new AuditContextDTO(actorKey: 'consumer-harness', reason: 'consumer verification', idempotencyKey: 'consumer-change-' . $run),
    ));
    expect($changed instanceof SlugMutationResultDTO, 'Change did not return the public SlugMutationResultDTO.');
    expect($changed->after->state->currentSlug?->value === 'updated-page', 'Change did not persist the new canonical current slug.');
    expect($changed->previousSlug?->value === 'consumer-page', 'Change did not expose the previous slug.');
    expect($changed->after->state->revision === 2, 'Change did not produce revision 2.');
    expect($changed->changeType === ChangeTypeEnum::CHANGED, 'Change returned an unexpected change type.');

    $resolved = $engine->resolve(new \Maatify\Slug\Criteria\ResolutionCriteria($scopeProfile, 'UPDATED-PAGE'));
    expect($resolved instanceof SlugResolutionDTO, 'Resolution did not return the public SlugResolutionDTO.');
    expect($resolved->inputCanonicality === InputFormCanonicalityEnum::NON_CANONICAL, 'Resolution did not expose lookup canonicalization.');
    expect($resolved->matchKind === MatchKindEnum::CURRENT, 'Resolution did not match the current claim.');
    expect($resolved->matchedSlug?->value === 'updated-page', 'Resolution returned an unexpected matched slug.');
    expect($resolved->entity?->entityKey === 'consumer-entity-' . $run, 'Resolution returned an unexpected public entity reference.');
    expect($resolved->bindingRevision === 2, 'Resolution returned an unexpected binding revision.');

    $current = $engine->getCurrent(new \Maatify\Slug\Criteria\CurrentSlugCriteria($identity));
    expect($current?->binding->state->currentSlug?->value === 'updated-page', 'Public current lookup did not observe persisted state.');
    expect($current?->revision === 2, 'Public current lookup returned an unexpected revision.');

    runConcurrencyProof($pdo, $engine, $profileKey, $run, $configuration);
} finally {
    if ($pdo instanceof PDO) {
        dropPackageSchema($pdo);
    }
}

echo sprintf("CONSUMER_WORKFLOW_RUN=%s PASS\n", $run);

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

function installedPackageSchema(): string
{
    if (! class_exists(\Composer\InstalledVersions::class)) {
        throw new RuntimeException('Composer runtime metadata is unavailable in the consumer.');
    }
    $packagePath = \Composer\InstalledVersions::getInstallPath('maatify/php-slug');
    if (! is_string($packagePath) || $packagePath === '') {
        throw new RuntimeException('Composer did not report the maatify/php-slug installation path.');
    }
    $schemaFile = $packagePath . '/schema/mysql/001_slug_rc1.sql';
    if (! is_file($schemaFile)) {
        throw new RuntimeException('The installed maatify/php-slug dependency does not contain its MySQL schema.');
    }
    return $schemaFile;
}

function connectToDatabase(array $configuration): PDO
{
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $configuration['SLUG_TEST_DB_HOST'], $configuration['SLUG_TEST_DB_PORT'], $configuration['SLUG_TEST_DB_NAME']),
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
    expect($executed === 6, 'The package schema did not execute its six expected statements.');
}

/** @param array{SLUG_TEST_DB_HOST: string, SLUG_TEST_DB_PORT: string, SLUG_TEST_DB_NAME: string, SLUG_TEST_DB_USER: string, SLUG_TEST_DB_PASSWORD: string} $configuration */
function runConcurrencyProof(PDO $pdo, SlugEngine $engine, SlugProfileKey $profileKey, string $run, array $configuration): void
{
    if (! function_exists('proc_open')) {
        throw new RuntimeException('Consumer concurrency proof requires proc_open.');
    }

    $namespace = 'consumer-race-' . $run;
    $barrier = __DIR__ . '/consumer-race-barrier-' . $run . '-' . bin2hex(random_bytes(8));
    if (! mkdir($barrier, 0700)) {
        throw new RuntimeException('Unable to create the external consumer race barrier.');
    }

    $workers = ['first', 'second'];
    $raceScopeProfile = new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), $profileKey);
    foreach ($workers as $worker) {
        $seedIdentity = new BindingIdentityDTO(
            $raceScopeProfile,
            new EntityReference('product', 'consumer-race-' . $run . '-' . $worker),
        );
        $seeded = $engine->assignExact(new AssignExactCommand(
            $seedIdentity,
            'race-old-' . $worker,
            null,
            new AuditContextDTO(actorKey: 'consumer-race', reason: 'consumer concurrency setup', idempotencyKey: 'consumer-race-seed-' . $run . '-' . $worker),
        ));
        expect($seeded instanceof SlugMutationResultDTO, 'Consumer race setup did not return the public mutation result.');
        expect($seeded->after->state->revision === 1, 'Consumer race setup did not create revision 1.');
    }
    $processes = [];
    $pipes = [];
    $closed = [];
    try {
        foreach ($workers as $worker) {
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open(
                [PHP_BINARY, __FILE__, '--consumer-race-worker', $run, $worker],
                $descriptors,
                $workerPipes,
                __DIR__,
                raceWorkerEnvironment($configuration, $barrier, $namespace),
                ['bypass_shell' => true],
            );
            if (! is_resource($process)) {
                throw new RuntimeException(sprintf('Unable to start consumer race worker %s.', $worker));
            }
            $processes[$worker] = $process;
            $pipes[$worker] = $workerPipes;
            $closed[$worker] = false;
        }

        waitForRaceWorkers($barrier, $workers, $processes);
        if (file_put_contents($barrier . '/GO', "GO\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to release the external consumer race barrier.');
        }

        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($pipes[$worker][1]);
            $stderr = stream_get_contents($pipes[$worker][2]);
            fclose($pipes[$worker][1]);
            fclose($pipes[$worker][2]);
            $exitCode = proc_close($processes[$worker]);
            $closed[$worker] = true;
            if ($exitCode !== 0) {
                throw new RuntimeException(sprintf('Consumer race worker %s failed with exit code %d: %s', $worker, $exitCode, trim((string) $stderr)));
            }
            try {
                $result = json_decode(trim((string) $stdout), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $throwable) {
                throw new RuntimeException(sprintf('Consumer race worker %s returned invalid evidence: %s', $worker, $throwable->getMessage()), 0, $throwable);
            }
            if (! is_array($result)) {
                throw new RuntimeException(sprintf('Consumer race worker %s returned non-array evidence.', $worker));
            }
            $results[] = $result;
        }

        $winners = array_values(array_filter($results, static fn(array $result): bool => ($result['status'] ?? null) === 'WINNER'));
        $losers = array_values(array_filter($results, static fn(array $result): bool => ($result['status'] ?? null) === 'LOSER'));
        expect(count($winners) === 1, 'Consumer concurrency proof did not produce exactly one winner.');
        expect(count($losers) === 1, 'Consumer concurrency proof did not produce exactly one loser.');
        expect(($winners[0]['class'] ?? null) === SlugMutationResultDTO::class, 'Consumer race winner did not return the public SlugMutationResultDTO.');
        expect(($winners[0]['slug'] ?? null) === 'race-slug', 'Consumer race winner returned an unexpected slug.');
        expect(($winners[0]['revision'] ?? null) === 2, 'Consumer race winner returned an unexpected revision.');
        expect(($winners[0]['changeType'] ?? null) === ChangeTypeEnum::CHANGED->value, 'Consumer race winner returned an unexpected change type.');
        expect(($losers[0]['exception'] ?? null) === SlugAlreadyClaimedException::class, 'Consumer race loser did not return SlugAlreadyClaimedException.');

        $scopeId = scalarInt($pdo, 'SELECT id FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => $namespace]);
        expect($scopeId > 0, 'Consumer race scope was not persisted.');
        expect(scalarInt($pdo, 'SELECT COUNT(*) FROM maa_slug_registry WHERE scope_id = :scope_id AND slug = :slug', ['scope_id' => $scopeId, 'slug' => 'race-slug']) === 1, 'Consumer race did not persist exactly one claim.');
        expect(scalarInt($pdo, 'SELECT COUNT(*) FROM maa_slug_registry WHERE scope_id = :scope_id', ['scope_id' => $scopeId]) === 3, 'Consumer race left an unexpected claim count.');
        expect(scalarInt($pdo, 'SELECT COUNT(*) FROM maa_slug_bindings WHERE scope_id = :scope_id', ['scope_id' => $scopeId]) === 2, 'Consumer race left partial or duplicate Binding state.');
        expect(scalarInt($pdo, "SELECT COUNT(*) FROM maa_slug_bindings WHERE scope_id = :scope_id AND status = 'ACTIVE' AND current_registry_id IS NOT NULL", ['scope_id' => $scopeId]) === 2, 'Consumer race did not preserve both active Binding states.');
        expect(scalarInt($pdo, 'SELECT COUNT(*) FROM maa_slug_history WHERE binding_id IN (SELECT id FROM maa_slug_bindings WHERE scope_id = :scope_id)', ['scope_id' => $scopeId]) === 3, 'Consumer race left partial history state.');
        expect(scalarInt($pdo, 'SELECT COUNT(*) FROM maa_slug_operation_bindings AS participants INNER JOIN maa_slug_bindings AS bindings ON bindings.id = participants.binding_id INNER JOIN maa_slug_operations AS operations ON operations.id = participants.operation_id WHERE bindings.scope_id = :scope_id', ['scope_id' => $scopeId]) === 3, 'Consumer race left partial operation state.');

        $winnerIdentity = new BindingIdentityDTO(
            $raceScopeProfile,
            new EntityReference('product', (string) ($winners[0]['entity'] ?? '')),
        );
        $current = $engine->getCurrent(new CurrentSlugCriteria($winnerIdentity));
        expect($current instanceof CurrentSlugDTO, 'Consumer race winner was not readable through the public current-query API.');
        expect($current->binding->state->currentSlug?->value === 'race-slug', 'Public current-query API returned unexpected consumer race state.');
        $loserWorker = (string) ($losers[0]['worker'] ?? '');
        expect(in_array($loserWorker, $workers, true), 'Consumer race loser did not identify its worker.');
        $loserIdentity = new BindingIdentityDTO(
            $raceScopeProfile,
            new EntityReference('product', 'consumer-race-' . $run . '-' . $loserWorker),
        );
        $loserCurrent = $engine->getCurrent(new CurrentSlugCriteria($loserIdentity));
        expect($loserCurrent instanceof CurrentSlugDTO, 'Consumer race loser state was not readable through the public current-query API.');
        expect($loserCurrent->binding->state->currentSlug?->value === 'race-old-' . $loserWorker, 'Consumer race loser did not retain its original current slug.');
        expect($loserCurrent->revision === 1, 'Consumer race loser state was partially mutated.');
        $resolved = $engine->resolve(new \Maatify\Slug\Criteria\ResolutionCriteria($raceScopeProfile, 'RACE-SLUG'));
        expect($resolved instanceof SlugResolutionDTO, 'Consumer race resolution did not return the public SlugResolutionDTO.');
        expect($resolved->entity?->entityKey === (string) ($winners[0]['entity'] ?? ''), 'Public resolution API returned the wrong consumer race winner.');
        expect($resolved->matchedSlug?->value === 'race-slug', 'Public resolution API returned an unexpected consumer race slug.');
        echo "CONSUMER_CONCURRENCY PASS (winner=1 loser=SlugAlreadyClaimedException claims=1 clean=1)\n";
    } finally {
        foreach ($workers as $worker) {
            if (($closed[$worker] ?? true) || ! is_resource($processes[$worker] ?? null)) {
                continue;
            }
            $state = proc_get_status($processes[$worker]);
            if (($state['running'] ?? false) === true) {
                proc_terminate($processes[$worker]);
            }
            foreach ([1, 2] as $pipeIndex) {
                if (isset($pipes[$worker][$pipeIndex]) && is_resource($pipes[$worker][$pipeIndex])) {
                    fclose($pipes[$worker][$pipeIndex]);
                }
            }
            proc_close($processes[$worker]);
        }
        removeRaceBarrier($barrier, $workers);
    }
}

/** @param list<string> $workers
 *  @param array<string, resource> $processes
 */
function waitForRaceWorkers(string $barrier, array $workers, array $processes): void
{
    $deadline = microtime(true) + 20.0;
    while (true) {
        $ready = true;
        foreach ($workers as $worker) {
            $readyFile = $barrier . '/READY-' . $worker;
            if (! is_file($readyFile)) {
                $ready = false;
                $state = proc_get_status($processes[$worker]);
                if (($state['running'] ?? false) === false) {
                    throw new RuntimeException(sprintf('Consumer race worker %s exited before reaching the external barrier.', $worker));
                }
            }
        }
        if ($ready) {
            return;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Consumer race workers did not reach the external barrier within 20 seconds.');
        }
        usleep(10000);
    }
}

/** @param array{SLUG_TEST_DB_HOST: string, SLUG_TEST_DB_PORT: string, SLUG_TEST_DB_NAME: string, SLUG_TEST_DB_USER: string, SLUG_TEST_DB_PASSWORD: string} $configuration
 *  @return array<string, string>
 */
function raceWorkerEnvironment(array $configuration, string $barrier, string $namespace): array
{
    return [
        'SLUG_TEST_DB_HOST' => $configuration['SLUG_TEST_DB_HOST'],
        'SLUG_TEST_DB_PORT' => $configuration['SLUG_TEST_DB_PORT'],
        'SLUG_TEST_DB_NAME' => $configuration['SLUG_TEST_DB_NAME'],
        'SLUG_TEST_DB_USER' => $configuration['SLUG_TEST_DB_USER'],
        'SLUG_TEST_DB_PASSWORD' => $configuration['SLUG_TEST_DB_PASSWORD'],
        'SLUG_RACE_BARRIER_DIR' => $barrier,
        'SLUG_RACE_NAMESPACE' => $namespace,
    ];
}

function runRaceWorker(string $run, string $worker): void
{
    if ($run === '' || ! in_array($worker, ['first', 'second'], true)) {
        throw new RuntimeException('Consumer race worker arguments are invalid.');
    }
    $barrier = getenv('SLUG_RACE_BARRIER_DIR');
    $namespace = getenv('SLUG_RACE_NAMESPACE');
    if (! is_string($barrier) || $barrier === '' || ! is_string($namespace) || $namespace === '') {
        throw new RuntimeException('Consumer race barrier configuration is incomplete.');
    }

    $configuration = databaseConfiguration();
    $pdo = connectToDatabase($configuration);
    $profiles = SlugProfileRegistryFactory::createBuiltIn();
    $profileKey = new SlugProfileKey('ascii-v1');
    $engine = SlugEngineFactory::create($pdo, $profiles, new AcceptAllReservedSlugPolicy(), new FrozenClock());
    $entityKey = 'consumer-race-' . $run . '-' . $worker;
    $identity = new BindingIdentityDTO(
        new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), $profileKey),
        new EntityReference('product', $entityKey),
    );
    if (file_put_contents($barrier . '/READY-' . $worker, "READY\n", LOCK_EX) === false) {
        throw new RuntimeException('Consumer race worker could not publish its external barrier readiness.');
    }

    $deadline = microtime(true) + 20.0;
    while (! is_file($barrier . '/GO')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Consumer race worker did not receive the external barrier release.');
        }
        usleep(10000);
    }

    try {
        $changed = $engine->changeExact(new ChangeExactCommand(
            $identity,
            'Race-Slug',
            1,
            new AuditContextDTO(actorKey: 'consumer-race', reason: 'consumer concurrency verification', idempotencyKey: 'consumer-race-change-' . $run . '-' . $worker),
        ));
        expect($changed instanceof SlugMutationResultDTO, 'Consumer race winner did not return the public mutation result.');
        echo json_encode([
            'worker' => $worker,
            'entity' => $entityKey,
            'status' => 'WINNER',
            'class' => $changed::class,
            'slug' => $changed->after->state->currentSlug?->value,
            'revision' => $changed->after->state->revision,
            'changeType' => $changed->changeType->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (SlugAlreadyClaimedException) {
        echo json_encode([
            'worker' => $worker,
            'status' => 'LOSER',
            'exception' => SlugAlreadyClaimedException::class,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } finally {
        $pdo = null;
    }
}

/** @param array<string, int|string> $parameters */
function scalarInt(PDO $pdo, string $sql, array $parameters = []): int
{
    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Consumer race state query could not be prepared.');
    }
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
}

/** @param list<string> $workers */
function removeRaceBarrier(string $barrier, array $workers): void
{
    foreach ($workers as $worker) {
        $readyFile = $barrier . '/READY-' . $worker;
        if (is_file($readyFile)) {
            unlink($readyFile);
        }
    }
    $goFile = $barrier . '/GO';
    if (is_file($goFile)) {
        unlink($goFile);
    }
    if (is_dir($barrier)) {
        rmdir($barrier);
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
PHP;
    writeFile($consumerRoot . '/consumer.php', $workflow);
}

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 */
function runProcess(array $command, string $workingDirectory, array $environment, string $label): void
{
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
        $environment,
        ['bypass_shell' => true],
    );
    if (! is_resource($process)) {
        throw new RuntimeException(sprintf('Unable to start %s.', $label));
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf("%s failed with exit code %d.\n%s%s", $label, $exitCode, trim((string) $stdout), trim((string) $stderr)));
    }
    if ($stdout !== '') {
        echo $stdout;
    }
}

/**
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function childEnvironment(array $overrides): array
{
    $environment = getenv();
    foreach ($overrides as $name => $value) {
        $environment[$name] = $value;
    }
    return $environment;
}

function createTemporaryDirectory(): string
{
    $temporaryPath = tempnam(sys_get_temp_dir(), 'php-slug-consumer-');
    if ($temporaryPath === false || ! unlink($temporaryPath) || ! mkdir($temporaryPath, 0700)) {
        throw new RuntimeException('Unable to create a clean temporary consumer root.');
    }
    return $temporaryPath;
}

function mkdirOrFail(string $path): void
{
    if (! mkdir($path, 0700, true) && ! is_dir($path)) {
        throw new RuntimeException(sprintf('Unable to create temporary directory %s.', $path));
    }
}

function writeFile(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException(sprintf('Unable to write temporary file %s.', $path));
    }
}

function removeTemporaryDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $entries = glob($path . '/*', GLOB_NOSORT);
    $hiddenEntries = glob($path . '/.[!.]*', GLOB_NOSORT);
    if ($entries === false || $hiddenEntries === false) {
        throw new RuntimeException(sprintf('Unable to inspect temporary directory %s.', $path));
    }
    foreach (array_merge($entries, $hiddenEntries) as $childPath) {
        if (is_dir($childPath)) {
            removeTemporaryDirectory($childPath);
        } else {
            unlink($childPath);
        }
    }
    rmdir($path);
}
