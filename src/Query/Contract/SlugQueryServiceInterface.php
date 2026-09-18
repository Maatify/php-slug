<?php

declare(strict_types=1);

namespace Maatify\Slug\Query\Contract;

use Maatify\Slug\Query\Criteria\AvailabilityCriteria;
use Maatify\Slug\Query\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Query\Criteria\ResolutionCriteria;
use Maatify\Slug\Query\DTO\CurrentSlugDTO;
use Maatify\Slug\Query\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Query\DTO\SlugResolutionDTO;

interface SlugQueryServiceInterface
{
    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO;

    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO;

    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO;
}
