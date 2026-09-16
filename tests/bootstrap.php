<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$envFile = $root . '/.env.test';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read .env.test.');
    }

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/\A([A-Z][A-Z0-9_]*)=(.*)\z/', $line, $matches) !== 1) {
            throw new RuntimeException(sprintf('Invalid .env.test entry on line %d.', $lineNumber + 1));
        }

        $value = $matches[2];
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }

        putenv($matches[1] . '=' . $value);
        $_ENV[$matches[1]] = $value;
    }
}

require $root . '/vendor/autoload.php';
