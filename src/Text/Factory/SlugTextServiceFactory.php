<?php

declare(strict_types=1);

namespace Maatify\Slug\Text\Factory;

use Maatify\Slug\Text\Contract\SlugTextServiceInterface;
use Maatify\Slug\Profile\Contract\SlugProfileRegistryInterface;
use Maatify\Slug\Text\SlugTextService;

final class SlugTextServiceFactory
{
    public static function create(SlugProfileRegistryInterface $profiles): SlugTextServiceInterface
    {
        return new SlugTextService($profiles);
    }

    private function __construct() {}
}
