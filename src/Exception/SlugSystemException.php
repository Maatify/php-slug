<?php

declare(strict_types=1);

namespace Maatify\Slug\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;

/** Base system-level exception for unexpected infrastructure or environment failures. */
abstract class SlugSystemException extends SystemMaatifyException implements SlugDomainExceptionInterface
{
    /** Returns the package-wide fallback error code for system failures. */
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
