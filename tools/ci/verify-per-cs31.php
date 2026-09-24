<?php

declare(strict_types=1);

/** Supplemental PER-CS 3.1 checks not established by @PER-CS3x0. */
final class PerCs31Verifier
{
    /** @var list<string> */
    private array $errors = [];

    public function run(string $root): int
    {
        $files = $this->phpFiles($root);
        $errors = $this->verifyFiles($files);

        if ($errors !== []) {
            fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);

            return 1;
        }

        printf("PER-CS 3.1 supplemental verification passed (%d PHP files).%s", count($files), PHP_EOL);

        return 0;
    }

    /** @param list<string> $files */
    public function verifyFiles(array $files): array
    {
        $this->errors = [];

        foreach ($files as $file) {
            $this->verifyFile($file);
        }

        return $this->errors;
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
        if (!is_readable($file)) {
            $this->errors[] = $this->relative($file) . ': unable to read file';

            return;
        }

        $source = file_get_contents($file);

        if ($source === false) {
            $this->errors[] = $this->relative($file) . ': unable to read file';

            return;
        }

        try {
            /** @var list<array{0:int,1:string,2:int}|string> $tokens */
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (Throwable $exception) {
            $this->errors[] = $this->relative($file) . ': parse/tokenizer failure: ' . $exception->getMessage();

            return;
        }

        $this->verifyCloneParentheses($file, $tokens);
        $this->verifySwitches($file, $tokens);
        $this->verifyAnonymousClassAttributes($file, $source, $tokens);
        $this->verifyEnumConstantVisibility($file, $tokens);
        $this->verifyArrayOpeningBracket($file, $tokens);
        $this->verifyMethodArgumentLayout($file, $tokens);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyCloneParentheses(string $file, array $tokens): void
    {
        // SHOULD only: intentionally non-failing; no repository-local MUST.
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifySwitches(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_SWITCH) {
                continue;
            }

            $openParenthesis = $this->nextToken($tokens, $index, '(');
            $closeParenthesis = $openParenthesis === null ? null : $this->matchingToken($tokens, $openParenthesis, '(', ')');
            $openBrace = $closeParenthesis === null ? null : $this->nextToken($tokens, $closeParenthesis, '{');
            $closeBrace = $openBrace === null ? null : $this->matchingToken($tokens, $openBrace, '{', '}');

            if ($openBrace === null || $closeBrace === null) {
                $this->error($file, $token[2], 'switch body is not structurally closed');

                continue;
            }

            $cases = $this->directCaseTokens($tokens, $openBrace, $closeBrace);

            foreach ($cases as $caseOffset => $caseIndex) {
                $nextCase = $cases[$caseOffset + 1] ?? $closeBrace;
                $colon = $this->caseColon($tokens, $caseIndex, $nextCase);

                if ($colon === null) {
                    $this->error($file, $this->tokenLine($tokens, $caseIndex), 'case header has no terminating colon');

                    continue;
                }

                $bodyStart = $this->nextMeaningful($tokens, $colon) ?? $nextCase;

                if ($bodyStart < $nextCase && $tokens[$bodyStart] === '{') {
                    $this->error($file, $this->tokenLine($tokens, $caseIndex), 'case bodies MUST NOT be wrapped in braces');
                }

                $caseToken = $tokens[$caseIndex];

                if (is_array($caseToken) && $caseToken[0] === T_CASE && $this->tokenLine($tokens, $colon) > $caseToken[2]) {
                    $this->verifyMultilineCaseHeader($file, $tokens, $caseIndex, $colon);
                }

                if ($this->significantTokens($tokens, $bodyStart, $nextCase) === []) {
                    continue;
                }

                if (!$this->hasDirectCaseTermination($tokens, $bodyStart, $nextCase)) {
                    if ($nextCase < $closeBrace && $this->hasFallThroughComment($tokens, $bodyStart, $nextCase)) {
                        continue;
                    }

                    $message = $nextCase < $closeBrace
                        ? 'non-empty fall-through case MUST have an intentional fall-through comment'
                        : 'every non-empty case MUST have a terminating statement';
                    $this->error($file, $this->tokenLine($tokens, $caseIndex), $message);
                }
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function directCaseTokens(array $tokens, int $openBrace, int $closeBrace): array
    {
        $cases = [];
        $depth = 0;

        for ($index = $openBrace + 1; $index < $closeBrace; $index++) {
            $token = $tokens[$index];

            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            } elseif ($depth === 0 && is_array($token) && in_array($token[0], [T_CASE, T_DEFAULT], true)) {
                $cases[] = $index;
            }
        }

        return $cases;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function caseColon(array $tokens, int $caseIndex, int $limit): ?int
    {
        $nesting = [];
        $ternaries = 0;

        for ($index = $caseIndex + 1; $index < $limit; $index++) {
            $token = $tokens[$index];

            if ($token === '?' && $nesting === []) {
                $ternaries++;
            } elseif ($token === ':' && $nesting === []) {
                if ($ternaries > 0) {
                    $ternaries--;
                } else {
                    return $index;
                }
            } else {
                $this->updateListNesting($token, $nesting);
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyMultilineCaseHeader(string $file, array $tokens, int $caseIndex, int $colon): void
    {
        $first = $this->nextMeaningful($tokens, $caseIndex);
        $last = $this->previousMeaningful($tokens, $colon);

        if ($first === null || $last === null || $tokens[$first] !== '(' || $tokens[$last] !== ')') {
            $this->error($file, $this->tokenLine($tokens, $caseIndex), 'multi-line case conditions MUST use the PER-CS 3.1 parenthesized layout');

            return;
        }

        if ($this->tokenLine($tokens, $first) !== $this->tokenLine($tokens, $caseIndex) || $this->tokenLine($tokens, $last) !== $this->tokenLine($tokens, $colon)) {
            $this->error($file, $this->tokenLine($tokens, $caseIndex), 'multi-line case parentheses have invalid line placement');
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function hasDirectCaseTermination(array $tokens, int $start, int $end): bool
    {
        $braceDepth = 0;
        $parentheses = 0;
        $brackets = 0;
        $statement = [];
        $terminated = false;
        $pendingTerminator = false;

        for ($index = $start; $index < $end; $index++) {
            $token = $tokens[$index];

            if ($token === '{') {
                $braceDepth++;
                $statement = [];
                continue;
            }
            if ($token === '}') {
                $braceDepth--;
                $statement = [];
                continue;
            }
            if ($token === '(') {
                $parentheses++;
            } elseif ($token === ')') {
                $parentheses--;
            } elseif ($token === '[') {
                $brackets++;
            } elseif ($token === ']') {
                $brackets--;
            }

            if ($braceDepth !== 0) {
                continue;
            }

            if ($pendingTerminator) {
                if ($parentheses === 0 && $brackets === 0 && $token === ';') {
                    $terminated = true;
                    $pendingTerminator = false;
                    $statement = [];
                }

                continue;
            }

            if ($parentheses === 0 && $brackets === 0 && $token === ';') {
                $statement = [];

                continue;
            }

            if ($parentheses !== 0 || $brackets !== 0 || !is_array($token) || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($terminated) {
                return false;
            }

            if (in_array($token[0], [T_BREAK, T_CONTINUE, T_RETURN, T_THROW, T_GOTO, T_EXIT], true) && $statement === []) {
                $pendingTerminator = true;
            }

            $statement[] = $token;
        }

        return $terminated;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyAnonymousClassAttributes(string $file, string $source, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $attribute = $this->nextMeaningful($tokens, $index);

            if ($attribute === null || !is_array($tokens[$attribute]) || $tokens[$attribute][0] !== T_ATTRIBUTE) {
                continue;
            }

            $newLine = $token[2];
            $newIndent = $this->lineIndent($source, $newLine);
            $lastAttributeLine = $newLine;
            $attributeIndent = null;
            $cursor = $attribute;

            do {
                $attributeToken = $tokens[$cursor];
                $attributeLine = $attributeToken[2];
                $currentIndent = $this->lineIndent($source, $attributeLine);
                $attributeIndent ??= $currentIndent;

                if ($attributeLine <= $newLine || $currentIndent !== $newIndent + 4 || $currentIndent !== $attributeIndent) {
                    $this->error($file, $attributeLine, 'anonymous-class attributes MUST start on a more-indented line after new');
                }

                $end = $this->attributeEnd($tokens, $cursor);

                if ($end === null) {
                    $this->error($file, $attributeLine, 'anonymous-class attribute block is not structurally closed');

                    continue 2;
                }

                $lastAttributeLine = $this->tokenLine($tokens, $end);
                $cursor = $this->nextMeaningful($tokens, $end);
            } while ($cursor !== null && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_ATTRIBUTE);

            if ($cursor === null || !is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_CLASS) {
                $this->error($file, $lastAttributeLine, 'anonymous-class attributes MUST be followed by class');

                continue;
            }

            $classLine = $tokens[$cursor][2];

            if ($classLine <= $lastAttributeLine || $this->lineIndent($source, $classLine) !== $attributeIndent) {
                $this->error($file, $classLine, 'anonymous class MUST start on a new line with attribute indentation');
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function attributeEnd(array $tokens, int $attribute): ?int
    {
        $depth = 1;

        for ($index = $attribute + 1, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === '[') {
                $depth++;
            } elseif ($tokens[$index] === ']') {
                $depth--;
            }

            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyEnumConstantVisibility(string $file, array $tokens): void
    {
        $pendingEnum = false;
        $scopes = [];

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_ENUM) {
                $pendingEnum = true;
                continue;
            }

            if ($token === '{') {
                $scopes[] = $pendingEnum ? 'enum' : 'other';
                $pendingEnum = false;
                continue;
            }

            if ($token === '}') {
                array_pop($scopes);
                continue;
            }

            if (is_array($token) && $token[0] === T_PROTECTED && end($scopes) === 'enum') {
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

            if ($previous === null || !$this->isArrayLiteralOpening($tokens, $index, $previous)) {
                continue;
            }

            $line = $this->tokenLine($tokens, $index);
            $next = $this->nextMeaningful($tokens, $index);

            if ($next !== null && $this->tokenLine($tokens, $next) > $line && $this->lineOnlyContainsWhitespaceBefore($tokens, $index)) {
                $this->error($file, $line, 'a multi-line array opening bracket MUST NOT be on its own line');
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function isArrayLiteralOpening(array $tokens, int $index, int $previous): bool
    {
        $close = $this->matchingToken($tokens, $index, '[', ']');

        if ($close !== null) {
            $after = $this->nextMeaningful($tokens, $close);

            if ($after !== null && $tokens[$after] === '=') {
                return false;
            }
        }

        return !$this->isDestructuringOpening($tokens, $index, $previous) && !$this->isExpressionOperand($tokens[$previous]);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function isDestructuringOpening(array $tokens, int $index, int $previous): bool
    {
        $token = $tokens[$previous];

        if (is_array($token) && $token[0] === T_AS) {
            return true;
        }

        $close = $this->matchingToken($tokens, $index, '[', ']');

        if ($close !== null) {
            $after = $this->nextMeaningful($tokens, $close);

            if ($after !== null && $tokens[$after] === '=') {
                return true;
            }
        }

        if ($token !== ',' && !(is_array($token) && $token[0] === T_DOUBLE_ARROW)) {
            return false;
        }

        $enclosing = $this->enclosingSquareOpening($tokens, $index);

        if ($enclosing !== null && $this->isDestructuringOpening(
            $tokens,
            $enclosing,
            $this->previousMeaningful($tokens, $enclosing) ?? $enclosing,
        )) {
            return true;
        }

        return is_array($token) && $token[0] === T_DOUBLE_ARROW && $this->hasForeachAsBefore($tokens, $previous);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function hasForeachAsBefore(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (is_array($token) && $token[0] === T_AS) {
                return true;
            }

            if (in_array($token, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function enclosingSquareOpening(array $tokens, int $index): ?int
    {
        $depth = 0;

        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if ($tokens[$cursor] === ']') {
                $depth++;
            } elseif ($tokens[$cursor] === '[') {
                if ($depth === 0) {
                    return $cursor;
                }

                $depth--;
            }
        }

        return null;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private function isExpressionOperand(array|string $token): bool
    {
        if (in_array($token, [')', ']', '}'], true)) {
            return true;
        }

        if (!is_array($token)) {
            return false;
        }

        return in_array($token[0], [
            T_VARIABLE,
            T_STRING,
            T_STRING_VARNAME,
            T_CONSTANT_ENCAPSED_STRING,
            T_LNUMBER,
            T_DNUMBER,
            T_CLASS_C,
            T_METHOD_C,
            T_LINE,
            T_FILE,
            T_DIR,
            T_NS_C,
        ], true);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function verifyMethodArgumentLayout(string $file, array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if ($token !== '(') {
                continue;
            }

            $previous = $this->previousMeaningful($tokens, $index);

            if ($previous === null || ($listKind = $this->argumentListKind($tokens, $index, $previous)) === null) {
                continue;
            }

            $close = $this->matchingToken($tokens, $index, '(', ')');

            if ($close === null || !$this->hasTopLevelNewline($tokens, $index, $close)) {
                continue;
            }

            $starts = [];
            $nesting = [];
            $segmentStart = $this->nextMeaningful($tokens, $index);

            for ($cursor = $index + 1; $cursor < $close; $cursor++) {
                $current = $tokens[$cursor];

                if ($current === ',' && $nesting === []) {
                    if ($segmentStart !== null) {
                        $starts[] = $segmentStart;
                    }
                    $segmentStart = $this->nextMeaningful($tokens, $cursor);

                    if ($segmentStart === $close) {
                        $segmentStart = null;
                    }
                } else {
                    $this->updateListNesting($current, $nesting);
                }
            }

            if ($segmentStart !== null) {
                $starts[] = $segmentStart;
            }

            if ($starts === []) {
                continue;
            }

            $openLine = $this->tokenLine($tokens, $index);
            $openIndent = $this->lineIndentFromTokens($tokens, $index);
            $firstEnd = $this->argumentEnd($tokens, $starts[0], $close);

            if ($this->tokenLine($tokens, $starts[0]) <= $openLine && !$this->argumentHasMultilineNestedValue($tokens, $starts[0], $firstEnd)) {
                $this->error($file, $openLine, 'multiline argument lists MUST start their first argument on the next line');
            }

            if ($this->tokenLine($tokens, $starts[0]) > $openLine && $this->lineIndentFromTokens($tokens, $starts[0]) !== $openIndent + 4) {
                $this->error($file, $this->tokenLine($tokens, $starts[0]), 'multiline argument list items MUST be indented once relative to the opening construct');
            }

            for ($offset = 1; $offset < count($starts); $offset++) {
                $argumentEnd = $this->argumentEnd($tokens, $starts[$offset], $close);
                $sameLine = $this->tokenLine($tokens, $starts[$offset]) === $this->tokenLine($tokens, $starts[$offset - 1]);

                if ($sameLine && !$this->argumentHasMultilineNestedValue($tokens, $starts[$offset], $argumentEnd)) {
                    $this->error($file, $this->tokenLine($tokens, $starts[$offset]), 'multiline argument lists MUST have one argument per line');
                }

                if (!$sameLine && $this->lineIndentFromTokens($tokens, $starts[$offset]) !== $openIndent + 4) {
                    $this->error($file, $this->tokenLine($tokens, $starts[$offset]), 'multiline argument list items MUST be indented once relative to the opening construct');
                }
            }

            if ($listKind !== 'call' && $this->tokenLine($tokens, $close) <= $this->tokenLine($tokens, $starts[count($starts) - 1])) {
                $this->error($file, $this->tokenLine($tokens, $close), 'multiline declaration and closure lists MUST close on their own line after the final item');
            }
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function argumentListKind(array $tokens, int $index, int $previous): ?string
    {
        $token = $tokens[$previous];

        if (!is_array($token)) {
            if ($token === '&') {
                $beforeReference = $this->previousMeaningful($tokens, $previous);

                return $beforeReference !== null && is_array($tokens[$beforeReference]) && in_array($tokens[$beforeReference][0], [T_FUNCTION, T_FN], true)
                    ? 'declaration'
                    : null;
            }

            return in_array($token, [')', ']'], true) ? 'call' : null;
        }

        if ($this->isReferenceToken($token)) {
            $beforeReference = $this->previousMeaningful($tokens, $previous);

            return $beforeReference !== null && is_array($tokens[$beforeReference]) && in_array($tokens[$beforeReference][0], [T_FUNCTION, T_FN], true)
                ? 'declaration'
                : null;
        }

        if (in_array($token[0], [T_IF, T_SWITCH, T_WHILE, T_FOR, T_FOREACH, T_CATCH, T_MATCH], true)) {
            return null;
        }

        if ($token[0] === T_USE && $this->isClosureUseList($tokens, $previous)) {
            return 'closure-use';
        }

        if ($token[0] === T_CLASS) {
            $beforeClass = $this->previousMeaningfulAnonymousClassPrefix($tokens, $previous);

            return $beforeClass !== null && is_array($tokens[$beforeClass]) && $tokens[$beforeClass][0] === T_NEW
                ? 'anonymous-constructor'
                : null;
        }

        if ($token[0] === T_FUNCTION || $token[0] === T_FN) {
            return 'declaration';
        }

        if (in_array($token[0], [T_VARIABLE, T_STRING], true)) {
            return $this->isNamedDeclaration($tokens, $previous) ? 'declaration' : 'call';
        }

        if (defined('T_NAME_QUALIFIED') && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return 'call';
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function isNamedDeclaration(array $tokens, int $nameIndex): bool
    {
        $before = $this->previousMeaningful($tokens, $nameIndex);

        if ($before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION) {
            return true;
        }

        return $before !== null && $tokens[$before] === '&' && ($before = $this->previousMeaningful($tokens, $before)) !== null
            && is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function isClosureUseList(array $tokens, int $useIndex): bool
    {
        $close = $this->previousMeaningful($tokens, $useIndex);

        if ($close === null || $tokens[$close] !== ')') {
            return false;
        }

        $open = $this->openingParenthesis($tokens, $close);

        if ($open === null) {
            return false;
        }

        $before = $this->previousMeaningful($tokens, $open);

        if ($before !== null && $this->isReferenceToken($tokens[$before])) {
            $before = $this->previousMeaningful($tokens, $before);
        }

        return $before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private function isReferenceToken(array|string $token): bool
    {
        if ($token === '&') {
            return true;
        }

        return is_array($token) && (
            (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG') && $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)
            || (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') && $token[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)
        );
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function previousMeaningfulAnonymousClassPrefix(array $tokens, int $classIndex): ?int
    {
        $cursor = $this->previousMeaningful($tokens, $classIndex);

        while ($cursor !== null && $tokens[$cursor] === ']') {
            do {
                $cursor--;
            } while ($cursor >= 0 && (!is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_ATTRIBUTE));

            $cursor = $cursor >= 0 ? $this->previousMeaningful($tokens, $cursor) : null;
        }

        return $cursor;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function openingParenthesis(array $tokens, int $close): ?int
    {
        $depth = 0;

        for ($cursor = $close; $cursor >= 0; $cursor--) {
            if ($tokens[$cursor] === ')') {
                $depth++;
            } elseif ($tokens[$cursor] === '(') {
                $depth--;

                if ($depth === 0) {
                    return $cursor;
                }
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function hasTopLevelNewline(array $tokens, int $start, int $end): bool
    {
        $nesting = [];

        for ($index = $start + 1; $index < $end; $index++) {
            $token = $tokens[$index];

            if ($nesting === [] && is_array($token) && str_contains($token[1], "\n")) {
                return true;
            }

            $this->updateListNesting($token, $nesting);
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function argumentEnd(array $tokens, int $start, int $close): int
    {
        $nesting = [];

        for ($index = $start; $index < $close; $index++) {
            $token = $tokens[$index];

            if ($token === ',' && $nesting === []) {
                return $index;
            }

            $this->updateListNesting($token, $nesting);
        }

        return $close;
    }

    /** @param array{0:int,1:string,2:int}|string $token
     *  @param list<string> $nesting
     */
    private function updateListNesting(array|string $token, array &$nesting): void
    {
        if (is_array($token) && $token[0] === T_ATTRIBUTE) {
            $nesting[] = ']';

            return;
        }

        if (in_array($token, ['(', '[', '{'], true)) {
            $nesting[] = $token === '(' ? ')' : ($token === '[' ? ']' : '}');

            return;
        }

        if (in_array($token, [')', ']', '}'], true) && end($nesting) === $token) {
            array_pop($nesting);
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function argumentHasMultilineNestedValue(array $tokens, int $start, int $end): bool
    {
        for ($index = $start; $index < $end; $index++) {
            if (!in_array($tokens[$index], ['(', '[', '{'], true)) {
                continue;
            }

            $closing = $tokens[$index] === '(' ? ')' : ($tokens[$index] === '[' ? ']' : '}');
            $match = $this->matchingToken($tokens, $index, $tokens[$index], $closing);

            if ($match !== null && $this->tokenLine($tokens, $match) > $this->tokenLine($tokens, $index)) {
                return true;
            }
        }

        return false;
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
    private function nextToken(array $tokens, int $index, string $expected): ?int
    {
        $next = $this->nextMeaningful($tokens, $index);

        return $next !== null && $tokens[$next] === $expected ? $next : null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function matchingToken(array $tokens, int $open, string $opening, string $closing): ?int
    {
        $depth = 0;

        for ($index = $open, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === $opening) {
                $depth++;
            } elseif ($tokens[$index] === $closing) {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
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
    private function tokenLine(array $tokens, int $index): int
    {
        $line = 1;

        foreach (array_slice($tokens, 0, $index) as $token) {
            $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
        }

        return $line;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function lineIndentFromTokens(array $tokens, int $index): int
    {
        $text = '';

        foreach (array_slice($tokens, 0, $index) as $token) {
            $text .= is_array($token) ? $token[1] : $token;
        }

        $line = substr($text, strrpos($text, "\n") + 1);

        return strlen($line) - strlen(ltrim($line, " \t"));
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function lineOnlyContainsWhitespaceBefore(array $tokens, int $index): bool
    {
        $text = '';

        foreach (array_slice($tokens, 0, $index) as $token) {
            $text .= is_array($token) ? $token[1] : $token;
        }

        return trim(substr($text, strrpos($text, "\n") + 1)) === '';
    }

    private function lineIndent(string $source, int $line): int
    {
        $lines = explode("\n", $source);

        return isset($lines[$line - 1]) ? strlen($lines[$line - 1]) - strlen(ltrim($lines[$line - 1], " \t")) : 0;
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

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit((new PerCs31Verifier())->run(dirname(__DIR__, 2)));
}
