<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/lib/code_grader.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload.']);
    exit;
}

try {
    echo json_encode(gradeQodaCode($input));
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Grading failed: ' . $e->getMessage(),
    ]);
}
