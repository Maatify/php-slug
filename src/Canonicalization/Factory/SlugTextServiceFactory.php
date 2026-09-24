<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Factory;

use Maatify\Slug\Canonicalization\Service\SlugTextServiceInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Canonicalization\Service\SlugTextService;

/** Creates the stateless text-service adapter over a profile registry. */
final class SlugTextServiceFactory
{
    /** Returns a text service that delegates canonicalization to the selected profile. */
    public static function create(SlugProfileRegistryInterface $profiles): SlugTextServiceInterface
    {
        return new SlugTextService($profiles);
    }

    private function __construct() {}
}
