<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditValidationTest extends TestCase
{
    public function testReasonPreservesBoundaryWhitespaceAndPathSeparators(): void
    {
        $reason = " reason / keep\\exactly ";
        $audit = new AuditContextDTO(reason: $reason);
        $event = ContractFixtures::history(
            100,
            700,
            1,
            ContractFixtures::identity(1),
            HistoryEventTypeEnum::ASSIGNED,
            ContractFixtures::slug('hello'),
            RegistryRoleEnum::CURRENT_CANONICAL,
        );
        $event = new \Maatify\Slug\Lifecycle\History\HistoryEventDTO(
            $event->id,
            $event->bindingId,
            $event->sequenceNo,
            $event->eventType,
            $event->scopeSnapshot,
            $event->entitySnapshot,
            $event->slugSnapshot,
            $event->previousSlugSnapshot,
            $event->claimRoleSnapshot,
            $event->previousClaimRoleSnapshot,
            $event->relatedScope,
            $event->relatedEntity,
            $event->operationKey,
            $event->actorKey,
            $reason,
            $event->correlationKey,
            $event->occurredAt,
            $event->originalOccurredAt,
        );

        self::assertSame($reason, $audit->reason);
        self::assertSame($reason, $event->reason);
        json_encode($audit, JSON_THROW_ON_ERROR);
        self::assertSame($reason, $audit->jsonSerialize()['reason']);
        self::assertSame($reason, $event->jsonSerialize()['reason']);
    }

    public function testReasonRejectsInvalidUtf8AndControlOrFormatCharacters(): void
    {
        foreach (["bad\0reason", "bad\xC3\x28", "bad\u{200D}reason"] as $reason) {
            try {
                new AuditContextDTO(reason: $reason);
                self::fail('Invalid reason was accepted.');
            } catch (SlugInvalidArgumentException $exception) {
                self::assertInstanceOf(SlugInvalidArgumentException::class, $exception);
            }
        }
    }

    public function testStrictAuditKeysStillRejectBoundaryWhitespaceAndPathSeparators(): void
    {
        foreach (['actorKey', 'correlationKey', 'idempotencyKey'] as $field) {
            foreach ([" value ", 'value/part', 'value\\part'] as $value) {
                try {
                    new AuditContextDTO(...[$field => $value]);
                    self::fail(sprintf('%s accepted a forbidden value.', $field));
                } catch (SlugInvalidArgumentException $exception) {
                    self::assertInstanceOf(SlugInvalidArgumentException::class, $exception);
                }
            }
        }
    }
}
