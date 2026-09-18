<?php

declare(strict_types=1);

namespace Maatify\Slug\Profile\Value;

use Maatify\Slug\Shared\Validation\IdentityValidator;

final readonly class SlugProfileKey
{
    public function __construct(public string $value)
    {
        IdentityValidator::assertProfileKey($value);
    }
}
