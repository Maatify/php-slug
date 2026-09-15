<?php

declare(strict_types=1);

namespace Maatify\Slug\Exception;

use Maatify\Exceptions\Exception\BusinessRule\BusinessRuleMaatifyException;

abstract class SlugBusinessRuleException extends BusinessRuleMaatifyException implements SlugDomainExceptionInterface
{
}
