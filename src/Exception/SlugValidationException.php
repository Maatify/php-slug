<?php

declare(strict_types=1);

namespace Maatify\Slug\Exception;

use Maatify\Exceptions\Exception\Validation\InvalidArgumentMaatifyException;

abstract class SlugValidationException extends InvalidArgumentMaatifyException implements SlugDomainExceptionInterface {}
