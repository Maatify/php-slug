<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Schema;

use PDO;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Throwable;

final readonly class PdoSchemaInstaller
{
    public function __construct(
        private PDO $pdo,
        private ?PdoCapabilityGuard $capabilities = null,
    ) {}

    public function installPackageSchema(): void
    {
        ($this->capabilities ?? new PdoCapabilityGuard($this->pdo))->assertSupported();
        $path = dirname(__DIR__, 5) . '/schema/mysql/001_slug_rc1.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new SlugPersistenceInvariantException('The package schema file cannot be read.');
        }

        $this->installSql($sql);
    }

    public function installSql(string $sql): void
    {
        $statements = preg_split('/;\s*(?:\r?\n|\z)/', $sql);
        if ($statements === false) {
            throw new SlugPersistenceInvariantException('The package schema could not be split into statements.');
        }

        $executed = 0;
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            try {
                if ($this->pdo->exec($statement) === false) {
                    throw new SlugPersistenceInvariantException('A package schema statement returned false.');
                }
                $executed++;
            } catch (SlugPersistenceInvariantException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                throw new SlugPersistenceInvariantException('A package schema statement failed.', 0, $throwable);
            }
        }

        if ($executed !== 6) {
            throw new SlugPersistenceInvariantException(sprintf('Expected six package schema statements; executed %d.', $executed));
        }
    }
}
