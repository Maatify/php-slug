<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\History;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\History\HistoryEventDraft;
use Maatify\Slug\Lifecycle\Repository\History\HistoryRepositoryInterface;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoRowHydrator;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/** Internal append-only History persistence used by WU-05 lifecycle components. */
final readonly class PdoHistoryRepository implements HistoryRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private SlugProfileRegistryInterface $profiles,
        ?PdoCapabilityGuard $capabilities = null,
    ) {
        $this->capabilities = $capabilities ?? new PdoCapabilityGuard($pdo);
    }

    private PdoCapabilityGuard $capabilities;

    public function append(HistoryEventDraft $draft): HistoryEventDTO
    {
        if ($draft->bindingId < 0 || $draft->sequenceNo < 1) {
            throw new SlugPersistenceInvariantException('History identity must be positive and non-negative.');
        }
        if (($draft->operationId === null) !== ($draft->operationKey === null)) {
            throw new SlugPersistenceInvariantException('History operation identity must be paired.');
        }
        $event = new HistoryEventDTO(
            0,
            $draft->bindingId,
            $draft->sequenceNo,
            $draft->eventType,
            $draft->scopeSnapshot,
            $draft->entitySnapshot,
            $draft->slugSnapshot,
            $draft->previousSlugSnapshot,
            $draft->claimRoleSnapshot,
            $draft->previousClaimRoleSnapshot,
            $draft->relatedScope,
            $draft->relatedEntity,
            $draft->operationKey,
            $draft->audit->actorKey,
            $draft->audit->reason,
            $draft->audit->correlationKey,
            $this->utc($draft->occurredAt),
            $draft->originalOccurredAt === null ? null : $this->utc($draft->originalOccurredAt),
        );
        $this->capabilities->assertInstalledSchemaSupported();

        $statement = $this->pdo->prepare(
            'INSERT INTO maa_slug_history '
            . '(binding_id, sequence_no, event_type, scope_namespace_snapshot, scope_locale_snapshot, '
            . 'scope_context_snapshot, entity_type_snapshot, entity_key_snapshot, slug_snapshot, '
            . 'previous_slug_snapshot, claim_role_snapshot, previous_claim_role_snapshot, related_namespace, '
            . 'related_locale, related_context, related_entity_type, related_entity_key, operation_id, operation_key, '
            . 'actor_key, reason, correlation_key, occurred_at, original_occurred_at) '
            . 'VALUES (:binding_id, :sequence_no, :event_type, :scope_namespace, :scope_locale, :scope_context, '
            . ':entity_type, :entity_key, :slug, :previous_slug, :claim_role, :previous_claim_role, :related_namespace, '
            . ':related_locale, :related_context, :related_entity_type, :related_entity_key, :operation_id, '
            . ':operation_key, :actor_key, :reason, :correlation_key, :occurred_at, :original_occurred_at)',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the History append.');
        }
        $related = $draft->relatedScope;
        $relatedEntity = $draft->relatedEntity;
        $statement->execute([
            'binding_id' => $event->bindingId,
            'sequence_no' => $event->sequenceNo,
            'event_type' => $event->eventType->value,
            'scope_namespace' => $event->scopeSnapshot->namespace,
            'scope_locale' => $event->scopeSnapshot->localeKey ?? '',
            'scope_context' => $event->scopeSnapshot->contextKey ?? '',
            'entity_type' => $event->entitySnapshot->entityType,
            'entity_key' => $event->entitySnapshot->entityKey,
            'slug' => $event->slugSnapshot?->value,
            'previous_slug' => $event->previousSlugSnapshot?->value,
            'claim_role' => $event->claimRoleSnapshot?->value,
            'previous_claim_role' => $event->previousClaimRoleSnapshot?->value,
            'related_namespace' => $related?->namespace,
            'related_locale' => $related?->localeKey,
            'related_context' => $related?->contextKey,
            'related_entity_type' => $relatedEntity?->entityType,
            'related_entity_key' => $relatedEntity?->entityKey,
            'operation_id' => $draft->operationId,
            'operation_key' => $event->operationKey,
            'actor_key' => $event->actorKey,
            'reason' => $event->reason,
            'correlation_key' => $event->correlationKey,
            'occurred_at' => $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'original_occurred_at' => $event->originalOccurredAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
        $id = filter_var($this->pdo->lastInsertId(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new SlugPersistenceInvariantException('History append did not return a valid id.');
        }
        $stored = $this->findById($id, true);
        if ($stored === null) {
            throw new SlugPersistenceInvariantException('Appended History row cannot be read back.');
        }

        return $stored;
    }

    public function findById(int $id, bool $forUpdate = false): ?HistoryEventDTO
    {
        if ($id < 0) {
            throw new SlugPersistenceInvariantException('History id cannot be negative.');
        }
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT h.id, h.binding_id, h.sequence_no, h.event_type, h.scope_namespace_snapshot, '
            . 'h.scope_locale_snapshot, h.scope_context_snapshot, h.entity_type_snapshot, h.entity_key_snapshot, '
            . 'h.slug_snapshot, h.previous_slug_snapshot, h.claim_role_snapshot, h.previous_claim_role_snapshot, '
            . 'h.related_namespace, h.related_locale, h.related_context, h.related_entity_type, h.related_entity_key, '
            . 'h.operation_key, h.actor_key, h.reason, h.correlation_key, h.occurred_at, h.original_occurred_at, '
            . 's.profile_key FROM maa_slug_history h INNER JOIN maa_slug_bindings b ON b.id = h.binding_id '
            . 'INNER JOIN maa_slug_scopes s ON s.id = b.scope_id WHERE h.id = :id' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the History lookup.');
        }
        $statement->execute(['id' => $id]);
        $row = PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));
        if ($row === null) {
            return null;
        }

        return $this->fromRow($row);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): HistoryEventDTO
    {
        try {
            $profile = $this->profiles->get(new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key')));
            $eventType = HistoryEventTypeEnum::tryFrom(PdoRowHydrator::string($row, 'event_type'));
            $role = $this->role($row['claim_role_snapshot'] ?? null, 'history.claim_role_snapshot');
            $previousRole = $this->role($row['previous_claim_role_snapshot'] ?? null, 'history.previous_claim_role_snapshot');
            $scope = new SlugScope(
                PdoRowHydrator::string($row, 'scope_namespace_snapshot'),
                PdoRowHydrator::string($row, 'scope_locale_snapshot') === '' ? null : PdoRowHydrator::string($row, 'scope_locale_snapshot'),
                PdoRowHydrator::string($row, 'scope_context_snapshot') === '' ? null : PdoRowHydrator::string($row, 'scope_context_snapshot'),
            );
            $relatedScope = $row['related_namespace'] === null ? null : new SlugScope(
                PdoRowHydrator::string($row, 'related_namespace'),
                PdoRowHydrator::nullableString($row, 'related_locale'),
                PdoRowHydrator::nullableString($row, 'related_context'),
            );
            $relatedEntity = $row['related_entity_type'] === null ? null : new EntityReference(
                PdoRowHydrator::string($row, 'related_entity_type'),
                PdoRowHydrator::string($row, 'related_entity_key'),
            );
            if ($eventType === null) {
                throw new SlugPersistenceInvariantException('History contains an unknown event type.');
            }

            return new HistoryEventDTO(
                PdoRowHydrator::nonNegativeInt($row, 'id'),
                PdoRowHydrator::nonNegativeInt($row, 'binding_id'),
                PdoRowHydrator::nonNegativeInt($row, 'sequence_no'),
                $eventType,
                $scope,
                new EntityReference(
                    PdoRowHydrator::string($row, 'entity_type_snapshot'),
                    PdoRowHydrator::string($row, 'entity_key_snapshot'),
                ),
                $row['slug_snapshot'] === null ? null : Slug::fromProfile($profile, PdoRowHydrator::string($row, 'slug_snapshot')),
                $row['previous_slug_snapshot'] === null ? null : Slug::fromProfile($profile, PdoRowHydrator::string($row, 'previous_slug_snapshot')),
                $role,
                $previousRole,
                $relatedScope,
                $relatedEntity,
                PdoRowHydrator::nullableString($row, 'operation_key'),
                PdoRowHydrator::nullableString($row, 'actor_key'),
                PdoRowHydrator::nullableString($row, 'reason'),
                PdoRowHydrator::nullableString($row, 'correlation_key'),
                $this->date(PdoRowHydrator::string($row, 'occurred_at'), 'history.occurred_at'),
                $row['original_occurred_at'] === null ? null : $this->date(PdoRowHydrator::string($row, 'original_occurred_at'), 'history.original_occurred_at'),
            );
        } catch (SlugPersistenceInvariantException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new SlugPersistenceInvariantException('History row violates the persistence invariant.', 0, $throwable);
        }
    }

    private function role(mixed $value, string $field): ?RegistryRoleEnum
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new SlugPersistenceInvariantException(sprintf('%s must be a role token or NULL.', $field));
        }
        $role = RegistryRoleEnum::tryFrom($value);
        if ($role === null) {
            throw new SlugPersistenceInvariantException(sprintf('%s contains an unknown role.', $field));
        }

        return $role;
    }

    private function utc(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private function date(string $value, string $field): DateTimeImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/', $value) !== 1) {
            throw new SlugPersistenceInvariantException(sprintf('%s must contain six microseconds.', $field));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new SlugPersistenceInvariantException(sprintf('%s is not a valid UTC timestamp.', $field));
        }

        return $date;
    }
}
