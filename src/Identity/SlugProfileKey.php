<?php

declare(strict_types=1);

namespace Maatify\Slug\Identity;

final readonly class SlugProfileKey
{
    public function __construct(public string $value)
    {
        IdentityValidator::assertProfileKey($value);
    }
}
