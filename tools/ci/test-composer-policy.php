<?php

declare(strict_types=1);

require __DIR__ . '/verify-composer-policy.php';

$verifier = new ComposerPolicyVerifier();
$policy = [
    'config' => [
        'policy' => [
            'advisories' => ['block' => true, 'audit' => 'fail'],
            'malware' => ['block' => true, 'block-scope' => 'all', 'audit' => 'fail'],
            'abandoned' => ['block' => false, 'audit' => 'fail'],
        ],
    ],
];

$cases = [
    'exact manifest policy' => [true, $policy, 'Composer version 2.10.0', []],
    'Composer 2.10.x higher' => [true, $policy, 'Composer version 2.10.9', []],
    'Composer newer 2.x' => [true, $policy, 'Composer version 2.11.0', []],
    'no weakening overrides' => [true, $policy, 'Composer version 2.10.0', []],
    "COMPOSER_POLICY='' is allowed" => [true, $policy, 'Composer version 2.10.0', ['COMPOSER_POLICY' => '']],
    "COMPOSER_POLICY_ADVISORIES_BLOCK='' is allowed" => [true, $policy, 'Composer version 2.10.0', ['COMPOSER_POLICY_ADVISORIES_BLOCK' => '']],
    "COMPOSER_POLICY_MALWARE_BLOCK='' is allowed" => [true, $policy, 'Composer version 2.10.0', ['COMPOSER_POLICY_MALWARE_BLOCK' => '']],
    'abandoned audit fail' => [true, $policy, 'Composer version 2.10.0', ['COMPOSER_AUDIT_ABANDONED' => 'fail']],
    'abandoned audit invalid' => [false, $policy, 'Composer version 2.10.0', ['COMPOSER_AUDIT_ABANDONED' => 'unexpected']],
    'abandoned audit empty' => [false, $policy, 'Composer version 2.10.0', ['COMPOSER_AUDIT_ABANDONED' => '']],
    'abandoned block may be disabled' => [true, $policy, 'Composer version 2.10.0', ['COMPOSER_POLICY_ABANDONED_BLOCK' => '0']],
    'missing policy' => [false, ['config' => []], 'Composer version 2.10.0', []],
    'policy false' => [false, ['config' => ['policy' => false]], 'Composer version 2.10.0', []],
    'Composer 2.9 rejected' => [false, $policy, 'Composer version 2.9.9', []],
    'advisories block false' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'advisories' => ['block' => false, 'audit' => 'fail']]]], 'Composer version 2.10.0', []],
    'advisories audit report' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'advisories' => ['block' => true, 'audit' => 'report']]]], 'Composer version 2.10.0', []],
    'advisories audit ignore' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'advisories' => ['block' => true, 'audit' => 'ignore']]]], 'Composer version 2.10.0', []],
    'malware block false' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'malware' => ['block' => false, 'block-scope' => 'all', 'audit' => 'fail']]]], 'Composer version 2.10.0', []],
    'malware scope not all' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'malware' => ['block' => true, 'block-scope' => 'direct', 'audit' => 'fail']]]], 'Composer version 2.10.0', []],
    'malware audit not fail' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'malware' => ['block' => true, 'block-scope' => 'all', 'audit' => 'report']]]], 'Composer version 2.10.0', []],
    'abandoned audit report' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'abandoned' => ['block' => false, 'audit' => 'report']]]], 'Composer version 2.10.0', []],
    'abandoned audit ignore' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'abandoned' => ['block' => false, 'audit' => 'ignore']]]], 'Composer version 2.10.0', []],
    'abandoned block must be boolean' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'abandoned' => ['block' => 'false', 'audit' => 'fail']]]], 'Composer version 2.10.0', []],
    'legacy audit rejected' => [false, ['config' => [...$policy['config'], 'audit' => ['abandoned' => 'report']]], 'Composer version 2.10.0', []],
    'ignore entry rejected' => [false, ['config' => ['policy' => [...$policy['config']['policy'], 'advisories' => ['block' => true, 'audit' => 'fail', 'ignore' => ['CVE-ignored']]]]], 'Composer version 2.10.0', []],
];

