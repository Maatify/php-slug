<?php

declare(strict_types=1);

namespace Maatify\Slug\Registry\Enum;

enum BindingStatusEnum: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case RELEASED = 'RELEASED';
}
