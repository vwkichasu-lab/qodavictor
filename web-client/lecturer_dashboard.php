<?php

// ========== FIXED: lecturer_dashboard.php ==========

// First, require database config (this creates $pdo)
// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../backend-php/lib/grade_storage.php';
require_once '../backend-php/lib/socket_auth.php';

// Helper function to round to nearest whole number
function roundToInt($value)
{
    return (int)round(floatval($value));
}

function qodaGradeInfo(float $score): array
{
    if ($score >= 80) return ['grade' => 'A', 'gradePoint' => 4.0];
    if ($score >= 75) return ['grade' => 'B+', 'gradePoint' => 3.5];
    if ($score >= 70) return ['grade' => 'B', 'gradePoint' => 3.0];
    if ($score >= 65) return ['grade' => 'C+', 'gradePoint' => 2.5];
    if ($score >= 60) return ['grade' => 'C', 'gradePoint' => 2.0];
    if ($score >= 55) return ['grade' => 'D+', 'gradePoint' => 1.5];
    if ($score >= 50) return ['grade' => 'D', 'gradePoint' => 1.0];
    return ['grade' => 'E', 'gradePoint' => 0.0];
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

function qodaEnsureExamQuestionDetailsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_question_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_id INT NOT NULL,
            question_index INT NOT NULL,
            question_id VARCHAR(100) NULL,
            question_type VARCHAR(50) NOT NULL DEFAULT 'code',
            language VARCHAR(80) NULL,
            question_text LONGTEXT NULL,
            input_format LONGTEXT NULL,
            output_format LONGTEXT NULL,
            notes LONGTEXT NULL,
            sample_cases LONGTEXT NULL,
            test_cases LONGTEXT NULL,
            starter_code LONGTEXT NULL,
            model_solution LONGTEXT NULL,
            marking_scheme LONGTEXT NULL,
            execution_settings LONGTEXT NULL,
            security_settings LONGTEXT NULL,
            question_bank_tags TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_exam_question_details (exam_id, question_index),
            INDEX idx_exam_question_details_exam (exam_id),
            INDEX idx_exam_question_details_language (language)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC
    ");
}