foreach ([
    ['COMPOSER_POLICY', '0', false],
    ['COMPOSER_POLICY', 'false', false],
    ['COMPOSER_POLICY', 'off', false],
    ['COMPOSER_POLICY', '1', true],
    ['COMPOSER_POLICY', 'true', true],
    ['COMPOSER_POLICY', 'on', true],
    ['COMPOSER_POLICY_ADVISORIES_BLOCK', '0', false],
    ['COMPOSER_POLICY_ADVISORIES_BLOCK', 'false', false],
    ['COMPOSER_POLICY_ADVISORIES_BLOCK', 'off', false],
    ['COMPOSER_POLICY_ADVISORIES_BLOCK', '1', true],
    ['COMPOSER_POLICY_ADVISORIES_BLOCK', 'true', true],
    ['COMPOSER_POLICY_ADVISORIES_BLOCK', 'on', true],
    ['COMPOSER_POLICY_MALWARE_BLOCK', '0', false],
    ['COMPOSER_POLICY_MALWARE_BLOCK', 'false', false],
    ['COMPOSER_POLICY_MALWARE_BLOCK', 'off', false],
    ['COMPOSER_POLICY_MALWARE_BLOCK', '1', true],
    ['COMPOSER_POLICY_MALWARE_BLOCK', 'true', true],
    ['COMPOSER_POLICY_MALWARE_BLOCK', 'on', true],
    ['COMPOSER_NO_BLOCKING', '0', true],
    ['COMPOSER_NO_BLOCKING', '1', false],
    ['COMPOSER_NO_BLOCKING', 'true', false],
    ['COMPOSER_NO_BLOCKING', 'on', false],
    ['COMPOSER_NO_BLOCKING', 'false', false],
    ['COMPOSER_NO_BLOCKING', 'off', false],
    ['COMPOSER_NO_SECURITY_BLOCKING', '0', true],
    ['COMPOSER_NO_SECURITY_BLOCKING', '1', false],
    ['COMPOSER_NO_SECURITY_BLOCKING', 'true', false],
    ['COMPOSER_NO_SECURITY_BLOCKING', 'on', false],
    ['COMPOSER_NO_SECURITY_BLOCKING', 'false', false],
    ['COMPOSER_NO_SECURITY_BLOCKING', 'off', false],
    ['COMPOSER_NO_AUDIT', '0', true],
    ['COMPOSER_NO_AUDIT', '1', false],
    ['COMPOSER_NO_AUDIT', 'true', false],
    ['COMPOSER_NO_AUDIT', 'on', false],
    ['COMPOSER_NO_AUDIT', 'false', false],
    ['COMPOSER_NO_AUDIT', 'off', false],
    ['COMPOSER_AUDIT_ABANDONED', 'ignore', false],
    ['COMPOSER_AUDIT_ABANDONED', 'report', false],
] as [$name, $value, $expectedPass]) {
    $cases["environment $name=$value"] = [$expectedPass, $policy, 'Composer version 2.10.0', [$name => $value]];
}

$cases['COMPOSER_NO_SECURITY_BLOCKING=0 is allowed'] = [true, $policy, 'Composer version 2.10.0', ['COMPOSER_NO_SECURITY_BLOCKING' => '0']];

if ($verifier->verifyJson('{', 'Composer version 2.10.0', []) === []) {
    fwrite(STDERR, "Failed regression case: malformed JSON\n");
    exit(1);
}

$passed = 0;
foreach ($cases as $name => [$expectedPass, $manifest, $version, $environment]) {
    $actualPass = $verifier->verify($manifest, $version, $environment) === [];

    if ($actualPass !== $expectedPass) {
        fwrite(STDERR, "Failed regression case: $name\n");
        exit(1);
    }

    $passed++;
}

printf("Composer policy regression passed (%d cases).%s", $passed, PHP_EOL);
