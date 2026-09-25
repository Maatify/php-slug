<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;

/** Immutable operational counts for one persisted Scope snapshot. */
final readonly class ScopeOperationalSummaryDTO implements JsonSerializable
{
    /**
     * Validates non-negative counts and the Binding/Registry total invariants.
     *
     * The counts describe current package rows within the supplied Scope;
     * History totals are based on persisted event Scope snapshots.
     */
    public function __construct(
        public ScopeDTO $scope,
        public int $bindingsTotal,
        public int $bindingsActive,
        public int $bindingsInactive,
        public int $bindingsReleased,
        public int $registryClaimsTotal,
        public int $registryCurrentCanonical,
        public int $registryHistoricalCanonical,
        public int $registryActiveAliases,
        public int $registryRetiredAliases,
        public int $historyEventsTotal,
    ) {
        $counts = [
            $bindingsTotal, $bindingsActive, $bindingsInactive, $bindingsReleased,
            $registryClaimsTotal, $registryCurrentCanonical, $registryHistoricalCanonical,
            $registryActiveAliases, $registryRetiredAliases, $historyEventsTotal,
        ];
        if (array_filter($counts, static fn(int $count): bool => $count < 0) !== []) {
            throw new SlugInvalidArgumentException('Operational counts must be non-negative.');
        }
        if ($bindingsTotal !== $bindingsActive + $bindingsInactive + $bindingsReleased) {
            throw new SlugInvalidArgumentException('Binding operational counts are inconsistent.');
        }
        if ($registryClaimsTotal !== $registryCurrentCanonical + $registryHistoricalCanonical + $registryActiveAliases + $registryRetiredAliases) {
            throw new SlugInvalidArgumentException('Registry operational counts are inconsistent.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'scope' => $this->scope,
            'bindings_total' => $this->bindingsTotal,
            'bindings_active' => $this->bindingsActive,
            'bindings_inactive' => $this->bindingsInactive,
            'bindings_released' => $this->bindingsReleased,
            'registry_claims_total' => $this->registryClaimsTotal,
            'registry_current_canonical' => $this->registryCurrentCanonical,
            'registry_historical_canonical' => $this->registryHistoricalCanonical,
            'registry_active_aliases' => $this->registryActiveAliases,
            'registry_retired_aliases' => $this->registryRetiredAliases,
            'history_events_total' => $this->historyEventsTotal,
        ];
    }
}
