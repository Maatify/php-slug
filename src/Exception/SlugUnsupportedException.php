<?php

declare(strict_types=1);

namespace Maatify\Slug\Exception;

use Maatify\Exceptions\Exception\Unsupported\UnsupportedOperationMaatifyException;

abstract class SlugUnsupportedException extends UnsupportedOperationMaatifyException implements SlugDomainExceptionInterface {}
