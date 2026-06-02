<?php

// ========== FIXED: lecturer_dashboard.php ==========

// First, require database config (this creates $pdo)
// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function to round to nearest whole number
function roundToInt($value)
{
    return (int)round(floatval($value));
}

function qodaQuestionMarks(array $question): float
{
    if (!empty($question['hasSubQuestions']) && !empty($question['subQuestions']) && is_array($question['subQuestions'])) {
        $sum = 0.0;
        foreach ($question['subQuestions'] as $subQuestion) {
            $sum += floatval($subQuestion['marks'] ?? 0);
        }
        return $sum;
    }

    return floatval($question['marks'] ?? 0);
}

function qodaEffectiveExamMarks(array $questions, int $questionsToAnswer = 0): float
{
    if (!$questions) {
        return 0.0;
    }

    $limit = $questionsToAnswer > 0 ? min($questionsToAnswer, count($questions)) : count($questions);
    $compulsoryMarks = 0.0;
    $optionalMarks = [];

    foreach ($questions as $question) {
        $marks = qodaQuestionMarks(is_array($question) ? $question : []);
        if (!empty($question['compulsory'])) {
            $compulsoryMarks += $marks;
        } else {
            $optionalMarks[] = $marks;
        }
    }

    rsort($optionalMarks, SORT_NUMERIC);
    $compulsoryCount = count(array_filter($questions, fn($q) => is_array($q) && !empty($q['compulsory'])));
    $optionalSlots = max(0, $limit - $compulsoryCount);
    return $compulsoryMarks + array_sum(array_slice($optionalMarks, 0, $optionalSlots));
}

function normalizeDateTimeInput($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    return $value;
}

function normalizeExamEndTime($startDatetime, $endDatetime, int $duration): ?string
{
    $start = normalizeDateTimeInput($startDatetime);
    $end = normalizeDateTimeInput($endDatetime);

    if (!$start) {
        return $end;
    }

    $startTs = strtotime($start);
    $endTs = $end ? strtotime($end) : false;
    $minimumEndTs = $startTs + (max(1, $duration) * 60);
    if (!$end || $endTs === false || $endTs < $minimumEndTs) {
        return date('Y-m-d H:i:s', $minimumEndTs);
    }

    return date('Y-m-d H:i:s', $endTs);
}

function normalizePublishedExamSchedule(?string $startDatetime, ?string $endDatetime, int $duration): array
{
    $startDatetime = normalizeDateTimeInput($startDatetime);
    $endDatetime = normalizeExamEndTime($startDatetime, $endDatetime, $duration);

    if (!$startDatetime) {
        return [$startDatetime, $endDatetime];
    }

    $startTs = strtotime($startDatetime);
    $endTs = $endDatetime ? strtotime($endDatetime) : false;
    $nowTs = time();

    if ($startTs !== false && ($endTs === false || $endTs <= $startTs)) {
        $endDatetime = date('Y-m-d H:i:s', $startTs + (max(1, $duration) * 60));
        $endTs = strtotime($endDatetime);
    }

    if ($startTs !== false && $endTs !== false && $endTs <= $nowTs) {
        $startDatetime = date('Y-m-d H:i:s', $nowTs + 60);
        $endDatetime = date('Y-m-d H:i:s', strtotime($startDatetime) + (max(1, $duration) * 60));
    }

    return [$startDatetime, $endDatetime];
}

function parseStudentCourseList(array $post): array
{
    $decoded = [];
    if (!empty($post['courses'])) {
        $raw = json_decode((string)$post['courses'], true);
        if (is_array($raw)) {
            $decoded = $raw;
        }
    }

    if (!$decoded) {
        $codes = preg_split('/[,;\n]+/', (string)($post['course_code'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $names = preg_split('/[,;\n]+/', (string)($post['course_name'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($codes as $index => $code) {
            $decoded[] = [
                'code' => trim($code),
                'name' => trim($names[$index] ?? ($names[0] ?? ''))
            ];
        }
    }

    $courses = [];
    $seen = [];
    foreach ($decoded as $course) {
        $code = trim((string)($course['code'] ?? $course['course_code'] ?? ''));
        $name = trim((string)($course['name'] ?? $course['course_name'] ?? ''));
        if ($code === '' || $name === '') continue;
        $key = strtolower($code);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $courses[] = ['code' => $code, 'name' => $name];
    }
    return $courses;
}

function lecturerUnlockCode(int $lecturerId): string
{
    return 'QODA-' . str_pad((string)$lecturerId, 5, '0', STR_PAD_LEFT);
}

function questionFileStem(string $questionText, int $questionNumber): string
{
    $words = preg_split('/[^A-Za-z0-9]+/', strtolower($questionText), -1, PREG_SPLIT_NO_EMPTY);
    $stop = ['write', 'program', 'that', 'the', 'and', 'with', 'from', 'user', 'their', 'your', 'this', 'question', 'accepts', 'prints', 'print', 'create', 'using'];
    $keywords = [];
    foreach ($words as $word) {
        if (strlen($word) < 3 || in_array($word, $stop, true)) continue;
        $keywords[] = $word;
        if (count($keywords) >= 4) break;
    }
    $stem = implode('_', $keywords);
    if ($stem === '') $stem = 'answer';
    return 'q' . $questionNumber . '_' . preg_replace('/[^a-z0-9_]/', '_', $stem);
}
// Modify all SELECT queries to filter by lecturer_id
// Debug: Uncomment to see session data (remove after testing)
// echo "<pre>SESSION: "; print_r($_SESSION); echo "</pre>";

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    // Not logged in, redirect to login
    header('Location: login.php');
    exit;
}

// Check if user has proper role (LECTURER only)
if ($_SESSION['user_role'] !== 'LECTURER') {
    // Wrong role, redirect to student dashboard or login
    header('Location: student_dashboard.php');
    exit;
}

// Get the logged-in lecturer's ID
$lecturerId = $_SESSION['user_id'];

// Now include database and config files
require_once __DIR__ . '/../backend-php/config/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../backend-php/helpers/grading.php';  // Add this line

// Get database connection
global $pdo;
$db = $pdo;

if (!function_exists('ensureLecturerColumn')) {
    function ensureLecturerColumn(PDO $pdo, string $table, string $column, string $definition): void
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
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS screen_captures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_id INT NOT NULL,
            student_id INT NOT NULL,
            image_path VARCHAR(255) NOT NULL DEFAULT '',
            image_data LONGTEXT NULL,
            capture_type VARCHAR(30) NOT NULL DEFAULT 'live',
            notes TEXT NULL,
            captured_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_exam_student (exam_id, student_id),
            INDEX idx_captured_at (captured_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    ensureLecturerColumn($pdo, 'exams', 'end_datetime', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exams', 'draft_saved_at', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exams', 'results_published', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureLecturerColumn($pdo, 'exams', 'results_published_at', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'screen_captures', 'image_data', 'LONGTEXT NULL');
    ensureLecturerColumn($pdo, 'screen_captures', 'capture_type', "VARCHAR(30) NOT NULL DEFAULT 'live'");
    ensureLecturerColumn($pdo, 'screen_captures', 'notes', 'TEXT NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'student_name', 'VARCHAR(255) NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'student_identifier', 'VARCHAR(100) NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'submitted', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureLecturerColumn($pdo, 'exam_submissions', 'submittedAt', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'graded_at', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'graded_by', 'INT NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'manual_feedback', 'TEXT NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'execution_results', 'JSON NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'ai_feedback', 'TEXT NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'auto_graded_at', 'DATETIME NULL');
    $pdo->exec("
        ALTER TABLE exam_submissions
        MODIFY status VARCHAR(50) DEFAULT 'in_progress'
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proctor_commands (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_id INT NOT NULL,
            student_id INT NOT NULL,
            command_type ENUM('warning', 'lock', 'unlock') NOT NULL,
            message TEXT NULL,
            handled TINYINT(1) DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            handled_at DATETIME NULL,
            INDEX idx_exam_student_handled (exam_id, student_id, handled),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        ALTER TABLE proctor_commands
        MODIFY command_type ENUM('warning', 'lock', 'unlock') NOT NULL
    ");
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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_grading_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            consistency_hash CHAR(32) NOT NULL UNIQUE,
            score DECIMAL(10,2) NOT NULL DEFAULT 0,
            feedback TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_consistency_hash (consistency_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('Lecturer dashboard schema upgrade failed: ' . $e->getMessage());
}
// ========== SUBMISSIONS TABLE CREATION (SIMPLIFIED) ==========
try {
    // Create submissions table if not exists
    $createSubmissionsTable = "
        CREATE TABLE IF NOT EXISTS exam_submissions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            exam_id INT NOT NULL,
            student_id INT NOT NULL,
            student_name VARCHAR(255),
            student_identifier VARCHAR(100),
            answers LONGTEXT,
            total_score DECIMAL(10,2) DEFAULT 0,
            percentage DECIMAL(5,2) DEFAULT 0,
            status VARCHAR(50) DEFAULT 'SUBMITTED',
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            INDEX idx_exam (exam_id),
            INDEX idx_student (student_id),
            FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($createSubmissionsTable);
} catch (Exception $e) {
    error_log("Error creating submissions table: " . $e->getMessage());
}

// ========== CREATE TEST SUBMISSION IF NONE EXISTS ==========
try {
    // Check if there are any submissions
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM exam_submissions");
    $countStmt->execute();
    $submissionCount = $countStmt->fetchColumn();

    if ($submissionCount == 0) {
        // Get first exam and student
        $examStmt = $pdo->prepare("SELECT id, title, questions, total_marks FROM exams WHERE lecturer_id = ? LIMIT 1");
        $examStmt->execute([$lecturerId]);
        $exam = $examStmt->fetch(PDO::FETCH_ASSOC);

        $studentStmt = $pdo->prepare("SELECT id, full_name, student_id FROM students WHERE lecturer_id = ? LIMIT 1");
        $studentStmt->execute([$lecturerId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

        if ($exam && $student) {
            // Create test answers
            $questions = json_decode($exam['questions'], true);
            $testAnswers = [];
            if (is_array($questions)) {
                foreach ($questions as $idx => $q) {
                    $testAnswers[$idx] = [
                        'question_id' => $q['id'] ?? $idx,
                        'answer' => "Sample answer for question " . ($idx + 1) . ": " . substr($q['text'] ?? 'No question text', 0, 100),
                        'code' => ''
                    ];
                }
            } else {
                $testAnswers = [
                    ['question_id' => 1, 'answer' => 'Test answer 1'],
                    ['question_id' => 2, 'answer' => 'Test answer 2']
                ];
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO exam_submissions (exam_id, student_id, student_name, student_identifier, answers, submitted_at, status, total_score, percentage)
                VALUES (?, ?, ?, ?, ?, NOW(), 'SUBMITTED', 0, 0)
            ");

            $insertStmt->execute([
                $exam['id'],
                $student['id'],
                $student['full_name'],
                $student['student_id'],
                json_encode($testAnswers)
            ]);

            error_log("✅ Test submission created for debugging");
        }
    }
} catch (Exception $e) {
    error_log("Error creating test submission: " . $e->getMessage());
}


// Get lecturer details from database
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'LECTURER'");
$stmt->execute([$_SESSION['user_id']]);
$lecturerData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lecturerData) {
    // User not found in database with correct role - logout
    session_destroy();
    header('Location: login.php');
    exit;
}

// ========== API ENDPOINTS ==========
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_POST['action']) {
            case 'get_dashboard_stats':
                $totalExams = $pdo->query("SELECT COUNT(*) FROM exams")->fetchColumn();
                $publishedExams = $pdo->query("SELECT COUNT(*) FROM exams WHERE published = 1")->fetchColumn();
                $totalSubmissions = $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
                $markedSubmissions = $pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'MARKED'")->fetchColumn();
                echo json_encode(['success' => true, 'data' => compact('totalExams', 'publishedExams', 'totalSubmissions', 'markedSubmissions')]);
                break;

            case 'get_exams':
                $stmt = $pdo->prepare("
        SELECT * FROM exams 
        WHERE created_by = ? OR lecturer_id = ?
        ORDER BY created_at DESC
    ");
                $stmt->execute([$lecturerId, $lecturerId]);
                $exams = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $exams]);
                break;

            case 'get_students':
                try {
                    // Get all students for this lecturer with their enrolled courses
                    $stmt = $pdo->prepare("
            SELECT DISTINCT 
                s.id, 
                s.student_id, 
                s.full_name, 
                s.level, 
                s.programme, 
                s.status, 
                s.created_at,
                s.lecturer_id
            FROM students s
            WHERE s.lecturer_id = ? OR s.lecturer_id IS NULL
            ORDER BY s.created_at DESC
        ");
                    $stmt->execute([$lecturerId]);
                    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // For each student, fetch their enrolled courses
                    foreach ($students as &$student) {
                        $courseStmt = $pdo->prepare("
                SELECT course_code, course_name, enrolled_at 
                FROM course_enrollments 
                WHERE student_id = ? AND lecturer_id = ?
                ORDER BY enrolled_at DESC
            ");
                        $courseStmt->execute([$student['id'], $lecturerId]);
                        $courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

                        if (!empty($courses)) {
                            // Store the first course as primary
                            $student['course_code'] = $courses[0]['course_code'];
                            $student['course_name'] = $courses[0]['course_name'];
                            // Store all courses as comma-separated for display
                            $student['enrolled_courses'] = implode(', ', array_column($courses, 'course_code'));
                            $student['enrolled_courses_names'] = implode(', ', array_column($courses, 'course_name'));
                            $student['courses'] = $courses;
                        } else {
                            $student['course_code'] = '—';
                            $student['course_name'] = '—';
                            $student['enrolled_courses'] = '—';
                            $student['enrolled_courses_names'] = '—';
                            $student['courses'] = [];
                        }
                    }

                    echo json_encode(['success' => true, 'data' => $students]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;



                // Add these cases inside the switch statement

case 'get_submission_for_ide':
    try {
        $submissionId = intval($_POST['submission_id'] ?? 0);
        
        $stmt = $pdo->prepare("
            SELECT es.*, s.full_name, s.student_id, e.questions
            FROM exam_submissions es
            JOIN students s ON es.student_id = s.id
            JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ?
        ");
        $stmt->execute([$submissionId]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($submission) {
            $questions = json_decode($submission['questions'], true);
            $answers = json_decode($submission['answers'], true);
            $scores = isset($answers['_scores']) ? $answers['_scores'] : [];
            
            echo json_encode([
                'success' => true,
                'student_name' => $submission['full_name'],
                'student_id' => $submission['student_id'],
                'questions' => $questions,
                'answers' => $answers,
                'scores' => $scores
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Submission not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    break;

case 'get_students_for_ide':
    try {
        $lecturerId = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT id, full_name, student_id FROM students WHERE lecturer_id = ? ORDER BY full_name");
        $stmt->execute([$lecturerId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $students]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    break;

    case 'get_submission_ide':
    try {
        $submissionId = intval($_POST['submission_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT es.*, s.full_name, s.student_id, e.questions
            FROM exam_submissions es
            JOIN students s ON es.student_id = s.id
            JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ?
        ");
        $stmt->execute([$submissionId]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($submission) {
            $questions = json_decode($submission['questions'], true);
            $answers = json_decode($submission['answers'], true);
            $scores = isset($answers['_scores']) ? $answers['_scores'] : [];
            
            echo json_encode([
                'success' => true,
                'student_name' => $submission['full_name'],
                'student_id' => $submission['student_id'],
                'questions' => $questions,
                'answers' => $answers,
                'scores' => $scores
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Submission not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    break;

case 'get_students_ide':
    try {
        $lecturerId = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT id, full_name, student_id FROM students WHERE lecturer_id = ? ORDER BY full_name");
        $stmt->execute([$lecturerId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $students]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    break;

// ========== COMPLETE AUTO-GRADER API ENDPOINTS ==========

case 'auto_grade_all_submissions':
    try {
        $examId = intval($_POST['exam_id'] ?? 0);
        $submissionsToGrade = isset($_POST['submission_id']) ? [intval($_POST['submission_id'])] : null;
        
        // Get all ungraded submissions or specific submission
        $sql = "SELECT es.*, e.questions, e.total_marks, e.auto_grading_enabled 
                FROM exam_submissions es
                JOIN exams e ON es.exam_id = e.id
                WHERE (es.status NOT IN ('AUTO_GRADED', 'GRADED') OR es.status IS NULL)";
        
        if ($examId > 0) $sql .= " AND es.exam_id = $examId";
        if ($submissionsToGrade) $sql .= " AND es.id IN (" . implode(',', $submissionsToGrade) . ")";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        foreach ($submissions as $submission) {
            $gradeResult = autoGradeSubmission($submission, $pdo);
            $results[] = $gradeResult;
            
            // Update submission with auto-grade
            $updateStmt = $pdo->prepare("
                UPDATE exam_submissions 
                SET total_score = ?, 
                    percentage = ?, 
                    status = 'AUTO_GRADED',
                    auto_graded_at = NOW(),
                    execution_results = ?,
                    ai_feedback = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $gradeResult['total_score'],
                $gradeResult['percentage'],
                json_encode($gradeResult['details']),
                $gradeResult['ai_feedback'],
                $submission['id']
            ]);
        }
        
        echo json_encode([
            'success' => true,
            'graded_count' => count($results),
            'results' => $results
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    break;


   

case 'finalize_grades':
    try {
        $submission_id = intval($_POST['submission_id'] ?? 0);
        $total_score = floatval($_POST['total_score'] ?? 0);
        $percentage = floatval($_POST['percentage'] ?? 0);
        $scores = json_decode($_POST['scores'] ?? '{}', true);
        
        $stmt = $pdo->prepare("SELECT answers FROM exam_submissions WHERE id = ?");
        $stmt->execute([$submission_id]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $answers = json_decode($submission['answers'], true);
        $answers['_scores'] = $scores;
        $answers['_finalized'] = true;
        $answers['_finalized_at'] = date('Y-m-d H:i:s');
        
        $stmt2 = $pdo->prepare("
            UPDATE exam_submissions 
            SET answers = ?, total_score = ?, percentage = ?, status = 'GRADED', graded_at = NOW()
            WHERE id = ?
        ");
        $stmt2->execute([json_encode($answers), $total_score, $percentage, $submission_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    break;

    
function autoGradeSubmission($submission, $pdo) {
    $questions = json_decode($submission['questions'], true);
    $answers = json_decode($submission['answers'], true);
    
    $totalEarned = 0;
    $totalPossible = 0;
    $details = [];
    $aiFeedback = [];
    
    foreach ($questions as $index => $question) {
        if ($question['type'] === 'code') {
            $studentCode = $answers[$index]['code'] ?? $answers[$index]['answer'] ?? '';
            $language = $question['language'] ?? 'python';
            $testCases = $question['testCases'] ?? [];
            $maxMarks = floatval($question['marks'] ?? 0);
            $totalPossible += $maxMarks;
            
            // Grade the code
            $gradeResult = gradeStudentCode($studentCode, $language, $testCases, $maxMarks);
            $totalEarned += $gradeResult['score'];
            
            $details[] = [
                'question_index' => $index,
                'question_id' => $question['id'],
                'score' => $gradeResult['score'],
                'max_marks' => $maxMarks,
                'test_results' => $gradeResult['test_results'],
                'execution_time' => $gradeResult['execution_time'] ?? 0
            ];
            
            $aiFeedback[] = $gradeResult['ai_feedback'];
        }
    }
    
    $percentage = $totalPossible > 0 ? round(($totalEarned / $totalPossible) * 100, 2) : 0;
    
    return [
        'submission_id' => $submission['id'],
        'student_name' => $submission['student_name'],
        'total_score' => $totalEarned,
        'total_possible' => $totalPossible,
        'percentage' => $percentage,
        'details' => $details,
        'ai_feedback' => implode("\n\n", $aiFeedback)
    ];
}

function gradeStudentCode($code, $language, $testCases, $maxMarks) {
    $tempDir = sys_get_temp_dir() . '/code_grade_' . uniqid();
    mkdir($tempDir, 0777, true);
    
    $totalScore = 0;
    $testResults = [];
    $startTime = microtime(true);
    
    foreach ($testCases as $index => $testCase) {
        $marks = floatval($testCase['marks'] ?? 0);
        $result = executeStudentCode($code, $language, $testCase['input'] ?? '', $tempDir);
        $expected = trim($testCase['expected'] ?? '');
        $actual = trim($result['output']);
        
        $passed = $result['success'] && ($expected === $actual);
        
        if ($passed) {
            $totalScore += $marks;
        }
        
        $testResults[] = [
            'test_case' => $index + 1,
            'input' => $testCase['input'] ?? '',
            'expected' => $expected,
            'actual' => $actual,
            'passed' => $passed,
            'marks' => $passed ? $marks : 0,
            'max_marks' => $marks,
            'error' => $result['error']
        ];
    }
    
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    
    // Generate AI feedback
    $aiFeedback = generateAICodeFeedback($code, $language, $testResults, $totalScore, $maxMarks);
    
    // Cleanup
    array_map('unlink', glob("$tempDir/*.*"));
    rmdir($tempDir);
    
    return [
        'score' => $totalScore,
        'test_results' => $testResults,
        'execution_time' => $executionTime,
        'ai_feedback' => $aiFeedback
    ];
}

function executeStudentCode($code, $language, $input, $tempDir) {
    $result = ['success' => false, 'output' => '', 'error' => null];
    
    // Add timeout to prevent infinite loops
    $timeout = 5; // 5 seconds timeout
    
    switch (strtolower($language)) {
        case 'python':
            $filePath = $tempDir . '/solution.py';
            file_put_contents($filePath, $code);
            $command = "cd $tempDir && timeout $timeout python3 $filePath 2>&1";
            if (!empty($input)) {
                $command = "echo " . escapeshellarg($input) . " | " . $command;
            }
            exec($command, $output, $returnCode);
            $result['output'] = implode("\n", $output);
            $result['success'] = ($returnCode === 0);
            if ($returnCode === 124) $result['error'] = "Time limit exceeded ({$timeout}s)";
            break;
            
        case 'javascript':
        case 'js':
            $filePath = $tempDir . '/solution.js';
            file_put_contents($filePath, $code);
            $command = "cd $tempDir && timeout $timeout node $filePath 2>&1";
            if (!empty($input)) {
                $command = "echo " . escapeshellarg($input) . " | " . $command;
            }
            exec($command, $output, $returnCode);
            $result['output'] = implode("\n", $output);
            $result['success'] = ($returnCode === 0);
            break;
            
        case 'java':
            preg_match('/public\s+class\s+(\w+)/', $code, $matches);
            $className = $matches[1] ?? 'Main';
            $filePath = $tempDir . '/' . $className . '.java';
            file_put_contents($filePath, $code);
            exec("cd $tempDir && javac $className.java 2>&1", $compileOutput, $compileCode);
            if ($compileCode === 0) {
                $command = "cd $tempDir && timeout $timeout java $className 2>&1";
                if (!empty($input)) {
                    $command = "echo " . escapeshellarg($input) . " | " . $command;
                }
                exec($command, $output, $returnCode);
                $result['output'] = implode("\n", $output);
                $result['success'] = ($returnCode === 0);
            } else {
                $result['error'] = "Compilation error: " . implode("\n", $compileOutput);
            }
            break;
            
        case 'c':
        case 'cpp':
            $ext = ($language === 'c') ? 'c' : 'cpp';
            $filePath = $tempDir . '/solution.' . $ext;
            $outputPath = $tempDir . '/solution';
            file_put_contents($filePath, $code);
            $compiler = ($language === 'c') ? 'gcc' : 'g++';
            exec("cd $tempDir && $compiler $filePath -o solution 2>&1", $compileOutput, $compileCode);
            if ($compileCode === 0) {
                $command = "cd $tempDir && timeout $timeout ./solution 2>&1";
                if (!empty($input)) {
                    $command = "echo " . escapeshellarg($input) . " | " . $command;
                }
                exec($command, $output, $returnCode);
                $result['output'] = implode("\n", $output);
                $result['success'] = ($returnCode === 0);
            } else {
                $result['error'] = "Compilation error: " . implode("\n", $compileOutput);
            }
            break;
            
        case 'php':
            $filePath = $tempDir . '/solution.php';
            file_put_contents($filePath, $code);
            $command = "cd $tempDir && timeout $timeout php $filePath 2>&1";
            if (!empty($input)) {
                $command = "echo " . escapeshellarg($input) . " | " . $command;
            }
            exec($command, $output, $returnCode);
            $result['output'] = implode("\n", $output);
            $result['success'] = ($returnCode === 0);
            break;
            
        case 'csharp':
        case 'c#':
            $filePath = $tempDir . '/solution.cs';
            file_put_contents($filePath, $code);
            exec("cd $tempDir && mcs solution.cs 2>&1", $compileOutput, $compileCode);
            if ($compileCode === 0) {
                $command = "cd $tempDir && timeout $timeout mono solution.exe 2>&1";
                if (!empty($input)) {
                    $command = "echo " . escapeshellarg($input) . " | " . $command;
                }
                exec($command, $output, $returnCode);
                $result['output'] = implode("\n", $output);
                $result['success'] = ($returnCode === 0);
            } else {
                $result['error'] = "Compilation error: " . implode("\n", $compileOutput);
            }
            break;
            
        case 'ruby':
            $filePath = $tempDir . '/solution.rb';
            file_put_contents($filePath, $code);
            $command = "cd $tempDir && timeout $timeout ruby $filePath 2>&1";
            if (!empty($input)) {
                $command = "echo " . escapeshellarg($input) . " | " . $command;
            }
            exec($command, $output, $returnCode);
            $result['output'] = implode("\n", $output);
            $result['success'] = ($returnCode === 0);
            break;
            
        case 'go':
            $filePath = $tempDir . '/solution.go';
            file_put_contents($filePath, $code);
            $command = "cd $tempDir && timeout $timeout go run $filePath 2>&1";
            if (!empty($input)) {
                $command = "echo " . escapeshellarg($input) . " | " . $command;
            }
            exec($command, $output, $returnCode);
            $result['output'] = implode("\n", $output);
            $result['success'] = ($returnCode === 0);
            break;
            
        default:
            $result['error'] = "Language '$language' is not supported yet";
    }
    
    return $result;
}

function generateAICodeFeedback($code, $language, $testResults, $score, $maxMarks) {
    $passedTests = count(array_filter($testResults, function($t) { return $t['passed']; }));
    $totalTests = count($testResults);
    $percentage = $maxMarks > 0 ? ($score / $maxMarks) * 100 : 0;
    
    $feedback = "=== AI GRADING REPORT ===\n\n";
    $feedback .= "📊 Score: $score / $maxMarks marks ($percentage%)\n";
    $feedback .= "✅ Tests Passed: $passedTests / $totalTests\n\n";
    
    $feedback .= "📝 DETAILED TEST RESULTS:\n";
    foreach ($testResults as $result) {
        $icon = $result['passed'] ? '✓' : '✗';
        $feedback .= "  $icon Test {$result['test_case']}: ";
        if ($result['passed']) {
            $feedback .= "Passed (+{$result['marks']} marks)\n";
        } else {
            $feedback .= "Failed\n";
            $feedback .= "     Input: {$result['input']}\n";
            $feedback .= "     Expected: {$result['expected']}\n";
            $feedback .= "     Got: {$result['actual']}\n";
            if ($result['error']) $feedback .= "     Error: {$result['error']}\n";
        }
    }
    
    $feedback .= "\n💡 AI SUGGESTIONS FOR IMPROVEMENT:\n";
    
    // Code quality analysis
    if (strlen($code) < 50 && $maxMarks > 5) {
        $feedback .= "  - Your solution is too brief. Consider adding more logic to meet requirements.\n";
    }
    
    if (!preg_match('/function\s+\w+\s*\(|def\s+\w+\s*\(|public\s+\w+\s+\w+\s*\(/', $code)) {
        $feedback .= "  - Wrap your solution in a function for better structure.\n";
    }
    
    if (strpos($code, '//') === false && strpos($code, '#') === false && strpos($code, '/*') === false) {
        $feedback .= "  - Add comments to explain your logic.\n";
    }
    
    // Check for proper return statements
    if (strpos($code, 'return') === false && $language !== 'bash') {
        $feedback .= "  - Ensure your function returns the expected output.\n";
    }
    
    // Specific language suggestions
    if ($language === 'python') {
        if (strpos($code, 'def') === false) $feedback .= "  - Use 'def' to define a function.\n";
        if (strpos($code, 'print') !== false) $feedback .= "  - Use 'return' instead of 'print' for function output.\n";
    }
    
    if ($language === 'javascript' || $language === 'js') {
        if (strpos($code, 'function') === false && strpos($code, '=>') === false) {
            $feedback .= "  - Define a function using 'function' or arrow syntax.\n";
        }
        if (strpos($code, 'console.log') !== false) $feedback .= "  - Use 'return' instead of 'console.log' for output.\n";
    }
    
    if ($passedTests < $totalTests && $passedTests > 0) {
        $feedback .= "  - You passed some test cases! Review the failed ones to see what's missing.\n";
    } else if ($passedTests === 0 && $totalTests > 0) {
        $feedback .= "  - No test cases passed. Check your logic and syntax carefully.\n";
    }
    
    if ($percentage >= 80) {
        $feedback .= "\n🎉 EXCELLENT WORK! Your solution is correct and well-structured.\n";
    } else if ($percentage >= 60) {
        $feedback .= "\n👍 GOOD EFFORT! A few improvements needed for full marks.\n";
    } else if ($percentage >= 40) {
        $feedback .= "\n📚 KEEP PRACTICING! Review the suggestions and try again.\n";
    } else {
        $feedback .= "\n⚠️ NEEDS WORK! Review the problem statement and try a different approach.\n";
    }
    
    return $feedback;
}

            case 'update_exam_visibility':
                try {
                    $studentId = $_POST['student_id'];
                    $examId = $_POST['exam_id'];
                    $visible = $_POST['visible'];

                    // Check if record exists
                    $checkStmt = $pdo->prepare("SELECT id FROM exam_visibility WHERE exam_id = ? AND student_id = ?");
                    $checkStmt->execute([$examId, $studentId]);

                    if ($checkStmt->fetch()) {
                        $stmt = $pdo->prepare("UPDATE exam_visibility SET visible = ?, updated_at = NOW() WHERE exam_id = ? AND student_id = ?");
                        $stmt->execute([$visible, $examId, $studentId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO exam_visibility (exam_id, student_id, visible, created_at) VALUES (?, ?, ?, NOW())");
                        $stmt->execute([$examId, $studentId, $visible]);
                    }

                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_exam_visibility':
                try {
                    $examId = $_POST['exam_id'];
                    $stmt = $pdo->prepare("SELECT student_id, visible FROM exam_visibility WHERE exam_id = ?");
                    $stmt->execute([$examId]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $visibility = [];
                    foreach ($results as $row) {
                        $visibility[$row['student_id']] = $row['visible'];
                    }

                    echo json_encode(['success' => true, 'data' => $visibility]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_active_sessions':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $stmt = $pdo->prepare("
                        SELECT
                            active.student_id,
                            MAX(COALESCE(sc.captured_at, es.updated_at, es.started_at, es.created_at)) AS last_activity,
                            CASE WHEN MAX(CASE WHEN sc.capture_type = 'live' THEN sc.captured_at ELSE NULL END) >= (NOW() - INTERVAL 12 SECOND) THEN 1 ELSE 0 END AS screen_sharing_active,
                            COUNT(DISTINCT sl.id) AS violations,
                            MAX(CASE WHEN pc.command_type = 'lock' THEN 1 WHEN pc.command_type = 'unlock' THEN 0 ELSE 0 END) AS screen_locked,
                            MAX(COALESCE(es.submitted, 0)) AS submitted,
                            MAX(es.submitted_at) AS submitted_at,
                            MAX(es.submittedAt) AS submittedAt,
                            (
                                SELECT sc3.image_data
                                FROM screen_captures sc3
                                WHERE sc3.exam_id = ?
                                  AND sc3.student_id = active.student_id
                                  AND sc3.capture_type = 'live'
                                ORDER BY sc3.id DESC
                                LIMIT 1
                            ) AS snapshot,
                            (
                                SELECT sc5.id
                                FROM screen_captures sc5
                                WHERE sc5.exam_id = ?
                                  AND sc5.student_id = active.student_id
                                  AND sc5.capture_type = 'live'
                                ORDER BY sc5.id DESC
                                LIMIT 1
                            ) AS snapshot_id,
                            (
                                SELECT sc4.captured_at
                                FROM screen_captures sc4
                                WHERE sc4.exam_id = ?
                                  AND sc4.student_id = active.student_id
                                  AND sc4.capture_type = 'live'
                                ORDER BY sc4.id DESC
                                LIMIT 1
                            ) AS latest_snapshot_at,
                            SUBSTRING_INDEX(GROUP_CONCAT(es.status ORDER BY es.id DESC SEPARATOR '||'), '||', 1) AS submission_status,
                            MAX(CASE
                                WHEN COALESCE(es.submitted, 0) = 1
                                  OR es.submitted_at IS NOT NULL
                                  OR es.submittedAt IS NOT NULL
                                  OR UPPER(COALESCE(es.status, '')) IN ('SUBMITTED', 'TIMED_OUT', 'GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED')
                                THEN 1 ELSE 0
                            END) AS is_submitted
                        FROM (
                            SELECT student_id FROM exam_submissions WHERE exam_id = ?
                            UNION
                            SELECT student_id FROM screen_captures WHERE exam_id = ?
                        ) active
                        LEFT JOIN exam_submissions es ON es.exam_id = ? AND es.student_id = active.student_id
                        LEFT JOIN screen_captures sc ON sc.exam_id = ? AND sc.student_id = active.student_id
                        LEFT JOIN suspicious_logs sl ON sl.exam_id = ? AND sl.student_id = active.student_id
                        LEFT JOIN proctor_commands pc ON pc.id = (
                            SELECT pc2.id
                            FROM proctor_commands pc2
                            WHERE pc2.exam_id = ?
                              AND pc2.student_id = active.student_id
                              AND pc2.command_type IN ('lock', 'unlock')
                            ORDER BY pc2.id DESC
                            LIMIT 1
                        )
                        GROUP BY active.student_id
                    ");
                    $stmt->execute([$examId, $examId, $examId, $examId, $examId, $examId, $examId, $examId, $examId]);
                    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_screen_updates':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $stmt = $pdo->prepare("
                        SELECT active.student_id,
                               sc.id AS snapshot_id,
                               sc.image_data AS snapshot,
                               sc.captured_at,
                               COUNT(DISTINCT sl.id) AS violations,
                               MAX(COALESCE(es.submitted, 0)) AS submitted,
                               MAX(es.submitted_at) AS submitted_at,
                               MAX(es.submittedAt) AS submittedAt,
                               SUBSTRING_INDEX(GROUP_CONCAT(es.status ORDER BY es.id DESC SEPARATOR '||'), '||', 1) AS submission_status,
                               MAX(CASE
                                   WHEN COALESCE(es.submitted, 0) = 1
                                     OR es.submitted_at IS NOT NULL
                                     OR es.submittedAt IS NOT NULL
                                     OR UPPER(COALESCE(es.status, '')) IN ('SUBMITTED', 'TIMED_OUT', 'GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED')
                                   THEN 1 ELSE 0
                               END) AS is_submitted
                        FROM (
                            SELECT student_id FROM screen_captures WHERE exam_id = ?
                            UNION
                            SELECT student_id FROM exam_submissions WHERE exam_id = ?
                        ) active
                        LEFT JOIN screen_captures sc ON sc.id = (
                            SELECT sc2.id
                            FROM screen_captures sc2
                            WHERE sc2.exam_id = ?
                              AND sc2.student_id = active.student_id
                              AND sc2.capture_type = 'live'
                            ORDER BY sc2.id DESC
                            LIMIT 1
                        )
                        LEFT JOIN exam_submissions es ON es.exam_id = ? AND es.student_id = active.student_id
                        LEFT JOIN suspicious_logs sl ON sl.exam_id = ? AND sl.student_id = active.student_id
                        GROUP BY active.student_id, sc.id, sc.image_data, sc.captured_at
                    ");
                    $stmt->execute([$examId, $examId, $examId, $examId, $examId]);
                    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_student_screen':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $stmt = $pdo->prepare("
                        SELECT sc.student_id, sc.image_data AS snapshot, sc.captured_at,
                               (SELECT COUNT(*) FROM suspicious_logs sl WHERE sl.exam_id = sc.exam_id AND sl.student_id = sc.student_id) AS violations
                        FROM screen_captures sc
                        WHERE sc.exam_id = ? AND sc.student_id = ?
                        ORDER BY sc.id DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$examId, $studentId]);
                    echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_violation_evidence':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $stmt = $pdo->prepare("
                        SELECT sl.id, sl.student_id, s.student_id AS student_identifier, s.full_name,
                               sl.event_type, sl.details, sl.severity, sl.created_at,
                               (
                                   SELECT sc.image_data
                                   FROM screen_captures sc
                                   WHERE sc.exam_id = sl.exam_id
                                     AND sc.student_id = sl.student_id
                                     AND sc.captured_at <= sl.created_at
                                   ORDER BY sc.captured_at DESC
                                   LIMIT 1
                               ) AS snapshot
                        FROM suspicious_logs sl
                        LEFT JOIN students s ON s.id = sl.student_id
                        WHERE sl.exam_id = ?
                        ORDER BY sl.created_at DESC
                        LIMIT 80
                    ");
                    $stmt->execute([$examId]);
                    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'send_warning_to_student':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $message = trim($_POST['warning'] ?? 'Please follow the exam rules.');
                    $stmt = $pdo->prepare("INSERT INTO proctor_commands (exam_id, student_id, command_type, message, created_by) VALUES (?, ?, 'warning', ?, ?)");
                    $stmt->execute([$examId, $studentId, $message, $lecturerId]);
                    $log = $pdo->prepare("INSERT INTO suspicious_logs (student_id, exam_id, event_type, details, severity) VALUES (?, ?, 'LECTURER_WARNING', ?, 'medium')");
                    $log->execute([$studentId, $examId, $message]);
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'lock_student_screen':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $stmt = $pdo->prepare("INSERT INTO proctor_commands (exam_id, student_id, command_type, message, created_by) VALUES (?, ?, 'lock', 'Your exam screen has been locked by the lecturer.', ?)");
                    $stmt->execute([$examId, $studentId, $lecturerId]);
                    $log = $pdo->prepare("INSERT INTO suspicious_logs (student_id, exam_id, event_type, details, severity) VALUES (?, ?, 'SCREEN_LOCKED_BY_LECTURER', 'Screen locked from proctoring dashboard', 'high')");
                    $log->execute([$studentId, $examId]);
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'unlock_student_screen':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $message = 'Your screen has been unlocked by the lecturer. Continue your exam.';
                    $stmt = $pdo->prepare("INSERT INTO proctor_commands (exam_id, student_id, command_type, message, created_by) VALUES (?, ?, 'unlock', ?, ?)");
                    $stmt->execute([$examId, $studentId, $message, $lecturerId]);
                    $log = $pdo->prepare("INSERT INTO suspicious_logs (student_id, exam_id, event_type, details, severity) VALUES (?, ?, 'SCREEN_UNLOCKED_BY_LECTURER', 'Screen unlocked from proctoring dashboard', 'low')");
                    $log->execute([$studentId, $examId]);
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'unlock_all_screens':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT es.student_id
                        FROM exam_submissions es
                        WHERE es.exam_id = ?
                    ");
                    $stmt->execute([$examId]);
                    $students = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $ins = $pdo->prepare("INSERT INTO proctor_commands (exam_id, student_id, command_type, message, created_by) VALUES (?, ?, 'unlock', ?, ?)");
                    foreach ($students as $studentId) {
                        $ins->execute([$examId, $studentId, 'Your screen has been unlocked by the lecturer. Continue your exam.', $lecturerId]);
                    }
                    echo json_encode(['success' => true, 'count' => count($students)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'take_snapshot':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $stmt = $pdo->prepare("SELECT image_data FROM screen_captures WHERE exam_id = ? AND student_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$examId, $studentId]);
                    $snapshot = $stmt->fetchColumn();
                    if (!$snapshot) {
                        echo json_encode(['success' => false, 'error' => 'No screen snapshot available']);
                        break;
                    }
                    $ins = $pdo->prepare("INSERT INTO screen_captures (exam_id, student_id, image_path, image_data, capture_type, notes, captured_at) VALUES (?, ?, '', ?, 'evidence', 'Saved by lecturer from proctoring dashboard', NOW())");
                    $ins->execute([$examId, $studentId, $snapshot]);
                    $log = $pdo->prepare("INSERT INTO suspicious_logs (student_id, exam_id, event_type, details, severity) VALUES (?, ?, 'SCREENSHOT_EVIDENCE', 'Lecturer saved screenshot evidence', 'high')");
                    $log->execute([$studentId, $examId]);
                    echo json_encode(['success' => true, 'data' => ['snapshot' => $snapshot]]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_submissions':
                try {
                    $stmt = $pdo->prepare("
            SELECT 
                es.*,
                s.full_name as student_name,
                s.student_id as student_identifier,
                e.title as exam_title,
                e.course_code
            FROM exam_submissions es
            LEFT JOIN students s ON es.student_id = s.id
            LEFT JOIN exams e ON es.exam_id = e.id
            WHERE e.lecturer_id = ? OR e.created_by = ? OR e.lecturer_id IS NULL
            ORDER BY es.submitted_at DESC
        ");
                    $stmt->execute([$lecturerId, $lecturerId]);
                    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode(['success' => true, 'data' => $submissions]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'create_test_submission':
                try {
                    // Get first exam
                    $examStmt = $pdo->prepare("SELECT id, title FROM exams WHERE lecturer_id = ? LIMIT 1");
                    $examStmt->execute([$lecturerId]);
                    $exam = $examStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$exam) {
                        echo json_encode(['success' => false, 'error' => 'No exam found. Please create an exam first.']);
                        break;
                    }

                    // Get first student
                    $studentStmt = $pdo->prepare("SELECT id, full_name, student_id FROM students WHERE lecturer_id = ? LIMIT 1");
                    $studentStmt->execute([$lecturerId]);
                    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$student) {
                        echo json_encode(['success' => false, 'error' => 'No student found. Please add a student first.']);
                        break;
                    }

                    // Create test answers
                    $testAnswers = json_encode([
                        ['question' => 1, 'answer' => 'This is a test answer for question 1.'],
                        ['question' => 2, 'answer' => 'This is a test answer for question 2.'],
                        ['question' => 3, 'answer' => 'This is a test answer for question 3.']
                    ]);

                    $insertStmt = $pdo->prepare("
            INSERT INTO exam_submissions (exam_id, student_id, answers, submitted_at, status)
            VALUES (?, ?, ?, NOW(), 'SUBMITTED')
        ");

                    $insertStmt->execute([
                        $exam['id'],
                        $student['id'],
                        $testAnswers
                    ]);

                    echo json_encode(['success' => true, 'message' => 'Test submission created successfully']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_submission_details':
                try {
                    $submissionId = $_POST['submission_id'] ?? 0;

                    $stmt = $pdo->prepare("
            SELECT 
                es.*,
                s.full_name as student_name,
                s.student_id as student_identifier,
                e.title as exam_title,
                e.course_code
            FROM exam_submissions es
            LEFT JOIN students s ON es.student_id = s.id
            LEFT JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ?
        ");
                    $stmt->execute([$submissionId]);
                    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($submission) {
                        echo json_encode(['success' => true, 'data' => $submission]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Submission not found']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'download_submission':
                try {
                    $submissionId = intval($_POST['submission_id'] ?? 0);

                    $stmt = $pdo->prepare("
            SELECT es.*, s.full_name, s.student_id, e.title as exam_title
            FROM exam_submissions es
            LEFT JOIN students s ON es.student_id = s.id
            LEFT JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ?
        ");
                    $stmt->execute([$submissionId]);
                    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$submission) {
                        echo "Submission not found";
                        exit;
                    }

                    $answers = json_decode($submission['answers'], true);

                    $content = "========================================\n";
                    $content .= "EXAM SUBMISSION DETAILS\n";
                    $content .= "========================================\n\n";
                    $content .= "Student: " . ($submission['full_name'] ?? 'Unknown') . "\n";
                    $content .= "Student ID: " . ($submission['student_id'] ?? 'N/A') . "\n";
                    $content .= "Exam: " . ($submission['exam_title'] ?? 'Unknown') . "\n";
                    $content .= "Submitted: " . ($submission['submitted_at'] ?? 'Unknown') . "\n";
                    $content .= "Status: " . ($submission['status'] ?? 'SUBMITTED') . "\n";
                    $content .= "========================================\n\n";
                    $content .= "ANSWERS:\n";
                    $content .= "========================================\n\n";

                    if (is_array($answers)) {
                        foreach ($answers as $idx => $answer) {
                            $content .= "Question " . ($idx + 1) . ":\n";
                            if (is_array($answer)) {
                                foreach ($answer as $key => $val) {
                                    $content .= "  " . ucfirst($key) . ": " . $val . "\n";
                                }
                            } else {
                                $content .= "  Answer: " . $answer . "\n";
                            }
                            $content .= "\n";
                        }
                    } else {
                        $content .= "No answers available\n";
                    }

                    header('Content-Type: text/plain');
                    header('Content-Disposition: attachment; filename="submission_' . ($submission['student_id'] ?? 'unknown') . '_' . date('Y-m-d') . '.txt"');
                    echo $content;
                    exit;
                } catch (Exception $e) {
                    echo "Error: " . $e->getMessage();
                    exit;
                }
                break;

            case 'update_submission_grade':
                try {
                    $submissionId = intval($_POST['submission_id'] ?? 0);
                    $status = $_POST['status'] ?? 'GRADED';
                    // Round to nearest whole number
                    $totalScore = roundToInt($_POST['total_score'] ?? 0);
                    $classScore = roundToInt($_POST['class_score'] ?? 0);
                    $examScore = roundToInt($_POST['exam_score'] ?? 0);
                    $grade = $_POST['grade'] ?? '';
                    $gradePoint = round(floatval($_POST['grade_point'] ?? 0), 1);

                    $stmt = $pdo->prepare("
            UPDATE exam_submissions 
            SET status = ?, 
                total_score = ?,
                percentage = ?,
                graded_at = NOW()
            WHERE id = ?
        ");

                    // Store class score and exam score in answers JSON
                    $getStmt = $pdo->prepare("SELECT answers FROM exam_submissions WHERE id = ?");
                    $getStmt->execute([$submissionId]);
                    $submission = $getStmt->fetch(PDO::FETCH_ASSOC);

                    $answers = json_decode($submission['answers'], true) ?? [];
                    $answers['grading'] = [
                        'class_score' => $classScore,
                        'exam_score' => $examScore,
                        'total_score' => $totalScore,
                        'grade' => $grade,
                        'grade_point' => $gradePoint,
                        'graded_at' => date('Y-m-d H:i:s')
                    ];

                    $updateStmt = $pdo->prepare("
            UPDATE exam_submissions 
            SET status = ?, 
                total_score = ?,
                percentage = ?,
                answers = ?,
                graded_at = NOW()
            WHERE id = ?
        ");

                    $updateStmt->execute([
                        $status,
                        $totalScore,
                        roundToInt(($totalScore / 100) * 100),
                        json_encode($answers),
                        $submissionId
                    ]);

                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;






            case 'get_submission_questions':
                try {
                    $submissionId = intval($_POST['submission_id'] ?? 0);

                    $stmt = $pdo->prepare("
            SELECT 
                es.*,
                s.full_name as student_name,
                s.student_id as student_identifier,
                e.title as exam_title,
                e.questions,
                e.course_code
            FROM exam_submissions es
            JOIN students s ON es.student_id = s.id
            JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ?
        ");
                    $stmt->execute([$submissionId]);
                    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($submission) {
                        // Parse questions and answers
                        $questions = json_decode($submission['questions'], true);
                        $answers = json_decode($submission['answers'], true);

                        // Combine questions with answers
                        $questionList = [];
                        if (is_array($questions)) {
                            foreach ($questions as $index => $question) {
                                $questionList[] = [
                                    'number' => $index + 1,
                                    'text' => $question['text'] ?? 'No question text',
                                    'marks' => $question['marks'] ?? 0,
                                    'language' => $question['language'] ?? 'text',
                                    'expectedOutput' => $question['expectedOutput'] ?? '',
                                    'answer' => $answers[$index]['code'] ?? $answers[$index]['answer'] ?? 'No answer provided',
                                    'savedScore' => $submission['question_scores'][$index] ?? 0
                                ];
                            }
                        }

                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'submission_id' => $submission['id'],
                                'student_name' => $submission['student_name'],
                                'student_id' => $submission['student_identifier'],
                                'exam_title' => $submission['exam_title'],
                                'submitted_at' => $submission['submitted_at'],
                                'total_marks' => $submission['total_score'] ?? 0,
                                'questions' => $questionList
                            ]
                        ]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Submission not found']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            

            case 'download_submission_zip':
                try {
                    $submissionId = intval($_POST['submission_id'] ?? 0);

                    // Get submission details with exam questions
                    $stmt = $pdo->prepare("
            SELECT 
                es.*,
                s.full_name,
                s.student_id,
                e.title as exam_title,
                e.questions,
                e.course_code
            FROM exam_submissions es
            JOIN students s ON es.student_id = s.id
            JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ?
        ");
                    $stmt->execute([$submissionId]);
                    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$submission) {
                        echo "Submission not found";
                        exit;
                    }

                    // Create temp directory - FIX: Convert all to string properly
                    $tempBase = sys_get_temp_dir();
                    $tempDir = $tempBase . DIRECTORY_SEPARATOR . 'submission_' . (string)$submissionId . '_' . (string)time();

                    if (!file_exists($tempDir)) {
                        mkdir($tempDir, 0777, true);
                    }

                    // Create student folder - FIX: Convert EVERYTHING to string before concatenation
                    $studentId = isset($submission['student_id']) ? (string)$submission['student_id'] : 'unknown';
                    $fullName = isset($submission['full_name']) ? (string)$submission['full_name'] : 'Unknown';
                    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $fullName);
                    $studentFolderName = $studentId . '_' . $safeName;
                    $studentFolder = $tempDir . DIRECTORY_SEPARATOR . $studentFolderName;

                    if (!file_exists($studentFolder)) {
                        mkdir($studentFolder, 0777, true);
                    }

                    // Parse answers and questions
                    $answers = json_decode($submission['answers'] ?? '[]', true);
                    $questions = json_decode($submission['questions'] ?? '[]', true);

                    // Create INFO file with submission details
                    $infoContent = "========================================\n";
                    $infoContent .= "SUBMISSION INFORMATION\n";
                    $infoContent .= "========================================\n\n";
                    $infoContent .= "Student ID: " . ($submission['student_id'] ?? 'N/A') . "\n";
                    $infoContent .= "Student Name: " . ($submission['full_name'] ?? 'Unknown') . "\n";
                    $infoContent .= "Exam Title: " . ($submission['exam_title'] ?? 'Unknown') . "\n";
                    $infoContent .= "Course Code: " . ($submission['course_code'] ?? 'N/A') . "\n";
                    $infoContent .= "Submitted: " . ($submission['submitted_at'] ?? 'Unknown') . "\n";
                    $infoContent .= "IP Address: " . ($submission['ip_address'] ?? 'N/A') . "\n";
                    $infoContent .= "Status: " . ($submission['status'] ?? 'SUBMITTED') . "\n";
                    $infoContent .= "========================================\n\n";

                    file_put_contents($studentFolder . DIRECTORY_SEPARATOR . 'README.txt', $infoContent);

                    // Create a folder for each question
                    if (is_array($answers) && !empty($answers)) {
                        $counter = 0;
                        foreach ($answers as $index => $answer) {
                            $counter++;
                            $questionNumber = $counter;
                            $questionFolder = $studentFolder . DIRECTORY_SEPARATOR . 'Question_' . (string)$questionNumber;

                            if (!file_exists($questionFolder)) {
                                mkdir($questionFolder, 0777, true);
                            }

                            // Get question details if available
                            $questionText = '';
                            $expectedLanguage = 'txt';
                            if (is_array($questions) && isset($questions[$index])) {
                                $q = $questions[$index];
                                $questionText = isset($q['text']) ? (string)$q['text'] : 'No question text provided';
                                $expectedLanguage = isset($q['language']) ? (string)$q['language'] : 'txt';
                            }

                            // Save question text
                            file_put_contents($questionFolder . DIRECTORY_SEPARATOR . 'question.txt', $questionText);

                            // Determine file extension based on language
                            $extension = 'txt';
                            $languageLower = strtolower((string)$expectedLanguage);

                            // Map languages to file extensions
                            $extensions = [
                                'python' => 'py',
                                'java' => 'java',
                                'javascript' => 'js',
                                'html' => 'html',
                                'css' => 'css',
                                'php' => 'php',
                                'c' => 'c',
                                'cpp' => 'cpp',
                                'c++' => 'cpp',
                                'csharp' => 'cs',
                                'c#' => 'cs',
                                'ruby' => 'rb',
                                'go' => 'go',
                                'rust' => 'rs',
                                'swift' => 'swift',
                                'kotlin' => 'kt',
                                'sql' => 'sql',
                                'bash' => 'sh',
                                'shell' => 'sh',
                                'typescript' => 'ts'
                            ];

                            if (isset($extensions[$languageLower])) {
                                $extension = $extensions[$languageLower];
                            }

                            // Extract the answer code - FIX: Convert answer to string properly
                            $codeContent = '';
                            if (is_array($answer)) {
                                if (isset($answer['code'])) {
                                    $codeContent = (string)$answer['code'];
                                } elseif (isset($answer['answer'])) {
                                    $codeContent = (string)$answer['answer'];
                                } else {
                                    $codeContent = json_encode($answer, JSON_PRETTY_PRINT);
                                }
                            } else {
                                $codeContent = (string)$answer;
                            }

                            // Save the solution file with proper extension
                            $answerStem = questionFileStem($questionText, (int)$questionNumber);
                            $solutionFile = $questionFolder . DIRECTORY_SEPARATOR . $answerStem . '.' . $extension;
                            file_put_contents($solutionFile, $codeContent);

                            // Also save as .txt for easy viewing
                            file_put_contents($questionFolder . DIRECTORY_SEPARATOR . 'answer.txt', $codeContent);

                        }
                    }

                    // Create ZIP file
                    $zipFile = $tempDir . '.zip';
                    $zip = new ZipArchive();
                    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                        $files = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($tempDir),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );

                        foreach ($files as $file) {
                            if (!$file->isDir()) {
                                $filePath = $file->getRealPath();
                                $relativePath = substr($filePath, strlen($tempDir) + 1);
                                $zip->addFile($filePath, $relativePath);
                            }
                        }
                        $zip->close();
                    }

                    // Output ZIP file for download
                    $zipFileName = 'submission_' . ($submission['student_id'] ?? 'unknown') . '_' . date('Y-m-d_H-i-s') . '.zip';

                    // Clear any output buffers
                    if (ob_get_level()) {
                        ob_end_clean();
                    }

                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
                    header('Content-Length: ' . filesize($zipFile));
                    header('Cache-Control: no-cache, must-revalidate');

                    readfile($zipFile);

                    // Cleanup - delete temp directory and files
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );

                    foreach ($files as $fileinfo) {
                        if ($fileinfo->isDir()) {
                            rmdir($fileinfo->getRealPath());
                        } else {
                            unlink($fileinfo->getRealPath());
                        }
                    }
                    if (is_dir($tempDir)) {
                        rmdir($tempDir);
                    }
                    if (file_exists($zipFile)) {
                        unlink($zipFile);
                    }

                    exit;
                } catch (Exception $e) {
                    error_log("Download ZIP error: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    echo "Error: " . $e->getMessage();
                    exit;
                }
                break;






            case 'get_exam_details':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);

                    $stmt = $pdo->prepare("
            SELECT e.*, 
                   s.full_name as lecturer_name,
                   s.staff_id as lecturer_staff_id
            FROM exams e
            LEFT JOIN users s ON e.lecturer_id = s.id
            WHERE e.id = ?
        ");
                    $stmt->execute([$examId]);
                    $exam = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($exam) {
                        // Parse questions if needed
                        if ($exam['questions']) {
                            $exam['questions'] = json_decode($exam['questions'], true);
                        }
                        echo json_encode(['success' => true, 'data' => $exam]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Exam not found']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'update_submission_scores':
                try {
                    $submissionId = intval($_POST['submission_id'] ?? 0);
                    $totalScore = floatval($_POST['total_score'] ?? 0);
                    $percentage = floatval($_POST['percentage'] ?? 0);

                    // Get current submission to update answers
                    $stmt = $pdo->prepare("SELECT answers, student_identifier FROM exam_submissions WHERE id = ?");
                    $stmt->execute([$submissionId]);
                    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($submission) {
                        $answers = json_decode($submission['answers'], true) ?? [];
                        $answers['_grading'] = [
                            'total_score' => $totalScore,
                            'percentage' => $percentage,
                            'graded_at' => date('Y-m-d H:i:s'),
                            'graded_by' => $lecturerId
                        ];

                        $updateStmt = $pdo->prepare("
                UPDATE exam_submissions 
                SET total_score = ?,
                    percentage = ?,
                    answers = ?,
                    status = 'AUTO_GRADED'
                WHERE id = ?
            ");

                        $updateStmt->execute([
                            $totalScore,
                            $percentage,
                            json_encode($answers),
                            $submissionId
                        ]);

                        echo json_encode(['success' => true, 'message' => 'Scores saved successfully']);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Submission not found']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_submission_for_review':
                try {
                    $submissionId = intval($_POST['submission_id'] ?? 0);

                    $stmt = $pdo->prepare("
            SELECT es.*, s.full_name, s.student_id, e.title, e.questions, e.total_marks
            FROM exam_submissions es
            JOIN students s ON es.student_id = s.id
            JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ?
        ");
                    $stmt->execute([$submissionId]);
                    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

                    echo json_encode(['success' => true, 'data' => $submission]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'save_manual_review':
                try {
                    $submissionId = intval($_POST['submission_id'] ?? 0);
                    $scores = json_decode($_POST['scores'] ?? '{}', true);
                    $feedback = $_POST['feedback'] ?? '';
                    $totalScore = floatval($_POST['total_score'] ?? 0);

                    $stmt = $pdo->prepare("
            UPDATE exam_submissions 
            SET total_score = ?, 
                percentage = (? / (SELECT total_marks FROM exams WHERE id = exam_id)) * 100,
                status = 'MANUALLY_GRADED',
                manual_feedback = ?,
                graded_at = NOW(),
                graded_by = ?
            WHERE id = ?
        ");
                    $stmt->execute([$totalScore, $totalScore, $feedback, $lecturerId, $submissionId]);

                    // Save individual question scores
                    foreach ($scores as $questionId => $score) {
                        $stmt2 = $pdo->prepare("
                INSERT INTO submission_question_scores (submission_id, question_id, score, feedback)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE score = ?, feedback = ?
            ");
                        $stmt2->execute([$submissionId, $questionId, $score, $feedback[$questionId] ?? '', $score, $feedback[$questionId] ?? '']);
                    }

                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

                function detectLanguageExtension($code)
                {
                    if (strpos($code, '<?php') !== false) return 'php';
                    if (strpos($code, 'def ') !== false || strpos($code, 'import ') !== false) return 'py';
                    if (strpos($code, 'function') !== false && strpos($code, '{') !== false) return 'js';
                    if (strpos($code, '#include') !== false) {
                        if (strpos($code, 'iostream') !== false) return 'cpp';
                        return 'c';
                    }
                    if (strpos($code, 'public class') !== false) return 'java';
                    if (strpos($code, 'SELECT') !== false) return 'sql';
                    return 'txt';
                }



            case 'create_exam':
                $examId = 'EXAM-' . strtoupper(uniqid());
                $duration = max(1, intval($_POST['duration'] ?? 180));
                $startDatetime = normalizeDateTimeInput($_POST['start_datetime'] ?? null);
                $endDatetime = normalizeExamEndTime($startDatetime, $_POST['end_datetime'] ?? null, $duration);
                $stmt = $pdo->prepare("
        INSERT INTO exams (exam_id, title, course_code, duration_minutes, start_datetime, end_datetime, instructions, marking_scheme,
        questions_to_answer, shuffle_enabled, grading_mode, created_by, lecturer_id, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
                $stmt->execute([
                    $examId,
                    $_POST['title'] ?? 'New Exam',
                    $_POST['course_code'] ?? '',
                    $duration,
                    $startDatetime,
                    $endDatetime,
                    $_POST['instructions'] ?? '',
                    $_POST['marking_scheme'] ?? '',
                    $_POST['questions_to_answer'] ?? 0,
                    $_POST['shuffle_enabled'] ?? 0,
                    $_POST['grading_mode'] ?? 'auto',
                    $_SESSION['user_id'],
                    $lecturerId
                ]);

                // Get the last inserted ID - THIS IS THE NUMERIC ID
                $lastId = $pdo->lastInsertId();

                echo json_encode(['success' => true, 'exam_id' => $lastId, 'exam_code' => $examId]);
                break;

            case 'add_student':
                try {
                    // Log incoming data for debugging
                    error_log("=== ADD STUDENT REQUEST ===");
                    error_log("POST data: " . print_r($_POST, true));
                    error_log("Lecturer ID: " . $lecturerId);

                    $studentId = $_POST['student_id'] ?? '';
                    $fullName = $_POST['full_name'] ?? '';
                    $level = $_POST['level'] ?? '';
                    $programme = $_POST['programme'] ?? '';
                    $status = $_POST['status'] ?? 'Active';
                    $courseCode = $_POST['course_code'] ?? '';
                    $courseName = $_POST['course_name'] ?? '';
                    $courses = parseStudentCourseList($_POST);

                    error_log("Parsed data - StudentID: $studentId, Name: $fullName, Course: $courseCode");

                    // Validate required fields
                    if (empty($studentId) || empty($fullName)) {
                        echo json_encode(['success' => false, 'error' => 'Student ID and Name are required']);
                        break;
                    }

                    if (empty($courses)) {
                        echo json_encode(['success' => false, 'error' => 'At least one Course Code and Course Name is required']);
                        break;
                    }

                    // Check if student already exists for this lecturer
                    $checkStmt = $pdo->prepare("
            SELECT s.id FROM students s 
            WHERE s.student_id = ? AND s.lecturer_id = ?
        ");
                    $checkStmt->execute([$studentId, $lecturerId]);
                    if ($checkStmt->fetch()) {
                        echo json_encode(['success' => false, 'error' => 'Student ID already exists for you']);
                        break;
                    }

                    $hashedPassword = password_hash($studentId, PASSWORD_DEFAULT);

                    error_log("Attempting to insert student...");

                    // Insert student
                    $stmt = $pdo->prepare("
    INSERT INTO students (student_id, full_name, level, programme, status, password, lecturer_id, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
");

                    $result = $stmt->execute([
                        $studentId,
                        $fullName,
                        $level,
                        $programme,
                        $status,
                        $hashedPassword,
                        $lecturerId
                    ]);
                    if (!$result) {
                        error_log("Student insert failed: " . print_r($stmt->errorInfo(), true));
                        echo json_encode(['success' => false, 'error' => 'Failed to insert student']);
                        break;
                    }

                    $newStudentId = $pdo->lastInsertId();
                    error_log("Student inserted with ID: $newStudentId");

                    // Enroll student in course if provided
                    if ($newStudentId) {
                        error_log("Attempting to enroll student in " . count($courses) . " course(s)");

                        // Check if course_enrollments table exists
                        $tableCheck = $pdo->query("SHOW TABLES LIKE 'course_enrollments'");
                        if ($tableCheck->rowCount() == 0) {
                            error_log("course_enrollments table doesn't exist! Creating it...");
                            $createTableSQL = "
                    CREATE TABLE IF NOT EXISTS course_enrollments (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        course_code VARCHAR(50) NOT NULL,
                        course_name VARCHAR(200) NOT NULL,
                        student_id INT NOT NULL,
                        lecturer_id INT NOT NULL,
                        enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uk_course_student (course_code, student_id),
                        INDEX idx_course (course_code),
                        INDEX idx_lecturer_course (lecturer_id),
                        INDEX idx_student_course (student_id)
                    )
                ";
                            $pdo->exec($createTableSQL);
                            error_log("course_enrollments table created");
                        }

                        $enrollStmt = $pdo->prepare("
                INSERT INTO course_enrollments (course_code, course_name, student_id, lecturer_id)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE course_name = VALUES(course_name), enrolled_at = CURRENT_TIMESTAMP
            ");
                        foreach ($courses as $course) {
                            $enrollResult = $enrollStmt->execute([$course['code'], $course['name'], $newStudentId, $lecturerId]);
                            if ($enrollResult) {
                                createCourseTable($course['code']);
                            } else {
                                error_log("Enrollment failed: " . print_r($enrollStmt->errorInfo(), true));
                            }
                        }
                    }

                    echo json_encode(['success' => true, 'message' => 'Student added successfully', 'student_id' => $newStudentId]);
                } catch (Exception $e) {
                    error_log("EXCEPTION in add_student: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'delete_student':
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
                $stmt->execute([$_POST['student_id']]);
                echo json_encode(['success' => true]);
                break;


            case 'delete_exam':
                try {
                    $examId = $_POST['exam_id'];
                    // Delete the exam (only if owned by this lecturer)
                    $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ? AND (created_by = ? OR lecturer_id = ?)");
                    $stmt->execute([$examId, $lecturerId, $lecturerId]);

                    if ($stmt->rowCount() > 0) {
                        echo json_encode(['success' => true]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Exam not found or you do not have permission']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_profile':
                try {
                    $stmt = $pdo->prepare("SELECT id, user_id, full_name, email, profile_pic, staff_id, department, faculty, levels_taught, classes, courses FROM users WHERE id = ? AND role = 'LECTURER'");
                    $stmt->execute([$lecturerId]);
                    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$profile) {
                        echo json_encode(['success' => false, 'error' => 'Profile not found']);
                        break;
                    }
                    echo json_encode(['success' => true, 'data' => $profile]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'update_profile':
                try {
                    $fullName = trim($_POST['full_name'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    if ($fullName === '') {
                        echo json_encode(['success' => false, 'error' => 'Full name is required']);
                        break;
                    }
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        echo json_encode(['success' => false, 'error' => 'A valid email is required']);
                        break;
                    }

                    $emailCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
                    $emailCheck->execute([$email, $lecturerId]);
                    if ($emailCheck->fetch()) {
                        echo json_encode(['success' => false, 'error' => 'Email is already in use']);
                        break;
                    }

                    $stmt = $pdo->prepare("
                        UPDATE users SET
                            full_name = ?,
                            fullName = ?,
                            email = ?,
                            staff_id = ?,
                            department = ?,
                            faculty = ?,
                            levels_taught = ?,
                            classes = ?,
                            courses = ?,
                            profile_pic = ?,
                            updated_at = NOW()
                        WHERE id = ? AND role = 'LECTURER'
                    ");
                    $stmt->execute([
                        $fullName,
                        $fullName,
                        $email,
                        trim($_POST['staff_id'] ?? ''),
                        trim($_POST['department'] ?? ''),
                        trim($_POST['faculty'] ?? ''),
                        trim($_POST['levels_taught'] ?? ''),
                        trim($_POST['classes'] ?? ''),
                        trim($_POST['courses'] ?? ''),
                        trim($_POST['profile_pic'] ?? ''),
                        $lecturerId
                    ]);
                    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'upload_profile_pic':
                if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
                    $uploadDir = 'uploads/profile_pictures/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    $extension = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];

                    if (in_array($extension, $allowed)) {
                        $filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $extension;
                        $uploadPath = $uploadDir . $filename;

                        if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadPath)) {
                            $profilePicUrl = $uploadPath;

                            $stmt = $pdo->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                            $stmt->execute([$profilePicUrl, $_SESSION['user_id']]);

                            echo json_encode(['success' => true, 'url' => $profilePicUrl]);
                        } else {
                            echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
                        }
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Invalid file type']);
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
                }
                break;
            case 'change_lecturer_password':
                try {
                    $currentPassword = $_POST['current_password'];
                    $newPassword = $_POST['new_password'];
                    $lecturerId = $_SESSION['user_id'];

                    // Get current user's password hash
                    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                    $stmt->execute([$lecturerId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$user) {
                        echo json_encode(['success' => false, 'error' => 'User not found']);
                        break;
                    }

                    // Verify current password
                    if (!password_verify($currentPassword, $user['password'])) {
                        echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
                        break;
                    }

                    // Hash new password
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                    // Update password
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $lecturerId]);

                    // Log the password change
                    $stmt = $pdo->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, description, ip_address) VALUES (?, 'LECTURER', 'PASSWORD_CHANGE', 'Lecturer changed password', ?)");
                    $stmt->execute([$lecturerId, $_SERVER['REMOTE_ADDR'] ?? '']);

                    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'reset_student_password':
                try {
                    $studentId = $_POST['student_id'];

                    // Get the student's student_id
                    $stmt = $pdo->prepare("SELECT student_id FROM students WHERE id = ?");
                    $stmt->execute([$studentId]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$student) {
                        echo json_encode(['success' => false, 'error' => 'Student not found']);
                        break;
                    }

                    $newPassword = $student['student_id'];
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $studentId]);

                    echo json_encode(['success' => true, 'message' => 'Password reset to Student ID']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'update_student':
                try {
                    $studentDbId = $_POST['student_db_id'];
                    $studentId = $_POST['student_id'];
                    $fullName = $_POST['full_name'];
                    $level = $_POST['level'];
                    $programme = $_POST['programme'];
                    $status = $_POST['status'] ?? 'Active';
                    $courseCode = trim($_POST['course_code'] ?? '');
                    $courseName = trim($_POST['course_name'] ?? '');
                    $courses = parseStudentCourseList($_POST);

                    // Check if student_id is being changed and if it already exists (excluding current student)
                    $checkStmt = $pdo->prepare("SELECT id FROM students WHERE student_id = ? AND id != ?");
                    $checkStmt->execute([$studentId, $studentDbId]);
                    if ($checkStmt->fetch()) {
                        echo json_encode(['success' => false, 'error' => 'Student ID already exists']);
                        break;
                    }

                    $stmt = $pdo->prepare("
            UPDATE students SET 
                student_id = ?,
                full_name = ?,
                level = ?,
                programme = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

                    $stmt->execute([
                        $studentId,
                        $fullName,
                        $level,
                        $programme,
                        $status,
                        $studentDbId
                    ]);

                    foreach ($courses as $course) {
                        $courseStmt = $pdo->prepare("
                            SELECT id FROM course_enrollments
                            WHERE student_id = ? AND lecturer_id = ? AND course_code = ?
                            LIMIT 1
                        ");
                        $courseStmt->execute([$studentDbId, $lecturerId, $course['code']]);
                        $existingEnrollment = $courseStmt->fetch(PDO::FETCH_ASSOC);

                        if ($existingEnrollment) {
                            $updateCourseStmt = $pdo->prepare("
                                UPDATE course_enrollments
                                SET course_name = ?
                                WHERE id = ?
                            ");
                            $updateCourseStmt->execute([$course['name'], $existingEnrollment['id']]);
                        } else {
                            $insertCourseStmt = $pdo->prepare("
                                INSERT INTO course_enrollments
                                    (student_id, lecturer_id, course_code, course_name, enrolled_at)
                                VALUES (?, ?, ?, ?, NOW())
                            ");
                            $insertCourseStmt->execute([$studentDbId, $lecturerId, $course['code'], $course['name']]);
                        }
                    }

                    if (!empty($courses)) {
                        $submittedCodes = array_column($courses, 'code');
                        $placeholders = implode(',', array_fill(0, count($submittedCodes), '?'));
                        $deleteStmt = $pdo->prepare("
                            DELETE FROM course_enrollments
                            WHERE student_id = ? AND lecturer_id = ?
                              AND course_code NOT IN ($placeholders)
                        ");
                        $deleteStmt->execute(array_merge([$studentDbId, $lecturerId], $submittedCodes));
                    }

                    echo json_encode(['success' => true, 'message' => 'Student updated successfully']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            // In lecturer_dashboard.php, update the get_dashboard_realtime_stats case
            case 'get_dashboard_realtime_stats':
                try {
                    // Students stats
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE lecturer_id = ?");
                    $stmt->execute([$lecturerId]);
                    $totalStudents = (int)$stmt->fetchColumn();

                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE lecturer_id = ? AND status = 'Active'");
                    $stmt->execute([$lecturerId]);
                    $activeStudents = (int)$stmt->fetchColumn();

                    // Exams stats
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE lecturer_id = ?");
                    $stmt->execute([$lecturerId]);
                    $totalExams = (int)$stmt->fetchColumn();

                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE lecturer_id = ? AND published = 1");
                    $stmt->execute([$lecturerId]);
                    $publishedExams = (int)$stmt->fetchColumn();

                    // Submissions stats
                    $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM exam_submissions es
            JOIN exams e ON es.exam_id = e.id
            WHERE e.lecturer_id = ?
        ");
                    $stmt->execute([$lecturerId]);
                    $totalSubmissions = (int)$stmt->fetchColumn();

                    $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM exam_submissions es
            JOIN exams e ON es.exam_id = e.id
            WHERE e.lecturer_id = ? AND es.status = 'MARKED'
        ");
                    $stmt->execute([$lecturerId]);
                    $markedSubmissions = (int)$stmt->fetchColumn();

                    // Average score from marked submissions only - ROUND to integer
                    $stmt = $pdo->prepare("
            SELECT ROUND(AVG(es.percentage)) as avg_score FROM exam_submissions es
            JOIN exams e ON es.exam_id = e.id
            WHERE e.lecturer_id = ? AND es.status = 'MARKED' AND es.percentage IS NOT NULL
        ");
                    $stmt->execute([$lecturerId]);
                    $avgScore = (int)($stmt->fetchColumn() ?: 0);

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'students' => [
                                'total' => $totalStudents,
                                'active' => $activeStudents,
                                'inactive' => $totalStudents - $activeStudents
                            ],
                            'exams' => [
                                'total' => $totalExams,
                                'published' => $publishedExams
                            ],
                            'submissions' => [
                                'total' => $totalSubmissions,
                                'marked' => $markedSubmissions,
                                'pending' => $totalSubmissions - $markedSubmissions
                            ],
                            'scores' => ['average' => $avgScore]
                        ]
                    ]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;


            // Create exam with access controls
            case 'create_exam_advanced':
                try {
                    // Generate exam ID in format EXAM-XXX-XX
                    $year = date('Y');
                    $random = strtoupper(substr(uniqid(), -4));
                    $examId = 'EXAM-' . $year . '-' . $random;


                    $title = trim($_POST['title'] ?? '');
                    $courseCode = trim($_POST['course_code'] ?? '');
                    $duration = max(1, intval($_POST['duration'] ?? 180));
                    $startDatetime = $_POST['start_datetime'] ?? null;
                    $endDatetime = $_POST['end_datetime'] ?? null;
                    $instructions = trim($_POST['instructions'] ?? '');
                    $markingScheme = trim($_POST['marking_scheme'] ?? '');
                    $questionsToAnswer = intval($_POST['questions_to_answer'] ?? 0);
                    $shuffleEnabled = intval($_POST['shuffle_enabled'] ?? 0);
                    $gradingMode = $_POST['grading_mode'] ?? 'auto';
                    $examPassword = trim($_POST['exam_password'] ?? '');
                    $questionsJson = $_POST['questions'] ?? '[]';
                    $schoolName = trim($_POST['school_name'] ?? '');
                    $facultyName = trim($_POST['faculty_name'] ?? '');
                    $department = trim($_POST['department'] ?? '');
                    $semester = trim($_POST['semester'] ?? '');
                    $examType = trim($_POST['exam_type'] ?? '');
                    $schoolType = trim($_POST['school_type'] ?? '');
                    $level = trim($_POST['level'] ?? '');
                    $examCode = trim($_POST['exam_code'] ?? $examId);
                    $autoGradingEnabled = intval($_POST['auto_grading_enabled'] ?? 0);
                    $partialGradingEnabled = intval($_POST['partial_grading_enabled'] ?? 0);
                    $showCorrectAnswers = intval($_POST['show_correct_answers'] ?? 0);
                    $allowReview = intval($_POST['allow_review'] ?? 1);
                    [$startDatetime, $endDatetime] = normalizePublishedExamSchedule($startDatetime, $endDatetime, $duration);
                    if ($startDatetime && $endDatetime) {
                        $duration = max(1, (int)round((strtotime($endDatetime) - strtotime($startDatetime)) / 60));
                    }

                    // ========== BACKEND VALIDATION ==========
                    $errors = [];

                    if ($duration <= 0) {
                        $errors[] = "Valid duration is required";
                    }

                    $questions = json_decode($questionsJson, true);
                    if (!is_array($questions)) $questions = [];

                    if (!empty($errors)) {
                        echo json_encode(['success' => false, 'error' => implode('. ', $errors)]);
                        break;
                    }

                    $hashedPassword = !empty($examPassword) ? password_hash($examPassword, PASSWORD_DEFAULT) : null;

                    // Verify course exists
                    $checkCourse = $pdo->prepare("
            SELECT COUNT(*) FROM course_enrollments 
            WHERE course_code = ? AND lecturer_id = ?
        ");
                    $checkCourse->execute([$courseCode, $lecturerId]);
                    $hasStudents = $checkCourse->fetchColumn() > 0;

                    $stmt = $pdo->prepare("
            INSERT INTO exams (
                exam_id, title, course_code, duration_minutes, start_datetime, end_datetime,
                instructions, marking_scheme, questions, questions_to_answer, 
                shuffle_enabled, grading_mode, exam_password, require_password, 
                school_name, faculty_name, department, semester, exam_type, 
                school_type, level, exam_code, auto_grading_enabled, 
                partial_grading_enabled, show_correct_answers, allow_review,
                published, created_by, lecturer_id, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW()
            )
        ");

                    $stmt->execute([
                        $examId,
                        $title,
                        $courseCode,
                        $duration,
                        $startDatetime,
                        $endDatetime,
                        $instructions,
                        $markingScheme,
                        $questionsJson,
                        $questionsToAnswer,
                        $shuffleEnabled,
                        $gradingMode,
                        $hashedPassword,
                        !empty($examPassword) ? 1 : 0,
                        $schoolName,
                        $facultyName,
                        $department,
                        $semester,
                        $examType,
                        $schoolType,
                        $level,
                        $examCode,
                        $autoGradingEnabled,
                        $partialGradingEnabled,
                        $showCorrectAnswers,
                        $allowReview,
                        $_SESSION['user_id'],
                        $lecturerId
                    ]);

                    $lastId = $pdo->lastInsertId();

                    echo json_encode([
                        'success' => true,
                        'exam_id' => $lastId,
                        'exam_code' => $examId,
                        'message' => 'Exam created successfully'
                    ]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;





                

            case 'publish_exam':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);

                    // Capture ALL form data
                    $title = trim($_POST['title'] ?? '');
                    $courseCode = trim($_POST['course_code'] ?? '');
                    $duration = max(1, intval($_POST['duration'] ?? $_POST['duration_minutes'] ?? 180));
                    $startDatetime = $_POST['start_datetime'] ?? null;
                    $endDatetime = $_POST['end_datetime'] ?? null;
                    $instructions = trim($_POST['instructions'] ?? '');
                    $markingScheme = trim($_POST['marking_scheme'] ?? '');
                    $questionsToAnswer = intval($_POST['questions_to_answer'] ?? 0);
                    $shuffleEnabled = intval($_POST['shuffle_enabled'] ?? 0);
                    $gradingMode = $_POST['grading_mode'] ?? 'auto';
                    $questionsJson = $_POST['questions'] ?? '[]';

                    $schoolName = trim($_POST['school_name'] ?? '');
                    $facultyName = trim($_POST['faculty_name'] ?? '');
                    $department = trim($_POST['department'] ?? '');
                    $semester = trim($_POST['semester'] ?? '');
                    $examType = trim($_POST['exam_type'] ?? '');
                    $schoolType = trim($_POST['school_type'] ?? '');
                    $level = trim($_POST['level'] ?? '');
                    $examCode = trim($_POST['exam_code'] ?? '');
                    $examPassword = trim($_POST['exam_password'] ?? '');

                    // Grading Options
                    $autoGradingEnabled = intval($_POST['auto_grading_enabled'] ?? 0);
                    $partialGradingEnabled = intval($_POST['partial_grading_enabled'] ?? 0);
                    $showCorrectAnswers = intval($_POST['show_correct_answers'] ?? 0);
                    $allowReview = intval($_POST['allow_review'] ?? 1);
                    $startDatetime = normalizeDateTimeInput($startDatetime);
                    $endDatetime = normalizeExamEndTime($startDatetime, $endDatetime, $duration);
                    if ($startDatetime && $endDatetime) {
                        $duration = max(1, (int)round((strtotime($endDatetime) - strtotime($startDatetime)) / 60));
                    }

                    $questions = json_decode($questionsJson, true);
                    $totalMarks = is_array($questions) ? qodaEffectiveExamMarks($questions, $questionsToAnswer) : 0;

                    $existingPasswordHash = null;
                    if ($examId > 0) {
                        $passwordStmt = $pdo->prepare("SELECT exam_password FROM exams WHERE id = ? AND (created_by = ? OR lecturer_id = ?) LIMIT 1");
                        $passwordStmt->execute([$examId, $lecturerId, $lecturerId]);
                        $existingPasswordHash = $passwordStmt->fetchColumn() ?: null;
                    }

                    // Hash password if provided. Keep the old hash when editing and password field is blank.
                    $hashedPassword = !empty($examPassword) ? password_hash($examPassword, PASSWORD_DEFAULT) : $existingPasswordHash;

                    // Generate exam code if not provided
                    if (empty($examCode)) {
                        $examCode = 'EXAM-' . strtoupper(uniqid());
                    }

                    $errors = [];
                    if ($title === '') $errors[] = 'Exam title is required';
                    if ($courseCode === '') $errors[] = 'Course code is required';
                    if ($instructions === '') $errors[] = 'Instructions are required';
                    if ($semester === '') $errors[] = 'Semester is required';
                    if ($examType === '') $errors[] = 'Exam type is required';
                    if ($schoolType === '') $errors[] = 'School type is required';
                    if ($level === '') $errors[] = 'Level is required';
                    if (!is_array($questions) || count($questions) === 0) $errors[] = 'At least one question is required';
                    if (!empty($errors)) {
                        echo json_encode(['success' => false, 'error' => implode('. ', $errors)]);
                        break;
                    }

                    // Check if exam exists
                    $checkStmt = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND (created_by = ? OR lecturer_id = ?)");
                    $checkStmt->execute([$examId, $lecturerId, $lecturerId]);

                    if ($checkStmt->rowCount() > 0) {
                        // ===== UPDATE EXISTING EXAM - INCLUDING ALL COLUMNS =====
                        $stmt = $pdo->prepare("
                UPDATE exams SET
                    title = ?,
                    course_code = ?,
                    duration_minutes = ?,
                    start_datetime = ?,
                    end_datetime = ?,
                    instructions = ?,
                    marking_scheme = ?,
                    questions = ?,
                    questions_to_answer = ?,
                    shuffle_enabled = ?,
                    grading_mode = ?,
                    exam_password = ?,
                    require_password = ?,
                    school_name = ?,
                    faculty_name = ?,
                    department = ?,
                    semester = ?,
                    exam_type = ?,
                    school_type = ?,
                    level = ?,
                    exam_code = ?,
                    auto_grading_enabled = ?,
                    partial_grading_enabled = ?,
                    show_correct_answers = ?,
                    allow_review = ?,
                    total_marks = ?,
                    published = 1,
                    status = 'published',
                    published_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND (created_by = ? OR lecturer_id = ?)
            ");

                        $stmt->execute([
                            $title,
                            $courseCode,
                            $duration,
                            $startDatetime,
                            $endDatetime,
                            $instructions,
                            $markingScheme,
                            $questionsJson,
                            $questionsToAnswer,
                            $shuffleEnabled,
                            $gradingMode,
                            $hashedPassword,
                            !empty($hashedPassword) ? 1 : 0,
                            $schoolName,
                            $facultyName,
                            $department,
                            $semester,
                            $examType,
                            $schoolType,
                            $level,
                            $examCode,
                            $autoGradingEnabled,
                            $partialGradingEnabled,
                            $showCorrectAnswers,
                            $allowReview,
                            $totalMarks,
                            $examId,
                            $lecturerId,
                            $lecturerId
                        ]);
                    } else {
                        // ===== INSERT NEW EXAM - INCLUDING ALL COLUMNS =====
                        $newExamId = 'EXAM-' . strtoupper(uniqid());
                        $stmt = $pdo->prepare("
                INSERT INTO exams (
                    exam_id, title, course_code, duration_minutes, start_datetime, end_datetime,
                    instructions, marking_scheme, questions, questions_to_answer,
                    shuffle_enabled, grading_mode, exam_password, require_password,
                    school_name, faculty_name, department, semester, exam_type,
                    school_type, level, exam_code, auto_grading_enabled,
                    partial_grading_enabled, show_correct_answers, allow_review,
                    total_marks, published, status, published_at, created_by, lecturer_id, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, 1, 'published', NOW(), ?, ?, NOW()
                )
            ");

                        $stmt->execute([
                            $newExamId,
                            $title,
                            $courseCode,
                            $duration,
                            $startDatetime,
                            $endDatetime,
                            $instructions,
                            $markingScheme,
                            $questionsJson,
                            $questionsToAnswer,
                            $shuffleEnabled,
                            $gradingMode,
                            $hashedPassword,
                            !empty($examPassword) ? 1 : 0,
                            $schoolName,
                            $facultyName,
                            $department,
                            $semester,
                            $examType,
                            $schoolType,
                            $level,
                            $examCode,
                            $autoGradingEnabled,
                            $partialGradingEnabled,
                            $showCorrectAnswers,
                            $allowReview,
                            $totalMarks,
                            $lecturerId,
                            $lecturerId
                        ]);

                        $examId = $pdo->lastInsertId();
                    }

                    // Also add to exam_class_access for course filtering
                    $checkAccess = $pdo->prepare("SELECT id FROM exam_class_access WHERE exam_id = ? AND class_code = ?");
                    $checkAccess->execute([$examId, $courseCode]);

                    if ($checkAccess->rowCount() == 0) {
                        $accessStmt = $pdo->prepare("INSERT INTO exam_class_access (exam_id, class_code, class_name, access_granted) VALUES (?, ?, ?, 1)");
                        $accessStmt->execute([$examId, $courseCode, $title]);
                    }

                    echo json_encode([
                        'success' => true,
                        'message' => 'Exam published successfully',
                        'exam_id' => $examId
                    ]);
                } catch (Exception $e) {
                    error_log("Publish exam error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_course_students':
                try {
                    $courseCode = $_POST['course_code'] ?? '';
                    $level = trim($_POST['level'] ?? '');
                    $examId = intval($_POST['exam_id'] ?? 0);

                    $stmt = $pdo->prepare("
            SELECT s.id, s.student_id, s.full_name, s.level, s.programme
            FROM students s
            JOIN course_enrollments ce ON s.id = ce.student_id
            LEFT JOIN exam_visibility ev ON ev.exam_id = ? AND ev.student_id = s.id
            WHERE ce.course_code = ? AND ce.lecturer_id = ?
              AND COALESCE(ev.visible, 1) = 1
              AND (? = '' OR s.level = ?)
            ORDER BY s.full_name
        ");
                    $stmt->execute([$examId, $courseCode, $lecturerId, $level, $level]);
                    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode(['success' => true, 'data' => $students, 'count' => count($students)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            // Auto-grade submission
            case 'auto_grade_submission':
                try {
                    $submissionId = $_POST['submission_id'];

                    // Get submission and exam details
                    $stmt = $pdo->prepare("SELECT s.*, e.marking_scheme, e.grading_mode, e.total_marks 
                               FROM submissions s JOIN exams e ON s.exam_id = e.id WHERE s.id = ?");
                    $stmt->execute([$submissionId]);
                    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$submission) {
                        echo json_encode(['success' => false, 'error' => 'Submission not found']);
                        break;
                    }

                    $answers = json_decode($submission['answers'], true);
                    $totalScore = 0;
                    $gradingDetails = [];

                    // Auto-grade each answer based on question type
                    foreach ($answers as $answer) {
                        $questionId = $answer['question_id'];
                        $answerText = $answer['answer'] ?? '';

                        // Get question details
                        $stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE question_id = ?");
                        $stmt->execute([$questionId]);
                        $question = $stmt->fetch(PDO::FETCH_ASSOC);

                        $score = 0;
                        $maxMarks = $question['marks'] ?? 0;

                        switch ($question['question_type']) {

                            case 'short':
                                // Keyword matching
                                $keywords = explode(',', strtolower($question['keywords'] ?? ''));
                                $answerLower = strtolower($answerText);
                                $matches = 0;
                                foreach ($keywords as $keyword) {
                                    if (strpos($answerLower, trim($keyword)) !== false) {
                                        $matches++;
                                    }
                                }
                                $score = ($matches / max(count($keywords), 1)) * $maxMarks;
                                break;

                            case 'essay':
                                // Manual grading required
                                $score = 0;
                                break;
                            default:
                                $score = 0;
                        }

                        $totalScore += $score;
                        $gradingDetails[] = [
                            'question_id' => $questionId,
                            'score' => $score,
                            'max_marks' => $maxMarks,
                            'auto_graded' => $question['question_type'] !== 'essay'
                        ];

                        // Save answer score
                        $stmt = $pdo->prepare("UPDATE student_answers SET auto_score = ? WHERE submission_id = ? AND question_id = ?");
                        $stmt->execute([$score, $submissionId, $questionId]);
                    }

                    $percentage = $submission['total_marks'] > 0 ? ($totalScore / $submission['total_marks']) * 100 : 0;

                    // Update submission
                    $stmt = $pdo->prepare("UPDATE submissions SET auto_score = ?, percentage = ?, status = 'AUTO_GRADED' WHERE id = ?");
                    $stmt->execute([$totalScore, $percentage, $submissionId]);

                    echo json_encode(['success' => true, 'total_score' => $totalScore, 'percentage' => round($percentage, 2), 'grading_details' => $gradingDetails]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

// Add these API endpoints
case 'save_question_score':
    try {
        $submission_id = intval($_POST['submission_id'] ?? 0);
        $question_index = intval($_POST['question_index'] ?? 0);
        $score = floatval($_POST['score'] ?? 0);
        $feedback = $_POST['feedback'] ?? '';
        $marking_scheme = $_POST['marking_scheme'] ?? '';
        $test_cases = $_POST['test_cases'] ?? '[]';
        
        // Save marking scheme and test cases for this question
        $stmt = $pdo->prepare("
            INSERT INTO exam_question_grading (submission_id, question_index, marking_scheme, test_cases, ai_score, ai_feedback, graded_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            marking_scheme = VALUES(marking_scheme),
            test_cases = VALUES(test_cases),
            ai_score = VALUES(ai_score),
            ai_feedback = VALUES(ai_feedback),
            graded_at = NOW()
        ");
        $stmt->execute([$submission_id, $question_index, $marking_scheme, $test_cases, $score, $feedback]);
        
        // Update the submission answers with the score
        $stmt2 = $pdo->prepare("SELECT answers FROM exam_submissions WHERE id = ?");
        $stmt2->execute([$submission_id]);
        $submission = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        $answers = json_decode($submission['answers'], true);
        if (!isset($answers['_scores'])) $answers['_scores'] = [];
        $answers['_scores'][$question_index] = $score;
        if (!isset($answers['_ai_feedback'])) $answers['_ai_feedback'] = [];
        $answers['_ai_feedback'][$question_index] = $feedback;
        
        $stmt3 = $pdo->prepare("UPDATE exam_submissions SET answers = ? WHERE id = ?");
        $stmt3->execute([json_encode($answers), $submission_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    break;


            case 'save_manual_grade':
                try {
                    $answerId = $_POST['answer_id'];
                    $manualScore = $_POST['manual_score'];
                    $feedback = $_POST['feedback'] ?? '';

                    $stmt = $pdo->prepare("UPDATE exam_answers SET manual_score = ?, feedback = ?, marked_by = ?, marked_at = NOW() WHERE id = ?");
                    $stmt->execute([$manualScore, $feedback, $_SESSION['user_id'], $answerId]);

                    // Recalculate total for the attempt
                    $stmt = $pdo->prepare("
            SELECT attempt_id, SUM(auto_score + manual_score) as total, SUM(marks_allocated) as total_marks 
            FROM exam_answers ea
            JOIN question_grading_criteria qg ON ea.question_id = qg.question_id
            WHERE ea.attempt_id = (SELECT attempt_id FROM exam_answers WHERE id = ?)
        ");
                    $stmt->execute([$answerId]);
                    $totals = $stmt->fetch();

                    $percentage = ($totals['total'] / $totals['total_marks']) * 100;
                    $stmt = $pdo->prepare("UPDATE submissions SET manual_score = ?, total_score = ?, percentage = ?, status = 'MANUALLY_GRADED' WHERE attempt_id = ?");
                    $stmt->execute([$totals['total'], $totals['total'], $percentage, $totals['attempt_id']]);

                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'publish_results':
                try {
                    $examId = $_POST['exam_id'];
                    $stmt = $pdo->prepare("UPDATE exams SET results_published = 1, results_published_at = NOW() WHERE id = ?");
                    $stmt->execute([$examId]);
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'delete_student_submission':
                try {
                    $submissionId = intval($_POST['submission_id'] ?? 0);
                    if ($submissionId <= 0) {
                        echo json_encode(['success' => false, 'error' => 'Invalid submission']);
                        break;
                    }

                    $check = $pdo->prepare("
                        SELECT es.id
                        FROM exam_submissions es
                        LEFT JOIN exams e ON e.id = es.exam_id
                        WHERE es.id = ?
                          AND (e.lecturer_id = ? OR e.created_by = ? OR e.lecturer_id IS NULL)
                        LIMIT 1
                    ");
                    $check->execute([$submissionId, $lecturerId, $lecturerId]);
                    if (!$check->fetchColumn()) {
                        echo json_encode(['success' => false, 'error' => 'Submission not found or not allowed']);
                        break;
                    }

                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM exam_question_grading WHERE submission_id = ?")->execute([$submissionId]);
                    $hasQuestionScores = $pdo->query("SHOW TABLES LIKE 'submission_question_scores'")->fetchColumn();
                    if ($hasQuestionScores) {
                        $pdo->prepare("DELETE FROM submission_question_scores WHERE submission_id = ?")->execute([$submissionId]);
                    }
                    $stmt = $pdo->prepare("DELETE FROM exam_submissions WHERE id = ?");
                    $stmt->execute([$submissionId]);
                    $pdo->commit();

                    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'delete_course_submissions':
                try {
                    $examIdsRaw = $_POST['exam_ids'] ?? '[]';
                    $examIds = json_decode($examIdsRaw, true);
                    if (!is_array($examIds)) $examIds = [];
                    $examIds = array_values(array_unique(array_filter(array_map('intval', $examIds))));

                    if (count($examIds) === 0) {
                        echo json_encode(['success' => false, 'error' => 'No course submissions selected']);
                        break;
                    }

                    $placeholders = implode(',', array_fill(0, count($examIds), '?'));
                    $checkParams = array_merge($examIds, [$lecturerId, $lecturerId]);
                    $check = $pdo->prepare("
                        SELECT id
                        FROM exams
                        WHERE id IN ($placeholders)
                          AND (lecturer_id = ? OR created_by = ? OR lecturer_id IS NULL)
                    ");
                    $check->execute($checkParams);
                    $allowedExamIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));

                    if (count($allowedExamIds) === 0) {
                        echo json_encode(['success' => false, 'error' => 'No allowed course submissions found']);
                        break;
                    }

                    $allowedPlaceholders = implode(',', array_fill(0, count($allowedExamIds), '?'));
                    $submissionStmt = $pdo->prepare("SELECT id FROM exam_submissions WHERE exam_id IN ($allowedPlaceholders)");
                    $submissionStmt->execute($allowedExamIds);
                    $submissionIds = array_map('intval', $submissionStmt->fetchAll(PDO::FETCH_COLUMN));

                    $pdo->beginTransaction();
                    if (count($submissionIds) > 0) {
                        $submissionPlaceholders = implode(',', array_fill(0, count($submissionIds), '?'));
                        $pdo->prepare("DELETE FROM exam_question_grading WHERE submission_id IN ($submissionPlaceholders)")->execute($submissionIds);
                        $hasQuestionScores = $pdo->query("SHOW TABLES LIKE 'submission_question_scores'")->fetchColumn();
                        if ($hasQuestionScores) {
                            $pdo->prepare("DELETE FROM submission_question_scores WHERE submission_id IN ($submissionPlaceholders)")->execute($submissionIds);
                        }
                    }
                    $deleteStmt = $pdo->prepare("DELETE FROM exam_submissions WHERE exam_id IN ($allowedPlaceholders)");
                    $deleteStmt->execute($allowedExamIds);
                    $deleted = $deleteStmt->rowCount();
                    $pdo->commit();

                    echo json_encode(['success' => true, 'deleted' => $deleted]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;


            // Add this case for enrolling existing student in additional course
            case 'enroll_student_course':
                try {
                    $studentId = $_POST['student_id'];
                    $courseCode = $_POST['course_code'];
                    $courseName = $_POST['course_name'];

                    // Check if already enrolled
                    $checkStmt = $pdo->prepare("
            SELECT id FROM course_enrollments 
            WHERE student_id = ? AND course_code = ? AND lecturer_id = ?
        ");
                    $checkStmt->execute([$studentId, $courseCode, $lecturerId]);

                    if ($checkStmt->fetch()) {
                        echo json_encode(['success' => false, 'error' => 'Student already enrolled in this course']);
                        break;
                    }

                    $stmt = $pdo->prepare("
            INSERT INTO course_enrollments (course_code, course_name, student_id, lecturer_id)
            VALUES (?, ?, ?, ?)
        ");
                    $stmt->execute([$courseCode, $courseName, $studentId, $lecturerId]);

                    echo json_encode(['success' => true, 'message' => 'Student enrolled successfully']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            // Add this case for getting student's enrolled courses
            case 'get_student_courses':
                try {
                    $studentId = $_POST['student_id'];

                    $stmt = $pdo->prepare("
            SELECT ce.*, u.full_name as lecturer_name
            FROM course_enrollments ce
            LEFT JOIN users u ON ce.lecturer_id = u.id
            WHERE ce.student_id = ?
            ORDER BY ce.enrolled_at DESC
        ");
                    $stmt->execute([$studentId]);
                    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode(['success' => true, 'data' => $courses]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'update_exam':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $duration = max(1, intval($_POST['duration'] ?? 180));
                    $startDatetime = normalizeDateTimeInput($_POST['start_datetime'] ?? null);
                    $endDatetime = normalizeExamEndTime($startDatetime, $_POST['end_datetime'] ?? null, $duration);
                    if ($startDatetime && $endDatetime) {
                        $duration = max(1, (int)round((strtotime($endDatetime) - strtotime($startDatetime)) / 60));
                    }

                    $stmt = $pdo->prepare("
            UPDATE exams SET 
                title = ?,
                course_code = ?,
                duration_minutes = ?,
                start_datetime = ?,
                end_datetime = ?,
                instructions = ?,
                marking_scheme = ?,
                questions = ?,
                questions_to_answer = ?,
                shuffle_enabled = ?,
                grading_mode = ?,
                school_name = ?,
                faculty_name = ?,
                department = ?,
                semester = ?,
                exam_type = ?,
                school_type = ?,
                level = ?,
                exam_code = ?,
                auto_grading_enabled = ?,
                partial_grading_enabled = ?,
                show_correct_answers = ?,
                allow_review = ?,
                draft_saved_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND (created_by = ? OR lecturer_id = ?)
        ");

                    $stmt->execute([
                        $_POST['title'] ?? '',
                        $_POST['course_code'] ?? '',
                        $duration,
                        $startDatetime,
                        $endDatetime,
                        $_POST['instructions'] ?? '',
                        $_POST['marking_scheme'] ?? '',
                        $_POST['questions'] ?? '[]',
                        intval($_POST['questions_to_answer'] ?? 0),
                        intval($_POST['shuffle_enabled'] ?? 0),
                        $_POST['grading_mode'] ?? 'auto',
                        $_POST['school_name'] ?? '',
                        $_POST['faculty_name'] ?? '',
                        $_POST['department'] ?? '',
                        $_POST['semester'] ?? '',
                        $_POST['exam_type'] ?? '',
                        $_POST['school_type'] ?? '',
                        $_POST['level'] ?? '',
                        $_POST['exam_code'] ?? '',
                        intval($_POST['auto_grading_enabled'] ?? 0),
                        intval($_POST['partial_grading_enabled'] ?? 0),
                        intval($_POST['show_correct_answers'] ?? 0),
                        intval($_POST['allow_review'] ?? 1),
                        $examId,
                        $lecturerId,
                        $lecturerId
                    ]);

                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

                // ========== CREATE SUBMISSIONS TABLE IF NOT EXISTS ==========
                try {
                    // Check if exam_submissions table exists
                    $checkTable = $pdo->query("SHOW TABLES LIKE 'exam_submissions'");
                    if ($checkTable->rowCount() == 0) {
                        // Create the submissions table
                        $createTable = "
            CREATE TABLE IF NOT EXISTS exam_submissions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                exam_id INT NOT NULL,
                student_id INT NOT NULL,
                student_name VARCHAR(255),
                student_identifier VARCHAR(100),
                answers LONGTEXT,
                total_score DECIMAL(10,2) DEFAULT 0,
                percentage DECIMAL(5,2) DEFAULT 0,
                status VARCHAR(50) DEFAULT 'SUBMITTED',
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_late BOOLEAN DEFAULT FALSE,
                late_minutes INT DEFAULT 0,
                ip_address VARCHAR(45),
                user_agent TEXT,
                manual_feedback TEXT,
                graded_at TIMESTAMP NULL,
                graded_by INT NULL,
                INDEX idx_exam (exam_id),
                INDEX idx_student (student_id),
                INDEX idx_status (status),
                FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
                        $pdo->exec($createTable);
                        error_log("✅ exam_submissions table created");
                    }

                    // Check if submission_question_scores table exists
                    $checkScoresTable = $pdo->query("SHOW TABLES LIKE 'submission_question_scores'");
                    if ($checkScoresTable->rowCount() == 0) {
                        $createScoresTable = "
            CREATE TABLE IF NOT EXISTS submission_question_scores (
                id INT PRIMARY KEY AUTO_INCREMENT,
                submission_id INT NOT NULL,
                question_id VARCHAR(50) NOT NULL,
                score DECIMAL(10,2) DEFAULT 0,
                feedback TEXT,
                UNIQUE KEY uk_submission_question (submission_id, question_id),
                INDEX idx_submission (submission_id),
                FOREIGN KEY (submission_id) REFERENCES exam_submissions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
                        $pdo->exec($createScoresTable);
                        error_log("✅ submission_question_scores table created");
                    }
                } catch (Exception $e) {
                    error_log("Error creating tables: " . $e->getMessage());
                }

                // ========== CREATE TEST SUBMISSION IF NONE EXISTS ==========
                try {
                    // Check if there are any submissions
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM exam_submissions");
                    $countStmt->execute();
                    $submissionCount = $countStmt->fetchColumn();

                    if ($submissionCount == 0) {
                        // Get first exam and student
                        $examStmt = $pdo->prepare("SELECT id, title, questions, total_marks FROM exams WHERE lecturer_id = ? LIMIT 1");
                        $examStmt->execute([$lecturerId]);
                        $exam = $examStmt->fetch(PDO::FETCH_ASSOC);

                        $studentStmt = $pdo->prepare("SELECT id, full_name, student_id FROM students WHERE lecturer_id = ? LIMIT 1");
                        $studentStmt->execute([$lecturerId]);
                        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

                        if ($exam && $student) {
                            // Create test answers
                            $questions = json_decode($exam['questions'], true);
                            $testAnswers = [];
                            if (is_array($questions)) {
                                foreach ($questions as $idx => $q) {
                                    $testAnswers[$idx] = [
                                        'question_id' => $q['id'] ?? $idx,
                                        'answer' => "Sample answer for question " . ($idx + 1) . ": " . substr($q['text'] ?? 'No question text', 0, 100),
                                        'code' => ''
                                    ];
                                }
                            } else {
                                $testAnswers = [
                                    ['question_id' => 1, 'answer' => 'Test answer 1'],
                                    ['question_id' => 2, 'answer' => 'Test answer 2']
                                ];
                            }

                            $insertStmt = $pdo->prepare("
                INSERT INTO exam_submissions (exam_id, student_id, student_name, student_identifier, answers, submitted_at, status, total_score, percentage)
                VALUES (?, ?, ?, ?, ?, NOW(), 'SUBMITTED', 0, 0)
            ");

                            $insertStmt->execute([
                                $exam['id'],
                                $student['id'],
                                $student['full_name'],
                                $student['student_id'],
                                json_encode($testAnswers)
                            ]);

                            error_log("✅ Test submission created for debugging");
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error creating test submission: " . $e->getMessage());
                }



            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}



function createCourseTable($courseCode)
{
    global $pdo;
    $tableName = "course_" . preg_replace('/[^a-zA-Z0-9_]/', '_', $courseCode) . "_students";

    $sql = "CREATE TABLE IF NOT EXISTS `$tableName` (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        exam_id VARCHAR(50),
        score DECIMAL(5,2),
        grade VARCHAR(2),
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student (student_id),
        INDEX idx_exam (exam_id)
    )";

    $pdo->exec($sql);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Qoda | Lecturer Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs/loader.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* =========================================================
   1. GENERAL PAGE / ROOT / THEME / GLOBALS
========================================================= */
    :root {
        --bg: #f8fafc;
        --panel: #ffffff;
        --border: #e2e8f0;
        --text: #0f172a;
        --text-light: #334155;
        --muted: #64748b;

        --sidebar: #0a0f1f;
        --sidebar2: #0c1222;
        --sideText: #cbd5e1;
        --sideActive: #38bdf8;

        --blue: #0284c7;
        --blue2: #0369a1;
        --danger: #ef4444;
        --warn: #f59e0b;
        --ok: #22c55e;
        --success: #10b981;
        --info: #3b82f6;

        --gradient-start: #3b82f6;
        --gradient-end: #8b5cf6;

        --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.08);
        --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, .1), 0 10px 10px -5px rgba(0, 0, 0, .04);

        --chart-bg: #ffffff;
        --input-bg: #ffffff;
        --label-color: #334155;

        --btn-text: #0f172a;
        --btn-bg: #ffffff;
        --btn-border: #e2e8f0;

        --btn-primary-bg: #f3f8ff;
        --btn-primary-text: #1e73be;
        --btn-primary-border: #9cc6e6;

        --btn-ok-bg: rgba(34, 197, 94, .10);
        --btn-ok-text: #166534;
        --btn-ok-border: rgba(34, 197, 94, .4);

        --btn-warn-bg: rgba(245, 158, 11, .10);
        --btn-warn-text: #92400e;
        --btn-warn-border: rgba(245, 158, 11, .4);

        --btn-danger-bg: rgba(239, 68, 68, .10);
        --btn-danger-text: #991b1b;
        --btn-danger-border: rgba(239, 68, 68, .5);

        --compulsory-badge: #ef4444;

        --status-published-bg: #10b981;
        --status-published-text: #ffffff;
        --status-locked-bg: #6b7280;
        --status-locked-text: #ffffff;
        --status-active-bg: #10b981;
        --status-active-text: #ffffff;
        --status-inactive-bg: #ef4444;
        --status-inactive-text: #ffffff;

        --grade-a-bg: #10b981;
        --grade-a-text: #ffffff;
        --grade-bplus-bg: #34d399;
        --grade-bplus-text: #ffffff;
        --grade-b-bg: #3b82f6;
        --grade-b-text: #ffffff;
        --grade-cplus-bg: #f59e0b;
        --grade-cplus-text: #ffffff;
        --grade-c-bg: #fbbf24;
        --grade-c-text: #ffffff;
        --grade-dplus-bg: #f97316;
        --grade-dplus-text: #ffffff;
        --grade-d-bg: #ef4444;
        --grade-d-text: #ffffff;
        --grade-e-bg: #6b7280;
        --grade-e-text: #ffffff;
    }

    body.dark {
        --bg: #0f172a;
        --panel: #1e293b;
        --border: #475569;
        --text: #f8fafc;
        --text-light: #e2e8f0;
        --muted: #94a3b8;

        --chart-bg: #1e293b;
        --input-bg: #0f172a;
        --label-color: #cbd5e1;

        --btn-text: #f8fafc;
        --btn-bg: #334155;
        --btn-border: #64748b;

        --btn-primary-bg: #1e3a5f;
        --btn-primary-text: #93c5fd;
        --btn-primary-border: #3b82f6;

        --btn-ok-bg: #14532d;
        --btn-ok-text: #86efac;
        --btn-ok-border: #22c55e;

        --btn-warn-bg: #713f12;
        --btn-warn-text: #fde047;
        --btn-warn-border: #eab308;

        --btn-danger-bg: #7f1d1d;
        --btn-danger-text: #fca5a5;
        --btn-danger-border: #ef4444;

        --compulsory-badge: #f87171;

        --status-published-bg: #059669;
        --status-published-text: #ffffff;
        --status-locked-bg: #4b5563;
        --status-locked-text: #ffffff;
        --status-active-bg: #059669;
        --status-active-text: #ffffff;
        --status-inactive-bg: #b91c1c;
        --status-inactive-text: #ffffff;

        --grade-a-bg: #059669;
        --grade-a-text: #ffffff;
        --grade-bplus-bg: #10b981;
        --grade-bplus-text: #ffffff;
        --grade-b-bg: #2563eb;
        --grade-b-text: #ffffff;
        --grade-cplus-bg: #d97706;
        --grade-cplus-text: #ffffff;
        --grade-c-bg: #ca8a04;
        --grade-c-text: #ffffff;
        --grade-dplus-bg: #c2410c;
        --grade-dplus-text: #ffffff;
        --grade-d-bg: #b91c1c;
        --grade-d-text: #ffffff;
        --grade-e-bg: #4b5563;
        --grade-e-text: #ffffff;
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    html,
    body {
        width: 100%;
        overflow-x: hidden;
    }

    body {
        font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: var(--bg);
        color: var(--text);
        line-height: 1.5;
        transition: background-color .3s, color .3s;
        padding-top: 72px;
    }


    .view {
        display: none;
        animation: fadeIn .35s ease-out;
    }

    .view.active {
        display: block;
    }

    button,
    input,
    select,
    textarea {
        font-family: inherit;
        transition: all .2s ease;
    }

    .small {
        font-size: 12px;
        color: var(--muted);
    }

    .divider {
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--border), transparent);
        margin: 20px 0;
    }

    .spinner {
        width: 40px;
        height: 40px;
        border: 4px solid #e2e8f0;
        border-top-color: var(--blue);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 20px auto;
    }

    .toast {
        position: fixed;
        left: 50%;
        bottom: 30px;
        transform: translateX(-50%) translateY(100px);
        background: #1e293b;
        color: #fff;
        padding: 12px 24px;
        border-radius: 999px;
        font-size: 14px;
        font-weight: 500;
        opacity: 0;
        pointer-events: none;
        transition: all .3s cubic-bezier(.68, -0.55, .265, 1.55);
        box-shadow: var(--shadow-lg);
        z-index: 3000;
    }

    .toast[style*="opacity: 1"] {
        transform: translateX(-50%) translateY(0);
    }

    /* Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    ::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    /* =========================================================
   2. HEADER BAR AND EVERYTHING INSIDE IT
========================================================= */
    .header-bar {
        background: var(--panel);
        border-bottom: 1px solid var(--border);
        padding: 10px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        height: 72px;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 2000;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
        min-width: 0;
    }

    .mobile-menu-btn {
        display: none;
        width: 42px;
        height: 42px;
        border: none;
        border-radius: 12px;
        background: linear-gradient(135deg, #4f46e5, #06b6d4);
        color: #fff;
        font-size: 18px;
        cursor: pointer;
        align-items: center;
        justify-content: center;
        box-shadow: 0 6px 14px rgba(79, 70, 229, .25);
    }

    .header-logo {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, #4f46e5, #06b6d4);
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 10px rgba(79, 70, 229, .3);
        flex-shrink: 0;
    }

    .header-logo span {
        font-size: 26px;
        font-weight: 800;
        color: #fff;
    }

    .header-typing {
        font-size: 20px;
        font-weight: 800;
        color: var(--text);
        white-space: nowrap;
    }

    .header-center {
        flex: 1;
        min-width: 0;
        max-width: 720px;
        display: flex;
        gap: 8px;
        margin: 0 auto;
    }

    .header-search {
        flex: 1;
        min-width: 0;
        position: relative;
    }

    .header-search i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        font-size: 14px;
        pointer-events: none;
    }

    .header-search input {
        width: 100%;
        padding: 10px 16px 10px 40px;
        border: 1px solid var(--border);
        border-radius: 30px;
        background: var(--bg);
        color: var(--text);
        font-size: 14px;
        outline: none;
    }

    .header-search input:focus {
        border-color: var(--blue);
        box-shadow: 0 0 0 2px rgba(2, 132, 199, .1);
    }

    .header-search-btn {
        padding: 0 16px;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        border: none;
        border-radius: 30px;
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }

    .header-search-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(79, 70, 229, .3);
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }

    .header-theme,
    .header-logout {
        height: 40px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .header-theme {
        width: 40px;
        background: var(--bg);
        border: 1px solid var(--border);
    }

    .header-theme:hover {
        background: var(--blue);
        color: #fff;
        border-color: var(--blue);
    }

    .header-logout {
        padding: 0 14px;
        background: var(--bg);
        border: 1px solid var(--border);
        gap: 8px;
        font-size: 14px;
        font-weight: 500;
    }

    .header-logout:hover {
        background: var(--danger);
        color: #fff;
        border-color: var(--danger);
    }

    .search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 12px;
        margin-top: 8px;
        display: none;
        z-index: 2100;
        max-height: 300px;
        overflow-y: auto;
        box-shadow: var(--shadow);
    }

    .search-results.active {
        display: block;
    }

    .search-result-item {
        padding: 10px 14px;
        cursor: pointer;
        border-bottom: 1px solid var(--border);
        font-size: 13px;
    }

    .search-result-item:hover {
        background: var(--bg);
    }

    .search-result-item strong {
        display: block;
        font-size: 14px;
    }

    .search-result-item small {
        color: var(--muted);
        font-size: 11px;
    }

    /* Header responsive */
    @media (max-width: 980px) {
        .mobile-menu-btn {
            display: flex !important;
        }

        .header-bar {
            padding: 8px 10px;
            gap: 10px;
        }


        .header-center {
            max-width: none;
        }

        .header-search-btn span,
        .header-logout span {
            display: none;

        }

        .header-search-btn {
            padding: 0 12px;
        }

        .header-logout {
            width: 40px;
            padding: 0;
        }
    }

    @media (max-width: 640px) {
        .header-logo {
            width: 42px;
            height: 42px;
        }

        .header-logo span {
            font-size: 22px;
        }


        .header-center {
            flex: 1;
        }

        .header-search input {
            height: 42px;
            font-size: 13px;
            width: 20px;
        }

        .header-search-btn {
            display: none;
        }
    }

    /* =========================================================
   3. SIDEBAR AND ITS COMPONENTS
========================================================= */
    .layout {
        display: block;
        margin-left: 80px;
    }

    .sidebar {
        position: fixed;
        left: 0;
        top: 72px;
        width: 80px;
        height: calc(100vh - 72px);
        background: linear-gradient(180deg, var(--sidebar) 0%, var(--sidebar2) 100%);
        border-right: 1px solid rgba(66, 153, 225, .2);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        z-index: 1500;
        overflow-y: auto;
        overflow-x: hidden;
        transition: left .3s ease, transform .3s ease;
    }

    .sidebar-top {
        padding: 20px 0;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, .08);
    }

    .profile-icon {
        width: 48px;
        height: 48px;
        margin: 0 auto;
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        overflow: hidden;
        border: 2px solid rgba(66, 153, 225, .5);
        transition: all .25s ease;
    }

    .profile-icon:hover {
        transform: scale(1.05);
        border-color: #4299e1;
    }

    .profile-icon i {
        font-size: 24px;
        color: #fff;
    }

    .profile-icon img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .sidebar-nav {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        padding: 20px 0;
        overflow-x: hidden;
    }

    .nav-icon {
        position: relative;
        width: 52px;
        height: 52px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #a0aec0;
        cursor: pointer;
        border-radius: 12px;
        transition: all .2s ease;
        flex-shrink: 0;
        overflow: hidden;
    }

    .nav-icon:hover {
        background: rgba(255, 255, 255, .1);
        color: #fff;
        transform: scale(1.04);
    }

    .nav-icon.active {
        background: rgba(59, 130, 246, .2);
        color: #3b82f6;
    }

    .nav-icon i {
        font-size: 22px;
    }

    .notification-badge {
        position: absolute;
        top: 6px;
        right: 6px;
        min-width: 18px;
        height: 18px;
        background: #ef4444;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 5px;
    }

    .tooltip-text {
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        margin-left: 12px;
        padding: 6px 12px;
        background: #1e293b;
        color: #fff;
        font-size: 12px;
        font-weight: 500;
        border-radius: 8px;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: all .2s ease;
        z-index: 2200;
        border: 1px solid #334155;
        pointer-events: none;
    }

    .nav-icon:hover .tooltip-text {
        opacity: 1;
        visibility: visible;
    }

    .sidebar-bottom {
        padding: 20px 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        border-top: 1px solid rgba(255, 255, 255, .08);
    }

    .theme-switch-icon,
    .logout-icon {
        width: 48px;
        height: 48px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #a0aec0;
        cursor: pointer;
        border-radius: 12px;
        transition: all .2s ease;
        position: relative;
    }

    .theme-switch-icon:hover,
    .logout-icon:hover {
        background: rgba(255, 255, 255, .1);
        color: #fff;
    }

    .theme-switch-icon i,
    .logout-icon i {
        font-size: 20px;
    }

    /* Sidebar close button */
    .sidebar-close-btn {
        display: none;
    }

    /* Overlay */
    .sidebar-overlay {
        display: none;
    }

    /* Desktop submenu */
    .submenu-panel {
        position: fixed;
        left: 80px;
        top: 72px;
        width: 280px;
        height: calc(100vh - 72px);
        background: #111827;
        border-right: 1px solid #1f2937;
        transform: translateX(-100%);
        transition: transform .3s ease;
        z-index: 1400;
        overflow-y: auto;
        overflow-x: hidden;
        display: flex;
        flex-direction: column;
    }

    body.dark .submenu-panel {
        background: #1e293b;
        border-color: #334155;
    }

    .submenu-panel.open {
        transform: translateX(0);
    }

    .submenu-header {
        padding: 20px;
        border-bottom: 1px solid #1f2937;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    body.dark .submenu-header {
        border-color: #334155;
    }

    .submenu-header h3 {
        font-size: 18px;
        font-weight: 600;
        color: #fff;
        margin: 0;
    }

    .submenu-header i {
        font-size: 20px;
        color: #a0aec0;
        cursor: pointer;
        transition: all .2s;
    }

    .submenu-header i:hover {
        color: #ef4444;
        transform: scale(1.08);
    }

    .submenu-content {
        flex: 1;
        padding: 12px 0;
        overflow-y: auto;
    }

    .submenu-item {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 20px;
        margin: 4px 12px;
        border-radius: 12px;
        color: #a0aec0;
        cursor: pointer;
        transition: all .2s;
        font-size: 14px;
        font-weight: 500;
    }

    .submenu-item i {
        width: 24px;
        font-size: 18px;
        color: #3b82f6;
    }

    .submenu-item:hover {
        background: rgba(59, 130, 246, .1);
        color: #fff;
        transform: translateX(4px);
    }

    .submenu-profile-info {
        padding: 16px 20px;
        border-top: 1px solid #1f2937;
        border-bottom: 1px solid #1f2937;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    body.dark .submenu-profile-info {
        border-color: #334155;
    }

    .profile-avatar-small {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    .profile-avatar-small i {
        font-size: 24px;
        color: #fff;
    }

    .profile-avatar-small img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .profile-details {
        flex: 1;
    }

    .profile-details .profile-name {
        font-size: 14px;
        font-weight: 600;
        color: #fff;
        margin-bottom: 2px;
    }

    .profile-details .profile-staff,
    .profile-details .profile-dept {
        font-size: 11px;
        color: #a0aec0;
    }

    .submenu-bottom {
        padding: 16px 20px;
        text-align: center;
    }

    .signed-in-info .signed-in-label {
        font-size: 10px;
        color: #a0aec0;
        text-transform: uppercase;
        margin-bottom: 4px;

    }

    .signed-in-info .signed-in-role {
        font-size: 13px;
        font-weight: 600;
        color: #fff;
    }

    /* ===== MOBILE SIDEBAR: KEEP IT NARROW ===== */
    @media (max-width: 980px) {
        .layout {
            margin-left: 90px;
        }

        .main {
            padding: 20px 14px 24px;
        }

        .mobile-menu-btn {
            display: flex !important;
        }

        .sidebar {
            left: -80px;
            width: 80px;
            top: 72px;
            height: calc(100vh - 72px);
            z-index: 2200;
            box-shadow: 2px 0 14px rgba(0, 0, 0, .3);
        }

        .sidebar.mobile-open {
            left: 0;
        }


        .sidebar-top {
            padding: 14px 0;
        }

        .sidebar-nav {
            align-items: center;
            padding: 14px 0;
            gap: 8px;
        }

        .nav-icon {
            width: 52px;
            height: 52px;
            justify-content: center;
            padding: 0;
        }

        .nav-icon::after {
            display: none !important;
            content: none !important;
        }

        .nav-icon .tooltip-text {
            display: none !important;
        }

        .submenu-panel {
            position: right;
            left: -40px;
            top: 72px;
            width: 400px;
            height: calc(100vh - 72px);
            background: #111827;
            border-right: 1px solid #1f2937;
            transform: translateX(-770%);
            transition: transform .3s ease;
            z-index: 2190;
            display: flex;
            flex-direction: column;
            padding-left: 120px;
        }

        .submenu-panel.open {
            transform: translateX(0);
        }

        .submenu-panel.active {
            transform: translateX(0);
        }

        .sidebar-overlay.active {
            display: block;
            position: fixed;
            top: 72px;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(162, 156, 156, 0.48);
            z-index: 2100;
        }

        #mobileSubmenuBox {
            display: none !important;
        }

    }



    /* =========================================================
   4. MAIN PAGES / SHARED COMPONENTS / PAGE CONTENT
========================================================= */
    .main {
        padding: 24px 24px 32px;
        background: linear-gradient(135deg, var(--bg) 0%, var(--panel) 100%);
        min-height: calc(100vh - 72px);
    }

    .page-title {
        background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end));
        color: #fff;
        border-radius: 16px;
        padding: 24px 20px;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 24px;
        box-shadow: 0 20px 25px -5px rgba(59, 130, 246, .3), 0 10px 10px -5px rgba(0, 0, 0, .04);
        position: relative;
        overflow: hidden;
    }

    .bluebar {
        background: linear-gradient(135deg, var(--blue), var(--blue2));
        color: #fff;
        border-radius: 12px;
        padding: 12px 20px;
        display: flex;
        align-items: center;
        font-weight: 600;
        margin-bottom: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, .1);
    }

    .panel {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, .05), 0 4px 6px -2px rgba(0, 0, 0, .025);
        transition: all .3s ease;
        animation: fadeIn .5s ease-out;
    }

    .panel:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-2px);
    }

    .panel-title {
        font-weight: 700;
        font-size: 18px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        color: var(--text);
        border-bottom: 2px solid var(--border);
        padding-bottom: 12px;
    }

    .panel-title small {
        color: var(--muted);
        font-weight: 500;
        font-size: 14px;
    }

    .crumb {
        margin: 8px 0 16px;
        font-size: 13px;
        color: var(--muted);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .crumb::before {
        content: '📌';
        opacity: .7;
    }

    .toolbar {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .sticky-actions {
        position: sticky !important;
        top: 12px !important;
        bottom: auto !important;
        left: auto !important;
        right: auto !important;
        transform: none !important;
        z-index: 60 !important;
        background: var(--panel) !important;
        padding: 12px 20px !important;
        border-radius: 18px !important;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.16) !important;
        border: 1px solid var(--border) !important;
        display: flex !important;
        gap: 12px !important;
        flex-wrap: wrap !important;
        backdrop-filter: blur(10px) !important;
        width: 100% !important;
        max-width: 100% !important;
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
        margin: 0 0 20px !important;
        justify-content: flex-end !important;
        will-change: auto !important;
    }

    /* Ensure parent containers don't affect positioning */
    .layout,
    .main,
    .view,
    .panel {
        position: relative;
        transform: none !important;
        will-change: auto !important;
    }

    /* Button Colors */
    .sticky-actions .btn {
        padding: 10px 20px;
        border-radius: 30px;
        font-weight: 600;
        font-size: 14px;
        border: none !important;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        color: white;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    /* Publish Exam - Green */
    .sticky-actions .btn.warn {
        background: linear-gradient(135deg, #10b981, #059669) !important;
        border: none !important;
    }

    .sticky-actions .btn.warn:hover {
        background: linear-gradient(135deg, #059669, #047857) !important;
        box-shadow: 0 6px 16px rgba(16, 185, 129, 0.5);
        transform: translateY(-2px);
    }

    /* Preview Exam - Blue */
    .sticky-actions .btn.primary {
        background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
        border: none !important;
    }

    .sticky-actions .btn.primary:hover {
        background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
        box-shadow: 0 6px 16px rgba(59, 130, 246, 0.5);
        transform: translateY(-2px);
    }

    /* Shuffle Questions - Purple */
    .sticky-actions .btn:not(.warn):not(.primary):not(.danger) {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed) !important;
        border: none !important;
    }

    .sticky-actions .btn:not(.warn):not(.primary):not(.danger):hover {
        background: linear-gradient(135deg, #7c3aed, #6d28d9) !important;
        box-shadow: 0 6px 16px rgba(139, 92, 246, 0.5);
        transform: translateY(-2px);
    }

    /* Delete Exam - Red */
    .sticky-actions .btn.danger {
        background: linear-gradient(135deg, #ef4444, #dc2626) !important;
        border: none !important;
    }

    .sticky-actions .btn.danger:hover {
        background: linear-gradient(135deg, #dc2626, #b91c1c) !important;
        box-shadow: 0 6px 16px rgba(239, 68, 68, 0.5);
        transform: translateY(-2px);
    }

    /* Prevent any hover hiding */
    .sticky-actions:hover {
        opacity: 1 !important;
        visibility: visible !important;
    }

    /* Dark theme */
    body.dark .sticky-actions {
        background: rgba(30, 41, 59, 0.95) !important;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6) !important;
        backdrop-filter: blur(10px) !important;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .sticky-actions {
            top: 8px !important;
            padding: 10px 15px !important;
            gap: 8px !important;
            justify-content: stretch !important;
        }

        .sticky-actions .btn {
            padding: 8px 16px !important;
            font-size: 13px !important;
        }
    }

    @media (max-width: 480px) {
        .sticky-actions {
            top: 6px !important;
            padding: 8px 12px !important;
            gap: 6px !important;
            left: auto !important;
            right: auto !important;
            transform: none !important;
            max-width: none !important;
            justify-content: stretch !important;
        }

        .sticky-actions .btn {
            padding: 6px 12px !important;
            font-size: 12px !important;
        }
    }

    /* ============================================ */
    /* UNIFIED BUTTON SYSTEM                        */
    /* ============================================ */

    /* Base Button Style */
    .btn,
    .quick-question-btn,
    .qtype-btn,
    .sticky-actions .btn,
    .action-btn {
        padding: 10px 20px;
        border-radius: 30px;
        font-weight: 600;
        font-size: 14px;
        border: none !important;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        color: white;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    /* ============================================ */
    /* LIGHT THEME - ALL BUTTONS SAME COLOR         */
    /* ============================================ */

    :root,
    body:not(.dark) {
        --btn-gradient-start: #3b82f6;
        --btn-gradient-end: #2563eb;
        --btn-hover-start: #2563eb;
        --btn-hover-end: #1d4ed8;
        --btn-shadow-color: rgba(59, 130, 246, 0.4);
    }

    /* All buttons in light theme - SAME BLUE */
    body:not(.dark) .btn,
    body:not(.dark) .quick-question-btn,
    body:not(.dark) .qtype-btn,
    body:not(.dark) .sticky-actions .btn,
    body:not(.dark) .action-btn,
    body:not(.dark) .btn.primary,
    body:not(.dark) .btn.warn,
    body:not(.dark) .btn.danger,
    body:not(.dark) .btn.success,
    body:not(.dark) .btn.ok,
    body:not(.dark) .quick-question-btn.code,
    body:not(.dark) .qtype-btn.code {
        background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
        color: white !important;
    }

    /* All buttons hover in light theme - SAME DARKER BLUE */
    body:not(.dark) .btn:hover,
    body:not(.dark) .quick-question-btn:hover,
    body:not(.dark) .qtype-btn:hover,
    body:not(.dark) .sticky-actions .btn:hover,
    body:not(.dark) .action-btn:hover,
    body:not(.dark) .btn.primary:hover,
    body:not(.dark) .btn.warn:hover,
    body:not(.dark) .btn.danger:hover,
    body:not(.dark) .btn.success:hover,
    body:not(.dark) .btn.ok:hover,
    body:not(.dark) .quick-question-btn.code:hover,
    body:not(.dark) .qtype-btn.code:hover {
        background: linear-gradient(135deg, #1d4ed8, #1e40af) !important;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4) !important;
        color: white !important;
    }

    /* ============================================ */
    /* DARK THEME - ALL BUTTONS UNIQUE COLOR        */
    /* ============================================ */

    body.dark {
        --btn-gradient-start: #8b5cf6;
        --btn-gradient-end: #7c3aed;
        --btn-hover-start: #7c3aed;
        --btn-hover-end: #6d28d9;
        --btn-shadow-color: rgba(139, 92, 246, 0.4);
    }

    /* All buttons in dark theme - SAME PURPLE */
    body.dark .btn,
    body.dark .quick-question-btn,
    body.dark .qtype-btn,
    body.dark .sticky-actions .btn,
    body.dark .action-btn,
    body.dark .btn.primary,
    body.dark .btn.warn,
    body.dark .btn.danger,
    body.dark .btn.success,
    body.dark .btn.ok,
    body.dark .quick-question-btn.code,
    body.dark .qtype-btn.code {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed) !important;
        color: white !important;
    }

    /* All buttons hover in dark theme - SAME DARKER PURPLE */
    body.dark .btn:hover,
    body.dark .quick-question-btn:hover,
    body.dark .qtype-btn:hover,
    body.dark .sticky-actions .btn:hover,
    body.dark .action-btn:hover,
    body.dark .btn.primary:hover,
    body.dark .btn.warn:hover,
    body.dark .btn.danger:hover,
    body.dark .btn.success:hover,
    body.dark .btn.ok:hover,
    body.dark .quick-question-btn.code:hover,
    body.dark .qtype-btn.code:hover {
        background: linear-gradient(135deg, #6d28d9, #5b21b6) !important;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(139, 92, 246, 0.5) !important;
        color: white !important;
    }

    /* ============================================ */
    /* QUICK QUESTION BUTTONS SPECIFIC              */
    /* ============================================ */

    .quick-question-btn {
        padding: 16px 24px;
        border-radius: 20px;
        min-width: 160px;
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.12);
    }

    /* Quick Question Button - Coding Only */
    .quick-question-btn.code {
        background: linear-gradient(135deg, #10b981, #059669) !important;
    }

    .quick-question-btn.code:hover {
        background: linear-gradient(135deg, #059669, #047857) !important;
    }

    /* ============================================ */
    /* QTYPE BUTTONS SPECIFIC                       */
    /* ============================================ */

    .qtype-btn {
        padding: 12px 20px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .qtype-btn i {
        font-size: 14px;
    }

    /* QType Button - Coding Only */
    .qtype-btn.code {
        background: linear-gradient(135deg, #10b981, #059669) !important;
    }

    .qtype-btn.code:hover {
        background: linear-gradient(135deg, #059669, #047857) !important;
    }

    /* ============================================ */
    /* STICKY ACTIONS (Floating Bottom Bar)         */
    /* ============================================ */

    .sticky-actions {
        position: sticky !important;
        top: 12px !important;
        bottom: auto !important;
        left: auto !important;
        right: auto !important;
        transform: none !important;
        z-index: 60 !important;
        background: var(--panel) !important;
        padding: 12px 20px !important;
        border-radius: 18px !important;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.16) !important;
        border: 1px solid var(--border) !important;
        display: flex !important;
        gap: 12px !important;
        flex-wrap: wrap !important;
        backdrop-filter: blur(10px) !important;
        width: 100% !important;
        max-width: 100% !important;
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
        margin: 0 0 20px !important;
        justify-content: flex-end !important;
    }

    .sticky-actions .btn {
        padding: 10px 20px;
    }

    .sticky-actions:hover {
        opacity: 1 !important;
        visibility: visible !important;
    }

    /* ============================================ */
    /* DARK THEME STICKY ACTIONS BACKGROUND         */
    /* ============================================ */

    body.dark .sticky-actions {
        background: rgba(30, 41, 59, 0.95) !important;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6) !important;
    }

    /* ============================================ */
    /* ACTION BUTTONS (Table Actions)               */
    /* ============================================ */

    .action-btn {
        width: 32px;
        height: 32px;
        padding: 0;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    /* ============================================ */
    /* DISABLED BUTTONS                             */
    /* ============================================ */

    .btn:disabled,
    .quick-question-btn:disabled,
    .qtype-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
    }

    .btn:disabled:hover,
    .quick-question-btn:disabled:hover,
    .qtype-btn:disabled:hover {
        transform: none !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
    }

    /* ============================================ */
    /* RESPONSIVE ADJUSTMENTS                       */
    /* ============================================ */

    @media (max-width: 768px) {
        .sticky-actions {
            top: 8px !important;
            padding: 10px 15px !important;
            gap: 8px !important;
            justify-content: stretch !important;
        }

        .sticky-actions .btn {
            padding: 8px 16px !important;
            font-size: 13px !important;
        }

        .quick-question-btn {
            padding: 12px 18px;
            font-size: 13px;
            min-width: 140px;
        }

        .qtype-btn {
            padding: 10px 16px;
            font-size: 12px;
        }
    }

    @media (max-width: 480px) {
        .sticky-actions {
            top: 6px !important;
            padding: 8px 12px !important;
            gap: 6px !important;
            left: auto !important;
            right: auto !important;
            transform: none !important;
            max-width: none !important;
            justify-content: stretch !important;
        }

        .sticky-actions .btn {
            padding: 6px 12px !important;
            font-size: 12px !important;
        }

        .quick-questions {
            flex-direction: column;
            align-items: center;
        }

        .quick-question-btn {
            width: 100%;
            max-width: 280px;
        }
    }

    @media (max-width: 980px) {

        .sidebar.mobile-open~.main .sticky-actions,
        .sidebar.mobile-open~.layout .main .sticky-actions {
            z-index: 100 !important;
            opacity: 0.5 !important;
            pointer-events: none !important;
        }
    }

    /* ============================================ */
    /* NO QUESTIONS MESSAGE CONTAINER               */
    /* ============================================ */

    .no-questions-container {
        text-align: center;
        padding: 60px 40px;
        background: var(--bg);
        border-radius: 24px;
        border: 2px dashed var(--border);
        margin-top: 20px;
    }

    .quick-questions {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 12px;
        margin-top: 20px;
    }

    /* ============================================ */
    /* QTYPE BUTTON BAR                            */
    /* ============================================ */

    .qtype-button-bar {
        display: none;
        margin-top: 30px;
        padding: 20px;
        background: var(--bg);
        border-radius: 16px;
        border: 1px solid var(--border);
        text-align: center;
    }

    .qtype-button-bar.visible {
        display: block;
    }

    .qtype-button-bar-title {
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 15px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .qtype-buttons {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 10px;
    }

    @media (max-width: 980px) {

        .sidebar.mobile-open~.main .sticky-actions,
        .sidebar.mobile-open~.layout .main .sticky-actions {
            z-index: 100;
            opacity: 0.5;
            pointer-events: none;
        }
    }

    .search {
        display: flex;
        gap: 10px;
        align-items: center;
        flex: 1;
    }

    .search input {
        width: 100%;
        max-width: 400px;
        border: 2px solid var(--border);
        border-radius: 12px;
        padding: 12px 16px;
        outline: none;
        background: var(--input-bg);
        font-size: 14px;
        color: var(--text);
    }

    .search input:focus {
        border-color: var(--blue);
        box-shadow: 0 0 0 4px rgba(2, 132, 199, .1);
        transform: scale(1.01);
    }

    .btn {
        border: 2px solid var(--btn-border);
        background: var(--btn-bg);
        padding: 10px 16px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        color: var(--btn-text);
        box-shadow: 0 2px 4px rgba(0, 0, 0, .05);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, .12);
    }

    .btn.primary {
        border-color: var(--btn-primary-border);
        background: var(--btn-primary-bg);
        color: var(--btn-primary-text);
    }

    .btn.primary:hover {
        background: var(--blue);
        color: #fff;
        border-color: var(--blue);
    }

    .btn.ok {
        border-color: var(--btn-ok-border);
        background: var(--btn-ok-bg);
        color: var(--btn-ok-text);
    }

    .btn.ok:hover {
        background: #22c55e;
        color: #fff;
    }

    .btn.warn {
        border-color: var(--btn-warn-border);
        background: var(--btn-warn-bg);
        color: var(--btn-warn-text);
    }

    .btn.warn:hover {
        background: #f59e0b;
        color: #fff;
    }

    .btn.danger {
        border-color: var(--btn-danger-border);
        background: var(--btn-danger-bg);
        color: var(--btn-danger-text);
    }

    .btn.danger:hover {
        background: #ef4444;
        color: #fff;
    }

    /* Tables */
    .table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        border: 1px solid var(--border);
        border-radius: 16px;
        overflow: hidden;
        background: var(--panel);
    }

    .table th,
    .table td {
        font-size: 14px;
        padding: 14px 16px;
        border-bottom: 1px solid var(--border);
        text-align: left;
        vertical-align: middle;
        color: var(--text);
    }

    .table th {
        background: var(--bg);
        color: var(--text-light);
        font-weight: 600;
        text-transform: uppercase;
        font-size: 12px;
        letter-spacing: .05em;
    }

    .table tbody tr:hover {
        background: var(--bg);
    }

    .tag,
    .pill {
        display: inline-block;
        font-size: 11px;
        padding: 4px 10px;
        border-radius: 999px;
        border: 1px solid var(--border);
        background: var(--bg);
        color: var(--text);
        font-weight: 600;
    }

    .status-published {
        background: var(--status-published-bg) !important;
        color: var(--status-published-text) !important;
        border: none !important;
    }


    .status-locked {
        background: var(--status-locked-bg) !important;
        color: var(--status-locked-text) !important;
        border: none !important;
    }

    .status-active {
        background: var(--status-active-bg) !important;
        color: var(--status-active-text) !important;
        border: none !important;
    }

    .status-inactive {
        background: var(--status-inactive-bg) !important;
        color: var(--status-inactive-text) !important;
        border: none !important;
    }

    .grade-a {
        background: var(--grade-a-bg) !important;
        color: var(--grade-a-text) !important;
        border: none !important;
    }

    .grade-bplus {
        background: var(--grade-bplus-bg) !important;
        color: var(--grade-bplus-text) !important;
        border: none !important;
    }

    .grade-b {
        background: var(--grade-b-bg) !important;
        color: var(--grade-b-text) !important;
        border: none !important;
    }

    .grade-cplus {
        background: var(--grade-cplus-bg) !important;
        color: var(--grade-cplus-text) !important;
        border: none !important;
    }

    .grade-c {
        background: var(--grade-c-bg) !important;
        color: var(--grade-c-text) !important;
        border: none !important;
    }

    .grade-dplus {
        background: var(--grade-dplus-bg) !important;
        color: var(--grade-dplus-text) !important;
        border: none !important;
    }

    .grade-d {
        background: var(--grade-d-bg) !important;
        color: var(--grade-d-text) !important;
        border: none !important;
    }

    .grade-e {
        background: var(--grade-e-bg) !important;
        color: var(--grade-e-text) !important;
        border: none !important;
    }

    .student-row:hover {
        background: var(--bg);
    }

    .action-btn {
        padding: 4px 8px;
        margin: 0 2px;
        font-size: 12px;
    }

    /* Question cards */
    .qcard {
        border: 2px solid var(--border);
        border-radius: 16px;
        padding: 20px;
        background: var(--panel);
        transition: all .3s ease;
        animation: fadeIn .4s ease-out;
        margin-bottom: 16px;
    }

    .qcard:hover {
        border-color: var(--blue);
        box-shadow: var(--shadow-lg);
        transform: translateY(-4px);
    }

    .qhead {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 12px;
    }

    .qtitle {
        font-weight: 700;
        font-size: 16px;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .compulsory-badge {
        background: var(--compulsory-badge);
        color: #fff;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 600;
        margin-left: 8px;
    }

    .qmeta {
        font-size: 12px;
        color: var(--muted);
        margin-top: 4px;
    }

    /* Form fields */
    .field {
        margin-bottom: 16px;
    }

    .field label {
        display: block;
        font-size: 13px;
        color: var(--label-color);
        margin-bottom: 8px;
        font-weight: 600;
        letter-spacing: .02em;
    }

    .field input,
    .field select,
    .field textarea {
        width: 100%;
        border: 2px solid var(--border);
        border-radius: 12px;
        padding: 12px 16px;
        background: var(--input-bg);
        outline: none;
        font-size: 14px;
        color: var(--text);
    }

    .field input:focus,
    .field select:focus,
    .field textarea:focus {
        border-color: var(--blue);
        box-shadow: 0 0 0 4px rgba(2, 132, 199, .1);
        transform: scale(1.01);
    }

    .field textarea {
        min-height: 100px;
        resize: vertical;
    }

    .hint {
        font-size: 12px;
        color: var(--muted);
        margin-top: 8px;
        line-height: 1.5;
        padding: 8px 12px;
        background: var(--bg);
        border-radius: 8px;
        border-left: 4px solid var(--blue);
    }

    .rowgrid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }

    /* Stats / charts */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: var(--panel);
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, .1);
        border: 1px solid var(--border);
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
    }

    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: var(--blue);
        margin: 8px 0;
    }

    .stat-label {
        font-size: 14px;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    .progress {
        height: 8px;
        background: var(--border);
        border-radius: 999px;
        overflow: hidden;
        margin: 8px 0;
    }

    .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, var(--blue), var(--gradient-end));
        border-radius: 999px;
        transition: width .5s ease;
    }

    .chart-container {
        background: var(--chart-bg);
        border-radius: 16px;
        padding: 20px;
        margin-top: 20px;
        border: 1px solid var(--border);
    }

    .charts-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-top: 20px;
    }

    .charts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }

    /* Question type buttons - Coding Only */
    .qtype-group {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
        background: var(--bg);
        padding: 16px;
        border-radius: 16px;
        margin-top: 20px;
        border: 1px solid var(--border);
    }

    .qtype-btn.code {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .qtype-btn:hover,
    .qtype-btn.selected {
        background: var(--blue);
        color: #fff;
        border-color: var(--blue);
    }

    /* No Questions Message */
    .no-questions-container {
        text-align: center;
        padding: 60px 40px;
        background: var(--bg);
        border-radius: 24px;
        border: 2px dashed var(--border);
        margin-top: 20px;
    }

    .no-questions-icon {
        font-size: 64px;
        margin-bottom: 20px;
    }

    .no-questions-title {
        margin-bottom: 12px;
        color: var(--text);
        font-size: 24px;
        font-weight: 700;
    }

    .no-questions-text {
        color: var(--muted);
        margin-bottom: 30px;
        font-size: 14px;
    }

    /* Quick Question Buttons - Coding Only */
    .quick-questions {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 12px;
        margin-top: 20px;
    }

    .quick-question-btn {
        padding: 16px 24px;
        border: none;
        border-radius: 20px;
        color: #fff;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.12);
        min-width: 160px;
        transition: all 0.2s ease;
    }

    .quick-question-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.18);
    }

    .quick-question-btn.code {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    /* QType Button Bar - Appears after first question */
    .qtype-button-bar {
        display: none;
        margin-top: 30px;
        padding: 20px;
        background: var(--bg);
        border-radius: 16px;
        border: 1px solid var(--border);
        text-align: center;
    }

    .qtype-button-bar.visible {
        display: block;
    }

    .qtype-button-bar-title {
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 15px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .qtype-buttons {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 10px;
    }

    .qtype-btn {
        padding: 12px 20px;
        border-radius: 30px;
        border: none;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        color: #fff;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .qtype-btn i {
        font-size: 14px;
    }

    .qtype-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
    }

    .qtype-btn.code {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    /* Questions List Container */
    .questions-container {
        margin-top: 20px;
    }

    #qList {
        display: grid;
        gap: 20px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .quick-question-btn {
            padding: 12px 18px;
            font-size: 13px;
            min-width: 140px;
        }

        .qtype-btn {
            padding: 10px 16px;
            font-size: 12px;
        }
    }

    @media (max-width: 480px) {
        .quick-questions {
            flex-direction: column;
            align-items: center;
        }

        .quick-question-btn {
            width: 100%;
            max-width: 280px;
        }
    }

    /* Modal */
    .modal {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 3000;
        backdrop-filter: blur(4px);
    }

    .modal-content {
        background: var(--panel);
        padding: 24px;
        border-radius: 16px;
        width: 600px;
        max-width: 90%;
        border: 1px solid var(--border);
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-content h3 {
        margin-bottom: 16px;
        color: var(--text);
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
    }




    /* Monitoring / proctoring */
    .student-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }

    .student-monitor-card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px;
        transition: all .3s ease;
    }

    .student-monitor-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, .1);
    }

    .student-monitor-card.warning {
        border-color: var(--warn);
        animation: pulse 2s infinite;
    }

    .student-monitor-card.cheating {
        border-color: var(--danger);
        animation: pulse 1s infinite;
    }

    .student-screen-preview {
        width: 100%;
        height: 200px;
        background: var(--bg);
        border-radius: 8px;
        margin: 10px 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--muted);
        border: 1px solid var(--border);
        overflow: hidden;
        position: relative;
        cursor: pointer;
    }

    .student-screen-preview img,
    .student-screen-preview video {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .screen-recording {
        position: absolute;
        top: 5px;
        right: 5px;
        width: 10px;
        height: 10px;
        background: #ef4444;
        border-radius: 50%;
        animation: blink 1s infinite;
    }

    .screen-timestamp {
        position: absolute;
        bottom: 5px;
        left: 5px;
        background: rgba(0, 0, 0, .7);
        color: #fff;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 10px;
    }

    .activity-timeline {
        height: 4px;
        background: var(--bg);
        border-radius: 2px;
        margin: 10px 0;
        overflow: hidden;
    }

    .activity-bar {
        height: 100%;
        background: var(--blue);
        border-radius: 2px;
        transition: width .3s;
    }

    .live-indicator {
        display: inline-block;
        width: 8px;
        height: 8px;
        background: #22c55e;
        border-radius: 50%;
        margin-right: 4px;
        animation: blink 1s infinite;
    }

    .export-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .marking-scheme-item {
        background: var(--bg);
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 10px;
        border: 1px solid var(--border);
    }

    .marking-scheme-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }


    .drag-handle {
        cursor: grab;
        font-size: 18px;
        user-select: none;
    }

    /* Main responsive */
    @media (max-width: 980px) {
        .main {
            padding: 20px 14px 24px;
        }

        .page-title {
            font-size: 22px;
            padding: 20px 16px;
        }

        .rowgrid,
        .charts-row,
        .student-grid {
            grid-template-columns: 1fr;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .table {
            display: block;
            overflow-x: auto;
        }
    }

    .empty-state {
        text-align: center;
        padding: 40px;
        background: var(--bg);
        border-radius: 16px;
        border: 2px dashed var(--border);
    }

    .empty-icon {
        font-size: 48px;
        margin-bottom: 16px;
    }

    .empty-title {
        margin-bottom: 8px;
        font-size: 18px;
        font-weight: 700;
    }

    .empty-text {
        color: var(--muted);
        margin-bottom: 24px;
        font-size: 14px;
    }

    /* Buttons (clean upgrade) */
    .quick-questions {
        display: flex;
        gap: 14px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .quick-question-btn {
        padding: 16px 24px;
        border: none;
        border-radius: 20px;
        color: #fff;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.12);
        flex: 1;
        min-width: 160px;
    }

    .quick-question-btn.code {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .quick-question-btn.code:hover {
        background: linear-gradient(135deg, #059669, #047857);
    }

    /* ========== DISABLED / READ-ONLY FIELD STYLES ========== */
    /* Light theme */
    input:disabled,
    input[readonly],
    textarea:disabled,
    textarea[readonly],
    select:disabled,
    select[readonly] {
        background-color: #f0f0f0 !important;
        color: #000000 !important;
        cursor: not-allowed;
        opacity: 0.8;
        border-color: #d0d0d0;
    }

    /* Dark theme - Fix for better visibility */
    body.dark input:disabled,
    body.dark input[readonly],
    body.dark textarea:disabled,
    body.dark textarea[readonly],
    body.dark select:disabled,
    body.dark select[readonly] {
        background-color: #2d3748 !important;
        color: #e2e8f0 !important;
        opacity: 1 !important;
        border-color: #4a5568;
    }

    /* Read-only container styling */
    .readonly-field {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 8px 12px;
        border: 1px solid #e0e0e0;
        color: #000000;
    }

    body.dark .readonly-field {
        background-color: #2d3748;
        border-color: #616161;
        color: #000000;
    }

    /* Lock icon styling */
    .lock-icon {
        font-size: 11px;
        margin-left: 6px;
        color: #f59e0b;
    }

    /* Warning box for read-only fields */
    .readonly-warning {
        background: rgba(245, 158, 11, 0.1);
        border-left: 4px solid #f59e0b;
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    body.dark .readonly-warning {
        background: rgba(245, 158, 11, 0.15);
    }

    .readonly-warning i {
        color: #f59e0b;
        font-size: 18px;
    }

    .readonly-warning span {
        color: var(--text-secondary);
        font-size: 13px;
    }

    /* Filter bar styles */
    .filter-bar {
        background: var(--bg);
        border-radius: 12px;
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid var(--border);
    }

    .filter-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .filter-actions {
        display: flex;
        gap: 10px;
        margin-top: 15px;
        flex-wrap: wrap;
    }

    /* Student table styles */
    .student-row td {
        vertical-align: middle;
    }

    .action-buttons {
        display: flex;
        gap: 6px;
        white-space: nowrap;
    }

    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: 1px solid var(--border);
        background: var(--card-bg);
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .action-btn:hover {
        background: var(--accent-blue);
        color: white;
        border-color: var(--accent-blue);
    }

    /* Status badges */
    .status-active {
        background: rgba(16, 185, 129, 0.15) !important;
        color: #10b981 !important;
    }

    .status-inactive {
        background: rgba(239, 68, 68, 0.15) !important;
        color: #ef4444 !important;
    }

    /* Colored Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 20px;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--card-color);
    }

    .stat-card:hover {
        transform: translateY(-5px) scale(1.02);
        box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.25);
    }

    .stat-card .stat-icon {
        font-size: 32px;
        opacity: 0.15;
        position: absolute;
        bottom: 15px;
        right: 15px;
        transition: all 0.3s;
    }

    .stat-card:hover .stat-icon {
        opacity: 0.25;
        transform: scale(1.1);
    }

    .stat-value {
        font-size: 36px;
        font-weight: 800;
        margin: 8px 0;
        position: relative;
        z-index: 1;
    }

    .stat-label {
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
        position: relative;
        z-index: 1;
    }

    .stat-card small {
        font-size: 11px;
        opacity: 0.8;
        position: relative;
        z-index: 1;
    }

    .progress {
        height: 6px;
        background: rgba(0, 0, 0, 0.1);
        border-radius: 3px;
        overflow: hidden;
        margin: 10px 0;
        position: relative;
        z-index: 1;
    }

    .progress-bar {
        height: 100%;
        border-radius: 3px;
        transition: width 1s ease-out;
    }

    /* Card Colors */
    .stat-card.blue {
        --card-color: #3b82f6;
    }

    .stat-card.blue .stat-value {
        color: #3b82f6;
    }

    .stat-card.blue .progress-bar {
        background: linear-gradient(90deg, #3b82f6, #60a5fa);
    }

    .stat-card.green {
        --card-color: #10b981;
    }

    .stat-card.green .stat-value {
        color: #10b981;
    }

    .stat-card.green .progress-bar {
        background: linear-gradient(90deg, #10b981, #34d399);
    }

    .stat-card.purple {
        --card-color: #8b5cf6;
    }

    .stat-card.purple .stat-value {
        color: #8b5cf6;
    }

    .stat-card.purple .progress-bar {
        background: linear-gradient(90deg, #8b5cf6, #a78bfa);
    }

    .stat-card.orange {
        --card-color: #f59e0b;
    }

    .stat-card.orange .stat-value {
        color: #f59e0b;
    }

    .stat-card.orange .progress-bar {
        background: linear-gradient(90deg, #f59e0b, #fbbf24);
    }

    /* Dark theme adjustments */
    body.dark .stat-card {
        background: var(--panel);
        border-color: var(--border);
    }

    body.dark .progress {
        background: rgba(255, 255, 255, 0.1);
    }

    /* Animated number counter */
    @keyframes countUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .count-animation {
        animation: countUp 0.6s ease-out;
    }

    /* Compact Table Styles */
    .stat-card table {
        width: 100%;
        font-size: 12px;
    }

    .stat-card table th,
    .stat-card table td {
        white-space: nowrap;
    }

    .stat-card table th {
        font-weight: 600;
        color: var(--text-secondary);
    }

    @media (max-width: 768px) {
        .stat-card table {
            font-size: 10px;
        }

        .stat-card table th,
        .stat-card table td {
            padding: 6px 3px;
        }
    }

    /* Visibility Toggle Button Styles */
    .visibility-btn {
        background: var(--success);
        border: none;
        width: 90px;
        padding: 8px 12px;
        border-radius: 30px;
        cursor: pointer;
        color: white;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: all 0.3s ease;
        font-size: 12px;
    }

    .visibility-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .visibility-btn:active {
        transform: translateY(0);
    }

    .visibility-btn.visible {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .visibility-btn.hidden {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }

    .visibility-btn i {
        font-size: 14px;
    }

    /* Enrolled Students Table Container */
    #enrolledStudentsListContainer {
        margin-top: 15px;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid var(--border);
        background: var(--panel);
    }

    #enrolledStudentsTable {
        width: 100%;
        border-collapse: collapse;
    }

    #enrolledStudentsTable th {
        background: var(--bg);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        color: var(--text);
        border-bottom: 2px solid var(--border);
    }

    #enrolledStudentsTable td {
        padding: 12px;
        border-bottom: 1px solid var(--border);
        color: var(--text);
    }

    #enrolledStudentsTable tr:hover {
        background: var(--bg);
    }

    /* Toggle Button Style */
    #toggleStudentListBtn {
        background: linear-gradient(135deg, #4f46e5, #06b6d4);
        border: none;
        padding: 10px 20px;
        border-radius: 30px;
        color: white;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-bottom: 10px;
    }

    #toggleStudentListBtn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(79, 70, 229, 0.4);
    }

    /* Marks Input Styling - Clean and consistent for both themes */
    .marks-input,
    .subquestion-marks {
        transition: all 0.2s ease;
    }

    .marks-input:focus,
    .subquestion-marks:focus {
        outline: none;
        border-color: var(--blue) !important;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    /* Light theme specific */
    :root .marks-input,
    :root .subquestion-marks {
        background-color: #ffffff;
        border-color: #e2e8f0;
    }

    /* Dark theme specific */
    body.dark .marks-input,
    body.dark .subquestion-marks {
        background-color: #1e293b;
        border-color: #475569;
        color: #f8fafc;
    }

    /* Number input spinner styling */
    .marks-input[type="number"],
    .subquestion-marks[type="number"] {
        appearance: textfield;
        -moz-appearance: textfield;
    }

    .marks-input[type="number"]::-webkit-inner-spin-button,
    .marks-input[type="number"]::-webkit-outer-spin-button,
    .subquestion-marks[type="number"]::-webkit-inner-spin-button,
    .subquestion-marks[type="number"]::-webkit-outer-spin-button {
        opacity: 0.5;
    }

    .marks-input[type="number"]:hover::-webkit-inner-spin-button,
    .subquestion-marks[type="number"]:hover::-webkit-inner-spin-button {
        opacity: 1;
    }

    /* Coding Question Card Styles */
    .coding-question-card {
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .coding-question-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }

    .subquestion-item {
        transition: all 0.2s ease;
    }

    .subquestion-item:hover {
        border-color: var(--blue) !important;
    }

    /* Empty state styling */
    .empty-subquestions {
        transition: all 0.2s ease;
    }

    .empty-subquestions:hover {
        border-color: var(--blue) !important;
        background: rgba(59, 130, 246, 0.05);
    }

    /* Code editor styling */
    .code-editor {
        font-family: 'Courier New', 'Fira Code', monospace;
        font-size: 13px;
        line-height: 1.5;
        tab-size: 4;
    }

    .code-editor:focus {
        outline: none;
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    /* Question ID badge */
    .question-id-badge {
        font-family: monospace;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .coding-question-card {
            padding: 16px !important;
        }

        .subquestion-item>div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }

        .subquestion-item .btn {
            margin-top: 5px;
        }
    }

    /* ============================================ */
    /* MARKS INPUT FIELD - THEME COMPATIBLE         */
    /* ============================================ */

    .qmeta input[type="number"] {
        width: 70px !important;
        padding: 6px 10px !important;
        border-radius: 8px !important;
        border: 1px solid var(--border) !important;
        background: var(--input-bg) !important;
        color: var(--text) !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        text-align: center !important;
    }

    .qmeta input[type="number"]:focus {
        border-color: var(--blue) !important;
        outline: none !important;
        box-shadow: 0 0 0 3px rgba(89, 125, 225, 0.1) !important;
    }

    .qmeta input[type="number"]:hover {
        border-color: var(--blue) !important;
    }

    /* Dark theme specific */
    body.dark .qmeta input[type="number"] {
        background: var(--input-bg) !important;
        color: var(--text) !important;
        border-color: var(--border) !important;
    }

    /* Remove spinner buttons for cleaner look */
    .qmeta input[type="number"]::-webkit-inner-spin-button,
    .qmeta input[type="number"]::-webkit-outer-spin-button {
        opacity: 0.5;
        height: 20px;
    }

    .qmeta input[type="number"]:hover::-webkit-inner-spin-button,
    .qmeta input[type="number"]:hover::-webkit-outer-spin-button {
        opacity: 1;
    }

    /* ============================================ */
    /* CODING QUESTION - COMPLETE STYLES            */
    /* ============================================ */

    .coding-question-card {
        background: var(--panel);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 20px;
        border: 2px solid var(--border);
    }

    .coding-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--border);
        flex-wrap: wrap;
        gap: 10px;
    }

    .coding-header-left {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .coding-badge {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .coding-question-id {
        background: var(--bg);
        color: var(--text);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-family: monospace;
        border: 1px solid var(--border);
    }

    .coding-marks-badge {
        background: var(--blue);
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    /* Code editor container */
    .code-editor-container {
        background: #1e1e1e;
        border-radius: 12px;
        overflow: hidden;
        margin-top: 8px;
    }

    .code-editor-header {
        background: #2d2d2d;
        padding: 8px 16px;
        border-bottom: 1px solid #3d3d3d;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .code-editor-language {
        color: #858585;
        font-size: 12px;
        font-family: monospace;
    }

    .code-editor-copy {
        background: none;
        border: none;
        color: #858585;
        cursor: pointer;
        font-size: 12px;
    }

    .code-editor-copy:hover {
        color: #10b981;
    }

    .code-editor-area {
        width: 100%;
        padding: 16px;
        background: #1e1e1e;
        color: #d4d4d4;
        font-family: 'Courier New', 'Fira Code', monospace;
        font-size: 13px;
        line-height: 1.5;
        border: none;
        resize: vertical;
        min-height: 200px;
    }

    .code-editor-area:focus {
        outline: none;
    }

    /* Sub-questions section */
    .subquestions-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .subquestion-item {
        background: var(--bg);
        border-radius: 12px;
        padding: 16px;
        border: 1px solid var(--border);
    }

    .subquestion-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .subquestion-prefix {
        font-weight: 700;
        font-size: 16px;
        color: var(--blue);
        background: rgba(59, 130, 246, 0.1);
        padding: 4px 12px;
        border-radius: 20px;
    }

    .subquestion-id {
        font-size: 11px;
        color: var(--muted);
        font-family: monospace;
    }

    /* Test cases section */
    .test-cases-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .test-case-item {
        display: flex;
        gap: 10px;
        align-items: center;
        background: var(--bg);
        padding: 12px;
        border-radius: 8px;
        flex-wrap: wrap;
    }

    .test-case-input {
        flex: 2;
        min-width: 150px;
    }

    .test-case-input input {
        width: 100%;
        padding: 8px 10px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--input-bg);
        color: var(--text);
    }

    .test-case-expected {
        flex: 2;
        min-width: 150px;
    }

    .test-case-expected input {
        width: 100%;
        padding: 8px 10px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--input-bg);
        color: var(--text);
    }

    .test-case-marks {
        width: 100px;
    }

    .test-case-marks input {
        width: 100%;
        padding: 8px 10px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--input-bg);
        color: var(--text);
        text-align: center;
    }

    /* Preview section */
    .coding-preview-section {
        margin-top: 20px;
        padding: 16px;
        background: var(--bg);
        border-radius: 12px;
        border: 1px dashed var(--border);
    }

    .coding-preview-title {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
        color: var(--muted);
        font-size: 13px;
    }

    /* Grading note */
    .coding-grading-note {
        margin-top: 16px;
        padding: 12px;
        background: rgba(16, 185, 129, 0.08);
        border-radius: 8px;
        border-left: 4px solid #10b981;
        font-size: 13px;
        color: var(--text);
    }

    .coding-grading-note i {
        color: #10b981;
        margin-right: 8px;
    }

    /* Student view */
    .coding-student-container {
        background: var(--panel);
        border-radius: 16px;
        padding: 24px;
        border: 1px solid var(--border);
    }

    .coding-student-code-area {
        margin-top: 16px;
    }

    .coding-student-code-area textarea {
        width: 100%;
        padding: 16px;
        border-radius: 12px;
        border: 2px solid var(--border);
        background: #1e1e1e;
        color: #d4d4d4;
        font-family: 'Courier New', monospace;
        font-size: 13px;
        line-height: 1.5;
        resize: vertical;
        min-height: 200px;
    }

    .coding-student-code-area textarea:focus {
        border-color: var(--blue);
        outline: none;
    }

    /* Marks input wrapper */
    .marks-input-wrapper {
        max-width: 200px;
    }

    /* ============================================ */
    /* COMPULSORY FIELD STYLES - RED ASTERISK       */
    /* ============================================ */
    .required-field label::after {
        content: " *";
        color: #ef4444;
        font-weight: bold;
    }

    .field.required label::after {
        content: " *";
        color: #ef4444;
        font-weight: bold;
    }

    .validation-error {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2) !important;
    }

    .validation-message {
        color: #ef4444;
        font-size: 11px;
        margin-top: 4px;
        display: block;
    }

    /* Required field indicator */
    .required-star {
        color: #ef4444;
        margin-left: 4px;
        font-size: 14px;
    }

    .field.required .field-label {
        display: inline-flex;
        align-items: center;
    }

    .field.required .field-label:after {
        content: " *";
        color: #ef4444;
        font-weight: bold;
    }


    .status-completed {
        background: linear-gradient(135deg, #10b981, #059669) !important;
        color: #ffffff !important;
        border: none !important;
    }

    .status-scheduled {
        background: linear-gradient(135deg, #f59e0b, #d97706) !important;
        color: #ffffff !important;
        border: none !important;
    }

    .status-ongoing {
        background: linear-gradient(135deg, #ef4444, #dc2626) !important;
        color: #ffffff !important;
        border: none !important;
        animation: pulse 2s infinite;
    }

    .status-published {
        background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
        color: #ffffff !important;
        border: none !important;
    }

    .status-draft {
        background: linear-gradient(135deg, #6b7280, #4b5563) !important;
        color: #ffffff !important;
        border: none !important;
    }

    @keyframes pulse {
        0% {

            opacity: 1;
        }

        50% {
            opacity: 0.7;
        }

        100% {
            opacity: 1;
        }
    }

    /* Proctoring Grid Styles */
    .student-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 16px;
        margin-top: 20px;
    }

    .student-proctor-card {
        background: var(--panel);
        border-radius: 16px;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
        cursor: pointer;
        border: 2px solid transparent;
    }

    .student-proctor-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.25);
    }

    .screen-preview-container {
        position: relative;
        background: #1e1e1e;
        aspect-ratio: 16 / 9;
        height: auto;
        min-height: 170px;
        overflow: hidden;
    }

    .screen-preview-container img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .live-badge,
    .violation-badge {
        position: absolute;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        z-index: 10;
    }

    .live-badge {
        top: 10px;
        right: 10px;
        background: #ef4444;
        color: white;
    }

    .live-badge i {
        font-size: 8px;
        margin-right: 4px;
        animation: pulse 1s infinite;
    }

    .violation-badge {
        top: 10px;
        left: 10px;
        background: #ef4444;
        color: white;
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }
    }

    /* Full screen modal */
    #fullScreenProctorModal .modal-content {
        padding: 0;
        background: var(--panel);
    }

    #fullScreenContent {
        background: #000;
        min-height: 500px;
    }


    /* Import/Export Button Styles */
    .btn.success {
        background: linear-gradient(135deg, #10b981, #059669) !important;
        color: white !important;
        border: none !important;
    }

    .btn.success:hover {
        background: linear-gradient(135deg, #059669, #047857) !important;
        transform: translateY(-2px);
    }

    .btn.info {
        background: linear-gradient(135deg, #06b6d4, #0891b2) !important;
        color: white !important;
        border: none !important;
    }

    .btn.info:hover {
        background: linear-gradient(135deg, #0891b2, #0e7490) !important;
        transform: translateY(-2px);
    }

    /* Progress bar styling */
    #importProgressBar {
        transition: width 0.3s ease;
        background: linear-gradient(90deg, #3b82f6, #8b5cf6);
    }

    #importResults {
        max-height: 300px;
        overflow-y: auto;
        font-size: 13px;
    }

    #importResults ul {
        margin: 5px 0 0 20px;
    }

    #importResults li {
        margin: 2px 0;
    }

    .status-pending {
        background: linear-gradient(135deg, #f59e0b, #d97706) !important;
        color: white !important;
        border: none !important;
    }

    .status-auto {
        background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
        color: white !important;
        border: none !important;
    }

    .btn.small {
        padding: 6px 12px;
        font-size: 12px;
        margin: 2px;
    }

    .btn.info {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed) !important;
        color: white !important;
    }

    /* Submissions Table Styles */
    .submissions-table {
        width: 100%;
        border-collapse: collapse;
    }

    .submissions-table th {
        background: var(--bg);
        padding: 12px 16px;
        text-align: left;
        font-weight: 600;
        color: var(--text);
        border-bottom: 2px solid var(--border);
    }

    .submissions-table td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        color: var(--text);
    }

    .submissions-table tr:hover {
        background: var(--bg);
    }

    .course-group {
        animation: fadeIn 0.3s ease-out;
    }

    /* Grade badges */
    .grade-a {
        background: linear-gradient(135deg, #10b981, #059669) !important;
        color: white !important;
    }

    .grade-bplus {
        background: linear-gradient(135deg, #34d399, #10b981) !important;
        color: white !important;
    }

    .grade-b {
        background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
        color: white !important;
    }

    .grade-cplus {
        background: linear-gradient(135deg, #f59e0b, #d97706) !important;
        color: white !important;
    }

    .grade-c {
        background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
        color: white !important;
    }

    .grade-dplus {
        background: linear-gradient(135deg, #f97316, #ea580c) !important;
        color: white !important;
    }

    .grade-d {
        background: linear-gradient(135deg, #ef4444, #dc2626) !important;
        color: white !important;
    }

    .grade-e {
        background: linear-gradient(135deg, #6b7280, #4b5563) !important;
        color: white !important;
    }

    .status-auto {
        background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
        color: white !important;
    }

    .status-pending {
        background: linear-gradient(135deg, #f59e0b, #d97706) !important;
        color: white !important;
    }

    .status-published {
        background: linear-gradient(135deg, #10b981, #059669) !important;
        color: white !important;
    }

    /* Course Cards Styles - Add to your existing CSS */
    .courses-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
        gap: 24px;
        margin-top: 20px;
    }

    .course-card {
        background: var(--panel);
        border-radius: 20px;
        overflow: hidden;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 2px solid transparent;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        position: relative;
    }

    .course-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.25);
    }

    .course-card.level-100 {
        border-top-color: #10b981;
    }

    .course-card.level-200 {
        border-top-color: #f59e0b;
    }

    .course-card.level-300 {
        border-top-color: #8b5cf6;
    }

    .course-card.level-400 {
        border-top-color: #ef4444;
    }

    .course-card.level-500 {
        border-top-color: #06b6d4;
    }

    .course-card-header {
        background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end));
        padding: 20px;
        color: white;
    }

    .course-card-stats {
        display: flex;
        justify-content: space-between;
        padding: 20px;
        border-bottom: 1px solid var(--border);
    }

    .course-stat {
        text-align: center;
        flex: 1;
    }

    .course-stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--gradient-start);
    }

    .course-stat-label {
        font-size: 11px;
        color: var(--muted);
        text-transform: uppercase;
        margin-top: 5px;
    }

    .course-card-footer {
        padding: 15px 20px;
        background: var(--bg);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .back-to-courses-btn {
        margin-bottom: 20px;
        padding: 10px 20px;
        border-radius: 30px;
        background: linear-gradient(135deg, #6b7280, #4b5563);
        color: white;
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.2s;
    }

    .back-to-courses-btn:hover {
        transform: translateX(-3px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .course-search-bar {
        background: var(--bg);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        border: 1px solid var(--border);
    }

    .course-level-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .level-100-badge {
        background: #10b981;
        color: white;
    }

    .level-200-badge {
        background: #f59e0b;
        color: white;
    }

    .level-300-badge {
        background: #8b5cf6;
        color: white;
    }

    .level-400-badge {
        background: #ef4444;
        color: white;
    }

    .level-500-badge {
        background: #06b6d4;
        color: white;
    }


    /* Course Cards Styles */
    .courses-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 24px;
        margin-top: 20px;
    }

    .course-card {
        background: var(--panel);
        border-radius: 20px;
        overflow: hidden;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 2px solid transparent;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .course-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.25);
    }

    .course-card.level-100 {
        border-top-color: #10b981;
    }

    .course-card.level-200 {
        border-top-color: #f59e0b;
    }

    .course-card.level-300 {
        border-top-color: #8b5cf6;
    }

    .course-card.level-400 {
        border-top-color: #ef4444;
    }

    .course-card.level-500 {
        border-top-color: #06b6d4;
    }

    .course-card-header {
        background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end));
        padding: 20px;
        color: white;
        border-radius: 18px 18px 0 0;
    }

    .course-card-stats {
        display: flex;
        justify-content: space-between;
        padding: 20px;
        border-bottom: 1px solid var(--border);
    }

    .course-stat {
        text-align: center;
        flex: 1;
    }

    .course-stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--gradient-start);
    }

    .course-stat-label {
        font-size: 11px;
        color: var(--muted);
        text-transform: uppercase;
        margin-top: 5px;
    }

    .course-card-footer {
        padding: 15px 20px;
        background: var(--bg);
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-radius: 0 0 18px 18px;
    }

    .back-to-courses-btn {
        margin-bottom: 20px;
        padding: 10px 20px;
        border-radius: 30px;
        background: linear-gradient(135deg, #6b7280, #4b5563);
        color: white;
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.2s;
    }

    .back-to-courses-btn:hover {
        transform: translateX(-3px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .course-search-bar {
        background: var(--bg);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        border: 1px solid var(--border);
    }

    .course-level-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .level-100-badge {
        background: #10b981;
        color: white;
    }

    .level-200-badge {
        background: #f59e0b;
        color: white;
    }

    .level-300-badge {
        background: #8b5cf6;
        color: white;
    }

    .level-400-badge {
        background: #ef4444;
        color: white;
    }

    .level-500-badge {
        background: #06b6d4;
        color: white;
    }


    /* Dashboard Course Selector */
    .dashboard-course-selector {
        background: var(--panel);
        border-radius: 12px;
        padding: 15px 20px;
        margin-bottom: 20px;
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }

    .dashboard-course-selector label {
        font-weight: 600;
        color: var(--text);
    }

    .dashboard-course-selector select {
        padding: 10px 15px;
        border-radius: 10px;
        border: 2px solid var(--border);
        background: var(--input-bg);
        color: var(--text);
        min-width: 250px;
        cursor: pointer;
    }

    .dashboard-course-selector button {
        padding: 10px 20px;
        border-radius: 10px;
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.2s;
    }

    .dashboard-course-selector button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    /* Course Stats Modal */
    .course-stats-modal .modal-content {
        width: 700px;
        max-width: 90%;
    }

    .course-stats-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }

    .course-stats-table th,
    .course-stats-table td {
        padding: 10px;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }

    .course-stats-table th {
        background: var(--bg);
        font-weight: 600;
    }

    .course-stats-table tr:hover {
        background: var(--bg);
    }

    .course-stat-score {
        font-weight: 700;
        color: #3b82f6;
    }

    .course-stat-pass {
        font-weight: 700;
        color: #10b981;
    }

    .submenu-overlay {
        position: fixed;
        top: 72px;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1399;
        display: none;
    }

    .submenu-overlay.active {
        display: block;
    }

    /* Fix for submissions table - make all text visible */
    #submissionsContainer table,
    #resultsTableContainer table,
    .submissions-table {
        width: 100%;
        border-collapse: collapse;
        background: var(--panel);
    }

    /* Table headers - ensure text is visible */
    #submissionsContainer th,
    #resultsTableContainer th,
    .submissions-table th {
        border: 1px solid #000 !important;
        padding: 12px !important;
        text-align: center !important;
        font-weight: bold !important;
        background-color: #f0f0f0 !important;
        color: #000000 !important;
    }

    body.dark #submissionsContainer th,
    body.dark #resultsTableContainer th,
    body.dark .submissions-table th {
        background-color: #334155 !important;
        color: #ffffff !important;
    }

    /* Table cells - ensure text is visible */
    #submissionsContainer td,
    #resultsTableContainer td,
    .submissions-table td {
        border: 1px solid #000 !important;
        padding: 10px !important;
        background: var(--panel) !important;
        color: var(--text) !important;
    }

    body.dark #submissionsContainer td,
    body.dark #resultsTableContainer td,
    body.dark .submissions-table td {
        background: #1e293b !important;
        color: #f8fafc !important;
    }

    /* Student ID column - remove special background */
    #submissionsContainer td code,
    #resultsTableContainer td code,
    .submissions-table td code {
        background: transparent !important;
        padding: 0 !important;
        color: inherit !important;
        font-family: monospace;
        font-size: 13px;
    }

    body.dark #submissionsContainer td code,
    body.dark #resultsTableContainer td code,
    body.dark .submissions-table td code {
        background: transparent !important;
        color: #f8fafc !important;
    }

    /* Remove any background from table rows */
    #submissionsContainer tr,
    #resultsTableContainer tr,
    .submissions-table tr {
        background: transparent !important;
    }

    /* Hover effect - subtle */
    #submissionsContainer tbody tr:hover td,
    #resultsTableContainer tbody tr:hover td,
    .submissions-table tbody tr:hover td {
        background: rgba(59, 130, 246, 0.1) !important;
    }

    body.dark #submissionsContainer tbody tr:hover td,
    body.dark #resultsTableContainer tbody tr:hover td,
    body.dark .submissions-table tbody tr:hover td {
        background: rgba(59, 130, 246, 0.2) !important;
    }

    .code-editor-shell {
        border: 1px solid #334155;
        border-radius: 12px;
        overflow: hidden;
        background: #0f172a;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,.04);
    }

    .code-editor-titlebar {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 8px 12px;
        background: #111827;
        color: #cbd5e1;
        font-size: 12px;
        border-bottom: 1px solid #334155;
    }

    .code-editor-grid {
        display: grid;
        grid-template-columns: 52px 1fr;
        min-height: 280px;
    }

    .code-line-numbers {
        margin: 0;
        padding: 12px 8px;
        background: #020617;
        color: #64748b;
        text-align: right;
        font: 13px/1.5 Consolas, "Courier New", monospace;
        user-select: none;
        overflow: hidden;
    }

    .starter-code-input {
        width: 100%;
        min-height: 280px;
        padding: 12px;
        border: 0;
        border-radius: 0;
        background: #0f172a;
        color: #d4d4d4;
        font: 13px/1.5 Consolas, "Courier New", monospace;
        resize: vertical;
        outline: none;
        tab-size: 4;
    }

    .starter-code-input::selection {
        background: rgba(59,130,246,.35);
    }

    #proctoringGrid {
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)) !important;
    }

    .student-proctor-card {
        min-width: 0;
    }

    .responsive-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    @media (max-width: 1024px) {
        .rowgrid,
        .stats-grid,
        .dashboard-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }

        .toolbar,
        .sticky-actions {
            flex-wrap: wrap;
        }
    }

    @media (max-width: 720px) {
        body {
            overflow-x: hidden;
        }

        main,
        .main-content,
        .content {
            width: 100%;
            max-width: 100vw;
        }

        .panel {
            padding: 14px !important;
            border-radius: 10px !important;
        }

        .rowgrid,
        .stats-grid,
        .dashboard-grid,
        #proctoringGrid {
            grid-template-columns: 1fr !important;
        }

        .toolbar,
        .sticky-actions,
        .modal-actions {
            display: grid !important;
            grid-template-columns: 1fr;
            width: 100%;
        }

        .toolbar .btn,
        .sticky-actions .btn,
        .toolbar select,
        .toolbar input {
            width: 100%;
            min-width: 0 !important;
        }

        .header-center {
            display: none;
        }

        table {
            min-width: 680px;
        }

        .table {
            display: block;
            overflow-x: auto;
        }

        .code-editor-grid {
            grid-template-columns: 42px 1fr;
        }

        .code-line-numbers,
        .starter-code-input {
            font-size: 12px;
        }
    }
    </style>
</head>

<body>
    <div class="header-bar">
        <div class="header-left">
            <button class="mobile-menu-btn" onclick="toggleMobileSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <div class="header-logo">
                <span>Q</span>
            </div>
            <div class="header-typing">QODA PU</div>
        </div>
        <div class="header-center">
            <div class="header-search">
                <i class="fas fa-search"></i>
                <input type="text" id="globalSearch" placeholder="Search students, exams..."
                    onkeyup="handleSearchEnter(event)">
                <div class="search-results" id="searchResults"></div>
            </div>
            <button class="header-search-btn" onclick="executeSearch()"><i
                    class="fas fa-search"></i><span>Search</span></button>
        </div>
        <div class="header-right">
            <div class="header-theme" onclick="toggleTheme()"><i class="fas fa-moon" id="themeIcon"></i></div>
            <div class="header-logout" onclick="logout()"><i class="fas fa-sign-out-alt"></i><span>Logout</span></div>
        </div>
    </div>

    <div class="layout">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-close-btn" onclick="closeMobileSidebar()">
                <i class="fas fa-times"></i>
            </div>

            <div class="sidebar-top">
                <div class="profile-icon" onclick="handleNavClick(this, 'profile')">
                    <?php if (!empty($lecturerData['profile_pic'])): ?>
                    <img src="<?php echo htmlspecialchars($lecturerData['profile_pic']); ?>" alt="Profile">
                    <?php else: ?>
                    <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-icon" data-tooltip="Dashboard" onclick="handleNavClick(this, 'dashboard')">
                    <i class="fas fa-home"></i>
                    <span class="tooltip-text">Dashboard</span>
                </div>

                <div class="nav-icon has-submenu" data-submenu="examSubmenu" onclick="toggleSubmenuPanel(this)">
                    <i class="fas fa-file-alt"></i>
                    <span class="tooltip-text">Exam Management</span>
                </div>

                <div class="nav-icon has-submenu" data-submenu="studentSubmenu" onclick="toggleSubmenuPanel(this)">
                    <i class="fas fa-users"></i>
                    <span class="tooltip-text">Student Management</span>
                </div>

                <div class="nav-icon has-submenu" data-submenu="monitorSubmenu" onclick="toggleSubmenuPanel(this)">
                    <i class="fas fa-eye"></i>
                    <span class="tooltip-text">Monitoring</span>
                </div>

                <div class="nav-icon has-submenu" data-submenu="accountSubmenu" onclick="toggleSubmenuPanel(this)">
                    <i class="fas fa-user-circle"></i>
                    <span class="tooltip-text">Account</span>
                </div>
            </nav>

            <div class="sidebar-bottom">
                <div class="theme-switch-icon" onclick="toggleTheme()">
                    <i class="fas fa-palette"></i>
                    <span class="tooltip-text">Switch Theme</span>
                </div>
                <div class="logout-icon" onclick="logout()">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="tooltip-text">Logout</span>
                </div>
            </div>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

        <div class="submenu-panel" id="submenuPanel">
            <div class="submenu-header">
                <h3 id="submenuTitle">Menu</h3>
                <i class="fas fa-times" onclick="closeSubmenuPanel()"></i>
            </div>
            <div class="submenu-content" id="submenuContent"></div>
            <div class="submenu-profile-info">
                <div class="profile-avatar-small">
                    <?php if (!empty($lecturerData['profile_pic'])): ?>
                    <img src="<?php echo htmlspecialchars($lecturerData['profile_pic']); ?>" alt="Profile">
                    <?php else: ?>
                    <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>
                <div class="profile-details">
                    <div class="profile-name">
                        <?php echo isset($lecturerData['full_name']) ? htmlspecialchars($lecturerData['full_name']) : 'Not Assigned'; ?>
                    </div>
                    <div class="profile-staff">Staff ID:
                        <?php echo isset($lecturerData['staff_id']) ? htmlspecialchars($lecturerData['staff_id']) : '—'; ?>
                    </div>
                    <div class="profile-dept">Dept:
                        <?php echo isset($lecturerData['department']) ? htmlspecialchars($lecturerData['department']) : '—'; ?>
                    </div>
                </div>
            </div>
            <div class="submenu-bottom">
                <div class="signed-in-info">
                    <div class="signed-in-label">Signed in as</div>
                    <div class="signed-in-role">Lecturer</div>
                </div>
            </div>
        </div>

        <main class="main">
            <div class="page-title">📚 Lecturer Exam Management</div>

            <div class="bluebar" id="bluebarTitle">
                <span style="margin-left:0">🏠 Dashboard</span>
            </div>
            <div class="submenu-overlay" id="submenuOverlay" onclick="closeSubmenuPanel()"></div>
            <!-- DASHBOARD -->
            <section id="view-dashboard" class="view active">
                <div class="panel">
                    <div class="panel-title">📊 Dashboard Overview <small>Real-time statistics</small></div>

                    <!-- Course Selector -->
                    <div class="dashboard-course-selector">
                        <label><i class="fas fa-chart-line"></i> View Analytics For:</label>
                        <select id="dashboardCourseSelect">
                            <option value="all">All Courses (Overall)</option>
                        </select>
                        <button onclick="updateDashboardWithCourse()">
                            <i class="fas fa-sync-alt"></i> Apply & Update
                        </button>
                        <button onclick="refreshAllDashboardStats()"
                            style="background: linear-gradient(135deg, #6b7280, #4b5563);">
                            <i class="fas fa-redo-alt"></i> Refresh All
                        </button>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card blue" onclick="showCourseStatsModal()" style="cursor: pointer;">
                            <div class="stat-icon"><i class="fas fa-users"></i></div>
                            <div class="stat-label">Total Students</div>
                            <div class="stat-value" id="statTotalStudents">0</div>
                            <div class="progress">
                                <div class="progress-bar" id="studentProgressBar" style="width:0%"></div>
                            </div>
                            <small id="statActiveStudents">Active: 0 | Inactive: 0</small>
                        </div>
                        <div class="stat-card green" onclick="go('exams')" style="cursor: pointer;">
                            <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                            <div class="stat-label">Total Exams</div>
                            <div class="stat-value" id="statTotalExams">0</div>
                            <div class="progress">
                                <div class="progress-bar" id="examProgressBar" style="width:0%"></div>
                            </div>
                            <small id="statExamStatus">Published: 0 </small>
                        </div>
                        <div class="stat-card purple" onclick="showSubmissionsStatsModal()" style="cursor: pointer;">
                            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="stat-label">Submissions</div>
                            <div class="stat-value" id="statTotalSubmissions">0</div>
                            <div class="progress">
                                <div class="progress-bar" id="submissionProgressBar" style="width:0%"></div>
                            </div>
                            <small id="statSubmissionStatus">Marked: 0 | Pending: 0</small>
                        </div>
                        <div class="stat-card orange" onclick="showAverageScoreModal()" style="cursor: pointer;">
                            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                            <div class="stat-label">Average Score</div>
                            <div class="stat-value" id="statAvgScore">0%</div>
                            <div class="progress">
                                <div class="progress-bar" id="avgScoreBar" style="width:0%"></div>
                            </div>
                            <small>Pass Rate: <span id="statPassRate">0</span>%</small>
                        </div>
                    </div>

                    <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr);">
                        <div class="stat-card">
                            <div class="stat-label">🎓 PU Grading System Distribution</div>
                            <div style="overflow-x: auto; margin-top: 15px;">
                                <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                                    <thead>
                                        <tr style="border-bottom: 2px solid var(--border);">
                                            <th style="text-align: left; padding: 8px 5px;">Grade</th>
                                            <th style="text-align: left; padding: 8px 5px;">Mark Range</th>
                                            <th style="text-align: left; padding: 8px 5px;">Description</th>
                                            <th style="text-align: left; padding: 8px 5px;">GP</th>
                                            <th style="text-align: center; padding: 8px 5px;">Count</th>
                                            <th style="text-align: center; padding: 8px 5px;">%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #10b981;">A</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">80-100</span></td>
                                            <td style="padding: 6px 5px;">Excellent</span></td>
                                            <td style="padding: 6px 5px;">4.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeACount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeAPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #34d399;">B+</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">75-79</span></td>
                                            <td style="padding: 6px 5px;">Very Good</span></td>
                                            <td style="padding: 6px 5px;">3.5</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeBplusCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeBplusPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #3b82f6;">B</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">70-74</span></td>
                                            <td style="padding: 6px 5px;">Good</span></td>
                                            <td style="padding: 6px 5px;">3.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="GradeBCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeBPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #8b5cf6;">C+</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">65-69</span></td>
                                            <td style="padding: 6px 5px;">Average</span></td>
                                            <td style="padding: 6px 5px;">2.5</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeCplusCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeCplusPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #f59e0b;">C</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">60-64</span></td>
                                            <td style="padding: 6px 5px;">Fair</span></td>
                                            <td style="padding: 6px 5px;">2.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="GradeCCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeCPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #f97316;">D+</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">55-59</span></td>
                                            <td style="padding: 6px 5px;">Barely Satisfactory</span></td>
                                            <td style="padding: 6px 5px;">1.5</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeDplusCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeDplusPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #fbbf24;">D</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">50-54</span></td>
                                            <td style="padding: 6px 5px;">Weak Pass</span></td>
                                            <td style="padding: 6px 5px;">1.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="GradeDCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeDPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #ef4444;">E</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">0-49</span></td>
                                            <td style="padding: 6px 5px;">Fail</span></td>
                                            <td style="padding: 6px 5px;">0.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="GradeECount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeEPercent">0</span>%</span></td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr style="border-top: 2px solid var(--border); background: var(--bg);">
                                            <td colspan="4" style="padding: 8px 5px;"><strong>Total</strong></td>
                                            <td style="padding: 8px 5px; text-align: center;"><strong
                                                    id="totalGradedStudents">0</strong></td>
                                            <td style="padding: 8px 5px; text-align: center;"><strong>100%</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">📊 Performance Metrics</div>
                            <div style="margin-top: 15px;">
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>🏆 Highest Score</span>
                                    <span id="statHighestScore" style="font-weight: 700; color: #10b981;">0%</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>📉 Lowest Score</span>
                                    <span id="statLowestScore" style="font-weight: 700; color: #ef4444;">0%</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>📊 Mean Score</span>
                                    <span id="statMeanScore"
                                        style="font-weight: 700; color: var(--accent-blue);">0%</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>📈 Median Score</span>
                                    <span id="statMedianScore"
                                        style="font-weight: 700; color: var(--accent-blue);">0%</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>📐 Standard Deviation</span>
                                    <span id="statStdDev" style="font-weight: 700;">0</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>✅ Pass Rate (≥50%)</span>
                                    <span id="statPassRateMetric" style="font-weight: 700; color: #10b981;">0%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="charts-row">
                        <div class="chart-card">
                            <h3>📈 Performance Trend</h3>
                            <div class="chart-container">
                                <canvas id="performanceLineChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3>🔔 Grade Distribution (Bell Curve)</h3>
                            <div class="chart-container">
                                <canvas id="gradeBellCurve"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="charts-row">
                        <div class="chart-card">
                            <h3>📊 Score Correlation Analysis</h3>
                            <div class="chart-container">
                                <canvas id="correlationScatterChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3>📉 Regression Analysis</h3>
                            <div class="chart-container">
                                <canvas id="regressionChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="table-section">
                        <div class="table-header"
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                            <h3>📋 Recent Submissions</h3>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <select id="recentSubmissionsCourseFilter"
                                    style="padding: 8px; border-radius: 8px; border: 1px solid var(--border); ">
                                    <option value="all">All Courses</option>
                                </select>
                                <select id="recentSubmissionsPeriodFilter"
                                    style="padding: 8px; border-radius: 8px; border: 1px solid var(--border); ">
                                    <option value="day">Today</option>
                                    <option value="week">This Week</option>
                                    <option value="month">This Month</option>
                                    <option value="year">This Year</option>
                                    <option value="all">All Time</option>
                                </select>
                                <button class="btn btn-outline"
                                    onclick="updateRecentSubmissionsWithFilters()">Apply</button>
                                <button class="btn btn-outline" onclick="go('submissions')">View All
                                    Submissions</button>
                            </div>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Course</th>
                                        <th>Score</th>
                                        <th>Grade</th>
                                        <th>Grade Point</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody id="recentSubmissionsTable">
                                    <tr>
                                        <td colspan="6" style="text-align:center">Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- EXAMS LIST -->
            <section id="view-exams" class="view">
                <div class="panel">
                    <div class="panel-title">📋 Exams <small>Published</small></div>
                    <div class="crumb">Home / Exams</div>
                    <div class="toolbar">
                        <div class="search">
                            <input id="examsSearch" placeholder="🔍 Search by title/code..." />
                            <button class="btn primary" onclick="applySearch('exams')">🔍 Search</button>
                        </div>
                        <button class="btn primary animate-pulse-slow" onclick="newExam()">✨ + Create New Exam</button>
                    </div>
                    <table class="table" id="examsTable"></table>
                </div>
            </section>

            <!-- BUILDER - CODING QUESTIONS ONLY -->
            <section id="view-builder" class="view">
                <div class="panel">
                    <div class="panel-title">
                        ✏️ Exam Builder
                        <small id="builderMeta">Create New Exam</small>
                    </div>
                    <div class="crumb" id="builderCrumb">Home / Create Exam</div>

                    <!-- REQUIRED FIELDS WARNING -->
                    <div class="readonly-warning" style="margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><strong>Note:</strong> Fields marked with <span style="color:#ef4444;">*</span> are
                            required. You must fill all required fields before publishing the exam.</span>
                    </div>

                    <!-- STICKY ACTION BUTTONS -->
                    <div class="sticky-actions">
                        <button class="btn primary" onclick="saveDraftExam()">
                            <i class="fas fa-save"></i> Save Draft
                        </button>
                        <button class="btn warn" onclick="publishExam()">
                            <i class="fas fa-check-circle"></i> Publish Exam
                        </button>
                        <button class="btn primary" onclick="previewExam()">
                            <i class="fas fa-eye"></i> Preview Exam
                        </button>
                        <button class="btn" onclick="toggleShuffle()" id="shuffleBtn">
                            <i class="fas fa-random"></i> <span id="shuffleBtnText">Shuffle Questions: OFF</span>
                        </button>
                        <button class="btn danger" onclick="deleteCurrentExam()">
                            <i class="fas fa-trash-alt"></i> Delete Exam
                        </button>
                    </div>

                    <!-- ==================== SECTION 1: BASIC EXAM INFO ==================== -->
                    <div class="panel" style="margin-bottom: 20px;">
                        <div class="panel-title">📋 Basic Exam Information</div>
                        <div class="rowgrid">
                            <!-- Exam Title -->
                            <div class="field required">
                                <label>📌 Exam Title <span class="required-star"></span></label>
                                <input id="bTitle" type="text"
                                    placeholder="e.g., Final Examination - Introduction to Programming"
                                    onchange="updateExamField('title', this.value)">
                            </div>

                            <!-- Course Code -->
                            <div class="field required">
                                <label>📚 Course Code <span class="required-star"></span></label>
                                <input id="bCode" type="text" placeholder="e.g., CS101"
                                    onchange="updateExamField('courseCode', this.value); if (document.getElementById('enrolledStudentsListContainer')?.style.display !== 'none') loadEnrolledStudentsForExam();">
                                <small>Only students enrolled in this course can access this exam</small>
                            </div>

                            <!-- Exam Code -->
                            <div class="field" style="display:none;">
                                <label>🔢 Exam Code</label>
                                <input type="text" id="exam_code" placeholder="e.g., EXAM-2024-001"
                                    onchange="updateExamField('exam_code', this.value)">
                                <small>Optional custom exam code</small>
                            </div>

                            <!-- Duration -->
                            <div class="field required">
                                <label>⏱️ Duration (minutes) <span class="required-star"></span></label>
                                <input id="bDuration" type="number" min="1" value="180" placeholder="180"
                                    onchange="updateExamField('durationMins', parseInt(this.value)); syncEndTimeFromDuration();">
                            </div>

                            <!-- Start Date/Time -->
                            <div class="field">
                                <label>📅 Start Date/Time</label>
                                <input id="bStartAt" type="datetime-local"
                                    onchange="updateExamField('startAtISO', this.value); syncDurationFromStartEnd(); syncEndTimeFromDuration();">
                                <small>Leave empty for immediate availability</small>
                            </div>

                            <div class="field">
                                <label>End Date/Time</label>
                                <input id="bEndAt" type="datetime-local"
                                    onchange="updateExamField('endAtISO', this.value); syncDurationFromStartEnd();">
                                <small>Leave empty to use the duration from the start time</small>
                            </div>

                            <!-- Exam Password -->
                            <div class="field">
                                <label>🔐 Exam Password</label>
                                <input type="password" id="examPassword" placeholder="Create a password for students"
                                    autocomplete="new-password"
                                    onchange="updateExamField('exam_password', this.value)">
                                <small>Students must enter this password to start the exam (optional)</small>
                            </div>

                            <!-- Questions to answer -->
                            <div class="field">
                                <label>📝 Questions to answer</label>
                                <input id="bQuestionsToAnswer" type="number" min="0" value="0"
                                    onchange="updateExamField('questionsToAnswer', parseInt(this.value) || 0)"
                                    oninput="updateExamField('questionsToAnswer', parseInt(this.value) || 0)">
                                <div class="hint">0 = all questions, or specify number like 5</div>
                                <div class="hint" id="questionAnswerRuleHint">Answer all questions. Total marks will update after questions are added.</div>
                            </div>
                        </div>

                        <!-- Instructions -->
                        <div class="field required">
                            <label>📝 Instructions (visible to students) <span class="required-star"></span></label>
                            <textarea id="bInstructions" rows="3"
                                placeholder="- Answer ALL questions.&#10;- Each question carries equal marks.&#10;- Time management is important."
                                onchange="updateExamField('instructions', this.value)"></textarea>
                        </div>
                    </div>

                    <!-- ==================== SECTION 2: SCHOOL INFORMATION ==================== -->
                    <div class="panel" style="margin-bottom: 20px; display:none;">
                        <div class="panel-title">🏛️ Institution Information</div>

                        <!-- School Logo -->
                        <div class="field">
                            <label>🏫 School Logo</label>
                            <input type="file" id="schoolLogo" accept="image/*"
                                onchange="handleSchoolLogoUpload(event)">
                            <div id="schoolLogoPreview" style="margin-top: 10px; display: none;">
                                <img id="schoolLogoImg"
                                    style="max-width: 100px; max-height: 100px; border-radius: 8px;">
                            </div>
                            <small>Upload your institution's logo (visible to students during exam)</small>
                        </div>

                        <div class="rowgrid">
                            <!-- School Name -->
                            <div class="field required">
                                <label>🏛️ School Name <span class="required-star"></span></label>
                                <input type="text" id="school_name" placeholder="e.g., University A+"
                                    onchange="updateExamField('school_name', this.value)">
                            </div>

                            <!-- Faculty Name -->
                            <div class="field required">
                                <label>📚 Faculty Name <span class="required-star"></span></label>
                                <input type="text" id="faculty_name"
                                    placeholder="e.g., Faculty of Science and Technology"
                                    onchange="updateExamField('faculty_name', this.value)">
                            </div>

                            <!-- Department -->
                            <div class="field required">
                                <label>📖 Department <span class="required-star"></span></label>
                                <input type="text" id="department" placeholder="e.g., Department of Computer Science"
                                    onchange="updateExamField('department', this.value)">
                            </div>

                            <!-- School Type -->
                            <div class="field required">
                                <label>🏫 School Type <span class="required-star"></span></label>
                                <select id="institution_school_type" onchange="updateExamField('school_type', this.value)">
                                    <option value="">Select School Type</option>
                                    <option value="Regular">Regular</option>
                                    <option value="Weekend">Weekend</option>
                                    <option value="Evening">Evening</option>
                                    <option value="Distance Learning">Distance Learning</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ==================== SECTION 3: ACADEMIC INFORMATION ==================== -->
                    <div class="panel" style="margin-bottom: 20px;">
                        <div class="panel-title">🎓 Academic Information</div>
                        <div class="rowgrid">
                            <div class="field required">
                                <label>School Type <span class="required-star"></span></label>
                                <select id="school_type" onchange="updateExamField('school_type', this.value)">
                                    <option value="">Select School Type</option>
                                    <option value="Regular">Regular</option>
                                    <option value="Weekend">Weekend</option>
                                    <option value="Evening">Evening</option>
                                    <option value="Distance Learning">Distance Learning</option>
                                </select>
                            </div>

                            <!-- Level -->
                            <div class="field required">
                                <label>🎓 Level <span class="required-star"></span></label>
                                <select id="level" onchange="updateExamField('level', this.value); if (document.getElementById('enrolledStudentsListContainer')?.style.display !== 'none') loadEnrolledStudentsForExam();">
                                    <option value="">Select Level</option>
                                    <option value="100">100 Level</option>
                                    <option value="200">200 Level</option>
                                    <option value="300">300 Level</option>
                                    <option value="400">400 Level</option>
                                    <option value="500">500 Level</option>
                                </select>
                            </div>

                            <!-- Semester -->
                            <div class="field required">
                                <label>📅 Semester <span class="required-star"></span></label>
                                <select id="semester" onchange="updateExamField('semester', this.value)">
                                    <option value="">Select Semester</option>
                                    <option value="First Semester">First Semester</option>
                                    <option value="Second Semester">Second Semester</option>
                                    <option value="Summer School">Summer School</option>
                                </select>
                            </div>

                            <!-- Exam Type -->
                            <div class="field required">
                                <label>📝 Exam Type <span class="required-star"></span></label>
                                <select id="exam_type" onchange="updateExamField('exam_type', this.value)">
                                    <option value="">Select Exam Type</option>
                                    <option value="End of Semester">End of Semester Examination</option>
                                    <option value="Mid-Semester">Mid-Semester Examination</option>
                                    <option value="Quiz">Quiz</option>
                                    <option value="Assignment">Assignment</option>
                                    <option value="Continuous Assessment">Continuous Assessment</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ==================== SECTION 4: GRADING OPTIONS ==================== -->
                    <div class="panel" style="margin-bottom: 20px;">
                        <div class="panel-title">⚙️ Grading Options</div>
                        <div class="field" style="max-width: 360px; margin-bottom: 15px;">
                            <label>Grading Mode</label>
                            <select id="gradingMode" onchange="setGradingMode(this.value)">
                                <option value="auto">Auto-grading with test cases</option>
                                <option value="manual">Manual grading only</option>
                                <option value="hybrid">Hybrid: auto-grading plus lecturer review</option>
                            </select>
                        </div>
                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="enableAutoGrading"
                                    onchange="toggleAutoGrading(this.checked)">
                                <span>🤖 Enable Automatic Grading</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="enablePartialGrading"
                                    onchange="togglePartialGrading(this.checked)">
                                <span>📊 Enable Partial Grading</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="showCorrectAnswers"
                                    onchange="toggleShowCorrectAnswers(this.checked)">
                                <span>✅ Show Correct Answers After Submission</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="allowReview" checked
                                    onchange="toggleAllowReview(this.checked)">
                                <span>🔄 Allow Review Before Submission</span>
                            </label>
                        </div>
                        <div class="hint" style="margin-top: 10px;">
                            <strong>Note:</strong> Coding questions can be auto-graded using test cases.
                        </div>
                    </div>

                    <!-- ==================== SECTION 5: STUDENT VISIBILITY ==================== -->
                    <div class="panel" style="margin-bottom: 20px;">
                        <div class="panel-title">👨🎓 Student Access Control</div>
                        <div class="field">
                            <button class="btn primary" id="toggleStudentListBtn"
                                onclick="toggleStudentVisibilityList()" style="margin-bottom: 10px;">
                                📋 Show/Hide Enrolled Students
                            </button>
                            <div id="enrolledStudentsListContainer" style="display: none; margin-top: 10px;">
                                <div style="background: var(--bg); border-radius: 12px; overflow-x: auto;">
                                    <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
                                        <thead>
                                            <tr
                                                style="background: var(--panel); border-bottom: 2px solid var(--border);">
                                                <th style="padding: 12px; text-align: left;">Student</th>
                                                <th style="padding: 12px; text-align: left;">Level</th>
                                                <th style="padding: 12px; text-align: left;">Course</th>
                                                <th style="padding: 12px; text-align: center;">Visibility</th>
                                            </tr>
                                        </thead>
                                        <tbody id="enrolledStudentsTable">
                                            <tr>
                                                <td colspan="4" style="text-align: center; padding: 40px;">
                                                    <div class="spinner" style="width: 30px; height: 30px;"></div>
                                                    Loading students...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="hint" style="margin-top: 10px;">
                                <strong>Note:</strong> Only students enrolled in this course will see the exam. Use the
                                visibility
                                controls above to hide/show the exam for specific students.
                            </div>
                        </div>
                    </div>

                    <!-- ==================== SECTION 6: QUESTIONS ==================== -->
                    <div class="questions-container">
                        <div class="panel-title" style="text-align: center; justify-content: center;">
                            📜🖋️📁📃 EXAM QUESTIONS 📜🖋️📁📃
                        </div>
                        <div id="qList" style="display: none;"></div>

                        <div id="noQuestionsMessage" class="no-questions-container">
                            <div class="no-questions-icon">📝</div>
                            <h3 class="no-questions-title">No Coding Questions Yet</h3>
                            <p class="no-questions-text">Click below to start adding coding questions.</p>
                            <div class="quick-questions">
                                <button class="quick-question-btn code" onclick="addFirstQuestion('code')">
                                    <i class="fas fa-code" style="margin-right: 8px;"></i> Add Coding Question
                                </button>
                            </div>
                        </div>

                        <div id="qtypeButtonBar" class="qtype-button-bar">
                            <div class="qtype-button-bar-title">
                                <i class="fas fa-plus-circle" style="margin-right: 8px; color: var(--blue);"></i>
                                Add More Coding Questions
                            </div>
                            <div class="qtype-buttons">
                                <button class="qtype-btn code" onclick="addQuestion('code')">
                                    <i class="fas fa-code"></i> Coding Question
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="hint" style="margin-top: 20px; text-align: center;">
                        <strong>💡 Tip:</strong> Click on the coding question button above to add a coding question. You
                        can
                        reorder, duplicate, or delete questions as needed.
                    </div>
                </div>
            </section>

            <!-- SUBMISSIONS -->
            <section id="view-submissions" class="view">
                <div class="panel">
                    <div class="panel-title">📤 Submissions <small>Student answers + auto scores</small></div>
                    <div class="crumb">Home / Submissions</div>

                    <!-- Calculator Modal -->
                    <div id="calculatorModal" class="modal" style="display:none;">
                        <div class="modal-content" style="width: 450px;">
                            <h3><i class="fas fa-calculator"></i> Grade Calculator</h3>
                            <div class="field">
                                <label>Select Student:</label>
                                <select id="calcStudentSelect"
                                    style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border);">
                                    <option value="">-- Select Student --</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Class Score (max 40):</label>
                                <input type="number" id="calcClassScoreField" min="0" max="40" step="0.5" value="0">
                            </div>
                            <div class="field">
                                <label>Exam Score (max 60):</label>
                                <input type="number" id="calcExamScoreField" min="0" max="60" step="0.5" value="0">
                            </div>
                            <div class="divider"></div>
                            <div style="background: var(--bg); padding: 15px; border-radius: 12px; text-align: center;">
                                <h4>Total Score: <span id="calcTotalScore"
                                        style="color: #3b82f6; font-size: 28px;">0</span> / 100</h4>
                                <h4>Grade: <span id="calcGradeDisplay" style="color: #10b981;">E</span></h4>
                                <div id="calcGradePointDisplay">Grade Point: 0.0</div>
                            </div>
                            <div class="modal-actions">
                                <button class="btn" onclick="closeCalculatorModal()">Close</button>
                                <button class="btn primary" onclick="updateCalculatorDisplay()">Calculate</button>
                            </div>
                        </div>
                    </div>

                    <!-- Print Modal -->
                    <div id="printModal" class="modal" style="display:none;">
                        <div class="modal-content" style="width: 800px; max-width: 95%;">
                            <h3><i class="fas fa-print"></i> Print Score Sheet</h3>
                            <div class="field">
                                <label>Select Course:</label>
                                <select id="printCourseSelect"
                                    style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border);">
                                    <option value="">-- Select Course --</option>
                                </select>
                            </div>
                            <div class="modal-actions">
                                <button class="btn" onclick="closePrintModal()">Cancel</button>
                                <button class="btn primary" onclick="generatePrintPreview()">Generate Score
                                    Sheet</button>
                            </div>
                        </div>
                    </div>

                    <!-- Print Preview Modal -->
                    <div id="printPreviewModal" class="modal" style="display:none;">
                        <div class="modal-content"
                            style="width: 1000px; max-width: 95%; max-height: 90vh; overflow-y: auto;">
                            <div id="printPreviewContent"></div>
                            <div class="modal-actions">
                                <button class="btn" onclick="closePrintPreviewModal()">Close</button>
                                <button class="btn primary" onclick="printScoreSheet()">Print / Save as PDF</button>
                            </div>
                        </div>
                    </div>


                    <!-- Submissions Container -->
                    <div id="submissionsContainer"></div>
                </div>
            </section>

            <!-- RESULTS -->
            <section id="view-results" class="view">
                <div class="panel">
                    <div class="panel-title">
                        <i class="fas fa-chart-line"></i> Student Results Dashboard
                        <small>View student performance by course and semester</small>
                    </div>
                    <div class="crumb">Home / Results</div>
                    <div id="resultsContainer"></div>
                </div>
            </section>

            <!-- STUDENTS PAGE -->
            <section id="view-students" class="view">
                <div class="panel">
                    <div class="panel-title">👥 Student Management <small>Add, Edit, Remove, Import, Export
                            students</small></div>
                    <div class="crumb">Home / Students</div>

                    <div
                        style="background: var(--bg); border-radius: 12px; padding: 15px; margin-bottom: 20px; border: 1px solid var(--border);">
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-search"></i> Search</label>
                                <input type="text" id="studentSearchInput" class="form-input"
                                    placeholder="Search by name or ID...">
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-graduation-cap"></i> Level</label>
                                <select id="levelFilter" class="form-input">
                                    <option value="all">All Levels</option>
                                    <option value="100">100 Level</option>
                                    <option value="200">200 Level</option>
                                    <option value="300">300 Level</option>
                                    <option value="400">400 Level</option>
                                    <option value="500">500 Level</option>
                                </select>
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-building"></i> Programme</label>
                                <select id="programmeFilter" class="form-input">
                                    <option value="all">All Programmes</option>
                                    <option value="Computer Science">Computer Science</option>
                                    <option value="Information Technology">Information Technology</option>
                                    <option value="Industrial Software Engineering">Industrial Software Engineering
                                    </option>
                                    <option value="NCCE">NCCE</option>
                                    <option value="Pre Engineering">Pre Engineering</option>
                                    <option value="Health Information Management">Health Information Management</option>
                                </select>
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="statusFilter" class="form-input">
                                    <option value="all">All Status</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div
                            style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap; justify-content: space-between;">
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button class="btn primary" onclick="showAddStudentModal()">
                                    <i class="fas fa-plus"></i> Add Single Student
                                </button>
                                <button class="btn success" onclick="showImportModal()">
                                    <i class="fas fa-file-upload"></i> Import Students (Bulk)
                                </button>
                                <button type="button" class="btn" onclick="applyFilters()">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <button type="button" class="btn btn-outline" onclick="resetFilters()">
                                    <i class="fas fa-undo"></i> Reset Filters
                                </button>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button class="btn ok" onclick="exportStudentsToExcelWithTemplate()">
                                    <i class="fas fa-file-excel"></i> Export to Excel
                                </button>
                                <button class="btn info" onclick="downloadImportTemplate()">
                                    <i class="fas fa-download"></i> Download Template
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Student ID</th>
                                    <th>Full Name</th>
                                    <th>Level</th>
                                    <th>Programme</th>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentsTableBody">
                                <tr>
                                    <td colspan="9" style="text-align:center; padding:40px;">Loading students...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- STUDENT DETAILS PAGE -->
            <section id="view-student-details" class="view">
                <div class="panel">
                    <div class="panel-title">📋 Student Details <small>Complete student information</small></div>
                    <div class="crumb">Home / Student Details</div>

                    <div
                        style="background: var(--bg); border-radius: 12px; padding: 15px; margin-bottom: 20px; border: 1px solid var(--border);">
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-search"></i> Search</label>
                                <input type="text" id="studentDetailsSearchInput" class="form-input"
                                    placeholder="Search by ID, Name, Programme...">
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-graduation-cap"></i> Level</label>
                                <select id="studentDetailsLevelFilter" class="form-input">
                                    <option value="all">All Levels</option>
                                    <option value="100">100 Level</option>
                                    <option value="200">200 Level</option>
                                    <option value="300">300 Level</option>
                                    <option value="400">400 Level</option>
                                    <option value="500">500 Level</option>
                                </select>
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-building"></i> Programme</label>
                                <select id="studentDetailsProgrammeFilter" class="form-input">
                                    <option value="all">All Programmes</option>
                                    <option value="Computer Science">Computer Science</option>
                                    <option value="Information Technology">Information Technology</option>
                                    <option value="SIndustal Software Engineering">Industral Software Engineering
                                    </option>
                                    <option value="NCCE">NCCE</option>
                                    <option value="Pre Engineering">Pre Engineering</option>
                                    <option value="Health Information Management">Health Information Management</option>
                                </select>
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="studentDetailsStatusFilter" class="form-input">
                                    <option value="all">All Status</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                            <button type="button" class="btn" onclick="applyStudentDetailsFilters()">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <button type="button" class="btn btn-outline" onclick="resetStudentDetailsFilters()">
                                <i class="fas fa-undo"></i> Reset Filters
                            </button>
                            <button class="btn ok" onclick="exportStudentDetailsToExcel()">
                                <i class="fas fa-file-excel"></i> Export to Excel
                            </button>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="table" id="studentDetailsTable">
                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Student ID</th>
                                    <th>Full Name</th>
                                    <th>Level</th>
                                    <th>Programme</th>
                                    <th>Course Name</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentDetailsBody">
                                <tr>
                                    <td colspan="11" style="text-align:center">Loading......</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- PROFILE -->
            <section id="view-profile" class="view">
                <div class="panel">
                    <div class="panel-title">👤 Profile</div>
                    <div class="crumb">Home / Profile</div>

                    <div class="rowgrid">
                        <div class="field">
                            <label>Profile Picture</label>
                            <input type="file" id="profilePicInput" accept="image/*"
                                onchange="previewProfilePic(event)" />
                            <div style="margin-top:10px">
                                <img id="profilePicDisplay"
                                    src="<?php echo isset($lecturerData['profile_pic']) ? htmlspecialchars($lecturerData['profile_pic']) : ''; ?>"
                                    style="max-width:150px; border-radius:10%; display:<?php echo empty($lecturerData['profile_pic']) ? 'none' : 'block'; ?>;" />
                            </div>
                            <small style="font-size: 11px; color: var(--muted);">Click to change profile picture</small>
                        </div>

                        <div class="field">
                            <label>Full Name <span style="color: var(--success);">(Editable)</span></label>
                            <input id="profileName"
                                value="<?php echo isset($lecturerData['full_name']) ? htmlspecialchars($lecturerData['full_name']) : ''; ?>"
                                placeholder="Not assigned yet" />
                        </div>
                    </div>

                    <div style="background: var(--bg); border-radius: 12px; padding: 15px; margin: 15px 0;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                            <i class="fas fa-user-edit" style="color: var(--success);"></i>
                            <span style="font-size: 13px; font-weight: 600; color: var(--success);">You can update your lecturer profile details here.</span>
                        </div>
                        <div class="rowgrid">
                            <div class="field">
                                <label>Staff ID</label>
                                <input id="profileStaffId"
                                    value="<?php echo isset($lecturerData['staff_id']) ? htmlspecialchars($lecturerData['staff_id']) : ''; ?>"
                                    class="form-input" placeholder="e.g., PULC/IT/00001" />
                            </div>
                            <div class="field">
                                <label>Department</label>
                                <input id="profileDepartment"
                                    value="<?php echo isset($lecturerData['department']) ? htmlspecialchars($lecturerData['department']) : ''; ?>"
                                    class="form-input" placeholder="e.g., Computer Science" />
                            </div>
                            <div class="field">
                                <label>Faculty</label>
                                <input id="profileFaculty"
                                    value="<?php echo isset($lecturerData['faculty']) ? htmlspecialchars($lecturerData['faculty']) : ''; ?>"
                                    class="form-input" placeholder="e.g., Science and Technology" />
                            </div>
                            <div class="field">
                                <label>Email <span style="color: var(--success);">(Editable)</span></label>
                                <input id="profileEmail" type="email"
                                    value="<?php echo isset($lecturerData['email']) ? htmlspecialchars($lecturerData['email']) : ''; ?>"
                                    placeholder="Not assigned yet" />
                            </div>
                        </div>
                    </div>

                    <div class="panel-title">📚 Teaching Assignments <span
                            style="font-size: 12px; color: var(--success);">(Editable)</span></div>
                    <div class="rowgrid">
                        <div class="field">
                            <label>Levels Taught</label>
                            <input id="profileLevels"
                                value="<?php echo isset($lecturerData['levels_taught']) ? htmlspecialchars($lecturerData['levels_taught']) : ''; ?>"
                                placeholder="e.g., 100, 200, 300" />
                            <small style="font-size: 11px; color: var(--muted);">Comma-separated list of levels</small>
                        </div>
                        <div class="field">
                            <label>Course Codes</label>
                            <input id="profileClasses"
                                value="<?php echo isset($lecturerData['classes']) ? htmlspecialchars($lecturerData['classes']) : ''; ?>"
                                placeholder="e.g., CS101, MATH201" />
                        </div>
                        <div class="field">
                            <label>Courses</label>
                            <textarea id="profileCourses" class="form-input" rows="3"
                                placeholder="e.g., Introduction to Programming, Data Structures"><?php echo isset($lecturerData['courses']) ? htmlspecialchars($lecturerData['courses']) : ''; ?></textarea>
                            <small style="font-size: 11px; color: var(--muted);">One course per line or
                                comma-separated</small>
                        </div>
                    </div>

                    <button class="btn primary" onclick="saveProfile()" style="margin-bottom: 30px;">
                        <i class="fas fa-save"></i> Save Profile Changes
                    </button>

                    <div class="panel-title" style="margin-top: 20px;">🔒 Change Password</div>
                    <div style="background: var(--bg); border-radius: 12px; padding: 20px; margin-top: 10px;">
                        <div class="rowgrid">
                            <div class="field">
                                <label>Current Password</label>
                                <div style="position: relative;">
                                    <input type="password" id="currentPassword" class="form-input"
                                        placeholder="Enter your current password" style="padding-right: 45px;" />
                                    <button type="button" onclick="togglePasswordVisibility('currentPassword')"
                                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted);">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="field">
                                <label>New Password</label>
                                <div style="position: relative;">
                                    <input type="password" id="newPassword" class="form-input"
                                        placeholder="Enter new password (min 6 characters)"
                                        style="padding-right: 45px;" />
                                    <button type="button" onclick="togglePasswordVisibility('newPassword')"
                                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted);">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="field">
                                <label>Confirm New Password</label>
                                <div style="position: relative;">
                                    <input type="password" id="confirmPassword" class="form-input"
                                        placeholder="Confirm new password" style="padding-right: 45px;" />
                                    <button type="button" onclick="togglePasswordVisibility('confirmPassword')"
                                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted);">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="hint" style="margin-top: 10px;">
                            <i class="fas fa-info-circle"></i> Password must be at least 6 characters long.
                        </div>
                        <button class="btn primary" onclick="changeLecturerPassword()" style="margin-top: 15px;">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </div>

                    <div class="hint" style="margin-top: 20px;">
                        <strong>Note:</strong> Lecturer accounts now manage their own profile details.
                    </div>
                </div>
            </section>

            <!-- MONITORING -->
            <section id="view-monitoring" class="view">
                <div class="panel">
                    <div class="panel-title">👁️ Live Monitoring <small>Real-time student screens</small></div>
                    <div class="crumb">Home / Monitoring</div>

                    <div class="toolbar">
                        <select id="monitoringExamSelect" onchange="loadMonitoringData()">
                            <option value="">Select Exam to Monitor</option>
                        </select>
                        <button class="btn danger" onclick="sendWarningToAll()">⚠️ Send Warning to All</button>
                        <button class="btn danger" onclick="lockAllScreens()">🔒 Lock All Screens</button>
                        <button class="btn primary" onclick="refreshMonitoring()">🔄 Refresh</button>
                    </div>

                    <div id="monitoringStats" class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Active Students</div>
                            <div class="stat-value" id="activeStudents">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Completed</div>
                            <div class="stat-value" id="completedStudents">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Warnings</div>
                            <div class="stat-value" id="warningCount">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Cheating Attempts</div>
                            <div class="stat-value" id="cheatingCount">0</div>
                        </div>
                    </div>

                    <div id="studentMonitoringGrid" class="student-grid"></div>
                </div>
            </section>

            <!-- PROCTORING (Enhanced) -->
            <section id="view-proctoring" class="view">
                <div class="panel">
                    <div class="panel-title">
                        <i class="fas fa-video"></i> Proctoring
                        <small>AI-powered exam supervision with live screen sharing</small>
                    </div>
                    <div class="crumb">Home / Proctoring</div>

                    <div class="toolbar">
                        <select id="proctoringExamSelect" onchange="loadProctoringData()" style="min-width: 250px;">
                            <option value="">Select Exam to Proctor</option>
                        </select>
                        <button class="btn primary" onclick="startProctoring()">
                            <i class="fas fa-play"></i> Start Proctoring
                        </button>
                        <button class="btn danger" onclick="stopProctoring()">
                            <i class="fas fa-stop"></i> Stop Proctoring
                        </button>
                        <button class="btn ok" onclick="unlockAllScreens()">
                            <i class="fas fa-unlock"></i> Unlock All
                        </button>
                        <button class="btn" onclick="refreshProctoring()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        <button class="btn warn" onclick="sendWarningToAllProctoring()">
                            <i class="fas fa-exclamation-triangle"></i> Warn All
                        </button>
                        <button class="btn" onclick="viewViolationEvidence()">
                            <i class="fas fa-folder-open"></i> Evidence
                        </button>
                    </div>

                    <!-- Proctoring Stats -->
                    <div id="proctoringStats" class="stats-grid" style="margin-bottom: 20px;">
                        <div class="stat-card blue">
                            <div class="stat-icon"><i class="fas fa-users"></i></div>
                            <div class="stat-label">Students Writing</div>
                            <div class="stat-value" id="studentsWriting">0</div>
                        </div>
                        <div class="stat-card green">
                            <div class="stat-icon"><i class="fas fa-desktop"></i></div>
                            <div class="stat-label">Screens Sharing</div>
                            <div class="stat-value" id="screensSharing">0</div>
                        </div>
                        <div class="stat-card orange">
                            <div class="stat-icon"><i class="fas fa-eye-slash"></i></div>
                            <div class="stat-label">Screens NOT Sharing</div>
                            <div class="stat-value" id="screensNotSharing">0</div>
                        </div>
                        <div class="stat-card purple">
                            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div class="stat-label">Violations</div>
                            <div class="stat-value" id="violationCount">0</div>
                        </div>
                    </div>

                    <!-- Student Grid View -->
                    <div id="proctoringGrid" class="student-grid"
                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
                        <div style="text-align: center; padding: 60px; grid-column: 1/-1;">
                            <i class="fas fa-desktop"
                                style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                            <p style="color: var(--muted);">Select an exam and start proctoring to see student screens
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <div id="toast" class="toast"></div>


        </main>
    </div>

    <div id="fullScreenProctorModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: min(1200px, 96vw); height: 92vh; display:flex; flex-direction:column; padding:0; overflow:hidden;">
            <div class="modal-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 18px; border-bottom:1px solid var(--border);">
                <div>
                    <h3 id="fullScreenStudentName" style="margin:0;">Student Screen</h3>
                    <div id="fullScreenStudentId" style="font-size:12px; color:var(--muted); margin-top:4px;"></div>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                    <span id="violationTag" class="tag danger" style="display:none;"></span>
                    <button class="btn warn" onclick="sendWarningToStudent()"><i class="fas fa-exclamation-triangle"></i> Warn</button>
                    <button class="btn danger" onclick="lockStudentScreenFromModal()"><i class="fas fa-lock"></i> Lock</button>
                    <button class="btn ok" onclick="unlockStudentScreenFromModal()"><i class="fas fa-unlock"></i> Unlock</button>
                    <button class="btn primary" onclick="takeSnapshotFromModal()"><i class="fas fa-camera"></i> Screenshot</button>
                    <button class="btn" onclick="closeFullScreenProctorModal()"><i class="fas fa-times"></i> Close</button>
                </div>
            </div>
            <div id="fullScreenContent" style="flex:1; min-height:0; background:#000;"></div>
        </div>
    </div>

    <!-- Student Modal -->
    <div id="studentModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 700px; max-width: 90%; max-height: 85vh; overflow-y: auto;">
            <div class="modal-header"
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                <h3 id="studentModalTitle" style="font-size: 20px; font-weight: 700; color: var(--accent-blue);">
                    <i class="fas fa-user-graduate"></i> Add New Student
                </h3>
                <button type="button" onclick="closeStudentModal()"
                    style="background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-secondary);">&times;</button>
            </div>

            <form id="studentForm" onsubmit="saveStudent(event)">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">

                    <div>
                        <div class="field required">
                            <label><i class="fas fa-id-card"></i> Student ID <span class="required-star"></span></label>
                            <input type="text" id="studentId" class="form-input" placeholder="e.g., STU001" required>
                            <small style="font-size: 11px; color: var(--muted);">Default password will be set to Student
                                ID</small>
                        </div>

                        <div class="field required">
                            <label><i class="fas fa-user"></i> Full Name <span class="required-star"></span></label>
                            <input type="text" id="studentFullName" class="form-input" placeholder="e.g., John Doe"
                                required>
                        </div>

                        <div class="field required" style="grid-column: 1 / -1;">
                            <label><i class="fas fa-book"></i> Courses <span class="required-star"></span></label>
                            <div id="studentCoursesList"></div>
                            <button type="button" class="btn btn-outline" onclick="addStudentCourseRow('', '')" style="margin-top: 8px;">
                                <i class="fas fa-plus"></i> Add Course
                            </button>
                            <small>Add one or more course code/name pairs for this student.</small>
                        </div>
                    </div>
                    <div id="studentFormError"
                        style="display:none; background:#fee2e2; color:#dc2626; padding:12px; border-radius:8px; margin-bottom:15px;">
                    </div>

                    <div>
                        <div class="field required">
                            <label><i class="fas fa-graduation-cap"></i> Programme <span
                                    class="required-star"></span></label>
                            <select id="studentProgramme" class="form-input" required>
                                <option value="">Select Programme</option>
                                <option value="Computer Science">Computer Science</option>
                                <option value="Information Technology">Information Technology</option>
                                <option value="SIndustal Software Engineering">Industral Software Engineering
                                </option>
                                <option value="NCCE">NCCE</option>
                                <option value="Pre Engineering">Pre Engineering</option>
                                <option value="Health Information Management">Health Information Management</option>
                            </select>
                        </div>

                        <div class="field required">
                            <label><i class="fas fa-layer-group"></i> Level <span class="required-star"></span></label>
                            <select id="studentLevel" class="form-input" required>
                                <option value="">Select Level</option>
                                <option value="100">100 Level</option>
                                <option value="200">200 Level</option>
                                <option value="300">300 Level</option>
                                <option value="400">400 Level</option>
                                <option value="500">500 Level</option>
                            </select>
                        </div>

                        <div class="field">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="studentStatus" class="form-input">
                                <option value="Active">✅ Active</option>
                                <option value="Inactive">❌ Inactive</option>
                            </select>
                        </div>

                        <div class="field">
                            <label><i class="fas fa-key"></i> Auto-generated Credentials</label>
                            <input type="text" id="studentUsername" class="form-input" readonly
                                style="background: var(--disabled-bg, #f0f0f0);" placeholder="Auto-generated">
                            <input type="text" id="studentPassword" class="form-input" readonly
                                style="background: var(--disabled-bg, #f0f0f0); margin-top: 8px;"
                                placeholder="Auto-generated">
                            <small style="font-size: 11px; color: var(--muted);">Username and password are set to
                                Student ID</small>
                        </div>
                    </div>
                </div>

                <div class="hint"
                    style="margin-top: 20px; padding: 12px; background: rgba(79, 70, 229, 0.1); border-radius: 10px; border-left: 4px solid var(--accent-blue);">
                    <i class="fas fa-info-circle"></i> <strong>Note:</strong> Default password will be set to the
                    Student ID. Student can change after first login.
                </div>

                <div class="modal-actions"
                    style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 25px; padding-top: 15px; border-top: 1px solid var(--border);">
                    <button type="button" class="btn btn-outline" onclick="closeStudentModal()"
                        style="padding: 10px 24px;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" style="padding: 10px 24px;">
                        <i class="fas fa-save"></i> Save Student
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Question Preview Modal -->
    <div id="previewModal" class="modal" style="display:none;">
        <div class="modal-content" style="width:800px; max-width:95%;">
            <h3>📋 Exam Preview</h3>
            <div id="previewContent" style="max-height:70vh; overflow-y:auto; padding:10px;"></div>
            <div class="modal-actions">
                <button class="btn" onclick="closePreviewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Marking Modal -->
    <div id="markingModal" class="modal" style="display:none;">
        <div class="modal-content" style="width:800px; max-width:95%;">
            <h3 id="markingModalTitle">Mark Submission</h3>
            <div id="markingContent" style="max-height:70vh; overflow-y:auto; padding:10px;"></div>
            <div class="modal-actions">
                <button class="btn ok" onclick="saveManualMarks()">Save Marks</button>
                <button class="btn" onclick="closeMarkingModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Screen View Modal -->
    <div id="screenModal" class="modal" style="display:none;">
        <div class="modal-content" style="width:90%; height:90%; max-width:1200px;">
            <h3 id="screenModalTitle">Student Screen</h3>
            <div id="screenContent" style="height:80%; overflow-y:auto; padding:10px;">
                <video
                    src="https://player.vimeo.com/external/370795553.sd.mp4?s=3f4f7a6e7c6f7f6e7c6f7f6e7c6f7f6e&profile_id=165&oauth2_token_id=57447761"
                    autoplay loop controls style="width:100%; height:auto;"></video>
            </div>
            <div class="modal-actions">
                <button class="btn" onclick="closeScreenModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- View Student Details Modal -->
    <div id="viewStudentModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 600px; max-width: 90%; max-height: 85vh; overflow-y: auto;">
            <div class="modal-header"
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                <h3 id="viewStudentModalTitle" style="font-size: 20px; font-weight: 700; color: var(--accent-blue);">
                    <i class="fas fa-user-graduate"></i> Student Details
                </h3>
                <button type="button" onclick="closeViewStudentModal()"
                    style="background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-secondary);">&times;</button>
            </div>
            <div id="viewStudentContent">
            </div>
            <div class="modal-actions"
                style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border);">
                <button type="button" class="btn btn-outline" onclick="closeViewStudentModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Exam Preview Modal -->
    <div id="examPreviewModal" class="modal" style="display:none;">
        <div class="modal-content"
            style="width:95%; max-width:920px; max-height:92vh; overflow-y:auto; padding:0; border-radius:20px;">
            <div
                style="position:sticky; top:0; z-index:10; background:var(--panel); border-bottom:1px solid var(--border); padding:16px 24px; display:flex; justify-content:space-between; align-items:center; border-radius:20px 20px 0 0;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div
                        style="width:36px; height:36px; background:linear-gradient(135deg,#4f46e5,#06b6d4); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-eye" style="color:#fff; font-size:16px;"></i>
                    </div>
                    <div>
                        <div style="font-weight:700; font-size:16px; color:var(--text);">Exam Preview</div>
                        <div style="font-size:12px; color:var(--muted);">Student view — read-only</div>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <button onclick="document.getElementById('examPreviewModal').style.display='none'"
                        style="width:36px; height:36px; background:none; border:1px solid var(--border); border-radius:10px; cursor:pointer; font-size:18px; color:var(--muted); display:flex; align-items:center; justify-content:center;">
                        &times;
                    </button>
                </div>
            </div>
            <div id="examPreviewContent" style="padding:24px;"></div>
        </div>
    </div>
    <!-- Import Students Modal -->
    <div id="importModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 700px; max-width: 90%; max-height: 85vh; overflow-y: auto;">
            <div class="modal-header"
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                <h3 style="font-size: 20px; font-weight: 700; color: var(--accent-blue);">
                    <i class="fas fa-file-upload"></i> Import Students (Bulk)
                </h3>
                <button type="button" onclick="closeImportModal()"
                    style="background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-secondary);">&times;</button>
            </div>

            <div class="import-instructions"
                style="background: rgba(59,130,246,0.1); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0;"><i class="fas fa-info-circle"></i> Instructions:</h4>
                <ul style="margin: 0; padding-left: 20px; font-size: 13px;">
                    <li>Download the template using the button below</li>
                    <li>Fill in student details (Student ID, Full Name, Level, Programme, Course Code, Course Name,
                        Status)</li>
                    <li>Level must be: 100, 200, 300, 400, or 500</li>
                    <li>Status must be: Active or Inactive</li>
                    <li>Maximum 500 students per import</li>
                    <li>Duplicate Student IDs will be skipped</li>
                </ul>
            </div>

            <div style="margin-bottom: 20px;">
                <button class="btn primary" onclick="downloadImportTemplate()">
                    <i class="fas fa-download"></i> Download CSV Template
                </button>
            </div>

            <div class="field">
                <label><i class="fas fa-file-csv"></i> Choose CSV/Excel File</label>
                <input type="file" id="importFile" accept=".csv, .xlsx, .xls"
                    style="padding: 10px; border: 2px solid var(--border); border-radius: 10px; width: 100%;">
                <small style="display: block; margin-top: 5px;">Supported formats: CSV, Excel (.xlsx, .xls)</small>
            </div>

            <div class="field">
                <label><i class="fas fa-book"></i> Default Course (Optional)</label>
                <input type="text" id="defaultCourseCode" placeholder="e.g., CS101"
                    style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border);">
                <small>If course code is empty in file, this will be used</small>
            </div>

            <div class="field">
                <label><i class="fas fa-book-open"></i> Default Course Name (Optional)</label>
                <input type="text" id="defaultCourseName" placeholder="e.g., Introduction to Programming"
                    style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border);">
            </div>

            <div id="importProgress" style="display: none; margin: 15px 0;">
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar" id="importProgressBar" style="width: 0%;"></div>
                </div>
                <p id="importStatus" style="font-size: 13px; margin-top: 8px;">Processing...</p>
            </div>

            <div id="importResults"
                style="display: none; margin: 15px 0; padding: 12px; border-radius: 8px; max-height: 200px; overflow-y: auto;">
            </div>

            <div class="modal-actions"
                style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border);">
                <button type="button" class="btn btn-outline" onclick="closeImportModal()">Cancel</button>
                <button type="button" class="btn primary" onclick="importStudents()">
                    <i class="fas fa-upload"></i> Import Students
                </button>
            </div>
        </div>
    </div>

    <!-- Question Review Modal -->
    <div id="questionReviewModal" class="modal" style="display:none;">
        <div class="modal-content"
            style="width: 90%; max-width: 1200px; height: 90vh; padding: 0; display: flex; flex-direction: column;">
            <div
                style="padding: 20px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 id="reviewStudentName" style="margin: 0;">Student Submission</h2>
                    <p id="reviewExamInfo" style="margin: 5px 0 0 0; opacity: 0.9;"></p>
                </div>
                <button onclick="closeQuestionReviewModal()"
                    style="background: none; border: none; color: white; font-size: 28px; cursor: pointer;">&times;</button>
            </div>

            <div style="display: flex; flex: 1; overflow: hidden;">
                <!-- Question List Sidebar (LEFT PANEL) -->
                <div
                    style="width: 280px; background: var(--bg); border-right: 1px solid var(--border); overflow-y: auto; padding: 15px;">
                    <h4 style="margin-bottom: 15px;">Questions</h4>
                    <div id="questionListSidebar"></div>
                </div>

                <!-- Main Content Area (RIGHT PANEL - CODE EDITOR) -->
                <div style="flex: 1; overflow-y: auto; padding: 20px;">
                    <div id="questionContentArea">
                        <div style="text-align: center; padding: 60px;">
                            <i class="fas fa-code"
                                style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                            <p>Select a question from the left to view and grade</p>
                        </div>
                    </div>
                </div>
            </div>

            <div
                style="padding: 15px 20px; border-top: 1px solid var(--border); background: var(--panel); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>Total Score: </strong>
                    <span id="totalScoreDisplay" style="font-size: 20px; font-weight: bold; color: #3b82f6;">0</span>
                    <span id="totalMarksDisplay"></span>
                </div>
                <div>

                    <button class="btn" onclick="closeQuestionReviewModal()">Close</button>
                    <button class="btn primary" onclick="saveAllScores()">Save All Scores</button>
                </div>
            </div>
        </div>
    </div>


    <!-- Enhanced Code Tester Modal with Multi-Language Support -->
    <div id="codeTesterModal" class="modal" style="display:none;">
        <div class="modal-content"
            style="width: 95%; max-width: 1400px; height: 85vh; padding: 0; display: flex; flex-direction: column;">
            <div
                style="padding: 15px 20px; background: linear-gradient(135deg, #10b981, #059669); color: white; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;"><i class="fas fa-code"></i> Code Tester & Grader</h3>
                <div>
                    <span id="languageBadge"
                        style="background: rgba(255,255,255,0.2); padding: 5px 12px; border-radius: 20px; margin-right: 10px; font-size: 12px;">
                        <i class="fas fa-tag"></i> <span id="currentLanguage">Python</span>
                    </span>
                    <button id="runCodeBtn" onclick="executeCode()"
                        style="background: white; color: #059669; border: none; padding: 8px 20px; border-radius: 8px; margin-right: 10px; cursor: pointer; font-weight: bold;">
                        <i class="fas fa-play"></i> Run Code
                    </button>
                    <button onclick="closeCodeTesterModal()"
                        style="background: none; border: none; color: white; font-size: 28px; cursor: pointer;">&times;</button>
                </div>
            </div>

            <div style="display: flex; flex: 1; overflow: hidden; flex-wrap: wrap;">
                <!-- Left Panel: Code Editor -->
                <div
                    style="flex: 1; min-width: 300px; display: flex; flex-direction: column; border-right: 1px solid var(--border);">
                    <div
                        style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <strong><i class="fas fa-laptop-code"></i> Code Editor</strong>
                        </div>
                        <div>
                            <label style="font-size: 12px;">Input (stdin):</label>
                            <textarea id="testInput" rows="2"
                                style="width: 200px; padding: 5px; font-family: monospace; font-size: 11px;"
                                placeholder="Optional input..."></textarea>
                        </div>
                    </div>
                    <textarea id="testCodeEditor"
                        style="flex: 1; padding: 15px; font-family: 'Courier New', monospace; font-size: 13px; background: #1e1e1e; color: #d4d4d4; border: none; resize: none; outline: none; min-height: 200px;"></textarea>
                </div>

                <!-- Right Panel: Output & Grading -->
                <div style="flex: 1; min-width: 300px; display: flex; flex-direction: column;">
                    <div style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                        <strong><i class="fas fa-terminal"></i> Output</strong>
                        <span id="executionStatus" style="margin-left: 10px; font-size: 11px;"></span>
                    </div>
                    <div id="testOutput"
                        style="flex: 0.5; overflow: auto; padding: 15px; background: #1e1e1e; font-family: monospace; font-size: 13px; color: #d4d4d4; min-height: 150px;">
                        Click "Run Code" to see output...
                    </div>

                    <!-- Test Results & Grading -->
                    <div id="testResultsPanel"
                        style="flex: 0.5; border-top: 1px solid var(--border); overflow-y: auto; background: var(--bg); min-height: 150px;">
                        <div style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                            <strong><i class="fas fa-chart-line"></i> Test Results & Grading</strong>
                            <span id="totalScoreDisplay"
                                style="float: right; font-size: 16px; font-weight: bold;"></span>
                        </div>
                        <!-- Enhanced Code Tester Modal with Multi-Language Support -->
                        <div id="codeTesterModal" class="modal" style="display:none;">
                            <div class="modal-content"
                                style="width: 95%; max-width: 1400px; height: 85vh; padding: 0; display: flex; flex-direction: column;">
                                <div
                                    style="padding: 15px 20px; background: linear-gradient(135deg, #10b981, #059669); color: white; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center;">
                                    <h3 style="margin: 0;"><i class="fas fa-code"></i> Code Tester & Grader</h3>
                                    <div>
                                        <span id="languageBadge"
                                            style="background: rgba(255,255,255,0.2); padding: 5px 12px; border-radius: 20px; margin-right: 10px; font-size: 12px;">
                                            <i class="fas fa-tag"></i> <span id="currentLanguage">Python</span>
                                        </span>
                                        <button id="runCodeBtn" onclick="executeCode()"
                                            style="background: white; color: #059669; border: none; padding: 8px 20px; border-radius: 8px; margin-right: 10px; cursor: pointer; font-weight: bold;">
                                            <i class="fas fa-play"></i> Run Code
                                        </button>
                                        <button onclick="closeCodeTesterModal()"
                                            style="background: none; border: none; color: white; font-size: 28px; cursor: pointer;">&times;</button>
                                    </div>
                                </div>

                                <div style="display: flex; flex: 1; overflow: hidden; flex-wrap: wrap;">
                                    <!-- Left Panel: Code Editor -->
                                    <div
                                        style="flex: 1; min-width: 300px; display: flex; flex-direction: column; border-right: 1px solid var(--border);">
                                        <div
                                            style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                                            <strong><i class="fas fa-laptop-code"></i> Code Editor</strong>
                                        </div>
                                        <textarea id="testCodeEditor"
                                            style="flex: 1; padding: 15px; font-family: 'Courier New', monospace; font-size: 13px; background: #1e1e1e; color: #d4d4d4; border: none; resize: none; outline: none;"></textarea>
                                    </div>

                                    <!-- Right Panel: Output & Grading -->
                                    <div style="flex: 1; min-width: 300px; display: flex; flex-direction: column;">
                                        <div
                                            style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                                            <strong><i class="fas fa-terminal"></i> Output</strong>
                                        </div>
                                        <div id="testOutput"
                                            style="flex: 0.5; overflow: auto; padding: 15px; background: #1e1e1e; font-family: monospace; font-size: 13px; color: #d4d4d4;">
                                            Click "Run Code" to see output...
                                        </div>

                                        <!-- Test Results & Grading -->
                                        <div id="testResultsPanel"
                                            style="flex: 0.5; border-top: 1px solid var(--border); overflow-y: auto; background: var(--bg);">
                                            <div
                                                style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                                                <strong><i class="fas fa-chart-line"></i> Test Results &
                                                    Grading</strong>
                                            </div>
                                            <div id="testResultsList" style="padding: 10px;">
                                                <div style="text-align: center; color: var(--muted); padding: 20px;">
                                                    Run the code to see test results
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="testResultsList" style="padding: 10px;">
                            <div style="text-align: center; color: var(--muted); padding: 20px;">
                                Run the code to see test results
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                style="padding: 10px 15px; border-top: 1px solid var(--border); background: var(--panel); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <span id="suggestedScoreDisplay" style="font-size: 14px;"></span>
                </div>
                <div>
                    <button class="btn" onclick="closeCodeTesterModal()">Close</button>
                    <button class="btn primary" id="applyScoreBtn" onclick="applySuggestedScoreToGrade()"
                        style="background: #10b981;">
                        <i class="fas fa-check-circle"></i> Apply Score to Grade
                    </button>
                </div>
            </div>
        </div>
    </div>







    <script>
    // ============================================
    // 1. GLOBAL VARIABLES & CONSTANTS
    // ============================================

    const K_EXAMS = "qoda_exams_v1";
    const K_SUBS = "qoda_submissions_v1";
    const K_AUDIT = "qoda_audit_v1";
    const K_PROFILE = "qoda_profile_v1";
    const K_STUDENTS = "qoda_students_v1";
    const K_SETTINGS = "qoda_settings_v1";
    const K_MONITORING = "qoda_monitoring_v1";
    const K_THEME = "qoda_theme_v1";

    const routes = ["exams", "builder", "submissions", "marking", "results",
        "students", "student-details", "profile", "monitoring", "proctoring"
    ];

    const codingLanguagesList = [
        "Python", "JavaScript", "PHP", "Java", "C", "C++",
        "C#", "VB.NET", "SQL", "HTML", "CSS"
    ];

    let routeState = {
        route: "dashboard",
        params: {}
    };
    let currentExamId = null;
    let currentSubmissionId = null;
    let dashboardChart = null;
    let lineChart = null;
    let bellCurve = null;
    let performanceChart = null;
    let correlationChart = null;
    let regressionChart = null;
    let regressionChartInstance = null;
    let performanceLineChart = null;
    let gradeBellCurve = null;
    let correlationScatterChart = null;
    let currentStudentId = null;
    let gradingMode = "auto";
    let shuffleEnabled = false;
    let sidebarWidth = 280;
    let isResizing = false;
    let essaySchemes = [];
    let codingSchemes = [];
    let shortSchemes = [];
    let monitoringInterval = null;
    let proctoringInterval = null;
    let currentScreenStudent = null;
    let allStudents = [];
    let filteredStudents = [];
    let allStudentsDetails = [];
    let filteredStudentDetails = [];
    let studentListVisible = false;
    let autoSaveInterval = null;
    let currentProctoringExam = null;
    let activeProctoringStudents = [];
    let proctoringStreams = {};
    let currentFullScreenStudent = null;

    const submenusData = {
        'examSubmenu': {
            title: 'Exam Management',
            items: [{
                    icon: 'fas fa-file-alt',
                    label: 'Exams',
                    page: 'exams'
                },
                {
                    icon: 'fas fa-upload',
                    label: 'Submissions',
                    page: 'submissions'
                },

                {
                    icon: 'fas fa-chart-line',
                    label: 'Results',
                    page: 'results'
                }
            ]
        },
        'studentSubmenu': {
            title: 'Student Management',
            items: [{
                    icon: 'fas fa-users',
                    label: 'Students',
                    page: 'students'
                },
                {
                    icon: 'fas fa-id-card',
                    label: 'Student Details',
                    page: 'student-details'
                }
            ]
        },
        'monitorSubmenu': {
            title: 'Monitoring',
            items: [{
                icon: 'fas fa-video',
                label: 'Proctoring',
                page: 'proctoring'
            }]
        },
        'accountSubmenu': {
            title: 'Account',
            items: [{
                icon: 'fas fa-user',
                label: 'Profile',
                page: 'profile'
            }]
        }
    };

    let currentActiveSubmenu = null;

    // ============================================
    // 2. UTILITY FUNCTIONS
    // ============================================

    function uid(prefix = "EX") {
        return prefix + "-" + Math.random().toString(16).slice(2, 10).toUpperCase();
    }

    function readJSON(key, fallback) {
        try {
            const stored = localStorage.getItem(key);
            if (!stored) return fallback;
            return JSON.parse(stored) ?? fallback;
        } catch {
            return fallback;
        }
    }

    function writeJSON(key, value) {
        localStorage.setItem(key, JSON.stringify(value));
    }

    function escapeHTML(s) {
        if (!s) return '';
        return String(s).replace(/[&<>"']/g, c => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;"
        } [c]));
    }

    function toast(msg, duration = 3000) {
        const existing = document.getElementById("centerToast");
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id = 'centerToast';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:10002;display:flex;align-items:center;justify-content:center;padding:20px;';
        modal.innerHTML = `
            <div style="background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:16px;padding:24px 28px;max-width:420px;width:100%;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.28);">
                <div style="white-space:pre-line;font-weight:700;line-height:1.5;">${escapeHTML(msg)}</div>
                <button class="btn primary" style="margin-top:18px;" onclick="this.closest('#centerToast').remove()">OK</button>
            </div>
        `;
        document.body.appendChild(modal);
        clearTimeout(window.__toastTimer);
        window.__toastTimer = setTimeout(() => {
            if (modal.parentElement) modal.remove();
        }, duration);
    }

    function confirmPopup(message, title = 'Confirm action', confirmText = 'Continue') {
        return new Promise(resolve => {
            const existing = document.getElementById('confirmPopup');
            if (existing) existing.remove();

            const modal = document.createElement('div');
            modal.id = 'confirmPopup';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.62);z-index:10050;display:flex;align-items:center;justify-content:center;padding:20px;';
            modal.innerHTML = `
                <div style="background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:18px;padding:26px;max-width:460px;width:100%;text-align:center;box-shadow:0 28px 70px rgba(0,0,0,.35);">
                    <div style="width:54px;height:54px;border-radius:16px;background:rgba(59,130,246,.16);color:#60a5fa;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:24px;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 style="margin:0 0 10px;font-size:20px;">${escapeHTML(title)}</h3>
                    <p style="white-space:pre-line;color:var(--muted);line-height:1.5;margin-bottom:22px;">${escapeHTML(message)}</p>
                    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                        <button class="btn" data-confirm="cancel">Cancel</button>
                        <button class="btn primary" data-confirm="ok">${escapeHTML(confirmText)}</button>
                    </div>
                </div>
            `;
            modal.querySelector('[data-confirm="cancel"]').onclick = () => {
                modal.remove();
                resolve(false);
            };
            modal.querySelector('[data-confirm="ok"]').onclick = () => {
                modal.remove();
                resolve(true);
            };
            document.body.appendChild(modal);
        });
    }

    function showLoading(message) {
        let loader = document.getElementById('globalLoader');
        if (!loader) {
            loader = document.createElement('div');
            loader.id = 'globalLoader';
            loader.style.cssText =
                'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:9999; display:flex; align-items:center; justify-content:center; flex-direction:column; color:white;';
            loader.innerHTML =
                '<div class="spinner"></div><div id="loaderMessage" style="margin-top:20px;">Loading...</div>';
            document.body.appendChild(loader);
        }
        const msgDiv = document.getElementById('loaderMessage');
        if (msgDiv) msgDiv.textContent = message;
        loader.style.display = 'flex';
    }

    function hideLoading() {
        const loader = document.getElementById('globalLoader');
        if (loader) loader.style.display = 'none';
    }

    function togglePasswordVisibility(fieldId) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
        field.setAttribute('type', type);
    }

    // ============================================
    // 3. API FUNCTIONS
    // ============================================

    async function apiRequest(action, data = {}) {
        const formData = new URLSearchParams();
        formData.append('action', action);
        Object.keys(data).forEach(key => {
            if (data[key] !== undefined && data[key] !== null) {
                formData.append(key, data[key]);
            }
        });
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text);
                return {
                    success: false,
                    error: 'Invalid server response'
                };
            }
        } catch (error) {
            console.error('API Error:', error);
            return {
                success: false,
                error: error.message
            };
        }
    }

    // ============================================
    // 4. EXAM MANAGEMENT FUNCTIONS
    // ============================================

    function getExams() {
        return readJSON(K_EXAMS, []);
    }

    function setExams(e) {
        writeJSON(K_EXAMS, e);
    }

    function findExam(id) {
        const numericId = parseInt(id);
        return getExams().find(e => parseInt(e.id) === numericId) || null;
    }

    async function loadExamsList() {
        showLoading('Loading exams...');
        try {
            const data = await apiRequest('get_exams');
            if (data.success && data.data) {
                const exams = data.data.map(exam => {
                    let questions = [];
                    if (exam.questions) {
                        if (typeof exam.questions === 'string') {
                            try {
                                questions = JSON.parse(exam.questions);
                            } catch (e) {
                                console.error("Failed to parse questions:", e);
                                questions = [];
                            }
                        } else if (Array.isArray(exam.questions)) {
                            questions = exam.questions;
                        }
                    }
                    return {
                        id: exam.id,
                        title: exam.title || '',
                        courseCode: exam.course_code || '',
                        durationMins: exam.duration_minutes || 180,
                        startAtISO: exam.start_datetime || '',
                        endAtISO: exam.end_datetime || '',
                        instructions: exam.instructions || '',
                        markingScheme: exam.marking_scheme || '',
                        published: exam.published == 1,
                        questions: questions,
                        questionsToAnswer: exam.questions_to_answer || 0,
                        shuffleEnabled: exam.shuffle_enabled == 1,
                        gradingMode: exam.grading_mode || 'auto',
                        exam_password: exam.exam_password || '',
                        school_name: exam.school_name || '',
                        faculty_name: exam.faculty_name || '',
                        department: exam.department || '',
                        semester: exam.semester || '',
                        exam_type: exam.exam_type || '',
                        school_type: exam.school_type || '',
                        level: exam.level || '',
                        auto_grading_enabled: exam.auto_grading_enabled || 0,
                        partial_grading_enabled: exam.partial_grading_enabled || 0,
                        show_correct_answers: exam.show_correct_answers || 0,
                        allow_review: exam.allow_review !== null ? exam.allow_review : 1
                    };
                });
                setExams(exams);
                renderExamsTable(exams);
                populateProctoringExamSelect(exams);
                return exams;
            } else {
                toast('❌ Failed to load exams: ' + (data.error || 'Unknown error'));
                renderExamsTable([]);
                return [];
            }
        } catch (error) {
            console.error('Error loading exams:', error);
            toast('❌ Error loading exams');
            renderExamsTable([]);
            return [];
        } finally {
            hideLoading();
        }
    }

    async function viewSubmissionDetails(submissionId) {
        showLoading('Loading submission details...');
        try {
            const result = await apiRequest('get_submission_details', {
                submission_id: submissionId
            });

            if (result.success && result.data) {
                const sub = result.data;
                let content = `
                <div style="padding: 20px;">
                    <div style="margin-bottom: 20px;">
                        <h3>${escapeHTML(sub.exam_title)}</h3>
                        <p><strong>Student:</strong> ${escapeHTML(sub.student_name)} (${escapeHTML(sub.student_identifier)})</p>
                        <p><strong>Submitted:</strong> ${new Date(sub.submitted_at).toLocaleString()}</p>
                        <p><strong>Score:</strong> ${sub.total_score} / ${sub.total_marks} (${sub.percentage}%)</p>
                        <p><strong>Status:</strong> ${sub.status}</p>
                    </div>
                    <div id="submissionAnswers">
                        <h4>Student Answers</h4>
            `;

                const answers = typeof sub.answers === 'string' ? JSON.parse(sub.answers) : sub.answers;

                for (const [qId, answer] of Object.entries(answers)) {
                    content += `
                    <div style="background: var(--bg); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                        <div style="font-weight: bold; margin-bottom: 10px;">Question ID: ${escapeHTML(qId)}</div>
                        <div style="margin-bottom: 10px;">
                            <strong>Answer:</strong>
                            <pre style="background: #1e1e1e; padding: 10px; border-radius: 8px; overflow-x: auto; color: #d4d4d4;">${escapeHTML(JSON.stringify(answer, null, 2))}</pre>
                        </div>
                    </div>
                `;
                }

                content += `</div></div>`;

                document.getElementById('previewContent').innerHTML = content;
                document.getElementById('previewModal').style.display = 'flex';
            }

        } catch (error) {
            console.error('Error:', error);
            toast('❌ Failed to load submission details');
        } finally {
            hideLoading();
        }
    }

    function renderExamsTable(exams) {
        const tableContainer = document.getElementById("examsTable");
        if (!tableContainer) return;

        if (!exams || exams.length === 0) {
            tableContainer.innerHTML =
                `<tbody><tr><td colspan="8" class="empty-state">📭 No exams yet. Click "Create New Exam" to get started!</td></tr></tbody>`;
            return;
        }

        const now = new Date();

        const rows = exams.map(exam => {
            // Determine exam state
            let examState = 'draft';
            let stateClass = 'status-draft';
            let canEdit = true;
            let canDelete = true;
            let completedDateTime = '';
            let showCompletedButton = false;

            if (exam.published) {
                const startTime = exam.startAtISO ? new Date(exam.startAtISO) : null;
                const endTime = exam.endTime ? new Date(exam.endTime) : (startTime ? new Date(startTime
                    .getTime() + (exam.durationMins * 60000)) : null);

                if (startTime && startTime > now) {
                    examState = 'scheduled';
                    stateClass = 'status-scheduled';
                    canEdit = true;
                    canDelete = true;
                } else if (startTime && startTime <= now && (!endTime || endTime > now)) {
                    examState = 'ongoing';
                    stateClass = 'status-ongoing';
                    canEdit = false;
                    canDelete = false;
                } else if (endTime && endTime <= now) {
                    examState = 'completed';
                    stateClass = 'status-completed';
                    canEdit = false;
                    canDelete = true;
                    showCompletedButton = true;
                    // Format the completion date/time
                    completedDateTime = endTime.toLocaleString();
                } else {
                    examState = 'published';
                    stateClass = 'status-published';
                    canEdit = true;
                    canDelete = true;
                }
            }

            let countdown = '';
            if (examState === 'scheduled' && exam.startAtISO) {
                const startTime = new Date(exam.startAtISO);
                const diffMs = startTime - now;
                if (diffMs > 0) {
                    const hours = Math.floor(diffMs / 3600000);
                    const minutes = Math.floor((diffMs % 3600000) / 60000);
                    countdown = `<span class="timer">Starts in: ${hours}h ${minutes}m</span>`;
                }
            } else if (examState === 'ongoing' && exam.durationMins) {
                const startTime = new Date(exam.startAtISO);
                const endTime = new Date(startTime.getTime() + (exam.durationMins * 60000));
                const diffMs = endTime - now;
                if (diffMs > 0) {
                    const minutes = Math.floor(diffMs / 60000);
                    const seconds = Math.floor((diffMs % 60000) / 1000);
                    countdown = `<span class="timer warning">Ends in: ${minutes}m ${seconds}s</span>`;
                }
            }

            const totalMarks = calculateEffectiveExamMarks(exam);

            return `
            <tr>
                <td><b style="color:var(--blue)">${escapeHTML(exam.title || '(untitled)')}</b><br><span class="small">📚 ${escapeHTML(exam.courseCode || 'No course')}</span>${examState !== 'draft' ? '<br>' + countdown : ''}${examState === 'completed' ? '<br><small style="color:var(--muted);">📅 Completed: ' + completedDateTime + '</small>' : ''}</td>
                <td><span class="tag">${escapeHTML(exam.id)}</span></td>
                <td>${(exam.questions || []).length} qns / <b>${totalMarks}</b> marks</span></td>
                <td><span class="tag ${stateClass}">${examState.toUpperCase()}</span></td>
                <td style="display:flex;gap:6px; flex-wrap:wrap;">
                    <button class="btn primary" onclick="openBuilder(${exam.id})" ${!canEdit ? 'disabled' : ''}>✏️ Edit</button>
                    <button class="btn" onclick="previewCompletedExam(${exam.id})" title="Preview exam as students see it">👁️ Preview</button>
                   <button class="btn" onclick="copyExamLinkFixed(currentExamId, document.getElementById('bTitle')?.value || 'Exam')">
    <i class="fas fa-link"></i> Copy Exam Link
</button>
                    ${showCompletedButton ? 
                        `<button class="btn success" disabled style="background:#10b981; opacity:0.7;">
                            ✅ Completed <i class="fas fa-check-circle"></i>
                        </button>` : 
                        `<button class="btn ${exam.published && examState !== 'ongoing' ? 'warn' : 'ok'}" onclick="togglePublish(${exam.id})" ${!canEdit ? 'disabled' : ''}>
                            ${exam.published ? (examState === 'ongoing' ? '🔴 Ongoing' : '📦 Unpublish') : '🚀 Publish'}
                        </button>`
                    }
                    <button class="btn danger" onclick="deleteExam(${exam.id})" ${!canDelete ? 'disabled' : ''}>🗑 Delete</button>
                </td>
            </tr>`;
        }).join('');

        tableContainer.innerHTML =
            `<thead><tr><th>Exam</th><th>ID</th><th>Questions/Marks</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows}</tbody>`;

    }

    function getExamRuntimeState(exam) {
        if (!exam || !exam.published) return 'draft';
        const now = new Date();
        const start = exam.startAtISO ? new Date(exam.startAtISO.replace(' ', 'T')) : null;
        let end = exam.endAtISO ? new Date(exam.endAtISO.replace(' ', 'T')) : null;
        if (start && (!end || end <= start)) {
            end = new Date(start.getTime() + ((parseInt(exam.durationMins) || 180) * 60000));
        }
        if (start && start > now) return 'scheduled';
        if (start && start <= now && (!end || end > now)) return 'ongoing';
        if (!start && exam.published) return 'published';
        return 'completed';
    }

    function populateProctoringExamSelect(exams = getExams()) {
        const select = document.getElementById('proctoringExamSelect');
        if (!select) return;
        const selected = select.value;
        const proctorable = exams.filter(exam => ['ongoing', 'published'].includes(getExamRuntimeState(exam)));
        select.innerHTML = '<option value="">Select Exam to Proctor</option>' + proctorable.map(exam =>
            `<option value="${exam.id}">${escapeHTML(exam.title || 'Untitled Exam')} (${escapeHTML(exam.courseCode || 'No course')})</option>`
        ).join('');
        if (selected && proctorable.some(exam => String(exam.id) === String(selected))) {
            select.value = selected;
        }
    }

    function copyExamLinkFixed(examId, examTitle) {
        // Get the correct base URL - this ensures it works from any page
        const currentPath = window.location.pathname;
        const baseUrl = window.location.origin;
        let examInterfacePath = '';

        // Determine the correct path to exam_interface.php
        if (currentPath.includes('/lecturer/')) {
            examInterfacePath = baseUrl + '/student/exam_interface.php';
        } else if (currentPath.includes('/admin/')) {
            examInterfacePath = baseUrl + '/student/exam_interface.php';
        } else {
            // Default - go up one directory level
            const pathParts = currentPath.split('/');
            pathParts.pop(); // Remove current file name
            examInterfacePath = baseUrl + pathParts.join('/') + '/exam_interface.php';
        }

        // Create the full exam URL
        const examUrl = `${examInterfacePath}?exam_id=${examId}`;

        // Copy to clipboard
        navigator.clipboard.writeText(examUrl).then(() => {
            // Show success message with the URL
            toast(`✅ Exam link copied!\n\n📎 ${examUrl}\n\nShare this link with students`, 5000);

            // Optional: Show a modal with the link for easy copying
            showLinkModal(examUrl, examTitle);
        }).catch(() => {
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = examUrl;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            toast(`✅ Exam link copied to clipboard!\n\nShare: ${examUrl}`, 5000);
            showLinkModal(examUrl, examTitle);
        });
    }

    // Optional: Show a modal with the link for easy copying
    function showLinkModal(examUrl, examTitle) {
        // Create modal if it doesn't exist
        let linkModal = document.getElementById('linkModal');
        if (!linkModal) {
            linkModal = document.createElement('div');
            linkModal.id = 'linkModal';
            linkModal.className = 'modal';
            linkModal.style.display = 'none';
            linkModal.innerHTML = `
            <div class="modal-content" style="width: 500px; max-width: 90%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: var(--text);">
                        <i class="fas fa-link" style="color: #3b82f6;"></i> Exam Link
                    </h3>
                    <button onclick="closeLinkModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--muted);">&times;</button>
                </div>
                <div style="margin-bottom: 15px;">
                    <p style="color: var(--text); margin-bottom: 5px;">
                        <strong>${escapeHTML(examTitle)}</strong>
                    </p>
                    <p style="color: var(--muted); font-size: 12px; margin-bottom: 10px;">
                        Share this link with students to access the exam:
                    </p>
                    <div style="background: var(--bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border); word-break: break-all;">
                        <code style="font-size: 12px; color: var(--text);">${escapeHTML(examUrl)}</code>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn" onclick="closeLinkModal()">Close</button>
                    <button class="btn primary" onclick="copyToClipboard('${escapeHTML(examUrl)}')">
                        <i class="fas fa-copy"></i> Copy Again
                    </button>
                </div>
            </div>
        `;
            document.body.appendChild(linkModal);
        } else {
            // Update existing modal content
            const modalContent = linkModal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: var(--text);">
                        <i class="fas fa-link" style="color: #3b82f6;"></i> Exam Link
                    </h3>
                    <button onclick="closeLinkModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--muted);">&times;</button>
                </div>
                <div style="margin-bottom: 15px;">
                    <p style="color: var(--text); margin-bottom: 5px;">
                        <strong>${escapeHTML(examTitle)}</strong>
                    </p>
                    <p style="color: var(--muted); font-size: 12px; margin-bottom: 10px;">
                        Share this link with students to access the exam:
                    </p>
                    <div style="background: var(--bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border); word-break: break-all;">
                        <code style="font-size: 12px; color: var(--text);">${escapeHTML(examUrl)}</code>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn" onclick="closeLinkModal()">Close</button>
                    <button class="btn primary" onclick="copyToClipboard('${escapeHTML(examUrl)}')">
                        <i class="fas fa-copy"></i> Copy Again
                    </button>
                </div>
            `;
            }
        }

        linkModal.style.display = 'flex';
    }

    function closeLinkModal() {
        const modal = document.getElementById('linkModal');
        if (modal) modal.style.display = 'none';
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            toast('✅ Link copied to clipboard again!');
        }).catch(() => {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            toast('✅ Link copied to clipboard!');
        });
    }


    function showEqualMarksModal() {
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.style.display = 'flex';
        modal.innerHTML = `
        <div class="modal-content" style="width: 500px;">
            <h3><i class="fas fa-balance-scale"></i> Equal Marks Distribution</h3>
            <div class="field">
                <label>Select Exam:</label>
                <select id="equalMarksExamSelect" style="width:100%; padding: 10px;">
                    <option value="">Select an exam...</option>
                    ${getExams().filter(e => e.published).map(e => `<option value="${e.id}">${escapeHTML(e.title)}</option>`).join('')}
                </select>
            </div>
            <div class="field">
                <label>Marks per Question:</label>
                <input type="number" id="equalMarksValue" min="1" max="100" step="0.5" style="width:100%; padding: 10px;">
            </div>
            <div class="modal-actions">
                <button class="btn" onclick="this.parentElement.parentElement.parentElement.remove()">Cancel</button>
                <button class="btn primary" onclick="applyEqualMarks()">Apply to All Questions</button>
            </div>
        </div>
    `;
        document.body.appendChild(modal);
    }

    function applyEqualMarks() {
        const examId = document.getElementById('equalMarksExamSelect').value;
        const marksValue = parseFloat(document.getElementById('equalMarksValue').value);

        if (!examId || !marksValue || marksValue <= 0) {
            toast('❌ Please select an exam and enter valid marks');
            return;
        }

        const exams = getExams();
        const exam = exams.find(e => e.id == examId);
        if (exam && exam.questions) {
            exam.questions.forEach(q => {
                q.marks = marksValue;
                if (q.subQuestions && q.subQuestions.length > 0) {
                    q.subQuestions.forEach(sq => sq.marks = marksValue);
                }
            });
            setExams(exams);
            saveExamToDatabase();
            renderQuestions();
            toast(`✅ Applied ${marksValue} marks to all questions in ${exam.title}`);
        }
        closeModal();
    }

    async function newExam() {
        const approved = await confirmPopup(
            'Open a blank exam builder?\nNothing will be saved to the database until you click Save Draft or Publish Exam.',
            'Create new exam',
            'Open Builder'
        );
        if (!approved) return;

        const tempId = -Date.now();
        const exams = getExams().filter(e => parseInt(e.id) > 0);
        const exam = {
            id: tempId,
            title: '',
            courseCode: '',
            durationMins: 180,
            startAtISO: '',
            endAtISO: '',
            instructions: '- Attempt ALL questions...',
            markingScheme: '',
            published: false,
            questions: [],
            questionsToAnswer: 0,
            shuffleEnabled: false,
            gradingMode: 'auto',
            exam_password: '',
            school_name: '',
            faculty_name: '',
            department: '',
            semester: '',
            exam_type: '',
            school_type: '',
            level: '',
            auto_grading_enabled: 0,
            partial_grading_enabled: 0,
            show_correct_answers: 0,
            allow_review: 1
        };

        exams.unshift(exam);
        setExams(exams);
        currentExamId = tempId;
        sessionStorage.setItem('currentExamId', tempId);
        sessionStorage.setItem('currentView', 'builder');
        sessionStorage.setItem('currentRouteParams', JSON.stringify({ id: tempId }));
        resetExamBuilderForm();
        populateBuilderForm(exam);
        renderQuestions();
        go('builder', { id: tempId });
        toast('Blank exam opened. Save Draft only when you want it stored.');
        return;

        showLoading('Creating new exam...');
        try {
            const result = await apiRequest('create_exam', {
                title: '',
                course_code: '',
                duration: 180,
                start_datetime: '',
                end_datetime: '',
                instructions: '- Attempt ALL questions...',
                marking_scheme: '',
                questions_to_answer: 0,
                shuffle_enabled: 0,
                grading_mode: 'auto'
            });

            if (result.success) {
                toast('✅ New exam created');
                await loadExamsList();
                const examId = result.exam_id;
                sessionStorage.setItem('currentExamId', examId);
                sessionStorage.setItem('currentView', 'builder');
                sessionStorage.setItem('currentRouteParams', JSON.stringify({
                    id: examId
                }));
                openBuilder(examId);
            } else {
                toast('❌ Failed to create exam: ' + (result.error || 'Unknown error'));
                hideLoading();
            }
        } catch (error) {
            console.error('Error creating exam:', error);
            toast('❌ Error creating exam');
            hideLoading();
        }
    }

    async function openBuilder(examId) {
        console.log("Opening builder for exam ID:", examId);
        showLoading('Loading exam...');

        try {
            const result = await apiRequest('get_exams');

            if (result.success && result.data) {
                const exams = result.data.map(exam => {
                    let questions = [];
                    if (exam.questions) {
                        if (typeof exam.questions === 'string') {
                            try {
                                questions = JSON.parse(exam.questions);
                            } catch (e) {
                                console.error("Failed to parse questions:", e);
                                questions = [];
                            }
                        } else if (Array.isArray(exam.questions)) {
                            questions = exam.questions;
                        }
                    }
                    return {
                        id: exam.id,
                        title: exam.title || '',
                        courseCode: exam.course_code || '',
                        durationMins: exam.duration_minutes || 180,
                        startAtISO: exam.start_datetime || '',
                        endAtISO: exam.end_datetime || '',
                        instructions: exam.instructions || '',
                        markingScheme: exam.marking_scheme || '',
                        published: exam.published == 1,
                        questions: questions,
                        questionsToAnswer: exam.questions_to_answer || 0,
                        shuffleEnabled: exam.shuffle_enabled == 1,
                        gradingMode: exam.grading_mode || 'auto',
                        exam_password: exam.exam_password || '',
                        school_name: exam.school_name || '',
                        faculty_name: exam.faculty_name || '',
                        department: exam.department || '',
                        semester: exam.semester || '',
                        exam_type: exam.exam_type || '',
                        school_type: exam.school_type || '',
                        level: exam.level || '',
                        auto_grading_enabled: exam.auto_grading_enabled || 0,
                        partial_grading_enabled: exam.partial_grading_enabled || 0,
                        show_correct_answers: exam.show_correct_answers || 0,
                        allow_review: exam.allow_review !== null ? exam.allow_review : 1
                    };
                });

                setExams(exams);
                const exam = exams.find(e => parseInt(e.id) === parseInt(examId));

                if (exam) {
                    console.log("Found exam with questions:", exam.questions.length);
                    currentExamId = parseInt(exam.id);
                    sessionStorage.setItem('currentExamId', currentExamId);
                    sessionStorage.setItem('currentView', 'builder');
                    populateBuilderForm(exam);
                    setTimeout(() => {
                        renderQuestions();
                        console.log("Questions rendered after timeout");
                    }, 100);
                    go('builder', {
                        id: examId
                    });
                    hideLoading();
                    return;
                }
            }

            toast("❌ Exam not found");
            go('exams');
            hideLoading();

        } catch (error) {
            console.error('Error loading exam:', error);
            hideLoading();
            toast("❌ Error loading exam");
            go('exams');
        }
    }

    function populateBuilderForm(exam) {
        console.log("Populating builder for exam:", exam);

        if (!exam) {
            console.error("No exam provided to populateBuilderForm");
            return;
        }

        currentExamId = parseInt(exam.id);
        sessionStorage.setItem('currentExamId', currentExamId);
        sessionStorage.setItem('currentView', 'builder');

        if (!exam.questions) {
            exam.questions = [];
        }

        // Populate form fields AND update exam object
        const titleInput = document.getElementById("bTitle");
        const codeInput = document.getElementById("bCode");
        const durationInput = document.getElementById("bDuration");
        const instructionsInput = document.getElementById("bInstructions");
        const startAtInput = document.getElementById("bStartAt");
        const endAtInput = document.getElementById("bEndAt");
        const questionsToAnswerInput = document.getElementById("bQuestionsToAnswer");
        const examPasswordInput = document.getElementById("examPassword");
        const schoolNameInput = document.getElementById("school_name");
        const facultyNameInput = document.getElementById("faculty_name");
        const departmentInput = document.getElementById("department");
        const semesterSelect = document.getElementById("semester");
        const examTypeSelect = document.getElementById("exam_type");
        const schoolTypeSelect = document.getElementById("school_type");
        const levelSelect = document.getElementById("level");

        // Set values and update exam object
        if (titleInput) {
            titleInput.value = exam.title || "";
            updateExamField('title', exam.title || "");
        }
        if (codeInput) {
            codeInput.value = exam.courseCode || "";
            updateExamField('courseCode', exam.courseCode || "");
        }
        if (durationInput) {
            durationInput.value = exam.durationMins || 180;
            updateExamField('durationMins', exam.durationMins || 180);
        }
        if (instructionsInput) {
            instructionsInput.value = exam.instructions || "";
            updateExamField('instructions', exam.instructions || "");
        }
        if (startAtInput) {
            startAtInput.value = exam.startAtISO ? exam.startAtISO.slice(0, 16) : "";
            updateExamField('startAtISO', exam.startAtISO || "");
        }
        if (endAtInput) {
            endAtInput.value = exam.endAtISO ? exam.endAtISO.slice(0, 16) : "";
            updateExamField('endAtISO', exam.endAtISO || "");
        }
        if (questionsToAnswerInput) {
            questionsToAnswerInput.value = exam.questionsToAnswer || 0;
            updateExamField('questionsToAnswer', exam.questionsToAnswer || 0);
        }
        if (examPasswordInput) {
            examPasswordInput.value = "";
            examPasswordInput.placeholder = exam.exam_password ? "Password is set. Enter a new password only if changing it." : "Create a password for students";
            updateExamField('exam_password', "");
        }
        if (schoolNameInput && exam.school_name) {
            schoolNameInput.value = exam.school_name;
            updateExamField('school_name', exam.school_name);
        }
        if (facultyNameInput && exam.faculty_name) {
            facultyNameInput.value = exam.faculty_name;
            updateExamField('faculty_name', exam.faculty_name);
        }
        if (departmentInput && exam.department) {
            departmentInput.value = exam.department;
            updateExamField('department', exam.department);
        }
        if (semesterSelect && exam.semester) {
            semesterSelect.value = exam.semester;
            updateExamField('semester', exam.semester);
        }
        if (examTypeSelect && exam.exam_type) {
            examTypeSelect.value = exam.exam_type;
            updateExamField('exam_type', exam.exam_type);
        }
        if (schoolTypeSelect && exam.school_type) {
            schoolTypeSelect.value = exam.school_type;
            updateExamField('school_type', exam.school_type);
        }
        if (levelSelect && exam.level) {
            levelSelect.value = exam.level;
            updateExamField('level', exam.level);
        }
        if (exam.gradingMode) {
            gradingMode = exam.gradingMode;
            const gradingModeSelect = document.getElementById('gradingMode');
            if (gradingModeSelect) gradingModeSelect.value = gradingMode;
        }

        const autoGrading = document.getElementById('enableAutoGrading');
        if (autoGrading) autoGrading.checked = exam.auto_grading_enabled == 1 || gradingMode === 'auto' || gradingMode === 'hybrid';
        const partialGrading = document.getElementById('enablePartialGrading');
        if (partialGrading) partialGrading.checked = exam.partial_grading_enabled == 1 || gradingMode === 'hybrid';
        const showAnswers = document.getElementById('showCorrectAnswers');
        if (showAnswers) showAnswers.checked = exam.show_correct_answers == 1;
        const allowReview = document.getElementById('allowReview');
        if (allowReview) allowReview.checked = exam.allow_review !== 0 && exam.allow_review !== '0';

        shuffleEnabled = exam.shuffleEnabled || false;
        const shuffleBtn = document.getElementById('shuffleBtn');
        if (shuffleBtn) {
            const btnText = document.getElementById('shuffleBtnText');
            if (btnText) {
                btnText.textContent = shuffleEnabled ? 'Shuffle Questions: ON' : 'Shuffle Questions: OFF';
            }
        }

        const builderMeta = document.getElementById("builderMeta");
        if (builderMeta) {
            builderMeta.textContent = exam.published ? "Published" : "Draft";
        }

        const builderCrumb = document.getElementById("builderCrumb");
        if (builderCrumb) {
            builderCrumb.innerHTML =
                `Home / Create / Edit / <span style="color:var(--blue);font-weight:600">${exam.title || exam.id}</span>`;
        }

        console.log("Builder form populated, exam has", exam.questions.length, "questions");

        // Force save to localStorage
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx] = exam;
            setExams(exams);
            console.log("Exam saved to localStorage after population");
        }
    }

    function syncDurationFromStartEnd() {
        const startField = document.getElementById('bStartAt');
        const endField = document.getElementById('bEndAt');
        const durationField = document.getElementById('bDuration');
        if (!startField || !endField || !durationField || !startField.value || !endField.value) return;
        const start = new Date(startField.value);
        const end = new Date(endField.value);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) return;
        const minutes = Math.max(1, Math.round((end - start) / 60000));
        durationField.value = minutes;
        updateExamField('durationMins', minutes);
        updateExamField('endAtISO', endField.value);
    }

    function syncEndTimeFromDuration() {
        const startField = document.getElementById('bStartAt');
        const endField = document.getElementById('bEndAt');
        const durationField = document.getElementById('bDuration');
        if (!startField || !endField || !durationField || !startField.value) return;
        const start = new Date(startField.value);
        const minutes = parseInt(durationField.value, 10) || 180;
        if (Number.isNaN(start.getTime())) return;
        const end = new Date(start.getTime() + minutes * 60000);
        endField.value = toDateTimeLocalValue(end);
        updateExamField('durationMins', minutes);
        updateExamField('endAtISO', endField.value);
    }

    function toDateTimeLocalValue(date) {
        const pad = value => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    async function saveExamToDatabase() {
        if (!currentExamId) {
            console.log("No exam selected to save");
            return false;
        }

        const exams = getExams();
        const exam = exams.find(e => parseInt(e.id) === parseInt(currentExamId));

        if (!exam) {
            console.log("Exam not found in localStorage");
            return false;
        }

        try {
            const questionsJSON = JSON.stringify(exam.questions || []);
            console.log("Saving questions:", exam.questions.length, "questions");

            if (parseInt(currentExamId) < 0) {
                const created = await apiRequest('create_exam', {
                    title: exam.title || '',
                    course_code: exam.courseCode || '',
                    duration: exam.durationMins || 180,
                    start_datetime: exam.startAtISO || '',
                    end_datetime: exam.endAtISO || '',
                    instructions: exam.instructions || '',
                    marking_scheme: exam.markingScheme || '',
                    questions: questionsJSON,
                    questions_to_answer: exam.questionsToAnswer || 0,
                    shuffle_enabled: exam.shuffleEnabled ? 1 : 0,
                    grading_mode: exam.gradingMode || 'auto',
                    school_name: exam.school_name || '',
                    faculty_name: exam.faculty_name || '',
                    department: exam.department || '',
                    semester: exam.semester || '',
                    exam_type: exam.exam_type || '',
                    school_type: exam.school_type || '',
                    level: exam.level || '',
                    exam_password: exam.exam_password || '',
                    auto_grading_enabled: exam.auto_grading_enabled || 0,
                    partial_grading_enabled: exam.partial_grading_enabled || 0,
                    show_correct_answers: exam.show_correct_answers || 0,
                    allow_review: exam.allow_review !== undefined ? exam.allow_review : 1
                });

                if (!created.success) {
                    console.error("Failed to create draft:", created.error);
                    return false;
                }

                const oldId = currentExamId;
                currentExamId = parseInt(created.exam_id);
                exam.id = currentExamId;
                const tempIndex = exams.findIndex(e => parseInt(e.id) === parseInt(oldId));
                if (tempIndex >= 0) exams[tempIndex] = exam;
                setExams(exams);
                sessionStorage.setItem('currentExamId', currentExamId);
                sessionStorage.setItem('currentRouteParams', JSON.stringify({ id: currentExamId }));
            }

            const result = await apiRequest('update_exam', {
                exam_id: currentExamId,
                title: exam.title || '',
                course_code: exam.courseCode || '',
                duration: exam.durationMins || 180,
                start_datetime: exam.startAtISO || '',
                end_datetime: exam.endAtISO || '',
                instructions: exam.instructions || '',
                marking_scheme: exam.markingScheme || '',
                questions: questionsJSON,
                questions_to_answer: exam.questionsToAnswer || 0,
                shuffle_enabled: exam.shuffleEnabled ? 1 : 0,
                grading_mode: exam.gradingMode || 'auto',
                school_name: exam.school_name || '',
                faculty_name: exam.faculty_name || '',
                department: exam.department || '',
                semester: exam.semester || '',
                exam_type: exam.exam_type || '',
                school_type: exam.school_type || '',
                level: exam.level || '',
                exam_password: exam.exam_password || '',
                auto_grading_enabled: exam.auto_grading_enabled || 0,
                partial_grading_enabled: exam.partial_grading_enabled || 0,
                show_correct_answers: exam.show_correct_answers || 0,
                allow_review: exam.allow_review !== undefined ? exam.allow_review : 1,
                published: exam.published ? 1 : 0
            });

            if (result.success) {
                console.log("Exam saved to database successfully");
                const exams = getExams();
                const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
                if (idx >= 0) {
                    exams[idx] = exam;
                    setExams(exams);
                }
                return true;
            } else {
                console.error("Failed to save exam:", result.error);
                return false;
            }
        } catch (error) {
            console.error("Error saving exam:", error);
            return false;
        }
    }

    async function saveDraftExam() {
        const approved = await confirmPopup(
            'Save this unfinished exam as a draft in the database?',
            'Save draft',
            'Save Draft'
        );
        if (!approved) return;
        showLoading(true);
        try {
            const saved = await saveExamToDatabase();
            showMessage(saved ? 'Draft saved. You can continue setting it later.' : 'Unable to save draft right now.', saved ? 'success' : 'error');
        } finally {
            showLoading(false);
        }
    }

    async function deleteExam(examId) {
        const approved = await confirmPopup(
            "Delete this exam?\nThis action cannot be undone. All student submissions will also be deleted.",
            "Delete exam",
            "Delete Exam"
        );
        if (!approved) return;
        const originalConfirm = window.confirm;
        window.confirm = () => true;
        setTimeout(() => { window.confirm = originalConfirm; }, 0);
        if (!confirm(
                "⚠️ Delete this exam? This action cannot be undone. All student submissions will also be deleted."))
            return;
        showLoading('Deleting exam...');
        try {
            const result = await apiRequest('delete_exam', {
                exam_id: examId
            });
            if (result.success) {
                toast('🗑 Exam deleted successfully');
                const exams = getExams().filter(e => parseInt(e.id) != parseInt(examId));
                setExams(exams);
                if (currentExamId == examId) {
                    currentExamId = null;
                    sessionStorage.removeItem('currentExamId');
                }
                await loadExamsList();
                go('exams');
            } else {
                toast('❌ ' + (result.error || 'Failed to delete exam'));
            }
        } catch (error) {
            console.error('Delete error:', error);
            toast('❌ Network error. Please try again.');
        } finally {
            hideLoading();
        }
    }

    function deleteCurrentExam() {
        if (currentExamId) {
            deleteExam(currentExamId);
        } else {
            toast("❌ No exam to delete");
        }
    }

    function getQuestionMarks(question) {
        if (!question) return 0;
        if (question.hasSubQuestions && Array.isArray(question.subQuestions) && question.subQuestions.length) {
            return question.subQuestions.reduce((sum, subQuestion) => sum + (parseFloat(subQuestion.marks) || 0), 0);
        }
        return parseFloat(question.marks) || 0;
    }

    function getQuestionAnswerLimit(examOrQuestions, explicitLimit) {
        const questions = Array.isArray(examOrQuestions) ? examOrQuestions : (examOrQuestions?.questions || []);
        const rawLimit = explicitLimit !== undefined ? explicitLimit : (examOrQuestions?.questionsToAnswer ?? examOrQuestions?.questions_to_answer ?? 0);
        const parsedLimit = parseInt(rawLimit, 10) || 0;
        return parsedLimit > 0 ? Math.min(parsedLimit, questions.length) : questions.length;
    }

    function calculateEffectiveExamMarks(examOrQuestions, explicitLimit) {
        const questions = Array.isArray(examOrQuestions) ? examOrQuestions : (examOrQuestions?.questions || []);
        const limit = getQuestionAnswerLimit(examOrQuestions, explicitLimit);
        const compulsory = questions.filter(q => !!q.compulsory);
        const optionalSlots = Math.max(0, limit - compulsory.length);
        const optionalMarks = questions
            .filter(q => !q.compulsory)
            .map(getQuestionMarks)
            .sort((a, b) => b - a)
            .slice(0, optionalSlots);
        return compulsory.reduce((sum, q) => sum + getQuestionMarks(q), 0) +
            optionalMarks.reduce((sum, marks) => sum + marks, 0);
    }

    function describeQuestionAnswerRule(examOrQuestions, explicitLimit) {
        const questions = Array.isArray(examOrQuestions) ? examOrQuestions : (examOrQuestions?.questions || []);
        const limit = getQuestionAnswerLimit(examOrQuestions, explicitLimit);
        if (!questions.length || limit >= questions.length) return 'Answer all questions';
        const compulsoryCount = questions.filter(q => !!q.compulsory).length;
        return compulsoryCount > 0 ?
            `Answer ${limit} questions including ${compulsoryCount} compulsory question(s)` :
            `Answer any ${limit} question(s)`;
    }

    function refreshQuestionAnswerRuleHint() {
        const hint = document.getElementById('questionAnswerRuleHint');
        if (!hint || !currentExamId) return;
        const exam = findExam(currentExamId);
        if (!exam) return;
        const qta = parseInt(document.getElementById('bQuestionsToAnswer')?.value) || 0;
        exam.questionsToAnswer = qta;
        hint.textContent = `${describeQuestionAnswerRule(exam, qta)}. Total obtainable marks: ${calculateEffectiveExamMarks(exam, qta)}.`;
    }

    async function togglePublish(examId) {
        const selectedExam = getExams().find(e => parseInt(e.id) === parseInt(examId));
        const isPublished = !!selectedExam?.published;
        const approved = await confirmPopup(
            isPublished ? 'Unpublish this exam? Students will no longer see it as available.' : 'Publish this exam? Enrolled and visible students will see it on their dashboard.',
            isPublished ? 'Unpublish exam' : 'Publish exam',
            isPublished ? 'Unpublish' : 'Publish'
        );
        if (!approved) return;

        const result = await apiRequest('toggle_publish', {
            exam_id: examId
        });
        if (result.success) {
            toast("📦 Status updated");
            loadExamsList();
        } else {
            const exams = getExams();
            const i = exams.findIndex(e => parseInt(e.id) === parseInt(examId));
            if (i < 0) return;
            exams[i].published = !exams[i].published;
            setExams(exams);
            toast(exams[i].published ? "🚀 Published" : "📦 Unpublished");
        }
    }

    async function publishExam() {
        console.log("===== PUBLISH EXAM STARTED =====");

        if (!currentExamId) {
            showMessage("❌ No exam selected", "error");
            return;
        }

        // ========== CAPTURE ALL FORM FIELDS ==========
        const title = document.getElementById('bTitle')?.value || '';
        const courseCode = document.getElementById('bCode')?.value || '';
        const duration = parseInt(document.getElementById('bDuration')?.value) || 180;
        const instructions = document.getElementById('bInstructions')?.value || '';
        const startDatetime = document.getElementById('bStartAt')?.value || null;
        const endDatetime = document.getElementById('bEndAt')?.value || null;
        const questionsToAnswer = parseInt(document.getElementById('bQuestionsToAnswer')?.value) || 0;
        const examPassword = document.getElementById('examPassword')?.value || '';

        // Academic Information
        const semester = document.getElementById('semester')?.value || '';
        const examType = document.getElementById('exam_type')?.value || '';
        const schoolType = document.getElementById('school_type')?.value || '';
        const level = document.getElementById('level')?.value || '';

        // Grading Options
        const autoGradingEnabled = document.getElementById('enableAutoGrading')?.checked ? 1 : 0;
        const partialGradingEnabled = document.getElementById('enablePartialGrading')?.checked ? 1 : 0;
        const showCorrectAnswers = document.getElementById('showCorrectAnswers')?.checked ? 1 : 0;
        const allowReview = document.getElementById('allowReview')?.checked !== false ? 1 : 0;

        // Get questions
        const exams = getExams();
        const exam = exams.find(e => parseInt(e.id) === parseInt(currentExamId));
        const questions = exam?.questions || [];

        const totalMarks = calculateEffectiveExamMarks(questions, questionsToAnswer);

        // ========== VALIDATION ==========
        const errors = [];
        if (!title) errors.push('Exam Title is required');
        if (!courseCode) errors.push('Course Code is required');
        if (!duration || duration <= 0) errors.push('Valid Duration is required');
        if (!instructions) errors.push('Instructions are required');
        if (!semester) errors.push('Semester is required');
        if (!examType) errors.push('Exam Type is required');
        if (!schoolType) errors.push('School Type is required');
        if (!level) errors.push('Level is required');
        if (!questions || questions.length === 0) errors.push('At least one question is required');

        if (errors.length > 0) {
            showMessage("❌ Please fix:\n" + errors.join('\n'), "error");
            return;
        }

        const approved = await confirmPopup(
            `Publish "${title}" for ${courseCode}?\n${describeQuestionAnswerRule(questions, questionsToAnswer)}. Total obtainable marks: ${totalMarks}.\nStudents who are enrolled and visible will see it according to the start and end time.`,
            'Publish exam',
            'Publish Exam'
        );
        if (!approved) return;

        // ========== PREPARE DATA ==========
        const publishData = {
            action: 'publish_exam',
            exam_id: currentExamId,
            title: title,
            course_code: courseCode,
            duration: duration,
            start_datetime: startDatetime,
            end_datetime: endDatetime,
            instructions: instructions,
            marking_scheme: exam?.markingScheme || '',
            questions: JSON.stringify(questions),
            questions_to_answer: questionsToAnswer,
            shuffle_enabled: exam?.shuffleEnabled ? 1 : 0,
            grading_mode: exam?.gradingMode || 'auto',
            school_name: '',
            faculty_name: '',
            department: '',
            semester: semester,
            exam_type: examType,
            school_type: schoolType,
            level: level,
            exam_password: examPassword,
            total_marks: totalMarks,
            auto_grading_enabled: autoGradingEnabled,
            partial_grading_enabled: partialGradingEnabled,
            show_correct_answers: showCorrectAnswers,
            allow_review: allowReview
        };

        console.log("📤 Sending to API:", publishData);

        showLoading(true);

        try {
            const result = await apiRequest('publish_exam', publishData);
            console.log("✅ API Response:", result);

            if (result.success) {
                // Show success message
                showMessage("✅ Exam Published Successfully!", "success");

                // Reset the form
                resetExamBuilderForm();

                // Refresh exams list
                await loadExamsList();
                go('exams');

            } else {
                showMessage("❌ " + (result.error || 'Failed to publish exam'), "error");
            }
        } catch (error) {
            console.error('❌ Publish error:', error);
            showMessage("❌ Network error: " + error.message, "error");
        } finally {
            showLoading(false);
        }
    }

    // ========== IMPROVED MESSAGE FUNCTION ==========
    function showMessage(message, type = 'info') {
        // Create a modal/dialog for success message
        if (type === 'success') {
            // Create a nice success modal
            const modal = document.createElement('div');
            modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10001;
            animation: fadeIn 0.3s ease;
        `;
            modal.innerHTML = `
            <div style="background: var(--panel); border-radius: 20px; padding: 40px; max-width: 400px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
                <div style="font-size: 60px; margin-bottom: 15px;">✅</div>
                <h2 style="color: var(--text); margin-bottom: 10px;">Success!</h2>
                <p style="color: var(--muted); margin-bottom: 20px;">${message}</p>
                <button onclick="this.parentElement.parentElement.remove()" 
                    style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 12px 30px; border-radius: 30px; cursor: pointer; font-size: 14px; font-weight: 600;">
                    OK
                </button>
            </div>
        `;
            document.body.appendChild(modal);

            // Auto close after 3 seconds
            setTimeout(() => {
                if (modal && modal.parentElement) {
                    modal.remove();
                }
            }, 3000);
        } else {
            toast(message);
        }
    }

    // ========== IMPROVED LOADING FUNCTION ==========
    function showLoading(show) {
        let loader = document.getElementById('globalLoader');
        if (show) {
            if (!loader) {
                loader = document.createElement('div');
                loader.id = 'globalLoader';
                loader.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.7);
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-direction: column;
                color: white;
            `;
                loader.innerHTML = `
                <div class="spinner" style="width: 50px; height: 50px;"></div>
                <div style="margin-top: 20px; font-size: 16px;">Publishing exam...</div>
            `;
                document.body.appendChild(loader);
            } else {
                loader.style.display = 'flex';
            }
        } else {
            if (loader) {
                loader.style.display = 'none';
            }
        }
    }

    // ========== NEW FUNCTION: Reset all form fields ==========
    function resetExamBuilderForm() {
        console.log("🔄 Resetting exam builder form...");

        // Reset text inputs
        const textFields = ['bTitle', 'bCode', 'examPassword', 'bInstructions'];
        textFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.value = '';
        });

        // Reset school information fields
        const schoolFields = ['school_name', 'faculty_name', 'department'];
        schoolFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.value = '';
        });

        // Reset dropdowns to first option (empty/default)
        const dropdowns = ['semester', 'exam_type', 'school_type', 'level'];
        dropdowns.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.selectedIndex = 0;
        });

        // Reset number fields to defaults
        const durationField = document.getElementById('bDuration');
        if (durationField) durationField.value = '180';

        const questionsToAnswerField = document.getElementById('bQuestionsToAnswer');
        if (questionsToAnswerField) questionsToAnswerField.value = '0';

        // Reset datetime field
        const startAtField = document.getElementById('bStartAt');
        if (startAtField) startAtField.value = '';

        const endAtField = document.getElementById('bEndAt');
        if (endAtField) endAtField.value = '';

        // Reset checkboxes
        const autoGrading = document.getElementById('enableAutoGrading');
        if (autoGrading) autoGrading.checked = false;

        const partialGrading = document.getElementById('enablePartialGrading');
        if (partialGrading) partialGrading.checked = false;

        const showAnswers = document.getElementById('showCorrectAnswers');
        if (showAnswers) showAnswers.checked = false;

        const allowReview = document.getElementById('allowReview');
        if (allowReview) allowReview.checked = true;

        // Reset shuffle button
        shuffleEnabled = false;
        const shuffleBtnText = document.getElementById('shuffleBtnText');
        if (shuffleBtnText) shuffleBtnText.textContent = 'Shuffle Questions: OFF';

        // Clear questions container
        const qList = document.getElementById('qList');
        if (qList) {
            qList.innerHTML = '';
            qList.style.display = 'none';
        }

        // Show no questions message
        const noQuestionsMsg = document.getElementById('noQuestionsMessage');
        if (noQuestionsMsg) noQuestionsMsg.style.display = 'block';

        // Hide qtype button bar
        const qtypeBar = document.getElementById('qtypeButtonBar');
        if (qtypeBar) qtypeBar.classList.remove('visible');

        // Reset builder meta text
        const builderMeta = document.getElementById('builderMeta');
        if (builderMeta) builderMeta.textContent = 'Create New Exam';

        // Reset crumb
        const builderCrumb = document.getElementById('builderCrumb');
        if (builderCrumb) builderCrumb.innerHTML = 'Home / Create Exam';

        // Clear current exam ID
        currentExamId = null;
        sessionStorage.removeItem('currentExamId');

        // Hide student visibility list if open
        const studentListContainer = document.getElementById('enrolledStudentsListContainer');
        if (studentListContainer) studentListContainer.style.display = 'none';
        studentListVisible = false;

        // Remove validation error styling
        document.querySelectorAll('.validation-error').forEach(el => {
            el.classList.remove('validation-error');
        });

        console.log("✅ Form reset complete");
    }

    // ========== NEW FUNCTION: Create a new exam after publish ==========
    async function createNewExamAfterPublish() {
        console.log("🆕 Creating new exam...");

        try {
            const result = await apiRequest('create_exam_advanced', {
                title: 'New Exam',
                course_code: '',
                duration: 180,
                instructions: '- Attempt ALL questions...',
                questions: '[]'
            });

            if (result.success) {
                const newExamId = result.exam_id;
                console.log("✅ New exam created with ID:", newExamId);

                // Set new current exam ID
                currentExamId = newExamId;
                sessionStorage.setItem('currentExamId', currentExamId);

                // Clear any existing questions from localStorage
                const exams = getExams();
                const newExam = exams.find(e => parseInt(e.id) === parseInt(newExamId));
                if (newExam) {
                    newExam.questions = [];
                    setExams(exams);
                }

                // Update builder meta
                const builderMeta = document.getElementById('builderMeta');
                if (builderMeta) builderMeta.textContent = 'Create New Exam';

                // Focus on the first field (Exam Title)
                const titleField = document.getElementById('bTitle');
                if (titleField) {
                    titleField.focus();
                    titleField.placeholder = 'e.g., Final Examination - Introduction to Programming';
                }

                toast('✅ New exam ready! Fill in the details below.');

                // Reload exams list in background
                await loadExamsList();

            } else {
                console.error("Failed to create new exam:", result.error);
                toast('⚠️ Exam published but could not create new form. Refresh the page.');
            }
        } catch (error) {
            console.error("Error creating new exam:", error);
            toast('⚠️ Exam published. Please refresh to create another exam.');
        }
    }

    // ========== Helper: Clear questions from the builder ==========
    function clearAllQuestions() {
        if (currentExamId) {
            const exams = getExams();
            const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
            if (idx >= 0) {
                exams[idx].questions = [];
                setExams(exams);
            }
        }
        renderQuestions();
    }

    function showValidationErrors(errors) {
        if (!errors || errors.length === 0) return;

        // Remove existing error container if any
        const existingContainer = document.getElementById('validationErrorsContainer');
        if (existingContainer) existingContainer.remove();

        // Create error container
        const errorContainer = document.createElement('div');
        errorContainer.id = 'validationErrorsContainer';
        errorContainer.style.cssText = `
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 10000;
            max-width: 400px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            animation: slideInRight 0.3s ease-out;
        `;

        errorContainer.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 20px;"></i>
                <strong style="font-size: 16px;">Cannot Publish Exam</strong>
                <button onclick="this.parentElement.parentElement.remove()" 
                    style="margin-left: auto; background: none; border: none; color: white; cursor: pointer; font-size: 18px;">
                    &times;
                </button>
            </div>
            <ul style="margin: 0; padding-left: 20px;">
                ${errors.map(err => `<li style="margin: 5px 0;">${escapeHTML(err)}</li>`).join('')}
            </ul>
        `;

        document.body.appendChild(errorContainer);

        // Auto-remove after 8 seconds
        setTimeout(() => {
            if (errorContainer && errorContainer.parentElement) {
                errorContainer.remove();
            }
        }, 8000);
    }

    function showSuccessMessage(title, message) {
        // Create success modal
        let successModal = document.getElementById('successModal');
        if (!successModal) {
            successModal = document.createElement('div');
            successModal.id = 'successModal';
            successModal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10001;
                animation: fadeIn 0.3s ease;
            `;
            document.body.appendChild(successModal);
        }

        successModal.innerHTML = `
            <div style="background: var(--panel); border-radius: 20px; padding: 30px; max-width: 400px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
                <div style="font-size: 60px; margin-bottom: 15px;">✅</div>
                <h2 style="color: var(--text); margin-bottom: 10px;">${escapeHTML(title)}</h2>
                <p style="color: var(--muted); margin-bottom: 20px;">${escapeHTML(message)}</p>
                <button onclick="document.getElementById('successModal').remove()" 
                    class="btn primary" style="padding: 12px 30px;">
                    OK
                </button>
            </div>
        `;

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (successModal && successModal.parentElement) {
                successModal.remove();
            }
        }, 5000);
    }

    // Add real-time validation on input fields
    const validationFields = ['bTitle', 'bCode', 'bDuration', 'bInstructions', 'semester', 'exam_type', 'school_type', 'level'
    ];
    validationFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', function() {
                this.classList.remove('validation-error');
                // Hide error container when user starts fixing
                const errorContainer = document.getElementById('validationErrorsContainer');
                if (errorContainer) errorContainer.remove();
            });
            field.addEventListener('change', function() {
                this.classList.remove('validation-error');
                const errorContainer = document.getElementById('validationErrorsContainer');
                if (errorContainer) errorContainer.remove();
            });
        }
    });

    function previewExam() {
        if (!currentExamId) {
            toast('No exam selected');
            return;
        }
        previewExamFromList(currentExamId);
    }

    function previewExamFromList(examId) {
        const exam = findExam(examId);
        if (!exam) {
            toast('❌ Exam not found');
            return;
        }

        const totalMarks = calculateEffectiveExamMarks(exam);
        const questionCount = (exam.questions || []).length;
        const durationText = exam.durationMins ? `${Math.floor(exam.durationMins / 60)}h ${exam.durationMins % 60}m` :
            'N/A';

        let questionsHtml = '';
        if (!exam.questions || exam.questions.length === 0) {
            questionsHtml = `<div style="text-align:center; padding:40px; color:var(--muted);">
                <i class="fas fa-question-circle" style="font-size:40px; margin-bottom:10px;"></i>
                <p>No questions added yet.</p>
            </div>`;
        } else {
            questionsHtml = exam.questions.map((q, idx) => {
                const qMarks = q.hasSubQuestions && q.subQuestions?.length ?
                    q.subQuestions.reduce((s, sq) => s + (parseFloat(sq.marks) || 0), 0) :
                    parseFloat(q.marks) || 0;

                let answerArea = '';
                if (q.type === 'code') {
                    answerArea = `
                        <div style="margin-top:12px;">
                            <div style="font-size:12px; font-weight:600; color:var(--muted); margin-bottom:6px;">
                                <i class="fas fa-terminal"></i> Student Code (${escapeHTML(q.language || 'Code')}):
                            </div>
                            <textarea rows="6" disabled placeholder="Student types code here..."
                                style="width:100%; padding:12px; border-radius:10px; border:2px solid var(--border); background:var(--input-bg); color:var(--text); font-family:monospace; font-size:13px; resize:vertical;"></textarea>
                        </div>`;

                    if (q.hasSubQuestions && q.subQuestions?.length) {
                        answerArea += '<div style="margin-top:14px;">' + q.subQuestions.map((sq, si) => {
                            const prefix = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'][si] || (
                                si + 1);
                            return `<div style="background:var(--bg); border-radius:10px; padding:14px; margin-bottom:10px; border:1px solid var(--border);">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <strong style="color:var(--blue);">${prefix})</strong>
                                    <span class="tag" style="background:var(--blue); color:#fff;">${sq.marks} marks</span>
                                </div>
                                <p style="margin:0 0 8px; color:var(--text);">${escapeHTML(sq.text || '(no text)')}</p>
                                ${sq.hint ? `<small style="color:var(--muted);"><i class="fas fa-lightbulb"></i> Hint: ${escapeHTML(sq.hint)}</small>` : ''}
                                <textarea rows="3" disabled placeholder="Student answer area..." style="width:100%; margin-top:8px; padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--input-bg); color:var(--text); font-family:monospace; font-size:13px;"></textarea>
                            </div>`;
                        }).join('') + '</div>';
                    }
                }

                return `
                <div style="background:var(--panel); border:2px solid var(--border); border-radius:14px; padding:20px; margin-bottom:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <span style="font-weight:700; font-size:15px; color:var(--text);">Q${idx + 1}.</span>
                            <span style="background:linear-gradient(135deg,#10b981,#059669); color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600;">CODING</span>
                            ${q.compulsory ? '<span style="background:#ef4444; color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600;">COMPULSORY</span>' : ''}
                        </div>
                        <span style="background:var(--blue); color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;">${qMarks} marks</span>
                    </div>
                    <p style="margin:0 0 4px; color:var(--text); font-size:15px; line-height:1.6;">${escapeHTML(q.text || '(no question text)')}</p>
                    ${answerArea}
                </div>`;
            }).join('');
        }

        const logoHtml = '';

        const previewContent = document.getElementById('examPreviewContent');
        if (!previewContent) return;

        previewContent.innerHTML = `
            <div style="max-width:860px; margin:0 auto;">
                <!-- Exam Header -->
                <div style="background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; border-radius:16px; padding:28px 24px; margin-bottom:24px; text-align:center;">
                    ${logoHtml}
                    <div style="font-size:13px; opacity:.85; margin-bottom:4px;">${escapeHTML(exam.school_name || '')}</div>
                    <div style="font-size:12px; opacity:.75; margin-bottom:12px;">${escapeHTML(exam.faculty_name || '')} ${exam.department ? '| ' + escapeHTML(exam.department) : ''}</div>
                    <h2 style="margin:0 0 6px; font-size:22px; font-weight:700;">${escapeHTML(exam.title || 'Exam')}</h2>
                    <div style="font-size:13px; opacity:.85;">${escapeHTML(exam.courseCode || '')} ${exam.exam_type ? '| ' + escapeHTML(exam.exam_type) : ''} ${exam.semester ? '| ' + escapeHTML(exam.semester) : ''}</div>
                </div>

                <!-- Exam Meta Bar -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin-bottom:24px;">
                    <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Duration</div>
                        <div style="font-size:18px; font-weight:700; color:var(--blue);">⏱ ${durationText}</div>
                    </div>
                    <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Questions</div>
                        <div style="font-size:18px; font-weight:700; color:var(--blue);">📝 ${questionCount}</div>
                    </div>
                    <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Total Marks</div>
                        <div style="font-size:18px; font-weight:700; color:var(--blue);">⭐ ${totalMarks}</div>
                    </div>
                    ${exam.level ? `<div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Level</div>
                        <div style="font-size:18px; font-weight:700; color:var(--blue);">🎓 ${escapeHTML(exam.level)}</div>
                    </div>` : ''}
                </div>

                <!-- Instructions -->
                ${exam.instructions ? `<div style="background:rgba(59,130,246,.06); border-left:4px solid #3b82f6; border-radius:0 12px 12px 0; padding:16px 20px; margin-bottom:24px;">
                    <div style="font-weight:700; margin-bottom:8px; color:var(--text);">📋 Instructions</div>
                    <pre style="margin:0; font-family:inherit; white-space:pre-wrap; color:var(--text); font-size:14px; line-height:1.6;">${escapeHTML(exam.instructions)}</pre>
                </div>` : ''}

                <!-- Questions -->
                <div style="margin-bottom:24px;">${questionsHtml}</div>

                <!-- Submit bar (disabled in preview) -->
                <div style="background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; opacity:.6;">
                    <span style="color:var(--muted); font-size:13px;"><i class="fas fa-info-circle"></i> Preview mode — submission disabled</span>
                    <button disabled style="padding:10px 24px; background:#3b82f6; color:#fff; border:none; border-radius:30px; font-weight:600; cursor:not-allowed; opacity:.5;">Submit Exam</button>
                </div>
            </div>`;

        document.getElementById('examPreviewModal').style.display = 'flex';
    }

    function copyExamLink(examId, examTitle) {
        const base = window.location.origin + window.location.pathname.replace('lecturer_dashboard.php', '');
        const link = `${base}student_exam.php?exam_id=${examId}`;
        navigator.clipboard.writeText(link).then(() => {
            toast(`🔗 Exam link copied: ${link}`);
        }).catch(() => {
            // Fallback
            const el = document.createElement('textarea');
            el.value = link;
            el.style.position = 'fixed';
            el.style.opacity = '0';
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            toast(`🔗 Link copied to clipboard`);
        });
    }

    function toggleShuffle() {
        shuffleEnabled = !shuffleEnabled;
        const btn = document.getElementById('shuffleBtn');
        const btnText = document.getElementById('shuffleBtnText');
        if (btn) {
            if (btnText) {
                btnText.textContent = shuffleEnabled ? 'Shuffle Questions: ON' : 'Shuffle Questions: OFF';
            } else {
                btn.innerHTML = shuffleEnabled ?
                    '<i class="fas fa-random"></i> Shuffle Questions: ON' :
                    '<i class="fas fa-random"></i> Shuffle Questions: OFF';
            }
        }
        toast(shuffleEnabled ? 'Question shuffling enabled' : 'Question shuffling disabled');

        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx].shuffleEnabled = shuffleEnabled;
            setExams(exams);
            saveExamToDatabase();
        }
    }

    function setGradingMode(mode) {
        gradingMode = mode;
        toast(`✅ Grading mode set to: ${mode}`);
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx].gradingMode = mode;
            setExams(exams);
            saveExamToDatabase();
        }
    }

    function startAutoSave() {
        if (autoSaveInterval) clearInterval(autoSaveInterval);
        autoSaveInterval = setInterval(() => {
            if (currentExamId && document.getElementById('view-builder') && document.getElementById(
                    'view-builder').classList.contains('active')) {
                saveExamToDatabase();
                console.log("Auto-saved exam at:", new Date().toLocaleTimeString());
            }
        }, 30000);
    }

    // ============================================
    // 5. QUESTION MANAGEMENT FUNCTIONS - CODING ONLY
    // ============================================

    function addQuestion(type) {
        console.log("Adding question of type:", type);
        if (type !== 'code') {
            toast("Only coding questions are supported");
            return;
        }

        let examId = currentExamId;
        if (!examId) {
            const savedId = sessionStorage.getItem('currentExamId');
            if (savedId) {
                examId = parseInt(savedId);
                currentExamId = examId;
            }
        }

        if (!examId) {
            toast("❌ Please create or select an exam first");
            go('exams');
            return;
        }

        let exams = getExams();
        let idx = exams.findIndex(e => parseInt(e.id) === parseInt(examId));

        if (idx < 0) {
            showLoading('Loading exam data...');
            loadExamsList().then(() => {
                exams = getExams();
                idx = exams.findIndex(e => parseInt(e.id) === parseInt(examId));
                if (idx >= 0) {
                    addQuestionToExam(exams, idx);
                } else {
                    hideLoading();
                    toast("❌ Exam not found. Please create a new exam.");
                    go('exams');
                }
            });
            return;
        }

        addQuestionToExam(exams, idx);
    }

    async function addFirstQuestion(type) {
        console.log("Adding first coding question");
        if (type !== 'code') {
            toast("Only coding questions are supported");
            return;
        }

        let examId = currentExamId;
        if (!examId) {
            const savedId = sessionStorage.getItem('currentExamId');
            if (savedId) {
                examId = parseInt(savedId);
                currentExamId = examId;
            }
        }

        if (!examId) {
            toast("❌ Please create or select an exam first");
            go('exams');
            return;
        }

        let exams = getExams();
        let idx = exams.findIndex(e => parseInt(e.id) === parseInt(examId));

        if (idx < 0) {
            showLoading('Loading exam data...');
            await loadExamsList();
            exams = getExams();
            idx = exams.findIndex(e => parseInt(e.id) === parseInt(examId));

            if (idx < 0) {
                hideLoading();
                toast("❌ Exam not found. Please create a new exam.");
                go('exams');
                return;
            }
        }

        const q = createCodeQuestionObject();
        exams[idx].questions.push(q);
        setExams(exams);
        await saveExamToDatabase();

        const noQuestionsMsg = document.getElementById("noQuestionsMessage");
        if (noQuestionsMsg) {
            noQuestionsMsg.style.display = "none";
        }

        const qList = document.getElementById("qList");
        if (qList) {
            qList.style.display = "grid";
        }

        const qtypeBar = document.getElementById("qtypeButtonBar");
        if (qtypeBar) {
            qtypeBar.classList.add("visible");
        }

        renderQuestions();
        toast("✅ Coding question added");
        hideLoading();
    }

    function createCodeQuestionObject() {
        return {
            id: uid("Q"),
            type: "code",
            text: "",
            marks: 5,
            compulsory: false,
            language: "Python",
            testCases: [],
            expectedOutput: "",
            gradingMode: "auto"
        };
    }

    function monacoLanguageFromQuestion(language) {
        const lang = String(language || '').toLowerCase();
        if (lang.includes('python')) return 'python';
        if (lang.includes('javascript')) return 'javascript';
        if (lang.includes('php')) return 'php';
        if (lang.includes('java')) return 'java';
        if (lang.includes('c++') || lang.includes('cpp')) return 'cpp';
        if (lang === 'c') return 'c';
        if (lang.includes('html')) return 'html';
        if (lang.includes('css')) return 'css';
        return 'plaintext';
    }

    function addQuestionToExam(exams, idx) {
        const q = createCodeQuestionObject();
        exams[idx].questions.push(q);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase().then(() => console.log("Question saved to database"));
        toast("✅ Coding question added");
        hideLoading();

        setTimeout(() => {
            const qList = document.getElementById("qList");
            if (qList && qList.lastChild) {
                qList.lastChild.scrollIntoView({
                    behavior: "smooth",
                    block: "start"
                });
            }
        }, 100);
    }

    function renderQuestions() {
        console.log("Rendering questions for exam ID:", currentExamId);

        if (!currentExamId) {
            console.error("No currentExamId");
            return;
        }

        const exam = findExam(currentExamId);
        const wrap = document.getElementById("qList");
        const noQuestionsMsg = document.getElementById("noQuestionsMessage");
        const qtypeBar = document.getElementById("qtypeButtonBar");

        console.log("Found exam:", exam);
        console.log("Questions:", exam ? exam.questions : "No exam");

        if (!exam) {
            console.error("Exam not found");
            if (currentExamId) {
                openBuilder(currentExamId);
            }
            return;
        }

        if (!exam.questions) {
            exam.questions = [];
        }

        if (exam.questions.length === 0) {
            if (wrap) {
                wrap.innerHTML = "";
                wrap.style.display = "none";
            }
            if (noQuestionsMsg) {
                noQuestionsMsg.style.display = "block";
            }
            if (qtypeBar) {
                qtypeBar.classList.remove("visible");
            }
            return;
        }

        if (noQuestionsMsg) {
            noQuestionsMsg.style.display = "none";
        }

        if (qtypeBar) {
            qtypeBar.classList.add("visible");
        }

        if (wrap) {
            wrap.style.display = "grid";
            wrap.innerHTML = exam.questions.map((q, idx) =>
                renderCodingQuestionCard(q, idx, exam.questions.length)
            ).join('');
            console.log(`Rendered ${exam.questions.length} coding questions`);
            refreshQuestionAnswerRuleHint();
        }
    }

    function renderCodingQuestionCard(q, idx, totalQuestions) {
        q.hasSubQuestions = false;
        q.subQuestions = [];
        // Calculate total marks
        let totalMarks = parseFloat(q.marks) || 0;

        let html = `
        <div class="coding-question-card" style="background: var(--panel); border-radius: 16px; padding: 24px; margin-bottom: 20px; border: 2px solid var(--border);">
            <div class="qhead" style="display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                <div style="flex: 1;">
                    <div class="qtitle" style="font-weight: 700; font-size: 16px; color: var(--text); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="cursor: grab; font-size: 18px;" class="drag-handle" title="Drag to reorder">⋮⋮</span>
                        <span>Q${idx + 1}</span>
                        <span class="question-type-badge" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">CODING</span>
                        ${q.compulsory ? '<span class="compulsory-badge" style="background: var(--compulsory-badge); color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 600;">COMPULSORY</span>' : ''}
                        <button class="btn small" onclick="toggleCompulsory('${q.id}')" style="margin-left: 8px; padding: 4px 8px; font-size: 11px;">${q.compulsory ? '❌ Make Optional' : '⭐ Make Compulsory'}</button>
                    </div>
                    <div class="qmeta" style="font-size: 12px; color: var(--muted); margin-top: 8px;">
                        Marks: <input type="number" value="${totalMarks}" style="width: 70px; padding: 4px 8px;" onchange="updateQuestion('${q.id}', 'marks', parseFloat(this.value))">
                        <span style="margin-left: 10px;">Language: ${escapeHTML(q.language || 'Python')}</span>
                    </div>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button class="btn" onclick="moveQ('${q.id}', -1)" ${idx === 0 ? 'disabled' : ''} title="Move Up">↑</button>
                    <button class="btn" onclick="moveQ('${q.id}', 1)" ${idx === totalQuestions - 1 ? 'disabled' : ''} title="Move Down">↓</button>
                    <button class="btn primary" onclick="duplicateQuestion('${q.id}')" title="Duplicate">📋 Duplicate</button>
                    <button class="btn danger" onclick="removeQuestion('${q.id}')" title="Delete">🗑 Delete</button>
                </div>
            </div>
            
            <!-- Question Text -->
            <div class="field" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                    <i class="fas fa-question-circle"></i> Question
                </label>
                <textarea onchange="updateQuestion('${q.id}', 'text', this.value)" rows="4" 
                    style="width: 100%; padding: 12px; border-radius: 12px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 14px;"
                    placeholder="Enter the coding question here...">${escapeHTML(q.text || '')}</textarea>
            </div>
            
            <!-- Has Sub-questions Toggle -->
            <div class="field" style="margin-bottom: 20px; display:none;">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                    <input type="checkbox" id="hasSubQuestions_${q.id}" ${q.hasSubQuestions ? 'checked' : ''} 
                        onchange="toggleSubQuestions('${q.id}', this.checked)" style="width: 20px; height: 20px; cursor: pointer;">
                    <span style="font-size: 14px; font-weight: 600;">
                        <i class="fas fa-list-ol"></i> This question has sub-questions (a, b, c, ...)
                    </span>
                </label>
                <small style="display: block; margin-top: 5px; color: var(--muted);">Enable this if you want to break down the question into parts</small>
            </div>`;

        if (q.hasSubQuestions) {
            html += `
            <div id="subquestionsSection_${q.id}" style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <label style="font-size: 14px; font-weight: 600;">
                        <i class="fas fa-list-ol"></i> Sub-Questions
                    </label>
                    <button class="btn primary" onclick="addSubQuestion('${q.id}')" style="padding: 6px 12px;">
                        <i class="fas fa-plus"></i> Add Sub-Question
                    </button>
                </div>
                <div id="subquestionsList_${q.id}" class="subquestions-list">
                    ${renderSubQuestionsList(q)}
                </div>
            </div>`;
        } else {
            html += `
            <div id="singleMarksSection_${q.id}" style="margin-bottom: 20px;">
                <div class="marks-input-wrapper" style="max-width: 200px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                        <i class="fas fa-star"></i> Marks
                    </label>
                    <input type="number" value="${q.marks || 5}" min="0" step="0.5" 
                        onchange="updateQuestion('${q.id}', 'marks', parseFloat(this.value))"
                        class="marks-input"
                        style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 14px;">
                </div>
            </div>`;
        }

        html += `
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div class="field">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                        <i class="fas fa-code"></i> Programming Language
                    </label>
                    <select onchange="updateCodeLanguage('${q.id}', this.value)" 
                        style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text);">
                        ${codingLanguagesList.map(lang => 
                            `<option value="${lang}" ${(q.language === lang) ? 'selected' : ''}>${lang}</option>`
                        ).join('')}
                    </select>
                </div>
                
                <div class="field">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                        <i class="fas fa-robot"></i> Grading Mode
                    </label>
                    <select onchange="updateQuestion('${q.id}', 'gradingMode', this.value)" 
                        style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text);">
                        <option value="auto" ${q.gradingMode === 'auto' ? 'selected' : ''}>🤖 Auto-grading (Test Cases)</option>
                        <option value="manual" ${q.gradingMode === 'manual' ? 'selected' : ''}>✏️ Manual Grading Only</option>
                        <option value="hybrid" ${q.gradingMode === 'hybrid' ? 'selected' : ''}>🔄 Hybrid (Auto + Manual Review)</option>
                    </select>
                    <small style="display: block; margin-top: 5px; color: var(--muted);">
                        Auto: System grades based on test cases | Manual: Lecturer grades | Hybrid: Auto-grade then manual review
                    </small>
                </div>
            </div>`;

        if (q.gradingMode !== 'manual') {
            html += `
            <div class="field" style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                    <label style="font-size: 14px; font-weight: 600;">
                        <i class="fas fa-vial"></i> Test Cases (for Auto-grading)
                    </label>
                    <button class="btn small" onclick="addTestCase('${q.id}')" style="padding: 4px 12px;">
                        <i class="fas fa-plus"></i> Add Test Case
                    </button>
                </div>
                <div id="testCasesList_${q.id}">
                    ${renderTestCasesList(q)}
                </div>
                <small style="display: block; margin-top: 8px; color: var(--muted);">
                    <i class="fas fa-info-circle"></i> Define test cases to automatically validate student code
                </small>
            </div>`;
        }

        html += `
            <div class="field" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                    <i class="fas fa-file-alt"></i> Expected Output / Model Solution
                </label>
                <textarea onchange="updateQuestion('${q.id}', 'expectedOutput', this.value)" rows="6" 
                    style="width: 100%; padding: 12px; border-radius: 12px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text); font-family: monospace;" 
                    placeholder="Enter the expected output or model solution...">${escapeHTML(q.expectedOutput || '')}</textarea>
            </div>
            
            <div class="field" style="margin-bottom: 0;">
                <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">Change Question Type</label>
                <select onchange="changeType('${q.id}', this.value)" style="width: 100%; padding: 12px; border-radius: 12px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text);">
                    <option value="code" ${q.type == 'code' ? 'selected' : ''}>Coding</option>
                </select>
            </div>
        </div>`;



        // Add this after the test cases section in renderCodingQuestionCard
        html += `
    <div class="field" style="margin-bottom: 20px;">
        <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
            <i class="fas fa-clipboard-list"></i> Marking Scheme
        </label>
        <textarea onchange="updateQuestion('${q.id}', 'markingScheme', this.value)" rows="4"
            style="width: 100%; padding: 12px; border-radius: 12px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text);"
            placeholder="Enter marking scheme criteria (one per line)...
- Correct syntax (2 marks)
- Logic implementation (3 marks)
- Edge cases handled (1 mark)
- Code efficiency (1 mark)
- Comments and readability (1 mark)">${escapeHTML(q.markingScheme || '')}</textarea>
        <small style="display: block; margin-top: 8px; color: var(--muted);">
            <i class="fas fa-info-circle"></i> Define criteria for AI grading
        </small>
    </div>`;

        return html;
    }

    function renderSubQuestionsList(q) {
        if (!q.subQuestions || q.subQuestions.length === 0) {
            return `<div class="empty-subquestions" style="text-align: center; padding: 30px; background: var(--bg); border-radius: 12px; border: 2px dashed var(--border);">
                <i class="fas fa-plus-circle" style="font-size: 40px; color: var(--muted); margin-bottom: 10px;"></i>
                <p style="color: var(--muted);">No sub-questions yet. Click "Add Sub-Question" to create parts like a), b), c)...</p>
            </div>`;
        }

        let html = '<div style="display: flex; flex-direction: column; gap: 15px;">';

        q.subQuestions.forEach((sq, idx) => {
            const prefix = getSubQuestionPrefix(idx);
            html += `
            <div class="subquestion-item" data-subq-id="${sq.id}" style="background: var(--bg); border-radius: 12px; padding: 15px; border: 1px solid var(--border); position: relative;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="subquestion-prefix" style="font-weight: 700; font-size: 16px; color: var(--blue); background: rgba(59,130,246,0.1); padding: 4px 12px; border-radius: 20px;">${prefix}</span>
                        <span class="subquestion-id" style="font-size: 11px; color: var(--muted);">ID: ${sq.id}</span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn danger small" onclick="removeSubQuestion('${q.id}', '${sq.id}')" style="padding: 4px 10px;">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </div>
                
                <div class="field" style="margin-bottom: 12px;">
                    <label style="font-size: 13px; font-weight: 600; margin-bottom: 5px; display: block;">Question Text</label>
                    <textarea onchange="updateSubQuestion('${q.id}', '${sq.id}', 'text', this.value)" rows="2" 
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 13px;">${escapeHTML(sq.text || '')}</textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                    <div class="field" style="margin-bottom: 0;">
                        <label style="font-size: 12px; font-weight: 600;">Marks</label>
                        <input type="number" value="${sq.marks || 0}" min="0" step="0.5" 
                            onchange="updateSubQuestion('${q.id}', '${sq.id}', 'marks', parseFloat(this.value))"
                            class="subquestion-marks"
                            style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                    </div>
                    
                    <div class="field" style="margin-bottom: 0;">
                        <label style="font-size: 12px; font-weight: 600;">Expected Output</label>
                        <input type="text" value="${escapeHTML(sq.expectedOutput || '')}" 
                            onchange="updateSubQuestion('${q.id}', '${sq.id}', 'expectedOutput', this.value)"
                            placeholder="Expected result..."
                            style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                    </div>
                    
                    <div class="field" style="margin-bottom: 0;">
                        <label style="font-size: 12px; font-weight: 600;">Hint (Optional)</label>
                        <input type="text" value="${escapeHTML(sq.hint || '')}" 
                            onchange="updateSubQuestion('${q.id}', '${sq.id}', 'hint', this.value)"
                            placeholder="Provide a hint..."
                            style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                    </div>
                </div>
            </div>`;
        });

        html += '</div>';
        return html;
    }

    function renderTestCasesList(q) {
        if (!q.testCases || q.testCases.length === 0) {
            return `<div style="text-align: center; padding: 20px; background: var(--bg); border-radius: 8px; border: 1px dashed var(--border);">
                <small style="color: var(--muted);"><i class="fas fa-info-circle"></i> No test cases. Add test cases to enable auto-grading.</small>
            </div>`;
        }

        let html = '<div style="display: flex; flex-direction: column; gap: 10px;">';

        q.testCases.forEach((tc, idx) => {
            html += `
            <div style="display: flex; gap: 10px; align-items: center; background: var(--bg); padding: 12px; border-radius: 8px; flex-wrap: wrap;">
                <div style="flex: 2; min-width: 150px;">
                    <input type="text" placeholder="Input" value="${escapeHTML(tc.input || '')}" 
                        onchange="updateTestCase('${q.id}', ${idx}, 'input', this.value)"
                        style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                </div>
                <div style="flex: 2; min-width: 150px;">
                    <input type="text" placeholder="Expected Output" value="${escapeHTML(tc.expected || '')}" 
                        onchange="updateTestCase('${q.id}', ${idx}, 'expected', this.value)"
                        style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                </div>
                <div style="width: 100px;">
                    <input type="number" placeholder="Marks" value="${tc.marks || 0}" min="0" step="0.5"
                        onchange="updateTestCase('${q.id}', ${idx}, 'marks', parseFloat(this.value))"
                        style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                </div>
                <button class="btn danger small" onclick="removeTestCase('${q.id}', ${idx})" style="padding: 6px 12px;">
                    <i class="fas fa-times"></i> Remove
                </button>
            </div>`;
        });

        html += '</div>';
        return html;
    }

    function getSubQuestionPrefix(index) {
        const letters = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's',
            't'
        ];
        const roman = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi', 'xii', 'xiii', 'xiv', 'xv'];

        if (index < 20) {
            return `${letters[index]})`;
        } else {
            return `${roman[index - 20]})`;
        }
    }

    function toggleSubQuestions(questionId, hasSubQuestions) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        q.hasSubQuestions = hasSubQuestions;

        if (hasSubQuestions) {
            if (!q.subQuestions || q.subQuestions.length === 0) {
                q.subQuestions = [{
                    id: uid("SQ"),
                    text: "",
                    marks: 5,
                    expectedOutput: "",
                    hint: ""
                }];
            }
            if (q.marks && !q.savedSingleMark) {
                q.savedSingleMark = q.marks;
            }
        } else {
            if (q.savedSingleMark) {
                q.marks = q.savedSingleMark;
            } else if (!q.marks || q.marks === 0) {
                q.marks = 5;
            }
            q.subQuestions = [];
        }

        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
    }

    function addSubQuestion(questionId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        if (!q.subQuestions) q.subQuestions = [];

        const newSubQ = {
            id: uid("SQ"),
            text: "",
            marks: 5,
            expectedOutput: "",
            hint: ""
        };

        q.subQuestions.push(newSubQ);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`✅ Sub-question added`);
    }

    function removeSubQuestion(questionId, subQuestionId) {
        if (!confirm('Remove this sub-question?')) return;

        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q || !q.subQuestions) return;

        q.subQuestions = q.subQuestions.filter(sq => sq.id !== subQuestionId);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`🗑 Sub-question removed`);
    }

    function updateSubQuestion(questionId, subQuestionId, field, value) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q || !q.subQuestions) return;

        const sq = q.subQuestions.find(x => x.id === subQuestionId);
        if (sq) {
            sq[field] = value;
            setExams(exams);
            saveExamToDatabase();
            if (field === 'marks') {
                renderQuestions();
            }
        }
    }

    function addTestCase(questionId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        if (!q.testCases) q.testCases = [];

        q.testCases.push({
            input: "",
            expected: "",
            marks: 5
        });

        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`✅ Test case added`);
    }

    function removeTestCase(questionId, testCaseIndex) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q || !q.testCases) return;

        q.testCases.splice(testCaseIndex, 1);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`🗑 Test case removed`);
    }

    function updateTestCase(questionId, testCaseIndex, field, value) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q || !q.testCases || !q.testCases[testCaseIndex]) return;

        q.testCases[testCaseIndex][field] = value;
        setExams(exams);
        saveExamToDatabase();
    }

    function updateCodeLanguage(questionId, language) {
        console.log("Updating language to:", language);

        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) {
            console.error("Exam not found");
            return;
        }

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) {
            console.error("Question not found");
            return;
        }

        q.language = language;

        setExams(exams);
        saveExamToDatabase().then(() => {
            console.log("Exam saved with new language");
        });
        renderQuestions();
        toast(`Language changed to ${language}`);
    }

    function previewCodeQuestion(questionId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        let totalMarks = 0;
        if (q.hasSubQuestions && q.subQuestions && q.subQuestions.length > 0) {
            totalMarks = q.subQuestions.reduce((sum, sq) => sum + (sq.marks || 0), 0);
        } else {
            totalMarks = q.marks || 0;
        }

        let previewHtml = `
        <div style="padding: 20px; max-width: 900px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end)); color: white; padding: 25px; border-radius: 16px; margin-bottom: 25px; text-align: center;">
                <h2 style="margin: 0; font-size: 24px;">📝 Coding Question</h2>
                <p style="margin: 10px 0 0 0; opacity: 0.9;">Total Marks: ${totalMarks}</p>
            </div>
            
            <div style="background: var(--panel); border-radius: 16px; padding: 25px; border: 1px solid var(--border); margin-bottom: 25px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                    <h3 style="margin: 0; color: var(--text);">
                        <i class="fas fa-code"></i> Question ${q.id}
                    </h3>
                    <span style="background: var(--blue); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        ${q.language || 'Programming'}
                    </span>
                </div>
                
                <div style="margin-bottom: 25px;">
                    <div style="background: var(--bg); padding: 20px; border-radius: 12px; border-left: 4px solid var(--blue);">
                        <p style="margin: 0; line-height: 1.6; color: var(--text); font-size: 16px;">
                            ${escapeHTML(q.text || 'No question text provided.')}
                        </p>
                    </div>
                </div>`;

        if (q.hasSubQuestions && q.subQuestions && q.subQuestions.length > 0) {
            previewHtml += `
                <div style="margin-bottom: 25px;">
                    <h4 style="margin-bottom: 15px; color: var(--text);">
                        <i class="fas fa-list-ol"></i> Sub-Questions:
                    </h4>
                    <div style="padding-left: 20px;">`;

            q.subQuestions.forEach((sq, idx) => {
                const prefix = getSubQuestionPrefix(idx);
                previewHtml += `
                        <div style="margin-bottom: 25px; background: var(--bg); padding: 15px; border-radius: 12px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <strong style="font-size: 16px; color: var(--blue);">${prefix}</strong>
                                <span class="tag" style="background: var(--blue); color: white; padding: 2px 10px; border-radius: 12px; font-size: 11px;">
                                    ${sq.marks} marks
                                </span>
                            </div>
                            <p style="margin: 0 0 10px 0; line-height: 1.5;">${escapeHTML(sq.text || 'No description')}</p>
                            ${sq.hint ? `<div style="background: rgba(59,130,246,0.1); padding: 10px; border-radius: 8px; margin-top: 10px;">
                                <small><i class="fas fa-lightbulb"></i> Hint: ${escapeHTML(sq.hint)}</small>
                            </div>` : ''}
                            
                            <div style="margin-top: 15px;">
                                <label style="font-size: 13px; font-weight: 600;">Your Answer:</label>
                                <textarea rows="3" placeholder="Write your answer here..." 
                                    style="width: 100%; margin-top: 5px; padding: 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);"></textarea>
                            </div>
                        </div>`;
            });

            previewHtml += `
                    </div>
                </div>`;
        }

        previewHtml += `
            <div style="margin-bottom: 25px;">
                <label style="font-size: 14px; font-weight: 600;">Your Answer / Code:</label>
                <textarea rows="8" placeholder="Write your code here..." 
                    style="width: 100%; margin-top: 8px; padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-family: monospace;"></textarea>
            </div>`;

        if (q.testCases && q.testCases.length > 0 && q.gradingMode !== 'manual') {
            previewHtml += `
                <div style="margin-top: 20px; padding: 15px; background: rgba(34,197,94,0.1); border-radius: 12px; border-left: 4px solid #22c55e;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <i class="fas fa-check-circle" style="color: #22c55e;"></i>
                        <strong style="color: var(--text);">Auto-Grading Information</strong>
                    </div>
                    <p style="margin: 0; font-size: 13px; color: var(--muted);">
                        Your code will be tested against ${q.testCases.length} test case(s). 
                        Each passing test case earns the allocated marks.
                    </p>
                </div>`;
        }

        previewHtml += `
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 20px;">
                <button class="btn" style="padding: 12px 24px;">
                    <i class="fas fa-save"></i> Save Draft
                </button>
                <button class="btn primary" style="padding: 12px 24px; background: var(--blue); color: white;">
                    <i class="fas fa-paper-plane"></i> Submit Answer
                </button>
            </div>
            
            <div style="margin-top: 30px; padding: 15px; background: var(--bg); border-radius: 12px; text-align: center; border: 1px dashed var(--border);">
                <small style="color: var(--muted);">
                    <i class="fas fa-info-circle"></i> This is how students will see this question. 
                    The actual exam interface may include a timer and additional features.
                </small>
            </div>
        </div>`;

        const previewContent = document.getElementById('previewContent');
        if (previewContent) previewContent.innerHTML = previewHtml;

        const previewModal = document.getElementById('previewModal');
        if (previewModal) {
            previewModal.style.display = 'flex';
            const modalContent = previewModal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.style.width = '90%';
                modalContent.style.maxWidth = '1000px';
            }
        }
    }

    function removeQuestion(qId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;
        exams[idx].questions = exams[idx].questions.filter(q => q.id !== qId);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast("🗑 Removed");
    }

    function updateQuestion(qId, field, value) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === qId);
        if (!q) return;

        q[field] = value;
        setExams(exams);
        if (field === 'marks' || field === 'compulsory') {
            refreshQuestionAnswerRuleHint();
        }
        saveExamToDatabase().then(() => {
            console.log(`Question ${qId} updated: ${field} = ${value}`);
        });
    }

    function moveQ(qId, dir) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;
        const qs = exams[idx].questions;
        const i = qs.findIndex(q => q.id === qId);
        const j = i + dir;
        if (i < 0 || j < 0 || j >= qs.length) return;
        [qs[i], qs[j]] = [qs[j], qs[i]];
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast("📋 Reordered");
    }

    function duplicateQuestion(qId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;
        const q = exams[idx].questions.find(x => x.id === qId);
        if (!q) return;
        const newQ = JSON.parse(JSON.stringify(q));
        newQ.id = uid("Q");
        exams[idx].questions.splice(idx + 1, 0, newQ);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast("📋 Duplicated");
    }

    function toggleCompulsory(qId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;
        const q = exams[idx].questions.find(x => x.id === qId);
        if (!q) return;
        q.compulsory = !q.compulsory;
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(q.compulsory ? "⭐ Question marked as compulsory" : "❌ Question now optional");
    }

    function changeType(qId, newType) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === qId);
        if (!q) return;

        if (newType !== 'code') {
            toast("Only coding questions are supported");
            return;
        }

        // Reset type-specific properties
        delete q.correctText;
        delete q.keywords;
        delete q.rubric;
        delete q.language;
        delete q.allowMultipleLangs;
        delete q.testCases;
        delete q.pairs;
        delete q.correctIndices;
        delete q.items;
        delete q.correctOrder;
        delete q.tolerance;
        delete q.precision;
        delete q.unit;
        delete q.subQuestions;
        delete q.hasSubQuestions;
        delete q.expectedOutput;
        delete q.gradingMode;

        q.type = newType;
        q.language = "Python";
        q.testCases = [];
        q.hasSubQuestions = false;
        q.subQuestions = [];
        q.expectedOutput = "";
        q.gradingMode = "auto";

        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`🔄 Changed to ${newType}`);
    }

    // ============================================
    // QUESTION REVIEW SYSTEM WITH CODE TESTING
    // ============================================

    let currentReviewSubmission = null;
    let currentQuestions = [];
    let currentScores = {};

    // Open question review modal
    async function openQuestionReview(submissionId) {
        showLoading('Loading submission...');
        try {
            const result = await apiRequest('get_submission_questions', {
                submission_id: submissionId
            });

            if (result.success && result.data) {
                currentReviewSubmission = result.data;
                currentQuestions = result.data.questions;

                // Initialize scores
                currentScores = {};
                currentQuestions.forEach((q, idx) => {
                    currentScores[idx] = q.savedScore || 0;
                });

                // Set header info
                document.getElementById('reviewStudentName').innerHTML =
                    `<i class="fas fa-user-graduate"></i> ${escapeHTML(currentReviewSubmission.student_name)} (${escapeHTML(currentReviewSubmission.student_id)})`;
                document.getElementById('reviewExamInfo').innerHTML =
                    `${escapeHTML(currentReviewSubmission.exam_title)} | Submitted: ${new Date(currentReviewSubmission.submitted_at).toLocaleString()}`;

                // Build question list sidebar
                buildQuestionSidebar();

                // Show total score
                updateTotalScoreDisplay();

                // Show modal
                document.getElementById('questionReviewModal').style.display = 'flex';
            } else {
                toast('❌ Failed to load submission: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            toast('❌ Error loading submission');
        } finally {
            hideLoading();
        }
    }

    // Build question sidebar (LEFT PANEL)
    function buildQuestionSidebar() {
        const sidebar = document.getElementById('questionListSidebar');
        if (!sidebar) return;

        let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';

        currentQuestions.forEach((question, idx) => {
            const isAnswered = question.answer && question.answer !== 'No answer provided';
            const statusIcon = isAnswered ? '✅' : '❌';
            const score = currentScores[idx] || 0;
            const maxMarks = question.marks;

            html += `
        <div class="question-list-item" onclick="loadQuestion(${idx})" 
             style="padding: 12px; background: var(--panel); border-radius: 8px; cursor: pointer; border: 1px solid var(--border);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>Question ${question.number}</strong>
                    <div style="font-size: 11px; margin-top: 3px;">
                        ${statusIcon} ${escapeHTML(question.language || 'text').toUpperCase()}
                    </div>
                </div>
                <div style="text-align: right;">
                    <span style="font-size: 14px; font-weight: bold; color: #3b82f6;">${score}</span>
                    <span style="font-size: 12px;">/${maxMarks}</span>
                </div>
            </div>
        </div>
        `;
        });

        html += '</div>';
        sidebar.innerHTML = html;
    }

    // Load specific question into RIGHT PANEL with code editor
    function loadQuestion(questionIndex) {
        const question = currentQuestions[questionIndex];
        if (!question) return;

        const contentArea = document.getElementById('questionContentArea');

        const escapedCode = escapeHTML(question.answer);

        let html = `
    <div style="max-width: 100%;">
        <!-- Question Header -->
        <div style="background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <h3 style="margin: 0;">Question ${question.number}</h3>
                <span class="tag" style="background: white; color: #3b82f6;">${escapeHTML(question.language || 'text').toUpperCase()} | ${question.marks} marks</span>
            </div>
        </div>
        
        <!-- Question Text -->
        <div style="background: var(--bg); padding: 20px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #3b82f6;">
            <h4 style="margin-top: 0;"><i class="fas fa-question-circle"></i> Question:</h4>
            <p style="margin-bottom: 0; line-height: 1.6;">${escapeHTML(question.text)}</p>
        </div>
        
        <!-- Student's Code with Run Button -->
        <div style="margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h4><i class="fas fa-code"></i> Student's Answer:</h4>
                <button class="btn info small" onclick="openCodeTester('${escapeHTML(question.answer).replace(/'/g, "\\'")}', '${question.language}')">
                    <i class="fas fa-play"></i> Test This Code
                </button>
            </div>
            <div style="background: #1e1e1e; border-radius: 12px; overflow: hidden;">
                <div style="background: #2d2d2d; padding: 8px 15px; border-bottom: 1px solid #3d3d3d;">
                    <span style="color: #858585; font-size: 12px;">${escapeHTML(question.language || 'code')}</span>
                </div>
                <pre style="margin: 0; padding: 20px; color: #d4d4d4; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.5; overflow-x: auto; white-space: pre-wrap;">${escapedCode || '// No code provided'}</pre>
            </div>
        </div>
        
        <!-- Grading Section -->
        <div style="background: var(--bg); padding: 20px; border-radius: 12px; margin-top: 20px;">
            <h4><i class="fas fa-star"></i> Grade This Question</h4>
            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end;">
                <div style="flex: 1; min-width: 150px;">
                    <label>Marks Awarded (max ${question.marks}):</label>
                    <input type="number" id="scoreInput_${questionIndex}" 
                           value="${currentScores[questionIndex]}" 
                           min="0" max="${question.marks}" step="0.5"
                           onchange="updateScore(${questionIndex}, this.value)"
                           style="width: 100%; padding: 10px; border-radius: 8px; border: 2px solid var(--border);">
                </div>
                <div style="flex: 2;">
                    <label>Feedback (optional):</label>
                    <input type="text" id="feedbackInput_${questionIndex}" 
                           placeholder="Add feedback for this question..."
                           style="width: 100%; padding: 10px; border-radius: 8px; border: 2px solid var(--border);">
                </div>
                <div>
                    <button class="btn primary" onclick="saveQuestionScore(${questionIndex})">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </div>
        </div>
    </div>
    `;

        contentArea.innerHTML = html;
    }


    // Update score temporarily
    function updateScore(questionIndex, value) {
        const score = roundToWholeNumber(value);
        currentScores[questionIndex] = score;
        updateTotalScoreDisplay();

        // Update the input field to show rounded value
        const inputField = document.getElementById(`scoreInput_${questionIndex}`);
        if (inputField) {
            inputField.value = score;
        }

        // Update the sidebar display
        const sidebarItem = document.querySelectorAll('.question-list-item')[questionIndex];
        if (sidebarItem) {
            const scoreSpan = sidebarItem.querySelector('span:first-child');
            if (scoreSpan) {
                scoreSpan.textContent = score;
            }
        }
    }

    // Update total score display
    function updateTotalScoreDisplay() {
        const total = Object.values(currentScores).reduce((sum, score) => sum + score, 0);
        const totalMarks = currentQuestions.reduce((sum, q) => sum + q.marks, 0);

        document.getElementById('totalScoreDisplay').textContent = total;
        document.getElementById('totalMarksDisplay').textContent = ` / ${totalMarks} marks`;
    }

    // Save individual question score
    async function saveQuestionScore(questionIndex) {
        const score = currentScores[questionIndex];
        const feedback = document.getElementById(`feedbackInput_${questionIndex}`)?.value || '';

        showLoading('Saving score...');
        try {
            const result = await apiRequest('save_question_score', {
                submission_id: currentReviewSubmission.submission_id,
                question_number: questionIndex + 1,
                score: score,
                feedback: feedback
            });

            if (result.success) {
                toast(`✅ Score saved! Total: ${result.total_score}`);
                updateTotalScoreDisplay();

                // Update the submission's total score in the background
                if (currentReviewSubmission) {
                    currentReviewSubmission.total_marks = result.total_score;
                }
            } else {
                toast('❌ Failed to save score: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            toast('❌ Error saving score');
        } finally {
            hideLoading();
        }
    }

    // Save all scores at once
    async function saveAllScores() {
        showLoading('Saving all scores...');
        let saved = 0;

        for (let i = 0; i < currentQuestions.length; i++) {
            const score = currentScores[i];
            const feedback = document.getElementById(`feedbackInput_${i}`)?.value || '';

            try {
                await apiRequest('save_question_score', {
                    submission_id: currentReviewSubmission.submission_id,
                    question_number: i + 1,
                    score: score,
                    feedback: feedback
                });
                saved++;
            } catch (error) {
                console.error(`Error saving question ${i + 1}:`, error);
            }
        }

        toast(`✅ Saved ${saved} of ${currentQuestions.length} questions`);
        hideLoading();
    }

    // Open code tester modal
    function openCodeTester(code, language) {
        const modal = document.getElementById('codeTesterModal');
        const editor = document.getElementById('testCodeEditor');
        const langSelect = document.getElementById('testLanguage');

        if (editor) {
            editor.value = code;
        }

        // Set language
        let langValue = 'javascript';
        if (language.toLowerCase().includes('python')) langValue = 'python';
        else if (language.toLowerCase().includes('html')) langValue = 'html';
        else if (language.toLowerCase().includes('java')) langValue = 'python';
        else if (language.toLowerCase().includes('php')) langValue = 'python';
        else langValue = 'javascript';

        if (langSelect) langSelect.value = langValue;

        if (modal) modal.style.display = 'flex';
    }

    // Close code tester modal
    function closeCodeTesterModal() {
        const modal = document.getElementById('codeTesterModal');
        if (modal) modal.style.display = 'none';
    }

    // Run code test
    function runCodeTest() {
        const code = document.getElementById('testCodeEditor').value;
        const language = document.getElementById('testLanguage').value;
        const iframe = document.getElementById('testOutputFrame');

        if (!iframe) return;

        if (language === 'javascript') {
            // Create a safe execution environment
            let output = '';
            const originalLog = console.log;
            console.log = function(...args) {
                output += args.map(arg => {
                    if (typeof arg === 'object') return JSON.stringify(arg, null, 2);
                    return String(arg);
                }).join(' ') + '\n';
            };

            try {
                const result = eval(code);
                if (result !== undefined && output === '') {
                    output = String(result);
                }
                iframe.srcdoc = `
                <html>
                <head><style>body{background:#1e1e1e;color:#d4d4d4;font-family:monospace;padding:15px;margin:0;}</style></head>
                <body><pre style="margin:0;white-space:pre-wrap;">${escapeHTML(output) || 'No output'}</pre></body>
                </html>
            `;
            } catch (e) {
                iframe.srcdoc = `
                <html>
                <head><style>body{background:#1e1e1e;color:#ef4444;font-family:monospace;padding:15px;margin:0;}</style></head>
                <body><pre style="margin:0;">Error: ${escapeHTML(e.message)}</pre></body>
                </html>
            `;
            }

            console.log = originalLog;
        } else if (language === 'html') {
            iframe.srcdoc = code;
        } else {
            iframe.srcdoc = `
            <html>
            <head><style>body{background:#1e1e1e;color:#fbbf24;font-family:monospace;padding:15px;margin:0;}</style></head>
            <body><pre style="margin:0;">⚠️ ${language.toUpperCase()} execution requires backend support.\n\nYour code:\n${escapeHTML(code)}</pre></body>
            </html>
        `;
        }
    }

    // Close question review modal
    function closeQuestionReviewModal() {
        const modal = document.getElementById('questionReviewModal');
        if (modal) modal.style.display = 'none';
        currentReviewSubmission = null;
        currentQuestions = [];
        currentScores = {};
    }

    // Download submission as ZIP with folder structure
    async function downloadSubmission(submissionId) {
        showLoading('Preparing submission files...');
        try {
            // Create a direct link download without AJAX to avoid blob issues
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;
            form.target = '_blank';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'download_submission_zip';

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'submission_id';
            idInput.value = submissionId;

            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();

            // Clean up
            setTimeout(() => {
                document.body.removeChild(form);
            }, 1000);

            toast('✅ Download started! The ZIP file will contain organized folders for each question.');
        } catch (error) {
            console.error('Download error:', error);
            toast('❌ Download failed: ' + error.message);
        } finally {
            setTimeout(() => hideLoading(), 1500);
        }
    }

    // ============================================
    // COURSE CARDS VIEW - First screen
    // ============================================

    let currentExpandedCourse = null;
    let currentCourseData = null;

    function courseAccentColor(courseCode, courseName = '') {
        const palette = ['#2563eb', '#059669', '#dc2626', '#7c3aed', '#ea580c', '#0891b2', '#be123c', '#4f46e5'];
        const key = `${courseCode}-${courseName}`;
        let hash = 0;
        for (let i = 0; i < key.length; i++) hash = ((hash << 5) - hash) + key.charCodeAt(i);
        return palette[Math.abs(hash) % palette.length];
    }

    // Render course cards (first view - shows which courses have submissions)
    // Render course cards (first view - ONLY CARDS, no filters or tables)
    function renderCourseCardsView(submissions) {
        const container = document.getElementById('submissionsContainer');
        if (!container) return;

        // Group submissions by course
        const groupedByCourse = {};
        submissions.forEach(sub => {
            const examDetail = examDetailsCache[sub.exam_id];
            const courseCode = examDetail ? examDetail.course_code : (sub.course_code || 'Unknown');
            const courseName = examDetail ? examDetail.title : (sub.exam_title || 'Unknown Course');
            const courseKey = `${courseCode}::${courseName}`;
            const level = examDetail ? examDetail.level : 'N/A';
            const semester = examDetail ? examDetail.semester : 'N/A';

            if (!groupedByCourse[courseKey]) {
                groupedByCourse[courseKey] = {
                    courseKey: courseKey,
                    courseCode: courseCode,
                    courseName: courseName,
                    level: level,
                    semester: semester,
                    submissions: [],
                    examDetail: examDetail
                };
            }
            groupedByCourse[courseKey].submissions.push(sub);
        });

        // Store globally
        window.courseGroupsData = groupedByCourse;

        // Simple search and filter for courses only
        let html = `
        <div class="course-search-bar" style="background: var(--bg); border-radius: 12px; padding: 20px; margin-bottom: 25px; border: 1px solid var(--border);">
            <h3 style="margin: 0 0 15px 0;"><i class="fas fa-chalkboard-teacher"></i> My Courses</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                <div class="field" style="margin-bottom: 0;">
                    <label><i class="fas fa-search"></i> Search Course</label>
                    <input type="text" id="courseSearchInput" placeholder="Search by course code or name..." 
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);"
                        onkeyup="filterCourseCards()">
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label><i class="fas fa-graduation-cap"></i> Filter by Level</label>
                    <select id="levelFilterSelect" onchange="filterCourseCards()" 
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        <option value="all">All Levels</option>
                        <option value="100">100 Level</option>
                        <option value="200">200 Level</option>
                        <option value="300">300 Level</option>
                        <option value="400">400 Level</option>
                        <option value="500">500 Level</option>
                    </select>
                </div>
            </div>
            <div style="margin-top: 15px;">
                <button class="btn" onclick="resetCourseFilters()">
                    <i class="fas fa-undo"></i> Reset Filters
                </button>
            </div>
        </div>
        <div class="courses-grid">
    `;

        // Filter courses
        let filteredCourses = Object.entries(groupedByCourse);
        const searchTerm = document.getElementById('courseSearchInput')?.value.toLowerCase() || '';
        const levelFilter = document.getElementById('levelFilterSelect')?.value || 'all';

        if (searchTerm) {
            filteredCourses = filteredCourses.filter(([code, course]) =>
                course.courseCode.toLowerCase().includes(searchTerm) ||
                course.courseName.toLowerCase().includes(searchTerm)
            );
        }
        if (levelFilter !== 'all') {
            filteredCourses = filteredCourses.filter(([code, course]) => course.level === levelFilter);
        }

        if (filteredCourses.length === 0) {
            container.innerHTML = `
            <div style="text-align: center; padding: 60px;">
                <i class="fas fa-search" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <h3>No courses found</h3>
                <p>Try adjusting your search filters</p>
                <button class="btn primary" onclick="resetCourseFilters()">Reset Filters</button>
            </div>
        `;
            return;
        }

        for (const [courseKey, course] of filteredCourses) {
            const courseCode = course.courseCode;
            const courseSubmissions = course.submissions;

            // Calculate statistics
            let totalScore = 0;
            let gradedCount = courseSubmissions.filter(s => isGradedSubmission(s.status)).length;
            let totalStudents = courseSubmissions.length;

            courseSubmissions.forEach(sub => {
                const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
                const classScore = studentClassScores[sub.student_identifier] || 0;
                const finalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
                totalScore += finalScore;
            });

            const averageScore = totalStudents > 0 ? roundToWholeNumber(totalScore / totalStudents) : 0;
            const progressPercent = (gradedCount / totalStudents) * 100;

            const levelColor = courseAccentColor(courseCode, course.courseName);

            html += `
            <div class="course-card level-${course.level}" onclick='showCourseDetails(${JSON.stringify(courseKey)})'>
                <div class="course-card-header" style="background: linear-gradient(135deg, ${levelColor}, ${levelColor}dd);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <h3 style="margin: 0; font-size: 20px;">
                                <i class="fas fa-code"></i> ${escapeHTML(course.courseName)}
                            </h3>
                            <p style="margin: 8px 0 0 0; opacity: 0.9; font-size: 14px;">
                                <i class="fas fa-tag"></i> ${escapeHTML(courseCode)}
                            </p>
                        </div>
                        <div class="course-level-badge" style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-weight: bold;">
                            Level ${escapeHTML(course.level)}
                        </div>
                    </div>
                </div>
                
                <div class="course-card-stats" style="display: flex; justify-content: space-between; padding: 20px;">
                    <div class="course-stat" style="text-align: center; flex: 1;">
                        <div class="course-stat-value" style="font-size: 28px; font-weight: 700; color: ${levelColor};">${totalStudents}</div>
                        <div class="course-stat-label" style="font-size: 11px; color: var(--muted); text-transform: uppercase;">Students</div>
                    </div>
                    <div class="course-stat" style="text-align: center; flex: 1;">
                        <div class="course-stat-value" style="font-size: 28px; font-weight: 700; color: ${levelColor};">${averageScore}%</div>
                        <div class="course-stat-label" style="font-size: 11px; color: var(--muted); text-transform: uppercase;">Avg Score</div>
                    </div>
                    <div class="course-stat" style="text-align: center; flex: 1;">
                        <div class="course-stat-value" style="font-size: 28px; font-weight: 700; color: ${levelColor};">${gradedCount}/${totalStudents}</div>
                        <div class="course-stat-label" style="font-size: 11px; color: var(--muted); text-transform: uppercase;">Graded</div>
                    </div>
                </div>
                
                <div style="padding: 0 20px;">
                    <div class="progress" style="height: 6px; background: var(--border); border-radius: 10px;">
                        <div class="progress-bar" style="width: ${progressPercent}%; background: ${levelColor}; height: 100%; border-radius: 10px;"></div>
                    </div>
                </div>
                
                <div class="course-card-footer" style="padding: 15px 20px; background: var(--bg); display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 12px; color: var(--muted);">
                        <i class="fas fa-calendar"></i> ${escapeHTML(course.semester)}
                    </div>
                    <div style="font-size: 12px; color: ${levelColor};">
                        Click to view <i class="fas fa-arrow-right"></i>
                    </div>
                </div>
            </div>
        `;
        }

        html += `</div>`;
        container.innerHTML = html;
    }

    // Show full submission page for selected course
    function showCourseDetails(courseCode) {
        const course = window.courseGroupsData[courseCode];
        if (!course) {
            toast('❌ Course not found');
            return;
        }

        currentExpandedCourse = courseCode;
        currentCourseData = course;

        // Save state for page refresh
        sessionStorage.setItem('currentSubmissionView', 'course_detail');
        sessionStorage.setItem('currentCourseCode', courseCode);

        // Render the full submission page for this course
        renderFullSubmissionPage(course);
    }

    // Render the complete submission page (all filters, stats, table) for a specific course
    function renderFullSubmissionPage(course) {
        const container = document.getElementById('submissionsContainer');
        const courseSubmissions = course.submissions;
        const examDetail = course.examDetail;

        // Filter submissions for this course only
        const filteredSubmissions = originalSubmissionsData.filter(s => {
            const examDetail2 = examDetailsCache[s.exam_id];
            const subCourseCode = examDetail2 ? examDetail2.course_code : (s.course_code || '');
            const subCourseName = examDetail2 ? examDetail2.title : (s.exam_title || '');
            return subCourseCode === course.courseCode && subCourseName === course.courseName;
        });

        // Calculate statistics for this course
        let totalScoreSum = 0;
        let passed = 0;
        let grades = {
            A: 0,
            Bplus: 0,
            B: 0,
            Cplus: 0,
            C: 0,
            Dplus: 0,
            D: 0,
            E: 0
        };

        courseSubmissions.forEach(sub => {
            const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
            totalScoreSum += totalScore;

            if (totalScore >= 50) passed++;

            const gradeInfo = getGradeInfo(totalScore);
            if (gradeInfo.grade === 'A') grades.A++;
            else if (gradeInfo.grade === 'B+') grades.Bplus++;
            else if (gradeInfo.grade === 'B') grades.B++;
            else if (gradeInfo.grade === 'C+') grades.Cplus++;
            else if (gradeInfo.grade === 'C') grades.C++;
            else if (gradeInfo.grade === 'D+') grades.Dplus++;
            else if (gradeInfo.grade === 'D') grades.D++;
            else grades.E++;
        });

        const studentCount = courseSubmissions.length;
        const average = studentCount > 0 ? roundToWholeNumber(totalScoreSum / studentCount) : 0;
        const passRate = studentCount > 0 ? roundToWholeNumber((passed / studentCount) * 100) : 0;

        // Check if all students have class scores
        const allHaveClassScores = courseSubmissions.every(sub => studentClassScores[sub.student_identifier] !==
            undefined);

        // Get course details for header
        const schoolName = examDetail?.school_name || 'Pentecost University';
        const programme = examDetail?.programme || examDetail?.department || 'IT';
        const schoolType = examDetail?.school_type || 'Regular';
        const level = examDetail?.level || course.level;
        const semester = examDetail?.semester || course.semester;
        const academicYear = examDetail?.academic_year || `${new Date().getFullYear()}/${new Date().getFullYear() + 1}`;
        const intake = examDetail?.intake || '1';
        const courseName = examDetail?.title || course.courseName;
        const courseCode = examDetail?.course_code || course.courseCode;
        const courseKey = course.courseKey || `${courseCode}::${courseName}`;

        let html = `
        <!-- Back Button -->
        <div style="margin-bottom: 20px;">
            <button class="back-to-courses-btn" onclick="goBackToCourses()">
                <i class="fas fa-arrow-left"></i> Back to All Courses
            </button>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar" style="background: var(--bg); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--border);">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 15px;">
                <div class="field" style="margin-bottom: 0;">
                    <label><i class="fas fa-user-graduate"></i> Search by Student</label>
                    <input type="text" id="filterStudentSearch" placeholder="Search student name or ID..." 
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);"
                        onkeyup="filterCourseSubmissions()">
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label><i class="fas fa-filter"></i> Filter by Status</label>
                    <select id="filterStatus" onchange="filterCourseSubmissions()"
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        <option value="all">All Status</option>
                        <option value="SUBMITTED">Submitted</option>
                        <option value="GRADED">Graded</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between;">
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="btn" onclick="resetCourseFilters()" style="background: var(--bg);">
                        <i class="fas fa-undo"></i> Reset Filters
                    </button>
                    <button class="btn success" onclick='exportCourseToExcel(${JSON.stringify(courseKey)})'
                        style="background: linear-gradient(135deg, #10b981, #059669); color: white;">
                        <i class="fas fa-file-excel"></i> Export Marks as CSV
                    </button>
                    <button class="btn primary" onclick="refreshSubmissions()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn info" onclick="openCalculatorModal()"
                        style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white;">
                        <i class="fas fa-calculator"></i> Grade Calculator
                    </button>
                    <button class="btn" onclick="openPrintModal()"
                        style="background: linear-gradient(135deg, #6b7280, #4b5563); color: white;">
                        <i class="fas fa-print"></i> Print Score Sheet
                    </button>
                    <button class="btn warn" onclick='downloadCourseSubmissions(${JSON.stringify(courseKey)})'
                        style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white;">
                        <i class="fas fa-folder-download"></i> Download All Submissions (ZIP)
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Class Score Entry Section -->
        <div style="background: linear-gradient(135deg, rgba(59,130,246,0.1), rgba(139,92,246,0.1)); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--border);">
            <h4 style="margin: 0 0 15px 0;"><i class="fas fa-chart-line"></i> Class Score Entry (Max 40 points)</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                <div class="field" style="margin-bottom: 0;">
                    <label>Select Student:</label>
                    <select id="classScoreStudentSelect" style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        <option value="">-- Select Student --</option>
                        ${courseSubmissions.map(sub => `<option value="${sub.student_identifier}">${escapeHTML(sub.student_name)} (${sub.student_identifier})</option>`).join('')}
                    </select>
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label>Class Score (0 - 40):</label>
                    <input type="number" id="classScoreInput" min="0" max="40" step="1" value="0"
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                </div>
                <div style="display: flex; align-items: flex-end; gap: 10px;">
                    <button class="btn primary" onclick='updateClassScoreForCourse(${JSON.stringify(courseKey)})' style="flex: 1;">
                        <i class="fas fa-save"></i> Apply Class Score
                    </button>
                    <button class="btn success" onclick='markAllCourseAsGraded(${JSON.stringify(courseKey)})' style="flex: 1;" ${!allHaveClassScores ? 'disabled' : ''}>
                        <i class="fas fa-check-double"></i> Mark All as Graded
                    </button>
                </div>
            </div>
            <div class="hint" style="margin-top: 10px;">
                <i class="fas fa-info-circle"></i> Exam score (max 60) is calculated from student's submission. Class score (max 40) is added by lecturer. Total = 100.
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px;">
            <div class="stat-card blue" style="padding: 20px; border-radius: 16px; background: var(--panel); border: 1px solid var(--border);">
                <div class="stat-label">TOTAL STUDENTS</div>
                <div class="stat-value" style="font-size: 32px;">${studentCount}</div>
            </div>
            <div class="stat-card green" style="padding: 20px; border-radius: 16px; background: var(--panel); border: 1px solid var(--border);">
                <div class="stat-label">PASSED (≥50)</div>
                <div class="stat-value" style="font-size: 32px; color: #10b981;">${passed}</div>
                <small>${passRate}% pass rate</small>
            </div>
            <div class="stat-card red" style="padding: 20px; border-radius: 16px; background: var(--panel); border: 1px solid var(--border);">
                <div class="stat-label">FAILED (<50)</div>
                <div class="stat-value" style="font-size: 32px; color: #ef4444;">${studentCount - passed}</div>
            </div>
            <div class="stat-card orange" style="padding: 20px; border-radius: 16px; background: var(--panel); border: 1px solid var(--border);">
                <div class="stat-label">AVERAGE SCORE</div>
                <div class="stat-value" style="font-size: 32px;">${average}</div>
            </div>
        </div>
        
        <!-- Grade Distribution -->
        <div style="background: var(--panel); border-radius: 16px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--border);">
            <h4 style="margin: 0 0 15px 0;"><i class="fas fa-chart-pie"></i> Grade Distribution</h4>
            <div style="display: flex; gap: 15px; flex-wrap: wrap; justify-content: space-between;">
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-a" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">A</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.A}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-bplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">B+</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Bplus}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-b" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">B</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.B}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-cplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">C+</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Cplus}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-c" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">C</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.C}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-dplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">D+</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Dplus}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-d" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">D</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.D}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-e" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">E</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.E}</div>
                </div>
            </div>
        </div>
        
        <!-- Course Header with Score Sheet -->
        <div style="background: linear-gradient(135deg, #1e3a5f, #3b82f6); color: white; border-radius: 16px; margin-bottom: 20px; overflow: hidden;">
            <div style="text-align: center; padding: 20px;">
                <h1 style="margin: 0; font-size: 24px;">${escapeHTML(schoolName)}</h1>
                <h2 style="margin: 10px 0; font-size: 20px;">SCORE SHEET</h2>
                <p style="margin: 5px 0; font-size: 14px;">${escapeHTML(programme)} - ${escapeHTML(schoolType)}</p>
                <p style="margin: 5px 0; font-size: 14px;">
                    Level: ${escapeHTML(level)} | Semester: ${escapeHTML(semester)} | Academic Year: ${escapeHTML(academicYear)} | Intake: ${escapeHTML(intake)}
                </p>
                <p style="margin: 5px 0; font-size: 14px; font-weight: bold;">
                    Course: ${escapeHTML(courseName)} (${escapeHTML(courseCode)})
                </p>
            </div>
            <div style="display: flex; justify-content: flex-end; padding: 10px 20px 20px 20px;">
                <button class="btn" onclick='downloadCourseSubmissions(${JSON.stringify(courseKey)})' 
                    style="background: white; color: #3b82f6; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer;">
                    <i class="fas fa-download"></i> Download Course ZIP
                </button>
            </div>
        </div>
        
        <!-- Submissions Table -->
        <div style="overflow-x: auto; border-radius: 12px; border: 1px solid var(--border);">
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #000;" id="courseSubmissionsTable">
                <thead>
                    <tr>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">S/N</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Student ID</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Student Name</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Class Score (40%)</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Exam Score (60%)</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Total Score (100%)</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Grade</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">GP</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Status</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="submissionsTableBody">
    `;

        let sn = 1;
        for (const sub of courseSubmissions) {
            const studentName = sub.student_name || 'Unknown Student';
            const studentId = sub.student_identifier || 'N/A';
            const status = sub.status || 'SUBMITTED';

            let examScoreRaw = parseFloat(sub.percentage) || 0;
            let examScore = roundToWholeNumber(convertTo60Scale(examScoreRaw));
            const classScore = studentClassScores[studentId] || 0;
            const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
            const gradeInfo = getGradeInfo(totalScore);

            let statusClass = 'status-pending';
            let statusText = status;
            if (isGradedSubmission(status)) {
                statusClass = 'status-published';
                statusText = '✓ Graded';
            } else if (normalizeSubmissionStatus(status) === 'SUBMITTED') {
                statusClass = 'status-pending';
                statusText = '📝 Submitted';
            }

            const scoreColor = totalScore >= 70 ? '#10b981' : (totalScore >= 50 ? '#f59e0b' : '#ef4444');
            const hasClassScore = studentClassScores[studentId] !== undefined;

            html += `
            <tr data-student-name="${studentName.toLowerCase()}" data-student-id="${studentId.toLowerCase()}" data-status="${status}">
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">${sn++}</td>
                <td style="border: 1px solid #000; padding: 12px;">
                    <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px;">${escapeHTML(studentId)}</code>
                </td>
                <td style="border: 1px solid #000; padding: 12px;">
                    <strong>${escapeHTML(studentName)}</strong>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                        <strong style="color: #f59e0b; font-size: 18px;">${classScore}</strong>
                        <button class="btn small" onclick='editClassScoreForCourse(${JSON.stringify(studentId)}, ${JSON.stringify(studentName)}, ${JSON.stringify(courseKey)})' 
                            style="padding: 2px 8px; font-size: 11px;">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                    </div>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <strong style="font-size: 18px;">${examScore}</strong>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <div style="font-weight: 700; font-size: 22px; color: ${scoreColor};">${totalScore}</div>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <span class="tag ${gradeInfo.class}" style="font-size: 18px; font-weight: 700; padding: 6px 12px;">${gradeInfo.grade}</span>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <strong>${gradeInfo.gradePoint.toFixed(1)}</strong>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <span class="tag ${statusClass}">${statusText}</span>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center; white-space: nowrap;">
                    <button class="btn primary small" onclick="openQuestionReview(${sub.id})" 
                        style="padding: 6px 12px; margin: 2px;">
                        <i class="fas fa-eye"></i> Review & Mark
                    </button>

<button class="btn info small" onclick="openFullIDE(${sub.id}, '${escapeHTML(sub.student_name)}', '${escapeHTML(sub.student_identifier)}')" style="margin-left: 5px;">
    <i class="fas fa-code"></i> IDE Compiler
</button>
                    
                    <button class="btn success small" onclick="downloadSubmission(${sub.id})" 
                        style="padding: 6px 12px; margin: 2px;">
                        <i class="fas fa-download"></i> Download ZIP
                    </button>
                </td>
            </tr>
        `;
        }

        html += `
                </tbody>
                <tfoot style="background: #f9f9f9;">
                    <tr>
                        <td colspan="9" style="border: 1px solid #000; padding: 8px; text-align: right;"><strong>Total Students:</strong></td>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center;"><strong>${studentCount}</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;

        container.innerHTML = html;

        // Store current course code for filters
        window.currentCourseCode = courseCode;
    }

    // Filter submissions within the current course view
    function filterCourseSubmissions() {
        const searchTerm = document.getElementById('filterStudentSearch')?.value.toLowerCase() || '';
        const statusFilter = document.getElementById('filterStatus')?.value || 'all';
        const tableBody = document.getElementById('submissionsTableBody');

        if (!tableBody) return;

        const rows = tableBody.querySelectorAll('tr');
        let visibleCount = 0;

        rows.forEach(row => {
            const studentName = row.getAttribute('data-student-name') || '';
            const studentId = row.getAttribute('data-student-id') || '';
            const status = row.getAttribute('data-status') || '';

            let matchesSearch = true;
            let matchesStatus = true;

            if (searchTerm) {
                matchesSearch = studentName.includes(searchTerm) || studentId.includes(searchTerm);
            }

            if (statusFilter !== 'all') {
                matchesStatus = status === statusFilter;
            }

            if (matchesSearch && matchesStatus) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Show/hide no results message
        let noResultsMsg = document.getElementById('noFilterResults');
        if (visibleCount === 0 && (searchTerm || statusFilter !== 'all')) {
            if (!noResultsMsg) {
                noResultsMsg = document.createElement('div');
                noResultsMsg.id = 'noFilterResults';
                noResultsMsg.style.cssText = 'text-align: center; padding: 40px; color: var(--muted);';
                noResultsMsg.innerHTML =
                    '<i class="fas fa-search" style="font-size: 48px; margin-bottom: 10px;"></i><p>No students found matching your filters</p>';
                if (tableBody.parentNode.parentNode) {
                    tableBody.parentNode.parentNode.insertBefore(noResultsMsg, tableBody.parentNode.nextSibling);
                }
            }
            noResultsMsg.style.display = 'block';
        } else if (noResultsMsg) {
            noResultsMsg.style.display = 'none';
        }
    }

    // Update class score for a student in the current course
    async function updateClassScoreForCourse(courseCode) {
        const select = document.getElementById('classScoreStudentSelect');
        const scoreInput = document.getElementById('classScoreInput');

        const studentId = select.value;
        let classScore = roundToWholeNumber(scoreInput.value);

        if (!studentId) {
            toast('❌ Please select a student');
            return;
        }

        if (classScore < 0) classScore = 0;
        if (classScore > 40) {
            toast('❌ Class score cannot exceed 40');
            return;
        }

        const course = window.courseGroupsData[courseCode];
        const submission = course.submissions.find(s => s.student_identifier === studentId);

        if (!submission) {
            toast('❌ Submission not found');
            return;
        }

        await persistClassScore(submission, classScore);
        saveClassScores();

        // Refresh the view
        renderFullSubmissionPage(course);

        toast(`✅ Class score ${classScore}/40 applied for ${submission.student_name}`);

        scoreInput.value = 0;
        select.value = "";
    }

    // Edit class score for a student
    async function editClassScoreForCourse(studentId, studentName, courseCode) {
        const currentScore = studentClassScores[studentId] || 0;
        const newScore = prompt(`Enter class score for ${studentName} (0-40):`, currentScore);

        if (newScore !== null) {
            let scoreNum = roundToWholeNumber(newScore);
            if (isNaN(scoreNum)) {
                toast('❌ Please enter a valid number');
                return;
            }
            if (scoreNum < 0) scoreNum = 0;
            if (scoreNum > 40) {
                toast('❌ Class score cannot exceed 40');
                return;
            }

            const course = window.courseGroupsData[courseCode];
            const submission = course.submissions.find(s => s.student_identifier === studentId);
            if (submission) {
                await persistClassScore(submission, scoreNum);
                saveClassScores();
            }

            renderFullSubmissionPage(course);
            toast(`✅ Class score updated to ${scoreNum}/40 for ${studentName}`);
        }
    }

    // Go back to courses list
    function goBackToCourses() {
        currentExpandedCourse = null;
        currentCourseData = null;

        // Clear session storage
        sessionStorage.removeItem('currentSubmissionView');
        sessionStorage.removeItem('currentCourseCode');

        // Clear search inputs
        const courseSearch = document.getElementById('courseSearchInput');
        const levelFilter = document.getElementById('levelFilterSelect');
        if (courseSearch) courseSearch.value = '';
        if (levelFilter) levelFilter.value = 'all';

        // Re-render the course cards
        if (originalSubmissionsData) {
            renderCourseCardsView(originalSubmissionsData);
        } else {
            loadSubmissions();
        }
    }

    // Filter course cards
    function filterCourseCards() {
        if (originalSubmissionsData && !currentExpandedCourse) {
            renderCourseCardsView(originalSubmissionsData);
        }
    }

    // Reset course filters
    function resetCourseFilters() {
        const searchInput = document.getElementById('courseSearchInput');
        const levelFilter = document.getElementById('levelFilterSelect');
        const studentSearch = document.getElementById('filterStudentSearch');
        const statusFilter = document.getElementById('filterStatus');

        if (searchInput) searchInput.value = '';
        if (levelFilter) levelFilter.value = 'all';
        if (studentSearch) studentSearch.value = '';
        if (statusFilter) statusFilter.value = 'all';

        if (currentExpandedCourse && currentCourseData) {
            filterCourseSubmissions();
        } else {
            filterCourseCards();
        }
        toast('Filters reset');
    }

    // Mark all submissions in a course as graded
    async function markAllCourseAsGraded(courseCode) {
        const course = window.courseGroupsData[courseCode];
        if (!course) return;

        const courseSubmissions = course.submissions;

        // Check if all students have class scores
        const allHaveClassScores = courseSubmissions.every(s => studentClassScores[s.student_identifier] !==
            undefined);

        if (!allHaveClassScores) {
            toast('❌ Please fill in class scores for all students in this course first');
            return;
        }

        if (!confirm(`Mark ALL ${courseSubmissions.length} submissions as graded for ${course.courseName}?`))
            return;

        showLoading(`Marking ${courseSubmissions.length} submissions...`);
        let count = 0;

        for (const submission of courseSubmissions) {
            if (submission.status !== 'GRADED') {
                submission.status = 'GRADED';

                const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(submission.percentage) || 0));
                const classScore = studentClassScores[submission.student_identifier] || 0;
                const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
                const gradeInfo = getGradeInfo(totalScore);

                await apiRequest('update_submission_grade', {
                    submission_id: submission.id,
                    status: 'GRADED',
                    total_score: totalScore,
                    class_score: classScore,
                    exam_score: examScore,
                    grade: gradeInfo.grade,
                    grade_point: gradeInfo.gradePoint
                });
                count++;
            }
        }

        saveGradedStatus();

        // Refresh the view
        renderFullSubmissionPage(course);

        toast(`✅ ${count} submissions marked as graded for ${course.courseName}`);
        hideLoading();
    }

    // Download all submissions for a course
    async function downloadCourseSubmissions(courseCode) {
        const course = window.courseGroupsData[courseCode];
        if (!course) return;

        const courseSubmissions = course.submissions;

        if (!courseSubmissions || courseSubmissions.length === 0) {
            toast('❌ No submissions found for this course');
            return;
        }

        if (!confirm(`Download ${courseSubmissions.length} submission(s) for ${course.courseName}?`)) {
            return;
        }

        showLoading(`Downloading ${courseSubmissions.length} submissions for ${course.courseName}...`);

        try {
            for (const sub of courseSubmissions) {
                await new Promise((resolve) => {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    form.target = '_blank';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'download_submission_zip';

                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'submission_id';
                    idInput.value = sub.id;

                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                    setTimeout(() => {
                        document.body.removeChild(form);
                        resolve();
                    }, 800);
                });
            }
            toast(`✅ ${courseSubmissions.length} submissions downloaded for ${course.courseName}`);
        } catch (error) {
            console.error('Download error:', error);
            toast('❌ Download failed: ' + error.message);
        } finally {
            setTimeout(() => hideLoading(), 2000);
        }
    }

    // Export course to Excel
    function exportCourseToExcel(courseCode) {
        const course = window.courseGroupsData[courseCode];
        if (!course) return;

        const exportData = course.submissions.map(sub => {
            const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
            const gradeInfo = getGradeInfo(totalScore);

            return {
                'Student ID': sub.student_identifier || 'N/A',
                'Student Name': sub.student_name || 'Unknown',
                'Course Code': course.courseCode,
                'Exam Title': sub.exam_title || 'Unknown',
                'Class Score (max 40)': classScore,
                'Exam Score (max 60)': examScore,
                'Total Score (max 100)': totalScore,
                'Grade': gradeInfo.grade,
                'Grade Point': gradeInfo.gradePoint.toFixed(1),
                'Status': sub.status || 'SUBMITTED'
            };
        });

        const ws = XLSX.utils.json_to_sheet(exportData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, course.courseCode);

        XLSX.writeFile(wb,
            `${course.courseCode}_marks_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.xlsx`);
        toast(`📊 Exported ${exportData.length} student marks for ${course.courseName}`);
    }


    // Update the openQuestionReview function to include the IDE button
    function openFullIDE(submissionId, studentName, studentId) {
        const url =
            `IDEcompiler.php?submission_id=${submissionId}&student_name=${encodeURIComponent(studentName)}&student_id=${encodeURIComponent(studentId)}`;
        window.open(url, '_blank', 'width=1400,height=900,resizable=yes,scrollbars=yes');
    }



    // ============================================
    // RESULTS PAGE - Student Results by Semester & Course (No Analytics)
    // ============================================

    let currentSemester = '';
    let currentCourseCode = '';
    let currentSearchTerm = '';

    // Initialize results page
    async function initResultsPage() {
        console.log("Initializing results page...");

        const container = document.getElementById('resultsContainer');
        if (!container) {
            console.error("resultsContainer not found!");
            return;
        }

        container.innerHTML = `
        <div style="text-align:center; padding:60px;">
            <div class="spinner"></div>
            <p style="margin-top:10px;">Loading student results...</p>
        </div>
    `;

        try {
            // Load all submissions
            const result = await apiRequest('get_submissions');
            if (result.success && result.data) {
                originalSubmissionsData = result.data;

                // Load exam details for each unique exam
                const uniqueExamIds = [...new Set(originalSubmissionsData.map(s => s.exam_id))];
                for (const examId of uniqueExamIds) {
                    if (!examDetailsCache[examId]) {
                        const examResult = await apiRequest('get_exam_details', {
                            exam_id: examId
                        });
                        if (examResult.success) {
                            examDetailsCache[examId] = examResult.data;
                        }
                    }
                }

                loadSavedClassScores();
                loadGradedStatus();

                // Get unique courses
                const courses = new Map();
                originalSubmissionsData.forEach(sub => {
                    const examDetail = examDetailsCache[sub.exam_id];
                    if (examDetail && examDetail.course_code) {
                        if (!courses.has(examDetail.course_code)) {
                            courses.set(examDetail.course_code, {
                                code: examDetail.course_code,
                                name: examDetail.title,
                                level: examDetail.level,
                                semester: examDetail.semester
                            });
                        }
                    }
                });

                // Render filter bar
                renderResultsFilters(courses);

            } else {
                container.innerHTML = `
                <div style="text-align:center; padding:60px;">
                    <i class="fas fa-folder-open" style="font-size:48px; margin-bottom:15px;"></i>
                    <h3>No Results Available</h3>
                    <p>No student submissions found.</p>
                    <button class="btn primary" onclick="initResultsPage()">Refresh</button>
                </div>
            `;
            }
        } catch (error) {
            console.error('Error:', error);
            container.innerHTML = `
            <div style="text-align:center; padding:60px;">
                <i class="fas fa-exclamation-triangle" style="font-size:48px; margin-bottom:15px;"></i>
                <h3>Error Loading Results</h3>
                <p>${error.message}</p>
                <button class="btn primary" onclick="initResultsPage()">Try Again</button>
            </div>
        `;
        }
    }

    // Render filter bar for results
    function renderResultsFilters(courses) {
        const container = document.getElementById('resultsContainer');
        if (!container) return;

        let courseOptions = '<option value="">All Course Score Sheets</option>';
        const sortedCourses = Array.from(courses.values()).sort((a, b) => a.code.localeCompare(b.code));
        sortedCourses.forEach(course => {
            courseOptions +=
                `<option value="${course.code}">${course.code} - ${course.name} (Level ${course.level})</option>`;
        });

        const filterHtml = `
        <div class="result-filter-bar" style="
            background: var(--panel);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        ">
            <h3 style="margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-chart-line" style="color: #3b82f6;"></i>
                Student Results Dashboard
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div class="field" style="margin-bottom: 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <i class="fas fa-book"></i> Select Course
                    </label>
                    <select id="resultCourseSelect" style="width: 100%; padding: 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        ${courseOptions}
                    </select>
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <i class="fas fa-calendar-alt"></i> Select Semester
                    </label>
                    <select id="resultSemesterSelect" style="width: 100%; padding: 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        <option value="">-- Select Semester --</option>
                        <option value="First Semester">First Semester</option>
                        <option value="Second Semester">Second Semester</option>
                    </select>
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <i class="fas fa-search"></i> Search Student
                    </label>
                    <input type="text" id="resultSearchInput" placeholder="Search by Student ID or Name..." 
                        style="width: 100%; padding: 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                </div>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button class="btn" onclick="resetResultsFilters()">
                    <i class="fas fa-undo"></i> Reset All
                </button>
                <button class="btn primary" onclick="applyResultsFilters()">
                    <i class="fas fa-search"></i> View Results
                </button>
            </div>
        </div>
        <div id="resultsTableContainer"></div>
    `;

        container.innerHTML = filterHtml;
    }

    // Apply filters and render results table
    function applyResultsFilters() {
        const semesterSelect = document.getElementById('resultSemesterSelect');
        const courseSelect = document.getElementById('resultCourseSelect');
        const searchInput = document.getElementById('resultSearchInput');

        currentSemester = semesterSelect ? semesterSelect.value : '';
        currentCourseCode = courseSelect ? courseSelect.value : '';
        currentSearchTerm = searchInput ? searchInput.value : '';

        renderResultsTable();
    }

    // Reset all filters
    function resetResultsFilters() {
        currentSemester = '';
        currentCourseCode = '';
        currentSearchTerm = '';

        const semesterSelect = document.getElementById('resultSemesterSelect');
        const courseSelect = document.getElementById('resultCourseSelect');
        const searchInput = document.getElementById('resultSearchInput');

        if (semesterSelect) semesterSelect.value = '';
        if (courseSelect) courseSelect.value = '';
        if (searchInput) searchInput.value = '';

        const tableContainer = document.getElementById('resultsTableContainer');
        if (tableContainer) {
            tableContainer.innerHTML = `
            <div style="text-align: center; padding: 60px; background: var(--panel); border-radius: 16px; border: 1px solid var(--border);">
                <i class="fas fa-chart-line" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <h3 style="margin-bottom: 10px;">Ready to View Results</h3>
                <p style="color: var(--muted);">Select a course for course-based printing/exporting, or view all courses together.</p>
            </div>
        `;
        }
    }

    function buildResultGroups() {
        let filteredSubmissions = [...originalSubmissionsData];

        if (currentCourseCode) {
            filteredSubmissions = filteredSubmissions.filter(sub => {
                const examDetail = examDetailsCache[sub.exam_id];
                return examDetail && examDetail.course_code === currentCourseCode;
            });
        }

        if (currentSemester) {
            filteredSubmissions = filteredSubmissions.filter(sub => {
                const examDetail = examDetailsCache[sub.exam_id];
                return examDetail && examDetail.semester === currentSemester;
            });
        }

        if (currentSearchTerm) {
            const searchLower = currentSearchTerm.toLowerCase();
            filteredSubmissions = filteredSubmissions.filter(sub =>
                String(sub.student_identifier || '').toLowerCase().includes(searchLower) ||
                String(sub.student_name || '').toLowerCase().includes(searchLower)
            );
        }

        const groups = {};
        filteredSubmissions.forEach(sub => {
            const examDetail = examDetailsCache[sub.exam_id] || {};
            const courseCode = examDetail.course_code || sub.course_code || 'N/A';
            const courseName = examDetail.title || sub.exam_title || 'Unknown Course';
            const semester = examDetail.semester || 'N/A';
            const key = `${courseName}::${courseCode}::${semester}`;

            if (!groups[key]) {
                groups[key] = {
                    key,
                    courseName,
                    courseCode,
                    semester,
                    level: examDetail.level || 'N/A',
                    academicYear: examDetail.academic_year || '',
                    programme: examDetail.programme || examDetail.department || 'BIT',
                    schoolType: examDetail.school_type || examDetail.school || 'Weekend',
                    schoolName: examDetail.school_name || 'PENTECOST UNIVERSITY',
                    intake: examDetail.intake || '0',
                    examIds: [],
                    submissions: []
                };
            }

            if (!groups[key].examIds.includes(sub.exam_id)) groups[key].examIds.push(sub.exam_id);

            const grading = getSubmissionGrading(sub);
            const hasStoredTotal = grading.total_score !== undefined && grading.total_score !== null && grading.total_score !== '';
            const totalScore = roundToWholeNumber(hasStoredTotal ? grading.total_score : (parseFloat(sub.percentage) || 0));
            const classScore = roundToWholeNumber(grading.class_score ?? studentClassScores[sub.student_identifier] ?? 0);
            const examScore = roundToWholeNumber(grading.exam_score ?? Math.max(0, totalScore - classScore));
            const fallbackGradeInfo = getGradeInfo(totalScore);
            const grade = grading.grade || fallbackGradeInfo.grade;
            const gradePoint = parseFloat(grading.grade_point ?? fallbackGradeInfo.gradePoint);

            groups[key].submissions.push({
                id: sub.id,
                examId: sub.exam_id,
                studentId: sub.student_identifier || 'N/A',
                studentName: sub.student_name || 'Unknown Student',
                classScore,
                examScore,
                totalScore,
                grade,
                gradePoint,
                status: sub.status || 'SUBMITTED'
            });
        });

        Object.values(groups).forEach(group => {
            group.submissions.sort((a, b) => String(a.studentName).localeCompare(String(b.studentName)));
        });

        return Object.values(groups).sort((a, b) =>
            `${a.courseName} ${a.courseCode} ${a.semester}`.localeCompare(`${b.courseName} ${b.courseCode} ${b.semester}`)
        );
    }

    function renderResultsTable() {
        const container = document.getElementById('resultsTableContainer');
        if (!container) return;

        const groups = buildResultGroups();
        window.currentResultGroups = {};
        groups.forEach(group => window.currentResultGroups[group.key] = group);

        if (groups.length === 0) {
            container.innerHTML = `
            <div style="text-align: center; padding: 60px; background: var(--panel); border-radius: 16px; border: 1px solid var(--border);">
                <i class="fas fa-folder-open" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <h3 style="margin-bottom: 10px;">No Results Found</h3>
                <p style="color: var(--muted);">No student results found for the selected course, semester, and search.</p>
                <button class="btn primary" onclick="resetResultsFilters()" style="margin-top: 15px;">Clear Filters</button>
            </div>
        `;
            return;
        }

        container.innerHTML = groups.map(renderCourseResultSheet).join('');
    }

    function renderCourseResultSheet(group) {
        const safeSheetName = `${group.courseName}_${group.courseCode}_${group.semester}`.replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 31);
        const keyToken = encodeURIComponent(group.key);

        return `
            <section class="course-score-sheet" data-result-key="${escapeHTML(group.key)}" data-sheet-name="${escapeHTML(safeSheetName)}" style="margin-bottom: 32px;">
                <div class="result-sheet-actions" style="background: linear-gradient(135deg, #1e3a5f, #3b82f6); color: white; border-radius: 16px; padding: 20px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                        <div>
                            <h2 style="margin: 0 0 8px 0;">${escapeHTML(group.courseName)}</h2>
                            <h3 style="margin: 0 0 8px 0;">SCORE SHEET</h3>
                            <p style="margin: 0; opacity: 0.95;">Course Code: ${escapeHTML(group.courseCode)} | Semester: ${escapeHTML(group.semester)} | Level: ${escapeHTML(group.level)}</p>
                            <p style="margin: 6px 0 0; opacity: 0.85;">${group.submissions.length} student submission(s)</p>
                        </div>
                        <div class="result-sheet-actions" style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
                            <button class="btn ok small" onclick="publishCourseResults('${keyToken}')"><i class="fas fa-upload"></i> Publish</button>
                            <button class="btn success small" onclick="exportCourseResultsToExcel('${keyToken}')"><i class="fas fa-file-excel"></i> Excel</button>
                            <button class="btn primary small" onclick="printCourseResults('${keyToken}')"><i class="fas fa-print"></i> Print</button>
                            <button class="btn danger small" onclick="deleteCourseSubmissions('${keyToken}')"><i class="fas fa-trash"></i> Delete Course Submissions</button>
                        </div>
                    </div>
                </div>
                ${renderPuScoreSheetTemplate(group, true)}
            </section>
        `;
    }

    function normalizePuSemester(semester) {
        const value = String(semester || '').toLowerCase();
        if (value.includes('first') || value === '1') return '1';
        if (value.includes('second') || value === '2') return '2';
        return String(semester || '');
    }

    function getTemplateGrade(result) {
        if (Number(result.classScore || 0) === 0 && Number(result.examScore || 0) === 0 && Number(result.totalScore || 0) === 0) {
            return 'X';
        }
        return result.grade || getGradeInfo(result.totalScore).grade;
    }

    function getGradeRemark(grade) {
        const remarks = {
            A: 'Excellent',
            'B+': 'Very Good',
            B: 'Good',
            'C+': 'Average',
            C: 'Fair',
            'D+': 'Barely Satisfactory',
            D: 'Weak Pass',
            E: 'Fail',
            X: 'Absent'
        };
        return remarks[grade] || '';
    }

    function buildGradeAnalysis(group) {
        const counts = {};
        group.submissions.forEach(result => {
            const grade = getTemplateGrade(result);
            counts[grade] = (counts[grade] || 0) + 1;
        });
        const order = ['D+', 'B', 'A', 'B+', 'X', 'C+', 'C', 'D', 'E'];
        return order
            .filter(grade => counts[grade])
            .map(grade => ({
                grade,
                count: counts[grade],
                percentage: group.submissions.length > 0 ? ((counts[grade] / group.submissions.length) * 100).toFixed(1) : '0.0',
                remark: getGradeRemark(grade)
            }));
    }

    function renderPuScoreSheetTemplate(group, includeActions = false) {
        const rows = group.submissions.map((result, index) => {
            const grade = getTemplateGrade(result);
            return `
                <tr data-submission-id="${result.id}">
                    <td>${index + 1}</td>
                    <td>${escapeHTML(result.studentId)}</td>
                    <td class="student-name-cell">${escapeHTML(result.studentName)}</td>
                    <td>${result.classScore}</td>
                    <td>${result.examScore}</td>
                    <td>${result.totalScore}</td>
                    <td>${escapeHTML(grade)}</td>
                    ${includeActions ? `<td class="result-actions no-print"><button class="btn primary small" onclick="openQuestionReview(${result.id})"><i class="fas fa-eye"></i> Review</button> <button class="btn danger small" onclick="deleteStudentSubmission(${result.id})"><i class="fas fa-trash"></i> Delete</button></td>` : ''}
                </tr>
            `;
        }).join('');

        const gradeRows = buildGradeAnalysis(group).map(item => `
            <tr>
                <td>${escapeHTML(item.grade)}</td>
                <td>${item.count}</td>
                <td>${item.percentage}</td>
                <td class="remark-cell">${escapeHTML(item.remark)}</td>
            </tr>
        `).join('');

        return `
            <div class="pu-score-template">
                <style>
                    .pu-score-template {
                        background: #fff;
                        color: #000;
                        font-family: Arial, Helvetica, sans-serif;
                        width: 100%;
                        max-width: 1180px;
                        margin: 0 auto;
                        padding: 16px 20px;
                        overflow-x: auto;
                    }
                    .pu-score-template .score-title {
                        text-align: center;
                        font-weight: 700;
                        line-height: 1.22;
                    }
                    .pu-score-template .score-title h1 {
                        font-size: 18px;
                        margin: 0 0 4px;
                        letter-spacing: 0;
                    }
                    .pu-score-template .score-title h2 {
                        font-size: 16px;
                        margin: 0 0 6px;
                        letter-spacing: 0;
                    }
                    .pu-score-template .score-meta {
                        text-align: center;
                        font-size: 12px;
                        line-height: 1.35;
                        margin-bottom: 8px;
                    }
                    .pu-score-template table {
                        border-collapse: collapse;
                        width: 100%;
                        font-size: 11px;
                    }
                    .pu-score-template th,
                    .pu-score-template td {
                        border: 1px solid #111;
                        padding: 4px 5px;
                        text-align: center;
                        vertical-align: middle;
                    }
                    .pu-score-template th {
                        font-weight: 700;
                        background: #fff;
                    }
                    .pu-score-template .student-name-cell,
                    .pu-score-template .remark-cell {
                        text-align: left;
                    }
                    .pu-score-template .main-score-table .col-no { width: 34px; }
                    .pu-score-template .main-score-table .col-student-no { width: 118px; }
                    .pu-score-template .main-score-table .col-name { min-width: 245px; }
                    .pu-score-template .main-score-table .col-score { width: 78px; }
                    .pu-score-template .grade-analysis-title {
                        font-size: 12px;
                        font-weight: 700;
                        margin: 12px 0 4px;
                    }
                    .pu-score-template .grade-analysis-table {
                        width: 430px;
                        max-width: 100%;
                    }
                    .pu-score-template .result-actions {
                        min-width: 150px;
                        white-space: nowrap;
                    }
                    @media print {
                        @page { size: A4 landscape; margin: 9mm; }
                        body { background: #fff !important; }
                        .no-print, .result-sheet-actions, .result-actions { display: none !important; }
                        .pu-score-template {
                            max-width: none;
                            padding: 0;
                            overflow: visible;
                        }
                        .pu-score-template table { font-size: 10.5px; }
                        .pu-score-template th,
                        .pu-score-template td { padding: 3px 4px; }
                    }
                </style>
                <div class="score-title">
                    <h1>${escapeHTML(String(group.schoolName || 'PENTECOST UNIVERSITY').toUpperCase())}</h1>
                    <h2>SCORE SHEET</h2>
                </div>
                <div class="score-meta">
                    <div><strong>${escapeHTML(group.programme || 'BIT')}&nbsp;-&nbsp;${escapeHTML(group.schoolType || 'Weekend')}</strong></div>
                    <div>
                        Level ${escapeHTML(group.level || '')}&nbsp;&nbsp;
                        Semester ${escapeHTML(normalizePuSemester(group.semester))}&nbsp;&nbsp;
                        ${escapeHTML(group.academicYear || '')}&nbsp;&nbsp;
                        Intake: ${escapeHTML(group.intake || '0')}
                    </div>
                    <div><strong>${escapeHTML(group.courseName)} - ${escapeHTML(group.courseCode)}</strong></div>
                </div>
                <table class="main-score-table">
                    <thead>
                        <tr>
                            <th class="col-no">No</th>
                            <th class="col-student-no">Student No</th>
                            <th class="col-name">Student Name</th>
                            <th class="col-score">Class<br>Score<br>40</th>
                            <th class="col-score">Exam<br>Score<br>60</th>
                            <th class="col-score">Total<br>Score<br>100</th>
                            <th class="col-score">Grade</th>
                            ${includeActions ? '<th class="result-actions no-print">Actions</th>' : ''}
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
                <div class="grade-analysis-title">GRADE ANALYSIS</div>
                <table class="grade-analysis-table">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>No. of Students</th>
                            <th>Percentage(%)</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>${gradeRows}</tbody>
                </table>
            </div>
        `;
    }

    function normalizeResultGroupKey(groupKey) {
        try {
            return decodeURIComponent(groupKey);
        } catch (error) {
            return groupKey;
        }
    }

    function getResultGroup(groupKey) {
        groupKey = normalizeResultGroupKey(groupKey);
        return (window.currentResultGroups || {})[groupKey] || null;
    }

    function getResultSheetElement(groupKey) {
        groupKey = normalizeResultGroupKey(groupKey);
        return Array.from(document.querySelectorAll('.course-score-sheet'))
            .find(sheet => sheet.dataset.resultKey === groupKey) || null;
    }

    function exportCourseResultsToExcel(groupKey) {
        const group = getResultGroup(groupKey);
        if (!group) {
            toast('No score sheet to export');
            return;
        }

        const gradeAnalysis = buildGradeAnalysis(group);
        const rows = [
            [String(group.schoolName || 'PENTECOST UNIVERSITY').toUpperCase()],
            ['SCORE SHEET'],
            [`${group.programme || 'BIT'} - ${group.schoolType || 'Weekend'}`],
            [`Level ${group.level || ''}`, `Semester ${normalizePuSemester(group.semester)}`, group.academicYear || '', `Intake: ${group.intake || '0'}`],
            [`${group.courseName} - ${group.courseCode}`],
            [],
            ['No', 'Student No', 'Student Name', 'Class Score 40', 'Exam Score 60', 'Total Score 100', 'Grade']
        ];

        group.submissions.forEach((result, index) => {
            rows.push([
                index + 1,
                result.studentId,
                result.studentName,
                result.classScore,
                result.examScore,
                result.totalScore,
                getTemplateGrade(result)
            ]);
        });

        rows.push([]);
        rows.push(['GRADE ANALYSIS']);
        rows.push(['Grade', 'No. of Students', 'Percentage(%)', 'Remark']);
        gradeAnalysis.forEach(item => {
            rows.push([item.grade, item.count, item.percentage, item.remark]);
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(rows);
        ws['!cols'] = [
            { wch: 6 },
            { wch: 18 },
            { wch: 36 },
            { wch: 14 },
            { wch: 14 },
            { wch: 15 },
            { wch: 10 }
        ];
        ws['!merges'] = [
            { s: { r: 0, c: 0 }, e: { r: 0, c: 6 } },
            { s: { r: 1, c: 0 }, e: { r: 1, c: 6 } },
            { s: { r: 2, c: 0 }, e: { r: 2, c: 6 } },
            { s: { r: 4, c: 0 }, e: { r: 4, c: 6 } },
            { s: { r: rows.length - gradeAnalysis.length - 2, c: 0 }, e: { r: rows.length - gradeAnalysis.length - 2, c: 3 } }
        ];
        const sheetName = `${group.courseName}_${group.courseCode}_${group.semester}`.replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 31) || 'ScoreSheet';
        XLSX.utils.book_append_sheet(wb, ws, sheetName);
        const filename = `${group.courseCode}_${group.semester}_scoresheet_${new Date().toISOString().slice(0, 10)}.xlsx`.replace(/[^A-Za-z0-9_.-]/g, '_');
        XLSX.writeFile(wb, filename);
        toast(`Exported ${group.courseName} (${group.courseCode}) score sheet`);
    }

    function printCourseResults(groupKey) {
        const group = getResultGroup(groupKey);
        const sheet = getResultSheetElement(groupKey);
        if (!group || !sheet) {
            toast('No score sheet to print');
            return;
        }

        const printContent = renderPuScoreSheetTemplate(group, false);
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>${escapeHTML(group.courseCode)} ${escapeHTML(group.semester)} Score Sheet</title>
                    <style>
                        @page { size: A4 landscape; margin: 9mm; }
                        body { margin: 0; background: #fff; }
                    </style>
                </head>
                <body>
                    ${printContent}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    async function publishCourseResults(groupKey) {
        const group = getResultGroup(groupKey);
        if (!group || group.examIds.length === 0) {
            toast('No results to publish for this score sheet');
            return;
        }
        if (!confirm(`Publish ${group.courseName} (${group.courseCode}) - ${group.semester} results? Students will be able to see them.`)) return;

        showLoading('Publishing results...');
        let published = 0;
        for (const examId of group.examIds) {
            const result = await apiRequest('publish_results', { exam_id: examId });
            if (result.success) published++;
        }
        hideLoading();
        toast(`Published ${group.courseName} (${group.courseCode}) result for ${published} exam(s)`);
    }

    async function deleteStudentSubmission(submissionId) {
        const groups = Object.values(window.currentResultGroups || {});
        const submission = groups.flatMap(group => group.submissions).find(item => Number(item.id) === Number(submissionId));
        const label = submission ? `${submission.studentName}'s submission` : 'this student submission';
        if (!confirm(`Delete ${label}? This cannot be undone.`)) return;

        const previousCourse = currentCourseCode;
        const previousSemester = currentSemester;
        const previousSearch = currentSearchTerm;
        showLoading('Deleting submission...');
        const result = await apiRequest('delete_student_submission', { submission_id: submissionId });
        hideLoading();
        if (result.success) {
            toast('Submission deleted');
            await initResultsPage();
            currentCourseCode = previousCourse;
            currentSemester = previousSemester;
            currentSearchTerm = previousSearch;
            const courseSelect = document.getElementById('resultCourseSelect');
            const semesterSelect = document.getElementById('resultSemesterSelect');
            const searchInput = document.getElementById('resultSearchInput');
            if (courseSelect) courseSelect.value = currentCourseCode;
            if (semesterSelect) semesterSelect.value = currentSemester;
            if (searchInput) searchInput.value = currentSearchTerm;
            renderResultsTable();
        } else {
            toast('Delete failed: ' + (result.error || 'Unknown error'));
        }
    }

    async function deleteCourseSubmissions(groupKey) {
        const group = getResultGroup(groupKey);
        if (!group) {
            toast('Course score sheet not found');
            return;
        }
        if (!confirm(`Delete ALL submissions for ${group.courseName} (${group.courseCode}) - ${group.semester}? This cannot be undone.`)) return;

        const previousCourse = currentCourseCode;
        const previousSemester = currentSemester;
        const previousSearch = currentSearchTerm;
        showLoading('Deleting course submissions...');
        const result = await apiRequest('delete_course_submissions', { exam_ids: JSON.stringify(group.examIds) });
        hideLoading();
        if (result.success) {
            toast(`Deleted ${result.deleted || 0} submission(s) for ${group.courseCode}`);
            await initResultsPage();
            currentCourseCode = previousCourse;
            currentSemester = previousSemester;
            currentSearchTerm = previousSearch;
            const courseSelect = document.getElementById('resultCourseSelect');
            const semesterSelect = document.getElementById('resultSemesterSelect');
            const searchInput = document.getElementById('resultSearchInput');
            if (courseSelect) courseSelect.value = currentCourseCode;
            if (semesterSelect) semesterSelect.value = currentSemester;
            if (searchInput) searchInput.value = currentSearchTerm;
            renderResultsTable();
        } else {
            toast('Delete failed: ' + (result.error || 'Unknown error'));
        }
    }
    // Override the renderResults function to use our new results page
    const originalRenderResults = window.renderResults;
    window.renderResults = function() {
        initResultsPage();
    };





    // ============================================
    // 6. STUDENT MANAGEMENT FUNCTIONS
    // ============================================

    function getStudents() {
        return readJSON(K_STUDENTS, []);
    }

    function saveStudents(students) {
        writeJSON(K_STUDENTS, students);
    }

    async function loadStudents() {
        try {
            const result = await apiRequest('get_students');
            if (result.success && result.data) {
                allStudents = result.data;
                allStudentsDetails = result.data;
                filteredStudents = [...allStudents];
                filteredStudentDetails = [...allStudentsDetails];
                renderStudentsTable();
                renderStudentDetailsTable();
            } else {
                renderStudentsTableEmpty();
                renderStudentDetailsTableEmpty();
            }
        } catch (error) {
            console.error('Error loading students:', error);
            renderStudentsTableEmpty();
            renderStudentDetailsTableEmpty();
        }
    }

    function renderStudentsTable() {
        const tbody = document.getElementById('studentsTableBody');
        if (!tbody) return;

        if (!filteredStudents || filteredStudents.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="9" style="text-align:center; padding:40px;">👥 No students found. Click "Add New Student" or "Import Students" to get started.您</td></tr>';
            return;
        }

        tbody.innerHTML = filteredStudents.map((s, index) => {
            const statusClass = s.status === 'Active' ? 'status-active' : 'status-inactive';

            // Get course information correctly
            let courseCode = s.course_code || '—';
            let courseName = s.course_name || '—';
            let enrolledCoursesList = s.enrolled_courses_names || s.enrolled_courses || '—';

            // If enrolled_courses_names exists and has multiple courses, show tooltip
            const hasMultipleCourses = enrolledCoursesList !== '—' && enrolledCoursesList.includes(',');
            const courseDisplay = hasMultipleCourses ?
                `<span title="Enrolled in: ${escapeHTML(enrolledCoursesList)}" style="cursor: help;">
                ${escapeHTML(courseCode)} <i class="fas fa-info-circle" style="font-size: 11px; color: var(--blue);"></i>
             </span>` :
                escapeHTML(courseCode);

            return `
            <tr class="student-row">
                <td>${index + 1}</td>
                <td><span class="tag">${escapeHTML(s.student_id || '—')}</span></td>
                <td><b>${escapeHTML(s.full_name || '—')}</b></td>
                <td>${escapeHTML(s.level || '—')}</td>
                <td>${escapeHTML(s.programme || '—')}</td>
                <td><code style="background: var(--bg); padding: 4px 8px; border-radius: 6px; font-size: 12px;">${courseDisplay}</code></td>
                <td><small>${escapeHTML(courseName)}</small></td>
                <td><span class="tag ${statusClass}">${s.status || 'Active'}</span></td>
                <td class="action-buttons" style="white-space: nowrap;">
                    <button class="action-btn" onclick="viewStudentCourses(${s.id})" title="View All Courses"><i class="fas fa-book"></i></button>
                    <button class="action-btn" onclick="editStudentById(${s.id})" title="Edit Student"><i class="fas fa-edit"></i></button>
                    <button class="action-btn" onclick="showEnrollModal(${s.id}, '${escapeHTML(s.full_name)}')" title="Enroll in Additional Course"><i class="fas fa-plus-circle"></i></button>
                    <button class="action-btn" onclick="resetStudentPasswordById(${s.id})" title="Reset Password"><i class="fas fa-key"></i></button>
                    <button class="action-btn" onclick="deleteStudentById(${s.id})" title="Delete" style="color: #ef4444;"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        }).join('');
    }

    async function loadStudents() {
        try {
            const result = await apiRequest('get_students');
            if (result.success && result.data) {
                // The API now returns course information directly
                allStudents = result.data;
                allStudentsDetails = [...allStudents];
                filteredStudents = [...allStudents];
                filteredStudentDetails = [...allStudentsDetails];
                renderStudentsTable();
                renderStudentDetailsTable();
            } else {
                renderStudentsTableEmpty();
                renderStudentDetailsTableEmpty();
            }
        } catch (error) {
            console.error('Error loading students:', error);
            renderStudentsTableEmpty();
            renderStudentDetailsTableEmpty();
        }
    }

    function renderStudentsTableEmpty() {
        const tbody = document.getElementById('studentsTableBody');
        if (tbody) {
            tbody.innerHTML =
                '<tr><td colspan="7" style="text-align:center; padding:40px;">❌ Failed to load students. Please refresh the page. </td></tr>';
        }
    }

    function renderStudentDetailsTable() {
        const tbody = document.getElementById('studentDetailsBody');
        if (!tbody) return;

        if (!filteredStudentDetails || filteredStudentDetails.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" style="text-align:center; padding:40px;">👥 No students found.</td></tr>';
            return;
        }

        tbody.innerHTML = filteredStudentDetails.map((s, index) => {
            const statusClass = s.status === 'Active' ? 'status-active' : 'status-inactive';
            const courseCodes = s.enrolled_courses && s.enrolled_courses !== '—' ? s.enrolled_courses : (s.course_code || '—');
            const courseNames = s.enrolled_courses_names && s.enrolled_courses_names !== '—' ? s.enrolled_courses_names : (s.course_name || '—');
            const courseName = `${courseCodes} - ${courseNames}`;

            return `
            <tr class="student-row">
                <td>${index + 1}</td>
                <td><span class="tag">${escapeHTML(s.student_id || '—')}</span></td>
                <td><b>${escapeHTML(s.full_name || '—')}</b></td>
                <td>${escapeHTML(s.level || '—')}</td>
                <td>${escapeHTML(s.programme || '—')}</td>
                <td><small>${escapeHTML(courseName)}</small></td>
                <td><span class="tag ${statusClass}">${s.status || 'Active'}</span></td>
                <td class="action-buttons">
                    <button class="action-btn" onclick="viewStudentDetails(${s.id})" title="View Details"><i class="fas fa-eye"></i></button>
                    <button class="action-btn" onclick="editStudentById(${s.id})" title="Edit Student"><i class="fas fa-edit"></i></button>
                    <button class="action-btn" onclick="resetStudentPasswordById(${s.id})" title="Reset Password"><i class="fas fa-key"></i></button>
                </td>
            </tr>
        `;
        }).join('');
    }

    function renderStudentDetailsTableEmpty() {
        const tbody = document.getElementById('studentDetailsBody');
        if (tbody) {
            tbody.innerHTML =
                '<tr><td colspan="11" style="text-align:center; padding:40px;">👥 No students found. </td></tr>';
        }
    }

    function applyFilters() {
        const searchTerm = document.getElementById('studentSearchInput')?.value.toLowerCase() || '';
        const levelFilter = document.getElementById('levelFilter')?.value || 'all';
        const programmeFilter = document.getElementById('programmeFilter')?.value || 'all';
        const statusFilter = document.getElementById('statusFilter')?.value || 'all';

        filteredStudents = [...allStudents];

        if (searchTerm) {
            filteredStudents = filteredStudents.filter(s =>
                (s.student_id && s.student_id.toLowerCase().includes(searchTerm)) ||
                (s.full_name && s.full_name.toLowerCase().includes(searchTerm)) ||
                (s.email && s.email.toLowerCase().includes(searchTerm))
            );
        }
        if (levelFilter !== 'all') filteredStudents = filteredStudents.filter(s => s.level === levelFilter);
        if (programmeFilter !== 'all') filteredStudents = filteredStudents.filter(s => s.programme === programmeFilter);
        if (statusFilter !== 'all') filteredStudents = filteredStudents.filter(s => s.status === statusFilter);

        renderStudentsTable();
        toast(`Showing ${filteredStudents.length} of ${allStudents.length} students`);
    }

    function applyStudentDetailsFilters() {
        const searchTerm = document.getElementById('studentDetailsSearchInput')?.value.toLowerCase() || '';
        const levelFilter = document.getElementById('studentDetailsLevelFilter')?.value || 'all';
        const programmeFilter = document.getElementById('studentDetailsProgrammeFilter')?.value || 'all';
        const statusFilter = document.getElementById('studentDetailsStatusFilter')?.value || 'all';

        filteredStudentDetails = [...allStudentsDetails];

        if (searchTerm) {
            filteredStudentDetails = filteredStudentDetails.filter(s =>
                (s.student_id && s.student_id.toLowerCase().includes(searchTerm)) ||
                (s.full_name && s.full_name.toLowerCase().includes(searchTerm)) ||
                (s.programme && s.programme.toLowerCase().includes(searchTerm))
            );
        }
        if (levelFilter !== 'all') filteredStudentDetails = filteredStudentDetails.filter(s => s.level === levelFilter);
        if (programmeFilter !== 'all') filteredStudentDetails = filteredStudentDetails.filter(s => s.programme ===
            programmeFilter);
        if (statusFilter !== 'all') filteredStudentDetails = filteredStudentDetails.filter(s => s.status ===
            statusFilter);

        renderStudentDetailsTable();
        toast(`Showing ${filteredStudentDetails.length} of ${allStudentsDetails.length} students`);
    }

    function resetFilters() {
        const searchInput = document.getElementById('studentSearchInput');
        const levelFilter = document.getElementById('levelFilter');
        const programmeFilter = document.getElementById('programmeFilter');
        const statusFilter = document.getElementById('statusFilter');

        if (searchInput) searchInput.value = '';
        if (levelFilter) levelFilter.value = 'all';
        if (programmeFilter) programmeFilter.value = 'all';
        if (statusFilter) statusFilter.value = 'all';

        filteredStudents = [...allStudents];
        renderStudentsTable();
        toast('Filters reset. Showing all students');
    }

    function resetStudentDetailsFilters() {
        const searchInput = document.getElementById('studentDetailsSearchInput');
        const levelFilter = document.getElementById('studentDetailsLevelFilter');
        const programmeFilter = document.getElementById('studentDetailsProgrammeFilter');
        const statusFilter = document.getElementById('studentDetailsStatusFilter');

        if (searchInput) searchInput.value = '';
        if (levelFilter) levelFilter.value = 'all';
        if (programmeFilter) programmeFilter.value = 'all';
        if (statusFilter) statusFilter.value = 'all';

        filteredStudentDetails = [...allStudentsDetails];
        renderStudentDetailsTable();
        toast('Filters reset. Showing all students');
    }

    function showAddStudentModal() {
        resetStudentForm();
        const modal = document.getElementById('studentModal');
        if (modal) modal.style.display = 'flex';
    }

    function addStudentCourseRow(code = '', name = '') {
        const list = document.getElementById('studentCoursesList');
        if (!list) return;
        const row = document.createElement('div');
        row.className = 'student-course-row';
        row.style.cssText = 'display:grid;grid-template-columns:1fr 1.5fr auto;gap:8px;margin-bottom:8px;align-items:center;';
        row.innerHTML = `
            <input type="text" class="form-input student-course-code" placeholder="Course Code" value="${escapeHTML(code)}" required>
            <input type="text" class="form-input student-course-name" placeholder="Course Name" value="${escapeHTML(name)}" required>
            <button type="button" class="btn danger" onclick="removeStudentCourseRow(this)" title="Remove course"><i class="fas fa-trash"></i></button>
        `;
        list.appendChild(row);
    }

    function removeStudentCourseRow(button) {
        const row = button.closest('.student-course-row');
        if (row) row.remove();
        if (!document.querySelector('.student-course-row')) addStudentCourseRow('', '');
    }

    function setStudentCourseRows(courses) {
        const list = document.getElementById('studentCoursesList');
        if (!list) return;
        list.innerHTML = '';
        const normalized = Array.isArray(courses) && courses.length ? courses : [{code: '', name: ''}];
        normalized.forEach(course => addStudentCourseRow(course.code || course.course_code || '', course.name || course.course_name || ''));
    }

    function collectStudentCourses() {
        const rows = Array.from(document.querySelectorAll('.student-course-row'));
        return rows.map(row => ({
            code: row.querySelector('.student-course-code')?.value.trim() || '',
            name: row.querySelector('.student-course-name')?.value.trim() || ''
        })).filter(course => course.code || course.name);
    }

    function resetStudentForm() {
        const title = document.getElementById('studentModalTitle');
        if (title) title.innerHTML = '<i class="fas fa-user-graduate"></i> Add New Student';

        const studentId = document.getElementById('studentId');
        const studentFullName = document.getElementById('studentFullName');
        const studentLevel = document.getElementById('studentLevel');
        const studentProgramme = document.getElementById('studentProgramme');
        const studentStatus = document.getElementById('studentStatus');
        const studentUsername = document.getElementById('studentUsername');
        const studentPassword = document.getElementById('studentPassword');

        if (studentId) {
            studentId.value = '';
            studentId.readOnly = false;
            studentId.style.backgroundColor = '';
            studentId.style.opacity = '';
        }
        if (studentFullName) studentFullName.value = '';
        if (studentLevel) studentLevel.value = '';
        if (studentProgramme) studentProgramme.value = '';
        if (studentStatus) studentStatus.value = 'Active';
        if (studentUsername) studentUsername.value = '';
        if (studentPassword) studentPassword.value = '';
        setStudentCourseRows([{code: '', name: ''}]);

        // Remove required field error styling
        const requiredFields = ['studentId', 'studentFullName', 'studentLevel', 'studentProgramme'];
        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.classList.remove('validation-error');
        });

        currentStudentId = null;
    }

    function closeStudentModal() {
        const modal = document.getElementById('studentModal');
        if (modal) modal.style.display = 'none';
        resetStudentForm();
    }

    async function saveStudent(event) {
        event.preventDefault();

        const id = document.getElementById('studentId')?.value.trim();
        const fullName = document.getElementById('studentFullName')?.value.trim();
        const level = document.getElementById('studentLevel')?.value;
        const programme = document.getElementById('studentProgramme')?.value;
        const status = document.getElementById('studentStatus')?.value;
        const courses = collectStudentCourses();
        const firstCourse = courses[0] || {code: '', name: ''};
        const courseCode = firstCourse.code;
        const courseName = firstCourse.name;

        const errorDiv = document.getElementById('studentFormError');
        if (errorDiv) {
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
        }

        // Validate all required fields
        const errors = [];
        if (!id) errors.push('Student ID is required');
        if (!fullName) errors.push('Full Name is required');
        if (!level) errors.push('Level is required');
        if (!programme) errors.push('Programme is required');
        if (courses.length === 0) errors.push('At least one course is required');
        courses.forEach((course, index) => {
            if (!course.code || !course.name) errors.push(`Course ${index + 1} needs both code and name`);
        });

        if (errors.length > 0) {
            if (errorDiv) {
                errorDiv.textContent = '❌ ' + errors.join(', ');
                errorDiv.style.display = 'block';
                setTimeout(() => errorDiv.style.display = 'none', 5000);
            }
            return;
        }

        const submitBtn = event.submitter || event.target.querySelector('button[type="submit"]');
        const originalText = submitBtn ? submitBtn.innerHTML : 'Save';
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
        }

        try {
            const formData = new FormData();
            if (currentStudentId) {
                formData.append('action', 'update_student');
                formData.append('student_db_id', currentStudentId);
            } else {
                formData.append('action', 'add_student');
            }
            formData.append('student_id', id);
            formData.append('full_name', fullName);
            formData.append('level', level);
            formData.append('programme', programme);
            formData.append('status', status);
            formData.append('course_code', courseCode);
            formData.append('course_name', courseName);
            formData.append('courses', JSON.stringify(courses));

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                toast('✅ Student saved and enrolled in course: ' + courseCode + ' - ' + courseName);
                closeStudentModal();
                await loadStudents(); // This will now fetch course info properly
                resetStudentForm();
                loadDashboardStats();
            } else {
                if (errorDiv) {
                    errorDiv.textContent = '❌ ' + (result.error || 'Failed to save student');
                    errorDiv.style.display = 'block';
                    setTimeout(() => errorDiv.style.display = 'none', 5000);
                }
            }
        } catch (error) {
            console.error('Error:', error);
            if (errorDiv) {
                errorDiv.textContent = '❌ Network error: ' + error.message;
                errorDiv.style.display = 'block';
                setTimeout(() => errorDiv.style.display = 'none', 5000);
            }
        } finally {
            if (submitBtn) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        }
    }

    async function viewStudentCourses(studentId) {
        try {
            const result = await apiRequest('get_student_courses', {
                student_id: studentId
            });
            if (result.success && result.data) {
                let coursesHtml =
                    '<div style="max-height: 300px; overflow-y: auto;"><table class="table" style="width:100%"><thead><tr><th>Course Code</th><th>Course Name</th><th>Enrolled By</th><th>Date</th></tr></thead><tbody>';
                result.data.forEach(course => {
                    coursesHtml += `
                    <tr>
                        <td><code>${escapeHTML(course.course_code)}</code></td>
                        <td>${escapeHTML(course.course_name)}</td>
                        <td>${escapeHTML(course.lecturer_name || '—')}</td>
                        <td>${new Date(course.enrolled_at).toLocaleDateString()}</td>
                    </tr>
                `;
                });
                coursesHtml += '</tbody></table></div>';

                // Find student name
                const student = allStudents.find(s => s.id == studentId);
                const studentName = student ? student.full_name : 'Student';

                const content = `
                <h4>${escapeHTML(studentName)} - Enrolled Courses</h4>
                ${coursesHtml}
            `;

                const contentDiv = document.getElementById('viewStudentContent');
                if (contentDiv) contentDiv.innerHTML = content;

                const modal = document.getElementById('viewStudentModal');
                if (modal) modal.style.display = 'flex';
            } else {
                toast('❌ No courses found for this student');
            }
        } catch (error) {
            console.error('Error loading student courses:', error);
            toast('❌ Failed to load courses');
        }
    }



    async function editStudentById(id) {
        console.log("Edit button clicked for student ID:", id);
        try {
            const result = await apiRequest('get_students');
            if (result.success && result.data) {
                allStudents = result.data;
                const student = allStudents.find(s => s.id == id);
                if (!student) {
                    toast('❌ Student not found');
                    return;
                }

                currentStudentId = student.id;
                const title = document.getElementById('studentModalTitle');
                if (title) title.innerHTML = '<i class="fas fa-edit"></i> Edit Student';

                const studentIdInput = document.getElementById('studentId');
                const studentFullName = document.getElementById('studentFullName');
                const studentLevel = document.getElementById('studentLevel');
                const studentProgramme = document.getElementById('studentProgramme');
                const studentStatus = document.getElementById('studentStatus');
                const studentUsername = document.getElementById('studentUsername');
                const studentPassword = document.getElementById('studentPassword');

                if (studentIdInput) studentIdInput.value = student.student_id || '';
                if (studentFullName) studentFullName.value = student.full_name || '';
                if (studentLevel) studentLevel.value = student.level || '';
                if (studentProgramme) studentProgramme.value = student.programme || '';
                if (studentStatus) studentStatus.value = student.status || 'Active';
                if (studentUsername) studentUsername.value = student.student_id || '';
                if (studentPassword) studentPassword.value = student.student_id || '';
                setStudentCourseRows(student.courses && student.courses.length ? student.courses : [{
                    code: student.course_code && student.course_code !== '—' ? student.course_code : '',
                    name: student.course_name && student.course_name !== '—' ? student.course_name : ''
                }]);

                if (studentIdInput) {
                    studentIdInput.readOnly = true;
                    studentIdInput.style.backgroundColor = 'var(--disabled-bg, #f0f0f0)';
                    studentIdInput.style.opacity = '0.7';
                }

                const modal = document.getElementById('studentModal');
                if (modal) modal.style.display = 'flex';
            } else {
                toast('❌ Failed to load student data');
            }
        } catch (error) {
            console.error('Error loading student:', error);
            toast('❌ Error loading student data');
        }
    }

    async function deleteStudentById(id) {
        if (!confirm('Delete this student? This action cannot be undone.')) return;
        try {
            const result = await apiRequest('delete_student', {
                student_id: id
            });
            if (result.success) {
                toast('🗑 Student deleted');
                await loadStudents();
            } else {
                toast('❌ ' + (result.error || 'Failed to delete student'));
            }
        } catch (error) {
            toast('❌ Network error. Please try again.');
        }
    }

    async function resetStudentPasswordById(id) {
        if (!confirm('Reset password to Student ID? The student will need to change it after login.')) return;
        try {
            const result = await apiRequest('reset_student_password', {
                student_id: id
            });
            if (result.success) {
                toast('✅ Password reset to Student ID');
            } else {
                toast('❌ ' + (result.error || 'Failed to reset password'));
            }
        } catch (error) {
            toast('❌ Network error. Please try again.');
        }
    }

    function viewStudentDetails(id) {
        const student = allStudents.find(s => s.id === id);
        if (!student) {
            toast('❌ Student not found');
            return;
        }

        const statusClass = student.status === 'Active' ? 'status-active' : 'status-inactive';
        const courseCodes = student.enrolled_courses && student.enrolled_courses !== '—' ? student.enrolled_courses : (student.course_code || '—');
        const courseNames = student.enrolled_courses_names && student.enrolled_courses_names !== '—' ? student.enrolled_courses_names : (student.course_name || '—');

        const content = `
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
            <div class="info-card"><div class="info-label"><i class="fas fa-id-card"></i> Student ID</div><div class="info-value">${escapeHTML(student.student_id || '—')}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-user"></i> Full Name</div><div class="info-value">${escapeHTML(student.full_name || '—')}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-graduation-cap"></i> Level</div><div class="info-value">${escapeHTML(student.level || '—')}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-building"></i> Programme</div><div class="info-value">${escapeHTML(student.programme || '—')}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-code"></i> Course Codes</div><div class="info-value">${escapeHTML(courseCodes)}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-book"></i> Course Names</div><div class="info-value">${escapeHTML(courseNames)}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-toggle-on"></i> Status</div><div class="info-value"><span class="tag ${statusClass}">${student.status || 'Active'}</span></div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-key"></i> Default Password</div><div class="info-value">${escapeHTML(student.student_id || '—')}</div></div>
        </div>`;

        const contentDiv = document.getElementById('viewStudentContent');
        if (contentDiv) contentDiv.innerHTML = content;

        const modal = document.getElementById('viewStudentModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeViewStudentModal() {
        const modal = document.getElementById('viewStudentModal');
        if (modal) modal.style.display = 'none';
    }

    async function enrollStudentInCourse(studentId, courseCode, courseName) {
        try {
            const result = await apiRequest('enroll_student_course', {
                student_id: studentId,
                course_code: courseCode,
                course_name: courseName
            });
            if (result.success) {
                toast('✅ Student enrolled in ' + courseCode);
                loadStudents();
                return true;
            } else {
                toast('❌ ' + (result.error || 'Enrollment failed'));
                return false;
            }
        } catch (error) {
            console.error('Error enrolling student:', error);
            toast('❌ Network error');
            return false;
        }
    }

    function showEnrollModal(studentId, studentName) {
        const courseCode = prompt(`Enter course code to enroll "${studentName}" in:`);
        if (courseCode) {
            const courseName = prompt(`Enter course name for ${courseCode}:`);
            if (courseName) {
                enrollStudentInCourse(studentId, courseCode, courseName);
            }
        }
    }

    async function viewStudentCourses(studentId) {
        try {
            const result = await apiRequest('get_student_courses', {
                student_id: studentId
            });
            if (result.success && result.data) {
                let coursesHtml =
                    '<div style="max-height: 300px; overflow-y: auto;"><table class="table" style="width:100%"><thead><tr><th>Course Code</th><th>Course Name</th><th>Enrolled By</th><th>Date</th></tr></thead><tbody>';
                result.data.forEach(course => {
                    coursesHtml +=
                        `<tr><td>${escapeHTML(course.course_code)}</td><td>${escapeHTML(course.course_name)}</td><td>${escapeHTML(course.lecturer_name || '—')}</td><td>${new Date(course.enrolled_at).toLocaleDateString()}</td></tr>`;
                });
                coursesHtml += '</tbody></table></div>';
                const contentDiv = document.getElementById('viewStudentContent');
                if (contentDiv) contentDiv.innerHTML = coursesHtml;

                const modal = document.getElementById('viewStudentModal');
                if (modal) modal.style.display = 'flex';
            }
        } catch (error) {
            console.error('Error loading student courses:', error);
            toast('❌ Failed to load courses');
        }
    }

    function exportStudentsToExcel() {
        const data = filteredStudents;
        if (!data || data.length === 0) {
            toast('❌ No students to export');
            return;
        }

        const exportData = data.map((s, index) => ({
            'S/N': index + 1,
            'Student ID': s.student_id || '—',
            'Full Name': s.full_name || '—',
            'Level': s.level || '—',
            'Programme': s.programme || '—',
            'Status': s.status || 'Active'
        }));

        const ws = XLSX.utils.json_to_sheet(exportData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Students');
        XLSX.writeFile(wb, `students_export_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.xlsx`);
        toast(`📊 Exported ${data.length} students to Excel`);
    }

    function exportStudentDetailsToExcel() {
        const data = filteredStudentDetails;
        if (!data || data.length === 0) {
            toast('❌ No students to export');
            return;
        }

        const exportData = data.map((s, index) => ({
            'S/N': index + 1,
            'Student ID': s.student_id || '—',
            'Full Name': s.full_name || '—',
            'Level': s.level || '—',
            'Programme': s.programme || '—',
            'Status': s.status || 'Active'
        }));

        const ws = XLSX.utils.json_to_sheet(exportData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'StudentDetails');
        XLSX.writeFile(wb, `student_details_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.xlsx`);
        toast(`📊 Exported ${data.length} student records to Excel`);
    }

    // ============================================
    // DASHBOARD WITH COURSE SELECTION & REAL-TIME UPDATES
    // ============================================

    let currentDashboardCourse = 'all';
    let dashboardChartInstances = {
        performanceLineChart: null,
        gradeBellCurve: null,
        correlationScatterChart: null,
        regressionChart: null
    };

    // Populate course selector dropdown
    function populateDashboardCourseSelector() {
        const select = document.getElementById('dashboardCourseSelect');
        if (!select) return;

        let courses = new Set();
        originalSubmissionsData.forEach(sub => {
            const examDetail = examDetailsCache[sub.exam_id];
            const courseCode = examDetail ? examDetail.course_code : (sub.course_code || '');
            const courseName = examDetail ? examDetail.title : (sub.exam_title || '');
            if (courseCode && courseName) {
                courses.add(JSON.stringify({
                    code: courseCode,
                    name: courseName
                }));
            }
        });

        let options = '<option value="all">All Courses (Overall)</option>';
        courses.forEach(courseStr => {
            const course = JSON.parse(courseStr);
            options +=
                `<option value="${escapeHTML(course.code)}">${escapeHTML(course.name)} (${escapeHTML(course.code)})</option>`;
        });

        select.innerHTML = options;

        // Also populate recent submissions course filter
        const recentSelect = document.getElementById('recentSubmissionsCourseFilter');
        if (recentSelect) {
            recentSelect.innerHTML = '<option value="all">All Courses</option>' +
                Array.from(courses).map(courseStr => {
                    const course = JSON.parse(courseStr);
                    return `<option value="${escapeHTML(course.code)}">${escapeHTML(course.name)} (${escapeHTML(course.code)})</option>`;
                }).join('');
        }
    }

    // Update dashboard with selected course
    async function updateDashboardWithCourse() {
        const select = document.getElementById('dashboardCourseSelect');
        currentDashboardCourse = select ? select.value : 'all';
        await loadDashboardStats();
        toast(
            `📊 Dashboard updated for ${currentDashboardCourse === 'all' ? 'All Courses' : currentDashboardCourse}`
        );
    }

    // Refresh all dashboard stats
    async function refreshAllDashboardStats() {
        showLoading('Refreshing dashboard data...');
        await loadDashboardStats();
        await loadSubmissions();
        await loadExamsList();
        await loadStudents();
        hideLoading();
        toast('✅ All dashboard data refreshed');
    }

    // Get submissions filtered by course
    function getSubmissionsByCourse(courseCode) {
        if (courseCode === 'all') {
            return originalSubmissionsData;
        }
        return originalSubmissionsData.filter(sub => {
            const examDetail = examDetailsCache[sub.exam_id];
            const subCourseCode = examDetail ? examDetail.course_code : (sub.course_code || '');
            return subCourseCode === courseCode;
        });
    }

    // Get filtered submissions for dashboard
    function getFilteredSubmissionsForDashboard() {
        return getSubmissionsByCourse(currentDashboardCourse);
    }

    // Load dashboard stats with course filtering
    async function loadDashboardStats() {
        try {
            const filteredSubmissions = getFilteredSubmissionsForDashboard();

            // Students stats (always overall since students aren't course-specific in this view)
            const studentsResult = await apiRequest('get_students');
            const students = studentsResult.success && studentsResult.data ? studentsResult.data : [];
            const totalStudents = students.length;
            const activeStudents = students.filter(s => s.status === 'Active').length;

            const totalStudentsEl = document.getElementById('statTotalStudents');
            const activeStudentsEl = document.getElementById('statActiveStudents');
            const studentProgressBar = document.getElementById('studentProgressBar');
            if (totalStudentsEl) totalStudentsEl.textContent = totalStudents;
            if (activeStudentsEl) activeStudentsEl.innerHTML =
                `Active: ${activeStudents} | Inactive: ${totalStudents - activeStudents}`;
            if (studentProgressBar) studentProgressBar.style.width = (activeStudents / Math.max(totalStudents, 1) *
                100) + '%';

            // Exams stats
            const examsResult = await apiRequest('get_exams');
            const exams = examsResult.success && examsResult.data ? examsResult.data : [];
            const totalExams = exams.length;
            const publishedExams = exams.filter(e => e.published === 1 || e.status === 'published').length;

            const totalExamsEl = document.getElementById('statTotalExams');
            const examStatusEl = document.getElementById('statExamStatus');
            const examProgressBar = document.getElementById('examProgressBar');
            if (totalExamsEl) totalExamsEl.textContent = totalExams;
            if (examStatusEl) examStatusEl.innerHTML = `Published: ${publishedExams} `;
            if (examProgressBar) examProgressBar.style.width = (publishedExams / Math.max(totalExams, 1) * 100) +
                '%';

            // Submissions stats (filtered by course)
            const totalSubmissions = filteredSubmissions.length;
            const markedSubmissions = filteredSubmissions.filter(s => s.status === 'GRADED' || s.status ===
                'MARKED').length;

            const totalSubmissionsEl = document.getElementById('statTotalSubmissions');
            const submissionStatusEl = document.getElementById('statSubmissionStatus');
            const submissionProgressBar = document.getElementById('submissionProgressBar');
            if (totalSubmissionsEl) totalSubmissionsEl.textContent = totalSubmissions;
            if (submissionStatusEl) submissionStatusEl.innerHTML =
                `Marked: ${markedSubmissions} | Pending: ${totalSubmissions - markedSubmissions}`;
            if (submissionProgressBar) submissionProgressBar.style.width = (markedSubmissions / Math.max(
                totalSubmissions, 1) * 100) + '%';

            // Calculate scores from filtered submissions
            const scores = [];
            filteredSubmissions.forEach(sub => {
                const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
                const classScore = studentClassScores[sub.student_identifier] || 0;
                const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
                scores.push(totalScore);
            });

            const avgScore = scores.length > 0 ? roundToWholeNumber(scores.reduce((a, b) => a + b, 0) / scores
                .length) : 0;
            const passCount = scores.filter(s => s >= 50).length;
            const passRate = scores.length > 0 ? roundToWholeNumber((passCount / scores.length) * 100) : 0;
            const highestScore = scores.length > 0 ? Math.max(...scores) : 0;
            const lowestScore = scores.length > 0 ? Math.min(...scores) : 0;

            // Calculate mean, median, standard deviation
            const mean = scores.length > 0 ? scores.reduce((a, b) => a + b, 0) / scores.length : 0;
            const sorted = [...scores].sort((a, b) => a - b);
            const median = sorted.length > 0 ? sorted[Math.floor(sorted.length / 2)] : 0;
            const variance = scores.length > 0 ? scores.reduce((a, b) => a + Math.pow(b - mean, 2), 0) / scores
                .length : 0;
            const stdDev = Math.sqrt(variance).toFixed(2);

            const avgScoreEl = document.getElementById('statAvgScore');
            const avgScoreBar = document.getElementById('avgScoreBar');
            const passRateEl = document.getElementById('statPassRate');
            const highestScoreEl = document.getElementById('statHighestScore');
            const lowestScoreEl = document.getElementById('statLowestScore');
            const passRateMetricEl = document.getElementById('statPassRateMetric');
            const meanScoreEl = document.getElementById('statMeanScore');
            const medianScoreEl = document.getElementById('statMedianScore');
            const stdDevEl = document.getElementById('statStdDev');

            if (avgScoreEl) avgScoreEl.textContent = avgScore + '%';
            if (avgScoreBar) avgScoreBar.style.width = avgScore + '%';
            if (passRateEl) passRateEl.textContent = passRate;
            if (highestScoreEl) highestScoreEl.textContent = highestScore + '%';
            if (lowestScoreEl) lowestScoreEl.textContent = lowestScore + '%';
            if (passRateMetricEl) passRateMetricEl.textContent = passRate + '%';
            if (meanScoreEl) meanScoreEl.textContent = Math.round(mean) + '%';
            if (medianScoreEl) medianScoreEl.textContent = median + '%';
            if (stdDevEl) stdDevEl.textContent = stdDev;

            // Grade distribution
            let grades = {
                A: 0,
                Bplus: 0,
                B: 0,
                Cplus: 0,
                C: 0,
                Dplus: 0,
                D: 0,
                E: 0
            };
            scores.forEach(score => {
                const gradeInfo = getGradeInfo(score);
                if (gradeInfo.grade === 'A') grades.A++;
                else if (gradeInfo.grade === 'B+') grades.Bplus++;
                else if (gradeInfo.grade === 'B') grades.B++;
                else if (gradeInfo.grade === 'C+') grades.Cplus++;
                else if (gradeInfo.grade === 'C') grades.C++;
                else if (gradeInfo.grade === 'D+') grades.Dplus++;
                else if (gradeInfo.grade === 'D') grades.D++;
                else grades.E++;
            });
            const totalGraded = scores.length;

            document.getElementById('gradeACount').textContent = grades.A;
            document.getElementById('gradeAPercent').textContent = totalGraded > 0 ? Math.round((grades.A /
                totalGraded) * 100) : 0;
            document.getElementById('gradeBplusCount').textContent = grades.Bplus;
            document.getElementById('gradeBplusPercent').textContent = totalGraded > 0 ? Math.round((grades.Bplus /
                totalGraded) * 100) : 0;
            document.getElementById('GradeBCount').textContent = grades.B;
            document.getElementById('gradeBPercent').textContent = totalGraded > 0 ? Math.round((grades.B /
                totalGraded) * 100) : 0;
            document.getElementById('gradeCplusCount').textContent = grades.Cplus;
            document.getElementById('gradeCplusPercent').textContent = totalGraded > 0 ? Math.round((grades.Cplus /
                totalGraded) * 100) : 0;
            document.getElementById('GradeCCount').textContent = grades.C;
            document.getElementById('gradeCPercent').textContent = totalGraded > 0 ? Math.round((grades.C /
                totalGraded) * 100) : 0;
            document.getElementById('gradeDplusCount').textContent = grades.Dplus;
            document.getElementById('gradeDplusPercent').textContent = totalGraded > 0 ? Math.round((grades.Dplus /
                totalGraded) * 100) : 0;
            document.getElementById('GradeDCount').textContent = grades.D;
            document.getElementById('gradeDPercent').textContent = totalGraded > 0 ? Math.round((grades.D /
                totalGraded) * 100) : 0;
            document.getElementById('GradeECount').textContent = grades.E;
            document.getElementById('gradeEPercent').textContent = totalGraded > 0 ? Math.round((grades.E /
                totalGraded) * 100) : 0;
            document.getElementById('totalGradedStudents').textContent = totalGraded;

            // Update charts
            updatePerformanceLineChartWithData(filteredSubmissions);
            updateGradeBellCurveWithData(scores);
            updateCorrelationScatterChartWithData(scores);
            updateRegressionChartWithData(scores);

            // Update recent submissions
            updateRecentSubmissionsWithFilters();

        } catch (error) {
            console.error('Error loading dashboard stats:', error);
        }
    }

    // Update Performance Line Chart
    function updatePerformanceLineChartWithData(submissions) {
        const ctx = document.getElementById('performanceLineChart')?.getContext('2d');
        if (!ctx) return;
        if (dashboardChartInstances.performanceLineChart) dashboardChartInstances.performanceLineChart.destroy();

        // Group by month
        const monthlyData = {};
        submissions.forEach(sub => {
            const date = new Date(sub.submitted_at || sub.submittedAt);
            const month = date.toLocaleString('default', {
                month: 'short',
                year: 'numeric'
            });
            if (!monthlyData[month]) {
                monthlyData[month] = {
                    total: 0,
                    count: 0
                };
            }
            const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
            monthlyData[month].total += totalScore;
            monthlyData[month].count++;
        });

        const months = Object.keys(monthlyData).sort((a, b) => new Date(a) - new Date(b));
        const avgScores = months.map(m => monthlyData[m].count > 0 ? roundToWholeNumber(monthlyData[m].total /
            monthlyData[m].count) : 0);

        dashboardChartInstances.performanceLineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Average Score (%)',
                    data: avgScores,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79,70,229,0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#4f46e5',
                    pointBorderColor: '#fff',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.raw}%`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Score (%)'
                        }
                    }
                }
            }
        });
    }

    // Update Grade Bell Curve
    function updateGradeBellCurveWithData(scores) {
        const ctx = document.getElementById('gradeBellCurve')?.getContext('2d');
        if (!ctx) return;
        if (dashboardChartInstances.gradeBellCurve) dashboardChartInstances.gradeBellCurve.destroy();

        const bins = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        scores.forEach(score => {
            const binIndex = Math.floor(score / 10);
            if (binIndex >= 0 && binIndex < 10) bins[binIndex]++;
        });

        dashboardChartInstances.gradeBellCurve = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['0-10', '11-20', '21-30', '31-40', '41-50', '51-60', '61-70', '71-80', '81-90',
                    '91-100'
                ],
                datasets: [{
                    label: 'Number of Students',
                    data: bins,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139,92,246,0.2)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#8b5cf6',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
    }

    // Update Correlation Scatter Chart
    function updateCorrelationScatterChartWithData(scores) {
        const ctx = document.getElementById('correlationScatterChart')?.getContext('2d');
        if (!ctx) return;
        if (dashboardChartInstances.correlationScatterChart) dashboardChartInstances.correlationScatterChart.destroy();

        const scatterData = scores.map((score, i) => ({
            x: Math.floor(Math.random() * 40) + 5,
            y: score
        }));

        dashboardChartInstances.correlationScatterChart = new Chart(ctx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Study Time vs Exam Score',
                    data: scatterData,
                    backgroundColor: '#3b82f6',
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `Study: ${ctx.raw.x}h, Score: ${ctx.raw.y}%`
                        }
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Study Time (hours)'
                        },
                        min: 0,
                        max: 50
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Exam Score (%)'
                        },
                        min: 0,
                        max: 100
                    }
                }
            }
        });
    }

    // Update Regression Chart
    function updateRegressionChartWithData(scores) {
        const ctx = document.getElementById('regressionChart')?.getContext('2d');
        if (!ctx) return;
        if (dashboardChartInstances.regressionChart) dashboardChartInstances.regressionChart.destroy();

        const xValues = scores.map((_, i) => i + 1);
        const meanX = xValues.reduce((a, b) => a + b, 0) / xValues.length;
        const meanY = scores.reduce((a, b) => a + b, 0) / scores.length;
        const slope = xValues.reduce((sum, xi, i) => sum + (xi - meanX) * (scores[i] - meanY), 0) / xValues.reduce((sum,
            xi) => sum + Math.pow(xi - meanX, 2), 0);
        const intercept = meanY - slope * meanX;
        const regressionLine = xValues.map(xi => slope * xi + intercept);

        dashboardChartInstances.regressionChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: xValues.map(i => `Student ${i}`),
                datasets: [{
                        label: 'Actual Scores',
                        data: scores,
                        borderColor: '#3b82f6',
                        backgroundColor: 'transparent',
                        pointRadius: 4,
                        showLine: false
                    },
                    {
                        label: 'Regression Line',
                        data: regressionLine,
                        borderColor: '#ef4444',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        pointRadius: 0,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        title: {
                            display: true,
                            text: 'Score (%)'
                        },
                        min: 0,
                        max: 100
                    }
                }
            }
        });
    }

    // Update recent submissions with filters
    function updateRecentSubmissionsWithFilters() {
        const courseFilter = document.getElementById('recentSubmissionsCourseFilter')?.value || 'all';
        const periodFilter = document.getElementById('recentSubmissionsPeriodFilter')?.value || 'all';

        let filtered = getSubmissionsByCourse(courseFilter);

        const now = new Date();
        filtered = filtered.filter(sub => {
            const subDate = new Date(sub.submitted_at || sub.submittedAt);
            if (periodFilter === 'day') return subDate.toDateString() === now.toDateString();
            if (periodFilter === 'week') {
                const weekAgo = new Date(now.setDate(now.getDate() - 7));
                return subDate >= weekAgo;
            }
            if (periodFilter === 'month') {
                const monthAgo = new Date(now.setMonth(now.getMonth() - 1));
                return subDate >= monthAgo;
            }
            if (periodFilter === 'year') {
                const yearAgo = new Date(now.setFullYear(now.getFullYear() - 1));
                return subDate >= yearAgo;
            }
            return true;
        });

        // Sort by date (newest first) and take top 10
        filtered.sort((a, b) => new Date(b.submitted_at || b.submittedAt) - new Date(a.submitted_at || a.submittedAt));
        const recent = filtered.slice(0, 10);

        const tbody = document.getElementById('recentSubmissionsTable');
        if (!tbody) return;

        if (!recent || recent.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="6" style="text-align:center; padding:40px;">No submissions found.</td></tr>';
            return;
        }

        tbody.innerHTML = recent.map(sub => {
            const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
            const gradeInfo = getGradeInfo(totalScore);
            const examDetail = examDetailsCache[sub.exam_id];
            const courseName = examDetail ? examDetail.title : (sub.exam_title || 'Unknown');
            const date = new Date(sub.submitted_at || sub.submittedAt);
            const formattedDate = date.toLocaleString();

            return `<tr>
            <td><b>${escapeHTML(sub.student_name || sub.student_identifier)}</b></td>
            <td>${escapeHTML(courseName)}</td>
            <td><strong>${totalScore}%</strong></td>
            <td><span class="tag ${gradeInfo.class}">${gradeInfo.grade}</span></td>
            <td>${gradeInfo.gradePoint.toFixed(1)}</td>
            <td><small>${formattedDate}</small></td>
        </tr>`;
        }).join('');
    }

    // Modal Functions
    function showCourseStatsModal() {
        const modal = document.getElementById('courseStatsModal');
        if (!modal) return;

        const courses = window.courseGroupsData || {};
        let html =
            '<table class="course-stats-table"><thead><tr><th>Course Code</th><th>Course Name</th><th>Level</th><th>Students</th><th>Avg Score</th><th>Pass Rate</th></tr></thead><tbody>';

        for (const [code, course] of Object.entries(courses)) {
            let totalScore = 0;
            let passed = 0;
            course.submissions.forEach(sub => {
                const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
                const classScore = studentClassScores[sub.student_identifier] || 0;
                const total = roundToWholeNumber(convertTo100Scale(examScore, classScore));
                totalScore += total;
                if (total >= 50) passed++;
            });
            const avg = course.submissions.length > 0 ? roundToWholeNumber(totalScore / course.submissions.length) : 0;
            const passRate = course.submissions.length > 0 ? roundToWholeNumber((passed / course.submissions.length) *
                100) : 0;

            html += `<tr>
            <td><code>${escapeHTML(code)}</code></td>
            <td>${escapeHTML(course.courseName)}</td>
            <td>Level ${escapeHTML(course.level)}</td>
            <td>${course.submissions.length}</td>
            <td class="course-stat-score">${avg}%</td>
            <td class="course-stat-pass">${passRate}%</td>
        </tr>`;
        }

        html += '</tbody></table>';
        document.getElementById('courseStatsContent').innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeCourseStatsModal() {
        document.getElementById('courseStatsModal').style.display = 'none';
    }

    function showSubmissionsStatsModal() {
        const modal = document.getElementById('submissionsStatsModal');
        if (!modal) return;

        const courses = window.courseGroupsData || {};
        let html =
            '<table class="course-stats-table"><thead><tr><th>Course Code</th><th>Course Name</th><th>Total Submissions</th><th>Graded</th><th>Pending</th><th>Completion %</th></tr></thead><tbody>';

        for (const [code, course] of Object.entries(courses)) {
            const total = course.submissions.length;
            const graded = course.submissions.filter(s => s.status === 'GRADED').length;
            const pending = total - graded;
            const percent = total > 0 ? roundToWholeNumber((graded / total) * 100) : 0;

            html += `<tr>
            <td><code>${escapeHTML(code)}</code></td>
            <td>${escapeHTML(course.courseName)}</td>
            <td>${total}</td>
            <td style="color: #10b981; font-weight: bold;">${graded}</td>
            <td style="color: #f59e0b;">${pending}</td>
            <td><div class="progress" style="height: 6px; width: 100px;"><div class="progress-bar" style="width: ${percent}%;"></div></div> ${percent}%</td>
        </tr>`;
        }

        html += '</tbody></table>';
        document.getElementById('submissionsStatsContent').innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeSubmissionsStatsModal() {
        document.getElementById('submissionsStatsModal').style.display = 'none';
    }

    function showAverageScoreModal() {
        const modal = document.getElementById('averageScoreModal');
        if (!modal) return;

        const courses = window.courseGroupsData || {};
        let html =
            '<table class="course-stats-table"><thead><tr><th>Course Code</th><th>Course Name</th><th>Average Score</th><th>Pass Rate</th><th>Highest Score</th><th>Lowest Score</th></tr></thead><tbody>';

        for (const [code, course] of Object.entries(courses)) {
            let scores = [];
            course.submissions.forEach(sub => {
                const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
                const classScore = studentClassScores[sub.student_identifier] || 0;
                const total = roundToWholeNumber(convertTo100Scale(examScore, classScore));
                scores.push(total);
            });
            const avg = scores.length > 0 ? roundToWholeNumber(scores.reduce((a, b) => a + b, 0) / scores.length) : 0;
            const passed = scores.filter(s => s >= 50).length;
            const passRate = scores.length > 0 ? roundToWholeNumber((passed / scores.length) * 100) : 0;
            const highest = scores.length > 0 ? Math.max(...scores) : 0;
            const lowest = scores.length > 0 ? Math.min(...scores) : 0;

            html += `<tr>
            <td><code>${escapeHTML(code)}</code></td>
            <td>${escapeHTML(course.courseName)}</td>
            <td class="course-stat-score">${avg}%</td>
            <td class="course-stat-pass">${passRate}%</td>
            <td style="color: #10b981;">${highest}%</td>
            <td style="color: #ef4444;">${lowest}%</td>
        </tr>`;
        }

        html += '</tbody></table>';
        document.getElementById('averageScoreContent').innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeAverageScoreModal() {
        document.getElementById('averageScoreModal').style.display = 'none';
    }

    // Auto-refresh dashboard every 30 seconds
    let dashboardRefreshInterval = null;

    function startDashboardAutoRefresh() {
        if (dashboardRefreshInterval) clearInterval(dashboardRefreshInterval);
        dashboardRefreshInterval = setInterval(() => {
            if (document.getElementById('view-dashboard').classList.contains('active')) {
                loadDashboardStats();
                console.log("Auto-refreshed dashboard at:", new Date().toLocaleTimeString());
            }
        }, 30000);
    }

    // Call this in DOMContentLoaded
    function initDashboard() {
        startDashboardAutoRefresh();
    }

    // ============================================
    // 8. SUBMISSIONS FUNCTIONS
    // ============================================

    function getSubs() {
        return readJSON(K_SUBS, []);
    }

    function setSubs(subs) {
        writeJSON(K_SUBS, subs);
    }

    async function loadSubmissions() {
        const data = await apiRequest('get_submissions');
        if (data.success && data.data) {
            renderSubmissionsFromDB(data.data);
        } else {
            renderSubmissions();
        }
    }


    // ============================================
    // COMPLETE GRADING SYSTEM - Exam 60% + Class 40%
    // ============================================

    let originalSubmissionsData = [];
    let studentClassScores = {};
    let examDetailsCache = {};

    function normalizeSubmissionStatus(status) {
        return String(status || '').toUpperCase();
    }

    function isGradedSubmission(status) {
        return ['GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED'].includes(normalizeSubmissionStatus(status));
    }

    function getSubmissionGrading(submission) {
        try {
            const answers = typeof submission.answers === 'string' ? JSON.parse(submission.answers || '{}') : (submission.answers || {});
            return answers.grading || answers._grading || {};
        } catch (error) {
            return {};
        }
    }

    async function persistClassScore(submission, classScore) {
        const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(submission.percentage) || 0));
        const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
        const gradeInfo = getGradeInfo(totalScore);

        submission.status = 'GRADED';
        studentClassScores[submission.student_identifier] = classScore;

        await apiRequest('update_submission_grade', {
            submission_id: submission.id,
            status: 'GRADED',
            total_score: totalScore,
            class_score: classScore,
            exam_score: examScore,
            grade: gradeInfo.grade,
            grade_point: gradeInfo.gradePoint
        });

        return { examScore, totalScore, gradeInfo };
    }

    // Load submissions
    async function loadSubmissions() {
        console.log("Loading submissions...");

        const container = document.getElementById('submissionsContainer');
        if (!container) {
            console.error("Submissions container not found!");
            return;
        }

        container.innerHTML = `
        <div style="text-align:center; padding:60px;">
            <div class="spinner"></div>
            <p style="margin-top:10px;">Loading courses and submissions...</p>
        </div>
    `;

        try {
            const result = await apiRequest('get_submissions');
            console.log("Submissions API response:", result);

            if (result.success && result.data) {
                originalSubmissionsData = result.data;

                // Load exam details for each unique exam
                const uniqueExamIds = [...new Set(originalSubmissionsData.map(s => s.exam_id))];
                for (const examId of uniqueExamIds) {
                    if (!examDetailsCache[examId]) {
                        const examResult = await apiRequest('get_exam_details', {
                            exam_id: examId
                        });
                        if (examResult.success) {
                            examDetailsCache[examId] = examResult.data;
                        }
                    }
                }

                loadSavedClassScores();
                populateStudentSelects();

                if (result.data.length === 0) {
                    showEmptySubmissionsState();
                } else {
                    // Group the data first
                    const groupedByCourse = {};
                    originalSubmissionsData.forEach(sub => {
                        const examDetail = examDetailsCache[sub.exam_id];
                        const courseCode = examDetail ? examDetail.course_code : (sub.course_code ||
                            'Unknown');
                        const courseName = examDetail ? examDetail.title : (sub.exam_title ||
                            'Unknown Course');
                        const courseKey = `${courseCode}::${courseName}`;
                        const level = examDetail ? examDetail.level : 'N/A';
                        const semester = examDetail ? examDetail.semester : 'N/A';

                        if (!groupedByCourse[courseKey]) {
                            groupedByCourse[courseKey] = {
                                courseKey: courseKey,
                                courseCode: courseCode,
                                courseName: courseName,
                                level: level,
                                semester: semester,
                                submissions: [],
                                examDetail: examDetail
                            };
                        }
                        groupedByCourse[courseKey].submissions.push(sub);
                    });
                    window.courseGroupsData = groupedByCourse;

                    if (currentExpandedCourse && window.courseGroupsData[currentExpandedCourse]) {
                        showCourseDetails(currentExpandedCourse);
                    } else {
                        // Show course cards (FIRST VIEW)
                        renderCourseCardsView(originalSubmissionsData);
                    }
                }
            } else {
                console.error("API Error:", result.error);
                showErrorSubmissionsState(result.error);
            }
        } catch (error) {
            console.error("Network Error:", error);
            showErrorSubmissionsState(error.message);
        }
    }

    function loadSavedClassScores() {
        studentClassScores = {};
        originalSubmissionsData.forEach(sub => {
            const grading = getSubmissionGrading(sub);
            if (sub.student_identifier && grading.class_score !== undefined) {
                studentClassScores[sub.student_identifier] = roundToWholeNumber(grading.class_score);
            }
        });

        const saved = localStorage.getItem('studentClassScores');
        if (saved) {
            const fallbackScores = JSON.parse(saved);
            Object.keys(fallbackScores).forEach(studentId => {
                if (studentClassScores[studentId] === undefined) {
                    studentClassScores[studentId] = fallbackScores[studentId];
                }
            });
        }
    }

    function saveClassScores() {
        localStorage.setItem('studentClassScores', JSON.stringify(studentClassScores));
    }

    function loadGradedStatus() {
        const saved = localStorage.getItem('studentGradedStatus');
        if (saved) {
            const gradedStatus = JSON.parse(saved);
            originalSubmissionsData.forEach(sub => {
                if (gradedStatus[sub.id]) {
                    sub.status = 'GRADED';
                }
            });
        }
    }

    function saveGradedStatus() {
        const gradedStatus = {};
        originalSubmissionsData.forEach(sub => {
            if (sub.status === 'GRADED') {
                gradedStatus[sub.id] = true;
            }
        });
        localStorage.setItem('studentGradedStatus', JSON.stringify(gradedStatus));
    }

    function populateStudentSelects() {
        const select1 = document.getElementById('classScoreStudentSelect');
        const select2 = document.getElementById('calcStudentSelect');

        if (!select1 && !select2) return;

        const students = {};
        originalSubmissionsData.forEach(sub => {
            const studentId = sub.student_identifier;
            const studentName = sub.student_name;
            if (studentId && !students[studentId]) {
                const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
                students[studentId] = {
                    id: studentId,
                    name: studentName,
                    submissionId: sub.id,
                    examScore: examScore,
                    examId: sub.exam_id
                };
            }
        });

        const optionsHtml = '<option value="">-- Select Student --</option>' +
            Object.values(students).map(student => {
                const currentScore = studentClassScores[student.id] || 0;
                return `<option value="${student.id}" data-exam-score="${student.examScore}" data-exam-id="${student.examId}">
                ${escapeHTML(student.name)} (${student.id}) - Exam: ${student.examScore}/60 | Class: ${currentScore}/40
            </option>`;
            }).join('');

        if (select1) select1.innerHTML = optionsHtml;
        if (select2) select2.innerHTML = optionsHtml;
    }

    function convertTo60Scale(percentage) {
        return roundToWholeNumber(Math.min(60, (percentage * 60) / 100));
    }

    function convertTo100Scale(exam60, class40) {
        return roundToWholeNumber(Math.min(100, (exam60 || 0) + (class40 || 0)));
    }

    function getGradeInfo(totalScore) {
        // Ensure totalScore is an integer
        totalScore = roundToWholeNumber(totalScore);

        if (totalScore >= 80) return {
            grade: 'A',
            gradePoint: 4.0,
            class: 'grade-a'
        };
        if (totalScore >= 75) return {
            grade: 'B+',
            gradePoint: 3.5,
            class: 'grade-bplus'
        };
        if (totalScore >= 70) return {
            grade: 'B',
            gradePoint: 3.0,
            class: 'grade-b'
        };
        if (totalScore >= 65) return {
            grade: 'C+',
            gradePoint: 2.5,
            class: 'grade-cplus'
        };
        if (totalScore >= 60) return {
            grade: 'C',
            gradePoint: 2.0,
            class: 'grade-c'
        };
        if (totalScore >= 55) return {
            grade: 'D+',
            gradePoint: 1.5,
            class: 'grade-dplus'
        };
        if (totalScore >= 50) return {
            grade: 'D',
            gradePoint: 1.0,
            class: 'grade-d'
        };
        return {
            grade: 'E',
            gradePoint: 0.0,
            class: 'grade-e'
        };
    }

    async function updateClassScoreForStudent() {
        const select = document.getElementById('classScoreStudentSelect');
        const scoreInput = document.getElementById('classScoreInput');

        const studentId = select.value;
        let classScore = roundToWholeNumber(scoreInput.value);

        if (!studentId) {
            toast('❌ Please select a student');
            return;
        }

        if (classScore < 0) classScore = 0;
        if (classScore > 40) {
            toast('❌ Class score cannot exceed 40');
            return;
        }

        const submission = originalSubmissionsData.find(s => s.student_identifier === studentId);
        if (!submission) {
            toast('❌ Submission not found');
            return;
        }

        studentClassScores[studentId] = classScore;
        saveClassScores();

        const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(submission.percentage) || 0));
        const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
        const gradeInfo = getGradeInfo(totalScore);

        if (true) {
            submission.status = 'GRADED';
            saveGradedStatus();

            await apiRequest('update_submission_grade', {
                submission_id: submission.id,
                status: 'GRADED',
                total_score: totalScore,
                class_score: classScore,
                exam_score: examScore,
                grade: gradeInfo.grade,
                grade_point: gradeInfo.gradePoint
            });
        }

        renderGroupedSubmissions(originalSubmissionsData);
        displayMarksSummary();
        populateStudentSelects();

        toast(`✅ Class score ${classScore}/40 applied. Total: ${totalScore}/100 - Grade: ${gradeInfo.grade}`);

        scoreInput.value = 0;
        select.value = "";
    }

    function updateCalculatorDisplay() {
        const select = document.getElementById('calcStudentSelect');
        const selectedOption = select.options[select.selectedIndex];
        const examScoreField = document.getElementById('calcExamScoreField');
        const classScoreField = document.getElementById('calcClassScoreField');

        let examScore = roundToWholeNumber(examScoreField.value);
        let classScore = roundToWholeNumber(classScoreField.value);

        if (select.value && selectedOption && selectedOption.dataset.examScore) {
            const preExamScore = roundToWholeNumber(selectedOption.dataset.examScore);
            if (examScoreField.value === '0') {
                examScore = preExamScore;
                examScoreField.value = preExamScore;
            }
        }

        if (examScore < 0) examScore = 0;
        if (examScore > 60) examScore = 60;
        if (classScore < 0) classScore = 0;
        if (classScore > 40) classScore = 40;

        const totalScore = examScore + classScore;
        const gradeInfo = getGradeInfo(totalScore);

        document.getElementById('calcTotalScore').textContent = totalScore;
        document.getElementById('calcGradeDisplay').textContent = gradeInfo.grade;
        document.getElementById('calcGradePointDisplay').textContent =
            `Grade Point: ${gradeInfo.gradePoint.toFixed(1)}`;

        const gradeSpan = document.getElementById('calcGradeDisplay');
        if (gradeSpan) {
            if (totalScore >= 80) gradeSpan.style.color = '#10b981';
            else if (totalScore >= 70) gradeSpan.style.color = '#3b82f6';
            else if (totalScore >= 60) gradeSpan.style.color = '#f59e0b';
            else if (totalScore >= 50) gradeSpan.style.color = '#f97316';
            else gradeSpan.style.color = '#ef4444';
        }
    }

    async function markAllAsGraded() {
        if (!confirm('Mark all submissions as graded?')) return;

        let count = 0;
        for (const submission of originalSubmissionsData) {
            if (submission.status !== 'GRADED') {
                submission.status = 'GRADED';

                const examScore = convertTo60Scale(parseFloat(submission.percentage) || 0);
                const classScore = studentClassScores[submission.student_identifier] || 0;
                const totalScore = convertTo100Scale(examScore, classScore);
                const gradeInfo = getGradeInfo(totalScore);

                await apiRequest('update_submission_grade', {
                    submission_id: submission.id,
                    status: 'GRADED',
                    total_score: totalScore,
                    class_score: classScore,
                    exam_score: examScore,
                    grade: gradeInfo.grade,
                    grade_point: gradeInfo.gradePoint
                });
                count++;
            }
        }

        saveGradedStatus();
        renderGroupedSubmissions(originalSubmissionsData);
        displayMarksSummary();
        toast(`✅ ${count} submissions marked as graded`);
    }

    // Render submissions grouped by course with exam details
    function renderGroupedSubmissions(submissions) {
        const container = document.getElementById('submissionsContainer');
        if (!container) return;

        const groupedByCourse = {};
        submissions.forEach(sub => {
            const examId = sub.exam_id;
            const examDetail = examDetailsCache[examId];
            const courseKey = examDetail ? `${examDetail.course_code} - ${examDetail.title}` : (sub
                .course_code || sub.exam_title || 'Unknown Course');

            if (!groupedByCourse[courseKey]) {
                groupedByCourse[courseKey] = {
                    submissions: [],
                    examDetail: examDetail
                };
            }
            groupedByCourse[courseKey].submissions.push(sub);
        });

        let html = '';

        for (const [courseDisplay, groupData] of Object.entries(groupedByCourse)) {
            const courseSubmissions = groupData.submissions;
            const examDetail = groupData.examDetail;

            // Get exam details
            const schoolName = examDetail?.school_name || 'SCHOOL NAME';
            const programme = examDetail?.programme || examDetail?.department || 'PROGRAMME';
            const schoolType = examDetail?.school_type || 'REGULAR';
            const level = examDetail?.level || 'N/A';
            const semester = examDetail?.semester || 'SEMESTER';
            const academicYear = examDetail?.academic_year ||
                `${new Date().getFullYear()}/${new Date().getFullYear() + 1}`;
            const intake = examDetail?.intake || '1';
            const courseName = examDetail?.title || courseDisplay;
            const courseCode = examDetail?.course_code || 'N/A';

            let courseTotal = 0;
            let passedCount = 0;

            courseSubmissions.forEach(sub => {
                const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
                const classScore = studentClassScores[sub.student_identifier] || 0;
                const totalScore = convertTo100Scale(examScore, classScore);
                courseTotal += totalScore;
                if (totalScore >= 50) passedCount++;
            });

            const courseAverage = courseSubmissions.length > 0 ? (courseTotal / courseSubmissions.length).toFixed(1) :
                0;
            const passRate = courseSubmissions.length > 0 ? ((passedCount / courseSubmissions.length) * 100).toFixed(
                1) : 0;

            html += `
        <div class="course-group" style="margin-bottom: 30px; border: 1px solid var(--border); border-radius: 16px; overflow: hidden;">
            <!-- Course Header with Exam Details -->
            <div style="background: linear-gradient(135deg, #1e3a5f, #3b82f6); color: white; padding: 20px;">
                <div style="text-align: center; margin-bottom: 15px;">
                    <h1 style="margin: 0; font-size: 24px;">${escapeHTML(schoolName)}</h1>
                    <h2 style="margin: 10px 0; font-size: 20px;">SCORE SHEET</h2>
                    <p style="margin: 5px 0; font-size: 14px;">${escapeHTML(programme)} - ${escapeHTML(schoolType)}</p>
                    <p style="margin: 5px 0; font-size: 14px;">
                        Level: ${escapeHTML(level)} | Semester: ${escapeHTML(semester)} | Academic Year: ${escapeHTML(academicYear)} | Intake: ${escapeHTML(intake)}
                    </p>
                    <p style="margin: 5px 0; font-size: 14px; font-weight: bold;">
                        Course: ${escapeHTML(courseName)} (${escapeHTML(courseCode)})
                    </p>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 15px;">
                    <div>
                        <div style="font-size: 13px; opacity: 0.9;">
                            📊 ${courseSubmissions.length} student(s) | 
                            📈  Average: ${Math.round(courseAverage)}% |
                            ✅ Pass Rate: ${passRate}%
                        </div>
                    </div>
                    <button class="btn" onclick="downloadCourseSubmissionsByExam('${escapeHTML(courseCode).replace(/'/g, "\\'")}', '${escapeHTML(courseName).replace(/'/g, "\\'")}')" 
                        style="background: white; color: #3b82f6; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer;">
                        <i class="fas fa-download"></i> Download Course ZIP
                    </button>
                </div>
            </div>
            
            <!-- Submissions Table -->
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; border: 1px solid #000;">
                    <thead>
                        <tr style="background: #f0f0f0;">
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">S/N</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Student ID</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Student Name</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Class Score (40%)</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Exam Score (60%)</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Total Score (100%)</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Grade</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">GP</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Status</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

            let sn = 1;
            for (const sub of courseSubmissions) {
                const studentName = sub.student_name || 'Unknown Student';
                const studentId = sub.student_identifier || 'N/A';
                const status = sub.status || 'SUBMITTED';

                let examScoreRaw = parseFloat(sub.percentage) || 0;
                let examScore = convertTo60Scale(examScoreRaw);
                const classScore = studentClassScores[studentId] || 0;
                const totalScore = convertTo100Scale(examScore, classScore);
                const gradeInfo = getGradeInfo(totalScore);

                let statusClass = 'status-pending';
                let statusText = status;
                if (isGradedSubmission(status)) {
                    statusClass = 'status-published';
                    statusText = '✓ Graded';
                } else if (normalizeSubmissionStatus(status) === 'SUBMITTED') {
                    statusClass = 'status-pending';
                    statusText = '📝 Submitted';
                }

                const scoreColor = totalScore >= 70 ? '#10b981' : (totalScore >= 50 ? '#f59e0b' : '#ef4444');

                html += `
                <tr>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">${sn++}</td>
                    <td style="border: 1px solid #000; padding: 12px;">
                        <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px;">${escapeHTML(studentId)}</code>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px;">
                        <strong>${escapeHTML(studentName)}</strong>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                            <strong style="color: #f59e0b; font-size: 18px;">${classScore}</strong>
                            <button class="btn small" onclick="editClassScore('${studentId}', '${escapeHTML(studentName)}')" 
                                style="padding: 2px 8px; font-size: 11px;">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </div>
                    </td>
<td style="border: 1px solid #000; padding: 12px; text-align: center;">
    <strong style="font-size: 18px;">${examScore}</strong>
</td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <div style="font-weight: 700; font-size: 22px; color: ${scoreColor};">${totalScore}</div>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <span class="tag ${gradeInfo.class}" style="font-size: 18px; font-weight: 700; padding: 6px 12px;">${gradeInfo.grade}</span>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <strong>${gradeInfo.gradePoint.toFixed(1)}</strong>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <span class="tag ${statusClass}">${statusText}</span>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center; white-space: nowrap;">
                        <button class="btn primary small" onclick="openQuestionReview(${sub.id})" 
                            style="padding: 6px 12px; margin: 2px;">
                            <i class="fas fa-eye"></i> Review & Mark
                        </button>
                        
                        
                        
                        <button class="btn success small" onclick="downloadSubmission(${sub.id})" 
                            style="padding: 6px 12px; margin: 2px;">
                            <i class="fas fa-download"></i> Download ZIP
                        </button>
                    </td>
                </tr>
            `;
            }

            html += `
                    </tbody>
                    <tfoot style="background: #f9f9f9;">
                        <tr>
                            <td colspan="9" style="border: 1px solid #000; padding: 8px; text-align: right;"><strong>Total Students:</strong></td>
                            <td style="border: 1px solid #000; padding: 8px; text-align: center;"><strong>${courseSubmissions.length}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        `;
        }

        container.innerHTML = html;
    }

    // Replace the existing openQuestionReview function with this:
    function openQuestionReview(submissionId) {
        // Open the IDE Compiler in a new window
        const url = `IDEcompiler.php?submission_id=${submissionId}`;
        window.open(url, '_blank', 'width=1400,height=900,resizable=yes,scrollbars=yes,toolbar=no,menubar=no');
    }
    // Update saveAllScores to update the exam score in the table
    async function saveAllScores() {
        showLoading('Saving all scores...');
        let saved = 0;
        let totalScore = 0;
        let totalMarks = 0;

        for (let i = 0; i < currentQuestions.length; i++) {
            const score = currentScores[i];
            const feedback = document.getElementById(`feedbackInput_${i}`)?.value || '';
            const maxMarks = currentQuestions[i].marks;

            totalScore += score;
            totalMarks += maxMarks;

            try {
                await apiRequest('save_question_score_simple', {
                    submission_id: currentReviewSubmission.submission_id,
                    question_number: i + 1,
                    score: score,
                    feedback: feedback
                });
                saved++;
            } catch (error) {
                console.error(`Error saving question ${i + 1}:`, error);
            }
        }

        // Calculate percentage (exam score as percentage)
        const examPercentage = totalMarks > 0 ? (totalScore / totalMarks) * 100 : 0;

        // Update the submission in the database with the exam score
        await apiRequest('update_submission_scores', {
            submission_id: currentReviewSubmission.submission_id,
            total_score: totalScore,
            percentage: examPercentage
        });

        // Reload submissions to update the table
        await loadSubmissions();

        toast(
            `✅ Saved ${saved} of ${currentQuestions.length} questions. Exam score: ${examPercentage.toFixed(1)}%`
        );
        hideLoading();
        closeQuestionReviewModal();
    }

    // Round to nearest whole number
    function roundToWholeNumber(value) {
        return Math.round(parseFloat(value) || 0);
    }

    // Update the updateScore function
    function updateScore(questionIndex, value) {
        const score = roundToWholeNumber(value);
        currentScores[questionIndex] = score;
        updateTotalScoreDisplay();

        // Update the input field to show rounded value
        const inputField = document.getElementById(`scoreInput_${questionIndex}`);
        if (inputField) {
            inputField.value = score;
        }

        // Update the sidebar display
        const sidebarItem = document.querySelectorAll('.question-list-item')[questionIndex];
        if (sidebarItem) {
            const scoreSpan = sidebarItem.querySelector('span:first-child');
            if (scoreSpan) {
                scoreSpan.textContent = score;
            }
        }
    }

    // Update updateClassScoreForStudent function
    async function updateClassScoreForStudent() {
        const select = document.getElementById('classScoreStudentSelect');
        const scoreInput = document.getElementById('classScoreInput');

        const studentId = select.value;
        let classScore = roundToWholeNumber(scoreInput.value);

        if (!studentId) {
            toast('❌ Please select a student');
            return;
        }

        if (classScore < 0) classScore = 0;
        if (classScore > 40) {
            toast('❌ Class score cannot exceed 40');
            return;
        }

        const submission = originalSubmissionsData.find(s => s.student_identifier === studentId);
        if (!submission) {
            toast('❌ Submission not found');
            return;
        }

        studentClassScores[studentId] = classScore;
        saveClassScores();

        const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(submission.percentage) || 0));
        const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
        const gradeInfo = getGradeInfo(totalScore);

        if (true) {
            submission.status = 'GRADED';
            saveGradedStatus();

            await apiRequest('update_submission_grade', {
                submission_id: submission.id,
                status: 'GRADED',
                total_score: totalScore,
                class_score: classScore,
                exam_score: examScore,
                grade: gradeInfo.grade,
                grade_point: gradeInfo.gradePoint
            });
        }

        renderGroupedSubmissions(originalSubmissionsData);
        displayMarksSummary();
        populateStudentSelects();

        toast(`✅ Class score ${classScore}/40 applied. Total: ${totalScore}/100 - Grade: ${gradeInfo.grade}`);

        scoreInput.value = 0;
        select.value = "";
    }









    async function editClassScore(studentId, studentName) {
        const currentScore = studentClassScores[studentId] || 0;
        const newScore = prompt(`Enter class score for ${studentName} (0-40):`, currentScore);

        if (newScore !== null) {
            let scoreNum = parseFloat(newScore);
            if (isNaN(scoreNum)) {
                toast('❌ Please enter a valid number');
                return;
            }
            if (scoreNum < 0) scoreNum = 0;
            if (scoreNum > 40) {
                toast('❌ Class score cannot exceed 40');
                return;
            }

            const submission = originalSubmissionsData.find(s => s.student_identifier === studentId);
            if (submission) {
                await persistClassScore(submission, scoreNum);
                saveClassScores();
            }

            renderGroupedSubmissions(originalSubmissionsData);
            displayMarksSummary();
            populateStudentSelects();
            toast(`✅ Class score updated to ${scoreNum}/40 for ${studentName}`);
        }
    }

    function filterSubmissions() {
        const studentSearch = document.getElementById('filterStudentSearch')?.value.toLowerCase() || '';
        const courseSearch = document.getElementById('filterCourseSearch')?.value.toLowerCase() || '';
        const statusFilter = document.getElementById('filterStatus')?.value || 'all';

        let filtered = [...originalSubmissionsData];

        if (studentSearch) {
            filtered = filtered.filter(sub =>
                (sub.student_name && sub.student_name.toLowerCase().includes(studentSearch)) ||
                (sub.student_identifier && sub.student_identifier.toLowerCase().includes(studentSearch))
            );
        }

        if (courseSearch) {
            filtered = filtered.filter(sub => {
                const examDetail = examDetailsCache[sub.exam_id];
                const courseCode = examDetail?.course_code || sub.course_code || '';
                const courseTitle = examDetail?.title || sub.exam_title || '';
                return courseCode.toLowerCase().includes(courseSearch) || courseTitle.toLowerCase().includes(
                    courseSearch);
            });
        }

        if (statusFilter !== 'all') {
            filtered = filtered.filter(sub => {
                const subStatus = sub.status || 'SUBMITTED';
                return subStatus === statusFilter;
            });
        }

        if (filtered.length === 0) {
            const container = document.getElementById('submissionsContainer');
            if (container) {
                container.innerHTML = `
                <div style="text-align: center; padding: 60px;">
                    <i class="fas fa-search" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                    <h3>No matching submissions</h3>
                    <p>Try adjusting your search filters</p>
                    <button class="btn" onclick="resetSubmissionsFilters()">Reset Filters</button>
                </div>
            `;
            }
        } else {
            renderGroupedSubmissions(filtered);
            displayMarksSummary();
        }
    }

    function resetSubmissionsFilters() {
        const studentSearch = document.getElementById('filterStudentSearch');
        const courseSearch = document.getElementById('filterCourseSearch');
        const statusFilter = document.getElementById('filterStatus');

        if (studentSearch) studentSearch.value = '';
        if (courseSearch) courseSearch.value = '';
        if (statusFilter) statusFilter.value = 'all';

        renderGroupedSubmissions(originalSubmissionsData);
        displayMarksSummary();
        populateStudentSelects();
        toast('Filters reset');
    }

    function refreshSubmissions() {
        loadSubmissions();
        toast('🔄 Refreshing submissions...');
    }

    function displayMarksSummary() {
        if (!originalSubmissionsData || originalSubmissionsData.length === 0) return;

        let totalScoreSum = 0;
        let passed = 0;
        let grades = {
            A: 0,
            Bplus: 0,
            B: 0,
            Cplus: 0,
            C: 0,
            Dplus: 0,
            D: 0,
            E: 0
        };

        originalSubmissionsData.forEach(sub => {
            const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = convertTo100Scale(examScore, classScore);
            totalScoreSum += totalScore;

            if (totalScore >= 50) passed++;

            const gradeInfo = getGradeInfo(totalScore);
            if (gradeInfo.grade === 'A') grades.A++;
            else if (gradeInfo.grade === 'B+') grades.Bplus++;
            else if (gradeInfo.grade === 'B') grades.B++;
            else if (gradeInfo.grade === 'C+') grades.Cplus++;
            else if (gradeInfo.grade === 'C') grades.C++;
            else if (gradeInfo.grade === 'D+') grades.Dplus++;
            else if (gradeInfo.grade === 'D') grades.D++;
            else grades.E++;
        });

        const studentCount = originalSubmissionsData.length;
        const average = studentCount > 0 ? Math.round(totalScoreSum / studentCount) : 0;
        const passRate = studentCount > 0 ? Math.round((passed / studentCount) * 100) : 0;
        let summaryDiv = document.querySelector('.marks-summary');
        if (!summaryDiv) {
            const container = document.getElementById('submissionsContainer');
            if (container && container.parentNode) {
                summaryDiv = document.createElement('div');
                summaryDiv.className = 'marks-summary';
                container.parentNode.insertBefore(summaryDiv, container);
            }
        }

        if (summaryDiv) {
            summaryDiv.innerHTML = `
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px;">
                <div class="stat-card blue" style="padding: 20px; border-radius: 16px;">
                    <div class="stat-label">Total Students</div>
                    <div class="stat-value" style="font-size: 32px;">${studentCount}</div>
                </div>
                <div class="stat-card green" style="padding: 20px; border-radius: 16px;">
                    <div class="stat-label">Passed (≥50)</div>
                    <div class="stat-value" style="font-size: 32px; color: #10b981;">${passed}</div>
                    <small>${passRate}% pass rate</small>
                </div>
                <div class="stat-card red" style="padding: 20px; border-radius: 16px;">
                    <div class="stat-label">Failed (<50)</div>
                    <div class="stat-value" style="font-size: 32px; color: #ef4444;">${studentCount - passed}</div>
                </div>
                <div class="stat-card orange" style="padding: 20px; border-radius: 16px;">
                    <div class="stat-label">Average Score</div>
                    <div class="stat-value" style="font-size: 32px;">${average}</div>
                </div>
            </div>
            
            <div style="background: var(--panel); border-radius: 16px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--border);">
                <h4 style="margin: 0 0 15px 0;"><i class="fas fa-chart-pie"></i> Grade Distribution</h4>
                <div style="display: flex; gap: 15px; flex-wrap: wrap; justify-content: space-between;">
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-a" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">A</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.A}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-bplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">B+</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Bplus}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-b" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">B</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.B}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-cplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">C+</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Cplus}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-c" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">C</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.C}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-dplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">D+</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Dplus}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-d" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">D</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.D}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-e" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">E</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.E}</div>
                    </div>
                </div>
            </div>
        `;
        }
    }

    // Calculator functions
    function openCalculatorModal() {
        populateStudentSelects();
        const modal = document.getElementById('calculatorModal');
        if (modal) {
            modal.style.display = 'flex';
            updateCalculatorDisplay();
        }
    }

    function closeCalculatorModal() {
        const modal = document.getElementById('calculatorModal');
        if (modal) modal.style.display = 'none';
    }

    function updateCalculatorDisplay() {
        const select = document.getElementById('calcStudentSelect');
        const selectedOption = select.options[select.selectedIndex];
        const examScoreField = document.getElementById('calcExamScoreField');
        const classScoreField = document.getElementById('calcClassScoreField');

        let examScore = roundToWholeNumber(examScoreField.value);
        let classScore = roundToWholeNumber(classScoreField.value);

        if (select.value && selectedOption && selectedOption.dataset.examScore) {
            const preExamScore = roundToWholeNumber(selectedOption.dataset.examScore);
            if (examScoreField.value === '0' || examScoreField.value === '') {
                examScore = preExamScore;
                examScoreField.value = preExamScore;
            }
        }

        if (examScore < 0) examScore = 0;
        if (examScore > 60) examScore = 60;
        if (classScore < 0) classScore = 0;
        if (classScore > 40) classScore = 40;

        const totalScore = examScore + classScore;
        const gradeInfo = getGradeInfo(totalScore);

        document.getElementById('calcTotalScore').textContent = totalScore; // Removed .toFixed(1)
        document.getElementById('calcGradeDisplay').textContent = gradeInfo.grade;
        document.getElementById('calcGradePointDisplay').textContent =
            `Grade Point: ${gradeInfo.gradePoint.toFixed(1)}`;

        const gradeSpan = document.getElementById('calcGradeDisplay');
        if (gradeSpan) {
            if (totalScore >= 80) gradeSpan.style.color = '#10b981';
            else if (totalScore >= 70) gradeSpan.style.color = '#3b82f6';
            else if (totalScore >= 60) gradeSpan.style.color = '#f59e0b';
            else if (totalScore >= 50) gradeSpan.style.color = '#f97316';
            else gradeSpan.style.color = '#ef4444';
        }
    }






    // Execute code in the tester modal
    function executeCode() {
        const code = document.getElementById('testCodeEditor').value;
        const language = document.getElementById('currentLanguage').innerText;
        const outputDiv = document.getElementById('testOutput');
        const resultsDiv = document.getElementById('testResultsList');

        outputDiv.innerHTML =
            '<div style="color: #fbbf24;"><i class="fas fa-spinner fa-spin"></i> Executing code...</div>';

        // Send to backend for execution
        fetch('/api/execute_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    code: code,
                    language: language
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    outputDiv.innerHTML =
                        `<pre style="margin: 0; color: #d4d4d4;">${escapeHTML(data.output)}</pre>`;
                    if (data.testResults) {
                        displayTestResults(data.testResults);
                    }
                } else {
                    outputDiv.innerHTML =
                        `<pre style="margin: 0; color: #ef4444;">Error: ${escapeHTML(data.error)}</pre>`;
                }
            })
            .catch(error => {
                outputDiv.innerHTML =
                    `<pre style="margin: 0; color: #ef4444;">Execution failed: ${escapeHTML(error.message)}</pre>`;
            });
    }



    // Print Score Sheet Functions
    function openPrintModal() {
        const select = document.getElementById('printCourseSelect');
        if (select) {
            const courses = [];
            for (const sub of originalSubmissionsData) {
                const examDetail = examDetailsCache[sub.exam_id];
                if (examDetail && !courses.find(c => c.code === examDetail.course_code)) {
                    courses.push({
                        code: examDetail.course_code,
                        name: examDetail.title,
                        examId: sub.exam_id
                    });
                }
            }
            select.innerHTML = '<option value="">-- Select Course --</option>' +
                courses.map(course => `<option value="${course.examId}" data-course-code="${escapeHTML(course.code)}" data-course-name="${escapeHTML(course.name)}">
                ${escapeHTML(course.code)} - ${escapeHTML(course.name)}
            </option>`).join('');
        }
        const modal = document.getElementById('printModal');
        if (modal) modal.style.display = 'flex';
    }

    function closePrintModal() {
        const modal = document.getElementById('printModal');
        if (modal) modal.style.display = 'none';
    }

    function closePrintPreviewModal() {
        const modal = document.getElementById('printPreviewModal');
        if (modal) modal.style.display = 'none';
    }

    function generatePrintPreview() {
        const select = document.getElementById('printCourseSelect');
        const examId = select.value;
        const courseCode = select.options[select.selectedIndex]?.dataset.courseCode || '';
        const courseName = select.options[select.selectedIndex]?.dataset.courseName || '';

        if (!examId) {
            toast('❌ Please select a course');
            return;
        }

        const examDetail = examDetailsCache[examId];
        const courseSubmissions = originalSubmissionsData.filter(sub => sub.exam_id == examId);

        if (courseSubmissions.length === 0) {
            toast('❌ No submissions found for this course');
            return;
        }

        const schoolName = examDetail?.school_name || 'SCHOOL NAME';
        const programme = examDetail?.programme || examDetail?.department || 'PROGRAMME';
        const schoolType = examDetail?.school_type || 'REGULAR';
        const level = examDetail?.level || 'N/A';
        const semester = examDetail?.semester || 'SEMESTER';
        const academicYear = examDetail?.academic_year || `${new Date().getFullYear()}/${new Date().getFullYear() + 1}`;
        const intake = examDetail?.intake || '1';

        let tableRows = '';
        let sn = 1;
        courseSubmissions.forEach(sub => {
            const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = convertTo100Scale(examScore, classScore);
            const gradeInfo = getGradeInfo(totalScore);

            tableRows += `
            <tr style="border: 1px solid #000;">
                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${sn++}</td>
                <td style="border: 1px solid #000; padding: 8px;">${escapeHTML(sub.student_identifier || 'N/A')}</td>
                <td style="border: 1px solid #000; padding: 8px;">${escapeHTML(sub.student_name || 'Unknown')}</td>
                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${classScore}</td>
                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${examScore}</td>
                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${totalScore}</td>
                <td style="border: 1px sodeInfo.grade}</td>
            </tr>
        `;
        });

        const printHtml = `
        <div id="scoreSheetPrint" style="font-family: Arial, sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h1 style="margin: 0; font-size: 28px;">${escapeHTML(schoolName)}</h1>
                <h2 style="margin: 10px 0; font-size: 24px;">SCORE SHEET</h2>
                <p style="margin: 5px 0; font-size: 14px;">${escapeHTML(programme)} - ${escapeHTML(schoolType)}</p>
                <p style="margin: 5px 0; font-size: 14px;">
                    Level: ${escapeHTML(level)} | Semester: ${escapeHTML(semester)} | Academic Year: ${escapeHTML(academicYear)} | Intake: ${escapeHTML(intake)}
                </p>
                <p style="margin: 5px 0; font-size: 14px; font-weight: bold;">
                    Course: ${escapeHTML(courseName)} (${escapeHTML(courseCode)})
                </p>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #000;">
                <thead>
                    <tr style="background: #f0f0f0;">
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">S/N</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Student ID</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Student Name</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Class Score (40%)</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Exam Score (60%)</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Total Score (100%)</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Grade</th>
                    </tr>
                </thead>
                <tbody>
                    ${tableRows}
                </tbody>
                <tfoot>
                    <tr style="background: #f9f9f9;">
                        <td colspan="6" style="border: 1px solid #000; padding: 8px; text-align: right;"><strong>Total Students:</strong></td>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center;"><strong>${courseSubmissions.length}</strong></td>
                    </tr>
                </tfoot>
            </table>
            
            <div style="margin-top: 40px;">
                <div style="display: flex; justify-content: space-between; margin-top: 30px;">
                    <div style="text-align: center;">
                        <p>_____________________</p>
                        <p>Lecturer's Signature</p>
                        <p>Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                    <div style="text-align: center;">
                        <p>_____________________</p>
                        <p>Head of Department</p>
                        <p>Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                    <div style="text-align: center;">
                        <p>_____________________</p>
                        <p>Dean's Signature</p>
                        <p>Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                </div>
            </div>
        </div>
    `;

        const previewContent = document.getElementById('printPreviewContent');
        if (previewContent) previewContent.innerHTML = printHtml;

        closePrintModal();
        const previewModal = document.getElementById('printPreviewModal');
        if (previewModal) previewModal.style.display = 'flex';
    }

    function printScoreSheet() {
        const printContent = document.getElementById('scoreSheetPrint');
        if (!printContent) return;

        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
        <html>
            <head>
                <title>Score Sheet</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #000; padding: 8px; text-align: center; }
                    th { background: #f0f0f0; }
                </style>
            </head>
            <body>
                ${printContent.outerHTML}
                <script>window.onload = function() { window.print(); window.close(); }<\/script>
            </body>
        </html>
    `);
        printWindow.document.close();
    }

    function exportMarksToCSV() {
        if (!originalSubmissionsData || originalSubmissionsData.length === 0) {
            toast('❌ No submissions to export');
            return;
        }

        const exportData = originalSubmissionsData.map(sub => {
            const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = convertTo100Scale(examScore, classScore);
            const gradeInfo = getGradeInfo(totalScore);

            return {
                'Student ID': sub.student_identifier || 'N/A',
                'Student Name': sub.student_name || 'Unknown',
                'Course Code': sub.course_code || 'N/A',
                'Exam Title': sub.exam_title || 'Unknown',
                'Class Score (max 40)': classScore,
                'Exam Score (max 60)': examScore,
                'Total Score (max 100)': totalScore,
                'Grade': gradeInfo.grade,
                'Grade Point': gradeInfo.gradePoint.toFixed(1),
                'Status': sub.status || 'SUBMITTED'
            };
        });

        const ws = XLSX.utils.json_to_sheet(exportData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Marks_Sheet');

        const currentDate = new Date();
        const fileName = `marks_sheet_${currentDate.toISOString().slice(0, 19).replace(/:/g, '-')}.xlsx`;
        XLSX.writeFile(wb, fileName);

        toast(`📊 Exported ${exportData.length} student marks`);
    }



    async function downloadCourseSubmissionsByExam(courseCode, courseName) {
        const courseSubmissions = originalSubmissionsData.filter(sub => sub.course_code === courseCode);

        if (!courseSubmissions || courseSubmissions.length === 0) {
            toast('❌ No submissions found for this course');
            return;
        }

        // Confirm before downloading
        if (!confirm(`Download ${courseSubmissions.length} submission(s) for ${courseName}?`)) {
            return;
        }

        showLoading(`Downloading ${courseSubmissions.length} submissions for ${courseName}...`);

        try {
            for (let i = 0; i < courseSubmissions.length; i++) {
                const sub = courseSubmissions[i];
                // Add delay between downloads to prevent browser blocking
                await new Promise((resolve) => {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    form.target = '_blank';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'download_submission_zip';

                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'submission_id';
                    idInput.value = sub.id;

                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                    setTimeout(() => {
                        document.body.removeChild(form);
                        resolve();
                    }, 800);
                });
            }
            toast(`✅ ${courseSubmissions.length} submissions downloaded for ${courseName}`);
        } catch (error) {
            console.error('Download error:', error);
            toast('❌ Download failed: ' + error.message);
        } finally {
            setTimeout(() => hideLoading(), 2000);
        }
    }


    // Add this to your JavaScript section (around line where other download functions are)

    async function downloadFullCourseSubmissions() {
        // Get unique courses from submissions
        const courses = {};
        originalSubmissionsData.forEach(sub => {
            const examDetail = examDetailsCache[sub.exam_id];
            const courseKey = examDetail ? examDetail.course_code : sub.course_code;
            const courseName = examDetail ? examDetail.title : sub.exam_title;
            if (courseKey && !courses[courseKey]) {
                courses[courseKey] = {
                    code: courseKey,
                    name: courseName,
                    submissions: []
                };
            }
            if (courseKey && courses[courseKey]) {
                courses[courseKey].submissions.push(sub);
            }
        });

        if (Object.keys(courses).length === 0) {
            toast('❌ No courses with submissions found');
            return;
        }

        // Create course selection modal
        const modalHtml = `
        <div id="courseSelectModal" class="modal" style="display: flex;">
            <div class="modal-content" style="width: 500px; max-width: 90%;">
                <h3><i class="fas fa-folder-download"></i> Select Course to Download</h3>
                <div class="field">
                    <label>Choose Course:</label>
                    <select id="courseSelect" style="width: 100%; padding: 12px; border-radius: 10px; border: 2px solid var(--border);">
                        <option value="">-- Select a course --</option>
                        ${Object.values(courses).map(course => 
                            `<option value="${escapeHTML(course.code)}">${escapeHTML(course.code)} - ${escapeHTML(course.name)} (${course.submissions.length} submissions)</option>`
                        ).join('')}
                    </select>
                </div>
                <div class="modal-actions" style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn" onclick="closeCourseSelectModal()">Cancel</button>
                    <button class="btn primary" onclick="downloadSelectedCourse()">Download ZIP</button>
                </div>
            </div>
        </div>
    `;

        // Remove existing modal if any
        const existingModal = document.getElementById('courseSelectModal');
        if (existingModal) existingModal.remove();

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // Store courses globally for access
        window.selectedCoursesData = courses;
    }

    function closeCourseSelectModal() {
        const modal = document.getElementById('courseSelectModal');
        if (modal) modal.remove();
    }

    async function downloadSelectedCourse() {
        const select = document.getElementById('courseSelect');
        const selectedCode = select.value;

        if (!selectedCode) {
            toast('❌ Please select a course');
            return;
        }

        const course = window.selectedCoursesData[selectedCode];
        if (!course || course.submissions.length === 0) {
            toast('❌ No submissions found for this course');
            closeCourseSelectModal();
            return;
        }

        closeCourseSelectModal();

        showLoading(`Downloading ${course.submissions.length} submissions for ${course.code}...`);

        try {
            for (const sub of course.submissions) {
                await new Promise((resolve) => {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    form.target = '_blank';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'download_submission_zip';

                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'submission_id';
                    idInput.value = sub.id;

                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                    setTimeout(() => {
                        document.body.removeChild(form);
                        resolve();
                    }, 800);
                });
            }
            toast(`✅ ${course.submissions.length} submissions downloaded for ${course.code}`);
        } catch (error) {
            console.error('Download error:', error);
            toast('❌ Download failed: ' + error.message);
        } finally {
            setTimeout(() => hideLoading(), 2000);
        }
    }




    function showEmptySubmissionsState() {
        const container = document.getElementById('submissionsContainer');
        if (container) {
            container.innerHTML = `
            <div style="text-align:center; padding:60px;">
                <div style="font-size:48px; margin-bottom:15px;">📭</div>
                <h3>No Submissions Yet</h3>
                <p style="color:var(--muted);">When students submit exams, they will appear here.</p>
            </div>
        `;
        }
    }

    function showErrorSubmissionsState(errorMsg) {
        const container = document.getElementById('submissionsContainer');
        if (container) {
            container.innerHTML = `
            <div style="text-align:center; padding:60px;">
                <div style="font-size:48px; margin-bottom:15px;">❌</div>
                <h3>Error Loading Submissions</h3>
                <p style="color:var(--muted);">${escapeHTML(errorMsg || 'Unknown error')}</p>
                <button class="btn primary" onclick="loadSubmissions()" style="margin-top:15px;">
                    <i class="fas fa-sync-alt"></i> Try Again
                </button>
            </div>
        `;
        }
    }





    // ============================================
    // 9. MARKING FUNCTIONS
    // ============================================

    function renderMarking() {
        const subs = getSubs();
        const exams = getExams();
        const container = document.getElementById('markingList');
        if (!container) return;

        if (subs.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:40px;">📭 No submissions to mark.</div>';
            return;
        }

        container.innerHTML = subs.map(s => {
            const ex = exams.find(e => e.id === s.examId);
            const pendingItems = s.items?.filter(it => it.manualScore === undefined) || [];
            const {
                grade,
                class: gradeClass
            } = calculateGrade(s.scoreTotal || s.scoreAuto || 0, s.totalMarks || 1);
            return `<div class="qcard"><div class="qhead"><div><div class="qtitle">${escapeHTML(ex?.title || s.examId)}</div><div class="qmeta">Student: ${escapeHTML(s.studentId)} | Submitted: ${new Date(s.submittedAtISO).toLocaleString()}</div></div><div><span class="tag ${s.status === 'MARKED' ? 'status-published' : ''}">${s.status || 'PENDING'}</span></div></div><div style="margin:10px 0;"><p><strong>Auto Score:</strong> ${s.scoreAuto || 0} / ${s.totalMarks}</p><p><strong>Manual Score:</strong> ${s.scoreManual || 0} / ${s.totalMarks}</p><p><strong>Total:</strong> ${(s.scoreTotal || s.scoreAuto || 0)} / ${s.totalMarks}</p><p><strong>Grade:</strong> <span class="tag ${gradeClass}">${grade}</span></p><p><strong>Pending Items:</strong> ${pendingItems.length}</p></div><div style="display:flex; gap:8px;"><button class="btn primary" onclick="openMarkingModal('${s.id}')">✏️ Mark Manually</button><button class="btn ok" onclick="publishSubmission('${s.id}')">🚀 Publish</button></div></div>`;
        }).join('');
    }

    function openManualMarking() {
        const subs = getSubs().filter(s => s.status !== 'MARKED');
        if (subs.length === 0) {
            toast('✅ All submissions are marked');
            return;
        }
        openMarkingModal(subs[0].id);
    }

    function closeMarkingModal() {
        const modal = document.getElementById('markingModal');
        if (modal) modal.style.display = 'none';
    }

    function closePreviewModal() {
        const modal = document.getElementById('previewModal');
        if (modal) modal.style.display = 'none';
    }

    async function autoGradeSubmission(submissionId) {
        toast('🤖 Auto-grading in progress...');
        try {
            const result = await apiRequest('auto_grade_submission', {
                submission_id: submissionId
            });
            if (result.success) {
                toast(
                    `✅ Auto-graded! Score: ${result.total_score}/${result.total_marks || '?'} (${result.percentage}%)`
                );
                loadSubmissions();
            } else {
                toast('❌ ' + (result.error || 'Auto-grading failed'));
            }
        } catch (error) {
            toast('❌ Network error. Please try again.');
        }
    }

    // ============================================
    // 10. RESULTS FUNCTIONS
    // ============================================

    function calculateGrade(score, total) {
        const percentage = (score / total) * 100;
        if (percentage >= 80) return {
            grade: 'A',
            class: 'grade-a'
        };
        if (percentage >= 75) return {
            grade: 'B+',
            class: 'grade-bplus'
        };
        if (percentage >= 70) return {
            grade: 'B',
            class: 'grade-b'
        };
        if (percentage >= 65) return {
            grade: 'C+',
            class: 'grade-cplus'
        };
        if (percentage >= 60) return {
            grade: 'C',
            class: 'grade-c'
        };
        if (percentage >= 55) return {
            grade: 'D+',
            class: 'grade-dplus'
        };
        if (percentage >= 50) return {
            grade: 'D',
            class: 'grade-d'
        };
        return {
            grade: 'E',
            class: 'grade-e'
        };
    }

    function renderResults() {
        const subs = getSubs();
        const exams = getExams();
        const students = getStudents();
        const scores = subs.map(s => s.scoreTotal || s.scoreAuto || 0);
        const mean = scores.reduce((a, b) => a + b, 0) / (scores.length || 1);
        const sorted = [...scores].sort((a, b) => a - b);
        const median = sorted[Math.floor(sorted.length / 2)] || 0;
        const variance = scores.reduce((a, b) => a + Math.pow(b - mean, 2), 0) / (scores.length || 1);
        const stdDev = Math.sqrt(variance).toFixed(2);
        const passCount = scores.filter(s => s >= 50).length;
        const passRate = ((passCount / (scores.length || 1)) * 100).toFixed(1);

        const meanScoreEl = document.getElementById('meanScore');
        const medianScoreEl = document.getElementById('medianScore');
        const stdDevEl = document.getElementById('stdDev');
        const passRateEl = document.getElementById('passRate');

        if (meanScoreEl) meanScoreEl.textContent = mean.toFixed(2);
        if (medianScoreEl) medianScoreEl.textContent = median.toFixed(2);
        if (stdDevEl) stdDevEl.textContent = stdDev;
        if (passRateEl) passRateEl.textContent = passRate + '%';

        updateLineChart(scores);
        updateBellCurve(scores);
        updateCorrelationChart(scores);
        updateRegressionChart(scores);

        const rows = subs.map((s, index) => {
            const ex = exams.find(e => e.id === s.examId);
            const student = students.find(st => st.id === s.studentId);
            const total = s.totalMarks || 0;
            const score = (s.scoreTotal ?? s.scoreAuto ?? 0);
            const {
                grade,
                class: gradeClass
            } = calculateGrade(score, total);
            const statusClass = s.status === 'MARKED' ? 'status-published' : '';
            return `<tr><td>${index + 1}</td><td><span class="tag">${escapeHTML(s.studentId)}</span><br><small>${escapeHTML(student?.fullName || '')}</small></td><td>${escapeHTML(student?.level || '—')}</td><td>${escapeHTML(ex?.courseCode || '')}</td><td>2025</td><td>Semester 2</span></td><td><input type="number" id="classScore_${s.id}" value="0" min="0" max="100" style="width:70px;" onchange="updateClassScore('${s.id}', this.value)"></td><td><b>${score.toFixed(1)}</b> / ${total}</td><td><b>${(score + (parseFloat(document.getElementById('classScore_' + s.id)?.value || 0))).toFixed(1)}</b> / ${total + 100}</span></td><td><span class="tag ${gradeClass}">${grade}</span></td><td><span class="tag ${statusClass}">${s.published ? 'PUBLISHED' : s.status}</span></td></tr>`;
        }).join('');

        const resultsTable = document.getElementById("resultsTable");
        if (resultsTable) {
            resultsTable.innerHTML =
                `<thead><tr><th>S/N</th><th>Student</th><th>Level</th><th>Course</th><th>Year</th><th>Semester</th><th>Class Score</th><th>Exam Score</th><th>Total</th><th>Grade</th><th>Status</th></tr></thead><tbody>${rows || '<tr><td colspan="11" style="text-align:center">📭 No results yet. </td></tr>'}</tbody>`;
        }
    }

    function calculateGrades() {
        renderResults();
        toast('📊 Grades calculated using PU grading system');
    }

    function publishAllResults() {
        const subs = getSubs();
        subs.forEach(s => {
            s.published = true;
            s.publishedAt = new Date().toISOString();
        });
        setSubs(subs);
        toast("🚀 All results published");
        renderResults();
    }


    async function exportResults(format) {
        const examId = document.getElementById('exportExamSelect')?.value;
        if (!examId) {
            toast('❌ Please select an exam to export');
            return;
        }

        const result = await apiRequest('get_exam_for_export', {
            exam_id: examId
        });
        if (!result.success) {
            toast('❌ Failed to load exam data');
            return;
        }

        const exam = result.data;
        const submissions = result.submissions || [];

        // Prepare export data with strict template
        const exportData = submissions.map(sub => ({
            'SCHOOL NAME': exam.school_name?.toUpperCase() || '',
            'FACULTY': exam.faculty_name?.toUpperCase() || '',
            'EXAM TYPE': exam.exam_type?.toUpperCase() || '',
            'COURSE NAME': exam.title?.toUpperCase() || '',
            'COURSE CODE': exam.course_code?.toUpperCase() || '',
            'LEVEL': exam.level?.toUpperCase() || '',
            'SCHOOL TYPE': exam.school_type?.toUpperCase() || '',
            'STUDENT ID': sub.student_identifier || '',
            'STUDENT NAME': sub.student_name || '',
            'SCORE': sub.percentage || sub.total_score || '',
            'GRADE': calculateGradeLetter(sub.percentage || sub.total_score || 0),
            'STATUS': sub.status || 'PENDING'
        }));

        if (format === 'excel' || format === 'csv') {
            const ws = XLSX.utils.json_to_sheet(exportData);
            // Center all cells
            const range = XLSX.utils.decode_range(ws['!ref'] || 'A1:A1');
            for (let R = range.s.r; R <= range.e.r; ++R) {
                for (let C = range.s.c; C <= range.e.c; ++C) {
                    const cellAddress = XLSX.utils.encode_cell({
                        r: R,
                        c: C
                    });
                    if (!ws[cellAddress]) continue;
                    ws[cellAddress].s = {
                        alignment: {
                            horizontal: 'center',
                            vertical: 'center'
                        }
                    };
                }
            }
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Exam_Results');
            const fileName = format === 'csv' ? 'exam_results.csv' : 'exam_results.xlsx';
            XLSX.writeFile(wb, fileName);
        } else if (format === 'pdf') {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'landscape'
            });

            // Add header with school info
            doc.setFontSize(16);
            doc.text(exam.school_name?.toUpperCase() || '', 148, 15, {
                align: 'center'
            });
            doc.setFontSize(12);
            doc.text(exam.faculty_name?.toUpperCase() || '', 148, 22, {
                align: 'center'
            });
            doc.text(`${exam.exam_type?.toUpperCase() || ''} - ${exam.title?.toUpperCase() || ''}`, 148, 29, {
                align: 'center'
            });

            // Add table
            doc.autoTable({
                head: [Object.keys(exportData[0] || {})],
                body: exportData.map(row => Object.values(row)),
                startY: 35,
                theme: 'grid',
                styles: {
                    halign: 'center',
                    cellPadding: 3,
                    fontSize: 8
                },
                headStyles: {
                    fillColor: [59, 130, 246],
                    textColor: [255, 255, 255],
                    fontStyle: 'bold'
                }
            });

            doc.save('exam_results.pdf');
        }

        toast(`📊 Results exported as ${format.toUpperCase()}`);
    }

    function calculateGradeLetter(percentage) {
        if (percentage >= 80) return 'A';
        if (percentage >= 75) return 'B+';
        if (percentage >= 70) return 'B';
        if (percentage >= 65) return 'C+';
        if (percentage >= 60) return 'C';
        if (percentage >= 55) return 'D+';
        if (percentage >= 50) return 'D';
        return 'E';
    }

    function exportResults(format) {
        const subs = getSubs();
        const exams = getExams();
        const students = getStudents();
        const data = subs.map((s, index) => {
            const ex = exams.find(e => e.id === s.examId);
            const student = students.find(st => st.id === s.studentId);
            const score = s.scoreTotal || s.scoreAuto || 0;
            const total = s.totalMarks || 0;
            const {
                grade
            } = calculateGrade(score, total);
            return {
                'S/N': index + 1,
                'Student ID': s.studentId,
                'Full Name': student?.fullName || '',
                'Level': student?.level || '',
                'Course': ex?.courseCode || '',
                'Academic Year': '2025',
                'Semester': '2',
                'Class Score': 0,
                'Exam Score': score.toFixed(1),
                'Total Score': score.toFixed(1),
                'Grade': grade,
                'Status': s.published ? 'Published' : s.status
            };
        });

        if (format === 'excel') {
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Results");
            XLSX.writeFile(wb, "exam_results.xlsx");
        } else if (format === 'csv') {
            const ws = XLSX.utils.json_to_sheet(data);
            const csv = XLSX.utils.sheet_to_csv(ws);
            const blob = new Blob([csv], {
                type: 'text/csv'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'exam_results.csv';
            a.click();
        } else if (format === 'pdf') {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF();
            doc.text('Exam Results', 20, 20);
            doc.autoTable({
                html: '#resultsTable'
            });
            doc.save('exam_results.pdf');
        }
        toast(`📊 Results exported as ${format.toUpperCase()}`);
    }

    function printResults() {
        window.print();
    }

    function updateLineChart(scores) {
        const ctx = document.getElementById('lineChart')?.getContext('2d');
        if (!ctx) return;
        if (lineChart) lineChart.destroy();

        lineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Exam 1', 'Exam 2', 'Exam 3', 'Exam 4', 'Exam 5'],
                datasets: [{
                    label: 'Average Score',
                    data: [72, 75, 78, 82, 85],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Performance Trend'
                    }
                }
            }
        });
    }

    function updateBellCurve(scores) {
        const ctx = document.getElementById('bellCurve')?.getContext('2d');
        if (!ctx) return;
        if (bellCurve) bellCurve.destroy();

        const bins = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        scores.forEach(score => {
            const binIndex = Math.floor(score / 10);
            if (binIndex >= 0 && binIndex < 10) bins[binIndex]++;
        });

        bellCurve = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['0-10', '11-20', '21-30', '31-40', '41-50', '51-60', '61-70', '71-80', '81-90',
                    '91-100'
                ],
                datasets: [{
                    label: 'Grade Distribution',
                    data: bins,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139,92,246,0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#8b5cf6'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Grade Distribution (Bell Curve)'
                    }
                }
            }
        });
    }

    function updateCorrelationChart(scores) {
        const ctx = document.getElementById('correlationChart2')?.getContext('2d');
        if (!ctx) return;
        if (correlationChart) correlationChart.destroy();

        const studyTime = scores.map((_, i) => i * 2);
        correlationChart = new Chart(ctx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Study Time vs Score',
                    data: studyTime.map((time, i) => ({
                        x: time,
                        y: scores[i]
                    })),
                    backgroundColor: 'rgba(59,130,246,0.6)'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Study Time vs Performance Correlation'
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Study Time (hours)'
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Score'
                        }
                    }
                }
            }
        });
    }

    function updateRegressionChart(scores) {
        const ctx = document.getElementById('regressionChart')?.getContext('2d');
        if (!ctx) return;
        if (regressionChart) regressionChart.destroy();

        const x = Array.from({
            length: scores.length
        }, (_, i) => i);
        const meanX = x.reduce((a, b) => a + b, 0) / x.length;
        const meanY = scores.reduce((a, b) => a + b, 0) / scores.length;
        const slope = x.reduce((sum, xi, i) => sum + (xi - meanX) * (scores[i] - meanY), 0) / x.reduce((sum, xi) =>
            sum + Math.pow(xi - meanX, 2), 0);
        const intercept = meanY - slope * meanX;
        const regressionLine = x.map(xi => slope * xi + intercept);

        regressionChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: Array.from({
                    length: scores.length
                }, (_, i) => `Student ${i + 1}`),
                datasets: [{
                    label: 'Actual Scores',
                    data: scores,
                    borderColor: '#3b82f6',
                    backgroundColor: 'transparent',
                    pointRadius: 4
                }, {
                    label: 'Regression Line',
                    data: regressionLine,
                    borderColor: '#ef4444',
                    backgroundColor: 'transparent',
                    borderDash: [5, 5],
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Score Regression Analysis'
                    }
                }
            }
        });
    }

    function updateClassScore(subId, value) {
        console.log(`Class score for ${subId}: ${value}`);
    }

    // ============================================
    // 11. PROFILE FUNCTIONS
    // ============================================

    async function loadProfile() {
        try {
            const result = await apiRequest('get_profile');
            if (result.success && result.data) {
                const profile = result.data;
                const profileName = document.getElementById('profileName');
                const profileStaffId = document.getElementById('profileStaffId');
                const profileEmail = document.getElementById('profileEmail');
                const profileDepartment = document.getElementById('profileDepartment');
                const profileFaculty = document.getElementById('profileFaculty');
                const profileLevels = document.getElementById('profileLevels');
                const profileClasses = document.getElementById('profileClasses');
                const profileCourses = document.getElementById('profileCourses');

                if (profileName) profileName.value = profile.full_name || '';
                if (profileStaffId) profileStaffId.value = profile.staff_id || '';
                if (profileEmail) profileEmail.value = profile.email || '';
                if (profileDepartment) profileDepartment.value = profile.department || '';
                if (profileFaculty) profileFaculty.value = profile.faculty || '';
                if (profileLevels) profileLevels.value = profile.levels_taught || '';
                if (profileClasses) profileClasses.value = profile.classes || '';
                if (profileCourses) profileCourses.value = profile.courses || '';

                const profilePicDisplay = document.getElementById('profilePicDisplay');
                if (profilePicDisplay) {
                    if (profile.profile_pic && profile.profile_pic !== '') {
                        profilePicDisplay.src = profile.profile_pic;
                        profilePicDisplay.style.display = 'block';
                    } else {
                        profilePicDisplay.style.display = 'none';
                    }
                }

                const profileIcon = document.querySelector('.profile-icon');
                const smallAvatar = document.querySelector('.profile-avatar-small');
                if (profileIcon && profile.profile_pic && profile.profile_pic !== '') {
                    profileIcon.innerHTML = `<img src="${profile.profile_pic}" alt="Profile">`;
                    if (smallAvatar) smallAvatar.innerHTML = `<img src="${profile.profile_pic}" alt="Profile">`;
                }
            }
        } catch (error) {
            console.error('Error loading profile:', error);
        }
    }

    function previewProfilePic(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(e) {
            const imgData = e.target.result;
            const display = document.getElementById('profilePicDisplay');
            if (display) {
                display.src = imgData;
                display.style.display = 'block';
            }

            const profileIcon = document.querySelector('.profile-icon');
            if (profileIcon) profileIcon.innerHTML = `<img src="${imgData}" alt="Profile">`;

            const smallAvatar = document.querySelector('.profile-avatar-small');
            if (smallAvatar) smallAvatar.innerHTML = `<img src="${imgData}" alt="Profile">`;
        };
        reader.readAsDataURL(file);

        uploadProfilePicture(file);
    }

    async function uploadProfilePicture(file) {
        const formData = new FormData();
        formData.append('action', 'upload_profile_pic');
        formData.append('profile_pic', file);
        try {
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success) toast('✅ Profile picture uploaded');
            else toast('❌ Upload failed: ' + result.error);
        } catch (error) {
            console.error('Upload error:', error);
            toast('❌ Upload failed');
        }
    }

    async function saveProfile() {
        const profilePicDisplay = document.getElementById('profilePicDisplay');
        const profilePic = profilePicDisplay && profilePicDisplay.src ? profilePicDisplay.src : '';

        const prof = {
            full_name: document.getElementById('profileName')?.value.trim() || '',
            email: document.getElementById('profileEmail')?.value.trim() || '',
            staff_id: document.getElementById('profileStaffId')?.value.trim() || '',
            department: document.getElementById('profileDepartment')?.value.trim() || '',
            faculty: document.getElementById('profileFaculty')?.value.trim() || '',
            levels_taught: document.getElementById('profileLevels')?.value.trim() || '',
            classes: document.getElementById('profileClasses')?.value.trim() || '',
            courses: document.getElementById('profileCourses')?.value.trim() || '',
            profile_pic: profilePic
        };

        const btn = event ? event.target : document.querySelector('#view-profile .btn.primary');
        const originalText = btn ? btn.innerHTML : 'Save';
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            btn.disabled = true;
        }

        try {
            const result = await apiRequest('update_profile', prof);
            if (result.success) {
                toast('✅ Profile saved successfully');
                loadProfile();
            } else {
                toast('❌ Failed to save profile: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            toast('❌ Network error. Please try again.');
        } finally {
            if (btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
    }

    async function changeLecturerPassword() {
        const currentPassword = document.getElementById('currentPassword')?.value;
        const newPassword = document.getElementById('newPassword')?.value;
        const confirmPassword = document.getElementById('confirmPassword')?.value;

        if (!currentPassword || !newPassword || !confirmPassword) {
            toast('❌ Please fill all password fields');
            return;
        }

        if (newPassword !== confirmPassword) {
            toast('❌ New passwords do not match');
            return;
        }

        if (newPassword.length < 6) {
            toast('❌ Password must be at least 6 characters');
            return;
        }

        const btn = event ? event.target : document.querySelector('#view-profile .btn.primary');
        const originalText = btn ? btn.innerHTML : 'Update';
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            btn.disabled = true;
        }

        try {
            const result = await apiRequest('change_lecturer_password', {
                current_password: currentPassword,
                new_password: newPassword
            });

            if (result.success) {
                toast('✅ Password changed successfully!');
                const currPass = document.getElementById('currentPassword');
                const newPass = document.getElementById('newPassword');
                const confirmPass = document.getElementById('confirmPassword');
                if (currPass) currPass.value = '';
                if (newPass) newPass.value = '';
                if (confirmPass) confirmPass.value = '';
            } else {
                toast('❌ ' + (result.error || 'Failed to change password'));
            }
        } catch (error) {
            console.error('Error:', error);
            toast('❌ Network error. Please try again.');
        } finally {
            if (btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
    }

    // ============================================
    // 12. EXAM VISIBILITY FUNCTIONS
    // ============================================

    async function loadEnrolledStudentsForExam() {
        const courseCode = document.getElementById('bCode')?.value;
        const level = document.getElementById('level')?.value || '';
        if (!courseCode) {
            const enrolledTable = document.getElementById('enrolledStudentsTable');
            if (enrolledTable) {
                enrolledTable.innerHTML =
                    '<tr><td colspan="5" style="text-align: center;">Please enter a course code first. </td></tr>';
            }
            return;
        }

        try {
            const result = await apiRequest('get_course_students', {
                course_code: courseCode,
                level: level
            });

            if (result.success && result.data && result.data.length > 0) {
                const tbody = document.getElementById('enrolledStudentsTable');
                const visibilityResult = await apiRequest('get_exam_visibility', {
                    exam_id: currentExamId
                });
                const visibilityMap = visibilityResult.success ? visibilityResult.data : {};

                if (tbody) {
                    tbody.innerHTML = result.data.map(student => {
                        const isVisible = visibilityMap[student.id] !== undefined ? visibilityMap[student
                            .id] : true;
                        return `<tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 12px 8px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #4f46e5, #06b6d4); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                                        ${escapeHTML(student.full_name.charAt(0).toUpperCase())}
                                    </div>
                                    <div>
                                        <strong>${escapeHTML(student.full_name)}</strong>
                                        <br><small style="color: var(--muted);">ID: ${escapeHTML(student.student_id)}</small>
                                    </div>
                                </div>
                              </td>
                            <td style="padding: 12px 8px;">Level ${escapeHTML(student.level)}</td>
                            <td style="padding: 12px 8px;">${escapeHTML(courseCode)}</td>
                            <td style="padding: 12px 8px; text-align: center;">
                                <button class="visibility-btn ${isVisible ? 'visible' : 'hidden'}" 
                                    onclick="toggleStudentVisibility(${student.id}, this)" 
                                    data-visible="${isVisible}" 
                                    style="background: ${isVisible ? '#10b981' : '#ef4444'};">
                                    <i class="fas ${isVisible ? 'fa-eye' : 'fa-eye-slash'}"></i>
                                    <span>${isVisible ? 'Visible' : 'Hidden'}</span>
                                </button>
                              </td>
                          </tr>`;
                    }).join('');
                }
            } else {
                const enrolledTable = document.getElementById('enrolledStudentsTable');
                if (enrolledTable) {
                    enrolledTable.innerHTML =
                        '<tr><td colspan="5" style="text-align: center; padding: 40px;">📭 No students enrolled in this course. Please add students first. </td></tr>';
                }
            }
        } catch (error) {
            console.error('Error loading students:', error);
            const enrolledTable = document.getElementById('enrolledStudentsTable');
            if (enrolledTable) {
                enrolledTable.innerHTML =
                    '<tr><td colspan="5" style="text-align: center; padding: 40px;">❌ Error loading students. Please try again. </td></tr>';
            }
        }
    }

    async function toggleStudentVisibility(studentId, button) {
        event.stopPropagation();
        const isCurrentlyVisible = button.classList.contains('visible');
        const newVisibility = !isCurrentlyVisible;

        if (newVisibility) {
            button.classList.remove('hidden');
            button.classList.add('visible');
            button.style.background = '#10b981';
            button.innerHTML = '<i class="fas fa-eye"></i><span>Visible</span>';
            toast(`✅ Student can now see this exam`, 2000);
        } else {
            button.classList.remove('visible');
            button.classList.add('hidden');
            button.style.background = '#ef4444';
            button.innerHTML = '<i class="fas fa-eye-slash"></i><span>Hidden</span>';
            toast(`🔒 Student cannot see this exam`, 2000);
        }

        try {
            await apiRequest('update_exam_visibility', {
                student_id: studentId,
                exam_id: currentExamId,
                visible: newVisibility ? 1 : 0
            });
        } catch (error) {
            console.error('Error saving visibility:', error);
            toast('❌ Failed to save visibility setting', 2000);
            if (newVisibility) {
                button.classList.remove('visible');
                button.classList.add('hidden');
                button.style.background = '#ef4444';
                button.innerHTML = '<i class="fas fa-eye-slash"></i><span>Hidden</span>';
            } else {
                button.classList.remove('hidden');
                button.classList.add('visible');
                button.style.background = '#10b981';
                button.innerHTML = '<i class="fas fa-eye"></i><span>Visible</span>';
            }
        }
    }

    function toggleStudentVisibilityList() {
        studentListVisible = !studentListVisible;
        const container = document.getElementById('enrolledStudentsListContainer');
        if (container) {
            if (studentListVisible) {
                container.style.display = 'block';
                loadEnrolledStudentsForExam();
            } else {
                container.style.display = 'none';
            }
        }
    }

    // ============================================
    // Preview Exam
    // ============================================

    // Add this function to your existing JavaScript code (around line where other preview functions are)
    function previewCompletedExam(examId) {
        const exam = findExam(examId);
        if (!exam) {
            toast('❌ Exam not found');
            return;
        }

        // Calculate total marks
        const totalMarks = calculateEffectiveExamMarks(exam);
        const questionCount = (exam.questions || []).length;
        const durationText = exam.durationMins ? `${Math.floor(exam.durationMins / 60)}h ${exam.durationMins % 60}m` :
            'N/A';

        let questionsHtml = '';
        if (!exam.questions || exam.questions.length === 0) {
            questionsHtml = `<div style="text-align:center; padding:40px; color:var(--muted);">
            <i class="fas fa-question-circle" style="font-size:40px; margin-bottom:10px;"></i>
            <p>No questions added yet.</p>
        </div>`;
        } else {
            questionsHtml = exam.questions.map((q, idx) => {
                const qMarks = q.hasSubQuestions && q.subQuestions?.length ?
                    q.subQuestions.reduce((s, sq) => s + (parseFloat(sq.marks) || 0), 0) :
                    parseFloat(q.marks) || 0;

                let answerArea = '';
                if (q.type === 'code') {
                    answerArea = `
                    <div style="margin-top:12px;">
                        <div style="font-size:12px; font-weight:600; color:var(--muted); margin-bottom:6px;">
                            <i class="fas fa-terminal"></i> Student Code (${escapeHTML(q.language || 'Code')}):
                        </div>
                        <textarea rows="6" disabled placeholder="Student types code here..."
                            style="width:100%; padding:12px; border-radius:10px; border:2px solid var(--border); background:var(--input-bg); color:var(--text); font-family:monospace; font-size:13px; resize:vertical;"></textarea>
                    </div>`;

                    if (q.hasSubQuestions && q.subQuestions?.length) {
                        answerArea += '<div style="margin-top:14px;">' + q.subQuestions.map((sq, si) => {
                            const prefix = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'][si] || (
                                si + 1);
                            return `<div style="background:var(--bg); border-radius:10px; padding:14px; margin-bottom:10px; border:1px solid var(--border);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <strong style="color:var(--blue);">${prefix})</strong>
                                <span class="tag" style="background:var(--blue); color:#fff;">${sq.marks} marks</span>
                            </div>
                            <p style="margin:0 0 8px; color:var(--text);">${escapeHTML(sq.text || '(no text)')}</p>
                            ${sq.hint ? `<small style="color:var(--muted);"><i class="fas fa-lightbulb"></i> Hint: ${escapeHTML(sq.hint)}</small>` : ''}
                            <textarea rows="3" disabled placeholder="Student answer area..." style="width:100%; margin-top:8px; padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--input-bg); color:var(--text); font-family:monospace; font-size:13px;"></textarea>
                        </div>`;
                        }).join('') + '</div>';
                    }
                }

                return `
            <div style="background:var(--panel); border:2px solid var(--border); border-radius:14px; padding:20px; margin-bottom:18px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span style="font-weight:700; font-size:15px; color:var(--text);">Q${idx + 1}.</span>
                        <span style="background:linear-gradient(135deg,#10b981,#059669); color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600;">CODING</span>
                        ${q.compulsory ? '<span style="background:#ef4444; color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600;">COMPULSORY</span>' : ''}
                    </div>
                    <span style="background:var(--blue); color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;">${qMarks} marks</span>
                </div>
                <p style="margin:0 0 4px; color:var(--text); font-size:15px; line-height:1.6;">${escapeHTML(q.text || '(no question text)')}</p>
                ${answerArea}
            </div>`;
            }).join('');
        }

        const logoHtml = '';

        const previewContent = document.getElementById('examPreviewContent');
        if (!previewContent) return;

        // Add exam status badge
        let statusBadge = '';
        const now = new Date();
        const startTime = exam.startAtISO ? new Date(exam.startAtISO) : null;
        const endTime = exam.endAtISO ? new Date(exam.endAtISO) : (startTime ? new Date(startTime.getTime() + (exam.durationMins * 60000)) : null);

        if (endTime && endTime < now) {
            statusBadge =
                '<span style="background:#6b7280; color:white; padding:4px 12px; border-radius:20px; font-size:12px; margin-left:10px;"><i class="fas fa-clock"></i> Exam Ended</span>';
        } else if (startTime && startTime > now) {
            statusBadge =
                '<span style="background:#f59e0b; color:white; padding:4px 12px; border-radius:20px; font-size:12px; margin-left:10px;"><i class="fas fa-calendar-alt"></i> Scheduled</span>';
        } else if (startTime && startTime <= now && (!endTime || endTime > now)) {
            statusBadge =
                '<span style="background:#10b981; color:white; padding:4px 12px; border-radius:20px; font-size:12px; margin-left:10px;"><i class="fas fa-play"></i> Ongoing</span>';
        } else {
            statusBadge =
                '<span style="background:#3b82f6; color:white; padding:4px 12px; border-radius:20px; font-size:12px; margin-left:10px;"><i class="fas fa-check-circle"></i> Published</span>';
        }

        previewContent.innerHTML = `
        <div style="max-width:860px; margin:0 auto;">
            <!-- Preview Mode Banner -->
            <div style="background:linear-gradient(135deg,#8b5cf6,#7c3aed); color:#fff; border-radius:16px; padding:12px 20px; margin-bottom:20px; text-align:center;">
                <i class="fas fa-eye" style="margin-right:8px;"></i> 
                <strong>Preview Mode</strong> - This is how students see the exam
                ${statusBadge}
            </div>
            
            <!-- Exam Header -->
            <div style="background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; border-radius:16px; padding:28px 24px; margin-bottom:24px; text-align:center;">
                ${logoHtml}
                <div style="font-size:13px; opacity:.85; margin-bottom:4px;">${escapeHTML(exam.school_name || '')}</div>
                <div style="font-size:12px; opacity:.75; margin-bottom:12px;">${escapeHTML(exam.faculty_name || '')} ${exam.department ? '| ' + escapeHTML(exam.department) : ''}</div>
                <h2 style="margin:0 0 6px; font-size:22px; font-weight:700;">${escapeHTML(exam.title || 'Exam')}</h2>
                <div style="font-size:13px; opacity:.85;">${escapeHTML(exam.courseCode || '')} ${exam.exam_type ? '| ' + escapeHTML(exam.exam_type) : ''} ${exam.semester ? '| ' + escapeHTML(exam.semester) : ''}</div>
            </div>
            
            <!-- Exam Meta Bar -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin-bottom:24px;">
                <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                    <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Duration</div>
                    <div style="font-size:18px; font-weight:700; color:var(--blue);">⏱ ${durationText}</div>
                </div>
                <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                    <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Questions</div>
                    <div style="font-size:18px; font-weight:700; color:var(--blue);">📝 ${questionCount}</div>
                </div>
                <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                    <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Total Marks</div>
                    <div style="font-size:18px; font-weight:700; color:var(--blue);">⭐ ${totalMarks}</div>
                </div>
                ${exam.level ? `<div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                    <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Level</div>
                    <div style="font-size:18px; font-weight:700; color:var(--blue);">🎓 ${escapeHTML(exam.level)}</div>
                </div>` : ''}
            </div>
            
            <!-- Instructions -->
            ${exam.instructions ? `<div style="background:rgba(59,130,246,.06); border-left:4px solid #3b82f6; border-radius:0 12px 12px 0; padding:16px 20px; margin-bottom:24px;">
                <div style="font-weight:700; margin-bottom:8px; color:var(--text);">📋 Instructions</div>
                <pre style="margin:0; font-family:inherit; white-space:pre-wrap; color:var(--text); font-size:14px; line-height:1.6;">${escapeHTML(exam.instructions)}</pre>
            </div>` : ''}
            
            <!-- Questions -->
            <div style="margin-bottom:24px;">${questionsHtml}</div>
            
            <!-- Submit bar (disabled in preview) -->
            <div style="background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; opacity:.6;">
                <span style="color:var(--muted); font-size:13px;"><i class="fas fa-info-circle"></i> Preview mode — submission disabled</span>
                <button disabled style="padding:10px 24px; background:#3b82f6; color:#fff; border:none; border-radius:30px; font-weight:600; cursor:not-allowed; opacity:.5;">Submit Exam</button>
            </div>
        </div>`;

        document.getElementById('examPreviewModal').style.display = 'flex';
    }


    // ============================================
    // 13. SIDEBAR & NAVIGATION FUNCTIONS
    // ============================================

    function go(route, params = {}) {
        if (!routes.includes(route)) route = "dashboard";

        if (route !== "builder" && currentExamId) {
            saveExamToDatabase();
        }

        if (route === "results") {
            setTimeout(() => {
                initResultsPage();
            }, 100);
        }
        routeState = {
            route,
            params
        };

        localStorage.setItem("currentView", route);
        sessionStorage.setItem("currentView", route);
        sessionStorage.setItem("currentRouteParams", JSON.stringify(params));

        if (params.id) {
            sessionStorage.setItem("currentExamId", params.id);
        }

        document.querySelectorAll(".nav-icon").forEach(icon => {
            const tooltip = icon.getAttribute('data-tooltip');
            if (tooltip && tooltip.toLowerCase().includes(route)) {
                icon.classList.add('active');
            } else if (icon.classList.contains('has-submenu') && route === 'builder') {
                icon.classList.add('active');
            } else {
                icon.classList.remove('active');
            }
        });

        document.querySelectorAll(".view").forEach(v => v.classList.remove("active"));
        const el = document.getElementById(`view-${route}`);
        if (el) el.classList.add("active");

        const map = {
            dashboard: "🏠 Dashboard",
            exams: "📝 Exams",
            builder: "✏️ Exam Builder",
            submissions: "📤 Submissions",
            marking: "✅ Marking",
            results: "📊 Results",
            students: "👥 Students",
            "student-details": "📋 Student Details",
            profile: "👤 Profile",
            proctoring: "👁️ Proctoring"
        };

        const bluebarTitle = document.getElementById("bluebarTitle");
        if (bluebarTitle) {
            bluebarTitle.innerHTML = `<span style="margin-left:0">${map[route] || "Dashboard"}</span>`;
        }

        if (window.history && window.history.pushState) {
            const url = new URL(window.location.href);
            url.hash = route;
            window.history.pushState({}, '', url);
        }

        renderRoute(route, params);
    }

    function renderRoute(route, params) {
        if (route === "dashboard") renderDashboard();
        if (route === "exams") renderExamsList(params || {});
        if (route === "builder") {
            document.querySelectorAll(".view").forEach(v => v.classList.remove("active"));
            const builderView = document.getElementById("view-builder");
            if (builderView) builderView.classList.add("active");
            return;
        }
        if (route === "submissions") {
            currentExpandedCourse = null;
            sessionStorage.removeItem('currentSubmissionView');
            sessionStorage.removeItem('currentCourseCode');
            loadSubmissions();
        }
        if (route === "marking") renderMarking();
        if (route === "results") renderResults();
        if (route === "students") renderStudentsTable();
        if (route === "student-details") renderStudentDetailsTable();
        if (route === "profile") loadProfile();
    }

    function debugCurrentExam() {
        if (!currentExamId) {
            console.log("No current exam ID");
            return;
        }

        const exams = getExams();
        const exam = exams.find(e => parseInt(e.id) === parseInt(currentExamId));

        if (!exam) {
            console.log("Exam not found");
            return;
        }

        console.log("=== CURRENT EXAM DEBUG ===");
        console.log("ID:", exam.id);
        console.log("Title:", exam.title);
        console.log("Course Code:", exam.courseCode);
        console.log("Duration:", exam.durationMins);
        console.log("Instructions:", exam.instructions);
        console.log("Questions count:", exam.questions?.length || 0);
        console.log("Published:", exam.published);
        console.log("School Name:", exam.school_name);
        console.log("Faculty:", exam.faculty_name);
        console.log("Department:", exam.department);
        console.log("Semester:", exam.semester);
        console.log("Exam Type:", exam.exam_type);
        console.log("Level:", exam.level);
        console.log("Full exam object:", exam);
        console.log("==========================");

        return exam;
    }

    // Add a keyboard shortcut (Ctrl+Shift+D) to debug
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && e.key === 'D') {
            debugCurrentExam();
            toast('Debug info logged to console');
        }
    });

    function renderDashboard() {
        const exams = getExams();
        const subs = getSubs();
        const published = exams.filter(e => e.published).length;
        const marked = subs.filter(s => s.status === "MARKED").length;

        const ctx = document.getElementById('dashboardChart')?.getContext('2d');
        if (ctx) {
            if (dashboardChart) dashboardChart.destroy();
            dashboardChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Exams', 'Published', 'Submissions', 'Marked'],
                    datasets: [{
                        label: 'Count',
                        data: [exams.length, published, subs.length, marked],
                        backgroundColor: ['#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6'],
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
    }



    // Handle school logo upload and convert to base64
    let uploadedSchoolLogo = null;

    function handleSchoolLogoUpload(event) {
        const file = event.target.files[0];
        if (!file) return;

        // Check file size (max 2MB)
        if (file.size > 2 * 1024 * 1024) {
            toast('❌ Logo too large! Maximum 2MB.', 3000);
            return;
        }

        // Check file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            toast('❌ Only JPG, PNG, GIF, or WEBP images allowed.', 3000);
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            uploadedSchoolLogo = e.target.result; // Store base64 string
            const preview = document.getElementById('schoolLogoPreview');
            const img = document.getElementById('schoolLogoImg');
            if (img) img.src = uploadedSchoolLogo;
            if (preview) preview.style.display = 'block';
            console.log("✅ School logo uploaded and converted to base64");
        };
        reader.onerror = function() {
            toast('❌ Failed to read logo file.', 3000);
        };
        reader.readAsDataURL(file);
    }

    function renderExamsList(params) {
        const exams = getExams();
        let filtered = exams;
        if (params.q) {
            const q = params.q.toLowerCase();
            filtered = exams.filter(e => e.title?.toLowerCase().includes(q) || e.courseCode?.toLowerCase().includes(q));
        }
        renderExamsTable(filtered);
    }

    function closeSubmenuPanel() {
        const panel = document.getElementById('submenuPanel');
        if (panel) panel.classList.remove('open');
        document.querySelectorAll('.nav-icon').forEach(icon => icon.classList.remove('active'));
        currentActiveSubmenu = null;
    }

    function handleSubmenuClick(page) {
        go(page);
        closeSubmenuPanel();
    }

    function handleNavClick(element, page) {
        closeSubmenuPanel();
        document.querySelectorAll('.nav-icon').forEach(icon => icon.classList.remove('active'));
        element.classList.add('active');
        go(page);
    }

    function toggleSubmenuPanel(element) {
        const submenuId = element.getAttribute('data-submenu');
        const submenu = submenusData[submenuId];
        if (!submenu) return;

        const panel = document.getElementById('submenuPanel');
        const content = document.getElementById('submenuContent');
        const title = document.getElementById('submenuTitle');

        if (currentActiveSubmenu === submenuId && panel && panel.classList.contains('open')) {
            closeSubmenuPanel();
            return;
        }

        if (title) title.textContent = submenu.title;
        if (content) {
            content.innerHTML = submenu.items.map(item =>
                `<div class="submenu-item" onclick="handleSubmenuClick('${item.page}')">
                    <i class="${item.icon}"></i>
                    <span>${item.label}</span>
                </div>`
            ).join('');
        }

        if (panel) panel.classList.add('open');
        currentActiveSubmenu = submenuId;

        document.querySelectorAll('.nav-icon').forEach(icon => icon.classList.remove('active'));
        element.classList.add('active');
    }

    function toggleMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.toggle('mobile-open');
        if (overlay) overlay.classList.toggle('active');

        if (sidebar && sidebar.classList.contains('mobile-open')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    function toggleSubmenuPanel(element) {
        const submenuId = element.getAttribute('data-submenu');
        const submenu = submenusData[submenuId];
        if (!submenu) return;

        const panel = document.getElementById('submenuPanel');
        const overlay = document.getElementById('submenuOverlay');
        const content = document.getElementById('submenuContent');
        const title = document.getElementById('submenuTitle');

        // If the same submenu is already open, close it
        if (currentActiveSubmenu === submenuId && panel && panel.classList.contains('open')) {
            closeSubmenuPanel();
            return;
        }

        // Close any open submenu first
        if (panel && panel.classList.contains('open')) {
            closeSubmenuPanel();
        }

        // Set new submenu content
        if (title) title.textContent = submenu.title;
        if (content) {
            content.innerHTML = submenu.items.map(item =>
                `<div class="submenu-item" onclick="handleSubmenuClick('${item.page}')">
                <i class="${item.icon}"></i>
                <span>${item.label}</span>
            </div>`
            ).join('');
        }

        // Open the panel
        if (panel) {
            panel.classList.add('open');
        }
        if (overlay) {
            overlay.classList.add('active');
        }

        currentActiveSubmenu = submenuId;

        // Remove active class from all nav icons, then add to current
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });
        element.classList.add('active');
    }

    function closeSubmenuPanel() {
        const panel = document.getElementById('submenuPanel');
        const overlay = document.getElementById('submenuOverlay');

        if (panel) {
            panel.classList.remove('open');
        }
        if (overlay) {
            overlay.classList.remove('active');
        }

        // Remove active class from all nav icons
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });

        currentActiveSubmenu = null;
    }


    // Close submenu panel when clicking outside
    function closeSubmenuPanel() {
        const panel = document.getElementById('submenuPanel');
        const overlay = document.getElementById('submenuOverlay');
        if (panel) {
            panel.classList.remove('open');
        }
        if (overlay) {
            overlay.classList.remove('active');
        }
        // Remove active class from all nav icons
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });
        currentActiveSubmenu = null;
    }

    // Handle submenu item click
    function handleSubmenuClick(page) {
        // First close the submenu panel
        closeSubmenuPanel();
        // Then navigate to the page
        go(page);
    }

    // Toggle submenu panel
    function toggleSubmenuPanel(element) {
        const submenuId = element.getAttribute('data-submenu');
        const submenu = submenusData[submenuId];
        if (!submenu) return;

        const panel = document.getElementById('submenuPanel');
        const content = document.getElementById('submenuContent');
        const title = document.getElementById('submenuTitle');

        // If the same submenu is already open, close it
        if (currentActiveSubmenu === submenuId && panel && panel.classList.contains('open')) {
            closeSubmenuPanel();
            return;
        }

        // Close any open submenu first
        closeSubmenuPanel();

        // Set new submenu content
        if (title) title.textContent = submenu.title;
        if (content) {
            content.innerHTML = submenu.items.map(item =>
                `<div class="submenu-item" onclick="handleSubmenuClick('${item.page}')">
                <i class="${item.icon}"></i>
                <span>${item.label}</span>
            </div>`
            ).join('');
        }

        // Open the panel
        if (panel) panel.classList.add('open');
        currentActiveSubmenu = submenuId;

        // Remove active class from all nav icons, then add to current
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });
        element.classList.add('active');
    }

    // Handle nav icon click (without submenu)
    function handleNavClick(element, page) {
        // Close any open submenu
        closeSubmenuPanel();

        // Remove active class from all nav icons
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });

        // Add active class to clicked icon
        element.classList.add('active');

        // Navigate to page
        go(page);
    }

    // ============================================
    // 14. THEME FUNCTIONS
    // ============================================

    function loadTheme() {
        const saved = localStorage.getItem('theme');
        if (saved === 'dark') document.body.classList.add('dark');
        else if (saved === 'light') document.body.classList.remove('dark');
    }

    function toggleTheme() {
        document.body.classList.toggle('dark');
        const isDark = document.body.classList.contains('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');

        const icon = document.getElementById('themeIcon');
        if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }

    function updateChartsTheme() {
        if (dashboardChart) dashboardChart.update();
        if (lineChart) lineChart.update();
        if (bellCurve) bellCurve.update();
        if (performanceChart) performanceChart.update();
        if (correlationChart) correlationChart.update();
        if (regressionChart) regressionChart.update();
    }

    // ============================================
    // 15. HEADER NAME
    // ============================================

    function setStaticHeaderText() {
        const el = document.getElementById("headerTyping");
        if (el) el.textContent = "QODA PU";
    }



    function exportJSON() {
        const p = {
            exams: getExams(),
            subs: getSubs(),
            audit: readJSON(K_AUDIT, []),
            students: getStudents()
        };

        const b = new Blob([JSON.stringify(p, null, 2)], {
            type: "application/json"
        });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(b);
        a.download = "qoda-export.json";
        a.click();
        toast("📦 Exported");
    }

    function importJSON() {
        const file = document.getElementById('importDataFile').files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                const data = JSON.parse(e.target.result);
                if (data.exams) writeJSON(K_EXAMS, data.exams);
                if (data.subs) writeJSON(K_SUBS, data.subs);
                if (data.students) writeJSON(K_STUDENTS, data.students);
                toast('✅ Data imported successfully');
                location.reload();
            } catch (err) {
                toast('❌ Invalid import file');
            }
        };
        reader.readAsText(file);
    }

    // ============================================
    // 17. MONITORING & PROCTORING FUNCTIONS
    // ============================================

    const PROCTOR_SUBMITTED_STATUSES = new Set([
        'SUBMITTED',
        'TIMED_OUT',
        'GRADED',
        'MARKED',
        'AUTO_GRADED',
        'MANUALLY_GRADED'
    ]);
    const proctorSubmittedRemovalTimers = {};

    function isSubmittedProctorRecord(record) {
        if (!record) return false;
        const status = String(record.submission_status || '').toUpperCase();
        return parseInt(record.is_submitted || 0) === 1 ||
            parseInt(record.submitted || 0) === 1 ||
            !!record.submitted_at ||
            !!record.submittedAt ||
            PROCTOR_SUBMITTED_STATUSES.has(status);
    }

    function markStudentSubmittedAndRemove(studentId, record = {}) {
        const studentIndex = activeProctoringStudents.findIndex(s => String(s.id) === String(studentId));
        if (studentIndex < 0) return;

        const student = activeProctoringStudents[studentIndex];
        student.isSubmitted = true;
        student.status = 'submitted';
        student.submissionStatus = record.submission_status || 'submitted';
        student.submittedAt = record.submitted_at || record.submittedAt || new Date().toISOString();

        const card = document.querySelector(`.student-proctor-card[data-student-id="${studentId}"]`);
        if (card && card.dataset.submittedRemoving !== '1') {
            card.dataset.submittedRemoving = '1';
            card.style.border = '2px solid #10b981';
            card.style.boxShadow = '0 0 0 4px rgba(16,185,129,0.12)';
            card.innerHTML = `
                <div style="min-height: 240px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px; text-align: center; background: var(--panel); color: var(--text);">
                    <div style="width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #10b981; color: white; font-size: 30px;">
                        <i class="fas fa-check"></i>
                    </div>
                    <strong style="font-size: 18px;">Submitted</strong>
                    <span style="color: var(--muted);">${escapeHTML(student.full_name || 'Student')} has submitted and left proctoring.</span>
                </div>
            `;
        }

        clearTimeout(proctorSubmittedRemovalTimers[studentId]);
        proctorSubmittedRemovalTimers[studentId] = setTimeout(() => {
            activeProctoringStudents = activeProctoringStudents.filter(s => String(s.id) !== String(studentId));
            delete proctorSubmittedRemovalTimers[studentId];
            updateProctoringStats(activeProctoringStudents);
            renderProctoringGrid(activeProctoringStudents);
        }, card ? 1200 : 0);
    }

    // Load proctoring data when exam is selected
    async function loadProctoringData() {
        const examSelect = document.getElementById('proctoringExamSelect');
        const examId = examSelect ? examSelect.value : null;

        if (!examId) {
            document.getElementById('proctoringGrid').innerHTML = `
            <div style="text-align: center; padding: 60px; grid-column: 1/-1;">
                <i class="fas fa-desktop" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <p style="color: var(--muted);">Please select an exam to start proctoring</p>
            </div>
        `;
            return;
        }

        currentProctoringExam = examId;

        try {
            // Get exam details
            const examResult = await apiRequest('get_exam_details', {
                exam_id: examId
            });
            if (!examResult.success) {
                toast('❌ Failed to load exam details');
                return;
            }

            // Get enrolled students for this exam
            const courseCode = examResult.data.course_code;
            const studentsResult = await apiRequest('get_course_students', {
                course_code: courseCode,
                exam_id: examId
            });

            if (studentsResult.success && studentsResult.data) {
                // Get active exam sessions from server
                const sessionsResult = await apiRequest('get_active_sessions', {
                    exam_id: examId
                });
                const activeSessions = sessionsResult.success ? sessionsResult.data : [];

                activeProctoringStudents = studentsResult.data.map(student => {
                    const session = activeSessions.find(s => s.student_id == student.id);
                    return {
                        id: student.id,
                        student_id: student.student_id,
                        full_name: student.full_name,
                        level: student.level,
                        programme: student.programme,
                        screenSharing: session ? parseInt(session.screen_sharing_active || 0) === 1 : false,
                        status: session ? 'active' : 'offline',
                        violationCount: session ? parseInt(session.violations || 0) : 0,
                        lastActivity: session ? session.last_activity : null,
                        snapshot: session ? (session.snapshot || '') : '',
                        snapshotId: session ? (session.snapshot_id || '') : '',
                        lastRenderedSnapshotId: '',
                        latestSnapshotAt: session ? (session.latest_snapshot_at || '') : '',
                        screenLocked: session ? !!parseInt(session.screen_locked || 0) : false,
                        isSubmitted: isSubmittedProctorRecord(session),
                        submissionStatus: session ? (session.submission_status || '') : '',
                        submittedAt: session ? (session.submitted_at || session.submittedAt || '') : ''
                    };
                }).filter(student => !student.isSubmitted);

                updateProctoringStats(activeProctoringStudents);
                renderProctoringGrid(activeProctoringStudents);
            } else {
                renderEmptyProctoringGrid();
            }
        } catch (error) {
            console.error('Error loading proctoring data:', error);
            toast('❌ Error loading proctoring data');
        }
    }

    function renderProctoringGrid(students) {
        const grid = document.getElementById('proctoringGrid');
        if (!grid) return;

        if (!students || students.length === 0) {
            grid.innerHTML = `
            <div style="text-align: center; padding: 60px; grid-column: 1/-1;">
                <i class="fas fa-users" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <p style="color: var(--muted);">No active students in this exam. Submitted students leave the live proctoring grid automatically.</p>
            </div>
        `;
            return;
        }

        grid.innerHTML = students.map(student => {
            const sharingStatus = student.screenSharing;
            const statusColor = sharingStatus ? '#10b981' : '#ef4444';
            const statusText = sharingStatus ? 'Sharing Screen' : 'Not Sharing';
            const activityTime = student.lastActivity ? new Date(student.lastActivity).toLocaleTimeString() :
                'N/A';

            return `
            <div class="student-proctor-card" id="proctorCard_${student.id}" data-student-id="${student.id}" style="
                background: var(--panel);
                border-radius: 16px;
                overflow: hidden;
                border: 2px solid ${sharingStatus ? '#10b981' : '#ef4444'};
                transition: transform 0.2s, box-shadow 0.2s;
                cursor: pointer;
            " onclick="viewFullScreenProctor(${student.id})">
                <!-- Screen Preview -->
                <div class="screen-preview-container" style="
                    position: relative;
                    background: #1e1e1e;
                    aspect-ratio: 16 / 9;
                    min-height: 170px;
                    overflow: hidden;
                ">
                    <div id="screenPreview_${student.id}" style="
                        width: 100%;
                        height: 100%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        background: #1e1e1e;
                    ">
                        <img id="screenImg_${student.id}" src="${student.snapshot ? `data:image/jpeg;base64,${student.snapshot}` : ''}" alt="Screen stream" style="width: 100%; height: 100%; object-fit: contain; ${student.snapshot || sharingStatus ? '' : 'display:none;'}">
                        <div id="screenPlaceholder_${student.id}" style="text-align: center; color: #666; ${student.snapshot || sharingStatus ? 'display:none;' : ''}">
                                <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#14b8a6);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;color:white;font-size:28px;font-weight:800;">
                                    ${escapeHTML((student.full_name || '?').trim().charAt(0).toUpperCase())}
                                </div>
                                <p style="margin:0 0 4px;">Screen not shared</p>
                                <small>${escapeHTML(student.full_name || 'Student')}</small>
                        </div>
                    </div>
                    <div id="liveBadge_${student.id}" class="live-badge" style="position: absolute; top: 10px; right: 10px; background: #ef4444; color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; ${sharingStatus ? '' : 'display:none;'}"><i class="fas fa-circle" style="font-size: 8px; margin-right: 4px;"></i> LIVE</div>
                    ${student.screenLocked ? '<div style="position:absolute;bottom:10px;right:10px;background:#111827;color:#fbbf24;border:1px solid #fbbf24;padding:5px 9px;border-radius:8px;font-size:11px;font-weight:800;"><i class="fas fa-lock"></i> LOCKED</div>' : ''}
                    ${student.violationCount > 0 ? `<div class="violation-badge" style="position: absolute; top: 10px; left: 10px; background: #ef4444; color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;"><i class="fas fa-exclamation-triangle"></i> ${student.violationCount}</div>` : ''}
                </div>
                
                <!-- Student Info -->
                <div style="padding: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div>
                            <strong style="font-size: 16px; color: var(--text);">${escapeHTML(student.full_name)}</strong>
                            <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">ID: ${escapeHTML(student.student_id)}</div>
                        </div>
                        <span class="tag" id="screenStatusTag_${student.id}" style="background: ${statusColor}; color: white;">
                            <i class="fas ${sharingStatus ? 'fa-desktop' : 'fa-eye-slash'}"></i> ${statusText}
                        </span>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 10px; font-size: 12px; color: var(--muted);">
                        <span><i class="fas fa-graduation-cap"></i> Level ${student.level || 'N/A'}</span>
                        <span id="screenFrameTime_${student.id}"><i class="fas fa-clock"></i> Last live frame: ${activityTime}</span>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div style="padding: 12px 15px; border-top: 1px solid var(--border); display: flex; gap: 8px;">
                    <button class="btn small" onclick="event.stopPropagation(); viewFullScreenProctor(${student.id})" style="flex: 1; padding: 6px 12px;">
                        <i class="fas fa-expand"></i> Full Screen
                    </button>
                    <button class="btn warn small" onclick="event.stopPropagation(); sendWarningToStudentById(${student.id})" style="flex: 1; padding: 6px 12px;">
                        <i class="fas fa-exclamation-triangle"></i> Warn
                    </button>
                    <button class="btn danger small" onclick="event.stopPropagation(); lockStudentScreenById(${student.id})" style="flex: 1; padding: 6px 12px;">
                        <i class="fas fa-lock"></i> Lock
                    </button>
                    <button class="btn ok small" onclick="event.stopPropagation(); unlockStudentScreenById(${student.id})" style="flex: 1; padding: 6px 12px;">
                        <i class="fas fa-unlock"></i> Unlock
                    </button>
                    <button class="btn primary small" onclick="event.stopPropagation(); takeSnapshot(${student.id})" style="flex: 1; padding: 6px 12px;">
                        <i class="fas fa-camera"></i> Shot
                    </button>
                </div>
            </div>
        `;
        }).join('');

        startScreenPolling();
        fetchScreenUpdates();
    }

    function renderEmptyProctoringGrid() {
        const grid = document.getElementById('proctoringGrid');
        if (grid) {
            grid.innerHTML = `
            <div style="text-align: center; padding: 60px; grid-column: 1/-1;">
                <i class="fas fa-desktop" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <p style="color: var(--muted);">No students found for this exam</p>
            </div>
        `;
        }
    }

    function startProctoring() {
        if (!currentProctoringExam) {
            toast('❌ Please select an exam first');
            return;
        }

        if (proctoringInterval) {
            clearInterval(proctoringInterval);
        }

        // Poll quickly so submitted students leave the live grid without waiting.
        proctoringInterval = setInterval(() => {
            fetchScreenUpdates();
        }, 800);

        toast('✅ Proctoring started - Monitoring student screens');
        fetchScreenUpdates();
    }

    function stopProctoring() {
        if (proctoringInterval) {
            clearInterval(proctoringInterval);
            proctoringInterval = null;
        }
        toast('⏹️ Proctoring stopped');
    }

    function refreshProctoring() {
        loadProctoringData();
        toast('🔄 Refreshed');
    }

    async function fetchScreenUpdates() {
        if (!currentProctoringExam) return;

        try {
            const result = await apiRequest('get_screen_updates', {
                exam_id: currentProctoringExam
            });

            if (result.success && result.data) {
                // Update each student's screen preview
                for (const update of result.data) {
                    if (isSubmittedProctorRecord(update)) {
                        markStudentSubmittedAndRemove(update.student_id, update);
                        continue;
                    }

                    const student = activeProctoringStudents.find(s => String(s.id) === String(update.student_id));
                    if (student) {
                        const ageMs = update.captured_at ? Date.now() - new Date(update.captured_at).getTime() : 0;
                        student.screenSharing = !!update.snapshot && (!ageMs || ageMs < 12000);
                        student.status = student.screenSharing ? 'active' : student.status;
                        student.lastActivity = update.captured_at || student.lastActivity;
                        student.violationCount = parseInt(update.violations || student.violationCount || 0);
                        student.snapshot = update.snapshot || student.snapshot || '';
                        student.snapshotId = update.snapshot_id || student.snapshotId || '';
                        updateProctorCardLiveState(student);
                    }

                    // Update violation count if needed
                    if (update.violations) {
                        const studentCard = document.querySelector(
                            `.student-proctor-card[data-student-id="${update.student_id}"]`);
                        if (studentCard) {
                            const violationBadge = studentCard.querySelector('.violation-badge');
                            if (violationBadge) {
                                violationBadge.innerHTML =
                                    `<i class="fas fa-exclamation-triangle"></i> ${update.violations}`;
                            } else if (update.violations > 0) {
                                const previewContainer = studentCard.querySelector('.screen-preview-container');
                                if (previewContainer && !previewContainer.querySelector('.violation-badge')) {
                                    const badge = document.createElement('div');
                                    badge.className = 'violation-badge';
                                    badge.style.cssText =
                                        'position: absolute; top: 10px; left: 10px; background: #ef4444; color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;';
                                    badge.innerHTML =
                                        `<i class="fas fa-exclamation-triangle"></i> ${update.violations}`;
                                    previewContainer.appendChild(badge);
                                }
                            }
                        }
                    }
                }

                updateProctoringStats(activeProctoringStudents);
            }
        } catch (error) {
            console.error('Error fetching screen updates:', error);
        }
    }

    function updateProctorCardLiveState(student) {
        const card = document.getElementById(`proctorCard_${student.id}`);
        const statusTag = document.getElementById(`screenStatusTag_${student.id}`);
        const liveBadge = document.getElementById(`liveBadge_${student.id}`);
        const placeholder = document.getElementById(`screenPlaceholder_${student.id}`);
        const screenImg = document.getElementById(`screenImg_${student.id}`);
        const frameTime = document.getElementById(`screenFrameTime_${student.id}`);
        const isSharing = !!student.screenSharing;

        if (card) {
            card.style.border = `2px solid ${isSharing ? '#10b981' : '#ef4444'}`;
        }
        if (statusTag) {
            statusTag.style.background = isSharing ? '#10b981' : '#ef4444';
            statusTag.innerHTML = `<i class="fas ${isSharing ? 'fa-desktop' : 'fa-eye-slash'}"></i> ${isSharing ? 'Sharing Screen' : 'Not Sharing'}`;
        }
        if (liveBadge) {
            liveBadge.style.display = isSharing ? 'block' : 'none';
        }
        if (screenImg && student.snapshot && isSharing) {
            if (String(student.lastRenderedSnapshotId || '') !== String(student.snapshotId || '')) {
                screenImg.src = `data:image/jpeg;base64,${student.snapshot}`;
                student.lastRenderedSnapshotId = student.snapshotId || Date.now();
            }
            screenImg.style.display = 'block';
        } else if (screenImg && !isSharing) {
            screenImg.style.display = 'none';
        }
        if (placeholder) {
            placeholder.style.display = isSharing && student.snapshot ? 'none' : 'block';
            const label = placeholder.querySelector('p');
            if (label) label.textContent = student.snapshot ? 'Live feed paused' : 'Screen not shared';
        }
        if (frameTime) {
            const text = student.lastActivity ? new Date(student.lastActivity).toLocaleTimeString() : 'N/A';
            frameTime.innerHTML = `<i class="fas fa-clock"></i> Last live frame: ${text}`;
        }
    }

    function startScreenPolling() {
        if (proctoringInterval) return;
        proctoringInterval = setInterval(fetchScreenUpdates, 800);
    }

    function updateProctoringStats(students) {
        const writing = students.length;
        const sharing = students.filter(s => s.screenSharing === true).length;
        const notSharing = writing - sharing;
        const violations = students.reduce((sum, s) => sum + (s.violationCount || 0), 0);

        const studentsWritingEl = document.getElementById('studentsWriting');
        const screensSharingEl = document.getElementById('screensSharing');
        const screensNotSharingEl = document.getElementById('screensNotSharing');
        const violationCountEl = document.getElementById('violationCount');

        if (studentsWritingEl) studentsWritingEl.textContent = writing;
        if (screensSharingEl) screensSharingEl.textContent = sharing;
        if (screensNotSharingEl) screensNotSharingEl.textContent = notSharing;
        if (violationCountEl) violationCountEl.textContent = violations;
    }

    async function viewViolationEvidence() {
        if (!currentProctoringExam) {
            toast('Please select an exam first');
            return;
        }
        try {
            const result = await apiRequest('get_violation_evidence', {
                exam_id: currentProctoringExam
            });
            if (!result.success) {
                toast('Failed to load evidence');
                return;
            }
            const rows = result.data || [];
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.display = 'flex';
            modal.innerHTML = `
                <div class="modal-content" style="width:min(1100px,96vw);max-height:90vh;overflow:auto;">
                    <div class="modal-header">
                        <h3><i class="fas fa-folder-open"></i> Violation Evidence</h3>
                        <button class="btn" onclick="this.closest('.modal').remove()"><i class="fas fa-times"></i> Close</button>
                    </div>
                    ${rows.length ? `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;">
                        ${rows.map(row => `
                            <div class="qcard" style="padding:12px;">
                                <div style="font-weight:800;color:var(--text);">${escapeHTML(row.full_name || 'Student')}</div>
                                <div class="small">${escapeHTML(row.student_identifier || '')} | ${escapeHTML(row.event_type || '')}</div>
                                <div class="small">${escapeHTML(row.created_at || '')}</div>
                                <p style="font-size:12px;color:var(--muted);">${escapeHTML(row.details || '')}</p>
                                ${row.snapshot ? `<img src="data:image/jpeg;base64,${row.snapshot}" style="width:100%;border-radius:8px;border:1px solid var(--border);">` : '<div class="empty-state" style="padding:18px;">No snapshot near this event</div>'}
                            </div>
                        `).join('')}
                    </div>` : '<div class="empty-state"><i class="fas fa-check-circle"></i><p>No violations recorded for this exam.</p></div>'}
                </div>
            `;
            document.body.appendChild(modal);
        } catch (error) {
            toast('Network error');
        }
    }

    // View full screen for a specific student
    async function viewFullScreenProctor(studentId) {
        const student = activeProctoringStudents.find(s => s.id == studentId);
        if (!student) {
            toast('❌ Student not found');
            return;
        }

        currentFullScreenStudent = student;

        // Update modal headers
        const nameEl = document.getElementById('fullScreenStudentName');
        const idEl = document.getElementById('fullScreenStudentId');
        const violationTag = document.getElementById('violationTag');

        if (nameEl) nameEl.innerHTML = `<i class="fas fa-user-graduate"></i> ${escapeHTML(student.full_name)}`;
        if (idEl) idEl.textContent =
            `ID: ${student.student_id} | Level: ${student.level || 'N/A'} | Programme: ${student.programme || 'N/A'}`;

        if (violationTag) {
            if (student.violationCount > 0) {
                violationTag.style.display = 'inline-flex';
                violationTag.innerHTML =
                    `<i class="fas fa-exclamation-triangle"></i> Violations: ${student.violationCount}`;
            } else {
                violationTag.style.display = 'none';
            }
        }

        // Show modal
        const modal = document.getElementById('fullScreenProctorModal');
        if (modal) {
            modal.style.display = 'flex';

            // Load the full screen stream
            const contentDiv = document.getElementById('fullScreenContent');
            if (contentDiv) {
                contentDiv.innerHTML = `
                <div style="display: flex; justify-content: center; align-items: center; height: 100%; position: relative;">
                    <div id="fullScreenStreamContainer" style="width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; background: #000;">
                        <img id="fullScreenImg" src="" alt="Screen stream" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    </div>
                    <div class="live-indicator-full" style="position: absolute; top: 20px; right: 20px; background: #ef4444; color: white; padding: 8px 16px; border-radius: 8px; font-weight: 600;">
                        <i class="fas fa-circle" style="font-size: 10px; animation: blink 1s infinite;"></i> LIVE STREAM
                    </div>
                </div>
            `;
            }

            // Start streaming for this student
            startStudentStream(studentId);
        }
    }

    async function startStudentStream(studentId) {
        // Start polling for this specific student's screen
        if (window.studentStreamInterval) {
            clearInterval(window.studentStreamInterval);
        }

        window.studentStreamInterval = setInterval(async () => {
            try {
                const result = await apiRequest('get_student_screen', {
                    student_id: studentId,
                    exam_id: currentProctoringExam
                });

                if (result.success && result.data && result.data.snapshot) {
                    const fullScreenImg = document.getElementById('fullScreenImg');
                    if (fullScreenImg) {
                        fullScreenImg.src = `data:image/jpeg;base64,${result.data.snapshot}`;
                    }

                    // Update violation count if needed
                    if (result.data.violations && currentFullScreenStudent) {
                        const violationTag = document.getElementById('violationTag');
                        if (violationTag) {
                            violationTag.style.display = 'inline-flex';
                            violationTag.innerHTML =
                                `<i class="fas fa-exclamation-triangle"></i> Violations: ${result.data.violations}`;
                        }

                        // Update local student data
                        if (currentFullScreenStudent) {
                            currentFullScreenStudent.violationCount = result.data.violations;
                            const studentIndex = activeProctoringStudents.findIndex(s => s.id ==
                                studentId);
                            if (studentIndex >= 0) {
                                activeProctoringStudents[studentIndex].violationCount = result.data
                                    .violations;
                            }
                        }
                    }
                }
            } catch (error) {
                console.error('Error fetching student screen:', error);
            }
        }, 2000);
    }

    function closeFullScreenProctorModal() {
        const modal = document.getElementById('fullScreenProctorModal');
        if (modal) modal.style.display = 'none';

        // Stop the stream interval
        if (window.studentStreamInterval) {
            clearInterval(window.studentStreamInterval);
            window.studentStreamInterval = null;
        }

        currentFullScreenStudent = null;
    }

    function sendWarningToStudent() {
        if (currentFullScreenStudent) {
            sendWarningToStudentById(currentFullScreenStudent.id);
        }
    }

    async function sendWarningToStudentById(studentId) {
        try {
            const result = await apiRequest('send_warning_to_student', {
                student_id: studentId,
                exam_id: currentProctoringExam,
                warning: "⚠️ Warning: Please follow exam rules. Screen sharing and tab switching are being monitored."
            });

            if (result.success) {
                toast(`⚠️ Warning sent to student`);

                // Log violation
                const student = activeProctoringStudents.find(s => s.id == studentId);
                if (student) {
                    student.violationCount = (student.violationCount || 0) + 1;
                    updateProctoringStats(activeProctoringStudents);
                    renderProctoringGrid(activeProctoringStudents);
                }
            } else {
                toast('❌ Failed to send warning');
            }
        } catch (error) {
            toast('❌ Network error');
        }
    }

    function sendWarningToAllProctoring() {
        if (!currentProctoringExam) {
            toast('❌ No exam selected');
            return;
        }

        if (confirm('Send warning to ALL students currently taking this exam?')) {
            activeProctoringStudents.forEach(student => {
                if (student.status === 'active') {
                    sendWarningToStudentById(student.id);
                }
            });
            toast('⚠️ Warnings sent to all active students');
        }
    }

    async function lockStudentScreenFromModal() {
        if (currentFullScreenStudent) {
            await lockStudentScreenById(currentFullScreenStudent.id);
        }
    }

    async function unlockStudentScreenFromModal() {
        if (currentFullScreenStudent) {
            await unlockStudentScreenById(currentFullScreenStudent.id);
        }
    }

    async function lockStudentScreenById(studentId) {
        try {
            const result = await apiRequest('lock_student_screen', {
                student_id: studentId,
                exam_id: currentProctoringExam
            });

            if (result.success) {
                toast(`🔒 Screen locked for student`);

                // Update UI to show locked status
                const student = activeProctoringStudents.find(s => s.id == studentId);
                if (student) {
                    student.screenLocked = true;
                    renderProctoringGrid(activeProctoringStudents);
                }
            } else {
                toast('❌ Failed to lock screen');
            }
        } catch (error) {
            toast('❌ Network error');
        }
    }

    async function unlockStudentScreenById(studentId) {
        try {
            const result = await apiRequest('unlock_student_screen', {
                student_id: studentId,
                exam_id: currentProctoringExam
            });

            if (result.success) {
                const student = activeProctoringStudents.find(s => s.id == studentId);
                if (student) student.screenLocked = false;
                toast('Screen unlocked for student');
                renderProctoringGrid(activeProctoringStudents);
            } else {
                toast('Failed to unlock screen');
            }
        } catch (error) {
            toast('Network error');
        }
    }

    async function unlockAllScreens() {
        if (!currentProctoringExam) {
            toast('Please select an exam first');
            return;
        }
        if (!(await confirmPopup('Unlock all locked student screens for this exam?', 'Unlock all screens', 'Unlock All'))) return;
        try {
            const result = await apiRequest('unlock_all_screens', {
                exam_id: currentProctoringExam
            });
            if (result.success) {
                activeProctoringStudents.forEach(student => student.screenLocked = false);
                toast(`Unlocked ${result.count || 0} screen(s)`);
                renderProctoringGrid(activeProctoringStudents);
            } else {
                toast('Failed to unlock screens');
            }
        } catch (error) {
            toast('Network error');
        }
    }

    function takeSnapshotFromModal() {
        if (currentFullScreenStudent) {
            takeSnapshot(currentFullScreenStudent.id);
        }
    }

    async function takeSnapshot(studentId) {
        try {
            const result = await apiRequest('take_snapshot', {
                student_id: studentId,
                exam_id: currentProctoringExam
            });

            if (result.success) {
                toast(`📸 Snapshot captured for student - saved to evidence log`);

                // Show snapshot preview
                if (result.data && result.data.snapshot) {
                    const snapshotModal = document.createElement('div');
                    snapshotModal.className = 'modal';
                    snapshotModal.style.display = 'flex';
                    snapshotModal.innerHTML = `
                    <div class="modal-content" style="width: 600px;">
                        <h3><i class="fas fa-camera"></i> Evidence Snapshot</h3>
                        <img src="data:image/jpeg;base64,${result.data.snapshot}" style="width: 100%; border-radius: 8px; margin: 15px 0;">
                        <p style="color: var(--muted); font-size: 12px;">Taken at: ${new Date().toLocaleString()}</p>
                        <div class="modal-actions">
                            <button class="btn" onclick="this.parentElement.parentElement.parentElement.remove()">Close</button>
                        </div>
                    </div>
                `;
                    document.body.appendChild(snapshotModal);

                    setTimeout(() => {
                        if (snapshotModal && snapshotModal.parentElement) {
                            snapshotModal.remove();
                        }
                    }, 5000);
                }
            } else {
                toast('❌ Failed to take snapshot');
            }
        } catch (error) {
            toast('❌ Network error');
        }
    }

    // Add CSS for animations
    const proctoringStyles = document.createElement('style');
    proctoringStyles.textContent = `
    @keyframes blink {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    
    .student-proctor-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.25);
    }
    
    .live-indicator-full i {
        animation: blink 1s infinite;
    }
`;
    document.head.appendChild(proctoringStyles);

    // ============================================
    // BULK STUDENT IMPORT/EXPORT FUNCTIONS
    // ============================================

    // Column headers for import/export (must match)
    const STUDENT_CSV_HEADERS = [
        'Student ID',
        'Full Name',
        'Level',
        'Programme',
        'Course Code',
        'Course Name',
        'Status'
    ];

    // Show import modal
    function showImportModal() {
        const modal = document.getElementById('importModal');
        if (modal) {
            modal.style.display = 'flex';
            document.getElementById('importFile').value = '';
            document.getElementById('importProgress').style.display = 'none';
            document.getElementById('importResults').style.display = 'none';
            document.getElementById('defaultCourseCode').value = '';
            document.getElementById('defaultCourseName').value = '';
        }
    }

    function closeImportModal() {
        const modal = document.getElementById('importModal');
        if (modal) modal.style.display = 'none';
    }

    // Download CSV template with headers
    function downloadImportTemplate() {
        // Create data with headers and example row
        const templateData = [{
                'Student ID': 'STU001',
                'Full Name': 'John Doe',
                'Level': '100',
                'Programme': 'Computer Science',
                'Course Code': 'CS101, CS102',
                'Course Name': 'Introduction to Programming, Data Structures',
                'Status': 'Active'
            },
            {
                'Student ID': 'STU002',
                'Full Name': 'Jane Smith',
                'Level': '200',
                'Programme': 'Information Technology',
                'Course Code': 'IT201',
                'Course Name': 'Web Programming',
                'Status': 'Active'
            }
        ];

        // Convert to worksheet
        const ws = XLSX.utils.json_to_sheet(templateData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Student_Template');

        // Download
        XLSX.writeFile(wb, `student_import_template_${new Date().toISOString().slice(0,10)}.xlsx`);
        toast('📥 Template downloaded. Fill in student data and import back.');
    }

    // Export students with exact same headers as import
    async function exportStudentsToExcelWithTemplate() {
        if (!filteredStudents || filteredStudents.length === 0) {
            toast('❌ No students to export');
            return;
        }

        const exportData = filteredStudents.map((student) => {
            return {
                'Student ID': student.student_id || '',
                'Full Name': student.full_name || '',
                'Level': student.level || '',
                'Programme': student.programme || '',
                'Course Code': student.enrolled_courses || student.course_code || '—',
                'Course Name': student.enrolled_courses_names || student.course_name || '—',
                'Status': student.status || 'Active'
            };
        });

        // Create worksheet
        const ws = XLSX.utils.json_to_sheet(exportData);

        // Auto-size columns
        ws['!cols'] = STUDENT_CSV_HEADERS.map(() => ({
            wch: 20
        }));

        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Students');

        // Download
        XLSX.writeFile(wb, `students_export_${new Date().toISOString().slice(0,19).replace(/:/g, '-')}.xlsx`);
        toast(`📊 Exported ${exportData.length} students with course information`);
    }

    // Import students from file
    async function importStudents() {
        const fileInput = document.getElementById('importFile');
        const file = fileInput.files[0];

        if (!file) {
            toast('❌ Please select a file to import');
            return;
        }

        // Show progress
        const progressDiv = document.getElementById('importProgress');
        const progressBar = document.getElementById('importProgressBar');
        const statusText = document.getElementById('importStatus');
        const resultsDiv = document.getElementById('importResults');

        progressDiv.style.display = 'block';
        resultsDiv.style.display = 'block';
        progressBar.style.width = '10%';
        statusText.innerHTML = 'Reading file...';

        try {
            // Parse file
            let studentsData = [];

            if (file.name.endsWith('.csv')) {
                studentsData = await parseCSV(file);
            } else {
                studentsData = await parseExcel(file);
            }

            progressBar.style.width = '30%';
            statusText.innerHTML = `Parsed ${studentsData.length} records. Validating...`;

            // Validate and prepare data
            const defaultCourseCode = document.getElementById('defaultCourseCode').value.trim();
            const defaultCourseName = document.getElementById('defaultCourseName').value.trim();

            const validStudents = [];
            const errors = [];
            const duplicates = [];

            for (let i = 0; i < studentsData.length; i++) {
                const row = studentsData[i];
                const studentId = (row['Student ID'] || row['student_id'] || row['Student ID'] || '').toString()
                    .trim();
                const fullName = (row['Full Name'] || row['full_name'] || row['Full Name'] || '').toString().trim();
                let level = (row['Level'] || row['level'] || '').toString().trim();
                let programme = (row['Programme'] || row['programme'] || '').toString().trim();
                let courseCode = (row['Course Code'] || row['course_code'] || row['Course Code'] || '').toString()
                    .trim();
                let courseName = (row['Course Name'] || row['course_name'] || row['Course Name'] || '').toString()
                    .trim();
                let status = (row['Status'] || row['status'] || 'Active').toString().trim();

                // Apply defaults if empty
                if (!courseCode && defaultCourseCode) courseCode = defaultCourseCode;
                if (!courseName && defaultCourseName) courseName = defaultCourseName;
                const courseCodes = courseCode.split(/[,;\n]+/).map(v => v.trim()).filter(Boolean);
                const courseNames = courseName.split(/[,;\n]+/).map(v => v.trim()).filter(Boolean);
                const courses = courseCodes.map((code, idx) => ({
                    code,
                    name: courseNames[idx] || courseNames[0] || ''
                }));

                // Validate required fields
                const rowErrors = [];
                if (!studentId) rowErrors.push('Student ID missing');
                if (!fullName) rowErrors.push('Full Name missing');
                if (!level) rowErrors.push('Level missing');
                if (!programme) rowErrors.push('Programme missing');
                if (courses.length === 0) rowErrors.push('Course Code missing');
                if (courses.some(course => !course.name)) rowErrors.push('Every Course Code needs a matching Course Name');

                // Validate level
                const validLevels = ['100', '200', '300', '400', '500'];
                if (level && !validLevels.includes(level.toString())) {
                    rowErrors.push(`Level must be ${validLevels.join(', ')}`);
                }

                // Validate status
                if (status && !['Active', 'Inactive'].includes(status)) {
                    rowErrors.push('Status must be Active or Inactive');
                }

                if (rowErrors.length > 0) {
                    errors.push(`Row ${i + 2}: ${rowErrors.join(', ')}`);
                } else {
                    // Check for duplicate within file
                    const existingInFile = validStudents.filter(s => s.student_id === studentId);
                    if (existingInFile.length > 0) {
                        duplicates.push(`Row ${i + 2}: Student ID ${studentId} already exists in this import file`);
                    } else {
                        validStudents.push({
                            student_id: studentId,
                            full_name: fullName,
                            level: level.toString(),
                            programme: programme,
                            course_code: courseCode,
                            course_name: courseName,
                            courses: courses,
                            status: status
                        });
                    }
                }
            }

            progressBar.style.width = '50%';
            statusText.innerHTML = `Validated ${validStudents.length} students. Importing...`;

            // Show validation errors
            if (errors.length > 0 || duplicates.length > 0) {
                let warningHtml =
                    '<div style="color: #f59e0b;"><i class="fas fa-exclamation-triangle"></i> Validation Warnings:</div><ul style="margin: 5px 0 0 20px;">';
                if (errors.length > 0) {
                    warningHtml +=
                        `<li><strong>Errors (${errors.length}):</strong><ul>${errors.slice(0, 10).map(e => `<li>${e}</li>`).join('')}${errors.length > 10 ? `<li>... and ${errors.length - 10} more</li>` : ''}</ul></li>`;
                }
                if (duplicates.length > 0) {
                    warningHtml +=
                        `<li><strong>Duplicates (${duplicates.length}):</strong><ul>${duplicates.slice(0, 10).map(d => `<li>${d}</li>`).join('')}${duplicates.length > 10 ? `<li>... and ${duplicates.length - 10} more</li>` : ''}</ul></li>`;
                }
                warningHtml += '</ul>';
                resultsDiv.innerHTML = warningHtml;
                resultsDiv.style.background = 'rgba(245, 158, 11, 0.1)';
                resultsDiv.style.border = '1px solid #f59e0b';
            }

            if (validStudents.length === 0) {
                statusText.innerHTML = 'No valid students to import';
                progressBar.style.width = '100%';
                toast('❌ No valid students found in file');
                return;
            }

            // Import students one by one
            let imported = 0;
            let failed = 0;
            const failedStudents = [];

            for (let i = 0; i < validStudents.length; i++) {
                const student = validStudents[i];
                progressBar.style.width = `${50 + (i / validStudents.length) * 40}%`;
                statusText.innerHTML = `Importing ${i + 1} of ${validStudents.length}: ${student.full_name}...`;

                try {
                    const formData = new FormData();
                    formData.append('action', 'add_student');
                    formData.append('student_id', student.student_id);
                    formData.append('full_name', student.full_name);
                    formData.append('level', student.level);
                    formData.append('programme', student.programme);
                    formData.append('status', student.status);
                    formData.append('course_code', student.course_code);
                    formData.append('course_name', student.course_name);
                    formData.append('courses', JSON.stringify(student.courses || []));

                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        imported++;
                    } else {
                        failed++;
                        failedStudents.push(
                            `${student.student_id} - ${student.full_name}: ${result.error || 'Unknown error'}`);
                    }
                } catch (error) {
                    failed++;
                    failedStudents.push(`${student.student_id} - ${student.full_name}: Network error`);
                }
            }

            progressBar.style.width = '100%';
            statusText.innerHTML = `Import completed! Imported: ${imported}, Failed: ${failed}`;

            // Show results
            let resultHtml = `<div style="margin-bottom: 10px;">
            <strong>✅ Successfully imported: ${imported} students</strong><br>
            <strong>❌ Failed: ${failed} students</strong>
        </div>`;

            if (failedStudents.length > 0) {
                resultHtml += `<div style="margin-top: 10px;">
                <strong>Failed students:</strong>
                <ul style="margin: 5px 0 0 20px; max-height: 100px; overflow-y: auto;">
                    ${failedStudents.slice(0, 20).map(f => `<li>${escapeHTML(f)}</li>`).join('')}
                    ${failedStudents.length > 20 ? `<li>... and ${failedStudents.length - 20} more</li>` : ''}
                </ul>
            </div>`;
            }

            resultsDiv.innerHTML = resultHtml;
            resultsDiv.style.background = imported > 0 ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)';
            resultsDiv.style.border = `1px solid ${imported > 0 ? '#10b981' : '#ef4444'}`;

            if (imported > 0) {
                toast(`✅ Successfully imported ${imported} students`);
                await loadStudents();
                loadDashboardStats();

                // Close modal after 3 seconds if no failures
                if (failed === 0) {
                    setTimeout(() => closeImportModal(), 3000);
                }
            } else {
                toast('❌ No students were imported. Check the errors above.');
            }

        } catch (error) {
            console.error('Import error:', error);
            statusText.innerHTML = 'Import failed';
            resultsDiv.innerHTML =
                `<div style="color: #ef4444;"><i class="fas fa-exclamation-circle"></i> Error: ${error.message}</div>`;
            resultsDiv.style.display = 'block';
            toast('❌ Import failed. Please check file format.');
        }
    }

    // Parse CSV file
    function parseCSV(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const text = e.target.result;
                    const lines = text.split('\n');
                    const headers = lines[0].split(',').map(h => h.trim().replace(/"/g, ''));

                    const data = [];
                    for (let i = 1; i < lines.length; i++) {
                        if (!lines[i].trim()) continue;

                        const values = parseCSVLine(lines[i]);
                        const row = {};
                        headers.forEach((header, idx) => {
                            row[header] = values[idx] ? values[idx].trim().replace(/"/g, '') : '';
                        });
                        data.push(row);
                    }
                    resolve(data);
                } catch (error) {
                    reject(error);
                }
            };
            reader.onerror = reject;
            reader.readAsText(file);
        });
    }

    // Parse CSV line (handles quoted fields)
    function parseCSVLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;

        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if (char === '"') {
                inQuotes = !inQuotes;
            } else if (char === ',' && !inQuotes) {
                result.push(current);
                current = '';
            } else {
                current += char;
            }
        }
        result.push(current);
        return result;
    }

    // Parse Excel file
    function parseExcel(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, {
                        type: 'array'
                    });
                    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                    const jsonData = XLSX.utils.sheet_to_json(firstSheet);
                    resolve(jsonData);
                } catch (error) {
                    reject(error);
                }
            };
            reader.onerror = reject;
            reader.readAsArrayBuffer(file);
        });
    }

    // Update renderStudentsTable to include Course Code and Course Name columns
    function renderStudentsTable() {
        const tbody = document.getElementById('studentsTableBody');
        if (!tbody) return;

        if (!filteredStudents || filteredStudents.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="9" style="text-align:center; padding:40px;">👥 No students found. Click "Add New Student" or "Import Students" to get started.</td></tr>';
            return;
        }

        tbody.innerHTML = filteredStudents.map((s, index) => {
            const statusClass = s.status === 'Active' ? 'status-active' : 'status-inactive';

            const courseCode = s.enrolled_courses && s.enrolled_courses !== '—'
                ? s.enrolled_courses
                : (s.course_code || '—');
            const courseName = s.enrolled_courses_names && s.enrolled_courses_names !== '—'
                ? s.enrolled_courses_names
                : (s.course_name || '—');

            return `
            <tr class="student-row">
                <td>${index + 1}</td>
                <td><span class="tag">${escapeHTML(s.student_id || '—')}</span></td>
                <td><b>${escapeHTML(s.full_name || '—')}</b></td>
                <td>${escapeHTML(s.level || '—')}</td>
                <td>${escapeHTML(s.programme || '—')}</td>
                <td><code style="background: var(--bg); padding: 2px 6px; border-radius: 4px; font-size: 12px;">${escapeHTML(courseCode)}</code></td>
                <td><small>${escapeHTML(courseName)}</small></td>
                <td><span class="tag ${statusClass}">${s.status || 'Active'}</span></td>
                <td class="action-buttons" style="white-space: nowrap;">
                    <button class="action-btn" onclick="viewStudentCourses(${s.id})" title="View Courses"><i class="fas fa-book"></i></button>
                    <button class="action-btn" onclick="editStudentById(${s.id})" title="Edit Student"><i class="fas fa-edit"></i></button>
                    <button class="action-btn" onclick="showEnrollModal(${s.id}, '${escapeHTML(s.full_name)}')" title="Enroll in Course"><i class="fas fa-plus-circle"></i></button>
                    <button class="action-btn" onclick="resetStudentPasswordById(${s.id})" title="Reset Password"><i class="fas fa-key"></i></button>
                    <button class="action-btn" onclick="deleteStudentById(${s.id})" title="Delete" style="color: #ef4444;"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        }).join('');
    }



    // ============================================
    // 18. GRADING OPTION TOGGLES
    // ============================================

    function toggleAutoGrading(enabled) {
        updateExamField('auto_grading_enabled', enabled ? 1 : 0);
        setGradingMode(enabled ? 'auto' : 'manual', false);
        toast(enabled ? '✅ Automatic grading enabled' : '❌ Automatic grading disabled');
    }

    function togglePartialGrading(enabled) {
        updateExamField('partial_grading_enabled', enabled ? 1 : 0);
        if (enabled) setGradingMode('hybrid', false);
        toast(enabled ? '✅ Partial grading enabled' : '❌ Partial grading disabled');
    }

    function toggleShowCorrectAnswers(enabled) {
        updateExamField('show_correct_answers', enabled ? 1 : 0);
        toast(enabled ? '✅ Show correct answers after submission enabled' : '❌ Show correct answers disabled');
    }

    function toggleAllowReview(enabled) {
        updateExamField('allow_review', enabled ? 1 : 0);
        toast(enabled ? '✅ Allow review before submission enabled' : '❌ Review before submission disabled');
    }

    function setGradingMode(mode, showToast = true) {
        const cleanMode = ['auto', 'manual', 'hybrid'].includes(mode) ? mode : 'auto';
        updateExamField('gradingMode', cleanMode);
        updateExamField('auto_grading_enabled', cleanMode === 'manual' ? 0 : 1);
        updateExamField('partial_grading_enabled', cleanMode === 'hybrid' ? 1 : 0);

        const gradingModeSelect = document.getElementById('gradingMode');
        if (gradingModeSelect) gradingModeSelect.value = cleanMode;
        const autoGrading = document.getElementById('enableAutoGrading');
        if (autoGrading) autoGrading.checked = cleanMode !== 'manual';
        const partialGrading = document.getElementById('enablePartialGrading');
        if (partialGrading) partialGrading.checked = cleanMode === 'hybrid';

        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx].questions = (exams[idx].questions || []).map(q => ({
                ...q,
                gradingMode: cleanMode
            }));
            setExams(exams);
            renderQuestions();
        }

        if (showToast) toast(`Grading mode set to ${cleanMode}`);
    }

    function updateExamField(field, value) {
        if (!currentExamId) return;
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx][field] = value;
            setExams(exams);
            console.log(`Updated ${field} = ${value}`);
            if (['questionsToAnswer', 'marks', 'questions'].includes(field)) {
                refreshQuestionAnswerRuleHint();
            }
        }
    }

    function applySearch(route) {
        const searchInput = document.getElementById("examsSearch");
        const q = searchInput ? searchInput.value.trim() : '';
        go(route, q ? {
            q
        } : {});
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.style.display = 'none';
    }

    function previewSchoolLogo(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('schoolLogoPreview');
                const img = document.getElementById('schoolLogoImg');
                if (img) img.src = e.target.result;
                if (preview) preview.style.display = 'block';
                updateExamField('school_logo', e.target.result);
            };
            reader.readAsDataURL(file);
        }
    }

    function updateStudentCredentials() {
        const idInput = document.getElementById('studentId');
        const usernameInput = document.getElementById('studentUsername');
        const passwordInput = document.getElementById('studentPassword');

        if (idInput && usernameInput && passwordInput) {
            const id = idInput.value;
            usernameInput.value = id;
            passwordInput.value = id;
        }
    }

    function addMarkingScheme(type) {
        const scheme = {
            id: Date.now() + Math.random(),
            text: ""
        };

        if (type === 'essay') {
            essaySchemes.push(scheme);
            renderMarkingSchemes('essay');
        } else if (type === 'coding') {
            codingSchemes.push(scheme);
            renderMarkingSchemes('coding');
        } else if (type === 'short') {
            shortSchemes.push(scheme);
            renderMarkingSchemes('short');
        }
    }

    function removeMarkingScheme(type, id) {
        if (type === 'essay') {
            essaySchemes = essaySchemes.filter(s => s.id !== id);
            renderMarkingSchemes('essay');
        } else if (type === 'coding') {
            codingSchemes = codingSchemes.filter(s => s.id !== id);
            renderMarkingSchemes('coding');
        } else if (type === 'short') {
            shortSchemes = shortSchemes.filter(s => s.id !== id);
            renderMarkingSchemes('short');
        }
    }

    function updateMarkingScheme(type, id, value) {
        if (type === 'essay') {
            const scheme = essaySchemes.find(s => s.id === id);
            if (scheme) scheme.text = value;
        } else if (type === 'coding') {
            const scheme = codingSchemes.find(s => s.id === id);
            if (scheme) scheme.text = value;
        } else if (type === 'short') {
            const scheme = shortSchemes.find(s => s.id === id);
            if (scheme) scheme.text = value;
        }
    }

    function renderMarkingSchemes(type) {
        const container = document.getElementById(`${type}SchemesContainer`);
        if (!container) return;

        const schemes = type === 'essay' ? essaySchemes : type === 'coding' ? codingSchemes : shortSchemes;

        container.innerHTML = schemes.map(s =>
            `<div class="marking-scheme-item">
                <div class="marking-scheme-header">
                    <span>Scheme ${schemes.indexOf(s) + 1}</span>
                    <button class="btn danger small" onclick="removeMarkingScheme('${type}', ${s.id})">✖</button>
                </div>
                <textarea class="marking-scheme-text" oninput="updateMarkingScheme('${type}', ${s.id}, this.value)" rows="2" placeholder="Enter marking scheme...">${escapeHTML(s.text)}</textarea>
            </div>`
        ).join('');
    }

    // ============================================
    // 19. INITIALIZATION & EVENT LISTENERS
    // ============================================

    document.addEventListener('DOMContentLoaded', async function() {
        loadTheme();
        loadProfile();
        loadDashboardStats();

        await loadExamsList();

        loadStudents();
        loadSubmissions();
        initResize();
        setStaticHeaderText();
        startAutoSave();

        // Inside DOMContentLoaded, after loading submissions, add:
        await loadSubmissions();
        populateDashboardCourseSelector();
        initDashboard();

        // In the navigation handler, when going to results:
        if (route === "results") {
            initResultsPage();
        }

        const savedView = sessionStorage.getItem('currentView');
        const savedExamId = sessionStorage.getItem('currentExamId');
        const savedParams = JSON.parse(sessionStorage.getItem('currentRouteParams') || '{}');

        console.log("Restoring view:", savedView, "Exam ID:", savedExamId);

        if (savedView === 'builder' && (savedExamId || savedParams.id)) {
            const examId = savedExamId || savedParams.id;
            setTimeout(async () => {
                console.log("Restoring builder with exam ID:", examId);
                await openBuilder(examId);
            }, 300);
        } else if (savedView && routes.includes(savedView)) {
            go(savedView, savedParams);
        } else {
            go('dashboard');
        }

        setInterval(loadDashboardStats, 30000);
        setInterval(() => {
            if (document.getElementById('view-submissions')?.classList.contains('active')) {
                loadSubmissions();
            }
            if (document.getElementById('view-results')?.classList.contains('active')) {
                renderResults();
            }
        }, 10000);

        window.addEventListener('beforeunload', function() {
            if (currentExamId) {
                saveExamToDatabase();
                sessionStorage.setItem('currentExamId', currentExamId);
            }
            sessionStorage.setItem('currentView', routeState.route);
            sessionStorage.setItem('currentRouteParams', JSON.stringify(routeState.params));
        });

        document.addEventListener('click', function(e) {
            const panel = document.getElementById('submenuPanel');
            if (!panel) return;
            const isClickInside = panel.contains(e.target);
            const isClickOnNavIcon = e.target.closest('.nav-icon');
            if (panel.classList.contains('open') && !isClickInside && !isClickOnNavIcon) {
                closeSubmenuPanel();
            }
        });

        // Initialize real-time form saving
        initRealTimeFormSaving();

        // Add a save button or auto-save on page unload
        window.addEventListener('beforeunload', function() {
            if (currentExamId && document.getElementById('view-builder')?.classList.contains(
                    'active')) {
                saveAllFormDataToExam();
                console.log("Auto-saved form data before page unload");
            }
        });

        // Close submenu when clicking outside
        document.addEventListener('click', function(e) {
            const panel = document.getElementById('submenuPanel');
            const sidebar = document.getElementById('sidebar');

            if (!panel) return;

            // Check if click is outside the submenu panel and outside the nav icons that have submenus
            const isClickInsidePanel = panel.contains(e.target);
            const isClickOnNavIcon = e.target.closest('.nav-icon');
            const isClickOnSubmenuItem = e.target.closest('.submenu-item');

            // If clicking on a submenu item, handle it in the item's onclick
            if (isClickOnSubmenuItem) {
                return;
            }

            // If clicking outside panel and not on a nav icon, close the panel
            if (panel.classList.contains('open') && !isClickInsidePanel && !isClickOnNavIcon) {
                closeSubmenuPanel();
            }
        });

        // Also close submenu when clicking on the main content area
        document.querySelector('.main')?.addEventListener('click', function() {
            closeSubmenuPanel();
        });

        // Close submenu on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSubmenuPanel();
            }
        });


        // Add Ctrl+S shortcut to save
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (currentExamId) {
                    saveAllFormDataToExam();
                    toast('✅ Exam data saved');
                }
            }
        });

        const studentSearch = document.getElementById('studentSearchInput');
        const levelFilter = document.getElementById('levelFilter');
        const programmeFilter = document.getElementById('programmeFilter');
        const statusFilter = document.getElementById('statusFilter');
        const studentDetailsSearch = document.getElementById('studentDetailsSearchInput');
        const studentDetailsLevelFilter = document.getElementById('studentDetailsLevelFilter');
        const studentDetailsProgrammeFilter = document.getElementById('studentDetailsProgrammeFilter');
        const studentDetailsStatusFilter = document.getElementById('studentDetailsStatusFilter');

        if (studentSearch) studentSearch.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
        if (levelFilter) levelFilter.addEventListener('change', applyFilters);
        if (programmeFilter) programmeFilter.addEventListener('change', applyFilters);
        if (statusFilter) statusFilter.addEventListener('change', applyFilters);

        if (studentDetailsSearch) studentDetailsSearch.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyStudentDetailsFilters();
        });
        if (studentDetailsLevelFilter) studentDetailsLevelFilter.addEventListener('change',
            applyStudentDetailsFilters);
        if (studentDetailsProgrammeFilter) studentDetailsProgrammeFilter.addEventListener('change',
            applyStudentDetailsFilters);
        if (studentDetailsStatusFilter) studentDetailsStatusFilter.addEventListener('change',
            applyStudentDetailsFilters);

        const studentIdInput = document.getElementById('studentId');
        if (studentIdInput) {
            studentIdInput.addEventListener('input', updateStudentCredentials);
        }

        // Focus on the first field when builder is opened
        function focusFirstField() {
            const titleField = document.getElementById('bTitle');
            if (titleField) {
                setTimeout(() => {
                    titleField.focus();
                    titleField.placeholder =
                        'e.g., Final Examination - Introduction to Programming';
                }, 100);
            }
        }

        // Call this when switching to builder view
        // Add this inside your go() function when route === 'builder'
        if (route === 'builder') {
            setTimeout(focusFirstField, 200);
        }
    });

    window.addEventListener("hashchange", parseHash);
    parseHash();

    // ========== ADD FORM FIELD EVENT LISTENERS ==========
    function initializeFormListeners() {
        console.log("Initializing form field listeners...");

        // Title field
        const titleField = document.getElementById('bTitle');
        if (titleField) {
            titleField.addEventListener('change', function() {
                updateExamField('title', this.value);
            });
            titleField.addEventListener('input', function() {
                updateExamField('title', this.value);
            });
        }

        // Course code field
        const courseField = document.getElementById('bCode');
        if (courseField) {
            courseField.addEventListener('change', function() {
                updateExamField('courseCode', this.value);
            });
            courseField.addEventListener('input', function() {
                updateExamField('courseCode', this.value);
            });
        }

        // Duration field
        const durationField = document.getElementById('bDuration');
        if (durationField) {
            durationField.addEventListener('change', function() {
                updateExamField('durationMins', parseInt(this.value) || 180);
                syncEndTimeFromDuration();
            });
            durationField.addEventListener('input', syncEndTimeFromDuration);
        }

        // Instructions field
        const instructionsField = document.getElementById('bInstructions');
        if (instructionsField) {
            instructionsField.addEventListener('change', function() {
                updateExamField('instructions', this.value);
            });
        }

        // School Name field
        const schoolNameField = document.getElementById('schoolName');
        if (schoolNameField) {
            schoolNameField.addEventListener('change', function() {
                updateExamField('school_name', this.value);
            });
            schoolNameField.addEventListener('input', function() {
                updateExamField('school_name', this.value);
            });
        }

        // Faculty Name field
        const facultyField = document.getElementById('facultyName');
        if (facultyField) {
            facultyField.addEventListener('change', function() {
                updateExamField('faculty_name', this.value);
            });
            facultyField.addEventListener('input', function() {
                updateExamField('faculty_name', this.value);
            });
        }

        // Department field
        const deptField = document.getElementById('department');
        if (deptField) {
            deptField.addEventListener('change', function() {
                updateExamField('department', this.value);
            });
            deptField.addEventListener('input', function() {
                updateExamField('department', this.value);
            });
        }

        // Semester dropdown
        const semesterField = document.getElementById('semester');
        if (semesterField) {
            semesterField.addEventListener('change', function() {
                updateExamField('semester', this.value);
            });
        }

        // Exam Type dropdown
        const examTypeField = document.getElementById('examType');
        if (examTypeField) {
            examTypeField.addEventListener('change', function() {
                updateExamField('exam_type', this.value);
            });
        }

        // School Type dropdown
        const schoolTypeField = document.getElementById('schoolType');
        if (schoolTypeField) {
            schoolTypeField.addEventListener('change', function() {
                updateExamField('school_type', this.value);
            });
        }

        // Level dropdown
        const levelField = document.getElementById('examLevel');
        if (levelField) {
            levelField.addEventListener('change', function() {
                updateExamField('level', this.value);
            });
        }

        // Start date time field
        const startField = document.getElementById('bStartAt');
        if (startField) {
            startField.addEventListener('change', function() {
                updateExamField('startAtISO', this.value);
                syncDurationFromStartEnd();
            });
        }

        const endField = document.getElementById('bEndAt');
        if (endField) {
            endField.addEventListener('change', function() {
                updateExamField('endAtISO', this.value);
                syncDurationFromStartEnd();
            });
        }

        // Exam password field
        const passwordField = document.getElementById('examPassword');
        if (passwordField) {
            passwordField.addEventListener('change', function() {
                updateExamField('exam_password', this.value);
            });
        }

        // Questions to answer field
        const qtaField = document.getElementById('bQuestionsToAnswer');
        if (qtaField) {
            qtaField.addEventListener('change', function() {
                updateExamField('questionsToAnswer', parseInt(this.value) || 0);
            });
            qtaField.addEventListener('input', function() {
                updateExamField('questionsToAnswer', parseInt(this.value) || 0);
            });
        }

        // Exam Code field
        const examCodeField = document.getElementById('examCode');
        if (examCodeField) {
            examCodeField.addEventListener('change', function() {
                updateExamField('exam_code', this.value);
            });
        }

        // Grading option checkboxes
        const autoGradingCheckbox = document.getElementById('enableAutoGrading');
        if (autoGradingCheckbox) {
            autoGradingCheckbox.addEventListener('change', function() {
                updateExamField('auto_grading_enabled', this.checked ? 1 : 0);
            });
        }

        const partialGradingCheckbox = document.getElementById('enablePartialGrading');
        if (partialGradingCheckbox) {
            partialGradingCheckbox.addEventListener('change', function() {
                updateExamField('partial_grading_enabled', this.checked ? 1 : 0);
            });
        }

        const showAnswersCheckbox = document.getElementById('showCorrectAnswers');
        if (showAnswersCheckbox) {
            showAnswersCheckbox.addEventListener('change', function() {
                updateExamField('show_correct_answers', this.checked ? 1 : 0);
            });
        }

        const allowReviewCheckbox = document.getElementById('allowReview');
        if (allowReviewCheckbox) {
            allowReviewCheckbox.addEventListener('change', function() {
                updateExamField('allow_review', this.checked ? 1 : 0);
            });
        }
    }

    // Call this after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeFormListeners);
    } else {
        initializeFormListeners();
    }

    function parseHash() {
        const h = (window.location.hash || "").replace("#", "");
        if (!h) return go("dashboard");
        const [r, q] = h.split("?");
        const params = Object.fromEntries(new URLSearchParams(q || ""));
        go(r || "dashboard", params);
    }

    function initResize() {
        const handle = document.getElementById('resizeHandle');
        const sidebar = document.getElementById('sidebar');
        const layout = document.querySelector('.layout');

        if (!handle) return;

        handle.addEventListener('mousedown', (e) => {
            isResizing = true;
            document.body.style.cursor = 'col-resize';
        });

        document.addEventListener('mousemove', (e) => {
            if (!isResizing) return;
            const newWidth = e.clientX;
            if (newWidth >= 200 && newWidth <= 400) {
                sidebarWidth = newWidth;
                if (layout) layout.style.gridTemplateColumns = `${newWidth}px 1fr`;
                if (handle) handle.style.left = `${newWidth}px`;
            }
        });

        document.addEventListener('mouseup', () => {
            isResizing = false;
            document.body.style.cursor = 'default';
        });
    }

    function logout() {
        if (confirm('Are you sure you want to logout?')) {
            if (currentExamId) {
                saveExamToDatabase();
            }
            localStorage.clear();
            sessionStorage.clear();
            window.location.href = 'logout.php';
        }
    }

    function openMarkingModal(submissionId) {
        const subs = getSubs();
        const submission = subs.find(s => s.id === submissionId);
        if (!submission) return;

        const exam = findExam(submission.examId);
        if (!exam) return;

        const student = getStudents().find(s => s.id === submission.studentId);

        let contentHtml = `
            <div style="padding: 10px;">
                <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                    <h3 style="margin: 0 0 10px 0; color: var(--text);">
                        <i class="fas fa-user-graduate"></i> ${escapeHTML(student?.fullName || submission.studentId)}
                    </h3>
                    <p style="margin: 5px 0; color: var(--muted);">
                        <i class="fas fa-file"></i> ${escapeHTML(exam.title)} | 
                        <i class="fas fa-clock"></i> Submitted: ${new Date(submission.submittedAtISO).toLocaleString()}
                    </p>
                </div>
                
                <div id="gradingQuestions">
        `;

        exam.questions.forEach(question => {
            const answer = submission.items?.find(item => item.qId === question.id);
            const studentAnswer = answer?.answerText || '';

            if (question.type === 'code') {
                contentHtml += renderCodingGrading(question, studentAnswer, answer?.manualScore || 0);
            }
        });

        contentHtml += `
                </div>
                
                <div style="margin-top: 20px; padding-top: 15px; border-top: 2px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                    <button class="btn" onclick="closeMarkingModal()">Cancel</button>
                    <button class="btn primary" onclick="saveAllMarks('${submissionId}')">
                        <i class="fas fa-save"></i> Save Marks
                    </button>
                    <button class="btn ok" onclick="finalizeGrading('${submissionId}')">
                        <i class="fas fa-check-circle"></i> Finalize & Publish
                    </button>
                </div>
            </div>
        `;

        const markingContent = document.getElementById('markingContent');
        if (markingContent) markingContent.innerHTML = contentHtml;

        const modal = document.getElementById('markingModal');
        if (modal) modal.style.display = 'flex';
    }

    function renderCodingGrading(question, studentAnswer, currentMarks = 0) {
        let html = `
            <div style="padding: 20px; background: var(--panel); border-radius: 12px; margin-bottom: 20px;">
                <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h4 style="color: var(--text); margin: 0;">
                            <i class="fas fa-code" style="color: #10b981;"></i> 
                            Coding Question (${question.marks} marks)
                        </h4>
                        <span class="tag">ID: ${question.id}</span>
                    </div>
                    <p style="color: var(--text); font-size: 15px; line-height: 1.6;">${escapeHTML(question.text || '')}</p>
                    <div style="margin-top: 10px;">
                        <span class="tag" style="background: #10b981; color: white;">Language: ${escapeHTML(question.language || 'Python')}</span>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text);">
                        <i class="fas fa-user-graduate"></i> Student's Code:
                    </label>
                    <div style="padding: 16px; background: #1e1e1e; border-radius: 10px; border: 1px solid var(--border);">
                        <pre style="margin: 0; color: #d4d4d4; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.5; white-space: pre-wrap; overflow-x: auto;">${escapeHTML(studentAnswer) || '<em style="color: var(--muted);">No code provided</em>'}</pre>
                    </div>
                </div>`;

        if (question.expectedOutput) {
            html += `
                <div style="margin-bottom: 20px; padding: 14px; background: rgba(16, 185, 129, 0.08); border-radius: 8px; border-left: 4px solid #10b981;">
                    <div style="font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #10b981;">
                        <i class="fas fa-check-circle"></i> Expected Output:
                    </div>
                    <pre style="margin: 0; color: var(--text); font-family: monospace; white-space: pre-wrap;">${escapeHTML(question.expectedOutput)}</pre>
                </div>`;
        }

        if (question.testCases && question.testCases.length > 0) {
            html += `
                <div style="margin-bottom: 20px;">
                    <div style="font-size: 13px; font-weight: 600; margin-bottom: 12px; color: var(--text);">
                        <i class="fas fa-vial"></i> Test Cases:
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        ${question.testCases.map((tc, idx) => `
                            <div style="padding: 10px; background: var(--bg); border-radius: 8px;">
                                <div style="font-size: 12px; color: var(--muted);">Test Case ${idx + 1}</div>
                                <div><strong>Input:</strong> <code>${escapeHTML(tc.input)}</code></div>
                                <div><strong>Expected:</strong> <code>${escapeHTML(tc.expected)}</code></div>
                                <div><strong>Marks:</strong> ${tc.marks}</div>
                            </div>
                        `).join('')}
                    </div>
                </div>`;
        }

        html += `
                <div style="margin-top: 20px; padding: 16px; background: var(--bg); border-radius: 10px;">
                    <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <label style="font-size: 14px; font-weight: 600; color: var(--text);">
                                <i class="fas fa-star" style="color: #f59e0b;"></i> Marks Awarded:
                            </label>
                            <input type="number" id="codingMarks_${question.id}" 
                                value="${currentMarks}" min="0" max="${question.marks}" step="0.5"
                                style="width: 100px; padding: 10px; border-radius: 8px; border: 2px solid var(--border); 
                                background: var(--input-bg); color: var(--text); font-size: 14px; font-weight: 600; text-align: center;"
                                onchange="updateCodingMarks('${question.id}', this.value)">
                            <span style="color: var(--muted);">/ ${question.marks}</span>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text);">
                        <i class="fas fa-comment"></i> Feedback for Student:
                    </label>
                    <textarea id="codingFeedback_${question.id}" rows="3" 
                        style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid var(--border); 
                        background: var(--input-bg); color: var(--text); font-size: 13px; resize: vertical;"
                        placeholder="Provide feedback to the student..."></textarea>
                </div>
            </div>
        `;

        return html;
    }

    function updateCodingMarks(questionId, value) {
        const marks = parseFloat(value) || 0;
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        if (marks > q.marks) {
            toast(`⚠️ Marks cannot exceed ${q.marks}`);
            document.getElementById(`codingMarks_${questionId}`).value = q.marks;
        }
    }

    async function saveAllMarks(submissionId) {
        const subs = getSubs();
        const submission = subs.find(s => s.id === submissionId);
        if (!submission) return;

        const exam = findExam(submission.examId);
        if (!exam) return;

        let totalScore = 0;
        let totalMarks = 0;

        exam.questions.forEach(question => {
            if (question.type === 'code') {
                const marksInput = document.getElementById(`codingMarks_${question.id}`);
                const feedbackInput = document.getElementById(`codingFeedback_${question.id}`);

                const marks = parseFloat(marksInput?.value) || 0;
                const feedback = feedbackInput?.value || '';

                const answerItem = submission.items?.find(item => item.qId === question.id);
                if (answerItem) {
                    answerItem.manualScore = marks;
                    answerItem.feedback = feedback;
                }

                totalScore += marks;
                totalMarks += question.marks;
            }
        });

        submission.scoreManual = totalScore;
        submission.status = totalScore > 0 ? 'MARKED' : 'PENDING';

        setSubs(subs);

        toast(`✅ Marks saved! Total: ${totalScore}/${totalMarks}`);
    }

    async function finalizeGrading(submissionId) {
        await saveAllMarks(submissionId);

        const subs = getSubs();
        const submission = subs.find(s => s.id === submissionId);
        if (submission) {
            submission.status = 'MARKED';
            submission.markedAt = new Date().toISOString();
            setSubs(subs);
        }

        toast('🚀 Grading finalized and published to student');
        closeMarkingModal();
        loadSubmissions();
    }

    function publishSubmission(submissionId) {
        toast(`Publishing submission ${submissionId}`);
    }

    function captureAllExamFormData() {
        console.log("===== CAPTURING ALL FORM DATA =====");

        const formData = {
            title: document.getElementById('bTitle')?.value || '',
            courseCode: document.getElementById('bCode')?.value || '',
            durationMins: parseInt(document.getElementById('bDuration')?.value) || 180,
            instructions: document.getElementById('bInstructions')?.value || '',
            startAtISO: document.getElementById('bStartAt')?.value || '',
            questionsToAnswer: parseInt(document.getElementById('bQuestionsToAnswer')?.value) || 0,
            exam_password: document.getElementById('examPassword')?.value || '',
            school_name: document.getElementById('schoolName')?.value || '',
            faculty_name: document.getElementById('facultyName')?.value || '',
            department: document.getElementById('department')?.value || '',
            school_type: document.getElementById('schoolType')?.value || '',
            semester: document.getElementById('semester')?.value || '',
            exam_type: document.getElementById('examType')?.value || '',
            level: document.getElementById('examLevel')?.value || '',
            exam_code: document.getElementById('examCode')?.value || '',
            auto_grading_enabled: document.getElementById('enableAutoGrading')?.checked ? 1 : 0,
            partial_grading_enabled: document.getElementById('enablePartialGrading')?.checked ? 1 : 0,
            show_correct_answers: document.getElementById('showCorrectAnswers')?.checked ? 1 : 0,
            allow_review: document.getElementById('allowReview')?.checked !== false ? 1 : 0,
            questions: []
        };

        const exams = getExams();
        const exam = exams.find(e => parseInt(e.id) === parseInt(currentExamId));

        if (exam && exam.questions) {
            formData.questions = exam.questions;
        }

        console.log("Captured form data:", formData);
        return formData;
    }

    function initRealTimeFormSaving() {
        console.log("Initializing real-time form saving...");

        const fieldsToMonitor = [{
                id: 'bTitle',
                property: 'title'
            },
            {
                id: 'bCode',
                property: 'courseCode'
            },
            {
                id: 'bDuration',
                property: 'durationMins',
                parser: (v) => parseInt(v) || 180
            },
            {
                id: 'bInstructions',
                property: 'instructions'
            },
            {
                id: 'bStartAt',
                property: 'startAtISO'
            },
            {
                id: 'bQuestionsToAnswer',
                property: 'questionsToAnswer',
                parser: (v) => parseInt(v) || 0
            },
            {
                id: 'examPassword',
                property: 'exam_password'
            },
            {
                id: 'schoolName',
                property: 'school_name'
            },
            {
                id: 'facultyName',
                property: 'faculty_name'
            },
            {
                id: 'department',
                property: 'department'
            },
            {
                id: 'semester',
                property: 'semester'
            },
            {
                id: 'examType',
                property: 'exam_type'
            },
            {
                id: 'schoolType',
                property: 'school_type'
            },
            {
                id: 'examLevel',
                property: 'level'
            },
            {
                id: 'examCode',
                property: 'exam_code'
            }
        ];

        fieldsToMonitor.forEach(field => {
            const element = document.getElementById(field.id);
            if (element) {
                element.addEventListener('change', function() {
                    let value = this.value;
                    if (field.parser) value = field.parser(value);
                    updateExamField(field.property, value);
                });
                if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                    element.addEventListener('input', function() {
                        let value = this.value;
                        if (field.parser) value = field.parser(value);
                        updateExamField(field.property, value);
                    });
                }
            } else {
                console.warn(`Field ${field.id} not found`);
            }
        });

        const checkboxesToMonitor = [{
                id: 'enableAutoGrading',
                property: 'auto_grading_enabled'
            },
            {
                id: 'enablePartialGrading',
                property: 'partial_grading_enabled'
            },
            {
                id: 'showCorrectAnswers',
                property: 'show_correct_answers'
            },
            {
                id: 'allowReview',
                property: 'allow_review'
            }
        ];

        checkboxesToMonitor.forEach(field => {
            const element = document.getElementById(field.id);
            if (element) {
                element.addEventListener('change', function() {
                    updateExamField(field.property, this.checked ? 1 : 0);
                });
            }
        });

        console.log("✅ Real-time form saving initialized");
    }

    function saveAllFormDataToExam() {
        if (!currentExamId) return false;

        const formData = captureAllExamFormData();
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));

        if (idx < 0) return false;

        exams[idx].title = formData.title;
        exams[idx].courseCode = formData.courseCode;
        exams[idx].durationMins = formData.durationMins;
        exams[idx].instructions = formData.instructions;
        exams[idx].startAtISO = formData.startAtISO;
        exams[idx].questionsToAnswer = formData.questionsToAnswer;
        exams[idx].exam_password = formData.exam_password;
        exams[idx].school_name = formData.school_name;
        exams[idx].faculty_name = formData.faculty_name;
        exams[idx].department = formData.department;
        exams[idx].school_type = formData.school_type;
        exams[idx].semester = formData.semester;
        exams[idx].exam_type = formData.exam_type;
        exams[idx].level = formData.level;
        exams[idx].exam_code = formData.exam_code;
        exams[idx].auto_grading_enabled = formData.auto_grading_enabled;
        exams[idx].partial_grading_enabled = formData.partial_grading_enabled;
        exams[idx].show_correct_answers = formData.show_correct_answers;
        exams[idx].allow_review = formData.allow_review;

        if (formData.questions.length > 0) exams[idx].questions = formData.questions;

        setExams(exams);
        console.log("✅ All form data saved to exam object");
        return true;
    }
    // Helper function to round to nearest whole number
    function roundToWholeNumber(value) {
        return Math.round(parseFloat(value) || 0);
    }
    </script>

    <!-- Course Stats Modal -->
    <div id="courseStatsModal" class="modal" style="display:none;">
        <div class="modal-content course-stats-modal">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3><i class="fas fa-chart-line"></i> Course Performance Summary</h3>
                <button onclick="closeCourseStatsModal()"
                    style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
            </div>
            <div id="courseStatsContent"></div>
            <div class="modal-actions">
                <button class="btn" onclick="closeCourseStatsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Submissions Stats Modal -->
    <div id="submissionsStatsModal" class="modal" style="display:none;">
        <div class="modal-content course-stats-modal">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3><i class="fas fa-check-circle"></i> Submissions Summary</h3>
                <button onclick="closeSubmissionsStatsModal()"
                    style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
            </div>
            <div id="submissionsStatsContent"></div>
            <div class="modal-actions">
                <button class="btn" onclick="closeSubmissionsStatsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Average Score Modal -->
    <div id="averageScoreModal" class="modal" style="display:none;">
        <div class="modal-content course-stats-modal">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3><i class="fas fa-chart-line"></i> Course Average Scores & Pass Rates</h3>
                <button onclick="closeAverageScoreModal()"
                    style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
            </div>
            <div id="averageScoreContent"></div>
            <div class="modal-actions">
                <button class="btn" onclick="closeAverageScoreModal()">Close</button>
            </div>
        </div>
    </div>
</body>

</html>
