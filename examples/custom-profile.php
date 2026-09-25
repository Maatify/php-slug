<?php

declare(strict_types=1);

use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Factory\SlugTextServiceFactory;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Small application-owned profile with a stable, versioned semantic contract. */
final class LowercaseWordsV1Profile implements SlugProfileInterface
{
    private SlugProfileKey $profileKey;

    public function __construct()
    {
        $this->profileKey = new SlugProfileKey('lowercase-words-v1');
    }

    public function key(): SlugProfileKey
    {
        return $this->profileKey;
    }

    public function generateFromSource(string $source): GeneratedSlugDTO
    {
        $value = trim(strtolower($source));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return new GeneratedSlugDTO($this->profileKey, $source, Slug::fromProfile($this, $value));
    }

    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO
    {
        $value = strtolower($candidate);
        $this->assertCanonicalSlug($value);

        return new CanonicalSlugDTO($this->profileKey, $candidate, Slug::fromProfile($this, $value));
    }

    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO
    {
        $value = strtolower($decodedSegment);
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) !== 1) {
            return new LookupCanonicalizationDTO($this->profileKey, $decodedSegment, InputFormCanonicalityEnum::INVALID, null);
        }

        return new LookupCanonicalizationDTO(
            $this->profileKey,
            $decodedSegment,
            $value === $decodedSegment ? InputFormCanonicalityEnum::CANONICAL : InputFormCanonicalityEnum::NON_CANONICAL,
            Slug::fromProfile($this, $value),
        );
    }

    public function assertCanonicalSlug(string $candidate): void
    {
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $candidate) !== 1) {
            throw new InvalidArgumentException('The custom profile requires lowercase hyphen-separated words.');
        }
    }
}

$profiles = SlugProfileRegistryFactory::createBuiltIn();
$profile = new LowercaseWordsV1Profile();
$profiles->register($profile);
$text = SlugTextServiceFactory::create($profiles);
$result = $text->generateFromSource($profile->key(), 'Hello, Custom Profile!');

if ($result->slug->value !== 'hello-custom-profile') {
    throw new RuntimeException(sprintf('Unexpected custom slug: %s', $result->slug->value));
}

echo "CUSTOM_PROFILE_EXAMPLE=hello-custom-profile PASS\n";
