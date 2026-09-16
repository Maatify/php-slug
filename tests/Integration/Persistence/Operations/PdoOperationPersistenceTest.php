<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Persistence\Operations;

use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\Operations\PdoOperationRepository;
use Maatify\Slug\Persistence\Contract\OperationParticipant;
use Maatify\Slug\Infrastructure\Persistence\PDO\Scope\PdoScopeRepository;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Internal\ResultSnapshot\ResultSnapshotMetadata;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Contracts\ContractFixtures;
use Maatify\Slug\Tests\Unit\Profile\StubSlugProfile;

final class PdoOperationPersistenceTest extends MySqlIntegrationTestCase
{
    public function testOperationParticipantReservationAndCommittedSnapshotRoundTrip(): void
    {
        $profile = new StubSlugProfile(new SlugProfileKey('ascii-v1'));
        $profiles = new SlugProfileRegistry();
        $profiles->register($profile);
        $capabilities = new PdoCapabilityGuard($this->pdo);
        $scopeRepository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), $capabilities);
        $request = new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1'));
        $binding = $scopeRepository->ensureBindingPlaceholder($request, new EntityReference('product', '42'));
        $operationKey = str_repeat('a', 32);
        $fingerprint = str_repeat('b', 64);
        $operationRepository = new PdoOperationRepository($this->pdo, $this->clock(), $capabilities);

        $reservation = $operationRepository->reserve(
            $operationKey,
            OperationTypeEnum::ASSIGN_EXACT,
            $fingerprint,
            'mutation',
            1,
            [new OperationParticipant($binding->id, 'request-42', 'SINGLE')],
        );

        self::assertTrue($reservation->created);
        $fixture = ContractFixtures::mutation();
        $snapshot = \Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotEncoder::encode($fixture);
        $snapshot = str_replace('"operation_key":null', '"operation_key":"' . $operationKey . '"', $snapshot);
        $metadata = new ResultSnapshotMetadata('mutation', 1, OperationTypeEnum::ASSIGN_EXACT, $operationKey, $snapshot);
        $operationRepository->commitSnapshot($reservation->operation->id, $metadata, $profiles);

        $stored = $operationRepository->findByParticipant($binding->id, 'request-42');
        self::assertNotNull($stored);
        self::assertSame('COMMITTED', $stored->status);
        self::assertNotNull($stored->resultSnapshot);
        self::assertSame($operationKey, $stored->operationKey);
    }
}
