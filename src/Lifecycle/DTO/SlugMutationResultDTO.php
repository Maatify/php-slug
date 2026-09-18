<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\ChangeTypeEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\History\HistoryEventDTO;
use Maatify\Slug\Registry\DTO\BindingDTO;
use Maatify\Slug\Registry\DTO\RegistryClaimDTO;
use Maatify\Slug\Shared\Validation\DTOAssertions;
use Maatify\Slug\Text\Value\Slug;

final readonly class SlugMutationResultDTO implements JsonSerializable
{
    /**
     * @param list<RegistryClaimDTO> $affectedClaims
     * @param list<HistoryEventDTO> $historyEvents
     */
    public function __construct(
        public OperationTypeEnum $operationType,
        public ?string $operationKey,
        public bool $replayed,
        public ?BindingDTO $before,
        public BindingDTO $after,
        /** @var list<RegistryClaimDTO> */
        public array $affectedClaims,
        public ?Slug $previousSlug,
        public ?Slug $currentSlug,
        public ChangeTypeEnum $changeType,
        public int $revision,
        /** @var list<HistoryEventDTO> */
        public array $historyEvents,
    ) {
        DTOAssertions::operationKey($operationKey);
        DTOAssertions::nonNegative($revision, 'revision');
        DTOAssertions::sortedById($affectedClaims, 'affectedClaims');
        DTOAssertions::orderedHistory($historyEvents, 'historyEvents');
    }

    public function withReplayed(bool $replayed): self
    {
        return new self(
            $this->operationType,
            $this->operationKey,
            $replayed,
            $this->before,
            $this->after,
            $this->affectedClaims,
            $this->previousSlug,
            $this->currentSlug,
            $this->changeType,
            $this->revision,
            $this->historyEvents,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'operation_type' => $this->operationType->value,
            'operation_key' => $this->operationKey,
            'replayed' => $this->replayed,
            'before' => $this->before,
            'after' => $this->after,
            'affected_claims' => $this->affectedClaims,
            'previous_slug' => $this->previousSlug?->value,
            'current_slug' => $this->currentSlug?->value,
            'change_type' => $this->changeType->value,
            'revision' => $this->revision,
            'history_events' => $this->historyEvents,
        ];
    }
}
