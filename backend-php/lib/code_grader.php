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
            || ($language === 'css' && preg_match('/[.#]?[A-Za-z0-9_-]+\s*\{[^}]+:/', $code));
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

        foreach ($testCases as $idx => $tc) {
            $input = (string)($tc['input'] ?? '');
            $expected = (string)($tc['expected']
                ?? $tc['expectedOutput']
                ?? $tc['expected_output']
                ?? $tc['output']
                ?? $tc['stdout']
                ?? '');
            $tolerance = isset($tc['tolerance']) && is_numeric($tc['tolerance']) ? (float)$tc['tolerance'] : null;
            $runnerResult = executeQodaCode($code, $language, $input, $files);
            $actual = (string)($runnerResult['output'] ?? '');
            $error = (string)($runnerResult['error'] ?? '');
            $passed = !empty($runnerResult['success']) && qodaOutputsMatch($actual, $expected, $tolerance);
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

        $requiresManualReview = false;
        $rubric = ['score' => 0.0, 'feedback' => [], 'cap' => 0.0];
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
                $runtimeScore = $maxMarks * 0.6;
                $outputBonus = trim($actual) !== '' ? $maxMarks * 0.1 : 0.0;
                $earned = min($maxMarks, $runtimeScore + $rubric['score'] + $outputBonus);
                $requiresManualReview = false;
            } else {
                $requiresManualReview = true;
                $compileLikeError = preg_match('/compile|syntax|javac|gcc|g\+\+|parse|cannot find symbol|expected|missing/i', $error);
                $cap = $compileLikeError ? $maxMarks * 0.35 : $maxMarks * 0.5;
                $earned = min($cap, $rubric['score']);
            }
            $testTotal = 0.0;
        } elseif ($testTotal > $maxMarks && $maxMarks > 0) {
            $earned = ($earned / $testTotal) * $maxMarks;
            $testTotal = $maxMarks;
        }

        if (trim($modelSolution) !== '' && $maxMarks > 0) {
            $modelScore = qodaModelSolutionSimilarityScore($code, $modelSolution, $maxMarks);
            $earned = max($earned, min($maxMarks, $modelScore));
            $rubric['feedback'][] = 'Model solution reference used as an additional similarity check.';
        }

        $score = round(min($maxMarks, max(0.0, $earned)), 2);
        $percentage = $maxMarks > 0 ? round(($score / $maxMarks) * 100, 1) : 0;
        $passedCount = count(array_filter($testResults, fn($r) => !empty($r['passed'])));

        $feedbackLines = [];
        $feedbackLines[] = 'AUTO GRADING REPORT';
        $feedbackLines[] = "Score: {$score} / {$maxMarks} marks ({$percentage}%).";
        if (count($testCases) > 0) {
            $feedbackLines[] = "Method: executed the student's code against " . count($testCases) . " lecturer test case(s).";
            $feedbackLines[] = "Passed: {$passedCount} / " . count($testCases) . " test case(s).";
        } else {
            $feedbackLines[] = 'Method: no lecturer test cases were available, so QODA ran a safe inferred input and combined execution success with static rubric checks.';
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
            'test_score' => round($score, 2),
            'test_total' => round($testTotal, 2),
            'requires_manual_review' => $requiresManualReview,
            'grading_method' => count($testCases) > 0 ? 'execution_test_cases' : ($requiresManualReview ? 'execution_inferred_input_requires_review' : 'execution_inferred_input'),
            'consistency_hash' => md5($code . qodaNormalizeLanguage($language) . json_encode($testCases) . $markingScheme . $expectedOutput . $modelSolution . json_encode($files)),
        ];
    }
}
