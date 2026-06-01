<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../../backend-php/config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$submission_id = $input['submission_id'] ?? 0;
$scores = $input['scores'] ?? [];
$total_score = $input['total_score'] ?? 0;

try {
    // Get current submission
    $stmt = $pdo->prepare("SELECT answers, percentage, exam_id FROM exam_submissions WHERE id = ?");
    $stmt->execute([$submission_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$submission) {
        echo json_encode(['success' => false, 'error' => 'Submission not found']);
        exit;
    }
    
    $answers = json_decode($submission['answers'], true);
    if (!is_array($answers)) $answers = [];
    
    // Update scores
    $answers['_scores'] = $scores;
    $answers['_graded_at'] = date('Y-m-d H:i:s');
    $answers['_graded_by'] = $_SESSION['user_id'] ?? 'lecturer';
    
    // Get exam total marks
    $stmt2 = $pdo->prepare("SELECT total_marks FROM exams WHERE id = ?");
    $stmt2->execute([$submission['exam_id']]);
    $exam = $stmt2->fetch(PDO::FETCH_ASSOC);
    $total_possible = $exam['total_marks'] ?? 100;
    
    // Calculate percentage (exam is 60% of total, class score is 40%)
    // For now, we store the raw score percentage
    $percentage = ($total_score / $total_possible) * 100;
    
    // Update submission
    $update = $pdo->prepare("
        UPDATE exam_submissions 
        SET answers = ?, total_score = ?, percentage = ?, status = 'MANUALLY_GRADED', graded_at = NOW()
        WHERE id = ?
    ");
    $update->execute([json_encode($answers), $total_score, $percentage, $submission_id]);
    
    echo json_encode(['success' => true, 'message' => 'Grades saved successfully', 'percentage' => $percentage]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>