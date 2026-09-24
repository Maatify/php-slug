<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Service;

use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugResolutionDTO;

/** Read-only consumer boundary for availability, current-slug, and resolution queries. */
interface SlugQueryServiceInterface
{
    /** Reports availability without changing package state. */
    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO;

    /** Returns the current slug for a binding, or null when none exists. */
    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO;

    /** Resolves a decoded lookup segment without mutating package state. */
    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO;
}
