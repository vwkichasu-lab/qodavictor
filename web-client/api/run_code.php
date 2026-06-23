<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../../backend-php/lib/code_runner.php';
require_once '../../backend-php/lib/code_grader.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload.']);
    exit;
}

$code = (string)($input['code'] ?? '');
$language = (string)($input['language'] ?? 'java');
$files = $input['files'] ?? [];
$programInput = (string)($input['input'] ?? '');
$useInferredInput = !empty($input['use_inferred_input']);
$checkOnly = !empty($input['check_only']);
$questionText = (string)($input['question_text'] ?? '');
$testCases = $input['test_cases'] ?? [];
if (is_string($testCases)) {
    $decoded = json_decode($testCases, true);
    $testCases = is_array($decoded) ? $decoded : [];
}

try {
    if ($checkOnly) {
        $run = checkQodaCodeSyntax($code, $language, is_array($files) ? $files : []);
        echo json_encode([
            'success' => !empty($run['success']),
            'output' => $run['output'] ?? '',
            'error' => $run['error'] ?? '',
            'execution_time_ms' => $run['execution_time_ms'] ?? null,
            'memory' => 'N/A',
        ]);
        exit;
    }

    if (is_array($testCases) && count($testCases) > 0) {
        $results = [];
        $combinedOutput = '';
        $staticTestLanguage = qodaShouldUseStaticTestMatching($code, $language);
        foreach (array_values($testCases) as $idx => $tc) {
            $caseInput = (string)($tc['input'] ?? '');
            $expected = (string)($tc['expected'] ?? $tc['expectedOutput'] ?? '');
            if ($staticTestLanguage) {
                $passed = qodaStaticTestMatches($code, $expected, (string)($tc['comparisonMode'] ?? 'contains'));
                $run = ['success' => true, 'output' => $passed ? 'Static requirement found in code.' : 'Static requirement not found in code.', 'error' => '', 'execution_time_ms' => 0];
            } else {
                $run = executeQodaCode($code, $language, $caseInput, is_array($files) ? $files : []);
                $passed = !empty($run['success']) && qodaOutputsMatch((string)($run['output'] ?? ''), $expected, isset($tc['tolerance']) ? (float)$tc['tolerance'] : null);
            }
            $actual = (string)($run['output'] ?? '');
            $error = (string)($run['error'] ?? '');
            $results[] = [
                'test_case' => $idx + 1,
                'passed' => $passed,
                'input' => $caseInput,
                'expected' => qodaNormalizeOutput($expected),
                'actual' => qodaNormalizeOutput($actual),
                'error' => qodaNormalizeOutput($error),
                'execution_time_ms' => $run['execution_time_ms'] ?? null,
            ];
            $combinedOutput .= "Test " . ($idx + 1) . " output:\n" . trim($actual);
            if (trim($error) !== '') {
                $combinedOutput .= "\nError:\n" . trim($error);
            }
            $combinedOutput .= "\n\n";
        }
        echo json_encode([
            'success' => true,
            'output' => trim($combinedOutput),
            'results' => $results,
            'memory' => 'N/A',
        ]);
        exit;
    }

    $generatedInput = '';
    if ($programInput === '' && $useInferredInput) {
        $generatedInput = qodaInferSampleInput($code, $language, $questionText);
        $programInput = $generatedInput;
    }

    $run = qodaShouldUseStaticTestMatching($code, $language)
        ? ['success' => true, 'output' => 'Model solution is available for browser/static review.', 'error' => '', 'execution_time_ms' => 0]
        : executeQodaCode($code, $language, $programInput, is_array($files) ? $files : []);
    echo json_encode([
        'success' => !empty($run['success']),
        'output' => $run['output'] ?? '',
        'error' => $run['error'] ?? '',
        'execution_time_ms' => $run['execution_time_ms'] ?? null,
        'memory' => 'N/A',
        'generated_input' => $generatedInput,
        'input_used' => $programInput,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