function qodaQuestionDetailJson($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function qodaIsStorageFullError(Throwable $error): bool
{
    $message = strtolower($error->getMessage());
    return str_contains($message, 'table') && str_contains($message, 'full')
        || str_contains($message, '1114')
        || str_contains($message, 'disk is full');
}

function qodaPlainQuestionText($value): string
{
    return trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function qodaCompactQuestionsForExamStorage(string $questionsJson): string
{
    $questions = json_decode($questionsJson, true);
    if (!is_array($questions)) {
        return $questionsJson;
    }

    $compact = [];
    foreach (array_values($questions) as $question) {
        if (!is_array($question)) {
            continue;
        }
        $problem = (string)($question['problemStatement'] ?? $question['text'] ?? '');
        $compact[] = [
            'id' => (string)($question['id'] ?? ''),
            'type' => (string)($question['type'] ?? 'code'),
            'title' => (string)($question['title'] ?? ''),
            'text' => qodaPlainQuestionText($problem),
            'problemStatement' => $problem,
            'inputFormat' => (string)($question['inputFormat'] ?? ''),
            'outputFormat' => (string)($question['outputFormat'] ?? ''),
            'language' => (string)($question['language'] ?? 'Python'),
            'languageMode' => (string)($question['languageMode'] ?? 'single'),
            'marks' => (float)($question['marks'] ?? 0),
            'compulsory' => !empty($question['compulsory']),
            'starterCode' => (string)($question['starterCode'] ?? ''),
            'testCases' => is_array($question['testCases'] ?? null) ? $question['testCases'] : [],
            'markingRubric' => is_array($question['markingRubric'] ?? null) ? $question['markingRubric'] : [],
            'executionSettings' => is_array($question['executionSettings'] ?? null) ? $question['executionSettings'] : [],
            'topic' => (string)($question['topic'] ?? ''),
            'tags' => (string)($question['tags'] ?? $question['questionBankTags'] ?? '')
        ];
    }

    return json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function qodaMarkExistingExamPublishedMinimal(PDO $pdo, int $examId, int $lecturerId): void
{
    $stmt = $pdo->prepare("
        UPDATE exams
        SET published = 1,
            status = 'published',
            published_at = COALESCE(published_at, NOW()),
            updated_at = NOW()
        WHERE id = ? AND (created_by = ? OR lecturer_id = ?)
    ");
    $stmt->execute([$examId, $lecturerId, $lecturerId]);
}

function qodaSyncExamQuestionDetails(PDO $pdo, int $examId, string $questionsJson): void
{
    if ($examId <= 0) {
        return;
    }

    $questions = json_decode($questionsJson, true);
    if (!is_array($questions)) {
        $questions = [];
    }

    try {
        qodaEnsureExamQuestionDetailsTable($pdo);
        $pdo->prepare("DELETE FROM exam_question_details WHERE exam_id = ?")->execute([$examId]);

        if (!$questions) {
            return;
        }

        $insert = $pdo->prepare("
            INSERT INTO exam_question_details (
                exam_id, question_index, question_id, question_type, language,
                question_text, input_format, output_format, notes,
                sample_cases, test_cases, starter_code, model_solution,
                marking_scheme, execution_settings, security_settings, question_bank_tags
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach (array_values($questions) as $index => $question) {
            if (!is_array($question)) {
                continue;
            }

            $insert->execute([
                $examId,
                $index + 1,
                (string)($question['id'] ?? ''),
                (string)($question['type'] ?? 'code'),
                (string)($question['language'] ?? ''),
                (string)($question['problemStatement'] ?? $question['text'] ?? ''),
                (string)($question['inputFormat'] ?? ''),
                (string)($question['outputFormat'] ?? ''),
                (string)($question['notes'] ?? $question['constraints'] ?? ''),
                qodaQuestionDetailJson($question['sampleCases'] ?? []),
                qodaQuestionDetailJson($question['testCases'] ?? []),
                (string)($question['starterCode'] ?? ''),
                (string)($question['modelSolution'] ?? ''),
                qodaQuestionDetailJson($question['markingRubric'] ?? $question['markingScheme'] ?? []),
                qodaQuestionDetailJson($question['executionSettings'] ?? []),
                qodaQuestionDetailJson($question['securitySettings'] ?? []),
                (string)($question['questionBankTags'] ?? $question['tags'] ?? '')
            ]);
        }
    } catch (Throwable $error) {
        error_log('Exam question details sync skipped: ' . $error->getMessage());
    }
}

function qodaEnsureStudentSessionMonitorTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_active_sessions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            student_identifier VARCHAR(100) NULL,
            session_id VARCHAR(191) NOT NULL,
            device_id VARCHAR(191) NOT NULL,
            browser_fingerprint CHAR(64) NOT NULL,
            operating_system VARCHAR(80) NULL,
            ip_address VARCHAR(64) NULL,
            user_agent TEXT NULL,
            login_at DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            expires_at DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            released_at DATETIME NULL,
            release_reason VARCHAR(80) NULL,
            locked_exam_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_student_active (student_id, active, last_seen),
            INDEX idx_session_id (session_id),
            INDEX idx_device_id (device_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_session_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NULL,
            student_identifier VARCHAR(100) NULL,
            event_type VARCHAR(80) NOT NULL,
            device_id VARCHAR(191) NULL,
            browser_fingerprint CHAR(64) NULL,
            operating_system VARCHAR(80) NULL,
            ip_address VARCHAR(64) NULL,
            user_agent TEXT NULL,
            exam_id INT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_student_events (student_id, created_at),
            INDEX idx_event_type (event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC
    ");
    if (function_exists('ensureLecturerColumn')) {
        ensureLecturerColumn($pdo, 'student_active_sessions', 'locked_exam_id', 'INT NULL');
        ensureLecturerColumn($pdo, 'student_session_events', 'exam_id', 'INT NULL');
        ensureLecturerColumn($pdo, 'student_session_events', 'operating_system', 'VARCHAR(80) NULL');
    }
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
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
        exit;
    }
    // Not logged in, redirect to login
    header('Location: login.php');
    exit;
}

// Check if user has proper role (LECTURER only)
if ($_SESSION['user_role'] !== 'LECTURER') {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Lecturer access is required.']);
        exit;
    }
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

// Final grades are read by submissions, results, and the grading IDE. Create the
// compact grade table before heavier schema maintenance so one failed ALTER does
// not break the submissions page.
try {
    qodaTryEnsureFinalGradeTable($pdo);
} catch (Throwable $finalGradeSetupError) {
    error_log('Early final grade table setup failed: ' . $finalGradeSetupError->getMessage());
}

$dashboardAjaxAction = (string)($_POST['action'] ?? '');
$skipLecturerSchemaUpgrade = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($dashboardAjaxAction, [
    'get_active_sessions',
    'get_screen_updates',
    'get_student_screen',
    'get_violation_evidence',
    'get_proctored_courses',
    'send_warning_to_student',
    'send_message_to_student',
    'add_student_exam_time',
    'manage_exam_time',
    'lock_student_screen',
    'unlock_student_screen',
    'unlock_all_screens',
    'take_snapshot',
    'get_course_students',
    'get_exam_details',
    'get_active_student_sessions',
    'force_logout_student_session',
    'get_student_session_history',
    'webrtc_fetch_student_offer',
    'webrtc_submit_monitor_answer',
    'webrtc_create_offer',
    'webrtc_poll_answer',
    'webrtc_close_stream',
], true);

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

if (!function_exists('qodaColumnExists')) {
    function qodaColumnExists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $cache[$key] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $error) {
            error_log('Column availability check failed for ' . $key . ': ' . $error->getMessage());
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('qodaExamOptionalColumnValues')) {
    function qodaExamOptionalColumnValues(PDO $pdo, array $values): array
    {
        $available = [];
        foreach ($values as $column => $value) {
            if (qodaColumnExists($pdo, 'exams', $column)) {
                $available[$column] = $value;
            }
        }
        return $available;
    }
}

if (!function_exists('qodaPruneLiveScreenCaptures')) {
    function qodaPruneLiveScreenCaptures(PDO $pdo, bool $force = false): void
    {
        static $lastRun = 0;
        if (!$force && mt_rand(1, 20) !== 1) {
            return;
        }
        if (!$force && time() - $lastRun < 20) {
            return;
        }
        $lastRun = time();

        try {
            $pdo->exec("
                DELETE FROM screen_captures
                WHERE capture_type IN ('live', 'heartbeat')
                  AND captured_at < (NOW() - INTERVAL 2 MINUTE)
                ORDER BY captured_at ASC
                LIMIT 5000
            ");
        } catch (Throwable $error) {
            error_log('Live screen prune failed: ' . $error->getMessage());
        }

        if ($force) {
            try {
                $pdo->exec("
                    DELETE FROM screen_captures
                    WHERE capture_type IN ('live', 'heartbeat')
                    ORDER BY captured_at ASC
                    LIMIT 5000
                ");
            } catch (Throwable $error) {
                error_log('Forced live screen prune failed: ' . $error->getMessage());
            }
        }
    }
}

if (!$skipLecturerSchemaUpgrade) {
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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proctor_webrtc_streams (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            stream_key VARCHAR(191) NOT NULL UNIQUE,
            exam_id INT NOT NULL,
            student_id INT NOT NULL,
            lecturer_id INT NOT NULL,
            offer LONGTEXT NULL,
            answer LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'offer',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX idx_exam_student_status (exam_id, student_id, status),
            INDEX idx_lecturer (lecturer_id, updated_at),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    ensureLecturerColumn($pdo, 'exams', 'end_datetime', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exams', 'draft_saved_at', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exams', 'academic_year', 'VARCHAR(50) NULL');
    ensureLecturerColumn($pdo, 'exams', 'results_published', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureLecturerColumn($pdo, 'exams', 'results_published_at', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exams', 'grace_period_minutes', 'INT NOT NULL DEFAULT 0');
    ensureLecturerColumn($pdo, 'exams', 'cutoff_datetime', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exams', 'exam_control_status', "VARCHAR(30) NOT NULL DEFAULT 'active'");
    ensureLecturerColumn($pdo, 'exams', 'pause_started_at', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exams', 'paused_seconds_total', 'INT NOT NULL DEFAULT 0');
    ensureLecturerColumn($pdo, 'screen_captures', 'image_data', 'LONGTEXT NULL');
    ensureLecturerColumn($pdo, 'screen_captures', 'capture_type', "VARCHAR(30) NOT NULL DEFAULT 'live'");
    ensureLecturerColumn($pdo, 'screen_captures', 'notes', 'TEXT NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'student_name', 'VARCHAR(255) NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'student_identifier', 'VARCHAR(100) NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'answers', 'LONGTEXT NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'submitted', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureLecturerColumn($pdo, 'exam_submissions', 'submittedAt', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'graded_at', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'graded_by', 'INT NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'manual_feedback', 'TEXT NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'class_score', 'DECIMAL(5,2) DEFAULT 0');
    ensureLecturerColumn($pdo, 'exam_submissions', 'exam_score', 'DECIMAL(5,2) DEFAULT 0');
    ensureLecturerColumn($pdo, 'exam_submissions', 'grade', 'VARCHAR(5) NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'grade_point', 'DECIMAL(3,1) DEFAULT 0');
    ensureLecturerColumn($pdo, 'exam_submissions', 'execution_results', 'JSON NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'ai_feedback', 'MEDIUMTEXT NULL');
    ensureLecturerColumn($pdo, 'exam_submissions', 'auto_graded_at', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'users', 'username', 'VARCHAR(120) NULL');
    ensureLecturerColumn($pdo, 'users', 'title', 'VARCHAR(50) NULL');
    ensureLecturerColumn($pdo, 'users', 'deleted_at', 'DATETIME NULL');
    ensureLecturerColumn($pdo, 'students', 'deleted_at', 'DATETIME NULL');
    if (getenv('QODA_ALLOW_HEAVY_ALTERS') === '1') {
        $pdo->exec("ALTER TABLE exam_submissions MODIFY answers LONGTEXT NULL");
        $pdo->exec("ALTER TABLE exam_submissions MODIFY ai_feedback MEDIUMTEXT NULL");
        $pdo->exec("ALTER TABLE users MODIFY profile_pic MEDIUMTEXT NULL");
        $pdo->exec("ALTER TABLE students MODIFY profile_pic MEDIUMTEXT NULL");
        try {
            $pdo->exec("ALTER TABLE exam_submissions ROW_FORMAT=DYNAMIC");
        } catch (Throwable $rowFormatError) {
            error_log('exam_submissions row format upgrade skipped: ' . $rowFormatError->getMessage());
        }
        $pdo->exec("
            ALTER TABLE exam_submissions
            MODIFY status VARCHAR(50) DEFAULT 'in_progress'
        ");
    }
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
        CREATE TABLE IF NOT EXISTS student_exam_time_adjustments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_id INT NOT NULL,
            student_id INT NOT NULL,
            delta_minutes INT NOT NULL DEFAULT 0,
            reason VARCHAR(255) NULL,
            adjusted_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_exam_student (exam_id, student_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    qodaPruneLiveScreenCaptures($pdo, false);
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
    qodaTryEnsureFinalGradeTable($pdo);
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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_bank (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lecturer_id INT NULL,
            course_code VARCHAR(50) NULL,
            course_name VARCHAR(255) NULL,
            topic VARCHAR(255) NULL,
            difficulty VARCHAR(50) NULL,
            language VARCHAR(50) NULL,
            semester VARCHAR(100) NULL,
            academic_year VARCHAR(50) NULL,
            title VARCHAR(255) NOT NULL,
            prompt LONGTEXT NOT NULL,
            question_json LONGTEXT NULL,
            test_cases JSON NULL,
            marks DECIMAL(10,2) NOT NULL DEFAULT 0,
            source_exam_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_question_bank_filters (lecturer_id, course_code, language, semester, academic_year),
            INDEX idx_question_bank_source (source_exam_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_time_adjustments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_id INT NOT NULL,
            adjusted_by INT NULL,
            old_start_datetime DATETIME NULL,
            old_end_datetime DATETIME NULL,
            new_start_datetime DATETIME NULL,
            new_end_datetime DATETIME NULL,
            delta_minutes INT NOT NULL DEFAULT 0,
            reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_exam_time_adjustments_exam (exam_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS compiler_run_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            actor_id INT NULL,
            actor_role VARCHAR(50) NULL,
            exam_id INT NULL,
            submission_id INT NULL,
            question_index INT NULL,
            language VARCHAR(50) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            input_preview TEXT NULL,
            output_preview TEXT NULL,
            error_preview TEXT NULL,
            execution_time_ms INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_compiler_run_logs_submission (submission_id, question_index),
            INDEX idx_compiler_run_logs_exam (exam_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lecturer_courses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lecturer_id INT NOT NULL,
            course_code VARCHAR(50) NOT NULL,
            course_name VARCHAR(255) NOT NULL,
            level VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lecturer_course_level (lecturer_id, course_code, level),
            INDEX idx_lecturer_courses_lecturer (lecturer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_active_sessions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            student_identifier VARCHAR(100) NOT NULL,
            session_id VARCHAR(128) NOT NULL,
            device_id VARCHAR(128) NOT NULL,
            browser_fingerprint CHAR(64) NOT NULL,
            operating_system VARCHAR(120) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            login_at DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            expires_at DATETIME NULL,
            locked_exam_id INT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            released_at DATETIME NULL,
            release_reason VARCHAR(80) NULL,
            INDEX idx_student_active_session_state (student_id, active, last_seen),
            INDEX idx_student_active_sessions_lookup (student_identifier, active),
            INDEX idx_student_active_sessions_last_seen (last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_session_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NULL,
            student_identifier VARCHAR(100) NULL,
            event_type VARCHAR(80) NOT NULL,
            device_id VARCHAR(128) NULL,
            browser_fingerprint CHAR(64) NULL,
            operating_system VARCHAR(120) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            exam_id INT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_events_student (student_id, created_at),
            INDEX idx_session_events_type (event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC
    ");
} catch (Exception $e) {
    error_log('Lecturer dashboard schema upgrade failed: ' . $e->getMessage());
}
}
// ========== SUBMISSIONS TABLE CREATION (SIMPLIFIED) ==========
if (!$skipLecturerSchemaUpgrade) {
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
}

// ========== CREATE TEST SUBMISSION IF NONE EXISTS ==========
if (!$skipLecturerSchemaUpgrade) {
try {
    // Check if there are any submissions
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM exam_submissions");
    $countStmt->execute();
    $submissionCount = $countStmt->fetchColumn();

    if (false && $submissionCount == 0) {
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
        SELECT
            e.*,
            (
                SELECT ce.course_name
                FROM course_enrollments ce
                WHERE ce.course_code = e.course_code
                  AND (ce.lecturer_id = e.lecturer_id OR ce.lecturer_id = e.created_by)
                ORDER BY ce.enrolled_at DESC
                LIMIT 1
            ) AS course_name,
            (
                SELECT COUNT(DISTINCT ce.student_id)
                FROM course_enrollments ce
                WHERE ce.course_code = e.course_code
                  AND (ce.lecturer_id = e.lecturer_id OR ce.lecturer_id = e.created_by)
            ) AS assigned_students_count,
            (
                SELECT COUNT(DISTINCT COALESCE(NULLIF(es.student_identifier, ''), CAST(es.student_id AS CHAR)))
                FROM exam_submissions es
                WHERE es.exam_id = e.id
                  AND LOWER(COALESCE(es.status, '')) NOT IN ('in_progress', 'draft', 'autosaved')
                  AND (COALESCE(es.submitted, 0) = 1 OR es.submitted_at IS NOT NULL OR es.submittedAt IS NOT NULL)
            ) AS submitted_students_count
        FROM exams e
        WHERE e.created_by = ? OR e.lecturer_id = ?
        ORDER BY e.created_at DESC
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

            $classScore = min(40, max(0, roundToInt($submission['class_score'] ?? 0)));
            $examScore = min(60, max(0, roundToInt((($gradeResult['percentage'] ?? 0) * 60) / 100)));
            $finalTotalScore = min(100, max(0, $examScore + $classScore));
            $gradeInfo = qodaGradeInfo($finalTotalScore);

            qodaPersistFinalGrade($pdo, [
                'submission_id' => $submission['id'],
                'raw_question_score' => $gradeResult['total_score'],
                'percentage' => $gradeResult['percentage'],
                'class_score' => $classScore,
                'exam_score' => $examScore,
                'total_score' => $finalTotalScore,
                'grade' => $gradeInfo['grade'],
                'grade_point' => $gradeInfo['gradePoint'],
                'status' => 'AUTO_GRADED',
                'score_source' => 'auto',
                'graded_by' => $lecturerId
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
        $rawQuestionScore = max(0, floatval($_POST['total_score'] ?? 0));
        $percentage = floatval($_POST['percentage'] ?? 0);
        $scores = json_decode($_POST['scores'] ?? '{}', true);
        
        $stmt = $pdo->prepare("SELECT answers, class_score FROM exam_submissions WHERE id = ?");
        $stmt->execute([$submission_id]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$submission) {
            echo json_encode(['success' => false, 'error' => 'Submission not found']);
            break;
        }

        $answers = json_decode($submission['answers'] ?? '[]', true);
        if (!is_array($answers)) $answers = [];
        $existingGrading = $answers['grading'] ?? $answers['_grading'] ?? [];
        $classScore = min(40, max(0, roundToInt($submission['class_score'] ?? $existingGrading['class_score'] ?? 0)));
        $examScore = min(60, max(0, roundToInt(($percentage * 60) / 100)));
        $finalTotalScore = min(100, max(0, $examScore + $classScore));
        $gradeInfo = qodaGradeInfo($finalTotalScore);
        
        qodaPersistFinalGrade($pdo, [
            'submission_id' => $submission_id,
            'raw_question_score' => $rawQuestionScore,
            'percentage' => $percentage,
            'class_score' => $classScore,
            'exam_score' => $examScore,
            'total_score' => $finalTotalScore,
            'grade' => $gradeInfo['grade'],
            'grade_point' => $gradeInfo['gradePoint'],
            'status' => 'GRADED',
            'score_source' => 'manual',
            'graded_by' => $lecturerId
        ]);
        
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

            case 'webrtc_create_offer':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $offer = trim($_POST['offer'] ?? '');
                    if (!$examId || !$studentId || $offer === '') {
                        echo json_encode(['success' => false, 'error' => 'Missing WebRTC offer data']);
                        break;
                    }
                    $streamKey = hash('sha256', implode('|', [
                        $examId,
                        $studentId,
                        $lecturerId,
                        session_id(),
                        microtime(true),
                        bin2hex(random_bytes(6))
                    ]));
                    $pdo->prepare("DELETE FROM proctor_webrtc_streams WHERE expires_at < NOW() OR (exam_id = ? AND student_id = ? AND lecturer_id = ? AND stream_key <> ?)")
                        ->execute([$examId, $studentId, $lecturerId, $streamKey]);
                    $stmt = $pdo->prepare("
                        INSERT INTO proctor_webrtc_streams
                            (stream_key, exam_id, student_id, lecturer_id, offer, answer, status, created_at, updated_at, expires_at)
                        VALUES (?, ?, ?, ?, ?, NULL, 'offer', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 5 MINUTE))
                        ON DUPLICATE KEY UPDATE
                            offer = VALUES(offer),
                            answer = NULL,
                            status = 'offer',
                            updated_at = NOW(),
                            expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
                    ");
                    $stmt->execute([$streamKey, $examId, $studentId, $lecturerId, $offer]);
                    echo json_encode(['success' => true, 'stream_key' => $streamKey]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'webrtc_fetch_student_offer':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS proctor_webrtc_streams (
                            id BIGINT AUTO_INCREMENT PRIMARY KEY,
                            stream_key VARCHAR(191) NOT NULL UNIQUE,
                            exam_id INT NOT NULL,
                            student_id INT NOT NULL,
                            lecturer_id INT NOT NULL,
                            offer LONGTEXT NULL,
                            answer LONGTEXT NULL,
                            status VARCHAR(30) NOT NULL DEFAULT 'offer',
                            created_at DATETIME NOT NULL,
                            updated_at DATETIME NOT NULL,
                            expires_at DATETIME NOT NULL,
                            INDEX idx_exam_student_status (exam_id, student_id, status),
                            INDEX idx_lecturer (lecturer_id, updated_at),
                            INDEX idx_expires (expires_at)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    $stmt = $pdo->prepare("
                        SELECT stream_key, offer, student_id, exam_id, updated_at
                        FROM proctor_webrtc_streams
                        WHERE exam_id = ?
                          AND student_id = ?
                          AND status IN ('student_offer', 'answered')
                          AND offer IS NOT NULL
                          AND expires_at > NOW()
                        ORDER BY updated_at DESC, id DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$examId, $studentId]);
                    echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'webrtc_submit_monitor_answer':
                try {
                    $streamKey = trim((string)($_POST['stream_key'] ?? ''));
                    $answer = trim((string)($_POST['answer'] ?? ''));
                    if ($streamKey === '' || $answer === '') {
                        echo json_encode(['success' => false, 'error' => 'Missing live stream answer data']);
                        break;
                    }
                    $stmt = $pdo->prepare("
                        UPDATE proctor_webrtc_streams
                        SET answer = ?,
                            lecturer_id = ?,
                            status = 'answered',
                            updated_at = NOW(),
                            expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
                        WHERE stream_key = ?
                    ");
                    $stmt->execute([$answer, $lecturerId, $streamKey]);
                    echo json_encode(['success' => $stmt->rowCount() > 0]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'webrtc_poll_answer':
                try {
                    $streamKey = trim($_POST['stream_key'] ?? '');
                    if ($streamKey === '') {
                        echo json_encode(['success' => false, 'error' => 'Missing stream key']);
                        break;
                    }
                    $stmt = $pdo->prepare("
                        SELECT answer, status, updated_at
                        FROM proctor_webrtc_streams
                        WHERE stream_key = ? AND lecturer_id = ? AND expires_at > NOW()
                        LIMIT 1
                    ");
                    $stmt->execute([$streamKey, $lecturerId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $row ?: null]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'webrtc_close_stream':
                try {
                    $streamKey = trim($_POST['stream_key'] ?? '');
                    if ($streamKey !== '') {
                        $stmt = $pdo->prepare("UPDATE proctor_webrtc_streams SET status = 'closed', updated_at = NOW(), expires_at = NOW() WHERE stream_key = ? AND lecturer_id = ?");
                        $stmt->execute([$streamKey, $lecturerId]);
                    }
                    echo json_encode(['success' => true]);
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
                            MAX(COALESCE(sss.last_updated, live_sc.captured_at, hb_sc.captured_at, es.updated_at, es.started_at, es.created_at)) AS last_activity,
                            CASE
                                WHEN MAX(live_sc.captured_at) >= (NOW() - INTERVAL 90 SECOND)
                                  OR MAX(CASE WHEN hb_sc.notes = 'sharing' THEN hb_sc.captured_at ELSE NULL END) >= (NOW() - INTERVAL 45 SECOND)
                                  OR MAX(CASE WHEN sss.is_sharing = 1 THEN sss.last_updated ELSE NULL END) >= (NOW() - INTERVAL 45 SECOND)
                                THEN 1 ELSE 0
                            END AS screen_sharing_active,
                            MAX(CASE WHEN sss.is_sharing = 1 THEN sss.last_updated ELSE NULL END) AS latest_socket_at,
                            SUBSTRING_INDEX(GROUP_CONCAT(sss.session_id ORDER BY sss.last_updated DESC SEPARATOR '||'), '||', 1) AS socket_session_id,
                            COUNT(DISTINCT sl.id) AS violations,
                            MAX(CASE WHEN pc.command_type = 'lock' THEN 1 WHEN pc.command_type = 'unlock' THEN 0 ELSE 0 END) AS screen_locked,
                            MAX(COALESCE(es.submitted, 0)) AS submitted,
                            MAX(es.submitted_at) AS submitted_at,
                            MAX(es.submittedAt) AS submittedAt,
                            (
                                SELECT sc3.image_data
                                FROM screen_captures sc3
                                WHERE sc3.exam_id = ?
                                  AND (sc3.student_id = active.student_id OR sc3.student_id = active_s.user_id)
                                  AND sc3.capture_type = 'live'
                                ORDER BY sc3.id DESC
                                LIMIT 1
                            ) AS snapshot,
                            (
                                SELECT sc5.id
                                FROM screen_captures sc5
                                WHERE sc5.exam_id = ?
                                  AND (sc5.student_id = active.student_id OR sc5.student_id = active_s.user_id)
                                  AND sc5.capture_type = 'live'
                                ORDER BY sc5.id DESC
                                LIMIT 1
                            ) AS snapshot_id,
                            (
                                SELECT sc4.captured_at
                                FROM screen_captures sc4
                                WHERE sc4.exam_id = ?
                                  AND (sc4.student_id = active.student_id OR sc4.student_id = active_s.user_id)
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
                            SELECT COALESCE(s.id, raw.student_id) AS student_id
                            FROM (
                                SELECT student_id FROM exam_submissions WHERE exam_id = ?
                                UNION
                                SELECT student_id FROM screen_captures WHERE exam_id = ?
                                UNION
                                SELECT student_id FROM screen_share_sessions WHERE exam_id = ?
                            ) raw
                            LEFT JOIN students s ON s.id = raw.student_id OR s.user_id = raw.student_id
                            GROUP BY COALESCE(s.id, raw.student_id)
                        ) active
                        LEFT JOIN students active_s ON active_s.id = active.student_id
                        LEFT JOIN exam_submissions es ON es.exam_id = ? AND (es.student_id = active.student_id OR es.student_id = active_s.user_id)
                        LEFT JOIN screen_captures live_sc ON live_sc.exam_id = ? AND (live_sc.student_id = active.student_id OR live_sc.student_id = active_s.user_id) AND live_sc.capture_type = 'live'
                        LEFT JOIN screen_captures hb_sc ON hb_sc.exam_id = ? AND (hb_sc.student_id = active.student_id OR hb_sc.student_id = active_s.user_id) AND hb_sc.capture_type = 'heartbeat'
                        LEFT JOIN screen_share_sessions sss ON sss.exam_id = ? AND (sss.student_id = active.student_id OR sss.student_id = active_s.user_id)
                        LEFT JOIN suspicious_logs sl ON sl.exam_id = ? AND (sl.student_id = active.student_id OR sl.student_id = active_s.user_id)
                        LEFT JOIN proctor_commands pc ON pc.id = (
                            SELECT pc2.id
                            FROM proctor_commands pc2
                            WHERE pc2.exam_id = ?
                              AND (pc2.student_id = active.student_id OR pc2.student_id = active_s.user_id)
                              AND pc2.command_type IN ('lock', 'unlock')
                            ORDER BY pc2.id DESC
                            LIMIT 1
                        )
                        GROUP BY active.student_id, active_s.id, active_s.user_id
                    ");
                    $stmt->execute([$examId, $examId, $examId, $examId, $examId, $examId, $examId, $examId, $examId, $examId, $examId, $examId]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $liveFrameDir = dirname(__DIR__) . '/runtime/live_frames';
                    foreach ($rows as &$row) {
                        $safeKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $examId . '_' . (int)($row['student_id'] ?? 0));
                        $framePath = $liveFrameDir . '/' . $safeKey . '.jpg';
                        if (is_file($framePath) && filemtime($framePath) >= time() - 8) {
                            $row['snapshot'] = base64_encode((string)file_get_contents($framePath));
                            $row['snapshot_id'] = 'liveframe_' . filemtime($framePath) . '_' . filesize($framePath);
                            $row['latest_snapshot_at'] = date('Y-m-d H:i:s', filemtime($framePath));
                            $row['last_activity'] = $row['latest_snapshot_at'];
                            $row['screen_sharing_active'] = 1;
                        }
                    }
                    unset($row);
                    echo json_encode(['success' => true, 'data' => $rows]);
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
                               MD5(sc.image_data) AS snapshot_hash,
                               sc.captured_at,
                               hb.captured_at AS last_heartbeat_at,
                               sss.last_updated AS latest_socket_at,
                               sss.session_id AS socket_session_id,
                               CASE
                                   WHEN sc.captured_at >= (NOW() - INTERVAL 90 SECOND)
                                     OR (hb.captured_at >= (NOW() - INTERVAL 45 SECOND) AND hb.notes = 'sharing')
                                     OR (sss.is_sharing = 1 AND sss.last_updated >= (NOW() - INTERVAL 45 SECOND))
                                   THEN 1 ELSE 0
                               END AS screen_sharing_active,
                               COUNT(DISTINCT sl.id) AS violations,
                               latest_pc.command_type AS latest_lock_command,
                               CASE WHEN latest_pc.command_type = 'lock' THEN 1 ELSE 0 END AS screen_locked,
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
                            SELECT COALESCE(s.id, raw.student_id) AS student_id
                            FROM (
                                SELECT student_id FROM screen_captures WHERE exam_id = ?
                                UNION
                                SELECT student_id FROM exam_submissions WHERE exam_id = ?
                                UNION
                                SELECT student_id FROM screen_share_sessions WHERE exam_id = ?
                            ) raw
                            LEFT JOIN students s ON s.id = raw.student_id OR s.user_id = raw.student_id
                            GROUP BY COALESCE(s.id, raw.student_id)
                        ) active
                        LEFT JOIN students active_s ON active_s.id = active.student_id
                        LEFT JOIN screen_captures sc ON sc.id = (
                            SELECT sc2.id
                            FROM screen_captures sc2
                            WHERE sc2.exam_id = ?
                              AND (sc2.student_id = active.student_id OR sc2.student_id = active_s.user_id)
                              AND sc2.capture_type = 'live'
                            ORDER BY sc2.id DESC
                            LIMIT 1
                        )
                        LEFT JOIN exam_submissions es ON es.exam_id = ? AND (es.student_id = active.student_id OR es.student_id = active_s.user_id)
                        LEFT JOIN screen_captures hb ON hb.id = (
                            SELECT hb2.id
                            FROM screen_captures hb2
                            WHERE hb2.exam_id = ?
                              AND (hb2.student_id = active.student_id OR hb2.student_id = active_s.user_id)
                              AND hb2.capture_type = 'heartbeat'
                            ORDER BY hb2.id DESC
                            LIMIT 1
                        )
                        LEFT JOIN screen_share_sessions sss ON sss.id = (
                            SELECT sss2.id
                            FROM screen_share_sessions sss2
                            WHERE sss2.exam_id = ?
                              AND (sss2.student_id = active.student_id OR sss2.student_id = active_s.user_id)
                            ORDER BY sss2.last_updated DESC, sss2.id DESC
                            LIMIT 1
                        )
                        LEFT JOIN suspicious_logs sl ON sl.exam_id = ? AND (sl.student_id = active.student_id OR sl.student_id = active_s.user_id)
                        LEFT JOIN proctor_commands latest_pc ON latest_pc.id = (
                            SELECT pc2.id
                            FROM proctor_commands pc2
                            WHERE pc2.exam_id = ?
                              AND (pc2.student_id = active.student_id OR pc2.student_id = active_s.user_id)
                              AND pc2.command_type IN ('lock', 'unlock')
                            ORDER BY pc2.id DESC
                            LIMIT 1
                        )
                        GROUP BY active.student_id, active_s.id, active_s.user_id, sc.id, sc.image_data, sc.captured_at, hb.captured_at, hb.notes, sss.id, sss.session_id, sss.is_sharing, sss.last_updated, latest_pc.command_type
                    ");
                    $stmt->execute([$examId, $examId, $examId, $examId, $examId, $examId, $examId, $examId, $examId]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $liveFrameDir = dirname(__DIR__) . '/runtime/live_frames';
                    foreach ($rows as &$row) {
                        $safeKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $examId . '_' . (int)($row['student_id'] ?? 0));
                        $framePath = $liveFrameDir . '/' . $safeKey . '.jpg';
                        if (is_file($framePath) && filemtime($framePath) >= time() - 8) {
                            $frameMtime = filemtime($framePath);
                            $row['snapshot'] = base64_encode((string)file_get_contents($framePath));
                            $row['snapshot_id'] = 'liveframe_' . $frameMtime . '_' . filesize($framePath);
                            $row['snapshot_hash'] = md5_file($framePath);
                            $row['captured_at'] = date('Y-m-d H:i:s', $frameMtime);
                            $row['screen_sharing_active'] = 1;
                        }
                    }
                    unset($row);
                    echo json_encode(['success' => true, 'data' => $rows]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_student_screen':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $stmt = $pdo->prepare("
                        SELECT active.student_id,
                               sc.image_data AS snapshot,
                               sc.captured_at,
                               hb.captured_at AS last_heartbeat_at,
                               CASE
                                   WHEN sc.captured_at >= (NOW() - INTERVAL 90 SECOND)
                                     OR (hb.captured_at >= (NOW() - INTERVAL 45 SECOND) AND hb.notes = 'sharing')
                                   THEN 1 ELSE 0
                               END AS screen_sharing_active,
                               CASE WHEN latest_pc.command_type = 'lock' THEN 1 ELSE 0 END AS screen_locked,
                               (
                                   SELECT COUNT(*)
                                   FROM suspicious_logs sl
                                   WHERE sl.exam_id = ?
                                     AND (sl.student_id = active.student_id OR sl.student_id = active_s.id OR sl.student_id = active_s.user_id)
                               ) AS violations
                        FROM (SELECT ? AS student_id) active
                        LEFT JOIN students active_s ON active_s.id = active.student_id OR active_s.user_id = active.student_id
                        LEFT JOIN screen_captures sc ON sc.id = (
                            SELECT sc2.id
                            FROM screen_captures sc2
                            WHERE sc2.exam_id = ?
                              AND (sc2.student_id = active.student_id OR sc2.student_id = active_s.id OR sc2.student_id = active_s.user_id)
                              AND sc2.capture_type = 'live'
                            ORDER BY sc2.id DESC
                            LIMIT 1
                        )
                        LEFT JOIN screen_captures hb ON hb.id = (
                            SELECT hb2.id
                            FROM screen_captures hb2
                            WHERE hb2.exam_id = ?
                              AND (hb2.student_id = active.student_id OR hb2.student_id = active_s.id OR hb2.student_id = active_s.user_id)
                              AND hb2.capture_type = 'heartbeat'
                            ORDER BY hb2.id DESC
                            LIMIT 1
                        )
                        LEFT JOIN proctor_commands latest_pc ON latest_pc.id = (
                            SELECT pc2.id
                            FROM proctor_commands pc2
                            WHERE pc2.exam_id = ?
                              AND (pc2.student_id = active.student_id OR pc2.student_id = active_s.id OR pc2.student_id = active_s.user_id)
                              AND pc2.command_type IN ('lock', 'unlock')
                            ORDER BY pc2.id DESC
                            LIMIT 1
                        )
                    ");
                    $stmt->execute([$examId, $studentId, $examId, $examId, $examId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($row) {
                        $liveFrameDir = dirname(__DIR__) . '/runtime/live_frames';
                        $safeKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $examId . '_' . (int)($row['student_id'] ?? $studentId));
                        $framePath = $liveFrameDir . '/' . $safeKey . '.jpg';
                        if (is_file($framePath) && filemtime($framePath) >= time() - 8) {
                            $frameMtime = filemtime($framePath);
                            $row['snapshot'] = base64_encode((string)file_get_contents($framePath));
                            $row['captured_at'] = date('Y-m-d H:i:s', $frameMtime);
                            $row['screen_sharing_active'] = 1;
                        }
                    }
                    echo json_encode(['success' => true, 'data' => $row]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_violation_evidence':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $where = "sl.exam_id = ?";
                    $params = [$examId];
                    if ($studentId > 0) {
                        $where .= " AND (
                            sl.student_id = ?
                            OR sl.student_id = (SELECT user_id FROM students WHERE id = ? OR user_id = ? LIMIT 1)
                            OR sl.student_id = (SELECT id FROM students WHERE id = ? OR user_id = ? LIMIT 1)
                        )";
                        array_push($params, $studentId, $studentId, $studentId, $studentId, $studentId);
                    }
                    $stmt = $pdo->prepare("
                        SELECT sl.id, sl.student_id, s.student_id AS student_identifier, s.full_name,
                               sl.event_type, sl.details, sl.severity, sl.created_at,
                               (
                                   SELECT sc.image_data
                                   FROM screen_captures sc
                                   WHERE sc.exam_id = sl.exam_id
                                     AND (sc.student_id = sl.student_id OR sc.student_id = s.id OR sc.student_id = s.user_id)
                                     AND sc.captured_at <= sl.created_at
                                   ORDER BY sc.captured_at DESC
                                   LIMIT 1
                               ) AS snapshot,
                               (
                                   SELECT sc.image_path
                                   FROM screen_captures sc
                                   WHERE sc.exam_id = sl.exam_id
                                     AND (sc.student_id = sl.student_id OR sc.student_id = s.id OR sc.student_id = s.user_id)
                                     AND sc.captured_at <= sl.created_at
                                     AND sc.image_path <> ''
                                   ORDER BY sc.captured_at DESC
                                   LIMIT 1
                               ) AS image_path
                        FROM suspicious_logs sl
                        LEFT JOIN students s ON s.id = sl.student_id OR s.user_id = sl.student_id
                        WHERE {$where}
                        ORDER BY sl.created_at DESC
                        LIMIT 80
                    ");
                    $stmt->execute($params);
                    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_proctored_courses':
                try {
                    $stmt = $pdo->prepare("
                        SELECT
                            e.id,
                            e.title,
                            e.course_code,
                            e.semester,
                            e.start_datetime,
                            e.end_datetime,
                            COUNT(DISTINCT sc.student_id) AS captured_students,
                            COUNT(DISTINCT sl.id) AS evidence_count,
                            MAX(COALESCE(sc.captured_at, sl.created_at)) AS last_proctored_at
                        FROM exams e
                        LEFT JOIN screen_captures sc ON sc.exam_id = e.id
                        LEFT JOIN suspicious_logs sl ON sl.exam_id = e.id
                        WHERE (e.lecturer_id = ? OR e.created_by = ?)
                          AND (sc.id IS NOT NULL OR sl.id IS NOT NULL)
                        GROUP BY e.id, e.title, e.course_code, e.semester, e.start_datetime, e.end_datetime
                        ORDER BY last_proctored_at DESC, e.created_at DESC
                    ");
                    $stmt->execute([$lecturerId, $lecturerId]);
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

            case 'send_message_to_student':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $message = trim((string)($_POST['message'] ?? ''));
                    if ($examId <= 0 || $studentId <= 0 || $message === '') {
                        echo json_encode(['success' => false, 'error' => 'Select a student and enter a message.']);
                        break;
                    }
                    $stmt = $pdo->prepare("INSERT INTO proctor_commands (exam_id, student_id, command_type, message, created_by) VALUES (?, ?, 'warning', ?, ?)");
                    $stmt->execute([$examId, $studentId, $message, $lecturerId]);
                    echo json_encode(['success' => true, 'message' => 'Message sent to student.']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'add_student_exam_time':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $minutes = max(1, intval($_POST['minutes'] ?? 0));
                    $reason = trim((string)($_POST['reason'] ?? 'Individual extra time granted by lecturer'));
                    if ($examId <= 0 || $studentId <= 0 || $minutes <= 0) {
                        echo json_encode(['success' => false, 'error' => 'Select a student and enter valid minutes.']);
                        break;
                    }
                    $allowed = $pdo->prepare("SELECT id FROM exams WHERE id = ? AND (lecturer_id = ? OR created_by = ?) LIMIT 1");
                    $allowed->execute([$examId, $lecturerId, $lecturerId]);
                    if (!$allowed->fetchColumn()) {
                        echo json_encode(['success' => false, 'error' => 'Exam not found or not allowed.']);
                        break;
                    }
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS student_exam_time_adjustments (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            exam_id INT NOT NULL,
                            student_id INT NOT NULL,
                            delta_minutes INT NOT NULL DEFAULT 0,
                            reason VARCHAR(255) NULL,
                            adjusted_by INT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            INDEX idx_exam_student (exam_id, student_id),
                            INDEX idx_created_at (created_at)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    $stmt = $pdo->prepare("
                        INSERT INTO student_exam_time_adjustments (exam_id, student_id, delta_minutes, reason, adjusted_by)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$examId, $studentId, $minutes, $reason, $lecturerId]);
                    $notice = "The lecturer has added {$minutes} minute(s) to your examination time.";
                    $cmd = $pdo->prepare("INSERT INTO proctor_commands (exam_id, student_id, command_type, message, created_by) VALUES (?, ?, 'warning', ?, ?)");
                    $cmd->execute([$examId, $studentId, $notice, $lecturerId]);
                    echo json_encode(['success' => true, 'message' => $notice]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'lock_student_screen':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $latestFrame = $pdo->prepare("
                        SELECT image_data
                        FROM screen_captures
                        WHERE exam_id = ?
                          AND (
                              student_id = ?
                              OR student_id = (SELECT user_id FROM students WHERE id = ? OR user_id = ? LIMIT 1)
                              OR student_id = (SELECT id FROM students WHERE id = ? OR user_id = ? LIMIT 1)
                          )
                          AND capture_type = 'live'
                          AND image_data IS NOT NULL
                          AND image_data <> ''
                          AND captured_at >= (NOW() - INTERVAL 2 MINUTE)
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $latestFrame->execute([$examId, $studentId, $studentId, $studentId, $studentId, $studentId]);
                    $lockEvidence = $latestFrame->fetchColumn();
                    if ($lockEvidence) {
                        $evidence = $pdo->prepare("
                            INSERT INTO screen_captures (exam_id, student_id, image_path, image_data, capture_type, notes, captured_at)
                            VALUES (?, ?, '', ?, 'evidence', 'Captured automatically immediately before lecturer lock screen', NOW())
                        ");
                        $evidence->execute([$examId, $studentId, $lockEvidence]);
                    }
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
                    $stmt = $pdo->prepare("
                        SELECT image_data
                        FROM screen_captures
                        WHERE exam_id = ?
                          AND (
                              student_id = ?
                              OR student_id = (SELECT user_id FROM students WHERE id = ? OR user_id = ? LIMIT 1)
                              OR student_id = (SELECT id FROM students WHERE id = ? OR user_id = ? LIMIT 1)
                          )
                          AND capture_type = 'live'
                          AND captured_at >= (NOW() - INTERVAL 45 SECOND)
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$examId, $studentId, $studentId, $studentId, $studentId, $studentId]);
                    $snapshot = $stmt->fetchColumn();
                    if (!$snapshot) {
                        echo json_encode(['success' => false, 'error' => 'No fresh live screen frame is available for this student. Ask the student to share their screen, then try again.']);
                        break;
                    }
                    $imagePath = '';
                    $studentStmt = $pdo->prepare("SELECT student_id FROM students WHERE id = ? LIMIT 1");
                    $studentStmt->execute([$studentId]);
                    $studentIdentifier = $studentStmt->fetchColumn() ?: ('student_' . $studentId);
                    $safeStudentFolder = preg_replace('/[^A-Za-z0-9_-]+/', '_', $studentIdentifier);
                    $evidenceRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'proctoring_evidence';
                    $evidenceDir = $evidenceRoot . DIRECTORY_SEPARATOR . $safeStudentFolder . DIRECTORY_SEPARATOR . 'exam_' . $examId;
                    if (!is_dir($evidenceDir)) {
                        @mkdir($evidenceDir, 0775, true);
                    }
                    $fileBase = date('Ymd_His') . '_lecturer_shot_' . bin2hex(random_bytes(3));
                    $absoluteImagePath = $evidenceDir . DIRECTORY_SEPARATOR . $fileBase . '.jpg';
                    $absoluteNotePath = $evidenceDir . DIRECTORY_SEPARATOR . $fileBase . '.txt';
                    $binary = base64_decode($snapshot, true);
                    if ($binary !== false && is_dir($evidenceDir)) {
                        @file_put_contents($absoluteImagePath, $binary);
                        $imagePath = 'storage/proctoring_evidence/' . $safeStudentFolder . '/exam_' . $examId . '/' . $fileBase . '.jpg';
                        $noteText = "Student: {$studentIdentifier}\nExam ID: {$examId}\nCapture type: evidence\nTime: " . date('Y-m-d H:i:s') . "\nReason: Saved by lecturer from proctoring dashboard\n";
                        @file_put_contents($absoluteNotePath, $noteText);
                    }
                    $ins = $pdo->prepare("INSERT INTO screen_captures (exam_id, student_id, image_path, image_data, capture_type, notes, captured_at) VALUES (?, ?, ?, ?, 'evidence', 'Saved by lecturer from proctoring dashboard', NOW())");
                    $ins->execute([$examId, $studentId, $imagePath, $snapshot]);
                    $log = $pdo->prepare("INSERT INTO suspicious_logs (student_id, exam_id, event_type, details, severity) VALUES (?, ?, 'SCREENSHOT_EVIDENCE', 'Lecturer saved screenshot evidence', 'high')");
                    $log->execute([$studentId, $examId]);
                    echo json_encode(['success' => true, 'data' => ['snapshot' => $snapshot, 'image_path' => $imagePath]]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_submissions':
                try {
                    $finalGradeTableReady = qodaTryEnsureFinalGradeTable($pdo);
                    $finalGradeSelect = $finalGradeTableReady ? "
                            efg.total_score AS final_total_score,
                            efg.percentage AS final_percentage,
                            efg.class_score AS final_class_score,
                            efg.exam_score AS final_exam_score,
                            efg.grade AS final_grade,
                            efg.grade_point AS final_grade_point,
                            efg.status AS final_status,
                            efg.graded_at AS final_graded_at" : "
                            NULL AS final_total_score,
                            NULL AS final_percentage,
                            NULL AS final_class_score,
                            NULL AS final_exam_score,
                            NULL AS final_grade,
                            NULL AS final_grade_point,
                            NULL AS final_status,
                            NULL AS final_graded_at";
                    $finalGradeJoin = $finalGradeTableReady
                        ? "LEFT JOIN exam_final_grades efg ON efg.submission_id = es.id"
                        : "";
                    $stmt = $pdo->prepare("
                        SELECT
                            es.*,
                            COALESCE(es.student_name, s.full_name) AS student_name,
                            COALESCE(es.student_identifier, s.student_id) AS student_identifier,
                            e.title AS exam_title,
                            e.course_code,
                            (
                                SELECT ce.course_name
                                FROM course_enrollments ce
                                WHERE ce.course_code = e.course_code
                                  AND (ce.lecturer_id = e.lecturer_id OR ce.lecturer_id = e.created_by)
                                ORDER BY ce.enrolled_at DESC
                                LIMIT 1
                            ) AS course_name,
                            e.exam_code,
                            e.start_datetime,
                            e.end_datetime,
                            e.duration_minutes,
                            e.semester,
                            e.school_type,
                            e.academic_year,
                            e.exam_type,
                            e.level,
                            e.school_name,
                            e.department,
                            e.results_published,
                            $finalGradeSelect
                        FROM exam_submissions es
                        LEFT JOIN students s ON es.student_id = s.id
                        LEFT JOIN exams e ON es.exam_id = e.id
                        $finalGradeJoin
                        WHERE (e.lecturer_id = ? OR e.created_by = ? OR e.lecturer_id IS NULL)
                          AND LOWER(COALESCE(es.status, '')) NOT IN ('in_progress', 'draft', 'autosaved')
                          AND (
                              COALESCE(es.submitted, 0) = 1
                              OR es.submitted_at IS NOT NULL
                              OR es.submittedAt IS NOT NULL
                              OR UPPER(COALESCE(es.status, '')) IN ('SUBMITTED', 'TIMED_OUT', 'GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED')
                          )
                        ORDER BY es.exam_id ASC, es.student_id ASC, es.id DESC
                    ");
                    $stmt->execute([$lecturerId, $lecturerId]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $statusPriority = static function ($status): int {
                        $status = strtoupper((string)$status);
                        if (in_array($status, ['GRADED', 'MARKED', 'MANUALLY_GRADED'], true)) return 5;
                        if ($status === 'AUTO_GRADED') return 4;
                        if (in_array($status, ['SUBMITTED', 'TIMED_OUT'], true)) return 3;
                        return 1;
                    };
                    $deduped = [];
                    foreach ($rows as $row) {
                        if ($row['final_total_score'] !== null && $row['final_total_score'] !== '') {
                            $row['total_score'] = $row['final_total_score'];
                            $row['percentage'] = $row['final_percentage'];
                            $row['class_score'] = $row['final_class_score'];
                            $row['exam_score'] = $row['final_exam_score'];
                            $row['grade'] = $row['final_grade'];
                            $row['grade_point'] = $row['final_grade_point'];
                            $row['status'] = $row['final_status'] ?: ($row['status'] ?? 'GRADED');
                            if (!empty($row['final_graded_at'])) {
                                $row['graded_at'] = $row['final_graded_at'];
                            }
                        }
                        $studentKey = $row['student_id'] ?: ($row['student_identifier'] ?? '');
                        $key = ($row['exam_id'] ?? '') . '::' . $studentKey;
                        $row['submitted_exact'] = $row['submittedAt'] ?? $row['submitted_at'] ?? null;
                        if (!isset($deduped[$key])) {
                            $deduped[$key] = $row;
                            continue;
                        }
                        $existing = $deduped[$key];
                        $currentPriority = $statusPriority($row['status'] ?? '');
                        $existingPriority = $statusPriority($existing['status'] ?? '');
                        $currentTime = strtotime((string)($row['submitted_exact'] ?? $row['updated_at'] ?? $row['submitted_at'] ?? '')) ?: 0;
                        $existingTime = strtotime((string)($existing['submitted_exact'] ?? $existing['updated_at'] ?? $existing['submitted_at'] ?? '')) ?: 0;
                        if ($currentPriority > $existingPriority || ($currentPriority === $existingPriority && ($currentTime > $existingTime || (int)$row['id'] > (int)$existing['id']))) {
                            $deduped[$key] = $row;
                        }
                    }
                    $submissions = array_values($deduped);
                    usort($submissions, static function ($a, $b) {
                        return strcmp((string)($b['submitted_exact'] ?? $b['submitted_at'] ?? ''), (string)($a['submitted_exact'] ?? $a['submitted_at'] ?? ''));
                    });

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
                    $classScore = min(40, max(0, roundToInt($_POST['class_score'] ?? 0)));
                    $examScore = min(60, max(0, roundToInt($_POST['exam_score'] ?? 0)));
                    $totalScore = min(100, max(0, $examScore + $classScore));
                    $fallbackGrade = qodaGradeInfo($totalScore);
                    $grade = trim((string)($_POST['grade'] ?? '')) ?: $fallbackGrade['grade'];
                    $gradePoint = isset($_POST['grade_point']) && $_POST['grade_point'] !== ''
                        ? round(floatval($_POST['grade_point']), 1)
                        : $fallbackGrade['gradePoint'];

                    $storedGrade = qodaPersistFinalGrade($pdo, [
                        'submission_id' => $submissionId,
                        'raw_question_score' => $examScore,
                        'percentage' => $examScore > 0 ? min(100, ($examScore / 60) * 100) : 0,
                        'class_score' => $classScore,
                        'exam_score' => $examScore,
                        'total_score' => $totalScore,
                        'grade' => $grade,
                        'grade_point' => $gradePoint,
                        'status' => $status,
                        'score_source' => 'manual',
                        'graded_by' => $lecturerId
                    ]);

                    echo json_encode(['success' => true, 'grade' => $storedGrade]);
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
                        if (!is_array($answers)) {
                            $answers = json_decode($submission['answers_json'] ?? '[]', true);
                        }
                        if (!is_array($answers)) {
                            $answers = [];
                        }

                        $savedScores = [];
                        $savedFeedback = [];
                        try {
                            $scoreTable = $pdo->query("SHOW TABLES LIKE 'submission_question_scores'");
                            if ($scoreTable && $scoreTable->rowCount() > 0) {
                                $scoreStmt = $pdo->prepare("SELECT question_id, score, feedback FROM submission_question_scores WHERE submission_id = ?");
                                $scoreStmt->execute([$submissionId]);
                                foreach ($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $scoreRow) {
                                    $savedScores[(string)$scoreRow['question_id']] = (float)$scoreRow['score'];
                                    $savedFeedback[(string)$scoreRow['question_id']] = (string)($scoreRow['feedback'] ?? '');
                                }
                            }
                        } catch (Throwable $scoreLoadError) {
                            error_log('Could not load saved question scores: ' . $scoreLoadError->getMessage());
                        }

                        // Combine questions with answers
                        $questionList = [];
                        if (is_array($questions)) {
                            foreach ($questions as $index => $question) {
                                $questionId = (string)($question['id'] ?? ('Q' . $index));
                                $answerRow = is_array($answers[$questionId] ?? null)
                                    ? $answers[$questionId]
                                    : (is_array($answers[$index] ?? null) ? $answers[$index] : []);
                                $answerValue = $answerRow['value'] ?? $answerRow;
                                $answerText = 'No answer provided';
                                if (is_array($answerValue)) {
                                    if (isset($answerValue['code'])) {
                                        $answerText = (string)$answerValue['code'];
                                    } elseif (!empty($answerValue['files']) && is_array($answerValue['files'])) {
                                        $firstFile = $answerValue['files'][0] ?? [];
                                        $answerText = (string)($firstFile['content'] ?? 'No answer provided');
                                    } elseif (isset($answerValue['answer'])) {
                                        $answerText = (string)$answerValue['answer'];
                                    }
                                } elseif (is_string($answerValue) && $answerValue !== '') {
                                    $answerText = $answerValue;
                                }

                                $questionList[] = [
                                    'number' => $index + 1,
                                    'id' => $questionId,
                                    'text' => $question['text'] ?? 'No question text',
                                    'marks' => $question['marks'] ?? 0,
                                    'language' => $question['language'] ?? 'text',
                                    'expectedOutput' => $question['expectedOutput'] ?? '',
                                    'answer' => $answerText,
                                    'savedScore' => $savedScores[$questionId] ?? (float)($answerRow['auto_score'] ?? $answerRow['score'] ?? 0),
                                    'autoFeedback' => $savedFeedback[$questionId] ?? (string)($answerRow['auto_feedback'] ?? '')
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
                   (
                       SELECT ce.course_name
                       FROM course_enrollments ce
                       WHERE ce.course_code = e.course_code
                         AND (ce.lecturer_id = e.lecturer_id OR ce.lecturer_id = e.created_by)
                       ORDER BY ce.enrolled_at DESC
                       LIMIT 1
                   ) AS course_name,
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
                    $rawTotalScore = max(0, floatval($_POST['total_score'] ?? 0));
                    $percentage = min(100, max(0, floatval($_POST['percentage'] ?? 0)));
                    $examScore = min(60, max(0, roundToInt(($percentage * 60) / 100)));

                    $stmt = $pdo->prepare("SELECT student_identifier, class_score FROM exam_submissions WHERE id = ?");
                    $stmt->execute([$submissionId]);
                    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($submission) {
                        $classScore = min(40, max(0, roundToInt($submission['class_score'] ?? 0)));
                        $finalTotalScore = min(100, max(0, $examScore + $classScore));
                        $gradeInfo = qodaGradeInfo($finalTotalScore);

                        qodaPersistFinalGrade($pdo, [
                            'submission_id' => $submissionId,
                            'raw_question_score' => $rawTotalScore,
                            'percentage' => $percentage,
                            'class_score' => $classScore,
                            'exam_score' => $examScore,
                            'total_score' => $finalTotalScore,
                            'grade' => $gradeInfo['grade'],
                            'grade_point' => $gradeInfo['gradePoint'],
                            'status' => 'AUTO_GRADED',
                            'score_source' => 'auto',
                            'graded_by' => $lecturerId
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
                    $rawScore = max(0, floatval($_POST['total_score'] ?? 0));

                    $stmt = $pdo->prepare("
                        SELECT e.total_marks, es.class_score
                        FROM exam_submissions es
                        JOIN exams e ON e.id = es.exam_id
                        WHERE es.id = ?
                    ");
                    $stmt->execute([$submissionId]);
                    $submissionMeta = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$submissionMeta) {
                        echo json_encode(['success' => false, 'error' => 'Submission not found']);
                        break;
                    }

                    $totalMarks = max(1, (float)($submissionMeta['total_marks'] ?? 100));
                    $percentage = min(100, max(0, ($rawScore / $totalMarks) * 100));
                    $examScore = min(60, max(0, roundToInt(($percentage * 60) / 100)));
                    $classScore = min(40, max(0, roundToInt($submissionMeta['class_score'] ?? 0)));
                    $finalTotalScore = min(100, max(0, $examScore + $classScore));
                    $gradeInfo = qodaGradeInfo($finalTotalScore);

                    qodaPersistFinalGrade($pdo, [
                        'submission_id' => $submissionId,
                        'raw_question_score' => $rawScore,
                        'percentage' => $percentage,
                        'class_score' => $classScore,
                        'exam_score' => $examScore,
                        'total_score' => $finalTotalScore,
                        'grade' => $gradeInfo['grade'],
                        'grade_point' => $gradeInfo['gradePoint'],
                        'status' => 'MANUALLY_GRADED',
                        'score_source' => 'manual',
                        'graded_by' => $lecturerId
                    ]);

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
                $gracePeriod = max(0, intval($_POST['grace_period_minutes'] ?? 0));
                $cutoffDatetime = normalizeDateTimeInput($_POST['cutoff_datetime'] ?? null);
                if (!$cutoffDatetime && $endDatetime && $gracePeriod > 0) {
                    $cutoffDatetime = date('Y-m-d H:i:s', strtotime($endDatetime) + ($gracePeriod * 60));
                }
                if ($cutoffDatetime && $endDatetime && strtotime($cutoffDatetime) < strtotime($endDatetime)) {
                    $cutoffDatetime = $endDatetime;
                }
                $academicYear = trim($_POST['academic_year'] ?? '');
                $stmt = $pdo->prepare("
        INSERT INTO exams (exam_id, title, course_code, duration_minutes, start_datetime, end_datetime, instructions, marking_scheme,
        questions_to_answer, shuffle_enabled, grading_mode, academic_year, created_by, lecturer_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
                    $academicYear,
                    $_SESSION['user_id'],
                    $lecturerId
                ]);

                // Get the last inserted ID - THIS IS THE NUMERIC ID
                $lastId = $pdo->lastInsertId();
                $optional = qodaExamOptionalColumnValues($pdo, [
                    'grace_period_minutes' => $gracePeriod,
                    'cutoff_datetime' => $cutoffDatetime,
                    'exam_control_status' => 'active',
                    'pause_started_at' => null,
                    'paused_seconds_total' => 0
                ]);
                if ($optional) {
                    $sets = implode(', ', array_map(fn($column) => "`$column` = ?", array_keys($optional)));
                    $pdo->prepare("UPDATE exams SET {$sets} WHERE id = ?")
                        ->execute([...array_values($optional), $lastId]);
                }
                qodaSyncExamQuestionDetails($pdo, (int)$lastId, $_POST['questions'] ?? '[]');

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
                    $stmt = $pdo->prepare("SELECT id, user_id, username, title, full_name, email, profile_pic, staff_id, department, faculty, levels_taught, classes, courses FROM users WHERE id = ? AND role = 'LECTURER' AND COALESCE(status, 'Active') <> 'Deleted'");
                    $stmt->execute([$lecturerId]);
                    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$profile) {
                        echo json_encode(['success' => false, 'error' => 'Profile not found']);
                        break;
                    }
                    $courseStmt = $pdo->prepare("SELECT id, course_code, course_name, level FROM lecturer_courses WHERE lecturer_id = ? ORDER BY course_code, level");
                    $courseStmt->execute([$lecturerId]);
                    $profile['teaching_courses'] = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
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
                    $extension = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];

                    if (!in_array($extension, $allowed, true)) {
                        echo json_encode(['success' => false, 'error' => 'Invalid file type']);
                        break;
                    }
                    if (($_FILES['profile_pic']['size'] ?? 0) > 2 * 1024 * 1024) {
                        echo json_encode(['success' => false, 'error' => 'Profile picture must be 2MB or smaller']);
                        break;
                    }
                    $tmpPath = $_FILES['profile_pic']['tmp_name'];
                    $mime = mime_content_type($tmpPath) ?: ('image/' . ($extension === 'jpg' ? 'jpeg' : $extension));
                    $profilePicDataUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($tmpPath));

                    $uploadDir = __DIR__ . '/uploads/profile_pictures';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }
                    $fileName = 'lecturer_' . (int)$_SESSION['user_id'] . '_' . time() . '.' . $extension;
                    $absolutePath = $uploadDir . '/' . $fileName;
                    if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $absolutePath)) {
                        echo json_encode(['success' => false, 'error' => 'Could not save uploaded profile picture']);
                        break;
                    }
                    $profilePicUrl = 'uploads/profile_pictures/' . $fileName;

                    $stmt = $pdo->prepare("UPDATE users SET profile_pic = ?, updated_at = NOW() WHERE id = ?");
                    try {
                        $stmt->execute([$profilePicDataUrl, $_SESSION['user_id']]);
                        $profilePicUrl = $profilePicDataUrl;
                    } catch (Throwable $imageStoreError) {
                        error_log('Profile picture data-url storage failed, using file path fallback: ' . $imageStoreError->getMessage());
                        try {
                            $stmt->execute(['uploads/profile_pictures/' . $fileName, $_SESSION['user_id']]);
                        } catch (Throwable $pathStoreError) {
                            error_log('Profile picture file-path storage also failed: ' . $pathStoreError->getMessage());
                        }
                    }

                    echo json_encode(['success' => true, 'url' => $profilePicUrl, 'preview_url' => $profilePicDataUrl]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
                }
                break;
            case 'save_lecturer_course':
                try {
                    $courseCode = strtoupper(trim($_POST['course_code'] ?? ''));
                    $courseName = trim($_POST['course_name'] ?? '');
                    $level = trim($_POST['level'] ?? '');
                    if ($courseCode === '' || $courseName === '' || $level === '') {
                        echo json_encode(['success' => false, 'error' => 'Course code, course name, and level are required']);
                        break;
                    }
                    $stmt = $pdo->prepare("
                        INSERT INTO lecturer_courses (lecturer_id, course_code, course_name, level)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE course_name = VALUES(course_name), updated_at = NOW()
                    ");
                    $stmt->execute([$lecturerId, $courseCode, $courseName, $level]);
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'delete_lecturer_account':
                try {
                    $password = $_POST['password'] ?? '';
                    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? AND role = 'LECTURER'");
                    $stmt->execute([$lecturerId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$user || !password_verify($password, $user['password'])) {
                        echo json_encode(['success' => false, 'error' => 'Password confirmation failed']);
                        break;
                    }
                    $stmt = $pdo->prepare("UPDATE users SET status = 'Deleted', deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND role = 'LECTURER'");
                    $stmt->execute([$lecturerId]);
                    session_unset();
                    session_destroy();
                    echo json_encode(['success' => true, 'redirect' => 'login.php']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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

                    $stmt = $pdo->prepare("UPDATE students SET password = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$hashedPassword, $studentId]);

                    echo json_encode(['success' => true, 'message' => 'Password reset to Student ID']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'migrate_student_level':
                try {
                    $studentDbId = intval($_POST['student_id'] ?? 0);
                    $newLevel = trim((string)($_POST['level'] ?? ''));
                    $allowedLevels = ['100', '200', '300', '400', '500'];
                    if ($studentDbId <= 0 || !in_array($newLevel, $allowedLevels, true)) {
                        echo json_encode(['success' => false, 'error' => 'Select a valid student and level']);
                        break;
                    }

                    $stmt = $pdo->prepare("SELECT student_id, full_name, level FROM students WHERE id = ?");
                    $stmt->execute([$studentDbId]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$student) {
                        echo json_encode(['success' => false, 'error' => 'Student not found']);
                        break;
                    }

                    $oldLevel = (string)($student['level'] ?? '');
                    $stmt = $pdo->prepare("UPDATE students SET level = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$newLevel, $studentDbId]);

                    try {
                        $audit = $pdo->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, description, ip_address) VALUES (?, 'LECTURER', 'STUDENT_LEVEL_MIGRATION', ?, ?)");
                        $audit->execute([
                            $lecturerId,
                            'Migrated ' . ($student['student_id'] ?? $studentDbId) . ' from level ' . ($oldLevel ?: 'not set') . ' to level ' . $newLevel,
                            $_SERVER['REMOTE_ADDR'] ?? ''
                        ]);
                    } catch (Throwable $auditError) {
                        error_log('student level migration audit skipped: ' . $auditError->getMessage());
                    }

                    echo json_encode([
                        'success' => true,
                        'message' => ($student['full_name'] ?? 'Student') . ' moved to level ' . $newLevel
                    ]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'bulk_migrate_student_level':
                try {
                    $newLevel = trim((string)($_POST['level'] ?? ''));
                    $allowedLevels = ['100', '200', '300', '400', '500'];
                    $ids = json_decode((string)($_POST['student_ids'] ?? '[]'), true);
                    $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];

                    if (!in_array($newLevel, $allowedLevels, true)) {
                        echo json_encode(['success' => false, 'error' => 'Select a valid destination level']);
                        break;
                    }

                    if ($ids) {
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $params = array_merge([$newLevel], $ids, [$lecturerId]);
                        $stmt = $pdo->prepare("UPDATE students SET level = ?, updated_at = NOW() WHERE id IN ($placeholders) AND lecturer_id = ?");
                        $stmt->execute($params);
                    } else {
                        $stmt = $pdo->prepare("UPDATE students SET level = ?, updated_at = NOW() WHERE lecturer_id = ?");
                        $stmt->execute([$newLevel, $lecturerId]);
                    }

                    $count = $stmt->rowCount();
                    try {
                        $audit = $pdo->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, description, ip_address) VALUES (?, 'LECTURER', 'BULK_STUDENT_LEVEL_MIGRATION', ?, ?)");
                        $audit->execute([
                            $lecturerId,
                            'Bulk migrated ' . $count . ' student(s) to level ' . $newLevel,
                            $_SERVER['REMOTE_ADDR'] ?? ''
                        ]);
                    } catch (Throwable $auditError) {
                        error_log('bulk student level migration audit skipped: ' . $auditError->getMessage());
                    }

                    echo json_encode(['success' => true, 'count' => $count, 'message' => "Migrated {$count} student(s) to Level {$newLevel}"]);
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
                    $gracePeriod = max(0, intval($_POST['grace_period_minutes'] ?? 0));
                    $cutoffDatetime = normalizeDateTimeInput($_POST['cutoff_datetime'] ?? null);
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
                    $academicYear = trim($_POST['academic_year'] ?? '');
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
                    if (!$cutoffDatetime && $endDatetime && $gracePeriod > 0) {
                        $cutoffDatetime = date('Y-m-d H:i:s', strtotime($endDatetime) + ($gracePeriod * 60));
                    }
                    if ($cutoffDatetime && $endDatetime && strtotime($cutoffDatetime) < strtotime($endDatetime)) {
                        $cutoffDatetime = $endDatetime;
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
                school_type, academic_year, level, exam_code, auto_grading_enabled,
                partial_grading_enabled, show_correct_answers, allow_review,
                published, created_by, lecturer_id, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW()
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
                        $academicYear,
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
                    $optional = qodaExamOptionalColumnValues($pdo, [
                        'grace_period_minutes' => $gracePeriod,
                        'cutoff_datetime' => $cutoffDatetime,
                        'exam_control_status' => 'active',
                        'pause_started_at' => null,
                        'paused_seconds_total' => 0
                    ]);
                    if ($optional) {
                        $sets = implode(', ', array_map(fn($column) => "`$column` = ?", array_keys($optional)));
                        $pdo->prepare("UPDATE exams SET {$sets} WHERE id = ?")
                            ->execute([...array_values($optional), $lastId]);
                    }
                    qodaSyncExamQuestionDetails($pdo, (int)$lastId, $questionsJson);

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
                    $gracePeriod = max(0, intval($_POST['grace_period_minutes'] ?? 0));
                    $cutoffDatetime = normalizeDateTimeInput($_POST['cutoff_datetime'] ?? null);
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
                    $academicYear = trim($_POST['academic_year'] ?? '');
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
                    if (!$cutoffDatetime && $endDatetime && $gracePeriod > 0) {
                        $cutoffDatetime = date('Y-m-d H:i:s', strtotime($endDatetime) + ($gracePeriod * 60));
                    }
                    if ($cutoffDatetime && $endDatetime && strtotime($cutoffDatetime) < strtotime($endDatetime)) {
                        $cutoffDatetime = $endDatetime;
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

                    $publishWarning = null;
                    $usedMinimalPublish = false;

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
                    academic_year = ?,
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

                        $updateParams = [
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
                            $academicYear,
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
                        ];
                        try {
                            $stmt->execute($updateParams);
                        } catch (Throwable $storageError) {
                            if (!qodaIsStorageFullError($storageError)) {
                                throw $storageError;
                            }
                            qodaPruneLiveScreenCaptures($pdo, true);
                            $updateParams[7] = qodaCompactQuestionsForExamStorage($questionsJson);
                            try {
                                $stmt->execute($updateParams);
                                $questionsJson = $updateParams[7];
                            } catch (Throwable $compactError) {
                                if (!qodaIsStorageFullError($compactError)) {
                                    throw $compactError;
                                }
                                qodaMarkExistingExamPublishedMinimal($pdo, $examId, $lecturerId);
                                $usedMinimalPublish = true;
                                $publishWarning = 'Railway MySQL is full, so QODA published the last saved copy of this exam. Some latest edits may remain only in your browser draft until database storage is freed.';
                            }
                        }
                    } else {
                        // ===== INSERT NEW EXAM - INCLUDING ALL COLUMNS =====
                        $newExamId = 'EXAM-' . strtoupper(uniqid());
                        $stmt = $pdo->prepare("
                INSERT INTO exams (
                    exam_id, title, course_code, duration_minutes, start_datetime, end_datetime,
                    instructions, marking_scheme, questions, questions_to_answer,
                    shuffle_enabled, grading_mode, exam_password, require_password,
                    school_name, faculty_name, department, semester, exam_type,
                    school_type, academic_year, level, exam_code, auto_grading_enabled,
                    partial_grading_enabled, show_correct_answers, allow_review,
                    total_marks, published, status, published_at, created_by, lecturer_id, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, 1, 'published', NOW(), ?, ?, NOW()
                )
            ");

                        $insertParams = [
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
                            $academicYear,
                            $level,
                            $examCode,
                            $autoGradingEnabled,
                            $partialGradingEnabled,
                            $showCorrectAnswers,
                            $allowReview,
                            $totalMarks,
                            $lecturerId,
                            $lecturerId
                        ];
                        try {
                            $stmt->execute($insertParams);
                        } catch (Throwable $storageError) {
                            if (!qodaIsStorageFullError($storageError)) {
                                throw $storageError;
                            }
                            qodaPruneLiveScreenCaptures($pdo, true);
                            $insertParams[8] = qodaCompactQuestionsForExamStorage($questionsJson);
                            $stmt->execute($insertParams);
                            $questionsJson = $insertParams[8];
                        }

                        $examId = $pdo->lastInsertId();
                    }

                    if (!$usedMinimalPublish) {
                        try {
                            $optional = qodaExamOptionalColumnValues($pdo, [
                                'grace_period_minutes' => $gracePeriod,
                                'cutoff_datetime' => $cutoffDatetime,
                                'exam_control_status' => 'active',
                                'pause_started_at' => null
                            ]);
                            if ($optional) {
                                $sets = implode(', ', array_map(fn($column) => "`$column` = ?", array_keys($optional)));
                                $pdo->prepare("UPDATE exams SET {$sets}, updated_at = NOW() WHERE id = ? AND (created_by = ? OR lecturer_id = ?)")
                                    ->execute([...array_values($optional), $examId, $lecturerId, $lecturerId]);
                            }

                            // Also add to exam_class_access for course filtering
                            $checkAccess = $pdo->prepare("SELECT id FROM exam_class_access WHERE exam_id = ? AND class_code = ?");
                            $checkAccess->execute([$examId, $courseCode]);

                            if ($checkAccess->rowCount() == 0) {
                                $accessStmt = $pdo->prepare("INSERT INTO exam_class_access (exam_id, class_code, class_name, access_granted) VALUES (?, ?, ?, 1)");
                                $accessStmt->execute([$examId, $courseCode, $title]);
                            }

                            qodaSyncExamQuestionDetails($pdo, (int)$examId, $questionsJson);
                        } catch (Throwable $postPublishError) {
                            if (!qodaIsStorageFullError($postPublishError)) {
                                throw $postPublishError;
                            }
                            $publishWarning = $publishWarning ?: 'Exam published, but Railway MySQL is full so some secondary sync data could not be updated.';
                        }
                    }

                    echo json_encode([
                        'success' => true,
                        'message' => $publishWarning ?: 'Exam published successfully',
                        'warning' => $publishWarning,
                        'exam_id' => $examId
                    ]);
                } catch (Exception $e) {
                    error_log("Publish exam error: " . $e->getMessage());
                    if (qodaIsStorageFullError($e)) {
                        echo json_encode(['success' => false, 'error' => 'The Railway MySQL database storage is full. QODA tried a compact exam save, but MySQL still rejected the publish. Please free database storage or upgrade the Railway database, then publish again.']);
                    } else {
                        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                    }
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
                    $pub = $pdo->prepare("
                        INSERT INTO result_publications (exam_id, course_code, semester, published_by, published_at, notes)
                        SELECT id, course_code, semester, ?, NOW(), 'Published from lecturer dashboard'
                        FROM exams
                        WHERE id = ?
                    ");
                    try {
                        $pub->execute([$lecturerId, $examId]);
                    } catch (Throwable $ignored) {
                        error_log('Result publication log skipped: ' . $ignored->getMessage());
                    }
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'adjust_exam_time':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $deltaMinutes = intval($_POST['delta_minutes'] ?? 0);
                    $newEndInput = trim((string)($_POST['new_end_datetime'] ?? ''));
                    $reason = trim((string)($_POST['reason'] ?? 'Time adjusted by lecturer'));

                    $stmt = $pdo->prepare("SELECT id, start_datetime, end_datetime, duration_minutes FROM exams WHERE id = ? AND (lecturer_id = ? OR created_by = ?) LIMIT 1");
                    $stmt->execute([$examId, $lecturerId, $lecturerId]);
                    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$exam) {
                        echo json_encode(['success' => false, 'error' => 'Exam not found or not allowed']);
                        break;
                    }

                    $oldStart = $exam['start_datetime'] ?: null;
                    $oldEnd = $exam['end_datetime'] ?: null;
                    $startTs = $oldStart ? strtotime($oldStart) : time();
                    $oldEndTs = $oldEnd ? strtotime($oldEnd) : ($startTs + (max(1, intval($exam['duration_minutes'] ?? 180)) * 60));
                    $newEndTs = $newEndInput !== '' ? strtotime($newEndInput) : ($oldEndTs + ($deltaMinutes * 60));
                    if (!$newEndTs || $newEndTs <= $startTs) {
                        echo json_encode(['success' => false, 'error' => 'New end time must be after the start time']);
                        break;
                    }

                    $newEnd = date('Y-m-d H:i:s', $newEndTs);
                    $newDuration = max(1, (int)round(($newEndTs - $startTs) / 60));
                    $pdo->beginTransaction();
                    $upd = $pdo->prepare("UPDATE exams SET end_datetime = ?, duration_minutes = ?, updated_at = NOW() WHERE id = ?");
                    $upd->execute([$newEnd, $newDuration, $examId]);
                    $log = $pdo->prepare("
                        INSERT INTO exam_time_adjustments
                            (exam_id, adjusted_by, old_start_datetime, old_end_datetime, new_start_datetime, new_end_datetime, delta_minutes, reason)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $actualDelta = (int)round(($newEndTs - $oldEndTs) / 60);
                    $log->execute([$examId, $lecturerId, $oldStart, $oldEnd ? date('Y-m-d H:i:s', $oldEndTs) : null, $oldStart, $newEnd, $actualDelta, $reason]);
                    $pdo->commit();

                    echo json_encode(['success' => true, 'new_end_datetime' => $newEnd, 'duration_minutes' => $newDuration, 'delta_minutes' => $actualDelta]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'manage_exam_time':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $control = strtolower(trim((string)($_POST['control'] ?? '')));
                    $minutes = intval($_POST['minutes'] ?? 0);
                    $newCutoffInput = normalizeDateTimeInput($_POST['cutoff_datetime'] ?? null);
                    $message = trim((string)($_POST['message'] ?? ''));

                    $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND (lecturer_id = ? OR created_by = ?) LIMIT 1");
                    $stmt->execute([$examId, $lecturerId, $lecturerId]);
                    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$exam) {
                        echo json_encode(['success' => false, 'error' => 'Exam not found or not allowed']);
                        break;
                    }

                    $hasControlColumns = qodaColumnExists($pdo, 'exams', 'exam_control_status')
                        && qodaColumnExists($pdo, 'exams', 'pause_started_at')
                        && qodaColumnExists($pdo, 'exams', 'paused_seconds_total');
                    $hasCutoff = qodaColumnExists($pdo, 'exams', 'cutoff_datetime');
                    $oldStart = $exam['start_datetime'] ?: null;
                    $oldEnd = $exam['end_datetime'] ?: null;
                    $startTs = $oldStart ? strtotime($oldStart) : time();
                    $oldEndTs = $oldEnd ? strtotime($oldEnd) : ($startTs + (max(1, intval($exam['duration_minutes'] ?? 180)) * 60));
                    $newEndTs = $oldEndTs;
                    $notice = '';

                    if ($control === 'pause') {
                        if (!$hasControlColumns) {
                            echo json_encode(['success' => false, 'error' => 'Pause controls need the exam timing columns to be created first.']);
                            break;
                        }
                        $pdo->prepare("UPDATE exams SET exam_control_status = 'paused', pause_started_at = COALESCE(pause_started_at, NOW()), updated_at = NOW() WHERE id = ?")
                            ->execute([$examId]);
                        $notice = 'The examination has been temporarily paused by the lecturer.';
                    } elseif ($control === 'resume') {
                        if (!$hasControlColumns) {
                            echo json_encode(['success' => false, 'error' => 'Resume controls need the exam timing columns to be created first.']);
                            break;
                        }
                        $pauseStarted = !empty($exam['pause_started_at']) ? strtotime($exam['pause_started_at']) : false;
                        $pausedSeconds = $pauseStarted ? max(0, time() - $pauseStarted) : 0;
                        $newEndTs = $oldEndTs + $pausedSeconds;
                        $newEnd = date('Y-m-d H:i:s', $newEndTs);
                        $newDuration = max(1, (int)round(($newEndTs - $startTs) / 60));
                        $pdo->prepare("
                            UPDATE exams
                            SET exam_control_status = 'active',
                                pause_started_at = NULL,
                                paused_seconds_total = COALESCE(paused_seconds_total, 0) + ?,
                                end_datetime = ?,
                                duration_minutes = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$pausedSeconds, $newEnd, $newDuration, $examId]);
                        $notice = 'The examination timer has resumed.';
                    } elseif (in_array($control, ['add_time', 'reduce_time'], true)) {
                        $delta = $control === 'reduce_time' ? -abs($minutes) : abs($minutes);
                        if ($delta === 0) {
                            echo json_encode(['success' => false, 'error' => 'Enter the number of minutes to adjust.']);
                            break;
                        }
                        $newEndTs = max($startTs + 60, $oldEndTs + ($delta * 60));
                        $newEnd = date('Y-m-d H:i:s', $newEndTs);
                        $newDuration = max(1, (int)round(($newEndTs - $startTs) / 60));
                        $pdo->prepare("UPDATE exams SET end_datetime = ?, duration_minutes = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$newEnd, $newDuration, $examId]);
                        if ($hasCutoff && !empty($exam['cutoff_datetime'])) {
                            $cutoffTs = strtotime($exam['cutoff_datetime']);
                            if ($cutoffTs && $cutoffTs < $newEndTs) {
                                $pdo->prepare("UPDATE exams SET cutoff_datetime = ? WHERE id = ?")->execute([$newEnd, $examId]);
                            }
                        }
                        $notice = $delta > 0
                            ? "The lecturer has added {$delta} minutes to this examination."
                            : 'The lecturer has reduced the examination time.';
                    } elseif ($control === 'extend_cutoff') {
                        if (!$hasCutoff) {
                            echo json_encode(['success' => false, 'error' => 'Cut-off control needs the cutoff_datetime column to be created first.']);
                            break;
                        }
                        if (!$newCutoffInput || strtotime($newCutoffInput) === false) {
                            echo json_encode(['success' => false, 'error' => 'Enter a valid cut-off date and time.']);
                            break;
                        }
                        if (strtotime($newCutoffInput) < $oldEndTs) {
                            echo json_encode(['success' => false, 'error' => 'Cut-off time cannot be earlier than the exam end time.']);
                            break;
                        }
                        $pdo->prepare("UPDATE exams SET cutoff_datetime = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$newCutoffInput, $examId]);
                        $notice = 'The examination cut-off time has been extended.';
                    } elseif ($control === 'reopen_submissions') {
                        $pdo->prepare("
                            UPDATE exam_submissions
                            SET status = 'in_progress', submitted = 0, submitted_at = NULL, submittedAt = NULL, updated_at = NOW()
                            WHERE exam_id = ? AND UPPER(COALESCE(status, '')) IN ('TIMED_OUT', 'SUBMITTED')
                        ")->execute([$examId]);
                        $notice = 'Submissions have been reopened by the lecturer.';
                    } elseif ($control === 'announcement') {
                        $notice = $message !== '' ? $message : 'Announcement from your lecturer.';
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Unknown exam timing control.']);
                        break;
                    }

                    if ($message !== '') {
                        $notice = $message;
                    }
                    if ($notice !== '') {
                        $students = $pdo->prepare("
                            SELECT DISTINCT s.id
                            FROM students s
                            JOIN course_enrollments ce ON ce.student_id = s.id
                            WHERE ce.course_code = ? AND ce.lecturer_id = ?
                        ");
                        $students->execute([$exam['course_code'] ?? '', $lecturerId]);
                        $command = $pdo->prepare("INSERT INTO proctor_commands (exam_id, student_id, command_type, message, created_by) VALUES (?, ?, 'warning', ?, ?)");
                        foreach ($students->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
                            $command->execute([$examId, (int)$studentId, $notice, $lecturerId]);
                        }
                    }

                    if (in_array($control, ['resume', 'add_time', 'reduce_time'], true)) {
                        try {
                            $actualDelta = (int)round(($newEndTs - $oldEndTs) / 60);
                            $log = $pdo->prepare("
                                INSERT INTO exam_time_adjustments
                                    (exam_id, adjusted_by, old_start_datetime, old_end_datetime, new_start_datetime, new_end_datetime, delta_minutes, reason)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $log->execute([$examId, $lecturerId, $oldStart, $oldEnd ? date('Y-m-d H:i:s', $oldEndTs) : null, $oldStart, date('Y-m-d H:i:s', $newEndTs), $actualDelta, $control]);
                        } catch (Throwable $logError) {
                            error_log('Exam time control log skipped: ' . $logError->getMessage());
                        }
                    }

                    echo json_encode(['success' => true, 'message' => $notice]);
                } catch (Throwable $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'force_submit_student':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $stmt = $pdo->prepare("
                        UPDATE exam_submissions es
                        JOIN exams e ON e.id = es.exam_id
                        LEFT JOIN students s ON s.id = ? OR s.user_id = ?
                        SET es.status = 'submitted',
                            es.submitted = 1,
                            es.submitted_at = COALESCE(es.submitted_at, NOW()),
                            es.submittedAt = COALESCE(es.submittedAt, NOW()),
                            es.updated_at = NOW()
                        WHERE es.exam_id = ?
                          AND (es.student_id = ? OR es.student_id = s.id OR es.student_id = s.user_id)
                          AND (e.lecturer_id = ? OR e.created_by = ?)
                    ");
                    $stmt->execute([$studentId, $studentId, $examId, $studentId, $lecturerId, $lecturerId]);
                    echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
                } catch (Throwable $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_question_bank':
                try {
                    $course = trim((string)($_POST['course_code'] ?? ''));
                    $language = trim((string)($_POST['language'] ?? ''));
                    $semester = trim((string)($_POST['semester'] ?? ''));
                    $year = trim((string)($_POST['academic_year'] ?? $_POST['year'] ?? ''));
                    $search = strtolower(trim((string)($_POST['search'] ?? $_POST['topic'] ?? '')));
                    $difficulty = strtolower(trim((string)($_POST['difficulty'] ?? '')));

                    $examStmt = $pdo->prepare("
                        SELECT id, title, course_code, questions, semester, academic_year, start_datetime, created_at
                        FROM exams
                        WHERE (lecturer_id = ? OR created_by = ?)
                          AND questions IS NOT NULL
                        ORDER BY created_at DESC
                        LIMIT 250
                    ");
                    $examStmt->execute([$lecturerId, $lecturerId]);
                    $items = [];
                    foreach ($examStmt->fetchAll(PDO::FETCH_ASSOC) as $exam) {
                        $questions = json_decode($exam['questions'] ?? '[]', true);
                        if (!is_array($questions)) continue;
                        foreach ($questions as $idx => $question) {
                            $qCourse = (string)($exam['course_code'] ?? '');
                            $qLanguage = (string)($question['language'] ?? $question['type'] ?? '');
                            $qSemester = (string)($exam['semester'] ?? '');
                            $qYear = (string)($exam['academic_year'] ?? '');
                            $qDifficulty = strtolower((string)($question['difficulty'] ?? ''));
                            $prompt = (string)($question['text'] ?? $question['prompt'] ?? $question['title'] ?? '');
                            if ($course !== '' && strcasecmp($qCourse, $course) !== 0) continue;
                            if ($language !== '' && strcasecmp($qLanguage, $language) !== 0) continue;
                            if ($semester !== '' && strcasecmp($qSemester, $semester) !== 0) continue;
                            if ($year !== '' && stripos($qYear, $year) === false && stripos((string)$exam['start_datetime'], $year) === false) continue;
                            if ($difficulty !== '' && $qDifficulty !== $difficulty) continue;
                            if ($search !== '' && stripos(strtolower($prompt . ' ' . json_encode($question)), $search) === false) continue;
                            $items[] = [
                                'source' => 'exam',
                                'source_exam_id' => (int)$exam['id'],
                                'source_exam_title' => $exam['title'],
                                'question_index' => $idx,
                                'course_code' => $qCourse,
                                'course_name' => $exam['title'],
                                'language' => $qLanguage,
                                'semester' => $qSemester,
                                'academic_year' => $qYear,
                                'year' => $exam['start_datetime'] ? date('Y', strtotime($exam['start_datetime'])) : date('Y', strtotime($exam['created_at'] ?? 'now')),
                                'difficulty' => $question['difficulty'] ?? '',
                                'marks' => $question['marks'] ?? 0,
                                'title' => $question['title'] ?? ('Question ' . ($idx + 1)),
                                'prompt' => $prompt,
                                'question' => $question
                            ];
                        }
                    }

                    echo json_encode(['success' => true, 'data' => $items]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'save_question_to_bank':
                try {
                    $courseCode = trim((string)($_POST['course_code'] ?? ''));
                    $courseName = trim((string)($_POST['course_name'] ?? ''));
                    $topic = trim((string)($_POST['topic'] ?? ''));
                    $difficulty = trim((string)($_POST['difficulty'] ?? ''));
                    $language = trim((string)($_POST['language'] ?? ''));
                    $semester = trim((string)($_POST['semester'] ?? ''));
                    $academicYear = trim((string)($_POST['academic_year'] ?? ''));
                    $title = trim((string)($_POST['title'] ?? 'Coding Question'));
                    $prompt = trim((string)($_POST['prompt'] ?? ''));
                    $questionJson = (string)($_POST['question_json'] ?? '{}');
                    $testCases = (string)($_POST['test_cases'] ?? '[]');
                    $marks = floatval($_POST['marks'] ?? 0);
                    $sourceExamId = intval($_POST['source_exam_id'] ?? 0) ?: null;

                    if ($prompt === '') {
                        echo json_encode(['success' => false, 'error' => 'Question text is required before saving to the question bank']);
                        break;
                    }

                    json_decode($questionJson, true);
                    if (json_last_error() !== JSON_ERROR_NONE) $questionJson = '{}';
                    json_decode($testCases, true);
                    if (json_last_error() !== JSON_ERROR_NONE) $testCases = '[]';

                    $stmt = $pdo->prepare("
                        INSERT INTO question_bank
                            (lecturer_id, course_code, course_name, topic, difficulty, language, semester, academic_year,
                             title, prompt, question_json, test_cases, marks, source_exam_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $lecturerId,
                        $courseCode,
                        $courseName,
                        $topic,
                        $difficulty,
                        $language,
                        $semester,
                        $academicYear,
                        $title,
                        $prompt,
                        $questionJson,
                        $testCases,
                        $marks,
                        $sourceExamId
                    ]);

                    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
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

            case 'get_active_student_sessions':
                try {
                    qodaEnsureStudentSessionMonitorTables($pdo);
                    $stmt = $pdo->prepare("
                        SELECT
                            sas.id,
                            sas.student_id,
                            sas.student_identifier,
                            sas.device_id,
                            sas.operating_system,
                            sas.ip_address,
                            sas.login_at,
                            sas.last_seen,
                            sas.locked_exam_id,
                            s.full_name,
                            s.level,
                            s.programme
                        FROM student_active_sessions sas
                        JOIN students s ON s.id = sas.student_id
                        WHERE sas.active = 1 AND s.lecturer_id = ?
                        ORDER BY sas.last_seen DESC
                    ");
                    $stmt->execute([$lecturerId]);
                    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Throwable $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'force_logout_student_session':
                try {
                    qodaEnsureStudentSessionMonitorTables($pdo);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $sessionRecordId = intval($_POST['session_record_id'] ?? 0);
                    if ($studentId <= 0 && $sessionRecordId <= 0) {
                        echo json_encode(['success' => false, 'error' => 'Student session is required']);
                        break;
                    }

                    $where = $sessionRecordId > 0 ? 'sas.id = ?' : 'sas.student_id = ?';
                    $param = $sessionRecordId > 0 ? $sessionRecordId : $studentId;
                    $stmt = $pdo->prepare("
                        UPDATE student_active_sessions sas
                        JOIN students s ON s.id = sas.student_id
                        SET sas.active = 0,
                            sas.released_at = NOW(),
                            sas.release_reason = 'lecturer_force_logout'
                        WHERE $where AND s.lecturer_id = ? AND sas.active = 1
                    ");
                    $stmt->execute([$param, $lecturerId]);

                    $event = $pdo->prepare("
                        INSERT INTO student_session_events (student_id, event_type, details)
                        VALUES (?, 'lecturer_force_logout', ?)
                    ");
                    $event->execute([$studentId ?: null, 'Lecturer terminated the active student session for emergency recovery or suspected multi-device use.']);

                    echo json_encode(['success' => true, 'released' => $stmt->rowCount()]);
                } catch (Throwable $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'get_student_session_history':
                try {
                    qodaEnsureStudentSessionMonitorTables($pdo);
                    $studentId = intval($_POST['student_id'] ?? 0);
                    $stmt = $pdo->prepare("
                        SELECT
                            sse.event_type,
                            sse.device_id,
                            sse.operating_system,
                            sse.ip_address,
                            sse.exam_id,
                            sse.details,
                            sse.created_at
                        FROM student_session_events sse
                        JOIN students s ON s.id = sse.student_id
                        WHERE s.lecturer_id = ? AND (? = 0 OR sse.student_id = ?)
                        ORDER BY sse.created_at DESC
                        LIMIT 120
                    ");
                    $stmt->execute([$lecturerId, $studentId, $studentId]);
                    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Throwable $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'update_exam':
                try {
                    $examId = intval($_POST['exam_id'] ?? 0);
                    $duration = max(1, intval($_POST['duration'] ?? 180));
                    $startDatetime = normalizeDateTimeInput($_POST['start_datetime'] ?? null);
                    $endDatetime = normalizeExamEndTime($startDatetime, $_POST['end_datetime'] ?? null, $duration);
                    $gracePeriod = max(0, intval($_POST['grace_period_minutes'] ?? 0));
                    $cutoffDatetime = normalizeDateTimeInput($_POST['cutoff_datetime'] ?? null);
                    if (!$cutoffDatetime && $endDatetime && $gracePeriod > 0) {
                        $cutoffDatetime = date('Y-m-d H:i:s', strtotime($endDatetime) + ($gracePeriod * 60));
                    }
                    if ($cutoffDatetime && $endDatetime && strtotime($cutoffDatetime) < strtotime($endDatetime)) {
                        $cutoffDatetime = $endDatetime;
                    }
                    $academicYear = trim($_POST['academic_year'] ?? '');
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
                academic_year = ?,
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

                    $updateParams = [
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
                        $academicYear,
                        $_POST['level'] ?? '',
                        $_POST['exam_code'] ?? '',
                        intval($_POST['auto_grading_enabled'] ?? 0),
                        intval($_POST['partial_grading_enabled'] ?? 0),
                        intval($_POST['show_correct_answers'] ?? 0),
                        intval($_POST['allow_review'] ?? 1),
                        $examId,
                        $lecturerId,
                        $lecturerId
                    ];
                    try {
                        $stmt->execute($updateParams);
                    } catch (Throwable $storageError) {
                        if (!qodaIsStorageFullError($storageError)) {
                            throw $storageError;
                        }
                        $updateParams[7] = qodaCompactQuestionsForExamStorage((string)($_POST['questions'] ?? '[]'));
                        $stmt->execute($updateParams);
                    }

                    $optional = qodaExamOptionalColumnValues($pdo, [
                        'grace_period_minutes' => $gracePeriod,
                        'cutoff_datetime' => $cutoffDatetime
                    ]);
                    if ($optional) {
                        $sets = implode(', ', array_map(fn($column) => "`$column` = ?", array_keys($optional)));
                        $pdo->prepare("UPDATE exams SET {$sets}, updated_at = NOW() WHERE id = ? AND (created_by = ? OR lecturer_id = ?)")
                            ->execute([...array_values($optional), $examId, $lecturerId, $lecturerId]);
                    }

                    qodaSyncExamQuestionDetails($pdo, (int)$examId, $_POST['questions'] ?? '[]');

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
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs/loader.js"></script>
    <script src="/socket.io/socket.io.js"></script>
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
        background: var(--card);
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 10px rgba(79, 70, 229, .3);
        flex-shrink: 0;
        overflow: hidden;
        border: 1px solid var(--border);
    }

    .header-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
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

        .header-logo img {
            width: 100%;
            height: 100%;
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

    .nav-icon:hover .tooltip-text,
    .profile-icon:hover .tooltip-text,
    .theme-switch-icon:hover .tooltip-text,
    .logout-icon:hover .tooltip-text {
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

    .proctoring-control-panel {
        display: grid;
        grid-template-columns: minmax(280px, 420px) 1fr;
        gap: 16px;
        align-items: stretch;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(16, 185, 129, 0.08));
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 16px;
        margin-bottom: 18px;
    }

    .proctoring-select-card,
    .proctoring-actions-card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 14px;
    }

    .proctoring-select-card label {
        display: block;
        color: var(--muted);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
        margin-bottom: 8px;
    }

    .proctoring-exam-select {
        width: 100%;
        min-height: 46px;
        padding: 0 14px;
        border-radius: 12px;
        border: 2px solid rgba(59, 130, 246, 0.25);
        background: var(--input-bg, var(--bg));
        color: var(--text);
        font-weight: 700;
        outline: none;
    }

    .proctoring-exam-select:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, .14);
    }

    .proctoring-actions-title {
        color: var(--muted);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
        margin-bottom: 10px;
    }

    .proctoring-button-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
    }

    .proctoring-button-grid .btn {
        width: 100%;
        justify-content: center;
    }

    @media (max-width: 900px) {
        .proctoring-control-panel {
            grid-template-columns: 1fr;
        }
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

    .exam-created-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 14px;
        align-items: stretch;
        margin-top: 18px;
    }

    .exam-created-card {
        background: #ffffff;
        color: #0f172a;
        border: 1px solid #dbe3ef;
        border-top: 5px solid var(--accent-blue);
        border-radius: 12px;
        padding: 16px;
        box-shadow: var(--shadow);
        display: flex;
        flex-direction: column;
        gap: 12px;
        min-height: 100%;
        cursor: pointer;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }

    .exam-created-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 16px 34px rgba(15, 23, 42, .12);
    }

    .exam-created-card.is-expanded {
        cursor: default;
        grid-column: span 2;
    }

    .exam-created-card.status-ongoing { border-top: 5px solid #10b981 !important; }
    .exam-created-card.status-scheduled { border-top: 5px solid #3b82f6 !important; }
    .exam-created-card.status-completed { border-top: 5px solid #64748b !important; }
    .exam-created-card.status-draft { border-top: 5px solid #f59e0b !important; }
    .exam-created-card.status-published { border-top: 5px solid #8b5cf6 !important; }
    .exam-created-card.status-ongoing,
    .exam-created-card.status-scheduled,
    .exam-created-card.status-completed,
    .exam-created-card.status-draft,
    .exam-created-card.status-published {
        background: #ffffff !important;
        color: #0f172a !important;
        border-left: 1px solid #dbe3ef !important;
        border-right: 1px solid #dbe3ef !important;
        border-bottom: 1px solid #dbe3ef !important;
    }

    .exam-created-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
    }

    .exam-created-title {
        margin: 0;
        font-size: 18px;
        line-height: 1.25;
        color: #0f172a;
    }

    .exam-created-subtitle {
        margin-top: 6px;
        color: #475569;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: .01em;
    }

    .exam-created-hint {
        color: #64748b;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding-top: 4px;
        border-top: 1px solid #e2e8f0;
    }

    .exam-created-toggle {
        color: var(--accent-blue);
        font-weight: 800;
        white-space: nowrap;
    }

    .exam-created-card.is-expanded .exam-created-toggle {
        color: #10b981;
    }

    .exam-created-details {
        display: flex;
        flex-direction: column;
        gap: 12px;
        padding-top: 4px;
    }

    .exam-created-details[hidden] {
        display: none;
    }

    .exam-created-meta {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .exam-created-meta-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 9px 10px;
        min-width: 0;
    }

    .exam-created-meta-item small {
        display: block;
        color: #64748b;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .03em;
        margin-bottom: 4px;
    }

    .exam-created-meta-item strong {
        color: #0f172a;
        font-size: 13px;
        word-break: break-word;
    }

    .exam-created-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: auto;
    }

    .exam-instance-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 16px;
    }

    .exam-instance-card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-left: 5px solid var(--accent-blue);
        border-radius: 14px;
        padding: 16px;
        box-shadow: var(--shadow);
    }

    .question-bank-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 12px;
    }

    .question-bank-card {
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 14px;
    }

    @media (max-width: 720px) {
        .exam-created-grid,
        .exam-created-meta,
        .exam-instance-grid,
        .question-bank-grid {
            grid-template-columns: 1fr;
        }

        .exam-created-card.is-expanded {
            grid-column: auto;
        }
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
    .password-field-wrap {
        position: relative;
    }

    .password-field-wrap input {
        padding-right: 46px;
    }

    .password-toggle-btn {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        width: 34px;
        height: 34px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--panel);
        color: var(--text);
        cursor: pointer;
    }

    .structured-toolbar {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin: 6px 0 8px;
    }

    body.dark input[type="date"]::-webkit-calendar-picker-indicator,
    body.dark input[type="datetime-local"]::-webkit-calendar-picker-indicator,
    body.dark input[type="time"]::-webkit-calendar-picker-indicator {
        filter: invert(1);
    }

    body:not(.dark) input[type="date"]::-webkit-calendar-picker-indicator,
    body:not(.dark) input[type="datetime-local"]::-webkit-calendar-picker-indicator,
    body:not(.dark) input[type="time"]::-webkit-calendar-picker-indicator {
        filter: invert(0);
    }

    /* Modern coding-question authoring workspace */
    #qList {
        gap: 22px;
    }

    .qoda-author-card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 18px;
        box-shadow: 0 18px 55px rgba(15, 23, 42, .08);
        overflow: hidden;
    }

    .qoda-q-header {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        padding: 18px 20px;
        background: var(--panel);
        background: color-mix(in srgb, var(--panel) 92%, var(--accent) 8%);
        border-bottom: 1px solid var(--border);
    }

    .qoda-q-meta {
        min-width: 0;
        flex: 1 1 auto;
    }

    .qoda-q-badges {
        display: flex;
        flex-wrap: nowrap;
        gap: 8px;
        align-items: center;
        min-width: 0;
        overflow-x: auto;
        padding-bottom: 2px;
        scrollbar-width: thin;
    }

    .qoda-badge {
        flex: 0 0 auto;
        border-radius: 999px;
        padding: 7px 10px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .02em;
        text-transform: uppercase;
        background: var(--bg);
        color: var(--text);
        border: 1px solid var(--border);
    }

    .qoda-badge.primary {
        background: linear-gradient(135deg, #0284c7, #7c3aed);
        color: white;
        border: 0;
    }

    .qoda-badge.success {
        background: rgba(16, 185, 129, .16);
        color: #059669;
        border-color: rgba(16, 185, 129, .34);
    }

    .qoda-badge.warning {
        background: rgba(245, 158, 11, .16);
        color: #b45309;
        border-color: rgba(245, 158, 11, .35);
    }

    .qoda-badge.progress {
        background: rgba(59, 130, 246, .14);
        color: #1d4ed8;
        border-color: rgba(59, 130, 246, .35);
        white-space: nowrap;
    }

    .qoda-icon-actions {
        display: flex;
        flex-wrap: wrap;
        flex: 0 0 auto;
        justify-content: flex-end;
        gap: 8px;
    }

    .qoda-icon-actions .btn {
        min-height: 38px;
    }

    .qoda-author-body {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(300px, 390px);
        gap: 18px;
        padding: 18px;
        align-items: start;
    }

    .qoda-author-main {
        display: grid;
        gap: 14px;
        min-width: 0;
    }

    .qoda-author-section {
        border: 1px solid var(--border);
        border-radius: 16px;
        background: var(--bg);
        overflow: hidden;
    }

    .qoda-author-section summary {
        cursor: pointer;
        list-style: none;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        padding: 15px 17px;
        font-weight: 800;
        color: var(--text);
    }

    .qoda-author-section summary::-webkit-details-marker {
        display: none;
    }

    .qoda-author-section summary::after {
        content: "\f078";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        color: var(--muted);
        transition: transform .2s ease;
    }

    .qoda-author-section[open] summary::after {
        transform: rotate(180deg);
    }

    .qoda-section-body {
        padding: 0 17px 17px;
        display: grid;
        gap: 14px;
    }

    .qoda-field-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 12px;
    }

    .qoda-field label {
        display: block;
        margin-bottom: 7px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .qoda-field input,
    .qoda-field select,
    .qoda-field textarea {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 11px 12px;
        color: var(--text);
        background: var(--input-bg);
        outline: none;
    }

    .qoda-field textarea {
        min-height: 88px;
        resize: vertical;
        line-height: 1.55;
    }

    .qoda-rich-wrap {
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: hidden;
        background: var(--input-bg);
    }

    .qoda-rich-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        padding: 8px;
        border-bottom: 1px solid var(--border);
        background: color-mix(in srgb, var(--panel) 88%, var(--accent) 12%);
    }

    .qoda-rich-toolbar button {
        border: 1px solid var(--border);
        border-radius: 9px;
        min-width: 34px;
        min-height: 32px;
        color: var(--text);
        background: var(--panel);
        cursor: pointer;
    }

    .qoda-rich-editor {
        min-height: 118px;
        padding: 13px;
        color: var(--text);
        line-height: 1.6;
        outline: none;
    }

    .qoda-rich-editor:empty::before {
        content: attr(data-placeholder);
        color: var(--muted);
    }

    .qoda-rich-editor pre,
    .qoda-preview-panel pre {
        white-space: pre-wrap;
        background: #0f172a;
        color: #e2e8f0;
        border-radius: 10px;
        padding: 12px;
        overflow: auto;
    }

    .qoda-rich-editor table,
    .qoda-preview-panel table {
        border-collapse: collapse;
        width: 100%;
        margin: 8px 0;
    }

    .qoda-rich-editor table[data-qoda-numbered="1"] td:first-child,
    .qoda-rich-editor table[data-qoda-numbered="1"] th:first-child,
    .qoda-preview-panel table[data-qoda-numbered="1"] td:first-child,
    .qoda-preview-panel table[data-qoda-numbered="1"] th:first-child {
        width: 48px;
        text-align: center;
        color: var(--muted);
        font-weight: 800;
    }

    .qoda-rich-editor td,
    .qoda-rich-editor th,
    .qoda-preview-panel td,
    .qoda-preview-panel th {
        border: 1px solid var(--border);
        padding: 8px;
    }

    .qoda-rich-editor img,
    .qoda-preview-panel img {
        max-width: 100%;
        height: auto;
        border-radius: 10px;
        border: 1px solid var(--border);
        display: block;
        margin: 10px 0;
    }

    .qoda-case-card,
    .qoda-rubric-row {
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 13px;
        background: var(--panel);
        display: grid;
        gap: 12px;
    }

    .qoda-case-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .qoda-case-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .qoda-toggle-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        color: var(--text);
        font-weight: 700;
    }

    .qoda-preview-panel {
        position: sticky;
        top: 146px;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: var(--panel);
        min-height: 520px;
        max-height: calc(100vh - 170px);
        overflow: auto;
    }

    .qoda-preview-head {
        position: sticky;
        top: 0;
        z-index: 2;
        padding: 14px 16px;
        background: var(--panel);
        border-bottom: 1px solid var(--border);
        font-weight: 900;
        display: flex;
        justify-content: space-between;
        gap: 10px;
    }

    .qoda-preview-content {
        padding: 16px;
        display: grid;
        gap: 14px;
        color: var(--text);
    }

    .qoda-validation-list {
        display: grid;
        gap: 8px;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .qoda-validation-list li {
        display: flex;
        gap: 8px;
        align-items: center;
        color: var(--muted);
        font-size: 13px;
    }

    .qoda-validation-list li.ready {
        color: #059669;
    }

    .qoda-validation-list li.missing {
        color: #dc2626;
    }

    .qoda-validation-panel {
        margin: 14px 18px 0;
        padding: 14px;
        border: 1px solid rgba(59, 130, 246, .25);
        border-radius: 12px;
        background: color-mix(in srgb, var(--panel) 88%, #dbeafe 12%);
        display: grid;
        gap: 12px;
    }

    .qoda-validation-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        font-weight: 900;
        color: var(--text);
    }

    .qoda-validation-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 8px;
    }

    .qoda-validation-item {
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 34px;
        padding: 8px 10px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: var(--bg);
        font-size: 13px;
        font-weight: 800;
    }

    .qoda-validation-item.ready {
        color: #047857;
        border-color: rgba(16, 185, 129, .35);
        background: rgba(16, 185, 129, .1);
    }

    .qoda-validation-item.warning {
        color: #b45309;
        border-color: rgba(245, 158, 11, .35);
        background: rgba(245, 158, 11, .12);
    }

    .qoda-validation-item.missing {
        color: #dc2626;
        border-color: rgba(239, 68, 68, .35);
        background: rgba(239, 68, 68, .1);
    }

    .qoda-validation-progress {
        font-weight: 900;
        color: #1d4ed8;
    }

    .qoda-publish-toolbar {
        position: sticky;
        bottom: 0;
        z-index: 7;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 14px 18px;
        border-top: 1px solid var(--border);
        background: color-mix(in srgb, var(--panel) 94%, var(--accent) 6%);
        flex-wrap: wrap;
    }

    .qoda-toolbar-actions {
        display: flex;
        gap: 9px;
        flex-wrap: wrap;
    }

    .qoda-autosave {
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
    }

    .qoda-required {
        box-shadow: 0 0 0 2px rgba(239, 68, 68, .2);
    }

    .qoda-code-shell {
        border: 1px solid #2d2d30;
        border-radius: 10px;
        overflow: hidden;
        background: #1e1e1e;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .04);
    }

    .qoda-code-head {
        display: flex;
        align-items: stretch;
        gap: 0;
        min-height: 34px;
        color: #d4d4d4;
        background: #252526;
        border-bottom: 1px solid #2d2d30;
        font-size: 12px;
        font-weight: 700;
    }

    .qoda-code-tab {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-width: 0;
        max-width: min(360px, 70%);
        height: 34px;
        padding: 0 12px;
        color: #d4d4d4;
        background: #1e1e1e;
        border-right: 1px solid #333337;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .qoda-code-close {
        color: #a6a6a6;
        font-size: 13px;
        line-height: 1;
    }

    .qoda-code-status {
        margin-left: auto;
        display: inline-flex;
        align-items: center;
        padding: 0 12px;
        color: #9ca3af;
        font-size: 11px;
        white-space: nowrap;
    }

    .qoda-code-editor {
        width: 100%;
        min-height: 320px;
        resize: none;
        border: 0;
        outline: none;
        padding: 12px 14px 12px 56px;
        color: #d4d4d4;
        background:
            linear-gradient(90deg, #252526 0 42px, #3c3c3c 42px 43px, transparent 43px),
            #1e1e1e;
        font-family: Consolas, "SFMono-Regular", Menlo, monospace;
        font-size: 14px;
        line-height: 22px;
        tab-size: 4;
        white-space: pre;
        overflow: auto;
    }

    .qoda-monaco-editor {
        display: none;
        width: 100%;
        min-height: 320px;
        height: 320px;
        border: 0;
    }

    .qoda-code-shell.monaco-ready .qoda-code-editor {
        display: none;
    }

    .qoda-code-shell.monaco-ready .qoda-monaco-editor {
        display: block;
    }

    body:not(.dark) .qoda-code-shell,
    body:not(.dark) .qoda-code-head,
    body:not(.dark) .qoda-code-editor {
        color: #d4d4d4;
        background-color: #1e1e1e;
    }

    .qoda-template-layout {
        display: grid;
        grid-template-columns: 270px minmax(0, 1fr);
        gap: 16px;
    }

    .qoda-template-filter {
        display: grid;
        gap: 12px;
        align-content: start;
        padding: 14px;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: var(--bg);
    }

    .qoda-template-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 12px;
    }

    .qoda-template-card {
        cursor: pointer;
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 15px;
        background: var(--panel);
        color: var(--text);
        display: grid;
        gap: 10px;
        transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease;
    }

    .qoda-template-card:hover {
        transform: translateY(-2px);
        border-color: var(--accent);
        box-shadow: 0 16px 35px rgba(15, 23, 42, .12);
    }

    .qoda-template-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .qoda-template-tag {
        border-radius: 999px;
        padding: 4px 8px;
        background: color-mix(in srgb, var(--accent) 14%, transparent);
        color: var(--text);
        font-size: 11px;
        font-weight: 800;
    }

    .tooltip-text {
        white-space: normal;
        min-width: 190px;
        max-width: 240px;
        text-align: left;
        line-height: 1.35;
        padding: 10px 12px;
    }

    .tooltip-text strong {
        display: block;
        font-size: 12px;
        margin-bottom: 3px;
    }

    .tooltip-text small {
        display: block;
        font-size: 11px;
        opacity: .8;
        font-weight: 500;
    }

    @media (max-width: 1180px) {
        .qoda-author-body {
            grid-template-columns: 1fr;
        }

        .qoda-preview-panel {
            position: relative;
            top: auto;
            max-height: none;
            min-height: 300px;
        }

        .qoda-q-header {
            top: 0;
            position: relative;
        }

        .qoda-template-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 860px) {
        .main {
            margin-left: 0;
            padding: 12px;
        }

        .qoda-q-header,
        .qoda-publish-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .qoda-icon-actions,
        .qoda-toolbar-actions {
            justify-content: flex-start;
        }

        .qoda-field-grid {
            grid-template-columns: 1fr;
        }

        .panel,
        .rowgrid > * {
            min-width: 0;
        }
    }
    </style>
    <link rel="stylesheet" href="css/lecturer-proctoring.css">

</head>

<body>
    <div class="header-bar">
        <div class="header-left">
            <button class="mobile-menu-btn" onclick="toggleMobileSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <div class="header-logo">
                <img src="../assets/qoda-logo.png" alt="QODA logo">
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
                <div class="profile-icon" title="Profile" onclick="handleNavClick(this, 'profile')">
                    <?php if (!empty($lecturerData['profile_pic'])): ?>
                    <img src="<?php echo htmlspecialchars($lecturerData['profile_pic']); ?>" alt="Profile">
                    <?php else: ?>
                    <i class="fas fa-user"></i>
                    <?php endif; ?>
                    <span class="tooltip-text">Profile</span>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-icon" data-tooltip="Dashboard" title="Dashboard" onclick="handleNavClick(this, 'dashboard')">
                    <i class="fas fa-home"></i>
                    <span class="tooltip-text">Dashboard</span>
                </div>

                <div class="nav-icon has-submenu" data-submenu="examSubmenu" title="Exam Management" onclick="toggleSubmenuPanel(this)">
                    <i class="fas fa-file-alt"></i>
                    <span class="tooltip-text">Exam Management</span>
                </div>

                <div class="nav-icon has-submenu" data-submenu="studentSubmenu" title="Student Management" onclick="toggleSubmenuPanel(this)">
                    <i class="fas fa-users"></i>
                    <span class="tooltip-text">Student Management</span>
                </div>

                <div class="nav-icon has-submenu" data-submenu="monitorSubmenu" title="Monitoring" onclick="toggleSubmenuPanel(this)">
                    <i class="fas fa-eye"></i>
                    <span class="tooltip-text">Monitoring</span>
                </div>

                <div class="nav-icon has-submenu" data-submenu="accountSubmenu" title="Account" onclick="toggleSubmenuPanel(this)">
                    <i class="fas fa-user-circle"></i>
                    <span class="tooltip-text">Account</span>
                </div>
            </nav>

            <div class="sidebar-bottom">
                <div class="theme-switch-icon" title="Switch Theme" onclick="toggleTheme()">
                    <i class="fas fa-palette"></i>
                    <span class="tooltip-text">Switch Theme</span>
                </div>
                <div class="logout-icon" title="Logout" onclick="logout()">
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
            <?php include __DIR__ . '/partials/lecturer/dashboard_view.php'; ?>

            <?php include __DIR__ . '/partials/lecturer/exams_view.php'; ?>

            <?php include __DIR__ . '/partials/lecturer/exam_builder_view.php'; ?>

            <?php include __DIR__ . '/partials/lecturer/submissions_view.php'; ?>

            <?php include __DIR__ . '/partials/lecturer/results_view.php'; ?>

            <?php include __DIR__ . '/partials/lecturer/students_view.php'; ?>

            <?php include __DIR__ . '/partials/lecturer/student_details_view.php'; ?>

            <?php include __DIR__ . '/partials/lecturer/profile_view.php'; ?>

            <?php include __DIR__ . '/partials/lecturer/monitoring_view.php'; ?>

            <?php include __DIR__ . '/partials/lecturer/proctoring_view.php'; ?>

            <div id="toast" class="toast"></div>


        </main>
    </div>

    <?php include __DIR__ . '/partials/lecturer/lecturer_modals.php'; ?>



    <script>
    window.QODA_DEBUG = window.QODA_DEBUG || new URLSearchParams(window.location.search).get('debug') === '1';
    if (!window.QODA_DEBUG && window.console) {
        console.log = function() {};
        console.debug = function() {};
    }
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
    const K_EXAM_DRAFTS = "qoda_exam_drafts_v2";
    const K_LAST_BUILDER_EXAM = "qoda_last_builder_exam_id";
    const screenMonitorSocketToken = <?php echo json_encode(qodaCreateSocketToken([
        'role' => 'lecturer',
        'lecturer_id' => (string)$lecturerId,
    ])); ?>;

    const routes = ["exams", "builder", "submissions", "marking", "results",
        "students", "student-details", "profile", "monitoring", "proctoring"
    ];

    const codingLanguagesList = [
        "Python", "Java", "JavaScript", "C", "C++",
        "C#", "PHP", "VB.NET", "SQL", "HTML/CSS/JS"
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
    let currentMonitoringExam = null;
    let activeMonitoringStudents = [];
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
    let lastViolationTotalsByStudent = {};
    const proctorGridWebrtcPeers = {};
    const proctorGridWebrtcRetryAt = {};
    let screenMonitorSocket = null;
    let screenMonitorExamId = null;
    const QODA_WEBRTC_ICE_SERVERS = [
        { urls: 'stun:openrelay.metered.ca:80' },
        { urls: 'turn:openrelay.metered.ca:80', username: 'openrelayproject', credential: 'openrelayproject' },
        { urls: 'turn:openrelay.metered.ca:443', username: 'openrelayproject', credential: 'openrelayproject' },
        { urls: 'turn:openrelay.metered.ca:443?transport=tcp', username: 'openrelayproject', credential: 'openrelayproject' },
        { urls: 'turns:openrelay.metered.ca:443', username: 'openrelayproject', credential: 'openrelayproject' }
    ];

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

    function toggleExamPasswordVisibility() {
        const input = document.getElementById('examPassword');
        const icon = document.getElementById('examPasswordEye');
        if (!input) return;
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        if (icon) {
            icon.classList.toggle('fa-eye', showing);
            icon.classList.toggle('fa-eye-slash', !showing);
        }
    }

    function insertStructuredLine(fieldId, mode) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        const value = field.value || '';
        const lines = value.split('\n');
        const nextNumber = lines.filter(line => /^\s*\d+[.)]\s+/.test(line)).length + 1;
        const token = mode === 'bullet' ? '- ' : `${nextNumber}. `;
        field.value = value ? `${value.replace(/\s*$/, '')}\n${token}` : token;
        field.focus();
        field.selectionStart = field.selectionEnd = field.value.length;
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function insertQuestionStructuredLine(questionId, fieldName, mode) {
        insertStructuredLine(`questionText_${questionId}`, mode);
        const field = document.getElementById(`questionText_${questionId}`);
        if (field) updateQuestion(questionId, fieldName, field.value);
    }

    function insertQuestionFieldStructuredLine(questionId, fieldName, mode) {
        insertStructuredLine(`${fieldName}_${questionId}`, mode);
        const field = document.getElementById(`${fieldName}_${questionId}`);
        if (field) updateQuestion(questionId, fieldName, field.value);
    }

    function handleStructuredTextareaKeydown(event, field, examFieldName, questionId = null) {
        if (event.key !== 'Enter') return;
        const text = field.value;
        const cursor = field.selectionStart;
        const lineStart = text.lastIndexOf('\n', Math.max(0, cursor - 1)) + 1;
        const currentLine = text.slice(lineStart, cursor);
        let nextPrefix = '';
        const numbered = currentLine.match(/^(\s*)(\d+)([.)])\s+/);
        const bullet = currentLine.match(/^(\s*)[-*]\s+/);
        const lettered = currentLine.match(/^(\s*)([a-zA-Z])([.)])\s+/);
        const roman = currentLine.match(/^(\s*)(i|ii|iii|iv|v|vi|vii|viii|ix|x)([.)])\s+/i);

        if (numbered) {
            nextPrefix = `${numbered[1]}${parseInt(numbered[2], 10) + 1}${numbered[3]} `;
        } else if (roman) {
            const romans = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi', 'xii'];
            const idx = romans.indexOf(roman[2].toLowerCase());
            nextPrefix = `${roman[1]}${romans[Math.min(idx + 1, romans.length - 1)]}${roman[3]} `;
        } else if (lettered) {
            const next = String.fromCharCode(lettered[2].toLowerCase().charCodeAt(0) + 1);
            nextPrefix = `${lettered[1]}${next}${lettered[3]} `;
        } else if (bullet) {
            nextPrefix = `${bullet[1]}- `;
        }

        if (!nextPrefix) return;
        event.preventDefault();
        field.value = `${text.slice(0, cursor)}\n${nextPrefix}${text.slice(field.selectionEnd)}`;
        const pos = cursor + 1 + nextPrefix.length;
        field.selectionStart = field.selectionEnd = pos;
        if (questionId) {
            updateQuestion(questionId, examFieldName, field.value);
        } else {
            updateExamField(examFieldName, field.value);
        }
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
                    'Cache-Control': 'no-store',
                    'Pragma': 'no-cache',
                },
                body: formData,
                cache: 'no-store'
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

    // Exam builder functions moved to web-client/js/lecturer-exam-builder.js

    // Submission review and marking functions moved to web-client/js/lecturer-submissions.js

    // Lecturer shell, navigation, profile, and initialization moved to web-client/js/lecturer-shell.js

    // Helper function to round to nearest whole number
    function roundToWholeNumber(value) {
        return Math.round(parseFloat(value) || 0);
    }
    </script>
    <script src="js/lecturer-exam-builder.js"></script>
    <script src="js/lecturer-submissions.js"></script>
    <script src="js/lecturer-proctoring.js"></script>
    <script src="js/lecturer-results.js"></script>
    <script src="js/lecturer-students.js"></script>
    <script src="js/lecturer-shell.js"></script>


</body>

</html>
