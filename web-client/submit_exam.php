<?php
require_once __DIR__ . '/../backend-php/config/database.php';
require_once __DIR__ . '/../backend-php/config/response.php';
require_once __DIR__ . '/../backend-php/config/auth.php';

// Check authentication
$user = authenticate();
if (!$user) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Get exam ID from POST
$examId = $_POST['examId'] ?? null;
if (!$examId) {
    jsonResponse(['success' => false, 'message' => 'Exam ID required'], 400);
}

try {
    $db = getDB();
    
    // Get exam details
    $stmt = $db->prepare("SELECT * FROM exams WHERE id = ?");
    $stmt->execute([$examId]);
    $exam = $stmt->fetch();
    
    if (!$exam) {
        jsonResponse(['success' => false, 'message' => 'Exam not found'], 404);
    }
    
    // Get questions for grading
    $stmt = $db->prepare("SELECT * FROM questions WHERE \"examId\" = ?");
    $stmt->execute([$examId]);
    $questions = $stmt->fetchAll();
    
    // Calculate score
    $score = 0;
    $totalQuestions = count($questions);
    
    foreach ($questions as $question) {
        $answerKey = "question_" . $question['id'];
        $studentAnswer = $_POST[$answerKey] ?? null;
        
        if ($studentAnswer !== null && $studentAnswer == $question['correctAnswer']) {
            $score++;
        }
    }
    
    // Calculate percentage
    $percentage = $totalQuestions > 0 ? ($score / $totalQuestions) * 100 : 0;
    
    // Check if submission already exists
    $stmt = $db->prepare("SELECT * FROM submissions WHERE \"examId\" = ? AND \"studentId\" = ?");
    $stmt->execute([$examId, $user->id]);
    $existingSubmission = $stmt->fetch();
    
    $answers = json_encode($_POST);
    
    if ($existingSubmission) {
        // Update existing submission
        $stmt = $db->prepare("UPDATE submissions SET answers = ?, score = ?, percentage = ?, submitted = true, \"submittedAt\" = NOW() WHERE id = ?");
        $stmt->execute([$answers, $score, $percentage, $existingSubmission['id']]);
    } else {
        // Create new submission
        $stmt = $db->prepare("INSERT INTO submissions (\"examId\", \"studentId\", answers, score, percentage, submitted, \"submittedAt\") VALUES (?, ?, ?, ?, ?, true, NOW())");
        $stmt->execute([$examId, $user->id, $answers, $score, $percentage]);
    }
    
    jsonResponse([
        'success' => true, 
        'message' => 'Exam submitted successfully',
        'score' => $score,
        'total' => $totalQuestions,
        'percentage' => round($percentage, 2)
    ]);
    
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
