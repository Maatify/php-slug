<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Exception;

use Maatify\Exceptions\Exception\BusinessRule\BusinessRuleMaatifyException;
use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Exceptions\Exception\NotFound\ResourceNotFoundMaatifyException;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Exceptions\Exception\Unsupported\UnsupportedOperationMaatifyException;
use Maatify\Exceptions\Exception\Validation\InvalidArgumentMaatifyException;
use Maatify\Slug\Exception\SlugAliasOperationNotPermittedException;
use Maatify\Slug\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Exception\SlugAssignmentNotPermittedException;
use Maatify\Slug\Exception\SlugBusinessRuleException;
use Maatify\Slug\Exception\SlugCannotBeGeneratedException;
use Maatify\Slug\Exception\SlugConflictException;
use Maatify\Slug\Exception\SlugCurrentClaimReleaseException;
use Maatify\Slug\Exception\SlugDomainExceptionInterface;
use Maatify\Slug\Exception\SlugExceptionInterface;
use Maatify\Slug\Exception\SlugHistoricalRestoreNotPermittedException;
use Maatify\Slug\Exception\SlugIdempotencyConflictException;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Exception\SlugNotFoundBaseException;
use Maatify\Slug\Exception\SlugNotFoundException;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugProfileAlreadyRegisteredException;
use Maatify\Slug\Exception\SlugProfileConfigurationException;
use Maatify\Slug\Exception\SlugProfileNotFoundException;
use Maatify\Slug\Exception\SlugPurgeNotPermittedException;
use Maatify\Slug\Exception\SlugReservedException;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Exception\SlugSystemException;
use Maatify\Slug\Exception\SlugTransactionParticipationException;
use Maatify\Slug\Exception\SlugTransferReplacementConflictException;
use Maatify\Slug\Exception\SlugUnsupportedDriverException;
use Maatify\Slug\Exception\SlugUnsupportedException;
use Maatify\Slug\Exception\SlugValidationException;
use PHPUnit\Framework\TestCase;

final class ExceptionTaxonomyTest extends TestCase
{
    /** @return iterable<string, array{class: class-string<SlugExceptionInterface>, parent: class-string<object>}> */
    public static function taxonomy(): iterable
    {
        yield 'invalid argument' => ['class' => SlugInvalidArgumentException::class, 'parent' => SlugValidationException::class];
        yield 'profile configuration' => ['class' => SlugProfileConfigurationException::class, 'parent' => SlugValidationException::class];
        yield 'cannot generate' => ['class' => SlugCannotBeGeneratedException::class, 'parent' => SlugBusinessRuleException::class];
        yield 'assignment' => ['class' => SlugAssignmentNotPermittedException::class, 'parent' => SlugBusinessRuleException::class];
        yield 'alias' => ['class' => SlugAliasOperationNotPermittedException::class, 'parent' => SlugBusinessRuleException::class];
        yield 'historical restore' => ['class' => SlugHistoricalRestoreNotPermittedException::class, 'parent' => SlugBusinessRuleException::class];
        yield 'current release' => ['class' => SlugCurrentClaimReleaseException::class, 'parent' => SlugBusinessRuleException::class];
        yield 'purge' => ['class' => SlugPurgeNotPermittedException::class, 'parent' => SlugBusinessRuleException::class];
        yield 'profile registered' => ['class' => SlugProfileAlreadyRegisteredException::class, 'parent' => SlugConflictException::class];
        yield 'claimed' => ['class' => SlugAlreadyClaimedException::class, 'parent' => SlugConflictException::class];
        yield 'reserved' => ['class' => SlugReservedException::class, 'parent' => SlugConflictException::class];
        yield 'revision' => ['class' => SlugRevisionConflictException::class, 'parent' => SlugConflictException::class];
        yield 'allocation' => ['class' => SlugAllocationExhaustedException::class, 'parent' => SlugConflictException::class];
        yield 'idempotency' => ['class' => SlugIdempotencyConflictException::class, 'parent' => SlugConflictException::class];
        yield 'transfer replacement' => ['class' => SlugTransferReplacementConflictException::class, 'parent' => SlugConflictException::class];
        yield 'scope profile' => ['class' => SlugScopeProfileMismatchException::class, 'parent' => SlugConflictException::class];
        yield 'not found' => ['class' => SlugNotFoundException::class, 'parent' => SlugNotFoundBaseException::class];
        yield 'profile not found' => ['class' => SlugProfileNotFoundException::class, 'parent' => SlugNotFoundException::class];
        yield 'compatibility' => ['class' => SlugRuntimeCompatibilityException::class, 'parent' => SlugUnsupportedException::class];
        yield 'transaction' => ['class' => SlugTransactionParticipationException::class, 'parent' => SlugUnsupportedException::class];
        yield 'driver' => ['class' => SlugUnsupportedDriverException::class, 'parent' => SlugUnsupportedException::class];
        yield 'persistence' => ['class' => SlugPersistenceInvariantException::class, 'parent' => SlugSystemException::class];
    }

    /**
     * @param class-string<SlugExceptionInterface> $class
     * @param class-string<object> $parent
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('taxonomy')]
    public function testConcreteExceptionHasExactStableParent(string $class, string $parent): void
    {
        $exception = new $class('test');

        self::assertTrue(is_a($exception, $parent));
        self::assertInstanceOf(SlugExceptionInterface::class, $exception);
        self::assertInstanceOf(SlugDomainExceptionInterface::class, $exception);
    }

    public function testPublishedParentsAreTheExactMaatifyExceptions(): void
    {
        self::assertSame(InvalidArgumentMaatifyException::class, get_parent_class(SlugValidationException::class));
        self::assertSame(BusinessRuleMaatifyException::class, get_parent_class(SlugBusinessRuleException::class));
        self::assertSame(GenericConflictMaatifyException::class, get_parent_class(SlugConflictException::class));
        self::assertSame(ResourceNotFoundMaatifyException::class, get_parent_class(SlugNotFoundBaseException::class));
        self::assertSame(UnsupportedOperationMaatifyException::class, get_parent_class(SlugUnsupportedException::class));
        self::assertSame(SystemMaatifyException::class, get_parent_class(SlugSystemException::class));
    }
}
