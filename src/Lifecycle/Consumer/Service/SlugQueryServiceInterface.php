<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Service;

use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugResolutionDTO;

interface SlugQueryServiceInterface
{
    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO;

    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO;

    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO;
}
