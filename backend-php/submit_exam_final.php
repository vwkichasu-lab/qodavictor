<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$examId = $_POST['exam_id'] ?? 0;
$answers = $_POST['answers'] ?? '';
$totalScore = floatval($_POST['total_score'] ?? 0);
$totalMarks = floatval($_POST['total_marks'] ?? 0);
$percentage = floatval($_POST['percentage'] ?? 0);
$studentId = $_SESSION['user_id'];

// Get student row id
$studRow = $pdo->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
$studRow->execute([$studentId]);
$student = $studRow->fetch();
$studentRowId = $student ? $student['id'] : $studentId;

// Check if already submitted
$checkStmt = $pdo->prepare("SELECT id, status FROM exam_submissions WHERE exam_id = ? AND student_id = ?");
$checkStmt->execute([$examId, $studentRowId]);
$existing = $checkStmt->fetch();

if ($existing && $existing['status'] !== 'in_progress') {
    echo json_encode(['success' => false, 'error' => 'Exam already submitted']);
    exit;
}

try {
    if ($existing) {
        // Update existing submission
        $stmt = $pdo->prepare("
            UPDATE exam_submissions 
            SET answers = ?, 
                total_score = ?, 
                total_marks = ?, 
                percentage = ?, 
                status = 'SUBMITTED', 
                submitted_at = NOW(),
                answers_json = ?
            WHERE id = ?
        ");
        $stmt->execute([$answers, $totalScore, $totalMarks, $percentage, $answers, $existing['id']]);
        $submissionId = $existing['id'];
    } else {
        // Create new submission
        $stmt = $pdo->prepare("
            INSERT INTO exam_submissions 
            (exam_id, student_id, answers, total_score, total_marks, percentage, status, submitted_at, answers_json)
            VALUES (?, ?, ?, ?, ?, ?, 'SUBMITTED', NOW(), ?)
        ");
        $stmt->execute([$examId, $studentRowId, $answers, $totalScore, $totalMarks, $percentage, $answers]);
        $submissionId = $pdo->lastInsertId();
    }
    
    // Clear any in_progress status for this student
    $clearStmt = $pdo->prepare("
        UPDATE exam_submissions 
        SET status = 'SUBMITTED' 
        WHERE exam_id = ? AND student_id = ? AND status = 'in_progress'
    ");
    $clearStmt->execute([$examId, $studentRowId]);
    
    echo json_encode([
        'success' => true, 
        'submission_id' => $submissionId,
        'message' => 'Exam submitted successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Submit exam error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>