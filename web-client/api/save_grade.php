<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../../backend-php/config/database.php';
require_once '../../backend-php/lib/grade_storage.php';

function ensureSaveGradeColumn(PDO $pdo, string $table, string $column, string $definition): void
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

function tryEnsureSaveGradeColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    try {
        ensureSaveGradeColumn($pdo, $table, $column, $definition);
    } catch (Throwable $error) {
        error_log("Could not ensure $table.$column while saving grade: " . $error->getMessage());
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$submission_id = $input['submission_id'] ?? 0;
$scores = $input['scores'] ?? [];
$total_score = $input['total_score'] ?? 0;

try {
    qodaTryEnsureFinalGradeTable($pdo);
    tryEnsureSaveGradeColumn($pdo, 'exam_submissions', 'exam_score', 'DECIMAL(5,2) DEFAULT 0');
    tryEnsureSaveGradeColumn($pdo, 'exam_submissions', 'class_score', 'DECIMAL(5,2) DEFAULT 0');
    tryEnsureSaveGradeColumn($pdo, 'exam_submissions', 'grade', 'VARCHAR(5) NULL');
    tryEnsureSaveGradeColumn($pdo, 'exam_submissions', 'grade_point', 'DECIMAL(3,1) DEFAULT 0');

    // Get current submission
    $stmt = $pdo->prepare("SELECT percentage, exam_id, class_score FROM exam_submissions WHERE id = ?");
    $stmt->execute([$submission_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$submission) {
        echo json_encode(['success' => false, 'error' => 'Submission not found']);
        exit;
    }
    
    // Get exam total marks
    $stmt2 = $pdo->prepare("SELECT total_marks FROM exams WHERE id = ?");
    $stmt2->execute([$submission['exam_id']]);
    $exam = $stmt2->fetch(PDO::FETCH_ASSOC);
    $total_possible = $exam['total_marks'] ?? 100;
    
    // Calculate percentage (exam is 60% of total, class score is 40%)
    // For now, we store the raw score percentage
    $percentage = ($total_score / $total_possible) * 100;
    $examScore = min(60, max(0, round(($percentage * 60) / 100)));
    $classScore = min(40, max(0, round((float)($submission['class_score'] ?? 0))));
    $finalTotal = min(100, max(0, $examScore + $classScore));
    if ($finalTotal >= 80) { $grade = 'A'; $gradePoint = 4.0; }
    elseif ($finalTotal >= 75) { $grade = 'B+'; $gradePoint = 3.5; }
    elseif ($finalTotal >= 70) { $grade = 'B'; $gradePoint = 3.0; }
    elseif ($finalTotal >= 65) { $grade = 'C+'; $gradePoint = 2.5; }
    elseif ($finalTotal >= 60) { $grade = 'C'; $gradePoint = 2.0; }
    elseif ($finalTotal >= 55) { $grade = 'D+'; $gradePoint = 1.5; }
    elseif ($finalTotal >= 50) { $grade = 'D'; $gradePoint = 1.0; }
    else { $grade = 'E'; $gradePoint = 0.0; }
    
    qodaPersistFinalGrade($pdo, [
        'submission_id' => $submission_id,
        'raw_question_score' => $total_score,
        'percentage' => $percentage,
        'exam_score' => $examScore,
        'class_score' => $classScore,
        'total_score' => $finalTotal,
        'grade' => $grade,
        'grade_point' => $gradePoint,
        'status' => 'MANUALLY_GRADED',
        'score_source' => 'manual',
        'graded_by' => $_SESSION['user_id'] ?? null
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Grades saved successfully', 'percentage' => $percentage]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
