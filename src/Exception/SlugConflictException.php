<?php

declare(strict_types=1);

namespace Maatify\Slug\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;

abstract class SlugConflictException extends GenericConflictMaatifyException implements SlugDomainExceptionInterface {}
