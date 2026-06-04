<?php
header('Content-Type: application/json');

require_once __DIR__ . '/lib/code_runner.php';

$code = (string)($_POST['code'] ?? '');
$language = (string)($_POST['language'] ?? '');
$input = (string)($_POST['input'] ?? '');
$checkOnly = !empty($_POST['check_only']);
$postedFiles = [];

if (!empty($_POST['files'])) {
    $decodedFiles = json_decode((string)$_POST['files'], true);
    if (is_array($decodedFiles)) {
        $postedFiles = $decodedFiles;
    }
}

$result = $checkOnly
    ? checkQodaCodeSyntax($code, $language, $postedFiles)
    : executeQodaCode($code, $language, $input, $postedFiles);

echo json_encode([
    'success' => !empty($result['success']),
    'output' => $result['output'] ?? '',
    'error' => $result['error'] ?? '',
    'execution_time_ms' => $result['execution_time_ms'] ?? null,
    'language' => $result['language'] ?? qodaNormalizeLanguage($language),
]);
