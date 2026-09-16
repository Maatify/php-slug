<?php

declare(strict_types=1);

namespace Maatify\Slug\Internal\Transaction;

use PDO;
use Maatify\Slug\Exception\SlugTransactionParticipationException;
use Throwable;

final readonly class PdoTransactionCoordinator
{
    public function __construct(private PDO $pdo) {}

    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(callable $callback): mixed
    {
        if (! $this->pdo->inTransaction()) {
            try {
                if (! $this->pdo->beginTransaction()) {
                    throw new SlugTransactionParticipationException('Unable to begin the package transaction.');
                }
            } catch (SlugTransactionParticipationException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                throw new SlugTransactionParticipationException('Unable to begin the package transaction.', 0, $throwable);
            }

            try {
                $result = $callback();
            } catch (Throwable $throwable) {
                $this->rollbackIfActive();

                throw $throwable;
            }

            try {
                if (! $this->pdo->commit()) {
                    throw new SlugTransactionParticipationException('Unable to commit the package transaction.');
                }
            } catch (SlugTransactionParticipationException $exception) {
                $this->rollbackIfActive();
                throw $exception;
            } catch (Throwable $throwable) {
                $this->rollbackIfActive();
                throw new SlugTransactionParticipationException('Unable to commit the package transaction.', 0, $throwable);
            }

            return $result;
        }

        $savepoint = $this->newSavepointName();
        $this->execOrThrow(
            sprintf('SAVEPOINT %s', $savepoint),
            'Unable to create the package savepoint before mutation.',
        );

        try {
            $result = $callback();
        } catch (Throwable $throwable) {
            $this->rollbackToSavepoint($savepoint);

            throw $throwable;
        }

        try {
            $this->execOrThrow(
                sprintf('RELEASE SAVEPOINT %s', $savepoint),
                'Unable to release the package savepoint.',
            );
        } catch (Throwable $throwable) {
            $this->rollbackToSavepoint($savepoint);

            throw $throwable;
        }

        return $result;
    }

    public function newSavepointName(): string
    {
        try {
            $suffix = strtoupper(bin2hex(random_bytes(16)));
        } catch (Throwable $throwable) {
            throw new SlugTransactionParticipationException(
                'Unable to generate a transaction savepoint name.',
                0,
                $throwable,
            );
        }

        return 'maa_slug_sp_' . $suffix;
    }

    private function rollbackToSavepoint(string $savepoint): void
    {
        try {
            $this->pdo->exec(sprintf('ROLLBACK TO SAVEPOINT %s', $savepoint));
        } catch (Throwable) {
            // The operation's original Throwable remains authoritative.
        }

        try {
            $this->pdo->exec(sprintf('RELEASE SAVEPOINT %s', $savepoint));
        } catch (Throwable) {
            // The operation's original Throwable remains authoritative.
        }
    }

    private function rollbackIfActive(): void
    {
        if (! $this->pdo->inTransaction()) {
            return;
        }

        try {
            $this->pdo->rollBack();
        } catch (Throwable) {
            // The operation's original Throwable remains authoritative.
        }
    }

    private function execOrThrow(string $sql, string $message): void
    {
        try {
            if ($this->pdo->exec($sql) === false) {
                throw new SlugTransactionParticipationException($message);
            }
        } catch (SlugTransactionParticipationException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new SlugTransactionParticipationException($message, 0, $throwable);
        }
    }
}
