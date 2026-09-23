<?php

declare(strict_types=1);

require __DIR__ . '/verify-per-cs31.php';

$root = sys_get_temp_dir() . '/php-slug-per-cs31-regression';
if (is_dir($root)) {
    foreach (glob($root . '/*.php') ?: [] as $file) {
        unlink($file);
    }
    rmdir($root);
}
mkdir($root, 0755, true);

$verifier = new PerCs31Verifier();
$pass = 0;
$fail = 0;

$cases = [
    'switch ordinary case' => [true, <<<'PHP'
<?php
switch ($value) {
    case 1:
        echo 'one';
        break;
}
PHP],
    'switch final terminating case' => [true, <<<'PHP'
<?php
switch ($value) {
    default:
        return;
}
PHP],
    'switch deliberate fall-through' => [true, <<<'PHP'
<?php
switch ($value) {
    case 1:
        echo 'one';
        // no break
    case 2:
        echo 'two';
        break;
}
PHP],
    'switch multiline condition' => [true, <<<'PHP'
<?php
switch (true) {
    case (
        $left === 1
        && $right === 2
    ):
        echo 'match';
        break;
}
PHP],
    'switch nested blocks' => [true, <<<'PHP'
<?php
switch ($value) {
    case 1:
        if ($enabled) {
            echo 'enabled';
        }
        $worker->run();
        break;
}
PHP],
    'clone SHOULD is non-failing' => [true, '<?php $copy = clone $object;'],
    'reject wrapped case body' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1: {
        echo 'one';
        break;
    }
}
PHP],
    'reject final case without termination' => [false, <<<'PHP'
<?php
switch ($value) {
    default:
        echo 'default';
}
PHP],
    'reject undocumented fall-through' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        echo 'one';
    case 2:
        break;
}
PHP],
    'reject malformed multiline condition' => [false, <<<'PHP'
<?php
switch ($value) {
    case $left === 1
        && $right === 2:
        break;
}
PHP],
    'reject nested conditional termination' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        if ($enabled) {
            return;
        }
        echo 'still reaches case end';
}
PHP],
    'reject termination followed by statement' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        break;
        echo 'unreachable but structurally present';
}
PHP],
    'reject nested loop break as case termination' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        while ($running) {
            break;
        }
}
PHP],
    'direct final return pass' => [true, <<<'PHP'
<?php
switch ($value) {
    case 1:
        return;
}
PHP],
    'direct return value pass' => [true, "<?php\nswitch (\$value) { case 1: return \$value; }\n"],
    'direct return call pass' => [true, "<?php\nswitch (\$value) { case 1: return foo(); }\n"],
    'direct throw variable pass' => [true, "<?php\nswitch (\$value) { case 1: throw \$exception; }\n"],
    'direct throw expression pass' => [true, "<?php\nswitch (\$value) { case 1: throw new RuntimeException(); }\n"],
    'direct break pass' => [true, "<?php\nswitch (\$value) { case 1: break; }\n"],
    'direct break level pass' => [true, "<?php\nswitch (\$value) { case 1: break 2; }\n"],
    'direct continue pass' => [true, "<?php\nswitch (\$value) { case 1: continue; }\n"],
    'direct continue level pass' => [true, "<?php\nswitch (\$value) { case 1: continue 2; }\n"],
    'direct goto pass' => [true, "<?php\nswitch (\$value) { case 1: goto label; }\n"],
    'direct exit pass' => [true, "<?php\nswitch (\$value) { case 1: exit; }\n"],
    'direct exit call pass' => [true, "<?php\nswitch (\$value) { case 1: exit(1); }\n"],
    'anonymous class attributes pass' => [true, <<<'PHP'
<?php
$value = new
    #[Example]
    class {};
PHP],
    'multiple anonymous class attributes pass' => [true, <<<'PHP'
<?php
$value = new
    #[First]
    #[Second]
    class {};
PHP],
    'reject attribute on new line' => [false, <<<'PHP'
<?php
$value = new #[Example] class {};
PHP],
    'reject class on attribute line' => [false, <<<'PHP'
<?php
$value = new
    #[Example] class {};
PHP],
    'reject attribute indentation' => [false, <<<'PHP'
<?php
$value = new
  #[Example]
class {};
PHP],
    'reject inconsistent multiple attribute indentation' => [false, <<<'PHP'
<?php
$value = new
    #[First]
  #[Second]
    class {};
PHP],
    'reject class indentation different from attributes' => [false, <<<'PHP'
<?php
$value = new
    #[First]
        class {};
PHP],
    'enum private and public constants pass' => [true, <<<'PHP'
<?php
enum Sample {
    private const PRIVATE_VALUE = 1;
    public const PUBLIC_VALUE = 2;
}
PHP],
    'reject enum protected constant' => [false, <<<'PHP'
<?php
enum Sample {
    protected const VALUE = 1;
}
PHP],
    'nested protected constant is not enum-level' => [true, <<<'PHP'
<?php
enum Sample {
    public function build(): object {
        return new class {
            protected const VALUE = 1;
        };
    }
}
PHP],
    'single multiline array argument pass' => [true, "<?php\nconsume([\n    1,\n]);\n"],
    'single multiline closure argument pass' => [true, "<?php\nconsume(function (): void {\n});\n"],
    'reject split scalar argument list first inline' => [false, "<?php\nconsume(\$first,\n    \$second);\n"],
    'reject split scalar arguments on one line' => [false, "<?php\nconsume(\n    \$first, \$second\n);\n"],
    'reject split scalar arguments after nested value' => [false, "<?php\nconsume([\n    1,\n], \$second, \$third\n);\n"],
    'split call exact indentation pass' => [true, "<?php\nconsume(\n    \$first,\n    \$second,\n);\n"],
    'reject split call under-indentation' => [false, "<?php\nconsume(\n  \$first,\n    \$second,\n);\n"],
    'reject split call over-indentation' => [false, "<?php\nconsume(\n      \$first,\n    \$second,\n);\n"],
    'reject split call inconsistent indentation' => [false, "<?php\nconsume(\n    \$first,\n      \$second,\n);\n"],
    'split named function parameters exact indentation pass' => [true, <<<'PHP'
<?php
function build(
    $first,
    $second,
): void {}
PHP],
    'reject named function parameter indentation' => [false, <<<'PHP'
<?php
function build(
  $first,
    $second,
): void {}
PHP],
    'split named method parameters exact indentation pass' => [true, <<<'PHP'
<?php
final class Builder
{
    public function build(
        $first,
        $second,
    ): void {}
}
PHP],
    'split anonymous closure parameters pass' => [true, <<<'PHP'
<?php
$closure = function (
    $first,
    $second,
) {};
PHP],
    'reject anonymous closure parameter split' => [false, "<?php\n\$closure = function (\$first,\n    \$second,\n) {};\n"],
    'reject anonymous closure parameters on one line' => [false, "<?php\n\$closure = function (\n    \$first, \$second\n) {};\n"],
    'closure use list exact indentation pass' => [true, <<<'PHP'
<?php
$closure = function () use (
    $first,
    $second,
) {};
PHP],
    'reject closure use first variable inline' => [false, "<?php\n\$closure = function () use (\$first,\n    \$second,\n) {};\n"],
    'reject closure use variables on one line' => [false, "<?php\n\$closure = function () use (\n    \$first, \$second,\n) {};\n"],
    'reject closure use under-indentation' => [false, "<?php\n\$closure = function () use (\n  \$first,\n    \$second,\n) {};\n"],
    'reject closure use over-indentation' => [false, "<?php\n\$closure = function () use (\n      \$first,\n    \$second,\n) {};\n"],
    'reject closure use inconsistent indentation' => [false, "<?php\n\$closure = function () use (\n    \$first,\n      \$second,\n) {};\n"],
    'anonymous class constructor arguments pass' => [true, <<<'PHP'
<?php
$instance = new class (
    $first,
    $second,
) {};
PHP],
    'reject anonymous constructor first argument inline' => [false, "<?php\n\$instance = new class (\$first,\n    \$second,\n) {};\n"],
    'reject anonymous constructor arguments on one line' => [false, "<?php\n\$instance = new class (\n    \$first, \$second,\n) {};\n"],
    'reject anonymous constructor under-indentation' => [false, "<?php\n\$instance = new class (\n  \$first,\n    \$second,\n) {};\n"],
    'reject anonymous constructor over-indentation' => [false, "<?php\n\$instance = new class (\n      \$first,\n    \$second,\n) {};\n"],
    'reject anonymous constructor inconsistent indentation' => [false, "<?php\n\$instance = new class (\n    \$first,\n      \$second,\n) {};\n"],
    'reject named function closing parenthesis placement' => [false, "<?php\nfunction build(\n    \$first,\n    \$second): void {}\n"],
    'reject closure closing parenthesis placement' => [false, "<?php\n\$closure = function (\n    \$first,\n    \$second) {};\n"],
    'reject closure use closing parenthesis placement' => [false, "<?php\n\$closure = function () use (\n    \$first,\n    \$second) {};\n"],
    'parenthesized expression is not an argument list' => [true, "<?php\n\$value = (\$first\n    + \$second);\n"],
    'reject assignment array bracket' => [false, "<?php\n\$value =\n[\n    1,\n];\n"],
    'reject return array bracket' => [false, "<?php\nreturn\n[\n    1,\n];\n"],
    'reject argument array bracket' => [false, "<?php\nconsume(\n[\n    1,\n]\n);\n"],
    'reject nested array bracket' => [false, "<?php\n\$value = [\n[\n    1,\n],\n];\n"],
    'reject arrow array bracket' => [false, "<?php\n\$value = fn(): array =>\n[\n    1,\n];\n"],
    'reject conditional array bracket' => [false, "<?php\n\$value = \$condition ?\n[\n    1,\n] : [];\n"],
    'reject cast expression array bracket' => [false, "<?php\n\$value = (array)\n[\n    1,\n];\n"],
    'reject unary expression array bracket' => [false, "<?php\n\$value = !\n[\n    1,\n];\n"],
    'reject yield from array bracket' => [false, <<<'PHP'
<?php
function values(): iterable
{
    yield from
    [
        1,
    ];
}
PHP],
    'reject unpacked argument array bracket' => [false, "<?php\nconsume(...\n[\n    1,\n]);\n"],
    'reject conditional throw expression as case termination' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        $value = $condition ? throw $exception : 1;
}
PHP],
    'reject coalescing exit expression as case termination' => [false, <<<'PHP'
<?php
switch ($value) {
    case 1:
        $value = foo() ?? exit();
}
PHP],
    'array access is not a literal' => [true, "<?php\n\$value = \$items[\n    \$key\n];\n"],
    'attribute syntax is not an array literal' => [true, "<?php\n#[Example]\nclass Sample {}\n"],
    'destructuring syntax is not an array literal' => [true, "<?php\n[\n    \$first,\n    \$second,\n] = \$values;\n"],
    'foreach destructuring is not an array literal' => [true, <<<'PHP'
<?php
foreach ($rows as
[
    $first,
    $second,
]) {
}
PHP],
    'nested destructuring is not an array literal' => [true, <<<'PHP'
<?php
[$first, [$second, $third]] = $values;
PHP],
    'nested foreach destructuring is not an array literal' => [true, <<<'PHP'
<?php
foreach ($rows as
[
    $first,
    [
        $second,
        $third,
    ],
]) {
}
PHP],
];

