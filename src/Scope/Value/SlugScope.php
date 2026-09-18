<?php

declare(strict_types=1);

namespace Maatify\Slug\Scope\Value;

use JsonSerializable;
use Maatify\Slug\Shared\Validation\IdentityValidator;

final readonly class SlugScope implements JsonSerializable
{
    public function __construct(
        public string $namespace,
        public ?string $localeKey,
        public ?string $contextKey,
    ) {
        IdentityValidator::assertNamespace($namespace);

        if ($localeKey !== null) {
            IdentityValidator::assertDimension($localeKey, 191, 'localeKey');
        }

        if ($contextKey !== null) {
            IdentityValidator::assertDimension($contextKey, 191, 'contextKey');
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
