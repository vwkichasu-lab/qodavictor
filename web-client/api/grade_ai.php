<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../../backend-php/config/database.php';
require_once '../../backend-php/lib/code_grader.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload.']);
    exit;
}

$code = (string)($input['code'] ?? '');
$language = (string)($input['language'] ?? 'java');
$testCases = $input['test_cases'] ?? [];
$markingScheme = (string)($input['marking_scheme'] ?? '');
$files = $input['files'] ?? [];
$maxMarks = (float)($input['max_marks'] ?? 20);
$questionText = (string)($input['question_text'] ?? '');
$manualInput = (string)($input['input'] ?? '');
$inferredInput = $manualInput !== '' ? $manualInput : qodaInferSampleInput($code, $language, $questionText);
$consistencyHash = md5($code . qodaNormalizeLanguage($language) . json_encode($testCases) . $markingScheme . json_encode($files) . $questionText . $inferredInput . 'v2_execution_fallback');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS ai_grading_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consistency_hash CHAR(32) NOT NULL UNIQUE,
        score DECIMAL(10,2) NOT NULL DEFAULT 0,
        feedback TEXT NULL,
        results JSON NULL,
        grading_method VARCHAR(80) NULL,
        requires_manual_review TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_consistency_hash (consistency_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

try {
    $columnStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_grading_cache' AND COLUMN_NAME = ?
    ");
    foreach ([
        'results' => 'JSON NULL',
        'grading_method' => 'VARCHAR(80) NULL',
        'requires_manual_review' => 'TINYINT(1) DEFAULT 0',
    ] as $column => $definition) {
        $columnStmt->execute([$column]);
        if ((int)$columnStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE ai_grading_cache ADD COLUMN `$column` $definition");
        }
    }
} catch (Throwable $e) {
    error_log('AI grading cache schema check failed: ' . $e->getMessage());
}

$stmt = $pdo->prepare("SELECT score, feedback, results, grading_method, requires_manual_review FROM ai_grading_cache WHERE consistency_hash = ?");
$stmt->execute([$consistencyHash]);
$cached = $stmt->fetch(PDO::FETCH_ASSOC);

if ($cached) {
    $results = json_decode($cached['results'] ?? '[]', true);
    if (!is_array($results)) $results = [];
    echo json_encode([
        'success' => true,
        'score' => (float)$cached['score'],
        'max_marks' => $maxMarks,
        'percentage' => $maxMarks > 0 ? round(((float)$cached['score'] / $maxMarks) * 100, 1) : 0,
        'feedback' => $cached['feedback'],
        'consistency_hash' => $consistencyHash,
        'results' => $results,
        'test_results' => $results,
        'grading_method' => $cached['grading_method'] ?: 'execution_test_cases',
        'requires_manual_review' => (bool)$cached['requires_manual_review'],
        'cached' => true,
    ]);
    exit;
}

try {
    $result = gradeQodaCode($input);
    $result['consistency_hash'] = $consistencyHash;

    $insert = $pdo->prepare("
        INSERT INTO ai_grading_cache
            (consistency_hash, score, feedback, results, grading_method, requires_manual_review, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $insert->execute([
        $consistencyHash,
        $result['score'],
        $result['feedback'],
        json_encode($result['results'] ?? []),
        $result['grading_method'] ?? null,
        !empty($result['requires_manual_review']) ? 1 : 0,
    ]);

    echo json_encode($result);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Grading failed: ' . $e->getMessage(),
    ]);
}
