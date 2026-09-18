<?php

declare(strict_types=1);

namespace Maatify\Slug\Profile\Registry;

use Maatify\Slug\Exception\SlugProfileAlreadyRegisteredException;
use Maatify\Slug\Exception\SlugProfileNotFoundException;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Profile\Contract\SlugProfileInterface;
use Maatify\Slug\Profile\Contract\SlugProfileRegistryInterface;

final class SlugProfileRegistry implements SlugProfileRegistryInterface
{
    /** @var array<string, SlugProfileInterface> */
    private array $profiles = [];

    public function register(SlugProfileInterface $profile): void
    {
        $key = $profile->key()->value;
        if (isset($this->profiles[$key])) {
            throw new SlugProfileAlreadyRegisteredException(sprintf('Slug profile %s is already registered.', $key));
        }
        $this->profiles[$key] = $profile;
    }

    public function get(SlugProfileKey $key): SlugProfileInterface
    {
        if (! isset($this->profiles[$key->value])) {
            throw new SlugProfileNotFoundException(sprintf('Slug profile %s was not found.', $key->value));
        }
        return $this->profiles[$key->value];
    }

    public function has(SlugProfileKey $key): bool
    {
        return isset($this->profiles[$key->value]);
    }
}
