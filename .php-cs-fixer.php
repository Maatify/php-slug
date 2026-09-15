<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$directories = array_values(array_filter(
    [__DIR__ . '/src', __DIR__ . '/tests'],
    static fn (string $directory): bool => is_dir($directory),
));

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder(Finder::create()->in($directories));
