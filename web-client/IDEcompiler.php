<?php
session_start();
require_once '../backend-php/config/database.php';
require_once '../backend-php/lib/grade_storage.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function tryEnsureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    try {
        ensureColumn($pdo, $table, $column, $definition);
    } catch (Throwable $error) {
        error_log("IDE schema column check skipped for {$table}.{$column}: " . $error->getMessage());
    }
}

function qodaGradeInfo(float $score): array
{
    if ($score >= 80) return ['grade' => 'A', 'point' => 4.0];
    if ($score >= 75) return ['grade' => 'B+', 'point' => 3.5];
    if ($score >= 70) return ['grade' => 'B', 'point' => 3.0];
    if ($score >= 65) return ['grade' => 'C+', 'point' => 2.5];
    if ($score >= 60) return ['grade' => 'C', 'point' => 2.0];
    if ($score >= 55) return ['grade' => 'D+', 'point' => 1.5];
    if ($score >= 50) return ['grade' => 'D', 'point' => 1.0];
    return ['grade' => 'E', 'point' => 0.0];
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_question_grading (
            id INT AUTO_INCREMENT PRIMARY KEY,
            submission_id INT NOT NULL,
            question_index INT NOT NULL,
            marking_scheme TEXT NULL,
            test_cases JSON NULL,
            ai_score DECIMAL(10,2) DEFAULT 0,
            ai_feedback TEXT NULL,
            graded_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_submission_question (submission_id, question_index),
            INDEX idx_submission (submission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    tryEnsureColumn($pdo, 'exam_submissions', 'graded_at', 'DATETIME NULL');
    tryEnsureColumn($pdo, 'exam_submissions', 'graded_by', 'INT NULL');
    tryEnsureColumn($pdo, 'exam_submissions', 'manual_feedback', 'TEXT NULL');
    tryEnsureColumn($pdo, 'exam_submissions', 'manual_score', 'DECIMAL(10,2) DEFAULT 0');
    tryEnsureColumn($pdo, 'exam_submissions', 'auto_score', 'DECIMAL(10,2) DEFAULT 0');
    tryEnsureColumn($pdo, 'exam_submissions', 'class_score', 'DECIMAL(5,2) DEFAULT 0');
    tryEnsureColumn($pdo, 'exam_submissions', 'exam_score', 'DECIMAL(5,2) DEFAULT 0');
    tryEnsureColumn($pdo, 'exam_submissions', 'grade', 'VARCHAR(5) NULL');
    tryEnsureColumn($pdo, 'exam_submissions', 'grade_point', 'DECIMAL(3,1) DEFAULT 0');
    tryEnsureColumn($pdo, 'exam_submissions', 'updated_at', 'DATETIME NULL');
    tryEnsureColumn($pdo, 'exam_question_grading', 'manual_score', 'DECIMAL(10,2) NULL');
    tryEnsureColumn($pdo, 'exam_question_grading', 'manual_feedback', 'TEXT NULL');
    tryEnsureColumn($pdo, 'exam_question_grading', 'score_source', "VARCHAR(20) DEFAULT 'ai'");
    qodaTryEnsureFinalGradeTable($pdo);
} catch (Exception $e) {
    error_log('IDE schema check failed: ' . $e->getMessage());
}

if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        if ($_POST['action'] === 'save_question_score') {
            $submissionId = intval($_POST['submission_id'] ?? 0);
            $questionIndex = intval($_POST['question_index'] ?? 0);
            $score = floatval($_POST['score'] ?? 0);
            $feedback = $_POST['feedback'] ?? '';
            $markingScheme = $_POST['marking_scheme'] ?? '';
            $testCases = $_POST['test_cases'] ?? '[]';
            $scoreSource = ($_POST['score_source'] ?? 'ai') === 'manual' ? 'manual' : 'ai';
            $manualScore = $scoreSource === 'manual' ? $score : null;
            $aiScore = $scoreSource === 'ai' ? $score : null;

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO exam_question_grading
                        (submission_id, question_index, marking_scheme, test_cases, ai_score, ai_feedback, manual_score, manual_feedback, score_source, graded_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        marking_scheme = VALUES(marking_scheme),
                        test_cases = VALUES(test_cases),
                        ai_score = COALESCE(VALUES(ai_score), ai_score),
                        ai_feedback = COALESCE(VALUES(ai_feedback), ai_feedback),
                        manual_score = COALESCE(VALUES(manual_score), manual_score),
                        manual_feedback = COALESCE(VALUES(manual_feedback), manual_feedback),
                        score_source = VALUES(score_source),
                        graded_at = NOW()
                ");
                $stmt->execute([
                    $submissionId,
                    $questionIndex,
                    $markingScheme,
                    $testCases,
                    $aiScore,
                    $scoreSource === 'ai' ? $feedback : null,
                    $manualScore,
                    $scoreSource === 'manual' ? $feedback : null,
                    $scoreSource
                ]);
            } catch (Throwable $error) {
                error_log('Question grading save skipped; database storage is unavailable: ' . $error->getMessage());
                echo json_encode([
                    'success' => true,
                    'warning' => 'Question score kept in the current grading session, but the database could not store the per-question record because storage is unavailable.'
                ]);
                exit;
            }

            $subStmt = $pdo->prepare("SELECT id FROM exam_submissions WHERE id = ?");
            $subStmt->execute([$submissionId]);
            if (!$subStmt->fetch(PDO::FETCH_ASSOC)) {
                echo json_encode(['success' => false, 'error' => 'Submission not found']);
                exit;
            }

            echo json_encode(['success' => true]);
            exit;
        }

        if ($_POST['action'] === 'finalize_grades') {
            $submissionId = intval($_POST['submission_id'] ?? 0);
            $rawTotalScore = max(0, floatval($_POST['total_score'] ?? 0));
            $percentage = min(100, max(0, floatval($_POST['percentage'] ?? 0)));
            $examScore60 = min(60, max(0, round(($percentage * 60) / 100)));
            $scores = json_decode($_POST['scores'] ?? '{}', true);
            if (!is_array($scores)) $scores = [];

            $stmt = $pdo->prepare("SELECT answers, class_score FROM exam_submissions WHERE id = ?");
            $stmt->execute([$submissionId]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                echo json_encode(['success' => false, 'error' => 'Submission not found']);
                exit;
            }

            $answers = json_decode($submission['answers'] ?? '[]', true);
            if (!is_array($answers)) $answers = [];
            $existingGrading = $answers['grading'] ?? $answers['_grading'] ?? [];
            $classScore40 = min(40, max(0, round((float)($submission['class_score'] ?? $existingGrading['class_score'] ?? 0))));
            $finalTotalScore = min(100, max(0, $examScore60 + $classScore40));
            $gradeInfo = qodaGradeInfo($finalTotalScore);

            qodaPersistFinalGrade($pdo, [
                'submission_id' => $submissionId,
                'raw_question_score' => $rawTotalScore,
                'percentage' => $percentage,
                'class_score' => $classScore40,
                'exam_score' => $examScore60,
                'total_score' => $finalTotalScore,
                'grade' => $gradeInfo['grade'],
                'grade_point' => $gradeInfo['point'],
                'status' => 'MANUALLY_GRADED',
                'score_source' => 'manual',
                'graded_by' => $_SESSION['user_id']
            ]);

            echo json_encode(['success' => true]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
$question_index = isset($_GET['q_index']) ? intval($_GET['q_index']) : 0;

// Get submission data
$stmt = $pdo->prepare("
    SELECT 
        es.*,
        s.full_name as student_name,
        s.student_id as student_identifier,
        e.title as exam_title,
        e.questions as exam_questions,
        e.total_marks as exam_total_marks,
        e.questions_to_answer as questions_to_answer
    FROM exam_submissions es
    LEFT JOIN students s ON es.student_id = s.id
    LEFT JOIN exams e ON es.exam_id = e.id
    WHERE es.id = ?
");
$stmt->execute([$submission_id]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    die("Submission not found");
}

// Parse data
$questions = json_decode($submission['exam_questions'], true);
$answers = json_decode($submission['answers'], true);
$saved_scores = isset($answers['_scores']) ? $answers['_scores'] : [];

$current_question = isset($questions[$question_index]) ? $questions[$question_index] : ($questions[0] ?? null);

function extractSubmittedCode($answer): string
{
    if (!is_array($answer)) {
        return is_string($answer) ? $answer : '';
    }

    if (isset($answer['code'])) return (string)$answer['code'];
    if (isset($answer['answer'])) return (string)$answer['answer'];
    if (isset($answer['value']['code'])) return (string)$answer['value']['code'];
    if (isset($answer['value']['files']) && is_array($answer['value']['files'])) {
        foreach ($answer['value']['files'] as $file) {
            if (isset($file['content']) && trim((string)$file['content']) !== '') {
                return (string)$file['content'];
            }
        }
    }

    return '';
}

function extractSubmittedFiles($answer): array
{
    if (!is_array($answer)) {
        return [];
    }

    if (isset($answer['files']) && is_array($answer['files'])) {
        return $answer['files'];
    }
    if (isset($answer['value']['files']) && is_array($answer['value']['files'])) {
        return $answer['value']['files'];
    }

    return [];
}

$answer_entry = $answers[$question_index] ?? null;
if ($answer_entry === null && is_array($current_question)) {
    $question_id = $current_question['id'] ?? $current_question['question_id'] ?? null;
    if ($question_id !== null && isset($answers[$question_id])) {
        $answer_entry = $answers[$question_id];
    }
}
$current_answer = extractSubmittedCode($answer_entry);
$current_files = extractSubmittedFiles($answer_entry);
$current_score = isset($saved_scores[$question_index]) ? $saved_scores[$question_index] : null;

$language = isset($current_question['language']) ? $current_question['language'] : 'java';
$max_marks = isset($current_question['marks']) ? $current_question['marks'] : 20;
$question_text = $current_question['text'] ?? $current_question['prompt'] ?? $current_question['title'] ?? '';

// Load saved marking scheme, test cases, and grading state from database
$stmt2 = $pdo->prepare("
    SELECT marking_scheme, test_cases, ai_score, ai_feedback, manual_score, manual_feedback, score_source
    FROM exam_question_grading
    WHERE submission_id = ? AND question_index = ?
");
$stmt2->execute([$submission_id, $question_index]);
$grading_data = $stmt2->fetch(PDO::FETCH_ASSOC);

$marking_scheme = $grading_data ? $grading_data['marking_scheme'] : ($current_question['markingScheme'] ?? '');
$question_test_cases = $current_question['testCases'] ?? ($current_question['test_cases'] ?? []);
$test_cases = $grading_data ? json_decode($grading_data['test_cases'], true) : $question_test_cases;
if (!is_array($test_cases)) {
    $test_cases = [];
}

$saved_ai_score = $grading_data && $grading_data['ai_score'] !== null ? (float)$grading_data['ai_score'] : null;
$saved_manual_score = $grading_data && $grading_data['manual_score'] !== null ? (float)$grading_data['manual_score'] : null;
$saved_score_source = $grading_data['score_source'] ?? ($answers['_score_source'][$question_index] ?? '');
$saved_ai_feedback = $grading_data['ai_feedback'] ?? ($answers['_ai_feedback'][$question_index] ?? '');
$saved_manual_feedback = $grading_data['manual_feedback'] ?? ($answers['_manual_feedback'][$question_index] ?? '');
if ($current_score === null) {
    if ($saved_score_source === 'manual' && $saved_manual_score !== null) {
        $current_score = $saved_manual_score;
    } elseif ($saved_ai_score !== null) {
        $current_score = $saved_ai_score;
    }
}
$has_autograded = $saved_ai_score !== null || trim((string)$saved_ai_feedback) !== '';

$allGradingStmt = $pdo->prepare("
    SELECT question_index, ai_score, ai_feedback, manual_score, manual_feedback, score_source
    FROM exam_question_grading
    WHERE submission_id = ?
");
$allGradingStmt->execute([$submission_id]);
$question_feedbacks = [];
foreach ($allGradingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $idx = (int)$row['question_index'];
    $effectiveScore = ($row['score_source'] ?? '') === 'manual' && $row['manual_score'] !== null
        ? (float)$row['manual_score']
        : ($row['ai_score'] !== null ? (float)$row['ai_score'] : ($row['manual_score'] !== null ? (float)$row['manual_score'] : null));
    if ($effectiveScore !== null) {
        $saved_scores[$idx] = $effectiveScore;
    }
    $question_feedbacks[$idx] = [
        'ai_score' => $row['ai_score'] !== null ? (float)$row['ai_score'] : null,
        'ai_feedback' => $row['ai_feedback'] ?? '',
        'manual_score' => $row['manual_score'] !== null ? (float)$row['manual_score'] : null,
        'manual_feedback' => $row['manual_feedback'] ?? '',
        'score_source' => $row['score_source'] ?? ''
    ];
}
foreach (($answers['_ai_feedback'] ?? []) as $idx => $feedback) {
    if (!isset($question_feedbacks[$idx])) $question_feedbacks[$idx] = [];
    $question_feedbacks[$idx]['ai_feedback'] = $question_feedbacks[$idx]['ai_feedback'] ?? $feedback;
}
foreach (($answers['_manual_feedback'] ?? []) as $idx => $feedback) {
    if (!isset($question_feedbacks[$idx])) $question_feedbacks[$idx] = [];
    $question_feedbacks[$idx]['manual_feedback'] = $question_feedbacks[$idx]['manual_feedback'] ?? $feedback;
}
foreach (($answers['_score_source'] ?? []) as $idx => $source) {
    if (!isset($question_feedbacks[$idx])) $question_feedbacks[$idx] = [];
    $question_feedbacks[$idx]['score_source'] = $question_feedbacks[$idx]['score_source'] ?? $source;
}

// Calculate total marks
$total_marks_all = 0;
foreach ($questions as $q) {
    $total_marks_all += $q['marks'] ?? 20;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Qoda Code Grader IDE</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.44.0/min/vs/loader.js"></script>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', 'Segoe UI', monospace;
        background: #0a0a0f;
        color: #e0e0e0;
        height: 100vh;
        overflow: hidden;
    }

    /* Top Title Bar - Exact as image */
    .title-bar {
        background: linear-gradient(135deg, #0f0f1a 0%, #1a1a2e 100%);
        border-bottom: 1px solid #2d2d44;
        padding: 12px 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        height: 60px;
    }

    .title-left .student-name {
        font-size: 14px;
        font-weight: 500;
    }

    .title-left .student-name span {
        color: #f97316;
        font-weight: 600;
    }

    .title-left .student-id {
        font-size: 11px;
        color: #8b949e;
        margin-top: 2px;
    }

    .title-center {
        font-size: 18px;
        font-weight: 600;
        letter-spacing: 1px;
    }

    .title-center i {
        color: #f97316;
        margin-right: 8px;
    }

    .title-buttons {
        display: flex;
        gap: 12px;
    }

    .btn {
        padding: 8px 20px;
        border-radius: 6px;
        border: none;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        filter: grayscale(0.4);
    }

    .btn-run {
        background: #238636;
        color: white;
    }

    .btn-run:hover {
        background: #2ea043;
    }

    .btn-grade {
        background: #6e40c9;
        color: white;
    }

    .btn-grade:hover {
        background: #8b5cf6;
    }

    .btn-save {
        background: #f97316;
        color: white;
    }

    .btn-save:hover {
        background: #fd7e14;
    }

    .btn-calc {
        background: #1f6392;
        color: white;
    }

    .btn-calc:hover {
        background: #287fb8;
    }

    /* Main Layout - VS Code-like resizable workspace */
    .main-layout {
        display: grid;
        grid-template-columns: var(--left-col, 280px) 6px minmax(360px, 1fr) 6px var(--right-col, 390px);
        grid-template-rows: minmax(300px, var(--top-row, 58vh)) 6px minmax(220px, 1fr);
        height: calc(100vh - 60px);
        background: #2d2d44;
    }

    .resize-handle {
        background: #2d2d44;
        position: relative;
        z-index: 20;
    }

    .resize-handle.vertical {
        cursor: col-resize;
    }

    .resize-handle.horizontal {
        cursor: row-resize;
        grid-column: 1 / -1;
    }

    .resize-handle:hover,
    body.resizing .resize-handle.active {
        background: #f97316;
    }

    /* Left Panel */
    .left-panel {
        background: #111827;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        grid-column: 1;
        grid-row: 1 / 4;
    }

    .panel-header {
        padding: 16px;
        border-bottom: 1px solid #2d2d44;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .panel-header h3 {
        font-size: 13px;
        font-weight: 600;
    }

    .total-marks {
        font-size: 12px;
        color: #f97316;
    }

    .search-box {
        padding: 12px;
        border-bottom: 1px solid #2d2d44;
    }

    .search-box input {
        width: 100%;
        background: #0f0f1a;
        border: 1px solid #2d2d44;
        padding: 10px 12px;
        border-radius: 8px;
        color: white;
        font-size: 12px;
        outline: none;
    }

    .search-box input:focus {
        border-color: #f97316;
    }

    .questions-list {
        flex: 0 0 42%;
        overflow-y: auto;
        padding: 8px;
    }

    .question-context {
        border-top: 1px solid #2d2d44;
        min-height: 190px;
        overflow: auto;
        padding: 14px;
        background: #0b1220;
    }

    .question-context h4 {
        font-size: 12px;
        margin-bottom: 10px;
        color: #f8fafc;
    }

    .question-context-text {
        background: #0f0f1a;
        border: 1px solid #2d2d44;
        border-radius: 8px;
        padding: 12px;
        color: #dbeafe;
        font-size: 12px;
        line-height: 1.6;
        white-space: pre-wrap;
    }

    .question-item {
        background: #0f0f1a;
        border: 1px solid #2d2d44;
        border-radius: 10px;
        padding: 12px;
        margin-bottom: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .question-item:hover {
        border-color: #f97316;
        background: #1a1a2e;
    }

    .question-item.active {
        border-color: #f97316;
        background: #1a1a2e;
    }

    .question-name {
        font-size: 13px;
        font-weight: 500;
        font-family: monospace;
        margin-bottom: 6px;
    }

    .question-marks {
        font-size: 11px;
        color: #f97316;
    }

    .question-score {
        font-size: 10px;
        margin-top: 6px;
        color: #22c55e;
    }

    /* Center Panel - Editor */
    .center-panel {
        background: #0f0f1a;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        grid-column: 3;
        grid-row: 1;
    }

    .editor-toolbar {
        padding: 10px 16px;
        background: #1a1a2e;
        border-bottom: 1px solid #2d2d44;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .language-badge {
        background: #2d2d44;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
    }

    #editor-container {
        flex: 1;
    }

    /* Right Panel - Output */
    .right-panel {
        background: #111827;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        grid-column: 5;
        grid-row: 1;
    }

    .output-tabs {
        display: flex;
        background: #1a1a2e;
        border-bottom: 1px solid #2d2d44;
        padding: 0 12px;
    }

    .output-tab {
        padding: 10px 16px;
        cursor: pointer;
        font-size: 12px;
        border-bottom: 2px solid transparent;
    }

    .output-tab.active {
        border-bottom-color: #f97316;
        color: #f97316;
    }

    .output-content {
        flex: 1;
        padding: 16px;
        overflow-y: auto;
        font-family: monospace;
        font-size: 16px;
        white-space: pre-wrap;
        background: #0f172a;
        color: #e5e7eb;
        line-height: 1.55;
    }

    /* Bottom Panels */
    .bottom-left,
    .bottom-center,
    .bottom-right {
        background: #111827;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .bottom-left {
        grid-column: 1;
        grid-row: 3;
    }

    .bottom-center {
        grid-column: 3;
        grid-row: 3;
    }

    .bottom-right {
        grid-column: 5;
        grid-row: 3;
    }

    .bottom-header {
        padding: 12px 16px;
        background: #1a1a2e;
        border-bottom: 1px solid #2d2d44;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        font-weight: 600;
    }

    .bottom-content {
        flex: 1;
        overflow-y: auto;
        padding: 12px;
    }

    /* Marking Scheme Items */
    .scheme-item {
        background: #0f0f1a;
        border-left: 3px solid #f97316;
        padding: 10px;
        margin-bottom: 8px;
        border-radius: 6px;
    }

    .scheme-text {
        width: 100%;
        background: #1a1a2e;
        border: 1px solid #2d2d44;
        padding: 8px;
        border-radius: 4px;
        color: white;
        font-size: 11px;
        resize: vertical;
    }

    /* Test Cases Table */
    .test-table {
        width: 100%;
        font-size: 11px;
        border-collapse: collapse;
    }

    .test-table th,
    .test-table td {
        padding: 8px 6px;
        text-align: left;
        border-bottom: 1px solid #2d2d44;
    }

    .test-table th {
        color: #8b949e;
    }

    .test-input,
    .test-expected {
        width: 100%;
        background: #0f0f1a;
        border: 1px solid #2d2d44;
        padding: 6px;
        border-radius: 4px;
        color: white;
        font-size: 10px;
    }

    .run-input-box {
        display: none;
        border-top: 1px solid #2d2d44;
        background: #0b1220;
        padding: 10px 12px;
    }

    .terminal-session {
        min-height: 100%;
        background: #0f172a;
        color: #e5e7eb;
        font-family: Consolas, "Courier New", monospace;
        font-size: 16px;
        line-height: 1.55;
        white-space: pre-wrap;
    }

    .terminal-transcript {
        margin: 0;
        color: inherit;
        white-space: pre-wrap;
        font: inherit;
    }

    .terminal-input-line {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
    }

    .terminal-input-prompt {
        color: #f8fafc;
    }

    .terminal-input-field {
        flex: 0 1 220px;
        min-width: 140px;
        border: 0;
        outline: 0;
        background: transparent;
        color: #f8fafc;
        font: inherit;
        caret-color: #22d3ee;
    }

    .run-input-box label {
        display: flex;
        justify-content: space-between;
        color: #9ca3af;
        font-size: 11px;
        margin-bottom: 6px;
    }

    .run-input-box textarea {
        width: 100%;
        min-height: 74px;
        resize: vertical;
        background: #0f0f1a;
        border: 1px solid #2d2d44;
        color: #e5e7eb;
        border-radius: 6px;
        padding: 9px;
        font-family: Consolas, monospace;
        font-size: 12px;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #2d2d44;
        background: #111827;
        color: #cbd5e1;
        border-radius: 999px;
        padding: 5px 10px;
        font-size: 11px;
    }

    .test-marks {
        width: 60px;
        background: #0f0f1a;
        border: 1px solid #2d2d44;
        padding: 6px;
        border-radius: 4px;
        color: white;
    }

    .test-pass {
        color: #22c55e;
    }

    .test-fail {
        color: #ef4444;
    }

    /* Evaluation Box */
    .eval-box {
        background: #0f0f1a;
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 12px;
    }

    .eval-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px solid #2d2d44;
    }

    .score-display {
        font-size: 28px;
        font-weight: bold;
        color: #22c55e;
        text-align: center;
        margin-top: 12px;
    }

    /* Modal Dialog */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }

    .modal-dialog {
        background: #1a1a2e;
        border-radius: 16px;
        padding: 24px;
        max-width: 450px;
        width: 90%;
        border: 1px solid #f97316;
    }

    .modal-dialog.modal-wide {
        max-width: 980px;
        width: min(96vw, 980px);
    }

    .marking-summary-wrap {
        max-height: 62vh;
        overflow: auto;
        padding-right: 4px;
    }

    .marking-summary-table {
        width: 100%;
        min-width: 760px;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 13px;
    }

    .marking-summary-table th,
    .marking-summary-table td {
        border-bottom: 1px solid #334155;
        padding: 10px 8px;
        vertical-align: top;
    }

    .marking-summary-table th {
        color: #dbeafe;
        text-align: left;
    }

    .marking-summary-table .num-col {
        width: 110px;
    }

    .marking-summary-table .score-col {
        width: 82px;
        text-align: center;
    }

    .marking-summary-table .feedback-col {
        width: auto;
        line-height: 1.45;
        word-break: normal;
        overflow-wrap: anywhere;
    }

    .marking-summary-total {
        margin-top: 16px;
        padding: 16px;
        border: 1px solid #334155;
        border-radius: 10px;
        background: #0f172a;
        line-height: 1.45;
    }

    .modal-dialog h3 {
        margin-bottom: 16px;
    }

    .modal-dialog input,
    .modal-dialog textarea {
        width: 100%;
        background: #0f0f1a;
        border: 1px solid #2d2d44;
        padding: 10px;
        border-radius: 8px;
        color: white;
        margin: 10px 0;
    }

    .modal-buttons {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
    }

    .progress-bar-container {
        height: 4px;
        background: #2d2d44;
        border-radius: 2px;
        margin: 15px 0;
    }

    .progress-fill {
        height: 100%;
        background: #f97316;
        width: 0%;
        transition: width 0.3s;
    }

    .add-btn {
        background: none;
        border: 1px solid #f97316;
        color: #f97316;
        padding: 4px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 11px;
    }

    .add-btn:hover {
        background: #f97316;
        color: white;
    }

    .delete-btn {
        background: none;
        border: none;
        color: #ef4444;
        cursor: pointer;
        font-size: 12px;
    }

    ::-webkit-scrollbar {
        width: 6px;
    }

    ::-webkit-scrollbar-track {
        background: #1a1a2e;
    }

    ::-webkit-scrollbar-thumb {
        background: #2d2d44;
        border-radius: 3px;
    }
    </style>
</head>

<body>
    <span id="compilationStatus" style="display:none;"></span>

    <div class="title-bar">
        <div class="title-left">
            <div class="student-name">Name: <span
                    id="studentName"><?= htmlspecialchars($submission['student_name'] ?? 'Unknown') ?></span></div>
            <div class="student-id">ID: <span
                    id="studentId"><?= htmlspecialchars($submission['student_identifier'] ?? 'N/A') ?></span></div>
        </div>
        <div class="title-center">
            <i class="fas fa-code"></i> Qoda Code Grader IDE
        </div>
        <div class="title-buttons">
            <span class="status-pill" id="autoGradeStatus"><i class="fas fa-shield-halved"></i> Auto-grade required</span>
            <button class="btn btn-run" id="runCodeBtn" onclick="runCode()"><i class="fas fa-play"></i> Run Code</button>
            <button class="btn btn-grade" id="autoGradeBtn" onclick="gradeWithAI()"><i class="fas fa-robot"></i> Auto Grade</button>
            <button class="btn btn-calc" onclick="calculateTotalMarks()"><i class="fas fa-calculator"></i>
                Total</button>
            <button class="btn btn-save" id="saveGradeBtn" onclick="saveAllGrades()"><i class="fas fa-save"></i> Save Grade</button>
        </div>
    </div>

    <div class="main-layout">
        <!-- LEFT PANEL -->
        <div class="left-panel">
            <div class="panel-header">
                <h3><i class="fas fa-file-alt"></i> Question files</h3>
                <div class="total-marks">Total: <span id="totalMarksAll"><?= $total_marks_all ?></span> marks</div>
            </div>
            <div class="search-box">
                <input type="text" id="studentSearch" placeholder="🔍 Pick student by ID" onchange="changeStudent()">
            </div>
            <div class="questions-list" id="questionsList">
                <?php foreach($questions as $idx => $q): 
                $q_lang = $q['language'] ?? 'java';
                $q_marks = $q['marks'] ?? 20;
                $saved_score = isset($saved_scores[$idx]) ? $saved_scores[$idx] : null;
            ?>
                <div class="question-item <?= $idx == $question_index ? 'active' : '' ?>"
                    onclick="loadQuestion(<?= $idx ?>)">
                    <div class="question-name">
                        <i class="fas fa-file-code"></i> Question_<?= $idx+1 ?>.<?= strtolower($q_lang) ?>
                    </div>
                    <div class="question-marks"><?= $q_marks ?> marks</div>
                    <?php if($saved_score !== null): ?>
                    <div class="question-score">✓ Scored: <?= $saved_score ?>/<?= $q_marks ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="question-context">
                <h4><i class="fas fa-circle-question"></i> Question <?= $question_index + 1 ?> Prompt</h4>
                <div class="question-context-text"><?= nl2br(htmlspecialchars($question_text ?: 'No question text was saved for this submission.')) ?></div>
            </div>
        </div>

        <div class="resize-handle vertical" data-resize="left"></div>

        <!-- CENTER PANEL - EDITOR -->
        <div class="center-panel">
            <div class="editor-toolbar">
                <div class="language-badge" id="languageBadge"><i class="fas fa-tag"></i> <?= $language ?></div>
                <div>Question <?= $question_index+1 ?> | Max: <?= $max_marks ?> marks</div>
            </div>
            <div id="editor-container" style="height: 100%;"></div>
        </div>

        <div class="resize-handle vertical" data-resize="right"></div>

        <!-- RIGHT PANEL - OUTPUT -->
        <div class="right-panel">
            <div class="output-tabs">
                <div class="output-tab active" onclick="switchTab('output')">Output</div>
                <div class="output-tab" onclick="switchTab('browser')">Browser</div>
                <div class="output-tab" onclick="switchTab('terminal')">Terminal</div>
            </div>
            <div id="outputContent" class="output-content">Click "Run Code" to execute or "Grade/Mark" for automatic grading...
            </div>
            <div id="browserContent" class="output-content" style="display:none;"><iframe id="browserFrame"
                    style="width:100%; height:100%; border:none; background:white;"></iframe></div>
            <div id="terminalContent" class="output-content" style="display:none;">Terminal output will appear here...
            </div>
            <div class="run-input-box">
                <label for="programInput">
                    <span><i class="fas fa-keyboard"></i> Program input / stdin</span>
                    <span>Use new lines for separate prompts</span>
                </label>
                <textarea id="programInput" placeholder="Example:&#10;2&#10;3"></textarea>
            </div>
        </div>

        <div class="resize-handle horizontal" data-resize="bottom"></div>

        <!-- BOTTOM LEFT - MARKING SCHEME -->
        <div class="bottom-left">
            <div class="bottom-header">
                <span><i class="fas fa-clipboard-list"></i> Marking Scheme</span>
                <button class="add-btn" onclick="editMarkingScheme()"><i class="fas fa-edit"></i> Edit</button>
            </div>
            <div class="bottom-content" id="markingSchemePanel">
                <div class="scheme-item">
                    <textarea id="markingSchemeText" class="scheme-text" rows="5"
                        placeholder="Enter marking scheme here..."><?= htmlspecialchars($marking_scheme) ?></textarea>
                </div>
            </div>
        </div>

        <!-- BOTTOM CENTER - TEST CASES -->
        <div class="bottom-center">
            <div class="bottom-header">
                <span><i class="fas fa-vial"></i> Test Cases</span>
                <button class="add-btn" onclick="addTestCase()"><i class="fas fa-plus"></i> Add Test Case</button>
            </div>
            <div class="bottom-content" id="testCasesPanel">
                <table class="test-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Input</th>
                            <th>Expected Output</th>
                            <th>Weight</th>
                            <th>Result</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="testCasesBody">
                        <?php if(empty($test_cases)): ?>
                        <tr id="noTestCasesRow">
                            <td colspan="6" style="text-align:center;">No test cases. Click "Add Test Case"</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach($test_cases as $idx => $tc): ?>
                        <tr data-test-idx="<?= $idx ?>">
                            <td><?= $idx+1 ?></td>
                            <td><textarea class="test-input" rows="2"><?= htmlspecialchars($tc['input'] ?? '') ?></textarea></td>
                            <td><textarea class="test-expected" rows="2"><?= htmlspecialchars($tc['expected'] ?? '') ?></textarea></td>
                            <td><input type="number" class="test-marks" value="<?= $tc['marks'] ?? 5 ?>"></td>
                            <td class="test-result-<?= $idx ?>">Pending</td>
                            <td><button class="delete-btn" onclick="removeTestCase(this)"><i
                                        class="fas fa-trash"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- BOTTOM RIGHT - EVALUATION -->
        <div class="bottom-right">
            <div class="bottom-header">
                <span><i class="fas fa-chart-line"></i> Feedback and Marks</span>
            </div>
            <div class="bottom-content" id="evaluationPanel">
                <div class="eval-box">
                    <div class="eval-row" id="testStatusRow" style="display:none;"><span>Test Status:</span><span
                            id="testStatus"></span></div>
                    <div id="aiFeedbackBox" style="display:none;"></div>
                    <div class="score-display" id="scoreDisplay">
                        Marks Obtained: <span
                            id="obtainedMarks"><?= $current_score !== null ? $current_score : '-' ?></span> / <span
                            id="maxMarks"><?= $max_marks ?></span>
                    </div>
                    <div style="margin-top: 14px; border-top: 1px solid #2d2d44; padding-top: 12px;">
                        <label style="font-size: 11px; color: #8b949e;">Manual Score for Question <?= $question_index + 1 ?></label>
                        <div style="display: flex; gap: 8px; align-items: center; margin-top: 6px;">
                            <input type="number" id="manualScoreInput" min="0" max="<?= $max_marks ?>" step="0.5"
                                value="<?= $current_score !== null ? htmlspecialchars((string)$current_score) : '' ?>"
                                style="width: 90px; background:#0f0f1a; border:1px solid #2d2d44; color:white; padding:8px; border-radius:6px;">
                            <span style="font-size: 12px; color: #8b949e;">/ <?= $max_marks ?></span>
                            <button class="add-btn" id="saveManualBtn" onclick="saveManualScore()"><i class="fas fa-check"></i> Save Manual</button>
                        </div>
                        <textarea id="manualFeedbackInput" rows="3" placeholder="Manual feedback or reason for overriding auto grade..."
                            style="width:100%; margin-top:8px; background:#0f0f1a; border:1px solid #2d2d44; color:white; padding:8px; border-radius:6px; resize:vertical;"></textarea>
                        <div style="font-size: 10px; color:#8b949e; margin-top:6px;">Manual score overrides AI for this question and is included in the final total.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Dialog -->
    <div id="modalOverlay" class="modal-overlay">
        <div class="modal-dialog">
            <h3 id="modalTitle">Loading...</h3>
            <div id="modalBody"></div>
            <div class="modal-buttons">
                <button class="btn" onclick="closeModal()">Cancel</button>
                <button class="btn btn-grade" id="modalConfirmBtn" onclick="modalConfirm()">Confirm</button>
            </div>
        </div>
    </div>

    <script>
    let editor = null;
    let currentSubmissionId = <?= $submission_id ?>;
    let currentQuestionIndex = <?= $question_index ?>;
    let currentQuestions = <?= json_encode($questions) ?>;
    let currentAnswers = <?= json_encode($answers) ?>;
    let currentScores = <?= json_encode($saved_scores) ?>;
    let questionsToAnswer = <?= intval($submission['questions_to_answer'] ?? 0) ?>;
    let currentSubmittedFiles = <?= json_encode($current_files) ?>;
    let currentMarkingScheme = '';
    let currentTestCases = [];
    let currentGradedScore = null;
    let questionAutoGraded = <?= $has_autograded ? 'true' : 'false' ?>;
    let savedAiFeedback = <?= json_encode($saved_ai_feedback) ?>;
    let savedManualFeedback = <?= json_encode($saved_manual_feedback) ?>;
    let savedScoreSource = <?= json_encode($saved_score_source) ?>;
    let questionFeedbacks = <?= json_encode($question_feedbacks) ?>;
    let modalCallback = null;

    // Initialize Monaco Editor
    require.config({
        paths: {
            vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.44.0/min/vs'
        }
    });
    require(['vs/editor/editor.main'], function() {
        editor = monaco.editor.create(document.getElementById('editor-container'), {
            value: <?= json_encode($current_answer) ?>,
            language: <?= json_encode(strtolower($language)) ?>,
            theme: 'vs-dark',
            fontSize: 13,
            lineNumbers: 'on',
            minimap: {
                enabled: false
            },
            automaticLayout: true,
            scrollBeyondLastLine: false,
            wordWrap: 'on'
        });
        hydrateGradingState();
        initResizableWorkspace();
    });

    function hydrateGradingState() {
        const firstTestInput = getCurrentTestCases()[0]?.input || '';
        const inputBox = document.getElementById('programInput');
        if (inputBox && !inputBox.value && firstTestInput) inputBox.value = firstTestInput;

        if (savedManualFeedback) {
            const manualBox = document.getElementById('manualFeedbackInput');
            if (manualBox) manualBox.value = savedManualFeedback;
        }

        const feedbackBox = document.getElementById('aiFeedbackBox');
        if (feedbackBox) feedbackBox.innerHTML = '';
        updateAutoGradeStatus();
    }

    function updateAutoGradeStatus() {
        const status = document.getElementById('autoGradeStatus');
        const saveBtn = document.getElementById('saveGradeBtn');
        const manualBtn = document.getElementById('saveManualBtn');
        if (!status) return;
        if (questionAutoGraded) {
            status.innerHTML = '<i class="fas fa-circle-check" style="color:#22c55e;"></i> Auto-graded';
            status.style.borderColor = '#14532d';
            status.style.color = '#bbf7d0';
            if (saveBtn) saveBtn.disabled = false;
            if (manualBtn) manualBtn.disabled = false;
        } else {
            status.innerHTML = '<i class="fas fa-triangle-exclamation" style="color:#f97316;"></i> Auto-grade required';
            status.style.borderColor = '#7c2d12';
            status.style.color = '#fed7aa';
            if (saveBtn) saveBtn.disabled = true;
            if (manualBtn) manualBtn.disabled = true;
        }
    }

    function initResizableWorkspace() {
        const layout = document.querySelector('.main-layout');
        if (!layout) return;
        let drag = null;
        document.querySelectorAll('.resize-handle').forEach(handle => {
            handle.addEventListener('pointerdown', (event) => {
                drag = {
                    type: handle.dataset.resize,
                    startX: event.clientX,
                    startY: event.clientY,
                    left: parseFloat(getComputedStyle(layout).getPropertyValue('--left-col')) || 280,
                    right: parseFloat(getComputedStyle(layout).getPropertyValue('--right-col')) || 390,
                    top: parseFloat(getComputedStyle(layout).getPropertyValue('--top-row')) || (window.innerHeight * 0.58)
                };
                handle.classList.add('active');
                document.body.classList.add('resizing');
                handle.setPointerCapture(event.pointerId);
            });
            handle.addEventListener('pointermove', (event) => {
                if (!drag) return;
                if (drag.type === 'left') {
                    layout.style.setProperty('--left-col', Math.max(220, Math.min(520, drag.left + event.clientX - drag.startX)) + 'px');
                } else if (drag.type === 'right') {
                    layout.style.setProperty('--right-col', Math.max(300, Math.min(700, drag.right - (event.clientX - drag.startX))) + 'px');
                } else if (drag.type === 'bottom') {
                    layout.style.setProperty('--top-row', Math.max(280, Math.min(window.innerHeight - 250, drag.top + event.clientY - drag.startY)) + 'px');
                }
                if (editor) editor.layout();
            });
            handle.addEventListener('pointerup', () => {
                drag = null;
                handle.classList.remove('active');
                document.body.classList.remove('resizing');
                if (editor) editor.layout();
            });
        });
    }

    function loadQuestion(index) {
        window.location.href = `IDEcompiler.php?submission_id=${currentSubmissionId}&q_index=${index}`;
    }

    function changeStudent() {
        const searchValue = document.getElementById('studentSearch').value;
        if (searchValue) {
            window.location.href = `IDEcompiler.php?submission_id=${searchValue}`;
        }
    }

    function getCurrentProjectFiles() {
        if (!Array.isArray(currentSubmittedFiles) || currentSubmittedFiles.length === 0) {
            return [];
        }

        let activeUpdated = false;
        const updatedFiles = currentSubmittedFiles.map((file, index) => {
            const copy = {
                ...file
            };
            if (!activeUpdated && (copy.active || index === 0)) {
                copy.content = editor ? editor.getValue() : (copy.content || '');
                copy.active = true;
                activeUpdated = true;
            }
            return copy;
        });
        return updatedFiles;
    }

    async function runCode() {
        const code = editor.getValue();
        const language = currentQuestions[currentQuestionIndex]?.language || 'java';
        const outputDiv = document.getElementById('outputContent');

        outputDiv.innerHTML =
            '<span style="color: #f97316;"><i class="fas fa-spinner fa-spin"></i> Compiling and executing...</span>';

        const startTime = performance.now();

        try {
            const response = await fetch('api/run_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    code: code,
                    language: language,
                    test_cases: getCurrentTestCases(),
                    files: getCurrentProjectFiles()
                })
            });
            const data = await response.json();
            const endTime = performance.now();
            const execTime = ((endTime - startTime) / 1000).toFixed(2);

            if (data.success) {
                let outputHtml = `<div style="margin-bottom:12px;"><strong>Program Output:</strong></div>`;
                outputHtml +=
                    `<pre style="background:#0f0f1a; padding:12px; border-radius:6px;">${escapeHtml(data.output || 'No output')}</pre>`;

                if (data.results && data.results.length) {
                    let passed = 0;
                    outputHtml += `<div style="margin-top:12px;"><strong>Test Results:</strong></div>`;
                    for (const r of data.results) {
                        const color = r.passed ? '#22c55e' : '#ef4444';
                        if (r.passed) passed++;
                        outputHtml += `<div style="margin-top:8px; padding:8px; background:#1a1a2e; border-radius:6px;">
                            <span style="color:${color}">${r.passed ? '✓' : '✗'}</span> Test ${r.test_case}: ${r.passed ? 'PASSED' : 'FAILED'}
                            <div style="font-size:10px; margin-top:4px;">Expected: ${escapeHtml(r.expected)}<br>Got: ${escapeHtml(r.actual)}</div>
                        </div>`;
                    }
                    document.getElementById('testStatusRow').style.display = 'flex';
                    document.getElementById('testStatus').innerHTML = passed === data.results.length ?
                        '<span style="color:#22c55e;">All tests passed ✓</span>' :
                        `<span style="color:#ef4444;">${passed}/${data.results.length} passed</span>`;
                }
                outputDiv.innerHTML = outputHtml;
                setOptionalText('cpuTime', `${execTime} sec(s)`);
                setOptionalText('memoryUsage', data.memory || Math.floor(Math.random() * 50000 + 20000));
                optionalElement('compilationStatus').innerHTML =
                    '<span style="color:#22c55e;">Success ✓</span>';
            } else {
                outputDiv.innerHTML = `<span style="color:#ef4444;">Error: ${escapeHtml(data.error)}</span>`;
                optionalElement('compilationStatus').innerHTML =
                    '<span style="color:#ef4444;">Failed ✗</span>';
            }
        } catch (error) {
            outputDiv.innerHTML = `<span style="color:#ef4444;">Network error: ${error.message}</span>`;
        }
    }

    async function runCode() {
        const code = editor.getValue();
        const language = currentQuestions[currentQuestionIndex]?.language || 'java';
        const normalizedLanguage = String(language).toLowerCase();
        const outputDiv = document.getElementById('outputContent');
        const terminalDiv = document.getElementById('terminalContent');
        const browserFrame = document.getElementById('browserFrame');
        const testCases = getCurrentTestCases();
        let stdin = document.getElementById('programInput')?.value || '';
        const projectFiles = getCurrentProjectFiles();
        const questionText = currentQuestions[currentQuestionIndex]?.text ||
            currentQuestions[currentQuestionIndex]?.prompt ||
            currentQuestions[currentQuestionIndex]?.title ||
            '';

        if (['html', 'css', 'javascript', 'js', 'web'].includes(normalizedLanguage) && testCases.length === 0) {
            const projectFiles = getCurrentProjectFiles();
            const htmlFile = projectFiles.find(f => String(f.name || '').toLowerCase().endsWith('.html'));
            const cssFiles = projectFiles.filter(f => String(f.name || '').toLowerCase().endsWith('.css'));
            const jsFiles = projectFiles.filter(f => String(f.name || '').toLowerCase().endsWith('.js'));
            let html = htmlFile ? htmlFile.content : code;
            if (!/<html[\s>]/i.test(html)) {
                html = `<!doctype html><html><head><meta charset="utf-8"><title>Qoda Preview</title></head><body>${html}</body></html>`;
            }
            if (cssFiles.length) {
                html = html.replace('</head>', `<style>${cssFiles.map(f => f.content || '').join('\n')}</style></head>`);
            }
            if (jsFiles.length && !htmlFile) {
                html = html.replace('</body>', `<script>${jsFiles.map(f => f.content || '').join('\n')}<\/script></body>`);
            }
            if (browserFrame) browserFrame.srcdoc = html;
            outputDiv.innerHTML = '<span style="color:#22c55e;">Browser preview rendered successfully.</span>';
            if (terminalDiv) terminalDiv.innerHTML = 'Web preview rendered in the Browser tab.';
            setOptionalHtml('compilationStatus', '<span style="color:#22c55e;">Preview ready</span>');
            switchTab('browser');
            return;
        }

        outputDiv.innerHTML =
            '<span style="color: #f97316;"><i class="fas fa-spinner fa-spin"></i> Compiling and executing...</span>';
        if (terminalDiv) terminalDiv.innerHTML = 'Running...';
        switchTab('output');
        const startTime = performance.now();

        try {
            const response = await fetch('api/run_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    code: code,
                    language: language,
                    input: stdin,
                    test_cases: testCases,
                    question_text: questionText,
                    files: getCurrentProjectFiles(),
                    use_inferred_input: false
                })
            });
            const data = await response.json();
            const execTime = ((performance.now() - startTime) / 1000).toFixed(2);
            if (data.success) {
                let outputHtml = `<div style="margin-bottom:12px;"><strong>Program Output:</strong></div>`;
                outputHtml += `<pre style="background:#0f0f1a; padding:12px; border-radius:6px;">${escapeHtml(data.output || 'No output')}</pre>`;
                if (terminalDiv) terminalDiv.innerHTML = escapeHtml(data.output || 'No terminal output.');

                if (data.results && data.results.length) {
                    let passed = 0;
                    outputHtml += `<div style="margin-top:12px;"><strong>Test Results:</strong></div>`;
                    for (const r of data.results) {
                        const color = r.passed ? '#22c55e' : '#ef4444';
                        if (r.passed) passed++;
                        outputHtml += `<div style="margin-top:8px; padding:8px; background:#1a1a2e; border-radius:6px;">
                            <span style="color:${color}">${r.passed ? '✓' : '✗'}</span> Test ${r.test_case}: ${r.passed ? 'PASSED' : 'FAILED'}
                            <div style="font-size:10px; margin-top:4px;">Input: ${escapeHtml(r.input || '[none]')}<br>Expected: ${escapeHtml(r.expected)}<br>Got: ${escapeHtml(r.actual || '[empty]')}${r.error ? '<br>Error: ' + escapeHtml(r.error) : ''}</div>
                        </div>`;
                    }
                    document.getElementById('testStatusRow').style.display = 'flex';
                    document.getElementById('testStatus').innerHTML = passed === data.results.length ?
                        '<span style="color:#22c55e;">All tests passed ✓</span>' :
                        `<span style="color:#ef4444;">${passed}/${data.results.length} passed</span>`;
                }

                outputDiv.innerHTML = outputHtml;
                setOptionalText('cpuTime', data.execution_time_ms ? `${data.execution_time_ms} ms` : `${execTime} sec(s)`);
                setOptionalText('memoryUsage', data.memory || 'N/A');
                setOptionalHtml('compilationStatus', '<span style="color:#22c55e;">Success ✓</span>');
            } else {
                const message = data.error || data.output || 'Execution failed.';
                const generatedInputHtml = '';
                outputDiv.innerHTML = `${generatedInputHtml}<span style="color:#ef4444;">Error:</span><pre style="margin-top:10px;">${escapeHtml(message)}</pre>`;
                if (terminalDiv) terminalDiv.innerHTML = escapeHtml(message);
                setOptionalHtml('compilationStatus', '<span style="color:#ef4444;">Failed ✗</span>');
            }
        } catch (error) {
            outputDiv.innerHTML = `<span style="color:#ef4444;">Network error: ${escapeHtml(error.message)}</span>`;
            if (terminalDiv) terminalDiv.innerHTML = `Network error: ${escapeHtml(error.message)}`;
        }
    }

    async function runCode() {
        const code = editor.getValue();
        const language = currentQuestions[currentQuestionIndex]?.language || 'java';
        const normalizedLanguage = String(language).toLowerCase();
        const outputDiv = document.getElementById('outputContent');
        const terminalDiv = document.getElementById('terminalContent');
        const browserFrame = document.getElementById('browserFrame');
        const testCases = getCurrentTestCases();
        let stdin = document.getElementById('programInput')?.value || '';
        const projectFiles = getCurrentProjectFiles();
        const questionText = currentQuestions[currentQuestionIndex]?.text ||
            currentQuestions[currentQuestionIndex]?.prompt ||
            currentQuestions[currentQuestionIndex]?.title ||
            '';

        if (['html', 'css', 'javascript', 'js', 'web'].includes(normalizedLanguage) && testCases.length === 0) {
            const projectFiles = getCurrentProjectFiles();
            const htmlFile = projectFiles.find(file => String(file.name || '').toLowerCase().endsWith('.html'));
            const cssFiles = projectFiles.filter(file => String(file.name || '').toLowerCase().endsWith('.css'));
            const jsFiles = projectFiles.filter(file => String(file.name || '').toLowerCase().endsWith('.js'));
            let html = htmlFile ? htmlFile.content : code;
            if (!/<html[\s>]/i.test(html)) {
                html = `<!doctype html><html><head><meta charset="utf-8"><title>Qoda Preview</title></head><body>${html}</body></html>`;
            }
            if (cssFiles.length) html = html.replace('</head>', `<style>${cssFiles.map(file => file.content || '').join('\n')}</style></head>`);
            if (jsFiles.length && !htmlFile) html = html.replace('</body>', `<script>${jsFiles.map(file => file.content || '').join('\n')}<\/script></body>`);
            if (browserFrame) browserFrame.srcdoc = html;
            outputDiv.innerHTML = '<span style="color:#22c55e;">Browser preview rendered successfully.</span>';
            if (terminalDiv) terminalDiv.innerHTML = 'Web preview rendered in the Browser tab.';
            switchTab('browser');
            return;
        }

        outputDiv.innerHTML = 'Checking code...';
        if (terminalDiv) terminalDiv.innerHTML = 'Running...';
        switchTab('output');

        try {
            if (codeLikelyNeedsInput(code, language)) {
                const preflight = await runCodePreflight(code, language, projectFiles);
                if (!preflight.success) {
                    const message = preflight.error || preflight.output || 'Syntax or compile error detected.';
                    outputDiv.textContent = `Error:\n${message}`;
                    if (terminalDiv) terminalDiv.textContent = `Error:\n${message}`;
                    return;
                }
                stdin = await collectProgramInputInConsole(code, language);
            }

            outputDiv.textContent = 'Running...';
            const response = await fetch('api/run_code.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    code,
                    language,
                    input: stdin,
                    test_cases: testCases,
                    question_text: questionText,
                    files: projectFiles,
                    use_inferred_input: false
                })
            });
            const data = await response.json();
            if (data.success) {
                const transcript = formatConsoleOutputWithInputEcho(data.output || 'No output', stdin, code, language, true);
                let outputHtml = escapeHtml(transcript);
                if (data.results && data.results.length) {
                    let passed = 0;
                    outputHtml += '\n\nTest Results:\n';
                    data.results.forEach(result => {
                        if (result.passed) passed++;
                        outputHtml += `${result.passed ? 'PASSED' : 'FAILED'} Test ${result.test_case}\n`;
                        outputHtml += `Input: ${escapeHtml(result.input || '[none]')}\nExpected: ${escapeHtml(result.expected)}\nGot: ${escapeHtml(result.actual || '[empty]')}${result.error ? '\nError: ' + escapeHtml(result.error) : ''}\n`;
                    });
                    const row = document.getElementById('testStatusRow');
                    if (row) row.style.display = 'flex';
                    setOptionalHtml('testStatus', passed === data.results.length ? '<span style="color:#22c55e;">All tests passed</span>' : `<span style="color:#ef4444;">${passed}/${data.results.length} passed</span>`);
                }
                outputDiv.innerHTML = outputHtml;
                if (terminalDiv) terminalDiv.textContent = transcript || 'No terminal output.';
            } else {
                const message = data.error || data.output || 'Execution failed.';
                const partial = data.output ? formatConsoleOutputWithInputEcho(data.output, stdin, code, language, false) : '';
                outputDiv.textContent = partial ? `${partial}\n\nError:\n${message}` : `Error:\n${message}`;
                if (terminalDiv) terminalDiv.textContent = outputDiv.textContent;
            }
        } catch (error) {
            outputDiv.innerHTML = `<span style="color:#ef4444;">Network error: ${escapeHtml(error.message)}</span>`;
            if (terminalDiv) terminalDiv.innerHTML = `Network error: ${escapeHtml(error.message)}`;
        }
    }

    function codeLikelyNeedsInput(code, language) {
        const lang = String(language || '').toLowerCase();
        const consoleLang = ['c', 'cpp', 'java', 'python', 'py', 'php', 'csharp', 'cs', 'vbnet', 'vb'];
        if (!consoleLang.includes(lang)) return false;
        return /\bscanf\s*\(|\bcin\s*>>|\bScanner\b|\binput\s*\(|\breadline\s*\(|\bConsole\.ReadLine\s*\(|\bfgets\s*\(|\bgets\s*\(/i.test(code);
    }

    function cleanInputPrompt(text, fallback) {
        const cleaned = String(text || '')
            .replace(/\\n/g, '')
            .replace(/\\t/g, ' ')
            .replace(/\s+/g, ' ')
            .replace(/["'`;]+/g, '')
            .trim();
        return cleaned || fallback;
    }

    function countScanfSpecifiers(format) {
        const matches = String(format || '').match(/%(?!%)[*]?(?:\d+)?(?:\.\d+)?[hlLjzt]*[diuoxXfFeEgGaAcspn]/g);
        return matches ? matches.length : 1;
    }

    function decodeConsoleLiteral(text) {
        return String(text || '')
            .replace(/\\n/g, '\n')
            .replace(/\\r/g, '\r')
            .replace(/\\t/g, '\t')
            .replace(/\\"/g, '"')
            .replace(/\\'/g, "'")
            .replace(/\\\\/g, '\\');
    }

    function firstInputCallIndex(code, language) {
        const patterns = [
            /scanf\s*\(/g,
            /cin\s*>>/g,
            /\.\s*next(?:Int|Double|Float|Long|Short|Byte|Boolean|Line)?\s*\(/g,
            /input\s*\(/g,
            /readline\s*\(/g,
            /Console\.ReadLine\s*\(/g,
            /ReadLine\s*\(/g
        ];
        let first = -1;
        patterns.forEach(pattern => {
            let match;
            while ((match = pattern.exec(code)) !== null) {
                if (first === -1 || match.index < first) first = match.index;
            }
        });
        return first;
    }

    function extractPrintedTextBeforeInput(code, language, promptToExclude = '') {
        const firstInput = firstInputCallIndex(code, language);
        if (firstInput <= 0) return '';
        const before = code.slice(0, firstInput);
        const statements = [];
        const readLiteralText = (segment, addNewline = false) => {
            const literalRegex = /["']((?:\\.|[^"'\\])*)["']/g;
            let match;
            let text = '';
            while ((match = literalRegex.exec(segment)) !== null) {
                text += decodeConsoleLiteral(match[1]);
            }
            if (addNewline && text && !text.endsWith('\n')) text += '\n';
            return text;
        };
        const collect = (regex, addNewline = false) => {
            let match;
            while ((match = regex.exec(before)) !== null) {
                statements.push({
                    index: match.index,
                    text: readLiteralText(match[1], addNewline)
                });
            }
        };

        [
            /printf\s*\(([^;]*)\)\s*;/g,
            /System\.out\.print\s*\(([^;]*)\)\s*;/g,
            /Console\.Write\s*\(([^;]*)\)\s*;/g,
            /echo\s+([^;]*);/g
        ].forEach(regex => collect(regex, false));

        [
            /System\.out\.println\s*\(([^;]*)\)\s*;/g,
            /Console\.WriteLine\s*\(([^;]*)\)\s*;/g
        ].forEach(regex => collect(regex, true));

        let coutMatch;
        const coutRegex = /cout\s*<<([^;]*);/g;
        while ((coutMatch = coutRegex.exec(before)) !== null) {
            statements.push({
                index: coutMatch.index,
                text: readLiteralText(coutMatch[1], /\bendl\b/.test(coutMatch[1]))
            });
        }

        let output = statements
            .sort((a, b) => a.index - b.index)
            .map(item => item.text)
            .join('');
        const prompt = cleanInputPrompt(promptToExclude, '');
        if (prompt) {
            const escaped = prompt.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            output = output.replace(new RegExp(`${escaped}\\s*$`, 'i'), '');
        }
        return output.trimEnd();
    }

    function lastPromptBefore(code, index, language, fallback) {
        const before = code.slice(Math.max(0, index - 350), index);
        const promptPatterns = [
            /printf\s*\(\s*"((?:\\.|[^"\\])*)"/g,
            /cout\s*<<\s*"((?:\\.|[^"\\])*)"/g,
            /System\.out\.print(?:ln)?\s*\(\s*"((?:\\.|[^"\\])*)"/g,
            /Console\.Write(?:Line)?\s*\(\s*"((?:\\.|[^"\\])*)"/g
        ];
        let prompt = '';
        for (const pattern of promptPatterns) {
            let match;
            while ((match = pattern.exec(before)) !== null) prompt = match[1];
        }
        const lang = String(language || '').toLowerCase();
        if (!prompt && (lang === 'python' || lang === 'py')) {
            const inputMatch = code.slice(index, index + 160).match(/input\s*\(\s*["']((?:\\.|[^"'\\])*)["']/);
            if (inputMatch) prompt = inputMatch[1];
        }
        if (!prompt && lang === 'php') {
            const inputMatch = code.slice(index, index + 160).match(/readline\s*\(\s*["']((?:\\.|[^"'\\])*)["']/);
            if (inputMatch) prompt = inputMatch[1];
        }
        return cleanInputPrompt(prompt, fallback);
    }

    function buildInputPlan(code, language) {
        const lang = String(language || '').toLowerCase();
        const groups = [];
        if (lang === 'c') {
            const regex = /scanf\s*\(\s*"((?:\\.|[^"\\])*)"/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({ count: countScanfSpecifiers(match[1]), prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}:`), index: match.index });
            }
        } else if (lang === 'cpp') {
            const regex = /cin\s*((?:>>\s*[^;]+)+);/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({ count: (match[1].match(/>>/g) || []).length, prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}:`), index: match.index });
            }
        } else if (lang === 'java') {
            const regex = /\.\s*next(?:Int|Double|Float|Long|Short|Byte|Boolean|Line)?\s*\(/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({ count: 1, prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}:`), index: match.index });
            }
        } else if (lang === 'python' || lang === 'py') {
            const regex = /input\s*\(/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({ count: 1, prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}:`), index: match.index });
            }
        } else if (lang === 'php') {
            const regex = /readline\s*\(/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({ count: 1, prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}:`), index: match.index });
            }
        } else if (['csharp', 'cs', 'vbnet', 'vb'].includes(lang)) {
            const regex = /(Console\.ReadLine|ReadLine)\s*\(/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({ count: 1, prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}:`), index: match.index });
            }
        }
        const total = groups.reduce((sum, group) => sum + group.count, 0);
        return { needsInput: total > 0, groups, total, introOutput: extractPrintedTextBeforeInput(code, lang, groups[0]?.prompt || '') };
    }

    function inputPromptsFromPlan(plan) {
        const prompts = [];
        (plan.groups || []).forEach((group, groupIndex) => {
            const basePrompt = cleanInputPrompt(group.prompt, `Input ${groupIndex + 1}:`);
            for (let i = 0; i < Math.max(1, group.count || 1); i++) {
                prompts.push(group.count > 1 ? `${basePrompt} ${i + 1}` : basePrompt);
            }
        });
        return prompts;
    }

    function renderTerminalPrompt(transcript, prompt) {
        const outputDiv = document.getElementById('outputContent');
        if (!outputDiv) return null;
        outputDiv.innerHTML = `
            <div class="terminal-session">
                <pre class="terminal-transcript">${escapeHtml(transcript)}</pre>
                <div class="terminal-input-line">
                    <span class="terminal-input-prompt">${escapeHtml(prompt)}</span>
                    <input id="terminalInputField" class="terminal-input-field" autocomplete="off" spellcheck="false">
                </div>
            </div>
        `;
        const input = document.getElementById('terminalInputField');
        if (input) input.focus();
        return input;
    }

    function collectProgramInputInConsole(code, language) {
        const plan = buildInputPlan(code, language);
        if (!plan.needsInput) return Promise.resolve(document.getElementById('programInput')?.value || '');
        switchTab('output');
        return new Promise(resolve => {
            const prompts = inputPromptsFromPlan(plan);
            const values = [];
            let transcript = plan.introOutput ? `${plan.introOutput}\n` : '';
            let index = 0;
            const askNext = () => {
                const prompt = prompts[index] || `Input ${index + 1}:`;
                const input = renderTerminalPrompt(transcript, prompt);
                if (!input) return resolve('');
                input.addEventListener('keydown', event => {
                    if (event.key !== 'Enter') return;
                    event.preventDefault();
                    const value = input.value;
                    values.push(value);
                    transcript += `${prompt} ${value}\n`;
                    index += 1;
                    if (index >= prompts.length) {
                        const programInput = values.join('\n') + '\n';
                        const storedInput = document.getElementById('programInput');
                        if (storedInput) storedInput.value = programInput;
                        const outputDiv = document.getElementById('outputContent');
                        if (outputDiv) outputDiv.innerHTML = `<div class="terminal-session"><pre class="terminal-transcript">${escapeHtml(transcript + 'Running...')}</pre></div>`;
                        resolve(programInput);
                    } else {
                        askNext();
                    }
                });
            };
            askNext();
        });
    }

    function formatConsoleOutput(output) {
        return String(output || '').replace(/\r\n/g, '\n').trimEnd();
    }

    function formatConsoleOutputWithInputEcho(output, input, code, language, success = true) {
        let formatted = formatConsoleOutput(output || '');
        const values = String(input || '').replace(/\r\n/g, '\n').split('\n').filter(value => value.length > 0);
        const prompts = inputPromptsFromPlan(buildInputPlan(code, language));
        prompts.forEach((prompt, index) => {
            if (!values[index]) return;
            const escapedPrompt = prompt.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const pattern = new RegExp(`${escapedPrompt}\\s*`, 'i');
            if (pattern.test(formatted)) formatted = formatted.replace(pattern, `${prompt} ${values[index]}\n`);
        });
        if (values.length && !prompts.some(prompt => formatted.toLowerCase().includes(prompt.toLowerCase()))) {
            formatted = `${prompts.map((prompt, index) => `${prompt} ${values[index] || ''}`).join('\n')}\n${formatted}`.trimEnd();
        }
        return formatted || (success ? 'Program finished successfully with no output.' : '');
    }

    async function runCodePreflight(code, language, files) {
        const response = await fetch('api/run_code.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code, language, files, check_only: true })
        });
        return await response.json();
    }

    async function gradeWithAI() {
        const code = editor.getValue();
        const language = currentQuestions[currentQuestionIndex]?.language || 'java';
        const maxMarks = currentQuestions[currentQuestionIndex]?.marks || 20;
        const markingScheme = document.getElementById('markingSchemeText').value;
        const testCases = getCurrentTestCases();
        const stdin = document.getElementById('programInput')?.value || '';
        const questionText = currentQuestions[currentQuestionIndex]?.text ||
            currentQuestions[currentQuestionIndex]?.prompt ||
            currentQuestions[currentQuestionIndex]?.title ||
            '';

        showModal('Auto Grading in Progress', `
            <div class="progress-bar-container"><div class="progress-fill" id="aiProgressFill"></div></div>
            <p id="aiStatusMsg">Running code against lecturer test cases...</p>
            <div id="aiResultPreview" style="margin-top:15px; display:none;"></div>
        `, 'Close');

        const progressFill = document.getElementById('aiProgressFill');
        const statusMsg = document.getElementById('aiStatusMsg');
        const resultPreview = document.getElementById('aiResultPreview');

        let progress = 0;
        const interval = setInterval(() => {
            progress += 10;
            if (progressFill) progressFill.style.width = progress + '%';
            if (progress >= 90) clearInterval(interval);
        }, 300);

        try {
            const response = await fetch('api/grade_ai.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    code: code,
                    language: language,
                    test_cases: testCases,
                    input: stdin,
                    files: getCurrentProjectFiles(),
                    marking_scheme: markingScheme,
                    max_marks: maxMarks,
                    question_text: questionText
                })
            });
            const data = await response.json();
            clearInterval(interval);
            if (progressFill) progressFill.style.width = '100%';

            if (data.success) {
                const cleanFeedback = '';
                currentGradedScore = data.score;
                statusMsg.innerHTML = 'Grading complete!';
                resultPreview.style.display = 'block';
                resultPreview.innerHTML = `
                    <div style="text-align:center;">
                        <div style="font-size:32px; font-weight:bold; color:#22c55e;">${data.score}/${maxMarks}</div>
                        <div style="margin-top:10px;color:#cbd5e1;">Auto grade completed.</div>
                    </div>
                `;

                document.getElementById('obtainedMarks').innerText = data.score;
                const feedbackBox = document.getElementById('aiFeedbackBox');
                if (feedbackBox) feedbackBox.innerHTML = '';

                if (data.results) {
                    data.results.forEach((r, idx) => {
                        const cell = document.querySelector(`.test-result-${idx}`);
                        if (cell) {
                            cell.innerHTML = r.passed ? '<span class="test-pass">✓ Passed</span>' :
                                '<span class="test-fail">✗ Failed</span>';
                        }
                    });
                }

                questionAutoGraded = true;
                savedAiFeedback = '';
                savedScoreSource = 'ai';
                updateAutoGradeStatus();
                setQuestionScore(currentQuestionIndex, data.score, '', 'ai');
            } else {
                statusMsg.innerHTML = 'Grading failed';
                resultPreview.style.display = 'block';
                resultPreview.innerHTML = `<div style="color:#ef4444;">Error: ${escapeHtml(data.error)}</div>`;
            }
        } catch (error) {
            clearInterval(interval);
            statusMsg.innerHTML = 'Error occurred';
            resultPreview.style.display = 'block';
            resultPreview.innerHTML = `<div style="color:#ef4444;">Network error: ${error.message}</div>`;
        }
    }

    function clampQuestionScore(score, questionIndex) {
        const maxMarks = parseFloat(currentQuestions[questionIndex]?.marks || 20);
        const numericScore = parseFloat(score);
        if (Number.isNaN(numericScore)) return null;
        return Math.max(0, Math.min(maxMarks, numericScore));
    }

    function setQuestionScore(questionIndex, score, feedback, source) {
        const safeScore = clampQuestionScore(score, questionIndex);
        if (safeScore === null) {
            showModal('Invalid Score', 'Please enter a valid numeric score.', 'OK');
            return false;
        }

        currentScores[questionIndex] = safeScore;
        if (!questionFeedbacks[questionIndex]) questionFeedbacks[questionIndex] = {};
        questionFeedbacks[questionIndex].score_source = source;
        if (source === 'manual') {
            questionFeedbacks[questionIndex].manual_score = safeScore;
            questionFeedbacks[questionIndex].manual_feedback = feedback || '';
        } else {
            questionFeedbacks[questionIndex].ai_score = safeScore;
            questionFeedbacks[questionIndex].ai_feedback = feedback || '';
        }
        document.getElementById('obtainedMarks').innerText = safeScore;
        const manualInput = document.getElementById('manualScoreInput');
        if (manualInput) manualInput.value = safeScore;
        saveScoreToDatabase(questionIndex, safeScore, feedback, source);
        return true;
    }

    async function saveManualScore() {
        if (!questionAutoGraded) {
            showModal('Auto Grade Required',
                'Run Auto Grade first. After the submitted code has been checked by the system, you can adjust the marks manually with feedback.',
                'OK');
            return;
        }

        const score = document.getElementById('manualScoreInput').value;
        const feedback = document.getElementById('manualFeedbackInput').value ||
            'Manual score entered by lecturer because auto grading needed review.';

        if (setQuestionScore(currentQuestionIndex, score, feedback, 'manual')) {
            const feedbackBox = document.getElementById('aiFeedbackBox');
            if (feedbackBox) feedbackBox.innerHTML = '';
            showModal('Manual Score Saved',
                `Question ${currentQuestionIndex + 1} saved as ${currentScores[currentQuestionIndex]}/${currentQuestions[currentQuestionIndex]?.marks || 20}.`,
                'OK');
        }
    }

    async function saveScoreToDatabase(questionIndex, score, feedback, source = 'ai') {
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'save_question_score',
                    submission_id: currentSubmissionId,
                    question_index: questionIndex,
                    score: score,
                    feedback: feedback,
                    score_source: source,
                    marking_scheme: document.getElementById('markingSchemeText').value,
                    test_cases: JSON.stringify(getCurrentTestCases())
                })
            });
            const result = await response.json();
            if (!result.success) {
                showModal('Save Failed', escapeHtml(result.error || 'Could not save question score.'), 'OK');
            }
        } catch (error) {
            console.error('Error saving:', error);
        }
    }

    function questionMaxMarks(question) {
        if (!question) return 0;
        if (question.hasSubQuestions && Array.isArray(question.subQuestions) && question.subQuestions.length) {
            return question.subQuestions.reduce((sum, subQuestion) => sum + (parseFloat(subQuestion.marks) || 0), 0);
        }
        return parseFloat(question.marks) || 0;
    }

    function calculateRuleBasedGradeTotal() {
        const rows = currentQuestions.map((question, index) => ({
            index,
            question,
            score: parseFloat(currentScores[index]) || 0,
            maxMarks: questionMaxMarks(question),
            compulsory: !!question?.compulsory
        }));
        const limit = questionsToAnswer > 0 ? Math.min(questionsToAnswer, rows.length) : rows.length;
        const compulsoryRows = rows.filter(row => row.compulsory);
        const optionalSlots = Math.max(0, limit - compulsoryRows.length);
        const optionalRows = rows
            .filter(row => !row.compulsory)
            .sort((a, b) => b.score - a.score || b.maxMarks - a.maxMarks)
            .slice(0, optionalSlots);
        const includedRows = [...compulsoryRows, ...optionalRows];
        return {
            includedRows,
            limit,
            totalScore: includedRows.reduce((sum, row) => sum + row.score, 0),
            totalPossible: includedRows.reduce((sum, row) => sum + row.maxMarks, 0),
            includedQuestionNumbers: includedRows.map(row => row.index + 1).sort((a, b) => a - b)
        };
    }

    function questionSummaryTitle(question, index) {
        const type = question?.type || 'coding';
        const title = question?.title || question?.text || question?.prompt || `Question ${index + 1}`;
        const shortTitle = String(title).replace(/\s+/g, ' ').trim().slice(0, 80);
        return `${shortTitle || `Question ${index + 1}`} (${type})`;
    }

    function gradingRemark(percentage) {
        if (percentage >= 80) return 'Excellent performance';
        if (percentage >= 70) return 'Good performance';
        if (percentage >= 60) return 'Satisfactory performance';
        if (percentage >= 50) return 'Pass';
        return 'Needs improvement';
    }

    function buildFinalMarkingSummaryHtml(gradeTotal) {
        const included = new Set((gradeTotal.includedRows || []).map(row => row.index));
        const totalPossible = gradeTotal.totalPossible || 0;
        const percentage = totalPossible > 0 ? (gradeTotal.totalScore / totalPossible) * 100 : 0;
        const rows = currentQuestions.map((question, index) => {
            const maxMarks = questionMaxMarks(question);
            const awarded = parseFloat(currentScores[index]) || 0;
            const feedback = questionFeedbacks[index] || {};
            const autoScore = feedback.ai_score !== null && feedback.ai_score !== undefined ? feedback.ai_score : '';
            const manualScore = feedback.manual_score !== null && feedback.manual_score !== undefined ? feedback.manual_score : '';
            const feedbackText = cleanLecturerFeedback(feedback.score_source === 'manual' && feedback.manual_feedback ? feedback.manual_feedback : (feedback.ai_feedback || feedback.manual_feedback || ''));
            return `
                <tr>
                    <td class="num-col">Question ${index + 1}${included.has(index) ? '' : ' (not counted)'}</td>
                    <td class="score-col">${maxMarks}</td>
                    <td class="score-col">${awarded}</td>
                    <td class="score-col">${autoScore === '' ? '-' : autoScore}</td>
                    <td class="score-col">${manualScore === '' ? '-' : manualScore}</td>
                    <td class="feedback-col">${escapeHtml(feedbackText || 'No feedback saved yet.')}</td>
                </tr>`;
        }).join('');
        return `
            <div class="marking-summary-wrap">
                <table class="marking-summary-table">
                    <thead>
                        <tr>
                            <th class="num-col">Question</th>
                            <th class="score-col">Total</th>
                            <th class="score-col">Awarded</th>
                            <th class="score-col">Auto</th>
                            <th class="score-col">Manual</th>
                            <th class="feedback-col">Feedback</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
                <div class="marking-summary-total">
                    <div><strong>Total:</strong> ${gradeTotal.totalScore}/${totalPossible}</div>
                    <div><strong>Percentage:</strong> ${percentage.toFixed(1)}%</div>
                    <div><strong>Remark:</strong> ${gradingRemark(percentage)}</div>
                    <div><strong>Rule Applied:</strong> ${questionsToAnswer > 0 ? `Best ${gradeTotal.limit} question(s)` : 'Answer all questions'}${gradeTotal.includedQuestionNumbers.length ? ` | Counted: Q${gradeTotal.includedQuestionNumbers.join(', Q')}` : ''}</div>
                    <div><strong>Score Sheet Exam Component:</strong> ${((percentage * 60) / 100).toFixed(1)}/60</div>
                </div>
            </div>
        `;
    }

    async function saveAllGrades() {
        if (!questionAutoGraded) {
            showModal('Auto Grade Required',
                'Please run Auto Grade for the current question before saving final grades. This keeps every manual adjustment tied to an automatic execution report.',
                'OK');
            return;
        }

        const missing = currentQuestions
            .map((question, index) => currentScores[index] === undefined || currentScores[index] === null ? index + 1 : null)
            .filter(Boolean);
        if (missing.length > 0) {
            showModal('Incomplete Grading',
                `Please grade these question(s) before final save: ${missing.join(', ')}.`,
                'OK');
            return;
        }

        const gradeTotal = calculateRuleBasedGradeTotal();
        const totalScore = gradeTotal.totalScore;
        const totalPossible = gradeTotal.totalPossible;
        const percentage = totalPossible > 0 ? (totalScore / totalPossible) * 100 : 0;
        const examScore60 = (percentage * 60) / 100;

        showModal('Save Grades', `
            <p>Confirm the final marking summary below. This updates the lecturer score sheet from the database.</p>
            ${buildFinalMarkingSummaryHtml(gradeTotal)}
        `, 'Confirm Save', async () => {
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'finalize_grades',
                        submission_id: currentSubmissionId,
                        total_score: totalScore,
                        percentage: percentage,
                        scores: JSON.stringify(currentScores)
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showModal('Success', '✅ Grades saved successfully! You can close this window.',
                        'OK');
                    setTimeout(() => window.close(), 2000);
                } else {
                    showModal('Error', 'Failed to save grades: ' + (data.error || 'Unknown error'),
                        'OK');
                }
            } catch (error) {
                showModal('Error', 'Network error: ' + error.message, 'OK');
            }
        });
    }

    function calculateTotalMarks() {
        const gradeTotal = calculateRuleBasedGradeTotal();
        showModal('Final Marking Summary', buildFinalMarkingSummaryHtml(gradeTotal), 'Close');
    }

    function getCurrentTestCases() {
        const testCases = [];
        const rows = document.querySelectorAll('#testCasesBody tr');
        rows.forEach((row, idx) => {
            if (row.id === 'noTestCasesRow') return;
            const inputs = row.querySelectorAll('.test-input');
            const expecteds = row.querySelectorAll('.test-expected');
            const marks = row.querySelectorAll('.test-marks');
            if (inputs[0]) {
                testCases.push({
                    input: inputs[0].value,
                    expected: expecteds[0].value,
                    marks: parseInt(marks[0].value) || 5
                });
            }
        });
        return testCases;
    }

    function addTestCase() {
        const tbody = document.getElementById('testCasesBody');
        const noRow = document.getElementById('noTestCasesRow');
        if (noRow) noRow.remove();

        const newIndex = tbody.children.length;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${newIndex + 1}</td>
            <td><textarea class="test-input" rows="2" placeholder="Example:\n2\n3"></textarea></td>
            <td><textarea class="test-expected" rows="2" placeholder="Example:\n5"></textarea></td>
            <td><input type="number" class="test-marks" value="5" style="width:60px;"></td>
            <td class="test-result-${newIndex}">Pending</td>
            <td><button class="delete-btn" onclick="removeTestCase(this)"><i class="fas fa-trash"></i></button></td>
        `;
        tbody.appendChild(tr);
        renumberTestCases();
    }

    function removeTestCase(btn) {
        const row = btn.closest('tr');
        row.remove();
        renumberTestCases();
    }

    function renumberTestCases() {
        const rows = document.querySelectorAll('#testCasesBody tr');
        rows.forEach((row, idx) => {
            row.cells[0].innerText = idx + 1;
        });
    }

    function editMarkingScheme() {
        const currentScheme = document.getElementById('markingSchemeText').value;
        showModal('Edit Marking Scheme', `
            <textarea id="schemeEditInput" rows="6" style="width:100%; background:#0f0f1a; border:1px solid #2d2d44; padding:10px; border-radius:6px; color:white;">${escapeHtml(currentScheme)}</textarea>
            <p style="font-size:11px; color:#8b949e; margin-top:8px;">The AI will use this scheme to grade the code.</p>
        `, 'Save', () => {
            const newScheme = document.getElementById('schemeEditInput').value;
            document.getElementById('markingSchemeText').value = newScheme;
            closeModal();
        });
    }

    function switchTab(tab) {
        const outputDiv = document.getElementById('outputContent');
        const browserDiv = document.getElementById('browserContent');
        const terminalDiv = document.getElementById('terminalContent');
        const tabs = document.querySelectorAll('.output-tab');

        tabs.forEach(t => t.classList.remove('active'));

        if (tab === 'output') {
            tabs[0].classList.add('active');
            outputDiv.style.display = 'block';
            browserDiv.style.display = 'none';
            terminalDiv.style.display = 'none';
        } else if (tab === 'browser') {
            tabs[1].classList.add('active');
            outputDiv.style.display = 'none';
            browserDiv.style.display = 'block';
            terminalDiv.style.display = 'none';
        } else if (tab === 'terminal') {
            tabs[2].classList.add('active');
            outputDiv.style.display = 'none';
            browserDiv.style.display = 'none';
            terminalDiv.style.display = 'block';
        }
    }

    function showModal(title, body, confirmText, onConfirm = null) {
        const overlay = document.getElementById('modalOverlay');
        const dialog = overlay.querySelector('.modal-dialog');
        const titleEl = document.getElementById('modalTitle');
        const bodyEl = document.getElementById('modalBody');
        const confirmBtn = document.getElementById('modalConfirmBtn');

        titleEl.innerHTML = title;
        bodyEl.innerHTML = body;
        confirmBtn.innerHTML = confirmText;
        if (dialog) {
            dialog.classList.toggle('modal-wide', /summary|save grades/i.test(String(title)));
        }

        modalCallback = onConfirm;

        if (onConfirm === null) {
            confirmBtn.onclick = closeModal;
        } else {
            confirmBtn.onclick = () => {
                if (modalCallback) modalCallback();
                closeModal();
            };
        }

        overlay.style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('modalOverlay').style.display = 'none';
        modalCallback = null;
    }

    function modalConfirm() {
        if (modalCallback) modalCallback();
        closeModal();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;');
    }

    function setOptionalText(id, value) {
        const element = document.getElementById(id);
        if (element) element.innerText = value;
    }

    function setOptionalHtml(id, value) {
        const element = document.getElementById(id);
        if (element) element.innerHTML = value;
    }

    function optionalElement(id) {
        const element = document.getElementById(id);
        return element || {
            set innerText(value) {},
            set innerHTML(value) {}
        };
    }

    function cleanLecturerFeedback(feedback) {
        let text = String(feedback || '')
            .replace(/AUTO GRADING REPORT:?/ig, '')
            .replace(/QODA ran a safe inferred input.*?checks\./ig, '')
            .replace(/CPU Time:.*$/img, '')
            .replace(/Memory:.*$/img, '')
            .replace(/Compilation:.*$/img, '')
            .trim();
        if (!text) return 'Code was checked. Review the run output, test-case results, and marks awarded.';
        return text.length > 900 ? text.slice(0, 900) + '...' : text;
    }
    </script>
</body>

</html>
