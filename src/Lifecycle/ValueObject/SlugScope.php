<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\ValueObject;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

final readonly class SlugScope implements JsonSerializable
{
    public function __construct(
        public string $namespace,
        public ?string $localeKey,
        public ?string $contextKey,
    ) {
        if (preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $namespace) !== 1) {
            throw new SlugInvalidArgumentException('Invalid slug scope namespace.');
        }

        if ($localeKey !== null) {
            self::assertDimension($localeKey, 191, 'localeKey');
        }

        if ($contextKey !== null) {
            self::assertDimension($contextKey, 191, 'contextKey');
        }
    }

    private static function assertDimension(string $value, int $maxCodePoints, string $field): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-empty valid UTF-8.', $field));
        }
        if (preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1 || strpbrk($value, '/\\') !== false) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden character.', $field));
        }
        if (preg_match('/^[\x09-\x0D\x20\x{00A0}]|[\x09-\x0D\x20\x{00A0}]$/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s has forbidden boundary whitespace.', $field));
        }
        if (mb_strlen($value, 'UTF-8') > $maxCodePoints) {
            throw new SlugInvalidArgumentException(sprintf('%s exceeds its maximum length.', $field));
        }
    }

    /** @return array<string, string|null> */
    public function jsonSerialize(): array
    {
        return [
            'namespace' => $this->namespace,
            'locale_key' => $this->localeKey,
            'context_key' => $this->contextKey,
        ];
    }
}
