<?php

declare(strict_types=1);

namespace Maatify\Slug\Engine;

use Closure;
use PDO;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Adoption\AdoptionService;
use Maatify\Slug\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Availability\SlugAvailabilityChecker;
use Maatify\Slug\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Factory\SlugTextServiceFactory;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\History\PdoHistoryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Operations\PdoOperationRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Query\PdoSlugManagementQueryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Scope\PdoScopeRepository;
use Maatify\Slug\Internal\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Lifecycle\SlugLifecycleService;
use Maatify\Slug\Management\SlugManagementQuery;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
use Maatify\Slug\Query\SlugQueryService as PublicSlugQueryService;
use Maatify\Slug\Resolution\SlugQueryService;
use Maatify\Slug\Transition\ScopeTransitionService;

/** Explicit construction boundary for the stateful SlugEngine. */
final class SlugEngineFactory
{
    public static function create(PDO $pdo, SlugProfileRegistryInterface $profiles, ReservedSlugPolicyInterface $reservedPolicy, ClockInterface $clock): SlugEngine
    {
        $capabilities = new PdoCapabilityGuard($pdo);
        $transactions = new PdoTransactionCoordinator($pdo);
        $scopes = new PdoScopeRepository($pdo, $profiles, $clock, $capabilities, $transactions);
        $registry = new PdoRegistryRepository($pdo, $profiles, $scopes, $clock, $capabilities);
        $history = new PdoHistoryRepository($pdo, $profiles, $capabilities);
        $operations = new PdoOperationRepository($pdo, $clock, $capabilities, $transactions);
        $candidates = new GeneratedCandidateSequence();

        $transition = new ScopeTransitionService(
            $profiles,
            $registry,
            $history,
            $operations,
            $transactions,
            $capabilities,
            $clock,
            $reservedPolicy,
            $candidates,
        );
        $adoption = new AdoptionService(
            $profiles,
            $registry,
            $history,
            $operations,
            $transactions,
            $capabilities,
            $clock,
            $reservedPolicy,
        );
        $legacyLifecycle = new SlugLifecycleService($pdo, $profiles, $reservedPolicy, $clock, $capabilities, $transactions, $candidates);
        $lifecycle = new PersistedSlugLifecycleService($legacyLifecycle, $transition, $adoption);
        $resolution = new SlugQueryService($profiles, $registry, new SlugAvailabilityChecker($registry, $profiles, $reservedPolicy), $capabilities);
        $query = new PublicSlugQueryService($resolution);
        $management = new SlugManagementQuery($registry, new PdoSlugManagementQueryRepository($pdo, $profiles), $capabilities);
        $text = SlugTextServiceFactory::create($profiles);

        $constructor = Closure::bind(
            static fn() => new SlugEngine($text, $profiles, $scopes, $lifecycle, $query, $management),
            null,
            SlugEngine::class,
        );
        return $constructor();
    }

    private function __construct() {}
}
