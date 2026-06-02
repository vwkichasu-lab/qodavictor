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
        if (qodaCollapseOutput($actualNorm) === qodaCollapseOutput($expectedNorm)) {
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

    function qodaExtractFilesFromPayload(array $payload): array
    {
        $files = $payload['files'] ?? [];
        if (is_string($files)) {
            $decoded = json_decode($files, true);
            $files = is_array($decoded) ? $decoded : [];
        }
        return is_array($files) ? $files : [];
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
        $maxMarks = max(0.0, (float)($payload['max_marks'] ?? 20));
        $files = qodaExtractFilesFromPayload($payload);

        $testResults = [];
        $earned = 0.0;
        $testWeights = qodaTestMarks($testCases, $maxMarks);
        $testTotal = array_sum($testWeights);

        foreach ($testCases as $idx => $tc) {
            $input = (string)($tc['input'] ?? '');
            $expected = (string)($tc['expected'] ?? '');
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
            $requiresManualReview = true;
            $rubric = qodaStaticRubricScore($code, $language, $markingScheme, $maxMarks);
            $earned = $rubric['score'];
            $testTotal = 0.0;
        } elseif ($testTotal > $maxMarks && $maxMarks > 0) {
            $earned = ($earned / $testTotal) * $maxMarks;
            $testTotal = $maxMarks;
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
            $feedbackLines[] = 'Method: no lecturer test cases were available, so the score is only a limited static review and must be checked manually.';
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
            ? 'Final decision: manual lecturer review is required before publishing this score.'
            : 'Final decision: the score is based on real code execution and expected-output matching.';

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
            'grading_method' => count($testCases) > 0 ? 'execution_test_cases' : 'static_review_requires_manual_check',
            'consistency_hash' => md5($code . qodaNormalizeLanguage($language) . json_encode($testCases) . $markingScheme . json_encode($files)),
        ];
    }
}
