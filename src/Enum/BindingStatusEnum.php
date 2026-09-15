<?php

declare(strict_types=1);

namespace Maatify\Slug\Enum;

enum BindingStatusEnum: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case RELEASED = 'RELEASED';
}
