<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Mapper;

use JsonException;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;

final class OperationFingerprintMapper
{
    /**
     * @param array<string, mixed> $payload
     */
    public function fingerprint(array $payload, string $failureMessage = 'Request fingerprint encoding failed.'): string
    {
        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new SlugPersistenceInvariantException($failureMessage, 0, $exception);
        }

        return hash('sha256', $json);
    }
}
