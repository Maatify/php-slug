<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Binding;

use PDO;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;

/** Internal destructive Binding maintenance used only by purgeBinding. */
final readonly class PdoBindingMaintenanceRepository
{
    public function __construct(
        private PDO $pdo,
        ?PdoCapabilityGuard $capabilities = null,
    ) {
        $this->capabilities = $capabilities ?? new PdoCapabilityGuard($pdo);
    }

    private PdoCapabilityGuard $capabilities;

    public function purge(int $bindingId): void
    {
        if ($bindingId < 0) {
            throw new SlugPersistenceInvariantException('Binding id cannot be negative.');
        }
        $this->capabilities->assertInstalledSchemaSupported();
        $statement = $this->pdo->prepare('SELECT operation_id FROM maa_slug_operation_bindings WHERE binding_id = :binding_id FOR UPDATE');
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare purge participant lookup.');
        }
        $statement->execute(['binding_id' => $bindingId]);
        $operationIds = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (! is_array($row)) {
                throw new SlugPersistenceInvariantException('Purge participant lookup returned an invalid row.');
            }
            $operationId = filter_var($row['operation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($operationId === false) {
                throw new SlugPersistenceInvariantException('Purge participant row contains an invalid operation id.');
            }
            $operationIds[] = $operationId;
        }

        $deleteHistory = $this->pdo->prepare('DELETE FROM maa_slug_history WHERE binding_id = :binding_id');
        if ($deleteHistory === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare purge History delete.');
        }
        $deleteHistory->execute(['binding_id' => $bindingId]);

        $deleteParticipants = $this->pdo->prepare('DELETE FROM maa_slug_operation_bindings WHERE binding_id = :binding_id');
        if ($deleteParticipants === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare purge participant delete.');
        }
        $deleteParticipants->execute(['binding_id' => $bindingId]);

        $deleteOperation = $this->pdo->prepare(
            'DELETE FROM maa_slug_operations WHERE id = :operation_id '
            . 'AND NOT EXISTS (SELECT 1 FROM maa_slug_operation_bindings p WHERE p.operation_id = maa_slug_operations.id)',
        );
        if ($deleteOperation === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare purge operation delete.');
        }
        foreach (array_values(array_unique($operationIds)) as $operationId) {
            $deleteOperation->execute(['operation_id' => $operationId]);
        }

        $deleteBinding = $this->pdo->prepare('DELETE FROM maa_slug_bindings WHERE id = :binding_id');
        if ($deleteBinding === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare purge Binding delete.');
        }
        $deleteBinding->execute(['binding_id' => $bindingId]);
        if ($deleteBinding->rowCount() !== 1) {
            throw new SlugPersistenceInvariantException('Binding purge did not affect exactly one row.');
        }
    }
}
