<?php
// setup_submissions_table.php - Run this once to create or verify the submissions table

require_once __DIR__ . '/../backend-php/config/database.php';

session_start();
$lecturerId = $_SESSION['user_id'] ?? 1;

try {
    global $pdo;
    
    // Create the submissions table without deleting existing submissions.
    $sql = "
    CREATE TABLE IF NOT EXISTS exam_submissions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        exam_id INT NOT NULL,
        student_id INT NOT NULL,
        student_name VARCHAR(255),
        student_identifier VARCHAR(100),
        answers LONGTEXT,
        answers_json JSON NULL,
        total_score DECIMAL(10,2) DEFAULT 0,
        total_marks INT DEFAULT 0,
        auto_score DECIMAL(10,2) DEFAULT 0,
        manual_score DECIMAL(10,2) DEFAULT 0,
        percentage DECIMAL(5,2) DEFAULT 0,
        status VARCHAR(50) DEFAULT 'in_progress',
        started_at DATETIME NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        submittedAt DATETIME NULL,
        submitted TINYINT(1) DEFAULT 0,
        graded_at DATETIME NULL,
        graded_by INT NULL,
        manual_feedback TEXT NULL,
        execution_results JSON NULL,
        ai_feedback TEXT NULL,
        auto_graded_at DATETIME NULL,
        ip_address VARCHAR(45),
        user_agent TEXT NULL,
        updated_at DATETIME NULL,
        INDEX idx_exam (exam_id),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    
    $pdo->exec($sql);
    echo "✅ Submissions table created successfully!<br>";
    
    // Get first exam
    $examStmt = $pdo->prepare("SELECT id, title FROM exams WHERE lecturer_id = ? LIMIT 1");
    $examStmt->execute([$lecturerId]);
    $exam = $examStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($exam) {
        echo "✅ Found exam: " . $exam['title'] . "<br>";
    } else {
        echo "⚠️ No exam found. Please create an exam first.<br>";
    }
    
    // Get first student
    $studentStmt = $pdo->prepare("SELECT id, full_name, student_id FROM students WHERE lecturer_id = ? LIMIT 1");
    $studentStmt->execute([$lecturerId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        echo "✅ Found student: " . $student['full_name'] . "<br>";
    } else {
        echo "⚠️ No student found. Please add a student first.<br>";
    }
    
    // Create test submission if both exist
    if ($exam && $student) {
        $testAnswers = json_encode([
            ['question' => 1, 'answer' => 'This is a test answer for question 1.'],
            ['question' => 2, 'answer' => 'This is a test answer for question 2.'],
            ['question' => 3, 'answer' => 'This is a test answer for question 3.']
        ]);
        
        $insertStmt = $pdo->prepare("
            INSERT INTO exam_submissions (exam_id, student_id, student_name, student_identifier, answers, submitted_at, status)
            VALUES (?, ?, ?, ?, ?, NOW(), 'SUBMITTED')
        ");
        
        $insertStmt->execute([
            $exam['id'],
            $student['id'],
            $student['full_name'],
            $student['student_id'],
            $testAnswers
        ]);
        
        echo "✅ Test submission created successfully!<br>";
    }
    
    echo "<br><a href='lecturer_dashboard.php'>Go back to Dashboard</a>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
