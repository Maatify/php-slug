<?php

declare(strict_types=1);

/**
 * Supplemental checks for the PER Coding Style 3.1 obligations not established
 * by PHP-CS-Fixer's explicit @PER-CS3x0 ruleset.
 */

final class PerCs31Verifier
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $files = [];

    public function run(string $root): int
    {
        $this->files = $this->phpFiles($root);

        foreach ($this->files as $file) {
            $this->verifyFile($file);
        }

        if ($this->errors !== []) {
            fwrite(STDERR, implode(PHP_EOL, $this->errors) . PHP_EOL);

            return 1;
        }

        printf("PER-CS 3.1 supplemental verification passed (%d PHP files).%s", count($this->files), PHP_EOL);

        return 0;
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $files = [$root . '/.php-cs-fixer.php'];

        foreach (['src', 'tests', 'examples', 'tools'] as $directory) {
            $path = $root . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function verifyFile(string $file): void
    {
        $source = file_get_contents($file);

        if ($source === false) {
            $this->errors[] = $this->relative($file) . ': unable to read file';

            return;
        }

        $tokens = token_get_all($source, TOKEN_PARSE);
        $this->verifyCloneParentheses($file, $tokens);
        $this->verifySwitches($file, $tokens);
        $this->verifyPipeOperator($file, $tokens);
        $this->verifyAnonymousClassAttributes($file, $tokens);
        $this->verifyEnumConstantVisibility($file, $tokens);
        $this->verifyArrayOpeningBracket($file, $tokens);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyCloneParentheses(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CLONE) {
                continue;
            }

            $next = $this->nextMeaningful($tokens, $index);

            if ($next === null || $tokens[$next] !== '(') {
                $this->error($file, $token[2], 'clone() SHOULD use parentheses under PER-CS 3.1');
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifySwitches(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_SWITCH) {
                continue;
            }

            $open = $this->nextToken($tokens, $index, '{');

            if ($open === null) {
                continue;
            }

            $depth = 1;
            $cases = [];

            for ($cursor = $open + 1, $count = count($tokens); $cursor < $count && $depth > 0; $cursor++) {
                $current = $tokens[$cursor];

                if ($current === '{') {
                    $depth++;
                } elseif ($current === '}') {
                    $depth--;
                }

                if ($depth === 1 && is_array($current) && in_array($current[0], [T_CASE, T_DEFAULT], true)) {
                    $cases[] = $cursor;
                }
            }

            foreach ($cases as $caseOffset => $caseIndex) {
                $nextCase = $cases[$caseOffset + 1] ?? ($cursor - 1);
                $caseToken = $tokens[$caseIndex];
                $bodyStart = $this->caseBodyStart($tokens, $caseIndex, $nextCase);

                if ($bodyStart === null) {
                    continue;
                }

                if ($tokens[$bodyStart] === '{') {
                    $this->error($file, $caseToken[2], 'case bodies MUST NOT be wrapped in braces');
                }

                if ($caseToken[0] === T_CASE && $this->caseHeaderIsMultiline($tokens, $caseIndex, $bodyStart)) {
                    $header = $this->significantText($tokens, $caseIndex, $bodyStart);

                    if (!str_contains($header, 'case (') || !str_ends_with(trim($header), '):')) {
                        $this->error($file, $caseToken[2], 'multi-line case conditions MUST use the PER-CS 3.1 parenthesized layout');
                    }
                }

                $bodyEnd = $this->caseBodyEnd($tokens, $bodyStart, $nextCase);
                $body = $this->significantTokens($tokens, $bodyStart, $bodyEnd);

                if ($body === []) {
                    continue;
                }

                if (!$this->hasTerminatingStatement($body)) {
                    if ($nextCase < $cursor - 1 && !$this->hasFallThroughComment($tokens, $bodyStart, $bodyEnd)) {
                        $this->error($file, $caseToken[2], 'non-empty fall-through case MUST have an intentional fall-through comment');
                    } else {
                        $this->error($file, $caseToken[2], 'every non-empty case MUST have a terminating statement');
                    }
                }
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyPipeOperator(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if ($token !== '|' || ($tokens[$index + 1] ?? null) !== '>') {
                continue;
            }

            $line = is_array($token) ? $token[2] : $this->lineBefore($tokens, $index);
            $this->error($file, $line, 'the |> operator is not applicable while this package supports PHP ^8.4');
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyAnonymousClassAttributes(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $attribute = $this->nextMeaningful($tokens, $index);

            if ($attribute === null || !is_array($tokens[$attribute]) || $tokens[$attribute][0] !== T_ATTRIBUTE) {
                continue;
            }

            $class = $this->nextMeaningfulAfterAttribute($tokens, $attribute);

            if ($class === null || !is_array($tokens[$class]) || $tokens[$class][0] !== T_CLASS) {
                $this->error($file, $token[2], 'anonymous-class attributes MUST be followed by the class declaration on a new, equally indented line');
                continue;
            }

            if ($tokens[$attribute][2] <= $token[2] || $tokens[$class][2] <= $tokens[$attribute][2]) {
                $this->error($file, $token[2], 'anonymous-class attributes MUST start after new and precede class on new lines');
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyEnumConstantVisibility(string $file, array $tokens): void
    {
        $enumPending = false;
        $braceScopes = [];

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_ENUM) {
                $enumPending = true;
            }
            if ($token === '{') {
                $braceScopes[] = $enumPending;
                $enumPending = false;
            } elseif ($token === '}') {
                array_pop($braceScopes);
            } elseif (is_array($token) && $token[0] === T_PROTECTED && in_array(true, $braceScopes, true)) {
                $next = $this->nextMeaningful($tokens, $index);

                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_CONST) {
                    $this->error($file, $token[2], 'enum non-public constants MUST use private, not protected');
                }
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyArrayOpeningBracket(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if ($token !== '[' || $index === 0) {
                continue;
            }

            $previous = $this->previousMeaningful($tokens, $index);

            if ($previous === null || !$this->isArrayOpeningContext($tokens[$previous])) {
                continue;
            }

            $line = $this->tokenLine($tokens, $index);
            $next = $this->nextMeaningful($tokens, $index);

            if (
                $this->lineOnlyContainsWhitespaceBefore($tokens, $index)
                && $next !== null
                && $this->tokenLine($tokens, $next) > $line
            ) {
                $this->error($file, $line, 'a multi-line array opening bracket MUST NOT be on its own line');
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function nextMeaningful(array $tokens, int $index): ?int
    {
        for ($index++; isset($tokens[$index]); $index++) {
            if (!is_array($tokens[$index]) || !in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function nextToken(array $tokens, int $index, string $expected): ?int
    {
        $next = $this->nextMeaningful($tokens, $index);

        return $next !== null && $tokens[$next] === $expected ? $next : null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function previousMeaningful(array $tokens, int $index): ?int
    {
        for ($index--; $index >= 0; $index--) {
            if (!is_array($tokens[$index]) || !in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function caseBodyStart(array $tokens, int $caseIndex, int $nextCase): ?int
    {
        for ($index = $caseIndex + 1; $index < $nextCase; $index++) {
            if ($tokens[$index] === ':') {
                return $this->nextMeaningful($tokens, $index);
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function caseBodyEnd(array $tokens, int $bodyStart, int $nextCase): int
    {
        for ($index = $bodyStart; $index < $nextCase; $index++) {
            if ($tokens[$index] === '}') {
                return $index;
            }
        }

        return $nextCase;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function caseHeaderIsMultiline(array $tokens, int $caseIndex, int $bodyStart): bool
    {
        $case = $tokens[$caseIndex];
        $body = $tokens[$bodyStart];

        return is_array($case) && is_array($body) && $body[2] > $case[2];
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function significantTokens(array $tokens, int $start, int $end): array
    {
        return array_values(array_filter(
            array_slice($tokens, $start, $end - $start),
            static fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function significantText(array $tokens, int $start, int $end): string
    {
        return implode('', array_map(
            static fn(array|string $token): string => is_array($token) ? $token[1] : $token,
            $this->significantTokens($tokens, $start, $end),
        ));
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function hasTerminatingStatement(array $tokens): bool
    {
        foreach (array_reverse($tokens) as $token) {
            if (is_array($token) && in_array($token[0], [T_BREAK, T_CONTINUE, T_RETURN, T_THROW, T_GOTO, T_EXIT], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function hasFallThroughComment(array $tokens, int $start, int $end): bool
    {
        foreach (array_slice($tokens, $start, $end - $start) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) && preg_match('/no\s+break|fall[- ]?through|fallthrough|deliberate/i', $token[1])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function nextMeaningfulAfterAttribute(array $tokens, int $attribute): ?int
    {
        $depth = 0;

        for ($index = $attribute; isset($tokens[$index]); $index++) {
            if ($tokens[$index] === '[') {
                $depth++;
            } elseif ($tokens[$index] === ']') {
                $depth--;
            }

            if ($index > $attribute && $depth === 0) {
                return $this->nextMeaningful($tokens, $index);
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function lineBefore(array $tokens, int $index): int
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if (is_array($tokens[$cursor])) {
                return $tokens[$cursor][2] + substr_count($tokens[$cursor][1], "\n");
            }
        }

        return 1;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function tokenLine(array $tokens, int $index): int
    {
        $line = 1;

        foreach (array_slice($tokens, 0, $index + 1) as $token) {
            if (is_array($token)) {
                $line += substr_count($token[1], "\n");
            } else {
                $line += substr_count($token, "\n");
            }
        }

        return $line - (is_array($tokens[$index]) ? substr_count($tokens[$index][1], "\n") : substr_count((string) $tokens[$index], "\n"));
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function lineOnlyContainsWhitespaceBefore(array $tokens, int $index): bool
    {
        $line = $this->lineBefore($tokens, $index);
        $text = '';

        foreach (array_slice($tokens, 0, $index) as $token) {
            $text .= is_array($token) ? $token[1] : $token;
        }

        $lineText = substr($text, strrpos($text, "\n") + 1);

        return trim($lineText) === '' && $line > 1;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private function isArrayOpeningContext(array|string $token): bool
    {
        return $token === '='
            || $token === '=>'
            || $token === ','
            || $token === '('
            || (is_array($token) && $token[0] === T_RETURN);
    }

    private function error(string $file, int $line, string $message): void
    {
        $this->errors[] = sprintf('%s:%d: %s', $this->relative($file), $line, $message);
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(getcwd() . '/', '', $file), './');
    }
}

exit((new PerCs31Verifier())->run(dirname(__DIR__, 2)));
