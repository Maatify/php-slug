<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service\Profile\BuiltIn;

use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\Service\RuntimeCompatibilityGuard;
use Maatify\Slug\Canonicalization\Service\SlugInputValidator;
use Normalizer;
use Transliterator;

/** Shared ICU normalization, safety, and bounded-output behavior for built-in profiles. */
abstract class AbstractBuiltinSlugProfile implements SlugProfileInterface
{
    /** Stores the immutable profile key and verifies required ICU capabilities. */
    protected function __construct(protected SlugProfileKey $profileKey)
    {
        RuntimeCompatibilityGuard::assertSupported();
    }

    /** Returns the immutable profile key selected by the concrete built-in profile. */
    final public function key(): SlugProfileKey
    {
        return $this->profileKey;
    }

    /** Normalizes input to NFC and fails when ICU cannot produce a string. */
    protected function normalize(string $value, string $field): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (! is_string($normalized)) {
            throw new SlugInvalidArgumentException(sprintf('%s NFC normalization failed.', $field));
        }
        return $normalized;
    }

    /** Applies ICU lowercase conversion required by the built-in canonical forms. */
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

    /** Rejects invalid UTF-8, controls, format characters, and path separators. */
    protected function assertSafe(string $value, string $field): void
    {
        SlugInputValidator::assertSafe($value, $field);
    }

    /** Ensures a value is safe, NFC-normalized, and already ICU-lowercase. */
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

    /** Truncates generated output to 160 code points without leaving a trailing hyphen. */
    protected function truncateGenerated(string $value): string
    {
        $value = mb_substr($value, 0, 160, 'UTF-8');
        return rtrim($value, '-');
    }
}
