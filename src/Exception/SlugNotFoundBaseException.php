<?php

declare(strict_types=1);

namespace Maatify\Slug\Exception;

use Maatify\Exceptions\Exception\NotFound\ResourceNotFoundMaatifyException;

abstract class SlugNotFoundBaseException extends ResourceNotFoundMaatifyException implements SlugDomainExceptionInterface {}
