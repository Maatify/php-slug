<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Schema;

use PDO;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoRowHydrator;

final readonly class PdoSchemaVerifier
{
    public function __construct(private PDO $pdo) {}

    public function assertInstalled(): void
    {
        $expected = [
            'maa_slug_scopes',
            'maa_slug_bindings',
            'maa_slug_operations',
            'maa_slug_operation_bindings',
            'maa_slug_registry',
            'maa_slug_history',
        ];
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%' ORDER BY TABLE_NAME",
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect the package schema.');
        }
        $rows = PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC));
        $names = array_map(static fn(array $row): string => PdoRowHydrator::string($row, 'TABLE_NAME'), $rows);
        sort($names);
        $sortedExpected = $expected;
        sort($sortedExpected);
        if ($names !== $sortedExpected) {
            throw new SlugPersistenceInvariantException('Package schema table set is not exact.');
        }
        foreach ($rows as $row) {
            if (PdoRowHydrator::string($row, 'ENGINE') !== 'InnoDB' || PdoRowHydrator::string($row, 'TABLE_COLLATION') !== 'utf8mb4_bin') {
                throw new SlugPersistenceInvariantException('Package schema does not satisfy exact InnoDB/utf8mb4 semantics.');
            }
        }
    }
}
