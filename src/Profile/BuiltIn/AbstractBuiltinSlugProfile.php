<?php

declare(strict_types=1);

namespace Maatify\Slug\Profile\BuiltIn;

use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Contracts\SlugProfileInterface;
use Maatify\Slug\Profile\Runtime\RuntimeCompatibilityGuard;
use Maatify\Slug\Validation\SlugInputValidator;
use Normalizer;
use Transliterator;

abstract class AbstractBuiltinSlugProfile implements SlugProfileInterface
{
    protected function __construct(protected SlugProfileKey $profileKey)
    {
        RuntimeCompatibilityGuard::assertSupported();
    }

    final public function key(): SlugProfileKey
    {
        return $this->profileKey;
    }

    protected function normalize(string $value, string $field): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (! is_string($normalized)) {
            throw new SlugInvalidArgumentException(sprintf('%s NFC normalization failed.', $field));
        }
        return $normalized;
    }

    protected function lower(string $value, string $field): string
    {
        $transliterator = Transliterator::create('Any-Lower');
        if ($transliterator === null) {
            throw new SlugInvalidArgumentException(sprintf('%s ICU lowercase failed.', $field));
        }
        $lowered = $transliterator->transliterate($value);
        if ($lowered === false) {
            throw new SlugInvalidArgumentException(sprintf('%s ICU lowercase failed.', $field));
        }
        return $lowered;
    }

    protected function assertSafe(string $value, string $field): void
    {
        SlugInputValidator::assertSafe($value, $field);
    }

    protected function assertCanonicalRepresentation(string $value, string $field): void
    {
        $this->assertSafe($value, $field);

        if ($this->normalize($value, $field) !== $value) {
            throw new SlugInvalidArgumentException(sprintf('%s must already be NFC.', $field));
        }

        if ($this->lower($value, $field) !== $value) {
            throw new SlugInvalidArgumentException(sprintf('%s must already be ICU-lowercase.', $field));
        }
    }

    protected function truncateGenerated(string $value): string
    {
        $value = mb_substr($value, 0, 160, 'UTF-8');
        return rtrim($value, '-');
    }
}
