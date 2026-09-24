<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Support;

use PDO;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\Mapper\OperationFingerprintMapper;
use Maatify\Slug\Lifecycle\Factory\OperationKeyFactory;
use Maatify\Slug\Lifecycle\Repository\Pdo\Binding\PdoBindingMaintenanceRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\History\PdoHistoryRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Operation\PdoOperationRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Registry\PdoRegistryRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Scope\PdoScopeRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Lifecycle\Service\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Lifecycle\Service\SlugLifecycleService;

/**
 * Test-only composition root for the core persisted lifecycle service.
 *
 * The caller supplies the real PDO connection, profile registry, reservation
 * policy, and clock; this factory wires the package repositories, capability
 * guard, transaction coordinator, and deterministic operation collaborators.
 */
final class SlugLifecycleServiceFactory
{
    /** Builds the complete persistence-backed lifecycle graph used by integration and system tests. */
    public static function create(
        PDO $pdo,
        SlugProfileRegistryInterface $profiles,
        ReservedSlugPolicyInterface $reservedPolicy,
        ClockInterface $clock,
    ): SlugLifecycleService {
        $capabilities = new PdoCapabilityGuard($pdo);
        $transactions = new PdoTransactionCoordinator($pdo);
        $scopes = new PdoScopeRepository($pdo, $profiles, $clock, $capabilities, $transactions);
        $registry = new PdoRegistryRepository($pdo, $profiles, $scopes, $clock, $capabilities);
        $history = new PdoHistoryRepository($pdo, $profiles, $capabilities);
        $operations = new PdoOperationRepository($pdo, $clock, $capabilities, $transactions);
        $maintenance = new PdoBindingMaintenanceRepository($pdo, $capabilities);

        return new SlugLifecycleService(
            $profiles,
            $registry,
            $history,
            $operations,
            $transactions,
            $capabilities,
            $maintenance,
            $reservedPolicy,
            $clock,
            new GeneratedCandidateSequence(),
            new OperationFingerprintMapper(),
            new OperationKeyFactory(),
        );
    }

    private function __construct() {}
}
