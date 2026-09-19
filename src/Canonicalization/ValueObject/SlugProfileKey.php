<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\ValueObject;

use Maatify\Slug\Exception\SlugInvalidArgumentException;

final readonly class SlugProfileKey
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A(?=.{1,63}\z)[a-z][a-z0-9-]*-v[1-9][0-9]*\z/', $value) !== 1) {
            throw new SlugInvalidArgumentException('Invalid slug profile key.');
        }
    }
}
