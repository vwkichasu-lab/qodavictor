<?php

require_once __DIR__ . '/code_runner.php';

if (!function_exists('gradeQodaCode')) {
    function qodaNormalizeOutput(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('rtrim', explode("\n", trim($value)));
        return trim(implode("\n", $lines));
    }

    function qodaCollapseOutput(string $value): string
    {
        return preg_replace('/\s+/', ' ', qodaNormalizeOutput($value));
    }

    function qodaOutputsMatch(string $actual, string $expected, ?float $tolerance = null): bool
    {
        $actualNorm = qodaNormalizeOutput($actual);
        $expectedNorm = qodaNormalizeOutput($expected);
        if ($actualNorm === $expectedNorm) {
            return true;
        }
        if ($expectedNorm !== '' && str_contains($actualNorm, $expectedNorm)) {
            return true;
        }
        if (qodaCollapseOutput($actualNorm) === qodaCollapseOutput($expectedNorm)) {
            return true;
        }
        $actualFlat = qodaCollapseOutput($actualNorm);
        $expectedFlat = qodaCollapseOutput($expectedNorm);
        if ($expectedFlat !== '' && str_contains($actualFlat, $expectedFlat)) {
            return true;
        }
        if (is_numeric($actualNorm) && is_numeric($expectedNorm)) {
            $tol = $tolerance ?? 0.000001;
            return abs((float)$actualNorm - (float)$expectedNorm) <= $tol;
        }
        return false;
    }

    function qodaTestMarks(array $testCases, float $maxMarks): array
    {
        $explicitTotal = 0.0;
        foreach ($testCases as $tc) {
            if (isset($tc['marks']) && is_numeric($tc['marks']) && (float)$tc['marks'] > 0) {
                $explicitTotal += (float)$tc['marks'];
            }
        }

        if ($explicitTotal > 0) {
            return array_map(function ($tc) {
                return isset($tc['marks']) && is_numeric($tc['marks']) && (float)$tc['marks'] > 0 ? (float)$tc['marks'] : 0.0;
            }, $testCases);
        }

        $count = max(count($testCases), 1);
        return array_fill(0, count($testCases), $maxMarks / $count);
    }

    function qodaStaticRubricScore(string $code, string $language, string $scheme, float $maxMarks): array
    {
        $codeLower = strtolower($code);
        $schemeLower = strtolower($scheme);
        $earned = 0.0;
        $cap = max(0.0, $maxMarks * 0.3);
        $notes = [];

        $checks = [
            ['label' => 'input handling', 'needles' => ['scan', 'input', 'readline', 'scanner', 'cin', 'console.readline', 'gets'], 'marks' => $cap * 0.25],
            ['label' => 'output statement', 'needles' => ['print', 'echo', 'printf', 'cout', 'system.out', 'console.writeline'], 'marks' => $cap * 0.25],
            ['label' => 'calculation or logic', 'needles' => ['+', '-', '*', '/', 'if', 'for', 'while', 'select', 'insert', 'update'], 'marks' => $cap * 0.25],
            ['label' => 'structured code', 'needles' => ['function', 'def ', 'class ', 'public ', 'void ', 'return'], 'marks' => $cap * 0.25],
        ];

        foreach ($checks as $check) {
            $passed = false;
            foreach ($check['needles'] as $needle) {
                if (str_contains($codeLower, $needle)) {
                    $passed = true;
                    break;
                }
            }
            if ($passed) {
                $earned += $check['marks'];
                $notes[] = "Static check passed: {$check['label']}.";
            } elseif ($schemeLower !== '') {
                $notes[] = "Static check needs review: {$check['label']}.";
            }
        }

        if (trim($scheme) === '') {
            $notes[] = 'No lecturer marking scheme was provided, so static review is limited.';
        }

        return ['score' => round(min($earned, $cap), 2), 'feedback' => $notes, 'cap' => round($cap, 2)];
    }

    function qodaModelSolutionSimilarityScore(string $code, string $modelSolution, float $maxMarks): float
    {
        $student = strtolower(preg_replace('/\s+/', ' ', trim($code)));
        $model = strtolower(preg_replace('/\s+/', ' ', trim($modelSolution)));
        if ($student === '' || $model === '' || $maxMarks <= 0) {
            return 0.0;
        }

        similar_text($student, $model, $percent);
        $studentTokens = array_unique(preg_split('/[^a-z0-9_]+/', $student, -1, PREG_SPLIT_NO_EMPTY));
        $modelTokens = array_unique(preg_split('/[^a-z0-9_]+/', $model, -1, PREG_SPLIT_NO_EMPTY));
        $shared = count(array_intersect($studentTokens, $modelTokens));
        $tokenRatio = count($modelTokens) > 0 ? $shared / count($modelTokens) : 0.0;
        $similarity = max($percent / 100, $tokenRatio);

        if ($similarity >= 0.75) return $maxMarks * 0.8;
        if ($similarity >= 0.5) return $maxMarks * 0.55;
        return $maxMarks * 0.25;
    }

    function qodaMeaningfulCodeUnits(string $code, string $language): int
    {
        $language = qodaNormalizeLanguage($language);
        $withoutBlocks = preg_replace('#/\*.*?\*/#s', '', $code);
        $lines = preg_split('/\R/', (string)$withoutBlocks) ?: [];
        $units = 0;

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\/\/.*$/', '', $line));
            if (in_array($language, ['python', 'php'], true)) {
                $line = trim(preg_replace('/#.*$/', '', $line));
            }
            $line = trim(preg_replace('/^\s*(?:(?:import|using|package|include)\b[^;]*;|#include[^\n;]*;?)\s*/i', '', $line));
            if ($line === '' || $line === ';' || $line === '{}' || preg_match('/^\s*[{}]+\s*$/', $line)) {
                continue;
            }
            if (preg_match('/^\s*(import|using|package|namespace|include|#include)\b/i', $line)) {
                continue;
            }
            if (preg_match('/^\s*(public\s+)?(class|interface|enum)\b/i', $line) && !preg_match('/[=+\-*\/%]|return|print|echo|scan|read|cin|cout/i', $line)) {
                continue;
            }

            $statementPieces = substr_count($line, ';');
            if ($statementPieces > 0) {
                $units += $statementPieces;
                if (qodaRepairMissingSemicolonInLine($line, $language) !== null) {
                    $units++;
                }
            } elseif (preg_match('/\b(if|else|for|while|switch|case|def|function|return|print|echo|scanf|cin|cout|console\.log|system\.out|readline|input|select|insert|update|delete)\b|[=+\-*\/%]/i', $line)) {
                $units++;
            }
        }

        return max(1, $units);
    }

    function qodaSyntaxMarkAllocation(float $maxMarks): float
    {
        return max(0.0, $maxMarks * 0.10);
    }

    function qodaAdaptiveSyntaxDeduction(float $maxMarks, float $severityWeight, int $repairUnits, int $effectiveUnits): float
    {
        if ($maxMarks <= 0 || $repairUnits <= 0) {
            return 0.0;
        }
        $syntaxAllocation = qodaSyntaxMarkAllocation($maxMarks);
        $effectiveUnits = max(1, $effectiveUnits);
        $deduction = ($syntaxAllocation * $severityWeight * $repairUnits) / $effectiveUnits;
        if ($severityWeight <= 0.10) {
            $scale = max(0.5, $maxMarks / 10);
            if ($effectiveUnits >= 60) {
                $minPerRepair = 0.01 * $scale;
                $maxPerRepair = 0.10 * $scale;
            } elseif ($effectiveUnits >= 15) {
                $minPerRepair = 0.10 * $scale;
                $maxPerRepair = 0.25 * $scale;
            } else {
                $minPerRepair = 0.25 * $scale;
                $maxPerRepair = 0.50 * $scale;
            }
            $deduction = min($maxPerRepair * $repairUnits, max($minPerRepair * $repairUnits, $deduction));
        }
        return round(min($syntaxAllocation, max(0.0, $deduction)), 4);
    }

    function qodaCandidateWithCode(array &$candidates, string $originalCode, string $newCode, string $description, float $severityWeight, int $repairUnits): void
    {
        if ($newCode === $originalCode) {
            return;
        }
        $hash = md5($newCode);
        if (isset($candidates[$hash])) {
            return;
        }
        $candidates[$hash] = [
            'code' => $newCode,
            'description' => $description,
            'severity_weight' => $severityWeight,
            'repair_units' => max(1, $repairUnits),
        ];
    }

    function qodaLineNeedsSemicolonRepair(string $line, string $language): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || preg_match('/[;{}:,]$/', $trimmed)) {
            return false;
        }
        if (preg_match('/^\s*(if|for|while|switch|catch|try|else|class|interface|enum|do|case|default)\b/i', $trimmed)) {
            return false;
        }
        if ($language === 'php' && preg_match('/^\s*(<\?php|\?>)$/i', $trimmed)) {
            return false;
        }
        return (bool)preg_match('/(=|\+\+|--|\breturn\b|\bthrow\b|\becho\b|\bprint\b|printf\s*\(|scanf\s*\(|cin\s*>>|cout\s*<<|System\.out\.print|Console\.Write|console\.log\s*\(|readline\s*\(|fgets\s*\()/i', $trimmed);
    }

    function qodaRepairMissingSemicolonInLine(string $line, string $language): ?string
    {
        $trimmed = rtrim($line);
        if (preg_match('/\.\s*$/', $trimmed)) {
            $withoutPeriod = preg_replace('/\.\s*$/', '', $trimmed);
            if ($withoutPeriod !== null && qodaLineNeedsSemicolonRepair($withoutPeriod, $language)) {
                return $withoutPeriod . ';';
            }
        }

        if (qodaLineNeedsSemicolonRepair($line, $language)) {
            return rtrim($line) . ';';
        }

        $statementBeforeBrace = '/((?:System\.out\.print(?:ln)?|Console\.Write(?:Line)?|console\.log|printf|scanf)\s*\([^;{}]*\)|\b(?:return|throw|echo|print)\b\s+[^;{}]+|[A-Za-z_][A-Za-z0-9_\]\)]*\s*(?:=|\+\+|--|\+=|-=|\*=|\/=)\s*[^;{}]+)(\s*\}.*)$/i';
        if (preg_match($statementBeforeBrace, $line)) {
            $repaired = preg_replace($statementBeforeBrace, '$1;$2', $line, 1);
            return $repaired !== $line ? $repaired : null;
        }

        return null;
    }

    function qodaSemicolonRepairDescription(string $line, int $lineNumber): string
    {
        return preg_match('/\.\s*$/', rtrim($line))
            ? "Replaced one accidental full stop with a semicolon on line {$lineNumber}."
            : "Added one obvious missing semicolon on line {$lineNumber}.";
    }

    function qodaCreateAdaptiveSyntaxRepairCandidates(string $code, string $language, string $error): array
    {
        $language = qodaNormalizeLanguage($language);
        $errorLower = strtolower($error);
        $candidates = [];
        $lines = preg_split('/\R/', $code) ?: [];
        $semicolonLanguages = ['java', 'c', 'cpp', 'csharp', 'javascript', 'php'];

        if (in_array($language, $semicolonLanguages, true) && preg_match('/;|semicolon|expected|parse error|syntax error|unexpected|request for member|not a structure|not a union/i', $errorLower)) {
            $lineNumbers = [];
            if (preg_match_all('/(?:line\s+|:)(\d+)(?::|\b)/i', $error, $matches)) {
                $lineNumbers = array_values(array_unique(array_map('intval', $matches[1])));
            }
            foreach ($lineNumbers as $lineNumber) {
                $idx = $lineNumber - 1;
                $repairedLine = isset($lines[$idx]) ? qodaRepairMissingSemicolonInLine($lines[$idx], $language) : null;
                if ($repairedLine !== null) {
                    $candidateLines = $lines;
                    $candidateLines[$idx] = $repairedLine;
                    qodaCandidateWithCode($candidates, $code, implode("\n", $candidateLines), qodaSemicolonRepairDescription($lines[$idx], $lineNumber), 0.10, 1);
                }
            }

            $limit = 0;
            $combinedLines = $lines;
            $combinedRepairLines = [];
            foreach ($lines as $idx => $line) {
                if ($limit >= 8) {
                    break;
                }
                $repairedLine = qodaRepairMissingSemicolonInLine($line, $language);
                if ($repairedLine !== null) {
                    $candidateLines = $lines;
                    $candidateLines[$idx] = $repairedLine;
                    qodaCandidateWithCode($candidates, $code, implode("\n", $candidateLines), qodaSemicolonRepairDescription($line, $idx + 1), 0.10, 1);
                    $combinedLines[$idx] = $repairedLine;
                    $combinedRepairLines[] = $idx + 1;
                    $limit++;
                }
            }
            if (count($combinedRepairLines) > 1) {
                qodaCandidateWithCode(
                    $candidates,
                    $code,
                    implode("\n", $combinedLines),
                    'Repaired obvious statement-ending semicolon punctuation on lines ' . implode(', ', $combinedRepairLines) . '.',
                    0.10,
                    count($combinedRepairLines)
                );
            }
        }

        if ($language === 'python' && preg_match("/expected\\s+'?:'?|invalid syntax|syntaxerror/i", $errorLower)) {
            foreach ($lines as $idx => $line) {
                $trimmed = rtrim($line);
                if (preg_match('/^\s*(if|elif|else|for|while|def|class|try|except|finally|with)\b.*[^:]$/i', $trimmed)) {
                    $candidateLines = $lines;
                    $candidateLines[$idx] = $trimmed . ':';
                    qodaCandidateWithCode($candidates, $code, implode("\n", $candidateLines), 'Added one obvious missing colon on line ' . ($idx + 1) . '.', 0.20, 1);
                }
            }
        }

        $openBraces = substr_count($code, '{');
        $closeBraces = substr_count($code, '}');
        if ($openBraces > $closeBraces && preg_match('/reached end|expected|missing|parse error|syntax error|unexpected end|\\}/i', $errorLower)) {
            $missing = min(5, $openBraces - $closeBraces);
            qodaCandidateWithCode($candidates, $code, rtrim($code) . "\n" . str_repeat("}\n", $missing), "Added {$missing} missing closing brace(s) at the end of the program.", 0.40, $missing);
        }

        return array_values($candidates);
    }

    function qodaApplyRepairToFiles(array $files, string $originalCode, string $repairedCode, string $language): array
    {
        if (!$files) {
            return [];
        }
        $language = qodaNormalizeLanguage($language);
        $extensionMap = [
            'python' => 'py',
            'javascript' => 'js',
            'php' => 'php',
            'java' => 'java',
            'c' => 'c',
            'cpp' => 'cpp',
            'csharp' => 'cs',
            'vbnet' => 'vb',
        ];
        $targetExt = $extensionMap[$language] ?? '';
        $updated = $files;
        $targetIndex = null;
        foreach ($updated as $idx => $file) {
            if (!empty($file['active'])) {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null && $targetExt !== '') {
            foreach ($updated as $idx => $file) {
                if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) === $targetExt) {
                    $targetIndex = $idx;
                    break;
                }
            }
        }
        if ($targetIndex === null) {
            $targetIndex = 0;
        }
        $updated[$targetIndex]['content'] = $repairedCode;
        return $updated;
    }

    function qodaBestAdaptiveSyntaxRepair(string $code, string $language, array $files, string $error, float $maxMarks, callable $evaluateCandidate): ?array
    {
        $candidates = qodaCreateAdaptiveSyntaxRepairCandidates($code, $language, $error);
        if (!$candidates) {
            return null;
        }

        $effectiveUnits = qodaMeaningfulCodeUnits($code, $language);
        $best = null;
        foreach ($candidates as $candidate) {
            $candidateFiles = qodaApplyRepairToFiles($files, $code, $candidate['code'], $language);
            $evaluation = $evaluateCandidate($candidate['code'], $candidateFiles);
            $earned = (float)($evaluation['earned'] ?? 0);
            if ($earned <= 0) {
                continue;
            }

            $deduction = qodaAdaptiveSyntaxDeduction(
                $maxMarks,
                (float)$candidate['severity_weight'],
                (int)$candidate['repair_units'],
                $effectiveUnits
            );
            $netEarned = max(0.0, $earned - $deduction);
            $candidate['files'] = $candidateFiles;
            $candidate['evaluation'] = $evaluation;
            $candidate['effective_units'] = $effectiveUnits;
            $candidate['syntax_allocation'] = qodaSyntaxMarkAllocation($maxMarks);
            $candidate['deduction'] = $deduction;
            $candidate['net_earned'] = $netEarned;

            if ($best === null || $netEarned > (float)$best['net_earned']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    function qodaSyntaxLeniencyScore(string $code, string $modelSolution, float $maxMarks, string $error): array
    {
        if ($maxMarks <= 0) {
            return ['score' => 0.0, 'reason' => 'No marks available for this question.'];
        }

        $errorLower = strtolower($error);
        $isSmallSyntax = preg_match('/missing|expected|semicolon|;|parse error|syntax error|unexpected token|unexpected end|request for member|not a structure|not a union/i', $errorLower) === 1;
        if (!$isSmallSyntax) {
            return ['score' => 0.0, 'reason' => 'Execution failed with a non-trivial compile/runtime error.'];
        }

        $similarityScore = $modelSolution !== ''
            ? qodaModelSolutionSimilarityScore($code, $modelSolution, $maxMarks)
            : 0.0;

        $cap = $modelSolution !== '' ? $maxMarks * 0.8 : $maxMarks * 0.6;
        $base = $modelSolution !== ''
            ? max($maxMarks * 0.55, $similarityScore)
            : $maxMarks * 0.45;

        return [
            'score' => round(min($cap, $base), 2),
            'reason' => 'Small syntax/compile issue detected, so QODA applied partial credit instead of a heavy deduction. Lecturer review is still recommended.',
        ];
    }

    function qodaExtractFilesFromPayload(array $payload): array
    {
        $files = $payload['files'] ?? [];
        if (is_string($files)) {
            $decoded = json_decode($files, true);
            $files = is_array($decoded) ? $decoded : [];
        }
        return is_array($files) ? $files : [];
    }

    function qodaInputValueForContext(string $context, int $numberIndex, bool $preferString = false): string
    {
        $context = strtolower($context);
        if ($preferString) return 'Flex';
        if (preg_match('/age/', $context)) return '23';
        if (preg_match('/score|mark|grade/', $context)) {
            $scores = ['80', '75', '70', '65', '60'];
            return $scores[$numberIndex % count($scores)];
        }
        if (preg_match('/price|amount|salary|money|cost/', $context)) return (string)(100 + ($numberIndex * 50));
        if (preg_match('/second|num2|number2|b\b/', $context)) return '3';
        if (preg_match('/first|num1|number1|a\b/', $context)) return '2';
        if (preg_match('/integer|number|num|int|double|float|long|short|decimal/', $context)) {
            $numbers = ['8', '3', '5', '7', '10', '12'];
            return $numbers[$numberIndex % count($numbers)];
        }
        return (string)(2 + $numberIndex);
    }

    function qodaInferSampleInput(string $code, string $language, string $questionText = ''): string
    {
        $language = qodaNormalizeLanguage($language);
        $lines = preg_split('/\R/', $code) ?: [];
        $inputs = [];
        $numericIndex = 0;

        foreach ($lines as $line) {
            $lineLower = strtolower($line);
            if ($language === 'java' && preg_match_all('/\bnext(?:int|double|float|long|short|byte|line)?\s*\(/i', $line, $matches)) {
                foreach ($matches[0] as $match) {
                    $isString = stripos($match, 'nextLine') !== false || preg_match('/\bnext\s*\(/i', $match);
                    $inputs[] = $isString ? qodaInputValueForContext($line . ' ' . $questionText, $numericIndex, true) : qodaInputValueForContext($line . ' ' . $questionText, $numericIndex++, false);
                }
            }

            if ($language === 'python' && preg_match_all('/\binput\s*\(/i', $line, $matches)) {
                foreach ($matches[0] as $_) {
                    $isString = preg_match('/name|person|customer|user/', strtolower($line)) && !preg_match('/int\s*\(|float\s*\(/i', $line);
                    $inputs[] = qodaInputValueForContext($line . ' ' . $questionText, $numericIndex++, $isString);
                }
            }

            if (($language === 'c' || $language === 'cpp') && preg_match_all('/scanf\s*\(\s*["\']([^"\']+)/i', $line, $matches)) {
                foreach ($matches[1] as $format) {
                    preg_match_all('/%[0-9.]*[diufFeEgGcs]/', $format, $specs);
                    foreach ($specs[0] as $spec) {
                        $isString = str_ends_with(strtolower($spec), 's') || str_ends_with(strtolower($spec), 'c');
                        $inputs[] = $isString ? qodaInputValueForContext($line . ' ' . $questionText, $numericIndex, true) : qodaInputValueForContext($line . ' ' . $questionText, $numericIndex++, false);
                    }
                }
            }

            if ($language === 'cpp' && preg_match('/\bcin\s*>>/', $line)) {
                $count = substr_count($line, '>>');
                for ($i = 0; $i < $count; $i++) {
                    $isString = preg_match('/name|person|customer|user|string/', strtolower($line));
                    $inputs[] = qodaInputValueForContext($line . ' ' . $questionText, $numericIndex++, $isString);
                }
            }

            if (($language === 'csharp' || $language === 'vbnet') && preg_match_all('/readline\s*\(/i', $line, $matches)) {
                foreach ($matches[0] as $_) {
                    $isString = preg_match('/name|person|customer|user|string/', strtolower($line));
                    $inputs[] = qodaInputValueForContext($line . ' ' . $questionText, $numericIndex++, $isString);
                }
            }

            if ($language === 'php' && preg_match_all('/fgets\s*\(\s*STDIN\s*\)|readline\s*\(/i', $line, $matches)) {
                foreach ($matches[0] as $_) {
                    $isString = preg_match('/name|person|customer|user/', strtolower($line));
                    $inputs[] = qodaInputValueForContext($line . ' ' . $questionText, $numericIndex++, $isString);
                }
            }

            if ($language === 'javascript' && preg_match_all('/readline|question\s*\(|prompt\s*\(/i', $line, $matches)) {
                foreach ($matches[0] as $_) {
                    $isString = preg_match('/name|person|customer|user/', strtolower($line));
                    $inputs[] = qodaInputValueForContext($line . ' ' . $questionText, $numericIndex++, $isString);
                }
            }
        }

        if (!$inputs) {
            $q = strtolower($questionText);
            if (preg_match('/name.*age|age.*name/', $q)) {
                $inputs = ['Flex', '23'];
            } elseif (preg_match('/name.*(three|3).*score|student.*(three|3).*score/', $q)) {
                $inputs = ['Flex', '80', '75', '70'];
            } elseif (preg_match('/(three|3).*score/', $q)) {
                $inputs = ['80', '75', '70'];
            } elseif (preg_match('/two|2/', $q) && preg_match('/number|integer|value/', $q)) {
                $inputs = ['2', '3'];
            } elseif (preg_match('/number|integer|input/', $q)) {
                $inputs = ['8'];
            }
        }

        return $inputs ? implode("\n", $inputs) . "\n" : '';
    }

    function qodaLooksLikeWebCode(string $code, string $language): bool
    {
        $language = qodaNormalizeLanguage($language);
        return in_array($language, ['html', 'css'], true)
            || preg_match('/<\s*(html|body|div|form|table|script|style|canvas)\b/i', $code)
            || ($language === 'javascript' && preg_match('/\b(document|window|querySelector|getElementById|addEventListener|innerHTML|localStorage)\b/i', $code))
            || ($language === 'css' && preg_match('/[.#]?[A-Za-z0-9_-]+\s*\{[^}]+:/', $code));
    }

    function qodaShouldUseStaticTestMatching(string $code, string $language): bool
    {
        $language = qodaNormalizeLanguage($language);
        return qodaLooksLikeWebCode($code, $language) || in_array($language, ['sql'], true);
    }

    function qodaStaticTestMatches(string $code, string $expected, string $mode = 'contains'): bool
    {
        $expected = trim($expected);
        if ($expected === '') return true;
        if ($mode === 'regex') {
            return @preg_match('/' . str_replace('/', '\\/', $expected) . '/i', $code) === 1;
        }
        return str_contains(strtolower($code), strtolower($expected));
    }

    function qodaLineForMarking(string $line, string $language): string
    {
        $language = qodaNormalizeLanguage($language);
        $line = trim(preg_replace('/\/\/.*$/', '', $line));
        if (in_array($language, ['python', 'php'], true)) {
            $line = trim(preg_replace('/#.*$/', '', $line));
        }
        $line = trim(preg_replace('/^\s*(?:(?:import|using|package|include)\b[^;]*;|#include[^\n;]*;?)\s*/i', '', $line));
        return trim($line);
    }

    function qodaLineShouldBeMarked(string $line, string $language): bool
    {
        $clean = qodaLineForMarking($line, $language);
        if ($clean === '' || $clean === ';' || preg_match('/^\s*[{}]+\s*$/', $clean)) {
            return false;
        }
        if (preg_match('/^\s*(import|using|package|namespace|include|#include)\b/i', $clean)) {
            return false;
        }
        if (preg_match('/^\s*(public\s+)?(class|interface|enum)\b/i', $clean) && !preg_match('/[=+\-*\/%]|return|print|echo|scan|read|cin|cout|input|console/i', $clean)) {
            return false;
        }
        return true;
    }

    function qodaLineRoles(string $line, string $language): array
    {
        $clean = qodaLineForMarking($line, $language);
        $roles = [];
        if (preg_match('/\b(input|scanner|nextInt|nextDouble|nextLine|readline|fgets|scanf|cin\s*>>|Console\.ReadLine|prompt)\b/i', $clean)) {
            $roles[] = 'input handling';
        }
        if (preg_match('/\b(print|println|printf|echo|cout\s*<<|console\.log|Console\.Write)\b/i', $clean)) {
            $roles[] = 'output';
        }
        if (preg_match('/\b(if|else|switch|case|for|while|do|foreach|elif)\b/i', $clean)) {
            $roles[] = 'control flow';
        }
        if (preg_match('/[=+\-*\/%]|Math\.|pow\s*\(|sqrt\s*\(|sum|count|select|insert|update|delete/i', $clean)) {
            $roles[] = 'logic/calculation';
        }
        if (preg_match('/\b(def|function|class|public|private|return|void|static)\b/i', $clean)) {
            $roles[] = 'structure';
        }
        return array_values(array_unique($roles ?: ['statement']));
    }

    function qodaAppliedRepairLines(?array $adaptiveRepair): array
    {
        if (!$adaptiveRepair || empty($adaptiveRepair['description'])) {
            return [];
        }
        if (preg_match_all('/\bline(?:s)?\s+([0-9,\s]+)/i', (string)$adaptiveRepair['description'], $matches)) {
            $lines = [];
            foreach ($matches[1] as $chunk) {
                foreach (preg_split('/\s*,\s*|\s+/', trim($chunk)) as $line) {
                    if ($line !== '' && ctype_digit($line)) {
                        $lines[] = (int)$line;
                    }
                }
            }
            return array_values(array_unique($lines));
        }
        return [];
    }

    function qodaHolisticPartialCreditScore(string $code, string $language, float $maxMarks, array $testResults): array
    {
        if ($maxMarks <= 0 || trim($code) === '' || !$testResults) {
            return ['score' => 0.0, 'feedback' => [], 'cap' => 0.0];
        }

        $lines = preg_split('/\R/', $code) ?: [];
        $roleCounts = [
            'structure' => 0,
            'input handling' => 0,
            'logic/calculation' => 0,
            'control flow' => 0,
            'output' => 0,
            'statement' => 0,
        ];
        $markableLines = 0;
        foreach ($lines as $line) {
            if (!qodaLineShouldBeMarked($line, $language)) {
                continue;
            }
            $markableLines++;
            foreach (qodaLineRoles($line, $language) as $role) {
                $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
            }
        }

        if ($markableLines === 0) {
            return ['score' => 0.0, 'feedback' => ['No meaningful code lines were available for partial credit.'], 'cap' => 0.0];
        }

        $failedCount = count(array_filter($testResults, fn($r) => empty($r['passed'])));
        $passedCount = count($testResults) - $failedCount;
        $allFailed = $failedCount === count($testResults);
        $hasActualOutput = count(array_filter($testResults, fn($r) => trim((string)($r['actual'] ?? '')) !== '')) > 0;
        $hasCompilerBlocker = count(array_filter($testResults, fn($r) => preg_match('/compile|syntax|javac|gcc|g\+\+|parse|expected|missing|semicolon|unexpected token|request for member|not a structure|not a union/i', (string)($r['error'] ?? '')))) > 0;
        $executed = !$hasCompilerBlocker && count(array_filter($testResults, fn($r) => trim((string)($r['error'] ?? '')) === '')) > 0;

        if (!$executed && !$hasActualOutput) {
            return ['score' => 0.0, 'feedback' => ['Program did not run far enough for runtime partial-credit scoring.'], 'cap' => 0.0];
        }

        $earned = 0.0;
        $feedback = [];
        $allocations = [
            'structure' => $maxMarks * 0.10,
            'input handling' => $maxMarks * 0.15,
            'algorithm attempt' => $maxMarks * 0.30,
            'implementation' => $maxMarks * 0.20,
            'output intention' => $maxMarks * 0.15,
            'syntax/code quality' => $maxMarks * 0.10,
        ];

        if ($roleCounts['structure'] > 0 || $markableLines >= 2) {
            $earned += $allocations['structure'];
            $feedback[] = 'Partial credit: program structure/declarations are present.';
        }
        if ($roleCounts['input handling'] > 0) {
            $earned += $allocations['input handling'];
            $feedback[] = 'Partial credit: input handling is attempted.';
        }
        if ($roleCounts['logic/calculation'] > 0 || $roleCounts['control flow'] > 0) {
            $earned += $allocations['algorithm attempt'];
            $feedback[] = 'Partial credit: algorithm/formula/control-flow attempt is visible.';
        }
        if ($markableLines >= 3 || $roleCounts['statement'] > 1) {
            $earned += $allocations['implementation'];
            $feedback[] = 'Partial credit: implementation has meaningful executable statements.';
        } elseif ($markableLines > 0) {
            $earned += $allocations['implementation'] * 0.4;
            $feedback[] = 'Limited partial credit: implementation is very short but relevant code exists.';
        }
        if ($roleCounts['output'] > 0) {
            $earned += $allocations['output intention'] * ($hasActualOutput ? 1.0 : 0.65);
            $feedback[] = 'Partial credit: output intention is present, even though expected output was not fully met.';
        }
        if ($executed) {
            $earned += $allocations['syntax/code quality'];
            $feedback[] = 'Partial credit: original code executed without a syntax/compile blocker.';
        }

        $strongRoleCount = 0;
        foreach (['input handling', 'logic/calculation', 'control flow', 'output'] as $role) {
            if (($roleCounts[$role] ?? 0) > 0) {
                $strongRoleCount++;
            }
        }

        $cap = $allFailed ? $maxMarks * 0.70 : $maxMarks * 0.90;
        if ($strongRoleCount <= 1 && $allFailed) {
            $cap = min($cap, $maxMarks * 0.30);
            $feedback[] = 'Partial credit capped because only one major requirement area was detected while all tests failed.';
        } elseif ($passedCount > 0) {
            $feedback[] = 'Partial credit considered together with passed lecturer tests; failed tests still limit the final mark.';
        }

        return [
            'score' => round(min($cap, max(0.0, $earned)), 3),
            'feedback' => $feedback,
            'cap' => round($cap, 3),
        ];
    }

    function qodaLineByLineMarkingReport(string $code, string $language, float $score, float $maxMarks, array $testResults, ?array $adaptiveRepair = null, bool $holisticPartialApplied = false): array
    {
        $lines = preg_split('/\R/', $code) ?: [];
        $markedLines = [];
        $repairLines = qodaAppliedRepairLines($adaptiveRepair);
        $allPassed = count($testResults) > 0 && count(array_filter($testResults, fn($r) => empty($r['passed']))) === 0;
        $hasFailedTests = count($testResults) > 0 && !$allPassed;
        $effectiveUnits = max(1, qodaMeaningfulCodeUnits($code, $language));
        $perUnitShare = max(0.0, $score) / $effectiveUnits;

        foreach ($lines as $idx => $rawLine) {
            if (!qodaLineShouldBeMarked($rawLine, $language)) {
                continue;
            }
            $lineNumber = $idx + 1;
            $clean = qodaLineForMarking($rawLine, $language);
            $roles = qodaLineRoles($clean, $language);
            $lineUnits = max(1, qodaMeaningfulCodeUnits($clean, $language));
            if (!array_intersect($roles, ['input handling', 'output', 'control flow', 'logic/calculation']) && in_array('structure', $roles, true)) {
                $lineUnits = 0;
            }
            $status = 'OK';
            $reason = $lineUnits > 0 ? 'Executed/checked as part of the submitted program.' : 'Structural line reviewed; no separate functional mark assigned.';
            $lineScore = $allPassed ? $perUnitShare * $lineUnits : 0.0;

            if (in_array($lineNumber, $repairLines, true)) {
                $status = 'REPAIRED SYNTAX';
                $deduction = round(((float)($adaptiveRepair['deduction'] ?? 0)) / max(1, count($repairLines)), 4);
                $reason = 'Obvious local syntax issue repaired temporarily for marking; this line carries ' . $deduction . ' mark(s) of the proportional syntax deduction.';
                $lineScore = max(0.0, ($perUnitShare * $lineUnits) - $deduction);
            } elseif ($hasFailedTests && $holisticPartialApplied && array_intersect($roles, ['input handling', 'logic/calculation', 'output', 'control flow', 'structure', 'statement'])) {
                $status = 'PARTIAL CREDIT';
                $reason = 'Line demonstrates part of the required solution, but failed test-case behavior limits the final mark.';
                $lineScore = round($perUnitShare * $lineUnits, 4);
            } elseif ($hasFailedTests && array_intersect($roles, ['logic/calculation', 'output', 'control flow'])) {
                $status = 'NEEDS REVIEW';
                $reason = 'Related to program behavior/output. One or more lecturer test cases failed, so this line should be checked for logic or output mistakes.';
                $lineScore = 0.0;
            } elseif ($hasFailedTests) {
                $status = 'SUPPORTING LINE';
                $reason = 'Line is structurally acceptable, but final marks depend on the failed test-case behavior.';
                $lineScore = round(($perUnitShare * $lineUnits) * 0.5, 4);
            }

            $markedLines[] = [
                'line' => $lineNumber,
                'status' => $status,
                'roles' => $roles,
                'score' => round($lineScore, 4),
                'code' => trim($rawLine),
                'reason' => $reason,
            ];
        }

        if (!$markedLines) {
            return ['Line-by-line marking trace: no meaningful executable lines were detected.'];
        }

        $report = ['Line-by-line marking trace:'];
        foreach ($markedLines as $item) {
            $report[] = 'Line ' . $item['line'] . ' [' . $item['status'] . '] +' . $item['score'] . ' mark(s) - ' . implode(', ', $item['roles']) . ' - ' . $item['reason'] . ' Code: ' . $item['code'];
        }
        return $report;
    }

    function qodaAiGradingEnv(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false ? $default : trim((string)$value);
    }

    function qodaAiGradingEnabled(): bool
    {
        $flag = strtolower(qodaAiGradingEnv('QODA_AI_GRADING_ENABLED', 'auto'));
        if (in_array($flag, ['0', 'false', 'off', 'no', 'disabled'], true)) {
            return false;
        }
        return qodaAiGradingApiKey() !== '';
    }

    function qodaAiGradingApiKey(): string
    {
        $key = qodaAiGradingEnv('OPENAI_API_KEY');
        if ($key !== '') {
            return $key;
        }
        return qodaAiGradingEnv('QODA_OPENAI_API_KEY');
    }

    function qodaClipForAi(string $value, int $maxChars): string
    {
        if (strlen($value) <= $maxChars) {
            return $value;
        }
        return substr($value, 0, $maxChars) . "\n...[truncated for AI grading context]...";
    }

    function qodaRoundWholeMark(float $score, float $maxMarks): int
    {
        return (int)round(min($maxMarks, max(0.0, $score)), 0, PHP_ROUND_HALF_UP);
    }

    function qodaAiRubricSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'score',
                'max_marks',
                'percentage',
                'confidence',
                'requires_human_review',
                'human_review_reason',
                'question_summary',
                'correct_parts',
                'root_errors',
                'minimal_repairs',
                'rubric_breakdown',
                'deductions',
                'student_feedback',
                'lecturer_feedback',
                'fairness_check',
            ],
            'properties' => [
                'score' => ['type' => 'number'],
                'max_marks' => ['type' => 'number'],
                'percentage' => ['type' => 'number'],
                'confidence' => ['type' => 'number'],
                'requires_human_review' => ['type' => 'boolean'],
                'human_review_reason' => ['type' => 'string'],
                'question_summary' => ['type' => 'string'],
                'correct_parts' => ['type' => 'array', 'items' => ['type' => 'string']],
                'root_errors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['location', 'error', 'category', 'effect', 'minimal_repair_allowed'],
                        'properties' => [
                            'location' => ['type' => 'string'],
                            'error' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'effect' => ['type' => 'string'],
                            'minimal_repair_allowed' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'minimal_repairs' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['location', 'original', 'repaired', 'reason'],
                        'properties' => [
                            'location' => ['type' => 'string'],
                            'original' => ['type' => 'string'],
                            'repaired' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
                'rubric_breakdown' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['requirement', 'earned', 'available', 'reason'],
                        'properties' => [
                            'requirement' => ['type' => 'string'],
                            'earned' => ['type' => 'number'],
                            'available' => ['type' => 'number'],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
                'deductions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['amount', 'category', 'reason'],
                        'properties' => [
                            'amount' => ['type' => 'number'],
                            'category' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
                'student_feedback' => ['type' => 'string'],
                'lecturer_feedback' => ['type' => 'string'],
                'fairness_check' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    function qodaExtractOpenAiOutputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }
        $chunks = [];
        $walk = function ($node) use (&$walk, &$chunks) {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['type']) && in_array($node['type'], ['output_text', 'text'], true) && isset($node['text']) && is_string($node['text'])) {
                $chunks[] = $node['text'];
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($response['output'] ?? $response);
        return trim(implode("\n", $chunks));
    }

    function qodaCallAiRubricGrader(array $context): ?array
    {
        if (!qodaAiGradingEnabled() || !function_exists('curl_init')) {
            return null;
        }

        $apiKey = qodaAiGradingApiKey();
        $model = qodaAiGradingEnv('QODA_AI_GRADING_MODEL', qodaAiGradingEnv('OPENAI_MODEL', 'gpt-4.1-mini'));
        $timeout = (int)qodaAiGradingEnv('QODA_AI_GRADING_TIMEOUT', '45');
        $timeout = max(15, min(90, $timeout));

        $systemPrompt = <<<PROMPT
You are FairCode Marker for QODA, a programming-exam grading system.
Grade fairly like a careful lecturer. Do not use all-or-nothing scoring.
Use the official marking scheme when present; otherwise create a question-specific rubric from the task.
Compiler/test results are evidence, not the whole grade. Award marks for demonstrated understanding, correct structure, input handling, algorithm, implementation, output intention, and code quality.
Never give 0 only because the original code does not compile. Group compiler messages by root cause. For obvious local syntax errors, mentally apply a minimal repair, disclose it, test the intended solution from the evidence, and deduct only from the affected category.
Do not double-penalize one root error. Do not reward unrelated code just because it runs.
If the code fully satisfies the problem and only has tiny local syntax omissions, give a high mark with a small deduction.
If the algorithm is wrong but the student shows relevant input/output/structure, give partial credit and explain the affected requirements.
Return only valid JSON that matches the schema.
PROMPT;

        $userContext = [
            'question' => qodaClipForAi((string)($context['question_text'] ?? ''), 6000),
            'maximum_mark' => (float)($context['max_marks'] ?? 0),
            'programming_language' => (string)($context['language'] ?? ''),
            'student_code' => qodaClipForAi((string)($context['code'] ?? ''), 14000),
            'official_marking_scheme' => qodaClipForAi((string)($context['marking_scheme'] ?? ''), 5000),
            'model_solution' => qodaClipForAi((string)($context['model_solution'] ?? ''), 10000),
            'test_cases' => $context['test_cases'] ?? [],
            'compiler_and_test_results' => $context['test_results'] ?? [],
            'local_qoda_score_before_ai' => [
                'score' => (float)($context['local_score'] ?? 0),
                'max_marks' => (float)($context['max_marks'] ?? 0),
                'feedback' => qodaClipForAi((string)($context['local_feedback'] ?? ''), 9000),
            ],
        ];

        $request = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $systemPrompt],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => json_encode($userContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'qoda_ai_code_grade',
                    'strict' => true,
                    'schema' => qodaAiRubricSchema(),
                ],
            ],
            'max_output_tokens' => 4500,
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            error_log('QODA AI grading unavailable. HTTP ' . $httpCode . ' ' . $curlError);
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('QODA AI grading returned non-JSON response.');
            return null;
        }

        $text = qodaExtractOpenAiOutputText($decoded);
        $grade = json_decode($text, true);
        if (!is_array($grade)) {
            error_log('QODA AI grading output could not be parsed as JSON.');
            return null;
        }

        $maxMarks = max(0.0, (float)($context['max_marks'] ?? 0));
        if (!isset($grade['score']) || !is_numeric($grade['score']) || $maxMarks <= 0) {
            return null;
        }
        $grade['score'] = qodaRoundWholeMark((float)$grade['score'], $maxMarks);
        $grade['max_marks'] = $maxMarks;
        $grade['percentage'] = round(($grade['score'] / $maxMarks) * 100, 1);
        $grade['model'] = $model;

        return $grade;
    }

    function qodaFormatAiGradingFeedback(array $aiGrade): array
    {
        $lines = [];
        $lines[] = 'ChatGPT rubric grader: active (' . ($aiGrade['model'] ?? 'configured model') . ').';
        $lines[] = 'AI question summary: ' . (string)($aiGrade['question_summary'] ?? '');
        if (!empty($aiGrade['correct_parts']) && is_array($aiGrade['correct_parts'])) {
            $lines[] = 'AI correct parts identified: ' . implode('; ', array_map('strval', $aiGrade['correct_parts']));
        }
        if (!empty($aiGrade['rubric_breakdown']) && is_array($aiGrade['rubric_breakdown'])) {
            $lines[] = 'AI rubric breakdown:';
            foreach ($aiGrade['rubric_breakdown'] as $item) {
                $lines[] = '- ' . ($item['requirement'] ?? 'Requirement') . ': ' . ($item['earned'] ?? 0) . ' / ' . ($item['available'] ?? 0) . ' - ' . ($item['reason'] ?? '');
            }
        }
        if (!empty($aiGrade['root_errors']) && is_array($aiGrade['root_errors'])) {
            $lines[] = 'AI root errors:';
            foreach ($aiGrade['root_errors'] as $item) {
                $lines[] = '- ' . ($item['location'] ?? 'Unknown location') . ': ' . ($item['error'] ?? '') . ' [' . ($item['category'] ?? 'uncategorized') . '] - ' . ($item['effect'] ?? '');
            }
        }
        if (!empty($aiGrade['deductions']) && is_array($aiGrade['deductions'])) {
            $lines[] = 'AI deductions:';
            foreach ($aiGrade['deductions'] as $item) {
                $lines[] = '- ' . ($item['amount'] ?? 0) . ' mark(s), ' . ($item['category'] ?? 'deduction') . ': ' . ($item['reason'] ?? '');
            }
        }
        if (trim((string)($aiGrade['student_feedback'] ?? '')) !== '') {
            $lines[] = 'AI student feedback: ' . (string)$aiGrade['student_feedback'];
        }
        if (trim((string)($aiGrade['lecturer_feedback'] ?? '')) !== '') {
            $lines[] = 'AI lecturer note: ' . (string)$aiGrade['lecturer_feedback'];
        }
        if (!empty($aiGrade['requires_human_review'])) {
            $lines[] = 'AI human review: recommended - ' . (string)($aiGrade['human_review_reason'] ?? 'The grading confidence or evidence requires lecturer review.');
        } else {
            $lines[] = 'AI human review: not required.';
        }
        return $lines;
    }

    function gradeQodaCode(array $payload): array
    {
        $code = (string)($payload['code'] ?? '');
        $language = (string)($payload['language'] ?? 'java');
        $testCases = $payload['test_cases'] ?? [];
        if (is_string($testCases)) {
            $decoded = json_decode($testCases, true);
            $testCases = is_array($decoded) ? $decoded : [];
        }
        $testCases = is_array($testCases) ? array_values($testCases) : [];
        $markingScheme = (string)($payload['marking_scheme'] ?? '');
        $expectedOutput = (string)($payload['expected_output'] ?? $payload['expectedOutput'] ?? '');
        $modelSolution = (string)($payload['model_solution'] ?? $payload['modelSolution'] ?? '');
        $questionText = (string)($payload['question_text'] ?? '');
        $manualInput = (string)($payload['input'] ?? '');
        $maxMarks = max(0.0, (float)($payload['max_marks'] ?? 20));
        $files = qodaExtractFilesFromPayload($payload);

        $testResults = [];
        $earned = 0.0;
        $testWeights = qodaTestMarks($testCases, $maxMarks);
        $testTotal = array_sum($testWeights);
        $appliedAdaptiveRepair = null;
        $holisticPartialApplied = false;
        $holisticPartialCredit = null;

        foreach ($testCases as $idx => $tc) {
            $input = (string)($tc['input'] ?? '');
            $expected = (string)($tc['expected']
                ?? $tc['expectedOutput']
                ?? $tc['expected_output']
                ?? $tc['output']
                ?? $tc['stdout']
                ?? '');
            $tolerance = isset($tc['tolerance']) && is_numeric($tc['tolerance']) ? (float)$tc['tolerance'] : null;
            if (qodaShouldUseStaticTestMatching($code, $language)) {
                $mode = (string)($tc['comparisonMode'] ?? $tc['comparison_mode'] ?? 'contains');
                $passed = qodaStaticTestMatches($code, $expected, $mode);
                $runnerResult = ['success' => true, 'output' => $passed ? 'Static requirement found in submitted code.' : 'Static requirement not found in submitted code.', 'error' => '', 'execution_time_ms' => 0];
            } else {
                $runnerResult = executeQodaCode($code, $language, $input, $files);
                $passed = !empty($runnerResult['success']) && qodaOutputsMatch((string)($runnerResult['output'] ?? ''), $expected, $tolerance);
            }
            $actual = (string)($runnerResult['output'] ?? '');
            $error = (string)($runnerResult['error'] ?? '');
            $marks = $testWeights[$idx] ?? 0.0;
            if ($passed) {
                $earned += $marks;
            }

            $testResults[] = [
                'test_case' => $idx + 1,
                'passed' => $passed,
                'input' => $input,
                'expected' => qodaNormalizeOutput($expected),
                'actual' => qodaNormalizeOutput($actual),
                'error' => qodaNormalizeOutput($error),
                'marks' => $passed ? round($marks, 2) : 0,
                'max_marks' => round($marks, 2),
                'execution_time_ms' => $runnerResult['execution_time_ms'] ?? null,
            ];
        }

        $rubric = ['score' => 0.0, 'feedback' => [], 'cap' => 0.0];
        $testCaseRequiresManualReview = false;
        if (count($testCases) > 0 && $earned < $maxMarks && $maxMarks > 0) {
            $compileErrors = array_values(array_filter($testResults, function ($result) {
                return !empty($result['error']) && preg_match('/compile|syntax|javac|gcc|g\+\+|parse|expected|missing|semicolon|unexpected token|request for member|not a structure|not a union/i', (string)$result['error']);
            }));
            if (count($compileErrors) > 0) {
                $combinedError = implode("\n", array_map(fn($r) => (string)$r['error'], $compileErrors));
                $adaptiveRepair = qodaBestAdaptiveSyntaxRepair(
                    $code,
                    $language,
                    $files,
                    $combinedError,
                    $maxMarks,
                    function (string $candidateCode, array $candidateFiles) use ($testCases, $testWeights, $language): array {
                        $candidateResults = [];
                        $candidateEarned = 0.0;
                        foreach ($testCases as $idx => $tc) {
                            $input = (string)($tc['input'] ?? '');
                            $expected = (string)($tc['expected']
                                ?? $tc['expectedOutput']
                                ?? $tc['expected_output']
                                ?? $tc['output']
                                ?? $tc['stdout']
                                ?? '');
                            $tolerance = isset($tc['tolerance']) && is_numeric($tc['tolerance']) ? (float)$tc['tolerance'] : null;
                            if (qodaShouldUseStaticTestMatching($candidateCode, $language)) {
                                $mode = (string)($tc['comparisonMode'] ?? $tc['comparison_mode'] ?? 'contains');
                                $passed = qodaStaticTestMatches($candidateCode, $expected, $mode);
                                $runnerResult = ['success' => true, 'output' => $passed ? 'Static requirement found in repaired code.' : 'Static requirement not found in repaired code.', 'error' => '', 'execution_time_ms' => 0];
                            } else {
                                $runnerResult = executeQodaCode($candidateCode, $language, $input, $candidateFiles);
                                $passed = !empty($runnerResult['success']) && qodaOutputsMatch((string)($runnerResult['output'] ?? ''), $expected, $tolerance);
                            }
                            $marks = $testWeights[$idx] ?? 0.0;
                            if ($passed) {
                                $candidateEarned += $marks;
                            }
                            $candidateResults[] = [
                                'test_case' => $idx + 1,
                                'passed' => $passed,
                                'input' => $input,
                                'expected' => qodaNormalizeOutput($expected),
                                'actual' => qodaNormalizeOutput((string)($runnerResult['output'] ?? '')),
                                'error' => qodaNormalizeOutput((string)($runnerResult['error'] ?? '')),
                                'marks' => $passed ? round($marks, 2) : 0,
                                'max_marks' => round($marks, 2),
                                'execution_time_ms' => $runnerResult['execution_time_ms'] ?? null,
                            ];
                        }
                        return ['earned' => $candidateEarned, 'test_results' => $candidateResults];
                    }
                );

                if ($adaptiveRepair && (float)$adaptiveRepair['net_earned'] > $earned) {
                    $earned = (float)$adaptiveRepair['net_earned'];
                    $testResults = $adaptiveRepair['evaluation']['test_results'] ?? $testResults;
                    $appliedAdaptiveRepair = $adaptiveRepair;
                    $rubric['feedback'][] = 'Adaptive syntax repair applied: ' . $adaptiveRepair['description'];
                    $rubric['feedback'][] = 'No double penalty applied: QODA graded the minimally repaired code, then deducted only the proportional syntax amount.';
                    $rubric['feedback'][] = 'Syntax deduction formula: ' . round($adaptiveRepair['syntax_allocation'], 4) . ' syntax mark allocation x ' . $adaptiveRepair['severity_weight'] . ' severity x ' . $adaptiveRepair['repair_units'] . ' repair unit(s) / ' . $adaptiveRepair['effective_units'] . ' effective code unit(s) = ' . $adaptiveRepair['deduction'] . ' mark(s).';
                    $testCaseRequiresManualReview = false;
                } elseif (count($compileErrors) === count($testResults)) {
                    $testCaseRequiresManualReview = true;
                    $rubric['feedback'][] = 'Syntax/compile failure could not be repaired safely by an obvious local edit. Lecturer review is recommended; QODA did not guess an ambiguous correction.';
                }
            }
        }

        $requiresManualReview = $testCaseRequiresManualReview;
        if (count($testCases) === 0) {
            $sampleInput = $manualInput !== '' ? $manualInput : qodaInferSampleInput($code, $language, $questionText);
            $rubric = qodaStaticRubricScore($code, $language, $markingScheme, $maxMarks);
            $runnerResult = qodaLooksLikeWebCode($code, $language)
                ? ['success' => true, 'ok' => true, 'output' => 'Web/UI code preview is syntactically reviewable in the browser panel.', 'error' => '', 'execution_time_ms' => 0]
                : executeQodaCode($code, $language, $sampleInput, $files);
            $actual = (string)($runnerResult['output'] ?? '');
            $error = (string)($runnerResult['error'] ?? '');
            $executionOk = !empty($runnerResult['success']);

            $expectedLabel = trim($expectedOutput) !== '' ? $expectedOutput : '[no lecturer expected output supplied]';
            $matchesExpectedOutput = trim($expectedOutput) !== '' && $executionOk && qodaOutputsMatch($actual, $expectedOutput, null);

            $testResults[] = [
                'test_case' => 1,
                'passed' => trim($expectedOutput) !== '' ? $matchesExpectedOutput : $executionOk,
                'input' => $sampleInput,
                'expected' => qodaNormalizeOutput($expectedLabel),
                'actual' => qodaNormalizeOutput($actual),
                'error' => qodaNormalizeOutput($error),
                'marks' => 0,
                'max_marks' => $maxMarks,
                'execution_time_ms' => $runnerResult['execution_time_ms'] ?? null,
            ];

            if ($matchesExpectedOutput) {
                $earned = $maxMarks;
                $requiresManualReview = false;
            } elseif ($executionOk) {
                if (trim($expectedOutput) === '') {
                    $earned = $maxMarks;
                } else {
                    $runtimeScore = $maxMarks * 0.65;
                    $outputBonus = trim($actual) !== '' ? $maxMarks * 0.1 : 0.0;
                    $earned = min($maxMarks * 0.85, $runtimeScore + $rubric['score'] + $outputBonus);
                }
                $requiresManualReview = false;
            } else {
                $requiresManualReview = true;
                $compileLikeError = preg_match('/compile|syntax|javac|gcc|g\+\+|parse|cannot find symbol|expected|missing|request for member|not a structure|not a union/i', $error);
                if ($compileLikeError) {
                    $adaptiveRepair = qodaBestAdaptiveSyntaxRepair(
                        $code,
                        $language,
                        $files,
                        $error,
                        $maxMarks,
                        function (string $candidateCode, array $candidateFiles) use ($language, $sampleInput, $expectedOutput, $maxMarks): array {
                            $runnerResult = qodaLooksLikeWebCode($candidateCode, $language)
                                ? ['success' => true, 'ok' => true, 'output' => 'Web/UI code preview is syntactically reviewable in the browser panel.', 'error' => '', 'execution_time_ms' => 0]
                                : executeQodaCode($candidateCode, $language, $sampleInput, $candidateFiles);
                            $candidateActual = (string)($runnerResult['output'] ?? '');
                            $candidateExecutionOk = !empty($runnerResult['success']);
                            $candidateMatchesExpected = trim($expectedOutput) !== '' && $candidateExecutionOk && qodaOutputsMatch($candidateActual, $expectedOutput, null);
                            if ($candidateMatchesExpected || ($candidateExecutionOk && trim($expectedOutput) === '')) {
                                $candidateEarned = $maxMarks;
                            } elseif ($candidateExecutionOk) {
                                $candidateEarned = $maxMarks * 0.65 + (trim($candidateActual) !== '' ? $maxMarks * 0.1 : 0.0);
                                $candidateEarned = min($maxMarks * 0.85, $candidateEarned);
                            } else {
                                $candidateEarned = 0.0;
                            }
                            return [
                                'earned' => $candidateEarned,
                                'test_results' => [[
                                    'test_case' => 1,
                                    'passed' => trim($expectedOutput) !== '' ? $candidateMatchesExpected : $candidateExecutionOk,
                                    'input' => $sampleInput,
                                    'expected' => qodaNormalizeOutput(trim($expectedOutput) !== '' ? $expectedOutput : '[no lecturer expected output supplied]'),
                                    'actual' => qodaNormalizeOutput($candidateActual),
                                    'error' => qodaNormalizeOutput((string)($runnerResult['error'] ?? '')),
                                    'marks' => round($candidateEarned, 2),
                                    'max_marks' => $maxMarks,
                                    'execution_time_ms' => $runnerResult['execution_time_ms'] ?? null,
                                ]],
                            ];
                        }
                    );

                    if ($adaptiveRepair && (float)$adaptiveRepair['net_earned'] > $earned) {
                        $earned = max($rubric['score'], (float)$adaptiveRepair['net_earned']);
                        $testResults = $adaptiveRepair['evaluation']['test_results'] ?? $testResults;
                        $appliedAdaptiveRepair = $adaptiveRepair;
                        $rubric['feedback'][] = 'Adaptive syntax repair applied: ' . $adaptiveRepair['description'];
                        $rubric['feedback'][] = 'No double penalty applied: QODA graded the minimally repaired code, then deducted only the proportional syntax amount.';
                        $rubric['feedback'][] = 'Syntax deduction formula: ' . round($adaptiveRepair['syntax_allocation'], 4) . ' syntax mark allocation x ' . $adaptiveRepair['severity_weight'] . ' severity x ' . $adaptiveRepair['repair_units'] . ' repair unit(s) / ' . $adaptiveRepair['effective_units'] . ' effective code unit(s) = ' . $adaptiveRepair['deduction'] . ' mark(s).';
                        $requiresManualReview = false;
                    } else {
                        $rubric['feedback'][] = 'Syntax/compile failure could not be repaired safely by an obvious local edit. Lecturer review is recommended; QODA did not guess an ambiguous correction.';
                    }
                } else {
                    $cap = $maxMarks * 0.5;
                    $earned = min($cap, $rubric['score']);
                }
            }
            $testTotal = 0.0;
        } elseif ($testTotal > $maxMarks && $maxMarks > 0) {
            $earned = ($earned / $testTotal) * $maxMarks;
            $testTotal = $maxMarks;
        }

        if (count($testCases) > 0 && $earned < $maxMarks && $maxMarks > 0) {
            $holisticPartialCredit = qodaHolisticPartialCreditScore($code, $language, $maxMarks, $testResults);
            if ((float)$holisticPartialCredit['score'] > $earned) {
                $earned = (float)$holisticPartialCredit['score'];
                $holisticPartialApplied = true;
                $requiresManualReview = true;
                $rubric['feedback'][] = 'Holistic partial credit applied: lecturer test cases did not fully pass, but QODA awarded marks for correct requirements demonstrated in the code.';
                $rubric['feedback'][] = 'Partial-credit cap: ' . $holisticPartialCredit['cap'] . ' / ' . $maxMarks . ' marks, based on the question-relative fairness policy.';
                foreach ($holisticPartialCredit['feedback'] as $partialNote) {
                    $rubric['feedback'][] = $partialNote;
                }
            }
        }

        if (trim($modelSolution) !== '' && $maxMarks > 0 && count($testCases) === 0) {
            $modelScore = qodaModelSolutionSimilarityScore($code, $modelSolution, $maxMarks);
            $earned = max($earned, min($maxMarks, $modelScore));
            $rubric['feedback'][] = 'Model solution reference used as an additional similarity check.';
        } elseif (trim($modelSolution) !== '' && count($testCases) > 0) {
            $rubric['feedback'][] = 'Model solution reference was available, but lecturer test cases remained authoritative for functional scoring.';
        }

        $score = qodaRoundWholeMark((float)$earned, $maxMarks);
        $percentage = $maxMarks > 0 ? round(($score / $maxMarks) * 100, 1) : 0;
        $passedCount = count(array_filter($testResults, fn($r) => !empty($r['passed'])));

        $localFeedbackForAi = implode("\n", array_merge(
            ["Local score before AI: {$score} / {$maxMarks} marks ({$percentage}%)."],
            $rubric['feedback']
        ));
        $aiGrading = qodaCallAiRubricGrader([
            'question_text' => $questionText,
            'max_marks' => $maxMarks,
            'language' => $language,
            'code' => $code,
            'marking_scheme' => $markingScheme,
            'model_solution' => $modelSolution,
            'test_cases' => $testCases,
            'test_results' => $testResults,
            'local_score' => $score,
            'local_feedback' => $localFeedbackForAi,
        ]);
        $aiGradingApplied = is_array($aiGrading);
        $localScoreBeforeAi = $score;
        if ($aiGradingApplied) {
            $score = qodaRoundWholeMark((float)$aiGrading['score'], $maxMarks);
            $percentage = $maxMarks > 0 ? round(($score / $maxMarks) * 100, 1) : 0;
            $requiresManualReview = !empty($aiGrading['requires_human_review']);
        }

        $feedbackLines = [];
        $feedbackLines[] = "Score: {$score} / {$maxMarks} marks ({$percentage}%).";
        if ($aiGradingApplied) {
            $feedbackLines[] = "Method: ChatGPT rubric grading using compiler/test evidence. Local execution score before ChatGPT review was {$localScoreBeforeAi} / {$maxMarks}.";
        }
        if (count($testCases) > 0) {
            if (!$aiGradingApplied) {
                $feedbackLines[] = "Method: executed the student's code against " . count($testCases) . " lecturer test case(s).";
            } else {
                $feedbackLines[] = "Execution evidence: ran the student's code against " . count($testCases) . " lecturer test case(s).";
            }
            $feedbackLines[] = "Passed: {$passedCount} / " . count($testCases) . " test case(s).";
        } else {
            if (!$aiGradingApplied) {
                $feedbackLines[] = 'Method: no lecturer test cases were available, so QODA ran a safe inferred input and combined execution success with static rubric checks.';
            } else {
                $feedbackLines[] = 'Execution evidence: no lecturer test cases were available, so QODA ran a safe inferred input before AI rubric review.';
            }
            if (trim($expectedOutput) !== '') {
                $feedbackLines[] = 'Expected output supplied by lecturer was checked separately from the model solution.';
            }
            if (!empty($sampleInput)) {
                $feedbackLines[] = "Inferred stdin used:\n" . trim($sampleInput);
            }
        }

        foreach ($testResults as $result) {
            $status = $result['passed'] ? 'PASSED' : 'FAILED';
            $feedbackLines[] = "Test {$result['test_case']}: {$status} ({$result['marks']}/{$result['max_marks']} marks).";
            if (!$result['passed']) {
                $deducted = round(max(0, (float)$result['max_marks'] - (float)$result['marks']), 2);
                if ($deducted > 0) {
                    if ($holisticPartialApplied) {
                        $feedbackLines[] = "Runtime test note: this test did not produce the expected result, so its direct test-case marks were not awarded; separate rubric partial credit is applied below for correct work shown in the code.";
                    } else {
                        $feedbackLines[] = "Deduction reason: {$deducted} mark(s) deducted because this test did not produce the expected result.";
                    }
                }
                $feedbackLines[] = "Expected: " . ($result['expected'] !== '' ? $result['expected'] : '[empty output]');
                $feedbackLines[] = "Actual: " . ($result['actual'] !== '' ? $result['actual'] : '[empty output]');
                if ($result['error'] !== '') {
                    $feedbackLines[] = "Error: {$result['error']}";
                }
            }
        }

        foreach ($rubric['feedback'] as $note) {
            $feedbackLines[] = $note;
        }

        if ($aiGradingApplied) {
            foreach (qodaFormatAiGradingFeedback($aiGrading) as $line) {
                $feedbackLines[] = $line;
            }
        }

        foreach (qodaLineByLineMarkingReport($code, $language, $score, $maxMarks, $testResults, $appliedAdaptiveRepair, $holisticPartialApplied) as $lineNote) {
            $feedbackLines[] = $lineNote;
        }

        $feedbackLines[] = $requiresManualReview
            ? 'Final decision: errors were found or expected outputs were missing, so lecturer review is recommended before publishing this score.'
            : 'Final decision: the code executed successfully and the score is based on runtime behavior plus rubric checks.';

        return [
            'success' => true,
            'score' => $score,
            'max_marks' => $maxMarks,
            'percentage' => $percentage,
            'feedback' => implode("\n", $feedbackLines),
            'results' => $testResults,
            'test_results' => $testResults,
            'test_score' => qodaRoundWholeMark((float)$score, $maxMarks),
            'test_total' => round($testTotal, 2),
            'requires_manual_review' => $requiresManualReview,
            'ai_grading_applied' => $aiGradingApplied,
            'ai_grading' => $aiGradingApplied ? $aiGrading : null,
            'local_score_before_ai' => qodaRoundWholeMark((float)$localScoreBeforeAi, $maxMarks),
            'grading_method' => $aiGradingApplied
                ? 'ai_rubric_with_execution_evidence'
                : (count($testCases) > 0 ? 'execution_test_cases' : ($requiresManualReview ? 'execution_inferred_input_requires_review' : 'execution_inferred_input')),
            'consistency_hash' => md5($code . qodaNormalizeLanguage($language) . json_encode($testCases) . $markingScheme . $expectedOutput . $modelSolution . json_encode($files)),
        ];
    }
}
