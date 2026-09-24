<?php

declare(strict_types=1);

namespace Maatify\Slug\Factory;

use Closure;
use PDO;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Lifecycle\Service\Adoption\AdoptionService;
use Maatify\Slug\Lifecycle\Service\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Lifecycle\Consumer\Service\SlugAvailabilityChecker;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Canonicalization\Factory\SlugTextServiceFactory;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\Repository\Pdo\History\PdoHistoryRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Operation\PdoOperationRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Binding\PdoBindingMaintenanceRepository;
use Maatify\Slug\Lifecycle\Management\Repository\Pdo\PdoSlugManagementQueryRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Registry\PdoRegistryRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Scope\PdoScopeRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Lifecycle\Service\SlugLifecycleService;
use Maatify\Slug\Lifecycle\Service\PersistedSlugLifecycleService;
use Maatify\Slug\Lifecycle\Management\Service\SlugManagementQuery;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\Consumer\Service\SlugQueryService as PublicSlugQueryService;
use Maatify\Slug\Lifecycle\Consumer\Service\Resolution\SlugQueryService;
use Maatify\Slug\Lifecycle\Service\Transition\ScopeTransitionService;
use Maatify\Slug\Lifecycle\Service\SlugScopeRegistryService;
use Maatify\Slug\Lifecycle\Factory\OperationKeyFactory;
use Maatify\Slug\Lifecycle\Mapper\OperationFingerprintMapper;

/**
 * Explicit construction boundary for the stateful SlugEngine.
 *
 * The factory wires PDO repositories, lifecycle services, read services, and
 * injected host policy/clock collaborators into one aggregate facade.
 */
final class SlugEngineFactory
{
    /** Builds a fully wired engine over the supplied PDO connection and host collaborators. */
    public static function create(PDO $pdo, SlugProfileRegistryInterface $profiles, ReservedSlugPolicyInterface $reservedPolicy, ClockInterface $clock): SlugEngine
    {
        $capabilities = new PdoCapabilityGuard($pdo);
        $transactions = new PdoTransactionCoordinator($pdo);
        $scopePersistence = new PdoScopeRepository($pdo, $profiles, $clock, $capabilities, $transactions);
        $scopes = new SlugScopeRegistryService($scopePersistence);
        $registry = new PdoRegistryRepository($pdo, $profiles, $scopePersistence, $clock, $capabilities);
        $history = new PdoHistoryRepository($pdo, $profiles, $capabilities);
        $operations = new PdoOperationRepository($pdo, $clock, $capabilities, $transactions);
        $maintenance = new PdoBindingMaintenanceRepository($pdo, $capabilities);
        $candidates = new GeneratedCandidateSequence();
        $fingerprints = new OperationFingerprintMapper();
        $operationKeys = new OperationKeyFactory();

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
            $fingerprints,
            $operationKeys,
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
            $fingerprints,
            $operationKeys,
        );
        $legacyLifecycle = new SlugLifecycleService(
            $profiles,
            $registry,
            $history,
            $operations,
            $transactions,
            $capabilities,
            $maintenance,
            $reservedPolicy,
            $clock,
            $candidates,
            $fingerprints,
            $operationKeys,
        );
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
