<?php

declare(strict_types=1);

use Maatify\Slug\Canonicalization\Factory\SlugProfileRegistryFactory;
use Maatify\Slug\Canonicalization\Factory\SlugTextServiceFactory;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

require dirname(__DIR__) . '/vendor/autoload.php';

$profiles = SlugProfileRegistryFactory::createBuiltIn();
$text = SlugTextServiceFactory::create($profiles);
$result = $text->generateFromSource(new SlugProfileKey('ascii-v1'), 'Hello, World!');

if ($result->slug->value !== 'hello-world') {
    throw new RuntimeException(sprintf('Unexpected canonical slug: %s', $result->slug->value));
}

echo "CANONICALIZATION_EXAMPLE=hello-world PASS\n";
