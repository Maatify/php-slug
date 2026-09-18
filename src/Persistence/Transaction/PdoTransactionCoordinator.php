<?php

declare(strict_types=1);

namespace Maatify\Slug\Persistence\Transaction;

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
                $this->rollbackOwnedPreserving($throwable);

                throw $throwable;
            }

            if ($result instanceof PdoTransactionRollback) {
                $this->rollbackOwnedOrThrow();

                return $result->value;
            }

            try {
                if (! $this->pdo->commit()) {
                    throw new SlugTransactionParticipationException('Unable to commit the package transaction.');
                }
            } catch (SlugTransactionParticipationException $exception) {
                try {
                    $this->rollbackOwnedOrThrow();
                } catch (Throwable $rollbackFailure) {
                    throw new SlugTransactionParticipationException(
                        'Package transaction commit failed and rollback also failed: ' . $rollbackFailure->getMessage(),
                        0,
                        $exception,
                    );
                }
                throw $exception;
            } catch (Throwable $throwable) {
                try {
                    $this->rollbackOwnedOrThrow();
                } catch (Throwable $rollbackFailure) {
                    throw new SlugTransactionParticipationException(
                        'Package transaction commit failed and rollback also failed: ' . $rollbackFailure->getMessage(),
                        0,
                        $throwable,
                    );
                }
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
            try {
                $this->rollbackToSavepoint($savepoint);
            } catch (Throwable $cleanupFailure) {
                throw new SlugTransactionParticipationException(
                    'Package savepoint cleanup failed after the original failure: ' . $cleanupFailure->getMessage(),
                    0,
                    $throwable,
                );
            }

            throw $throwable;
        }

        if ($result instanceof PdoTransactionRollback) {
            $this->rollbackToSavepoint($savepoint);

            return $result->value;
        }

        try {
            $this->execOrThrow(
                sprintf('RELEASE SAVEPOINT %s', $savepoint),
                'Unable to release the package savepoint.',
            );
        } catch (Throwable $throwable) {
            try {
                $this->rollbackToSavepoint($savepoint);
            } catch (Throwable $cleanupFailure) {
                throw new SlugTransactionParticipationException(
                    'Package savepoint cleanup failed after release failure: ' . $cleanupFailure->getMessage(),
                    0,
                    $throwable,
                );
            }

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
        $this->execOrThrow(
            sprintf('ROLLBACK TO SAVEPOINT %s', $savepoint),
            'Unable to roll back the package savepoint.',
        );
        $this->execOrThrow(
            sprintf('RELEASE SAVEPOINT %s', $savepoint),
            'Unable to release the rolled-back package savepoint.',
        );
    }

    private function rollbackOwnedOrThrow(): void
    {
        if (! $this->pdo->inTransaction()) {
            return;
        }

        try {
            if (! $this->pdo->rollBack()) {
                throw new SlugTransactionParticipationException('Unable to roll back the package transaction.');
            }
        } catch (SlugTransactionParticipationException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new SlugTransactionParticipationException('Unable to roll back the package transaction.', 0, $throwable);
        }
    }

    private function rollbackOwnedPreserving(Throwable $original): void
    {
        try {
            $this->rollbackOwnedOrThrow();
        } catch (Throwable $rollbackFailure) {
            throw new SlugTransactionParticipationException(
                'Package transaction rollback failed after the original failure: ' . $rollbackFailure->getMessage(),
                0,
                $original,
            );
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
