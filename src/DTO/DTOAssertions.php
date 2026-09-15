<?php

declare(strict_types=1);

namespace Maatify\Slug\DTO;

use Maatify\Slug\Exception\SlugInvalidArgumentException;

final class DTOAssertions
{
    public static function nonNegative(int $value, string $field): void
    {
        if ($value < 0) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-negative.', $field));
        }
    }

    public static function operationKey(?string $value): void
    {
        if ($value !== null && preg_match('/\\A[a-f0-9]{32}\\z/', $value) !== 1) {
            throw new SlugInvalidArgumentException('Operation key must be lowercase hexadecimal of length 32.');
        }
    }

    /** @param array<int, mixed> $value */
    public static function list(array $value, string $field): void
    {
        if (! array_is_list($value)) {
            throw new SlugInvalidArgumentException(sprintf('%s must be a list.', $field));
        }
    }

    /** @param array<int, mixed> $items */
    public static function sortedById(array $items, string $field): void
    {
        self::list($items, $field);
        $previous = null;
        foreach ($items as $item) {
            if (! is_object($item) || ! property_exists($item, 'id') || ! is_int($item->id)) {
                throw new SlugInvalidArgumentException(sprintf('%s contains an item without an integer id.', $field));
            }
            if ($previous !== null && $item->id <= $previous) {
                throw new SlugInvalidArgumentException(sprintf('%s must be strictly ordered by id.', $field));
            }
            $previous = $item->id;
        }
    }

    /** @param array<int, mixed> $items */
    public static function orderedHistory(array $items, string $field): void
    {
        self::list($items, $field);
        $previousSequence = null;
        $previousId = null;
        foreach ($items as $item) {
            if (! is_object($item) || ! property_exists($item, 'sequenceNo') || ! property_exists($item, 'id') || ! is_int($item->sequenceNo) || ! is_int($item->id)) {
                throw new SlugInvalidArgumentException(sprintf('%s contains an invalid history event.', $field));
            }
            if ($previousSequence !== null && ($item->sequenceNo < $previousSequence || ($item->sequenceNo === $previousSequence && $item->id <= $previousId))) {
                throw new SlugInvalidArgumentException(sprintf('%s must be ordered by sequence and id.', $field));
            }
            $previousSequence = $item->sequenceNo;
            $previousId = $item->id;
        }
    }

    /**
     * @param array<int, mixed> $items
     * @param list<int> $bindingIds
     */
    public static function participantHistory(array $items, array $bindingIds, string $field): void
    {
        self::list($items, $field);
        $lastParticipant = -1;
        $lastSequence = [];
        $lastId = [];
        foreach ($items as $item) {
            if (! is_object($item) || ! property_exists($item, 'bindingId') || ! property_exists($item, 'sequenceNo') || ! property_exists($item, 'id') || ! is_int($item->bindingId) || ! is_int($item->sequenceNo) || ! is_int($item->id)) {
                throw new SlugInvalidArgumentException(sprintf('%s contains an invalid history event.', $field));
            }
            $participant = array_search($item->bindingId, $bindingIds, true);
            if ($participant === false || $participant < $lastParticipant) {
                throw new SlugInvalidArgumentException(sprintf('%s has invalid participant ordering.', $field));
            }
            if ($participant > $lastParticipant) {
                $lastParticipant = $participant;
            }
            if (isset($lastSequence[$item->bindingId]) && ($item->sequenceNo < $lastSequence[$item->bindingId] || ($item->sequenceNo === $lastSequence[$item->bindingId] && $item->id <= $lastId[$item->bindingId]))) {
                throw new SlugInvalidArgumentException(sprintf('%s must be ordered within each participant.', $field));
            }
            $lastSequence[$item->bindingId] = $item->sequenceNo;
            $lastId[$item->bindingId] = $item->id;
        }
    }

    private function __construct()
    {
    }
}
