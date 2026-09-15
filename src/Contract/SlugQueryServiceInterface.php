<?php

declare(strict_types=1);

namespace Maatify\Slug\Contract;

use Maatify\Slug\Criteria\AvailabilityCriteria;
use Maatify\Slug\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Criteria\ResolutionCriteria;
use Maatify\Slug\DTO\CurrentSlugDTO;
use Maatify\Slug\DTO\SlugAvailabilityDTO;
use Maatify\Slug\DTO\SlugResolutionDTO;

interface SlugQueryServiceInterface
{
    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO;

    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO;

    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO;
}
