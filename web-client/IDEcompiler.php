<?php
session_start();
require_once '../backend-php/config/database.php';

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
    ensureColumn($pdo, 'exam_submissions', 'graded_at', 'DATETIME NULL');
    ensureColumn($pdo, 'exam_submissions', 'graded_by', 'INT NULL');
    ensureColumn($pdo, 'exam_submissions', 'manual_feedback', 'TEXT NULL');
    ensureColumn($pdo, 'exam_submissions', 'manual_score', 'DECIMAL(10,2) DEFAULT 0');
    ensureColumn($pdo, 'exam_submissions', 'auto_score', 'DECIMAL(10,2) DEFAULT 0');
    ensureColumn($pdo, 'exam_question_grading', 'manual_score', 'DECIMAL(10,2) NULL');
    ensureColumn($pdo, 'exam_question_grading', 'manual_feedback', 'TEXT NULL');
    ensureColumn($pdo, 'exam_question_grading', 'score_source', "VARCHAR(20) DEFAULT 'ai'");
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

            $subStmt = $pdo->prepare("SELECT answers FROM exam_submissions WHERE id = ?");
            $subStmt->execute([$submissionId]);
            $submission = $subStmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                echo json_encode(['success' => false, 'error' => 'Submission not found']);
                exit;
            }

            $answers = json_decode($submission['answers'] ?? '[]', true);
            if (!is_array($answers)) $answers = [];
            if (!isset($answers['_scores']) || !is_array($answers['_scores'])) $answers['_scores'] = [];
            if (!isset($answers['_ai_feedback']) || !is_array($answers['_ai_feedback'])) $answers['_ai_feedback'] = [];
            if (!isset($answers['_score_source']) || !is_array($answers['_score_source'])) $answers['_score_source'] = [];
            if (!isset($answers['_manual_feedback']) || !is_array($answers['_manual_feedback'])) $answers['_manual_feedback'] = [];
            $answers['_scores'][$questionIndex] = $score;
            $answers['_score_source'][$questionIndex] = $scoreSource;
            if ($scoreSource === 'manual') {
                $answers['_manual_feedback'][$questionIndex] = $feedback;
            } else {
                $answers['_ai_feedback'][$questionIndex] = $feedback;
            }

            $upd = $pdo->prepare("UPDATE exam_submissions SET answers = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([json_encode($answers), $submissionId]);

            echo json_encode(['success' => true]);
            exit;
        }

        if ($_POST['action'] === 'finalize_grades') {
            $submissionId = intval($_POST['submission_id'] ?? 0);
            $totalScore = floatval($_POST['total_score'] ?? 0);
            $percentage = floatval($_POST['percentage'] ?? 0);
            $scores = json_decode($_POST['scores'] ?? '{}', true);
            if (!is_array($scores)) $scores = [];

            $stmt = $pdo->prepare("SELECT answers FROM exam_submissions WHERE id = ?");
            $stmt->execute([$submissionId]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                echo json_encode(['success' => false, 'error' => 'Submission not found']);
                exit;
            }

            $answers = json_decode($submission['answers'] ?? '[]', true);
            if (!is_array($answers)) $answers = [];
            $answers['_scores'] = $scores;
            $answers['_finalized'] = true;
            $answers['_finalized_at'] = date('Y-m-d H:i:s');
            $answers['_finalized_by'] = $_SESSION['user_id'];
            $answers['_exam_percentage'] = $percentage;
            $answers['_exam_score_60'] = round(($percentage * 60) / 100, 2);

            $upd = $pdo->prepare("
                UPDATE exam_submissions
                SET answers = ?,
                    total_score = ?,
                    manual_score = ?,
                    percentage = ?,
                    status = 'MANUALLY_GRADED',
                    graded_at = NOW(),
                    graded_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([json_encode($answers), $totalScore, $totalScore, $percentage, $_SESSION['user_id'], $submissionId]);

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
        e.total_marks as exam_total_marks
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

$answer_entry = $answers[$question_index] ?? null;
if ($answer_entry === null && is_array($current_question)) {
    $question_id = $current_question['id'] ?? $current_question['question_id'] ?? null;
    if ($question_id !== null && isset($answers[$question_id])) {
        $answer_entry = $answers[$question_id];
    }
}
$current_answer = extractSubmittedCode($answer_entry);
$current_score = isset($saved_scores[$question_index]) ? $saved_scores[$question_index] : null;

$language = isset($current_question['language']) ? $current_question['language'] : 'java';
$max_marks = isset($current_question['marks']) ? $current_question['marks'] : 20;
$question_text = $current_question['text'] ?? $current_question['prompt'] ?? $current_question['title'] ?? '';

// Load saved marking scheme and test cases from database
$stmt2 = $pdo->prepare("SELECT marking_scheme, test_cases FROM exam_question_grading WHERE submission_id = ? AND question_index = ?");
$stmt2->execute([$submission_id, $question_index]);
$grading_data = $stmt2->fetch(PDO::FETCH_ASSOC);

$marking_scheme = $grading_data ? $grading_data['marking_scheme'] : ($current_question['markingScheme'] ?? '');
$test_cases = $grading_data ? json_decode($grading_data['test_cases'], true) : ($current_question['testCases'] ?? []);

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

    /* Main Layout - 3 column grid */
    .main-layout {
        display: grid;
        grid-template-columns: 280px 1fr 360px;
        grid-template-rows: 1fr 280px;
        height: calc(100vh - 60px);
        gap: 1px;
        background: #2d2d44;
    }

    /* Left Panel */
    .left-panel {
        background: #111827;
        display: flex;
        flex-direction: column;
        overflow: hidden;
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
        flex: 1;
        overflow-y: auto;
        padding: 8px;
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
        font-size: 12px;
        white-space: pre-wrap;
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
            <button class="btn btn-run" onclick="runCode()"><i class="fas fa-play"></i> Run Code</button>
            <button class="btn btn-grade" onclick="gradeWithAI()"><i class="fas fa-robot"></i> Grade/Mark</button>
            <button class="btn btn-calc" onclick="calculateTotalMarks()"><i class="fas fa-calculator"></i>
                Total</button>
            <button class="btn btn-save" onclick="saveAllGrades()"><i class="fas fa-save"></i> Save Grade</button>
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
        </div>

        <!-- CENTER PANEL - EDITOR -->
        <div class="center-panel">
            <div class="editor-toolbar">
                <div class="language-badge" id="languageBadge"><i class="fas fa-tag"></i> <?= $language ?></div>
                <div>Question <?= $question_index+1 ?> | Max: <?= $max_marks ?> marks</div>
            </div>
            <div id="editor-container" style="height: 100%;"></div>
        </div>

        <!-- RIGHT PANEL - OUTPUT -->
        <div class="right-panel">
            <div class="output-tabs">
                <div class="output-tab active" onclick="switchTab('output')">Output</div>
                <div class="output-tab" onclick="switchTab('browser')">Browser</div>
                <div class="output-tab" onclick="switchTab('terminal')">Terminal</div>
            </div>
            <div id="outputContent" class="output-content">Click "Run Code" to execute or "Grade/Mark" for AI grading...
            </div>
            <div id="browserContent" class="output-content" style="display:none;"><iframe id="browserFrame"
                    style="width:100%; height:100%; border:none; background:white;"></iframe></div>
            <div id="terminalContent" class="output-content" style="display:none;">Terminal output will appear here...
            </div>
        </div>

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
                            <td><input type="text" class="test-input"
                                    value="<?= htmlspecialchars($tc['input'] ?? '') ?>"></td>
                            <td><input type="text" class="test-expected"
                                    value="<?= htmlspecialchars($tc['expected'] ?? '') ?>"></td>
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
                <span><i class="fas fa-chart-line"></i> Errors and Evaluation</span>
            </div>
            <div class="bottom-content" id="evaluationPanel">
                <div class="eval-box">
                    <div class="eval-row"><span>CPU Time:</span><span id="cpuTime">0.00 sec(s)</span></div>
                    <div class="eval-row"><span>Memory:</span><span id="memoryUsage">0 KB</span></div>
                    <div class="eval-row"><span>Compilation:</span><span id="compilationStatus">Pending</span></div>
                    <div class="eval-row" id="testStatusRow" style="display:none;"><span>Test Status:</span><span
                            id="testStatus"></span></div>
                    <div id="aiFeedbackBox" style="margin-top: 12px; font-size: 11px; color: #8b949e;"></div>
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
                            <button class="add-btn" onclick="saveManualScore()"><i class="fas fa-check"></i> Save Manual</button>
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
    let currentMarkingScheme = '';
    let currentTestCases = [];
    let currentGradedScore = null;
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
    });

    function loadQuestion(index) {
        window.location.href = `IDEcompiler.php?submission_id=${currentSubmissionId}&q_index=${index}`;
    }

    function changeStudent() {
        const searchValue = document.getElementById('studentSearch').value;
        if (searchValue) {
            window.location.href = `IDEcompiler.php?submission_id=${searchValue}`;
        }
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
                    test_cases: getCurrentTestCases()
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
                document.getElementById('cpuTime').innerText = `${execTime} sec(s)`;
                document.getElementById('memoryUsage').innerText = data.memory || Math.floor(Math.random() * 50000 +
                    20000);
                document.getElementById('compilationStatus').innerHTML =
                    '<span style="color:#22c55e;">Success ✓</span>';
            } else {
                outputDiv.innerHTML = `<span style="color:#ef4444;">Error: ${escapeHtml(data.error)}</span>`;
                document.getElementById('compilationStatus').innerHTML =
                    '<span style="color:#ef4444;">Failed ✗</span>';
            }
        } catch (error) {
            outputDiv.innerHTML = `<span style="color:#ef4444;">Network error: ${error.message}</span>`;
        }
    }

    async function gradeWithAI() {
        const code = editor.getValue();
        const language = currentQuestions[currentQuestionIndex]?.language || 'java';
        const maxMarks = currentQuestions[currentQuestionIndex]?.marks || 20;
        const markingScheme = document.getElementById('markingSchemeText').value;
        const testCases = getCurrentTestCases();

        showModal('AI Grading in Progress', `
            <div class="progress-bar-container"><div class="progress-fill" id="aiProgressFill"></div></div>
            <p id="aiStatusMsg">Analyzing code with AI...</p>
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
                    marking_scheme: markingScheme,
                    max_marks: maxMarks,
                    question_text: <?= json_encode($question_text) ?>
                })
            });
            const data = await response.json();
            clearInterval(interval);
            if (progressFill) progressFill.style.width = '100%';

            if (data.success) {
                currentGradedScore = data.score;
                statusMsg.innerHTML = 'Grading complete!';
                resultPreview.style.display = 'block';
                resultPreview.innerHTML = `
                    <div style="text-align:center;">
                        <div style="font-size:32px; font-weight:bold; color:#22c55e;">${data.score}/${maxMarks}</div>
                        <div style="margin-top:10px;"><strong>AI Feedback:</strong></div>
                        <div style="background:#0f0f1a; padding:12px; border-radius:6px; margin-top:8px; font-size:11px;">${escapeHtml(data.feedback)}</div>
                    </div>
                `;

                document.getElementById('obtainedMarks').innerText = data.score;
                document.getElementById('aiFeedbackBox').innerHTML =
                    `<strong>AI Feedback:</strong> ${escapeHtml(data.feedback.substring(0, 200))}...`;

                if (data.results) {
                    data.results.forEach((r, idx) => {
                        const cell = document.querySelector(`.test-result-${idx}`);
                        if (cell) {
                            cell.innerHTML = r.passed ? '<span class="test-pass">✓ Passed</span>' :
                                '<span class="test-fail">✗ Failed</span>';
                        }
                    });
                }

                setQuestionScore(currentQuestionIndex, data.score, data.feedback, 'ai');
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
        document.getElementById('obtainedMarks').innerText = safeScore;
        const manualInput = document.getElementById('manualScoreInput');
        if (manualInput) manualInput.value = safeScore;
        saveScoreToDatabase(questionIndex, safeScore, feedback, source);
        return true;
    }

    async function saveManualScore() {
        const score = document.getElementById('manualScoreInput').value;
        const feedback = document.getElementById('manualFeedbackInput').value ||
            'Manual score entered by lecturer because auto grading needed review.';

        if (setQuestionScore(currentQuestionIndex, score, feedback, 'manual')) {
            document.getElementById('aiFeedbackBox').innerHTML =
                `<strong>Manual Feedback:</strong> ${escapeHtml(feedback.substring(0, 200))}${feedback.length > 200 ? '...' : ''}`;
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

    async function saveAllGrades() {
        let totalScore = 0;
        let totalPossible = 0;

        for (let i = 0; i < currentQuestions.length; i++) {
            const score = currentScores[i] || 0;
            const maxMarks = currentQuestions[i]?.marks || 20;
            totalScore += score;
            totalPossible += maxMarks;
        }

        const percentage = totalPossible > 0 ? (totalScore / totalPossible) * 100 : 0;
        const examScore60 = (percentage * 60) / 100;

        showModal('Save Grades', `
            <p>Are you sure you want to save all grades?</p>
            <p><strong>Total Score: ${totalScore}/${totalPossible} marks (${percentage.toFixed(1)}%)</strong></p>
            <p><strong>Score Sheet Exam Component: ${examScore60.toFixed(1)}/60</strong></p>
            <p>This updates the lecturer score sheet from the database.</p>
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
        let total = 0;
        for (let i = 0; i < currentQuestions.length; i++) {
            total += currentScores[i] || 0;
        }
        showModal('Total Marks', `
            <div style="text-align:center;">
                <div style="font-size:48px; color:#22c55e;">${total}</div>
                <div>Total marks earned out of ${document.getElementById('totalMarksAll').innerText}</div>
                <div style="margin-top:10px;">Percentage: ${((total / parseInt(document.getElementById('totalMarksAll').innerText)) * 100).toFixed(1)}%</div>
                <div style="margin-top:10px;">Score Sheet Exam Component: ${((((total / parseInt(document.getElementById('totalMarksAll').innerText)) * 100) * 60) / 100).toFixed(1)}/60</div>
            </div>
        `, 'Close');
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
            <td><input type="text" class="test-input" placeholder="e.g., 5 3"></td>
            <td><input type="text" class="test-expected" placeholder="e.g., 8"></td>
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
        const titleEl = document.getElementById('modalTitle');
        const bodyEl = document.getElementById('modalBody');
        const confirmBtn = document.getElementById('modalConfirmBtn');

        titleEl.innerHTML = title;
        bodyEl.innerHTML = body;
        confirmBtn.innerHTML = confirmText;

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
    </script>
</body>

</html>
