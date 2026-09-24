<?php

declare(strict_types=1);

final class ComposerPolicyVerifier
{
    /** @param array<string, mixed> $composer */
    /** @param array<string, string> $environment */
    public function verify(array $composer, string $composerVersion, array $environment): array
    {
        $errors = [];

        $config = $composer['config'] ?? null;
        if (!is_array($config)) {
            $errors[] = 'composer.json config must be an object';
        } else {
            if (array_key_exists('audit', $config)) {
                $errors[] = 'legacy config.audit is not permitted';
            }

            $policy = $config['policy'] ?? null;
            if ($policy === false) {
                $errors[] = 'config.policy must not be false';
            } elseif (!is_array($policy)) {
                $errors[] = 'config.policy must be an object';
            } else {
                $errors = [...$errors, ...$this->verifyPolicy($policy)];
            }
        }

        $errors = [...$errors, ...$this->verifyComposerVersion($composerVersion)];
        $errors = [...$errors, ...$this->verifyEnvironment($environment)];

        return $errors;
    }

    /** @return list<string> */
    public function verifyJson(string $contents, string $composerVersion, array $environment): array
    {
        try {
            /** @var mixed $composer */
            $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ['Invalid composer.json: ' . $exception->getMessage()];
        }

        if (!is_array($composer)) {
            return ['composer.json must contain an object'];
        }

        return $this->verify($composer, $composerVersion, $environment);
    }

    /** @param array<string, mixed> $policy */
    private function verifyPolicy(array $policy): array
    {
        $errors = [];
        $required = [
            'advisories' => [
                'block' => true,
                'audit' => 'fail',
            ],
            'malware' => [
                'block' => true,
                'block-scope' => 'all',
                'audit' => 'fail',
            ],
            'abandoned' => [
                'audit' => 'fail',
            ],
        ];

        foreach ($required as $section => $values) {
            $actual = $policy[$section] ?? null;
            if (!is_array($actual)) {
                $errors[] = "config.policy.$section must be an object";

                continue;
            }

            foreach ($values as $key => $expected) {
                if (!array_key_exists($key, $actual) || $actual[$key] !== $expected) {
                    $errors[] = "config.policy.$section.$key must be " . var_export($expected, true);
                }
            }

            $allowedKeys = array_keys($values);
            if ($section === 'abandoned') {
                $allowedKeys[] = 'block';
            }

            foreach (array_keys($actual) as $key) {
                if (!in_array($key, $allowedKeys, true)) {
                    $errors[] = "config.policy.$section.$key is not an approved policy setting";
                }
            }

            if ($section === 'abandoned' && (!array_key_exists('block', $actual) || !is_bool($actual['block']))) {
                $errors[] = 'config.policy.abandoned.block must be an explicit boolean';
            }

            foreach (array_keys($actual) as $key) {
                if (stripos((string) $key, 'ignore') !== false) {
                    $errors[] = "config.policy.$section.$key is an unapproved weakening mechanism";
                }
            }
        }

        foreach (array_keys($policy) as $section) {
            if (!array_key_exists($section, $required)) {
                $errors[] = "config.policy.$section is not an approved built-in policy section";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function verifyComposerVersion(string $version): array
    {
        if (preg_match('/(?:^|\s)Composer version\s+(\d+\.\d+\.\d+)/i', $version, $matches) !== 1) {
            return ['unable to determine Composer version from executable output'];
        }

        if ((int) explode('.', $matches[1], 2)[0] !== 2 || version_compare($matches[1], '2.10.0', '<')) {
            return ["Composer 2.10 capability is required; detected {$matches[1]}"];
        }

        return [];
    }

    /** @param array<string, string> $environment */
    private function verifyEnvironment(array $environment): array
    {
        $errors = [];

        foreach (['COMPOSER_POLICY', 'COMPOSER_POLICY_ADVISORIES_BLOCK', 'COMPOSER_POLICY_MALWARE_BLOCK'] as $name) {
            $state = $this->composerBooleanState($environment[$name] ?? null);
            if (in_array($state, ['empty', 'false', 'invalid'], true)) {
                $errors[] = "$name has an invalid or weakening value";
            }
        }

        foreach (['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING', 'COMPOSER_NO_AUDIT'] as $name) {
            if (!array_key_exists($name, $environment)) {
                continue;
            }

            if ($this->rawNoFlagState($environment[$name]) === 'non-empty') {
                $errors[] = "$name has a weakening non-empty value";
            }
        }

        if (array_key_exists('COMPOSER_AUDIT_ABANDONED', $environment) && $environment['COMPOSER_AUDIT_ABANDONED'] !== 'fail') {
            $errors[] = 'COMPOSER_AUDIT_ABANDONED must be exactly fail when present';
        }

        return $errors;
    }

    private function composerBooleanState(?string $value): string
    {
        if ($value === null) {
            return 'unset';
        }

        return match ($value) {
            '' => 'empty',
            '0', 'false', 'off' => 'false',
            '1', 'true', 'on' => 'true',
            default => 'invalid',
        };
    }

    private function rawNoFlagState(string $value): string
    {
        return $value === '' || $value === '0' ? 'safe' : 'non-empty';
    }

    public function run(string $root): int
    {
        $path = $root . '/composer.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            fwrite(STDERR, "Unable to read $path\n");

            return 1;
        }

        $executable = $this->composerExecutable();
        if ($executable === null) {
            fwrite(STDERR, "Composer executable was not found in PATH\n");

            return 1;
        }

        $version = $this->composerVersion($executable);
        if ($version === null) {
            fwrite(STDERR, "Unable to read version from Composer executable: $executable\n");

            return 1;
        }

        $errors = $this->verifyJson($contents, $version, $this->environment());
        if ($errors !== []) {
            fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);

            return 1;
        }

        printf("Composer policy verification passed (%s).%s", $version, PHP_EOL);

        return 0;
    }

    private function composerExecutable(): ?string
    {
        $output = [];
        exec('command -v composer', $output, $status);

        return $status === 0 && isset($output[0]) && is_executable($output[0]) ? $output[0] : null;
    }

    private function composerVersion(string $executable): ?string
    {
        $process = proc_open([$executable, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return $status === 0 ? $output : null;
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $environment = [];
        foreach (['COMPOSER_POLICY', 'COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING', 'COMPOSER_POLICY_ADVISORIES_BLOCK', 'COMPOSER_POLICY_MALWARE_BLOCK', 'COMPOSER_NO_AUDIT', 'COMPOSER_AUDIT_ABANDONED', 'COMPOSER_POLICY_ABANDONED_BLOCK'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = getcwd();
    exit((new ComposerPolicyVerifier())->run($root));
}
