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
    'reject assignment array bracket' => [false, "<?php\n\$value =\n[\n    1,\n];\n"],
    'reject return array bracket' => [false, "<?php\nreturn\n[\n    1,\n];\n"],
    'reject argument array bracket' => [false, "<?php\nconsume(\n[\n    1,\n]\n);\n"],
    'reject nested array bracket' => [false, "<?php\n\$value = [\n[\n    1,\n],\n];\n"],
    'reject arrow array bracket' => [false, "<?php\n\$value = fn(): array =>\n[\n    1,\n];\n"],
    'reject conditional array bracket' => [false, "<?php\n\$value = \$condition ?\n[\n    1,\n] : [];\n"],
    'reject cast expression array bracket' => [false, "<?php\n\$value = (array)\n[\n    1,\n];\n"],
    'reject unary expression array bracket' => [false, "<?php\n\$value = !\n[\n    1,\n];\n"],
    'array access is not a literal' => [true, "<?php\n\$value = \$items[\n    \$key\n];\n"],
    'attribute syntax is not an array literal' => [true, "<?php\n#[Example]\nclass Sample {}\n"],
    'destructuring syntax is not an array literal' => [true, "<?php\n[\n    \$first,\n    \$second,\n] = \$values;\n"],
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