foreach ($cases as $name => [$expectedPass, $source]) {
    $file = $root . '/' . sha1($name) . '.php';
    file_put_contents($file, $source);
    $errors = $verifier->verifyFiles([$file]);
    $actualPass = $errors === [];

    if ($actualPass === $expectedPass) {
        $pass++;
        continue;
    }

    $fail++;
    fwrite(STDERR, "FAIL {$name}: " . implode(' | ', $errors) . PHP_EOL);
}

$invalid = $root . '/invalid.php';
file_put_contents($invalid, '<?php function broken( {');
$invalidErrors = $verifier->verifyFiles([$invalid]);
if ($invalidErrors !== []) {
    $pass++;
} else {
    $fail++;
    fwrite(STDERR, "FAIL invalid source was accepted" . PHP_EOL);
}

$unreadableErrors = $verifier->verifyFiles([$root . '/does-not-exist.php']);
if ($unreadableErrors !== []) {
    $pass++;
} else {
    $fail++;
    fwrite(STDERR, "FAIL unreadable source was accepted" . PHP_EOL);
}

foreach (glob($root . '/*.php') ?: [] as $file) {
    unlink($file);
}
rmdir($root);

printf("PER-CS 3.1 regression cases: %d PASS, %d FAIL.%s", $pass, $fail, PHP_EOL);
exit($fail === 0 ? 0 : 1);
