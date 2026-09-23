<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service;

use Maatify\Slug\Canonicalization\Exception\SlugProfileAlreadyRegisteredException;
use Maatify\Slug\Canonicalization\Exception\SlugProfileNotFoundException;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;

/** In-memory registry that prevents duplicate profile keys and resolves registered profiles. */
final class SlugProfileRegistry implements SlugProfileRegistryInterface
{
    /** @var array<string, SlugProfileInterface> */
    private array $profiles = [];

    /** Registers a profile once; duplicate keys are rejected rather than replaced. */
    public function register(SlugProfileInterface $profile): void
    {
        $key = $profile->key()->value;
        if (isset($this->profiles[$key])) {
            throw new SlugProfileAlreadyRegisteredException(sprintf('Slug profile %s is already registered.', $key));
        }
        $this->profiles[$key] = $profile;
    }

    /** Returns the profile for a key or throws when the key is unknown. */
    public function get(SlugProfileKey $key): SlugProfileInterface
    {
        if (! isset($this->profiles[$key->value])) {
            throw new SlugProfileNotFoundException(sprintf('Slug profile %s was not found.', $key->value));
        }
        return $this->profiles[$key->value];
    }

    /** Reports whether a profile key is currently registered. */
    public function has(SlugProfileKey $key): bool
    {
        return isset($this->profiles[$key->value]);
    }
}
