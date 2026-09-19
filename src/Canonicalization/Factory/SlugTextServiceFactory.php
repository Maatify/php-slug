<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Factory;

use Maatify\Slug\Canonicalization\Service\SlugTextServiceInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Canonicalization\Service\SlugTextService;

final class SlugTextServiceFactory
{
    public static function create(SlugProfileRegistryInterface $profiles): SlugTextServiceInterface
    {
        return new SlugTextService($profiles);
    }

    private function __construct() {}
}
