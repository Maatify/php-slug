<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\History\HistoryEventDTO;
use Maatify\Slug\Registry\DTO\BindingDTO;
use Maatify\Slug\Registry\DTO\RegistryClaimDTO;
use Maatify\Slug\Shared\Validation\DTOAssertions;

final readonly class AdoptionResultDTO implements JsonSerializable
{
    public function __construct(
        public OperationTypeEnum $operationType,
        public ?string $operationKey,
        public bool $replayed,
        public ?BindingDTO $before,
        public BindingDTO $after,
        public RegistryClaimDTO $adoptedClaim,
        public HistoryEventDTO $historyEvent,
    ) {
        if (! in_array($operationType, [
            OperationTypeEnum::ADOPT_CURRENT,
            OperationTypeEnum::ADOPT_HISTORICAL,
            OperationTypeEnum::ADOPT_ALIAS,
        ], true)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Adoption result requires an adoption operation.');
        }
        DTOAssertions::operationKey($operationKey);
    }

    public function withReplayed(bool $replayed): self
    {
        return new self(
            $this->operationType,
            $this->operationKey,
            $replayed,
            $this->before,
            $this->after,
            $this->adoptedClaim,
            $this->historyEvent,
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
            'adopted_claim' => $this->adoptedClaim,
            'history_event' => $this->historyEvent,
        ];
    }
}
