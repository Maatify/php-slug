<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service;

use Maatify\Slug\Canonicalization\Exception\SlugRuntimeCompatibilityException;
use Normalizer;
use Transliterator;

/** Fails closed unless the capabilities required by built-in profiles behave as required. */
final class RuntimeCompatibilityGuard
{
    private static bool $verified = false;

    /** Validates the actual ext-intl capabilities used by built-in profiles. */
    public static function assertSupported(): void
    {
        if (self::$verified) {
            return;
        }

        if (! class_exists(Normalizer::class)) {
            throw new SlugRuntimeCompatibilityException('ext-intl Normalizer is unavailable.');
        }

        try {
            if (Normalizer::normalize("cafe\u{0301}", Normalizer::FORM_C) !== 'café') {
                throw new SlugRuntimeCompatibilityException('ext-intl NFC normalization probe failed.');
            }

            $lower = Transliterator::create('Any-Lower');
            if (! $lower instanceof Transliterator || $lower->transliterate('HELLO') !== 'hello') {
                throw new SlugRuntimeCompatibilityException('ext-intl Any-Lower probe failed.');
            }

            $ascii = Transliterator::create('Any-Latin; Latin-ASCII');
            if (! $ascii instanceof Transliterator || $ascii->transliterate('Über Café') !== 'Uber Cafe') {
                throw new SlugRuntimeCompatibilityException('ext-intl ASCII transliteration probe failed.');
            }
        } catch (SlugRuntimeCompatibilityException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new SlugRuntimeCompatibilityException('ext-intl capability verification failed.', previous: $exception);
        }

        self::$verified = true;
    }

    private function __construct() {}
}
