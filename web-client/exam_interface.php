<?php
session_start();
// exam_interface.php - CODING QUESTIONS ONLY VERSION
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../backend-php/config/database.php';
require_once __DIR__ . '/../backend-php/lib/code_grader.php';

function ensureExamInterfaceColumn(PDO $pdo, string $table, string $column, string $definition): void
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
    ensureExamInterfaceColumn($pdo, 'screen_captures', 'image_data', 'LONGTEXT NULL');
    ensureExamInterfaceColumn($pdo, 'screen_captures', 'capture_type', "VARCHAR(30) NOT NULL DEFAULT 'live'");
    ensureExamInterfaceColumn($pdo, 'screen_captures', 'notes', 'TEXT NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'student_name', 'VARCHAR(255) NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'student_identifier', 'VARCHAR(100) NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'answers_json', 'JSON NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'total_marks', 'INT DEFAULT 0');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'submitted', 'TINYINT(1) DEFAULT 0');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'started_at', 'DATETIME NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'submittedAt', 'DATETIME NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'user_agent', 'TEXT NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'updated_at', 'DATETIME NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'submission_folder', 'VARCHAR(255) NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'auto_score', 'DECIMAL(5,2) DEFAULT 0');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'execution_results', 'JSON NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'ai_feedback', 'TEXT NULL');
    ensureExamInterfaceColumn($pdo, 'exam_submissions', 'auto_graded_at', 'DATETIME NULL');
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
    $pdo->exec("ALTER TABLE proctor_commands MODIFY command_type ENUM('warning', 'lock', 'unlock') NOT NULL");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proctor_screen_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_id INT NOT NULL,
            student_id INT NOT NULL,
            sharing_active TINYINT(1) NOT NULL DEFAULT 0,
            last_heartbeat_at DATETIME NULL,
            last_frame_at DATETIME NULL,
            last_status VARCHAR(40) NOT NULL DEFAULT 'offline',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_exam_student (exam_id, student_id),
            INDEX idx_heartbeat (last_heartbeat_at),
            INDEX idx_frame (last_frame_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('Exam interface schema upgrade failed: ' . $e->getMessage());
}

function qodaAnswerHasCode(array $answer): bool
{
    $value = $answer['value'] ?? [];
    if (is_string($value)) {
        return trim($value) !== '' && strtolower(trim($value)) !== 'unanswered';
    }
    if (!is_array($value)) return false;
    if (trim((string)($value['code'] ?? '')) !== '' && strtolower(trim((string)$value['code'])) !== 'unanswered') {
        return true;
    }
    $files = $value['files'] ?? [];
    if (!is_array($files)) return false;
    foreach ($files as $file) {
        if (trim((string)($file['content'] ?? '')) !== '' && strtolower(trim((string)($file['content'] ?? ''))) !== 'unanswered') {
            return true;
        }
    }
    return false;
}

function qodaAnswerCodeAndFiles(array $answer, array $question): array
{
    $value = $answer['value'] ?? [];
    if (is_string($value)) {
        return [$value, [], (string)($question['language'] ?? 'python')];
    }
    $files = is_array($value['files'] ?? null) ? $value['files'] : [];
    $language = (string)($value['language'] ?? $question['language'] ?? 'python');
    $code = (string)($value['code'] ?? '');
    if ($code === '' && $files) {
        foreach ($files as $file) {
            if (!empty($file['active'])) {
                $code = (string)($file['content'] ?? '');
                $language = (string)($file['language'] ?? $language);
                break;
            }
        }
    }
    if ($code === '' && $files) {
        $code = (string)($files[0]['content'] ?? '');
        $language = (string)($files[0]['language'] ?? $language);
    }
    return [$code, $files, $language];
}

function qodaStudentSafeQuestions(array $questions): array
{
    return array_map(function ($question) {
        if (!is_array($question)) return $question;
        if (!empty($question['testCases']) && is_array($question['testCases'])) {
            $question['testCases'] = array_values(array_filter($question['testCases'], function ($case) {
                return empty($case['hidden']);
            }));
        }
        return $question;
    }, $questions);
}

function qodaAutoGradeExamAnswers(array $examRow, string $answersJson): array
{
    $answers = json_decode($answersJson, true);
    if (!is_array($answers)) $answers = [];
    $questions = json_decode((string)($examRow['questions'] ?? '[]'), true);
    if (!is_array($questions)) $questions = [];

    $rows = [];
    $feedback = [];
    foreach (array_values($questions) as $index => $question) {
        if (!is_array($question)) continue;
        $qid = (string)($question['id'] ?? ('Q' . $index));
        $answer = is_array($answers[$qid] ?? null) ? $answers[$qid] : [];
        $maxMarks = max(0.0, (float)($question['marks'] ?? 0));
        $score = 0.0;
        $result = [
            'success' => true,
            'score' => 0,
            'max_marks' => $maxMarks,
            'percentage' => 0,
            'feedback' => 'No answer submitted. Score: 0.',
            'results' => [],
            'requires_manual_review' => true,
            'grading_method' => 'unanswered',
        ];

        if (qodaAnswerHasCode($answer)) {
            [$code, $files, $language] = qodaAnswerCodeAndFiles($answer, $question);
            $result = gradeQodaCode([
                'code' => $code,
                'language' => $language,
                'files' => $files,
                'test_cases' => $question['testCases'] ?? [],
                'marking_scheme' => (string)($question['markingScheme'] ?? $examRow['marking_scheme'] ?? ''),
                'expected_output' => (string)($question['expectedOutput'] ?? ''),
                'model_solution' => (string)($question['modelSolution'] ?? ''),
                'question_text' => (string)($question['text'] ?? $question['question_text'] ?? ''),
                'max_marks' => $maxMarks,
            ]);
            $score = (float)($result['score'] ?? 0);
        }

        if (isset($answers[$qid]) && is_array($answers[$qid])) {
            $answers[$qid]['score'] = $score;
            $answers[$qid]['auto_score'] = $score;
            $answers[$qid]['auto_feedback'] = $result['feedback'] ?? '';
            $answers[$qid]['auto_graded_at'] = date('Y-m-d H:i:s');
        }

        $rows[] = [
            'index' => $index,
            'question_id' => $qid,
            'question_number' => $index + 1,
            'score' => $score,
            'max_marks' => $maxMarks,
            'compulsory' => !empty($question['compulsory']),
            'result' => $result,
        ];
        $feedback[] = 'Q' . ($index + 1) . ': ' . round($score, 2) . '/' . round($maxMarks, 2) . ' - ' . (($result['grading_method'] ?? 'auto') ?: 'auto');
    }

    $limit = (int)($examRow['questions_to_answer'] ?? 0);
    if ($limit <= 0 || $limit > count($rows)) $limit = count($rows);
    $compulsory = array_values(array_filter($rows, fn($row) => !empty($row['compulsory'])));
    $optionalSlots = max(0, $limit - count($compulsory));
    $optional = array_values(array_filter($rows, fn($row) => empty($row['compulsory'])));
    usort($optional, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['max_marks'] <=> $a['max_marks']));
    $included = array_merge($compulsory, array_slice($optional, 0, $optionalSlots));

    $totalScore = array_reduce($included, fn($sum, $row) => $sum + (float)$row['score'], 0.0);
    $totalPossible = array_reduce($included, fn($sum, $row) => $sum + (float)$row['max_marks'], 0.0);
    if ($totalPossible <= 0) $totalPossible = max(0.0, (float)($examRow['total_marks'] ?? 0));
    $percentage = $totalPossible > 0 ? round(($totalScore / $totalPossible) * 100, 2) : 0.0;

    $answers['_auto_grading'] = [
        'total_score' => round($totalScore, 2),
        'total_marks' => round($totalPossible, 2),
        'percentage' => $percentage,
        'exam_score_60' => round(($percentage * 60) / 100, 2),
        'included_questions' => array_map(fn($row) => $row['question_number'], $included),
        'graded_at' => date('Y-m-d H:i:s'),
        'status' => 'pending_lecturer_review',
    ];

    return [
        'answers_json' => json_encode($answers),
        'total_score' => round($totalScore, 2),
        'total_marks' => round($totalPossible, 2),
        'percentage' => $percentage,
        'execution_results' => json_encode($rows),
        'ai_feedback' => implode("\n", $feedback),
    ];
}

// ---- AJAX handlers ----
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }

    $ajaxExamId   = $_POST['exam_id']  ?? null;
    $ajaxStudentId = $_SESSION['user_id'];

    // Resolve student row id (FK to students.id)
    $studRow = $pdo->prepare("SELECT id, full_name, student_id FROM students WHERE user_id = ? OR id = ? LIMIT 1");
    $studRow->execute([$ajaxStudentId, $ajaxStudentId]);
    $sRow = $studRow->fetch();
    $studentRowId = $sRow ? $sRow['id'] : $ajaxStudentId;
    $studentNameForSubmission = $sRow['full_name'] ?? ($_SESSION['user_name'] ?? null);
    $studentIdentifierForSubmission = $sRow['student_id'] ?? ($_SESSION['user_id_value'] ?? null);

    if ($_POST['action'] === 'screen_heartbeat') {
        $active = (int)($_POST['active'] ?? 1) === 1 ? 1 : 0;
        $status = $active ? 'sharing' : 'offline';

        $stmt = $pdo->prepare("
            INSERT INTO proctor_screen_status (exam_id, student_id, sharing_active, last_heartbeat_at, last_status)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                sharing_active = VALUES(sharing_active),
                last_heartbeat_at = VALUES(last_heartbeat_at),
                last_status = VALUES(last_status),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$ajaxExamId, $studentRowId, $active, $status]);

        if ($active) {
            $session = $pdo->prepare("SELECT id FROM exam_submissions WHERE exam_id = ? AND student_id = ? LIMIT 1");
            $session->execute([$ajaxExamId, $studentRowId]);
            $sessionId = $session->fetchColumn();
            if ($sessionId) {
                $pdo->prepare("
                    UPDATE exam_submissions
                    SET updated_at = NOW(), status = IF(UPPER(status) IN ('SUBMITTED', 'TIMED_OUT', 'GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED'), status, 'in_progress')
                    WHERE id = ?
                ")->execute([$sessionId]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO exam_submissions
                        (exam_id, student_id, student_name, student_identifier, status, started_at, updated_at, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, 'in_progress', NOW(), NOW(), ?, ?)
                ");
                $ins->execute([
                    $ajaxExamId,
                    $studentRowId,
                    $studentNameForSubmission,
                    $studentIdentifierForSubmission,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            }
        }

        echo json_encode(['success' => true, 'active' => $active, 'heartbeat_at' => date('Y-m-d H:i:s')]);
        exit;
    }

    if ($_POST['action'] === 'start_exam') {
        // Create an in_progress record if none exists
        $check = $pdo->prepare(
            "SELECT id, status, submitted_at, submitted
             FROM exam_submissions
             WHERE exam_id = ? AND student_id = ?
             ORDER BY submitted_at DESC, id DESC
             LIMIT 1"
        );
        $check->execute([$ajaxExamId, $studentRowId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        $existingStatus = strtoupper((string)($existing['status'] ?? ''));
        $existingIsSubmitted = $existing && (!empty($existing['submitted_at']) || intval($existing['submitted'] ?? 0) === 1);

        if ($existingIsSubmitted && in_array($existingStatus, ['SUBMITTED', 'TIMED_OUT', 'GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED'], true)) {
            echo json_encode(['success' => false, 'error' => 'This exam has already been submitted.']);
            exit;
        }

        if (!$existing) {
            $ins = $pdo->prepare("
                INSERT INTO exam_submissions
                    (exam_id, student_id, student_name, student_identifier, status, started_at, ip_address, user_agent)
                VALUES (?, ?, ?, ?, 'in_progress', NOW(), ?, ?)
            ");
            $ins->execute([
                $ajaxExamId, $studentRowId,
                $studentNameForSubmission,
                $studentIdentifierForSubmission,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            $submissionId = $pdo->lastInsertId();
        } else {
            $resume = $pdo->prepare("
                UPDATE exam_submissions
                SET status = 'in_progress',
                    submitted = 0,
                    submitted_at = NULL,
                    submittedAt = NULL,
                    started_at = COALESCE(started_at, NOW()),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $resume->execute([$existing['id']]);
            $submissionId = $existing['id'];
        }
        echo json_encode(['success' => true, 'submission_id' => $submissionId]);
        exit;
    }

    if ($_POST['action'] === 'auto_save') {
        $answers = $_POST['answers'] ?? '';
        $upd = $pdo->prepare("
            UPDATE exam_submissions
            SET answers = ?, answers_json = ?, updated_at = NOW()
            WHERE exam_id = ? AND student_id = ? AND status = 'in_progress'
        ");
        $answersJson = (json_decode($answers, true) !== null) ? $answers : null;
        $upd->execute([$answers, $answersJson, $ajaxExamId, $studentRowId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_POST['action'] === 'submit_exam') {
        $answers = $_POST['answers'] ?? '[]';
        $answersJson = (json_decode($answers, true) !== null) ? $answers : null;
        $submittedStatus = !empty($_POST['auto_submit']) ? 'timed_out' : 'submitted';
        $submissionFolder = preg_replace('/[^A-Za-z0-9_\-]/', '_', ($studentIdentifierForSubmission ?: 'student') . '_' . ($_POST['course_code'] ?? 'course'));

        $examStmt = $pdo->prepare("SELECT total_marks, questions, questions_to_answer, marking_scheme FROM exams WHERE id = ? LIMIT 1");
        $examStmt->execute([$ajaxExamId]);
        $examRow = $examStmt->fetch(PDO::FETCH_ASSOC);
        $totalMarks = $examRow ? floatval($examRow['total_marks'] ?? 0) : 0;
        $autoGrade = $examRow ? qodaAutoGradeExamAnswers($examRow, $answers) : [
            'answers_json' => $answersJson,
            'total_score' => 0,
            'total_marks' => $totalMarks,
            'percentage' => 0,
            'execution_results' => null,
            'ai_feedback' => null,
        ];
        $answersToStore = $autoGrade['answers_json'] ?: $answers;
        $answersJsonToStore = (json_decode($answersToStore, true) !== null) ? $answersToStore : $answersJson;
        $totalMarks = (float)($autoGrade['total_marks'] ?? $totalMarks);
        $autoTotalScore = (float)($autoGrade['total_score'] ?? 0);
        $autoPercentage = (float)($autoGrade['percentage'] ?? 0);

        $check = $pdo->prepare(
            "SELECT id FROM exam_submissions WHERE exam_id = ? AND student_id = ? LIMIT 1"
        );
        $check->execute([$ajaxExamId, $studentRowId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $upd = $pdo->prepare("
                UPDATE exam_submissions
                SET answers = ?,
                    answers_json = ?,
                    total_score = ?,
                    total_marks = ?,
                    percentage = ?,
                    auto_score = ?,
                    execution_results = ?,
                    ai_feedback = ?,
                    auto_graded_at = NOW(),
                    student_name = ?,
                    student_identifier = ?,
                    status = ?,
                    submitted = 1,
                    submitted_at = NOW(),
                    submittedAt = NOW(),
                    updated_at = NOW(),
                    submission_folder = ?
                WHERE id = ?
            ");
            $upd->execute([
                $answersToStore,
                $answersJsonToStore,
                $autoTotalScore,
                $totalMarks,
                $autoPercentage,
                $autoTotalScore,
                $autoGrade['execution_results'] ?? null,
                $autoGrade['ai_feedback'] ?? null,
                $studentNameForSubmission,
                $studentIdentifierForSubmission,
                $submittedStatus,
                $submissionFolder,
                $existing['id']
            ]);
            $submissionId = $existing['id'];
        } else {
            $ins = $pdo->prepare("
                INSERT INTO exam_submissions
                    (exam_id, student_id, student_name, student_identifier, answers, answers_json, total_score, total_marks, percentage, auto_score, execution_results, ai_feedback, auto_graded_at, status, submitted, started_at, submitted_at, submittedAt, ip_address, user_agent, submission_folder)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1, NOW(), NOW(), NOW(), ?, ?, ?)
            ");
            $ins->execute([
                $ajaxExamId,
                $studentRowId,
                $studentNameForSubmission,
                $studentIdentifierForSubmission,
                $answersToStore,
                $answersJsonToStore,
                $autoTotalScore,
                $totalMarks,
                $autoPercentage,
                $autoTotalScore,
                $autoGrade['execution_results'] ?? null,
                $autoGrade['ai_feedback'] ?? null,
                $submittedStatus,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $submissionFolder
            ]);
            $submissionId = $pdo->lastInsertId();
        }

        echo json_encode([
            'success' => true,
            'submission_id' => $submissionId,
            'auto_score' => $autoTotalScore,
            'percentage' => $autoPercentage,
            'exam_score_60' => round(($autoPercentage * 60) / 100, 2),
        ]);
        exit;
    }

    if ($_POST['action'] === 'screen_snapshot') {
        $snapshot = $_POST['snapshot'] ?? '';
        $captureType = strtolower(trim($_POST['capture_type'] ?? 'live'));
        $allowedCaptureTypes = ['live', 'manual', 'violation', 'evidence'];
        if (!in_array($captureType, $allowedCaptureTypes, true)) {
            $captureType = 'live';
        }
        $notes = trim($_POST['notes'] ?? '');
        if (strpos($snapshot, 'base64,') !== false) {
            $snapshot = substr($snapshot, strpos($snapshot, 'base64,') + 7);
        }
        if ($snapshot === '') {
            echo json_encode(['success' => false, 'error' => 'No snapshot received']);
            exit;
        }
        $imagePath = '';
        if ($captureType !== 'live') {
            $safeStudentFolder = preg_replace('/[^A-Za-z0-9_-]+/', '_', $studentIdentifierForSubmission ?: ('student_' . $studentRowId));
            $evidenceRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'proctoring_evidence';
            $evidenceDir = $evidenceRoot . DIRECTORY_SEPARATOR . $safeStudentFolder . DIRECTORY_SEPARATOR . 'exam_' . $ajaxExamId;
            if (!is_dir($evidenceDir)) {
                @mkdir($evidenceDir, 0775, true);
            }
            $fileBase = date('Ymd_His') . '_' . $captureType . '_' . bin2hex(random_bytes(3));
            $absoluteImagePath = $evidenceDir . DIRECTORY_SEPARATOR . $fileBase . '.jpg';
            $absoluteNotePath = $evidenceDir . DIRECTORY_SEPARATOR . $fileBase . '.txt';
            $binary = base64_decode($snapshot, true);
            if ($binary !== false && is_dir($evidenceDir)) {
                @file_put_contents($absoluteImagePath, $binary);
                $imagePath = 'storage/proctoring_evidence/' . $safeStudentFolder . '/exam_' . $ajaxExamId . '/' . $fileBase . '.jpg';
                $noteText = "Student: {$studentIdentifierForSubmission}\nExam ID: {$ajaxExamId}\nCapture type: {$captureType}\nTime: " . date('Y-m-d H:i:s') . "\nReason: {$notes}\n";
                @file_put_contents($absoluteNotePath, $noteText);
            }
        }
        $stmt = $pdo->prepare("
            INSERT INTO screen_captures (exam_id, student_id, image_path, image_data, capture_type, notes, captured_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$ajaxExamId, $studentRowId, $imagePath, $snapshot, $captureType, $notes]);
        $captureId = $pdo->lastInsertId();
        $status = $pdo->prepare("
            INSERT INTO proctor_screen_status (exam_id, student_id, sharing_active, last_heartbeat_at, last_frame_at, last_status)
            VALUES (?, ?, 1, NOW(), NOW(), 'sharing')
            ON DUPLICATE KEY UPDATE
                sharing_active = 1,
                last_heartbeat_at = NOW(),
                last_frame_at = NOW(),
                last_status = 'sharing',
                updated_at = CURRENT_TIMESTAMP
        ");
        $status->execute([$ajaxExamId, $studentRowId]);
        if ($captureType === 'live') {
            try {
                $cleanup = $pdo->prepare("
                    DELETE FROM screen_captures
                    WHERE exam_id = ?
                      AND student_id = ?
                      AND capture_type = 'live'
                      AND id NOT IN (
                          SELECT id FROM (
                              SELECT id
                              FROM screen_captures
                              WHERE exam_id = ?
                                AND student_id = ?
                                AND capture_type = 'live'
                              ORDER BY id DESC
                              LIMIT 12
                          ) keep_rows
                      )
                ");
                $cleanup->execute([$ajaxExamId, $studentRowId, $ajaxExamId, $studentRowId]);
            } catch (Throwable $cleanupError) {
                error_log('Live screen cleanup failed: ' . $cleanupError->getMessage());
            }
        }
        $session = $pdo->prepare("SELECT id FROM exam_submissions WHERE exam_id = ? AND student_id = ? LIMIT 1");
        $session->execute([$ajaxExamId, $studentRowId]);
        $sessionId = $session->fetchColumn();
        if ($sessionId) {
            $pdo->prepare("
                UPDATE exam_submissions
                SET updated_at = NOW(), status = IF(UPPER(status) IN ('SUBMITTED', 'TIMED_OUT', 'GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED'), status, 'in_progress')
                WHERE id = ?
            ")->execute([$sessionId]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO exam_submissions
                    (exam_id, student_id, student_name, student_identifier, status, started_at, updated_at, ip_address, user_agent)
                VALUES (?, ?, ?, ?, 'in_progress', NOW(), NOW(), ?, ?)
            ");
            $ins->execute([
                $ajaxExamId,
                $studentRowId,
                $studentNameForSubmission,
                $studentIdentifierForSubmission,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        }
        echo json_encode(['success' => true, 'capture_id' => $captureId, 'captured_at' => date('Y-m-d H:i:s')]);
        exit;
    }

    if ($_POST['action'] === 'poll_proctor_commands') {
        $stmt = $pdo->prepare("
            SELECT id, command_type, message
            FROM proctor_commands
            WHERE exam_id = ? AND student_id = ? AND handled = 0
            ORDER BY id ASC
        ");
        $stmt->execute([$ajaxExamId, $studentRowId]);
        $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($commands) {
            $ids = array_column($commands, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $upd = $pdo->prepare("UPDATE proctor_commands SET handled = 1, handled_at = NOW() WHERE id IN ($placeholders)");
            $upd->execute($ids);
        }
        echo json_encode(['success' => true, 'commands' => $commands]);
        exit;
    }

    if ($_POST['action'] === 'report_violation') {
        $reason = trim($_POST['reason'] ?? 'violation');
        $stmt = $pdo->prepare("
            INSERT INTO suspicious_logs (student_id, exam_id, event_type, details, severity)
            VALUES (?, ?, ?, ?, 'high')
        ");
        $stmt->execute([$studentRowId, $ajaxExamId, strtoupper($reason), $reason]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Check if user is logged in and is a student
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['user_role'] !== 'STUDENT') {
    // Allow preview for lecturers
    $preview = isset($_GET['preview']) && $_GET['preview'] == 1;
    if (!$preview) {
        header('Location: lecturer_dashboard.php');
        exit;
    }
}

$examId = isset($_GET['exam_id']) ? $_GET['exam_id'] : null;
$studentId = $_SESSION['user_id'] ?? 'test_student';
$studentName = $_SESSION['user_name'] ?? 'Student';

// Get student data if logged in
$studentData = null;
if ($studentId && $studentId !== 'test_student') {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($studentData) {
        $studentName = $studentData['full_name'];
    }
}

$examData = null;
$questions = [];
$error = null;

if ($examId) {
    try {
        $preview = isset($_GET['preview']) && $_GET['preview'] == 1;

        if ($preview) {
            $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND published = 1");
        }
        $stmt->execute([$examId]);
        $examData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($examData) {
            // Check if student is enrolled in this course (skip for preview)
            if (!$preview && $studentId !== 'test_student') {
                $courseCode = $examData['course_code'];
                
                // Check enrollment
                $checkStmt = $pdo->prepare("
                    SELECT ce.id 
                    FROM course_enrollments ce
                    WHERE ce.student_id = ? AND ce.course_code = ?
                ");
                $checkStmt->execute([$studentId, $courseCode]);
                
                if (!$checkStmt->fetch()) {
                    $error = "You are not enrolled in this course. Please contact your lecturer.";
                    $examData = null;
                }
                
                // Also check exam_class_access — only block if records exist AND none grant access
                if ($examData) {
                    $accessCountStmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM exam_class_access WHERE exam_id = ?"
                    );
                    $accessCountStmt->execute([$examId]);
                    $accessCount = (int)$accessCountStmt->fetchColumn();

                    if ($accessCount > 0) {
                        $accessStmt = $pdo->prepare("
                            SELECT eca.id
                            FROM exam_class_access eca
                            WHERE eca.exam_id = ? AND eca.class_code = ? AND eca.access_granted = 1
                        ");
                        $accessStmt->execute([$examId, $courseCode]);
                        if (!$accessStmt->fetch()) {
                            $error = "This exam is not available for your class.";
                            $examData = null;
                        }
                    }
                }
                
                // Check exam visibility
                if ($examData) {
                    $visStmt = $pdo->prepare("
                        SELECT visible FROM exam_visibility 
                        WHERE exam_id = ? AND student_id = ?
                    ");
                    $visStmt->execute([$examId, $studentId]);
                    $visibility = $visStmt->fetch(PDO::FETCH_ASSOC);
                    if ($visibility && $visibility['visible'] == 0) {
                        $error = "This exam is not available for you.";
                        $examData = null;
                    }
                }

                // Check if already submitted
                if ($examData) {
                    $subStmt = $pdo->prepare("
                        SELECT id, status, submitted_at, submitted FROM exam_submissions 
                        WHERE exam_id = ? AND student_id = ?
                        ORDER BY submitted_at DESC, id DESC
                        LIMIT 1
                    ");
                    $subStmt->execute([$examId, $studentId]);
                    $submission = $subStmt->fetch(PDO::FETCH_ASSOC);
                    $submissionStatus = strtoupper((string)($submission['status'] ?? ''));
                    $hasRealSubmission = $submission && (!empty($submission['submitted_at']) || intval($submission['submitted'] ?? 0) === 1);
                    if ($hasRealSubmission && in_array($submissionStatus, ['SUBMITTED', 'TIMED_OUT', 'GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED'], true)) {
                        $error = "You have already submitted this exam.";
                        $examData = null;
                    }
                }
            }

            if ($examData) {
                // Parse questions from JSON
                if (isset($examData['questions']) && !empty($examData['questions'])) {
                    $questionsJson = $examData['questions'];
                    if (is_string($questionsJson)) {
                        $questions = json_decode($questionsJson, true);
                    } else {
                        $questions = $questionsJson;
                    }
                }

                // Ensure questions is an array
                if (!is_array($questions)) {
                    $questions = [];
                }

                // FILTER: Keep ONLY coding questions
                $codingQuestions = [];
                foreach ($questions as $q) {
                    if (isset($q['type']) && in_array(strtolower($q['type']), ['code', 'coding'])) {
                        if (!isset($q['marks'])) $q['marks'] = 5;
                        if (!isset($q['compulsory'])) $q['compulsory'] = false;
                        if (!isset($q['language'])) $q['language'] = 'python';
                        if (!isset($q['hasSubQuestions'])) $q['hasSubQuestions'] = false;
                        if (!isset($q['subQuestions'])) $q['subQuestions'] = [];
                        if (!isset($q['testCases'])) $q['testCases'] = [];
                        if (!isset($q['expectedOutput'])) $q['expectedOutput'] = '';
                        if (!isset($q['gradingMode'])) $q['gradingMode'] = 'auto';
                        $codingQuestions[] = $q;
                    }
                }
                $questions = $codingQuestions;

                if (empty($questions)) {
                    $error = "This exam contains no coding questions. Please contact your lecturer.";
                    $examData = null;
                }
            }
        } else {
            if (!$preview) {
                $error = "Exam not found or not published.";
            } else {
                $error = "Exam not found.";
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching exam: " . $e->getMessage());
        $error = "Database error. Please try again later.";
    }
}

// Shuffle questions if enabled
$shuffleEnabled = $examData && isset($examData['shuffle_enabled']) && $examData['shuffle_enabled'] == 1;
if ($shuffleEnabled && !empty($questions)) {
    shuffle($questions);
}

// Prepare data for JavaScript
if ($examData) {
    $dbNow = $pdo->query("SELECT NOW()")->fetchColumn();
    $nowTs = strtotime($dbNow ?: date('Y-m-d H:i:s'));
    $startTs = !empty($examData['start_datetime']) ? strtotime($examData['start_datetime']) : null;
    $durationMins = max(1, (int)($examData['duration_minutes'] ?? 180));
    $endTs = !empty($examData['end_datetime']) ? strtotime($examData['end_datetime']) : null;
    if ($startTs && (!$endTs || $endTs <= $startTs)) {
        $endTs = $startTs + ($durationMins * 60);
        $examData['end_datetime'] = date('Y-m-d H:i:s', $endTs);
    }
    $examData['server_now'] = $dbNow;
    $examData['server_now_ms'] = $nowTs * 1000;
    $examData['remaining_seconds'] = $endTs ? max(0, $endTs - $nowTs) : $durationMins * 60;
    $examData['runtime_status'] = ($startTs && $startTs > $nowTs) ? 'upcoming' : (($endTs && $endTs <= $nowTs) ? 'expired' : 'active');
}
$examJson = $examData ? json_encode($examData) : 'null';
$questionsJson = json_encode(qodaStudentSafeQuestions($questions));
$studentNameJson = json_encode($studentName);
$studentIdJson = json_encode($studentId);
$previewJson = json_encode($preview ?? false);

// If error, show error page
if ($error):
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Qoda | Exam Error</title>
    <style>
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #f5f5f5;
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100vh;
        margin: 0;
    }

    .error-container {
        background: white;
        padding: 40px;
        border-radius: 16px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        text-align: center;
        max-width: 500px;
    }

    .error-icon {
        font-size: 64px;
        color: #ef4444;
        margin-bottom: 20px;
    }

    h1 {
        color: #1a1a1a;
        margin-bottom: 10px;
    }

    p {
        color: #666;
        margin-bottom: 30px;
    }

    .btn {
        background: #3b82f6;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 16px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .btn:hover {
        background: #2563eb;
    }
    </style>
</head>

<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <h1>Exam Not Available</h1>
        <p><?php echo htmlspecialchars($error); ?></p>
        <a href="student_dashboard.php" class="btn">Return to Dashboard</a>
    </div>
</body>

</html>
<?php
    exit;
endif;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Qoda | Secure Coding Exam Interface</title>
    <!-- Monaco Editor -->
    <!-- Monaco Editor - VS Code Style -->
    <link rel="stylesheet" data-name="vs/editor/editor.main"
        href="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs/editor/editor.main.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs/loader.js"></script>
    <style>
    /* ============================================
       EXAM INTERFACE STYLES - CODING ONLY
    ============================================ */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        user-select: none;
        -webkit-user-select: none;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        background: #1e1e1e;
        color: #d4d4d4;
        height: 100vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    body.light {
        background: #f3f4f6;
        color: #111827;
    }

    body.light .fixed-header,
    body.light .question-nav-bar,
    body.light .left-panel,
    body.light .editor-container,
    body.light .preview-container,
    body.light .file-tabs,
    body.light .preview-header,
    body.light .bottom-nav,
    body.light .question-card,
    body.light .question-panel {
        background: #ffffff !important;
        color: #111827 !important;
        border-color: #d1d5db !important;
    }

    body.light .header-center,
    body.light .timer,
    body.light .problem-card,
    body.light .output-area,
    body.light .ui-output-area {
        background: #f9fafb !important;
        color: #111827 !important;
        border-color: #d1d5db !important;
    }

    body.light .course-name,
    body.light .course-code,
    body.light .student-id,
    body.light .preview-header,
    body.light .file-tabs {
        color: #111827 !important;
    }

    body.light .file-tab {
        background: #e5e7eb;
        border-color: #d1d5db;
        color: #111827;
    }

    body.light .file-tab.active {
        background: #007acc;
        color: #ffffff;
    }

    /* Header */
    .fixed-header {
        height: 60px;
        background: #2d2d30;
        border-bottom: 1px solid #3e3e42;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 24px;
        flex-shrink: 0;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .logo-icon {
        width: 36px;
        height: 36px;
        background: linear-gradient(135deg, #007acc, #0098ff);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 18px;
        color: white;
    }

    .system-name {
        font-weight: 600;
        font-size: 14px;
        color: #007acc;
    }

    .header-center {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 6px 16px;
        background: #252526;
        border-radius: 8px;
    }

    .course-name,
    .course-code,
    .student-id {
        font-size: 12px;
        color: #cccccc;
    }

    .separator {
        color: #6a6a6a;
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .submit-exam-top {
        background: #dc2626;
        border: 1px solid #f87171;
        color: #ffffff;
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 10px 22px rgba(220, 38, 38, 0.22);
    }

    .submit-exam-top:hover {
        background: #b91c1c;
    }

    .exam-top-action {
        background: transparent;
        border: 1px solid #4b5563;
        color: #f9fafb;
        border-radius: 10px;
        padding: 9px 12px;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }

    .exam-top-action.save {
        border-color: #14b8a6;
        color: #5eead4;
    }

    body.light .exam-top-action {
        color: #111827;
        border-color: #111827;
        background: #ffffff;
    }

    body.light .exam-top-action.save {
        color: #0f766e;
        border-color: #0f766e;
    }

    body.light .submit-exam-top {
        color: #ffffff;
        border-color: #991b1b;
    }

    .share-screen {
        background: transparent;
        border: 0;
        border-radius: 50%;
        width: 36px;
        height: 36px;
        cursor: pointer;
        color: #ff0000;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0;
    }

    .share-screen:hover {
        background: rgba(255, 0, 0, 0.08);
    }

    body.dark .share-screen {
        background: transparent;
        border-color: transparent;
        color: #ff0000;
    }

    .share-screen.sharing-on {
        background: transparent !important;
        border-color: transparent !important;
        color: #ff0000 !important;
        animation: sharingBlink 1s infinite;
    }

    .share-screen.sharing-off {
        background: transparent !important;
        border-color: transparent !important;
        color: #7f1d1d !important;
        animation: none;
    }

    .share-dot {
        width: 14px;
        height: 14px;
        border-radius: 999px;
        background: #ff0000;
        display: block;
        box-shadow: 0 0 0 3px rgba(255, 0, 0, 0.15);
    }

    .sharing-off .share-dot {
        opacity: .35;
        box-shadow: none;
    }

    @keyframes sharingBlink {
        0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(239, 68, 68, .65); }
        50% { opacity: .28; box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
    }

    body.dark .share-screen.active {
        background: transparent;
        color: #ff0000;
        box-shadow: 0 0 0 8px rgba(239, 68, 68, 0.12);
    }

    .exam-theme-toggle {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        border: 2px solid #ffffff;
        background: transparent;
        color: #ffffff;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 800;
        line-height: 1;
    }

    body.light .exam-theme-toggle {
        background: #ffffff;
        color: #111827;
        border-color: #ef4444;
    }

    .exam-theme-toggle span {
        display: block;
        min-width: 18px;
        text-align: center;
        color: currentColor;
    }

    .timer {
        padding: 10px 18px;
        background: #252526;
        border-radius: 8px;
        font-weight: 800;
        font-size: 24px;
        font-family: monospace;
        color: #4ec9b0;
        min-width: 155px;
        text-align: center;
        box-shadow: 0 0 0 1px rgba(78, 201, 176, 0.18);
    }

    .timer.warning {
        color: #ffffff;
        background: #dc2626;
        box-shadow: 0 0 0 8px rgba(220, 38, 38, 0.16);
        animation: timerWarningPulse 0.8s infinite alternate;
    }

    @keyframes timerWarningPulse {
        from { transform: scale(1); filter: brightness(1); }
        to { transform: scale(1.04); filter: brightness(1.25); }
    }

    /* Question Navigation Bar */
    .question-nav-bar {
        background: #252526;
        border-bottom: 1px solid #3e3e42;
        padding: 10px 24px;
        flex-shrink: 0;
    }

    .question-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .question-id-area {
        display: flex;
        align-items: baseline;
        gap: 12px;
        flex-wrap: wrap;
    }

    .question-id {
        font-size: 16px;
        font-weight: 700;
        color: #007acc;
        font-family: monospace;
    }

    .question-unique-id {
        font-size: 11px;
        background: #3e3e42;
        padding: 2px 8px;
        border-radius: 12px;
        font-family: monospace;
        color: #f80404;
    }

    .question-marks {
        background: #4ec9b0;
        color: #1e1e1e;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }

    .question-type {
        background: #007acc;
        color: white;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
    }

    .datetime {
        font-size: 11px;
        color: #888;
        font-family: monospace;
    }

    .question-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .q-nav-btn {
        width: 38px;
        height: 38px;
        background: #3e3e42;
        border: 1px solid #555;
        border-radius: 6px;
        color: #ccc;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.2s;
    }

    .q-nav-btn:hover {
        background: #555;
        border-color: #007acc;
    }

    .q-nav-btn.unanswered {
        background: #3b1f24;
        border-color: #ef4444;
        color: #fecaca;
    }

    .q-nav-btn.active {
        background: #f59e0b;
        border-color: #fbbf24;
        color: #111827;
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.28);
    }

    .q-nav-btn.answered {
        background: #4ec9b0;
        border-color: #4ec9b0;
        color: #1e1e1e;
    }

    .q-nav-btn.answered.active {
        background: #2563eb;
        border-color: #93c5fd;
        color: #ffffff;
    }

    .q-nav-btn.flagged {
        border: 2px solid #ffcc00;
        background: rgba(255, 204, 0, 0.2);
        color: #ffcc00;
    }

    /* Main Content */
    .main-content {
        flex: 1;
        display: flex;
        overflow: hidden;
        min-height: 0;
    }

    /* Left Panel - Question Info */
    .left-panel {
        width: 30%;
        min-width: 280px;
        max-width: 50%;
        background: #252526;
        border-right: 1px solid #3e3e42;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .resize-handle {
        width: 6px;
        background: #3e3e42;
        cursor: col-resize;
        transition: background 0.2s;
        flex-shrink: 0;
    }

    .resize-handle:hover,
    .resize-handle.active {
        background: #007acc;
    }

    .left-panel-content {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .question-type-box {
        background: #2d2d30;
        padding: 10px 14px;
        border-radius: 8px;
        border-left: 3px solid #007acc;
        flex-shrink: 0;
        font-size: 13px;
        font-weight: 500;
        color: #ccc;
    }

    .question-text-area {
        background: #2d2d30;
        padding: 14px;
        border-radius: 8px;
        line-height: 1.6;
        font-size: 14px;
        max-height: 250px;
        overflow-y: auto;
        flex-shrink: 0;
        text-align: justify;
        color: #d4d4d4;
    }

    /* Sub-questions */
    .subquestion-buttons {
        flex-shrink: 0;
    }

    .subq-title {
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 10px;
        color: #888;
    }

    .subq-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .subq-btn {
        min-width: 40px;
        padding: 6px 12px;
        background: #3e3e42;
        border: 1px solid #555;
        border-radius: 20px;
        color: #ccc;
        cursor: pointer;
        font-size: 11px;
        transition: all 0.2s;
    }

    .subq-btn:hover {
        background: #555;
        border-color: #007acc;
    }

    .subq-btn.active {
        background: #007acc;
        border-color: #007acc;
        color: white;
    }

    .subquestion-content {
        display: block;
        margin-top: 10px;
        padding-left: 20px;
    }

    .subquestion-item {
        display: block;
        margin-bottom: 12px;
        padding: 12px;
        background: #2d2d30;
        border-radius: 8px;
        border-left: 3px solid #007acc;
    }

    .subquestion-letter {
        font-weight: bold;
        color: #007acc;
        display: inline-block;
        margin-right: 8px;
    }

    .subquestion-text {
        display: inline;
    }

    .main-question-btn {
        background: #007acc;
        color: white;
        border: none;
        border-radius: 6px;
        padding: 6px 12px;
        cursor: pointer;
        font-size: 11px;
        margin-top: 10px;
    }

    /* Progress Bar */
    .progress-bar-container {
        flex-shrink: 0;
    }

    .progress-label {
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        color: #888;
        margin-bottom: 6px;
    }

    .progress-bar {
        height: 4px;
        background: #3e3e42;
        border-radius: 2px;
        overflow: hidden;
        margin-bottom: 6px;
    }

    .progress-fill {
        height: 100%;
        background: #007acc;
        width: 0%;
        transition: width 0.3s;
    }

    .progress-text {
        font-size: 11px;
        color: #888;
    }

    /* Action Buttons */
    .action-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        flex-shrink: 0;
        margin-top: auto;
        padding-top: 14px;
    }

    .action-btn {
        padding: 8px 12px;
        background: #3e3e42;
        border: 1px solid #555;
        border-radius: 6px;
        color: #ccc;
        cursor: pointer;
        font-size: 11px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }

    .action-btn:hover {
        background: #555;
        border-color: #007acc;
    }

    .action-btn.flag {
        color: #ffcc00;
    }

    .action-btn.flag.active {
        background: rgba(255, 204, 0, 0.15);
        border-color: #ffcc00;
    }

    .action-btn.clear-btn {
        background: #4a2a2a;
        border-color: #a04040;
        color: #ff8080;
    }

    .action-btn.clear-btn:hover {
        background: #5a3535;
    }

    .action-btn.finish {
        background: #4ec9b0;
        border-color: #4ec9b0;
        color: #1e1e1e;
        width: 100%;
        justify-content: center;
        margin-top: 4px;
    }

    .action-btn.finish:hover {
        background: #5ed9c0;
    }

    /* Right Panel - Coding Area */
    .right-panel {
        flex: 1;
        background: #1e1e1e;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .coding-split {
        display: flex;
        height: 100%;
        overflow: hidden;
    }

    .editor-container {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        min-width: 200px;
    }

    .file-tabs {
        background: #2d2d30;
        padding: 6px 12px;
        border-bottom: 1px solid #3e3e42;
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        align-items: center;
        flex-shrink: 0;
    }

    .file-tab {
        padding: 6px 12px;
        background: #3e3e42;
        border: 1px solid #555;
        border-radius: 6px 6px 0 0;
        color: #ccc;
        cursor: pointer;
        font-size: 11px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .file-tab.active {
        background: #007acc;
        border-color: #007acc;
        color: white;
    }

    .file-tab button {
        background: none;
        border: none;
        color: inherit;
        cursor: pointer;
        font-size: 10px;
    }

    .add-file-btn {
        background: #555;
        border: none;
        border-radius: 4px;
        padding: 6px 10px;
        color: #ccc;
        cursor: pointer;
        font-size: 11px;
    }

    .add-file-btn:hover {
        background: #666;
    }

    .monaco-container {
        flex: 1;
        min-height: 0;
    }

    .coding-resize-handle {
        width: 6px;
        background: #3e3e42;
        cursor: col-resize;
        transition: background 0.2s;
        flex-shrink: 0;
    }

    .coding-resize-handle:hover,
    .coding-resize-handle.active {
        background: #007acc;
    }

    .preview-container {
        width: 45%;
        min-width: 280px;
        max-width: 60%;
        background: #1e1e1e;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border-left: 1px solid #3e3e42;
    }

    .preview-header {
        background: #2d2d30;
        padding: 8px 12px;
        border-bottom: 1px solid #3e3e42;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 11px;
        font-weight: 600;
        color: #ccc;
        flex-shrink: 0;
        flex-wrap: wrap;
        gap: 8px;
    }

    .output-area {
        flex: 1;
        margin: 0;
        padding: 12px;
        background: #1e1e1e;
        color: #d4d4d4;
        font-family: 'Consolas', monospace;
        font-size: 12px;
        overflow: auto;
        white-space: pre-wrap;
    }

    .web-preview-frame {
        flex: 1;
        width: 100%;
        border: none;
        background: white;
        display: none;
    }

    .input-area {
        background: #2d2d30;
        padding: 12px;
        border-bottom: 1px solid #3e3e42;
        display: none;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .input-area label {
        font-size: 12px;
        font-weight: 600;
        color: #ccc;
    }

    .input-field {
        padding: 6px 12px;
        background: #3e3e42;
        border: 1px solid #555;
        border-radius: 4px;
        font-size: 12px;
        color: #d4d4d4;
        width: 200px;
    }

    .input-submit {
        padding: 6px 16px;
        background: #007acc;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
    }

    .input-submit:hover {
        background: #005a99;
    }

    /* Bottom Bar */
    .fixed-bottom-bar {
        height: 50px;
        background: #2d2d30;
        border-top: 1px solid #3e3e42;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 20px;
        padding: 0 20px;
        flex-shrink: 0;
    }

    .nav-buttons {
        display: flex;
        gap: 12px;
    }

    .bottom-btn {
        padding: 8px 20px;
        background: #3e3e42;
        border: 1px solid #555;
        border-radius: 6px;
        color: #ccc;
        cursor: pointer;
        font-size: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }

    .bottom-btn:hover:not(:disabled) {
        background: #555;
        border-color: #007acc;
        color: #007acc;
    }

    .bottom-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .bottom-btn.primary {
        background: #0e639c;
        border-color: #0e639c;
        color: white;
    }

    .bottom-btn.primary:hover {
        background: #1177bb;
    }

    .run-btn {
        background: #dc2626 !important;
        border-color: #f87171 !important;
        color: #ffffff !important;
        font-weight: 900;
        min-width: 118px;
        justify-content: center;
        box-shadow: 0 0 0 1px rgba(248,113,113,.35), 0 10px 22px rgba(220,38,38,.2);
    }

    .run-btn:hover:not(:disabled) {
        background: #b91c1c !important;
        color: #ffffff !important;
    }

    body.light .run-btn {
        border-color: #991b1b !important;
    }

    .page-indicator {
        font-size: 12px;
        color: #888;
    }

    /* Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    ::-webkit-scrollbar-track {
        background: #2d2d30;
    }

    ::-webkit-scrollbar-thumb {
        background: #555;
        border-radius: 4px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #666;
    }

    /* Fullscreen */
    body:fullscreen {
        width: 100%;
        height: 100%;
    }

    /* Blur Overlay */
    .blur-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.95);
        backdrop-filter: blur(10px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 20px;
        color: white;
        font-size: 24px;
        animation: fadeIn 0.3s ease;
    }

    .timer-warning {
        color: #f48771;
        font-size: 48px;
        font-weight: bold;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    /* Toast */
    .toast {
        position: fixed;
        bottom: 80px;
        left: 50%;
        transform: translateX(-50%);
        background: #2d2d30;
        color: #d4d4d4;
        padding: 10px 20px;
        border-radius: 8px;
        z-index: 10000;
        font-size: 13px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        border-left: 3px solid #007acc;
    }

    .toast.success {
        border-left-color: #4ec9b0;
    }

    .toast.error {
        border-left-color: #f48771;
    }

    .toast.warning {
        border-left-color: #ffcc00;
    }


    /* Monaco Editor Container Styling - VS Code Style */
    .monaco-container {
        flex: 1;
        min-height: 500px;
        height: 100%;
        width: 100%;
        position: relative;
        border: 1px solid #3e3e42;
        border-radius: 6px;
        overflow: hidden;
    }

    .editor-container {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        min-width: 300px;
        height: 100%;
    }

    .coding-split {
        display: flex;
        height: 100%;
        overflow: hidden;
        min-height: 550px;
        gap: 4px;
    }

    /* File tabs styling */
    .file-tabs {
        background: #252526;
        padding: 6px 12px;
        border-bottom: 1px solid #3e3e42;
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        align-items: center;
        flex-shrink: 0;
    }

    .file-tab {
        padding: 6px 12px;
        background: #2d2d30;
        border: 1px solid #3e3e42;
        border-radius: 6px 6px 0 0;
        color: #cccccc;
        cursor: pointer;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }

    .file-tab:hover {
        background: #3e3e42;
    }

    .file-tab.active {
        background: #1e1e1e;
        border-bottom-color: #1e1e1e;
        color: #ffffff;
    }

    .file-tab button {
        background: none;
        border: none;
        color: #858585;
        cursor: pointer;
        font-size: 12px;
        padding: 0 4px;
    }

    .file-tab button:hover {
        color: #ffffff;
    }

    .add-file-btn {
        background: #3e3e42;
        border: 1px solid #555;
        border-radius: 6px;
        padding: 6px 12px;
        color: #cccccc;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.2s;
    }

    .add-file-btn:hover {
        background: #4e4e52;
        border-color: #007acc;
    }

    /* Preview container */
    .preview-container {
        width: 45%;
        min-width: 300px;
        max-width: 60%;
        background: #1e1e1e;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border-left: 1px solid #3e3e42;
        border-radius: 8px;
    }

    .preview-header {
        background: #2d2d30;
        padding: 10px 15px;
        border-bottom: 1px solid #3e3e42;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        font-weight: 600;
        color: #cccccc;
        flex-shrink: 0;
    }

    .output-area {
        flex: 1;
        margin: 0;
        padding: 15px;
        background: #1e1e1e;
        color: #d4d4d4;
        font-family: 'Consolas', monospace;
        font-size: 13px;
        overflow: auto;
        white-space: pre-wrap;
        line-height: 1.5;
    }

    .output-tabs {
        display: flex;
        gap: 6px;
        align-items: center;
    }

    .output-tab {
        border: 1px solid #3e3e42;
        background: #252526;
        color: #d4d4d4;
        border-radius: 6px;
        padding: 6px 10px;
        font-size: 11px;
        cursor: pointer;
    }

    .output-tab.active {
        background: #007acc;
        border-color: #007acc;
        color: #ffffff;
    }

    .console-input-card {
        border: 1px solid #3e3e42;
        border-radius: 8px;
        background: #252526;
        color: #d4d4d4;
        padding: 14px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .console-input-card textarea,
    .console-input-card input {
        width: 100%;
        border-radius: 8px;
        border: 1px solid #3e3e42;
        background: #1e1e1e;
        color: #d4d4d4;
        padding: 12px;
        font-family: Consolas, monospace;
    }

    .console-input-card textarea {
        min-height: 120px;
        resize: vertical;
    }

    .stdin-panel {
        display: none;
    }

    .terminal-session {
        min-height: 100%;
        padding: 12px;
        background: #111;
        color: #f8fafc;
        font-family: Consolas, "Courier New", monospace;
        font-size: 14px;
        line-height: 1.4;
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
        caret-color: #22c55e;
    }

    .console-input-row {
        display: grid;
        grid-template-columns: minmax(120px, 1fr) minmax(150px, 1.2fr);
        gap: 10px;
        align-items: center;
    }

    .console-input-row label {
        color: #d4d4d4;
        font-size: 12px;
    }

    .empty-editor-state,
    .ui-output-area {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 12px;
        padding: 24px;
        text-align: center;
        color: #b8b8b8;
        background: #1e1e1e;
    }

    /* Coding resize handle */
    .coding-resize-handle {
        width: 6px;
        background: #3e3e42;
        cursor: col-resize;
        transition: background 0.2s;
        flex-shrink: 0;
    }

    .coding-resize-handle:hover,
    .coding-resize-handle.active {
        background: #007acc;
    }

    /* Monaco Editor Container - Ensure proper rendering */
    .monaco-container {
        flex: 1;
        min-height: 450px;
        height: 100%;
        width: 100%;
        position: relative;
        border: 1px solid #3e3e42;
        border-radius: 6px;
        overflow: hidden;
    }

    /* Ensure Monaco editor content has proper colors */
    .monaco-editor .mtk1 {
        color: #d4d4d4;
    }

    .monaco-editor .mtk2 {
        color: #9cdcfe;
    }

    .monaco-editor .mtk3 {
        color: #ce9178;
    }

    .monaco-editor .mtk4 {
        color: #569cd6;
    }

    .monaco-editor .mtk5 {
        color: #dcdcaa;
    }

    .monaco-editor .mtk6 {
        color: #4ec9b0;
    }

    .monaco-editor .mtk7 {
        color: #c586c0;
    }

    .monaco-editor .mtk8 {
        color: #b5cea8;
    }

    .monaco-editor .mtk9 {
        color: #6a9955;
    }

    /* Comment style */
    .monaco-editor .comment {
        color: #6a9955;
        font-style: italic;
    }

    /* Keyword style */
    .monaco-editor .keyword {
        color: #569cd6;
    }

    /* String style */
    .monaco-editor .string {
        color: #ce9178;
    }

    /* Number style */
    .monaco-editor .number {
        color: #b5cea8;
    }

    /* Function style */
    .monaco-editor .function {
        color: #dcdcaa;
    }

    /* Class style */
    .monaco-editor .class {
        color: #4ec9b0;
    }

    /* Variable style */
    .monaco-editor .variable {
        color: #9cdcfe;
    }

    /* External Compiler Modal Styles */
    .external-compiler-btn {
        padding: 14px 20px;
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-size: 16px;
        font-weight: 600;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
    }

    .external-compiler-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
    }

    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        z-index: 100000;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-container {
        background: var(--panel);
        border-radius: 16px;
        padding: 30px;
        max-width: 500px;
        width: 90%;
        border: 1px solid var(--border);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .modal-header h2 {
        color: var(--text);
        margin: 0;
    }

    .modal-close {
        background: none;
        border: none;
        color: var(--muted);
        font-size: 24px;
        cursor: pointer;
    }

    .modal-close:hover {
        color: var(--danger);
    }

    /* Compiler Panel - Replaces Output Panel */
    .compiler-panel {
        width: 45%;
        min-width: 300px;
        max-width: 60%;
        background: #1e1e1e;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border-left: 1px solid #3e3e42;
    }

    .compiler-header {
        background: #2d2d30;
        padding: 10px 15px;
        border-bottom: 1px solid #3e3e42;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        font-weight: 600;
        color: #cccccc;
        flex-shrink: 0;
    }

    .compiler-header span i {
        color: #8b5cf6;
        margin-right: 8px;
    }

    .compiler-header button {
        background: #3b82f6;
        border: none;
        color: white;
        padding: 5px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 11px;
        transition: all 0.2s;
    }

    .compiler-header button:hover {
        transform: scale(1.02);
    }

    #copyCodeBtn {
        background: #10b981;
    }

    #copyCodeBtn:hover {
        background: #059669;
    }

    #refreshCompilerBtn {
        background: #6b7280;
    }

    #refreshCompilerBtn:hover {
        background: #4b5563;
    }

    .compiler-frame {
        flex: 1;
        width: 100%;
        border: none;
        background: #ffffff;
    }

    .compiler-note {
        background: #2d2d30;
        padding: 8px 12px;
        border-top: 1px solid #3e3e42;
        font-size: 11px;
        color: #888;
        text-align: center;
    }
    </style>
</head>

<body>
    <div class="fixed-header">
        <div class="header-left">
            <div class="logo-icon">Q</div>
            <span class="system-name">Qoda PU</span>
        </div>
        <div class="header-center">
            <span class="course-name"
                id="courseName"><?php echo htmlspecialchars($examData['title'] ?? 'Loading...'); ?></span>
            <span class="separator">|</span>
            <span class="course-code"
                id="courseCode"><?php echo htmlspecialchars($examData['course_code'] ?? '---'); ?></span>
            <span class="separator">|</span>
            <span class="course-code"
                id="examCodeDisplay"><?php echo htmlspecialchars($examData['exam_id'] ?? '---'); ?></span>
            <span class="separator">|</span>
            <span class="student-id" id="studentIdDisplay"><?php echo htmlspecialchars($studentData['student_id'] ?? $studentId); ?></span>
        </div>
        <div class="header-right">
            <button class="share-screen" id="shareScreenBtn" onclick="startScreenShare()" title="Share Screen with Lecturer"><i
                    class="fas fa-share-alt"></i></button>
            <button class="exam-theme-toggle" id="examThemeToggle" onclick="toggleExamTheme()" title="Toggle theme"><span id="examThemeIcon"
                    aria-hidden="true">&#9790;</span></button>
            <button class="exam-top-action" onclick="showInstructionsModal()" title="View instructions">
                <i class="fas fa-list-ol"></i> Instructions
            </button>
            <button class="exam-top-action save" onclick="saveCurrentAnswer()" title="Save work">
                <i class="fas fa-save"></i> Save
            </button>
            <button class="submit-exam-top" id="submitExamTopBtn" onclick="submitExam(false)" title="Submit exam">
                <i class="fas fa-paper-plane"></i> Submit Exam
            </button>
            <div class="timer" id="timerDisplay"><i class="fas fa-clock"></i> <span id="timerText">01:00:00</span></div>
        </div>
    </div>
    </div>

    <div class="question-nav-bar">
        <div class="question-meta">
            <div class="question-id-area">
                <span class="question-id" id="questionId">Q1</span>
                <!-- Removed QID display - hidden -->
                <span class="question-unique-id" id="questionUniqueId" style="display: none;"></span>
                <span class="question-marks" id="questionMarks">0 marks</span>
                <span class="question-type" id="questionType">CODING</span>
                <span class="datetime" id="datetime"></span>
            </div>
            <div class="question-buttons" id="questionButtons"></div>
        </div>
    </div>

    <div class="main-content">
        <div class="left-panel" id="leftPanel">
            <div class="left-panel-content">
                <div class="question-type-box">
                    <span id="questionTypeBox">Coding Question</span>
                </div>
                <div class="question-text-area" id="questionText"></div>
                <div class="subquestion-buttons" id="subquestionSection" style="display: none;">
                    <div class="subq-title">SUB-QUESTIONS</div>
                    <div class="subq-buttons" id="subqButtons"></div>
                    <div class="subquestion-content" id="subquestionContent"></div>
                    <button class="main-question-btn" onclick="showMainQuestion()" id="showMainBtn"
                        style="display: none;">Show Main Question</button>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-label">PROGRESS</div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div class="progress-text" id="progressText">0 of 0 answered</div>
                </div>
            </div>
        </div>
        <div class="resize-handle" id="resizeHandle"></div>
        <div class="right-panel" id="rightPanel">
            <div class="coding-split">
                <!-- Left side: Monaco Editor -->
                <div class="editor-container">
                    <div class="file-tabs" id="fileTabs"></div>
                    <div id="monacoEditor" class="monaco-container"></div>
                </div>
                <!-- Resize handle -->
                <div class="coding-resize-handle" id="codingResizeHandle"></div>
                <!-- Right side: Online Compiler (replaces console output) -->
                <div id="compilerPanel" class="compiler-panel">
                    <div class="compiler-header">
                        <span id="compilerTitle"><i class="fas fa-code"></i> Loading compiler...</span>
                        <div>
                            <button id="pasteAndRunBtn" onclick="pasteAndRun()"
                                style="background: #8b5cf6; border: none; color: white; padding: 4px 12px; border-radius: 6px; margin-right: 8px; cursor: pointer;">
                                <i class="fas fa-play"></i> Execute
                            </button>
                            <button id="refreshCompilerBtn" onclick="refreshCompiler()"
                                style="background: #6b7280; border: none; color: white; padding: 4px 12px; border-radius: 6px; cursor: pointer;">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <iframe id="compilerFrame" class="compiler-frame" title="Online Compiler" src="about:blank"
                        sandbox="allow-same-origin allow-scripts allow-forms allow-modals allow-popups allow-popups-to-escape-sandbox"></iframe>
                    <div class="compiler-note">
                        <i class="fas fa-info-circle"></i>
                        <strong>Instructions:</strong> Write code in the left editor → Click "Execute" to see output.
                        <span style="color: #f59e0b;">⚠️ Do not click external links inside the compiler - this may
                            violate exam rules.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="fixed-bottom-bar">
        <div class="nav-buttons">
            <button class="bottom-btn" id="prevBtn" onclick="prevQuestion()"><i class="fas fa-chevron-left"></i>
                Previous</button>
        </div>
        <div class="page-indicator" id="pageIndicator">Question 1 of 1</div>
        <div class="nav-buttons">
            <button class="bottom-btn run-btn" id="testOnlineBtn" onclick="runCode()">
                <i class="fas fa-play"></i> RUN
            </button>
            <button class="bottom-btn" id="resetBtn" onclick="resetCode()"><i class="fas fa-undo-alt"></i>
                Reset</button>
            <button class="bottom-btn" id="nextBtn" onclick="nextQuestion()">Next <i
                    class="fas fa-chevron-right"></i></button>
        </div>
    </div>

    <script>
    // ============================================
    // ENHANCED TAB SWITCHING & WINDOW LOCK
    // ============================================

    let windowMinimized = false;
    let lastVisibilityChange = Date.now();
    let violationReported = false;
    let outputInteractionUntil = 0;

    function markOutputInteraction() {
        outputInteractionUntil = Date.now() + 60000;
    }

    function isOutputInteractionActive() {
        return Date.now() < outputInteractionUntil || isFocusInsideOutputPanel();
    }

    function isFocusInsideOutputPanel() {
        const active = document.activeElement;
        if (!active) return false;
        if (active.id === 'webPreview' || active.id === 'compilerFrame') return true;
        return !!active.closest?.('.preview-container, .output-area, .web-preview-frame, .ui-output-area');
    }

    function bindSafeOutputFrame(frame) {
        if (!frame || frame.dataset.safeOutputBound === '1') return;
        frame.dataset.safeOutputBound = '1';

        ['pointerenter', 'mouseenter', 'pointerdown', 'mousedown', 'touchstart', 'focus', 'wheel'].forEach(eventName => {
            frame.addEventListener(eventName, markOutputInteraction, true);
        });

        frame.addEventListener('load', () => {
            markOutputInteraction();
            try {
                const doc = frame.contentDocument;
                if (!doc) return;
                ['pointerdown', 'mousedown', 'click', 'keydown', 'keyup', 'wheel', 'touchstart', 'focusin'].forEach(eventName => {
                    doc.addEventListener(eventName, markOutputInteraction, true);
                });
                doc.querySelectorAll('a[target="_blank"]').forEach(link => link.setAttribute('target', '_self'));
            } catch (error) {
                // Cross-origin previews cannot be inspected, but focusing the iframe is still safe.
            }
        });
    }

    // Detect tab/window switching
    document.addEventListener('visibilitychange', function() {
        const now = Date.now();
        console.log("Visibility changed. Hidden:", document.hidden);

        if (document.hidden && !screenSharePromptActive && !isOutputInteractionActive() && !pageIsUnloading && !examLocked && !examLockedByViolation && !isPreview) {
            const timeSinceLastChange = now - lastVisibilityChange;

            // Only count as violation if they stayed away for more than 1 second
            if (timeSinceLastChange > 1000) {
                tabSwitchCount++;
                console.log(`Tab switch count: ${tabSwitchCount}`);

                lockExamWithViolation('tab_switch_attempt');
            }

            lastVisibilityChange = now;
        } else if (!document.hidden) {
            // Page became visible again
            lastVisibilityChange = now;
            removeBlurOverlay();

            if (examLockedByViolation) {
                showLockedScreen();
            }
        }
    });

    // Detect window blur (switching to another application)
    let wasBlurred = false;
    let blurTimer = null;

    window.addEventListener('blur', function() {
        console.log("Window blurred");
        setTimeout(() => {
            if (isOutputInteractionActive()) {
                wasBlurred = false;
                if (blurTimer) clearTimeout(blurTimer);
            }
        }, 50);

        if (!screenSharePromptActive && !isOutputInteractionActive() && !pageIsUnloading && !examLocked && !examLockedByViolation && !isPreview) {
            wasBlurred = true;

            // Check after 2 seconds if they're still away
            blurTimer = setTimeout(() => {
                if (isOutputInteractionActive()) {
                    wasBlurred = false;
                    return;
                }
                if (wasBlurred && !examLockedByViolation && !examLocked) {
                    tabSwitchCount++;
                    console.log(`Window blur count: ${tabSwitchCount}`);

                    lockExamWithViolation('window_switch_attempt');
                }
            }, 2000);
        }
    });

    window.addEventListener('focus', function() {
        console.log("Window focused");
        wasBlurred = false;
        if (blurTimer) clearTimeout(blurTimer);
        removeBlurOverlay();

        if (examLockedByViolation) {
            showLockedScreen();
        }
    });

    function showBlurWarning() {
        const warningDiv = document.createElement('div');
        warningDiv.className = 'blur-overlay';
        warningDiv.innerHTML = `
        <i class="fas fa-exclamation-triangle" style="font-size: 64px; color: #ffcc00;"></i>
        <h2 style="color: #ffcc00;">Warning!</h2>
        <p>You switched away from the exam window.</p>
        <p style="font-size: 14px; margin-top: 10px;">This has been recorded. Further violations will lock your exam.</p>
        <p style="font-size: 12px; margin-top: 20px;">Warning ${tabSwitchCount}/2</p>
    `;
        document.body.appendChild(warningDiv);

        setTimeout(() => {
            if (warningDiv.parentNode) warningDiv.remove();
        }, 3000);
    }

    function removeBlurOverlay() {
        const overlays = document.querySelectorAll('.blur-overlay');
        overlays.forEach(overlay => overlay.remove());
        if (blurTimer) clearTimeout(blurTimer);
    }



    // ========== WORKING TIMER FUNCTIONS ==========
    function startExamTimer() {
        console.log("===== START EXAM TIMER CALLED =====");
        console.log("Exam ID:", examId);
        console.log("isPreview:", isPreview);
        console.log("dbExam:", dbExam);

        if (isPreview) {
            console.log("Preview mode - timer disabled");
            document.getElementById('timerText').textContent = "PREVIEW";
            return;
        }

        // Use the database-calculated remaining time so the browser clock cannot expire exams early.
        let durationMinutes = 60;
        if (dbExam && dbExam.duration_minutes) durationMinutes = parseInt(dbExam.duration_minutes, 10) || 60;
        const serverRemainingSeconds = dbExam && dbExam.remaining_seconds !== undefined ? parseInt(dbExam.remaining_seconds, 10) : null;
        const totalSeconds = serverRemainingSeconds !== null && !Number.isNaN(serverRemainingSeconds) ?
            Math.max(0, serverRemainingSeconds) :
            durationMinutes * 60;
        console.log("Duration minutes:", durationMinutes, "Total seconds:", totalSeconds);

        // IMPORTANT: Use exam-specific localStorage keys
        let startTime = localStorage.getItem('exam_start_time_' + examId);
        const now = Math.floor(Date.now() / 1000);
        console.log("Current timestamp:", now);
        console.log("Stored start time:", startTime);

        if (!startTime || startTime === 'null' || startTime === 'undefined') {
            // First time - set start time
            startTime = now;
            localStorage.setItem('exam_start_time_' + examId, startTime);
            timeLeft = totalSeconds;
            localStorage.setItem('exam_timeLeft_' + examId, timeLeft);
            console.log("FIRST TIME - Timer started at:", new Date().toLocaleTimeString(), "Time left:", timeLeft);
        } else {
            // Calculate remaining time, but never exceed the server-side exam window.
            const elapsed = now - parseInt(startTime);
            const localRemaining = Math.max(0, (durationMinutes * 60) - elapsed);
            timeLeft = serverRemainingSeconds !== null && !Number.isNaN(serverRemainingSeconds) ?
                Math.min(localRemaining, Math.max(0, serverRemainingSeconds)) :
                localRemaining;
            localStorage.setItem('exam_timeLeft_' + examId, timeLeft);
            console.log("EXISTING - Elapsed:", elapsed, "Time left:", timeLeft);

            if (timeLeft <= 0) {
                console.log("TIME IS UP!");
                showMessageBox("Time is up. Submitting your exam now.");
                submitExam(true);
                return;
            }
        }

        // Update display immediately
        updateTimerDisplay();

        // Clear any existing interval
        if (timerInterval) {
            console.log("Clearing existing timer interval:", timerInterval);
            clearInterval(timerInterval);
            timerInterval = null;
        }

        // Start countdown
        console.log("Starting new timer interval...");
        timerInterval = setInterval(function() {
            if (timeLeft > 0) {
                timeLeft--;
                localStorage.setItem('exam_timeLeft_' + examId, timeLeft);
                updateTimerDisplay();

                // Auto-submit at 5 seconds warning
                if (timeLeft === 5) {
                    showToast("5 seconds remaining!", "warning");
                }

                if (timeLeft <= 0) {
                    showMessageBox("Time is up. Submitting your exam now.");
                    submitExam(true);
                }


            }
        }, 1000);

        console.log("Timer interval set, ID:", timerInterval);
    }

    function updateTimerDisplay() {
        const hours = Math.floor(timeLeft / 3600);
        const minutes = Math.floor((timeLeft % 3600) / 60);
        const seconds = timeLeft % 60;
        const timerText = document.getElementById('timerText');
        const timerDisplay = document.getElementById('timerDisplay');

        if (timerText) {
            timerText.textContent =
                `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }

        if (timeLeft <= 1800 && timerDisplay && !isPreview) {
            timerDisplay.classList.add('warning');
            const warningKey = `exam_30_minute_warning_${examId}`;
            if (!localStorage.getItem(warningKey)) {
                localStorage.setItem(warningKey, 'shown');
                showMessageBox('30 minutes remaining. Please hurry up and submit before time runs out.');
            }
        }
    }

    function lockExamWithViolation(reason) {
        if (examLockedByViolation || examLocked) return;

        console.log("Locking exam due to:", reason);
        examLockedByViolation = true;
        examLocked = true;
        screenSharingLocked = true;

        if (window.forceUpdateInterval) clearInterval(window.forceUpdateInterval);

        // Store lock state
        localStorage.setItem('screen_locked_' + examId, 'true');
        localStorage.setItem('exam_lock_reason_' + examId, reason);
        localStorage.setItem('exam_lock_time_' + examId, Date.now());

        captureViolationEvidence(reason).finally(() => {
            reportViolation(reason);
            showLockedScreen();
        });
    }

    async function captureViolationEvidence(reason) {
        if (!screenSharingActive || !screenVideoEl || screenVideoEl.readyState < 2 || !screenCanvasEl) return;
        const timestamp = new Date().toLocaleString();
        await sendScreenSnapshot('violation', `Screen locked after rule violation: ${reason}. Captured before lock at ${timestamp}.`);
    }

    function lockExamFromLecturer(message = 'Your exam screen has been locked by the lecturer.') {
        if (examLocked) return;
        examLocked = true;
        examLockedByViolation = false;
        screenSharingLocked = true;
        localStorage.setItem('screen_locked_' + examId, 'true');
        localStorage.setItem('exam_lock_reason_' + examId, 'lecturer_lock');
        showLockedScreen(message);
    }
    // ============================================
    // PERSISTENT SCREEN SHARING - SURVIVES REFRESH
    // ============================================

    let screenSharingCheckInterval = null;

    let screenShareRestartAttempts = 0;
    let screenCaptureInterval = null;
    let proctorCommandInterval = null;
    let screenVideoEl = null;
    let screenCanvasEl = null;
    let screenImageCapture = null;
    let screenSnapshotInFlight = false;
    let screenSharePromptActive = false;
    let screenShareStartedThisPage = false;
    let screenHeartbeatInterval = null;
    let pageIsUnloading = false;

    // Start screen share with persistence
    // ========== SIMPLE WORKING SCREEN SHARING ==========
    async function startScreenShare() {
        console.log("Starting screen share...");
        if (screenSharePromptActive || screenSharingActive) return screenSharingActive;
        screenSharePromptActive = true;

        if (isPreview) {
            console.log("Preview mode - screen sharing disabled");
            screenSharePromptActive = false;
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            showToast('Screen sharing not supported in this browser', 'error');
            screenSharePromptActive = false;
            return false;
        }

        try {
            await ensureExamSession();
            await requestExamFullscreen();
            screenStream = await navigator.mediaDevices.getDisplayMedia({
                video: {
                    cursor: "always",
                    displaySurface: "monitor"
                },
                audio: false
            });

            screenSharingActive = true;
            screenShareStartedThisPage = true;
            localStorage.setItem('screen_active_' + examId, 'true');
            localStorage.setItem('screen_sharing_active', 'true');
            localStorage.setItem('screen_sharing_exam_id', examId);
            localStorage.setItem('screen_sharing_start_time', Date.now().toString());
            updateScreenShareButton();
            document.getElementById('screenShareRequiredOverlay')?.remove();
            const track = screenStream.getVideoTracks()[0];
            try {
                screenImageCapture = (track && window.ImageCapture) ? new ImageCapture(track) : null;
            } catch (captureError) {
                screenImageCapture = null;
            }
            startScreenHeartbeat();
            setupScreenCapturePipeline();
            startProctorCommandPolling();
            await requestExamFullscreen();
            showToast('Screen sharing active!', 'success');

            if (track) {
                track.onended = function() {
                    console.log("Screen sharing stopped");
                    screenSharingActive = false;
                    stopScreenCapturePipeline();
                    stopScreenHeartbeat();
                    updateScreenShareButton();
                    localStorage.removeItem('screen_active_' + examId);
                    localStorage.removeItem('screen_sharing_active');
                    if (pageIsUnloading) return;
                    showToast('Screen sharing stopped. Please share again to keep proctoring active.', 'warning');
                    showScreenShareOverlay();
                };
            }

            return true;
        } catch (err) {
            console.error("Screen share error:", err);
            showToast('Screen sharing is required before writing the exam.', 'warning');
            showScreenShareOverlay();
            return false;
        } finally {
            screenSharePromptActive = false;
        }
    }

    async function ensureExamSession() {
        if (isPreview || !examId) return;
        const formData = new URLSearchParams();
        formData.append('action', 'start_exam');
        formData.append('exam_id', examId);
        try {
            await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            });
        } catch (error) {
            console.error('Unable to create exam session:', error);
        }
    }

    function updateScreenShareButton() {
        const btn = document.getElementById('shareScreenBtn');
        if (!btn) return;
        btn.classList.toggle('active', screenSharingActive);
        btn.classList.toggle('sharing-on', screenSharingActive);
        btn.classList.toggle('sharing-off', !screenSharingActive);
        btn.innerHTML = '<span class="share-dot" aria-hidden="true"></span>';
        btn.title = screenSharingActive ? 'Screen sharing ON' : 'Screen sharing OFF';
    }

    function applyExamTheme(theme) {
        document.body.classList.toggle('light', theme === 'light');
        document.body.classList.toggle('dark', theme !== 'light');
        const icon = document.getElementById('examThemeIcon');
        if (icon) icon.innerHTML = theme === 'light' ? '&#9728;' : '&#9790;';
        const button = document.getElementById('examThemeToggle');
        if (button) button.title = theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme';
        localStorage.setItem('qoda_exam_theme', theme);
    }

    function toggleExamTheme() {
        const current = document.body.classList.contains('light') ? 'light' : 'dark';
        applyExamTheme(current === 'light' ? 'dark' : 'light');
    }

    function loadExamTheme() {
        applyExamTheme(localStorage.getItem('qoda_exam_theme') || 'dark');
    }

    function requestExamFullscreen() {
        const el = document.documentElement;
        if (document.fullscreenElement || !el.requestFullscreen) return Promise.resolve(false);
        return el.requestFullscreen()
            .then(() => true)
            .catch(() => false);
    }

    function showScreenShareOverlay() {
        if (isPreview || document.getElementById('screenShareRequiredOverlay')) return;
        const overlay = document.createElement('div');
        overlay.id = 'screenShareRequiredOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.94);z-index:100002;display:flex;align-items:center;justify-content:center;color:white;text-align:center;padding:24px;';
        overlay.innerHTML = `
            <div style="max-width:520px;background:#111827;border:1px solid #374151;border-radius:18px;padding:28px;">
                <i class="fas fa-desktop" style="font-size:56px;color:#60a5fa;margin-bottom:14px;"></i>
                <h2 style="margin-bottom:10px;">Screen sharing required</h2>
                <p style="color:#d1d5db;margin-bottom:18px;">Share your entire screen to continue proctoring this exam.</p>
                <button class="bottom-btn" onclick="document.getElementById('screenShareRequiredOverlay')?.remove(); startScreenShare();" style="background:#2563eb;">
                    <i class="fas fa-desktop"></i> Share Screen
                </button>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    function showFullscreenRequiredOverlay() {
        if (isPreview || examLocked || examLockedByViolation || document.fullscreenElement || document.getElementById('fullscreenRequiredOverlay')) return;
        const overlay = document.createElement('div');
        overlay.id = 'fullscreenRequiredOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.94);z-index:100001;display:flex;align-items:center;justify-content:center;color:white;text-align:center;padding:24px;';
        overlay.innerHTML = `
            <div style="max-width:520px;background:#111827;border:1px solid #374151;border-radius:18px;padding:28px;">
                <i class="fas fa-expand" style="font-size:56px;color:#60a5fa;margin-bottom:14px;"></i>
                <h2 style="margin-bottom:10px;">Return to full screen</h2>
                <p style="color:#d1d5db;margin-bottom:18px;">The exam must stay in full screen while screen sharing is active.</p>
                <button class="bottom-btn" onclick="document.getElementById('fullscreenRequiredOverlay')?.remove(); requestExamFullscreen();" style="background:#2563eb;">
                    <i class="fas fa-expand"></i> Full Screen
                </button>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    document.addEventListener('fullscreenchange', function() {
        if (document.fullscreenElement) {
            document.getElementById('fullscreenRequiredOverlay')?.remove();
            return;
        }

        if (!isPreview && screenSharingActive && !examLocked && !examLockedByViolation) {
            showToast('Full screen exited. You can continue writing, but keep the exam window visible.', 'warning');
        }
    });

    let resizeLockTimer = null;
    window.addEventListener('resize', function() {
        if (isPreview || !screenSharingActive || isOutputInteractionActive() || examLocked || examLockedByViolation || document.fullscreenElement) return;
        if (resizeLockTimer) clearTimeout(resizeLockTimer);
        resizeLockTimer = setTimeout(() => {
            const widthRatio = window.innerWidth / Math.max(screen.availWidth, 1);
            const heightRatio = window.innerHeight / Math.max(screen.availHeight, 1);
            if (widthRatio < 0.72 || heightRatio < 0.72) {
                lockExamWithViolation('window_resize_or_split_screen_attempt');
            }
        }, 1200);
    });

    document.addEventListener('keydown', function(e) {
        if (!isPreview && !examLocked && !examLockedByViolation && (e.key === 'PrintScreen' || (e.ctrlKey && e.key.toLowerCase() === 'p'))) {
            e.preventDefault();
            lockExamWithViolation('screenshot_or_print_attempt');
        }
    });

    document.addEventListener('keyup', function(e) {
        if (!isPreview && !examLocked && (e.key === 'PrintScreen' || e.code === 'PrintScreen')) {
            lockExamWithViolation('screenshot_attempt');
        }
    });

    function showShareRequiredOverlay() {
        if (isPreview || examLocked || screenSharingActive || document.getElementById('shareRequiredOverlay')) return;
        const overlay = document.createElement('div');
        overlay.id = 'shareRequiredOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:100000;display:flex;align-items:center;justify-content:center;color:white;text-align:center;padding:24px;';
        overlay.innerHTML = `
            <div style="max-width:520px;background:#1f2937;border:1px solid #374151;border-radius:18px;padding:28px;">
                <i class="fas fa-desktop" style="font-size:56px;color:#60a5fa;margin-bottom:14px;"></i>
                <h2 style="margin-bottom:10px;">Share your entire screen</h2>
                <p style="color:#d1d5db;margin-bottom:18px;">Screen sharing is required for this exam. Choose your entire screen/window when the browser asks.</p>
                <button class="bottom-btn" onclick="document.getElementById('shareRequiredOverlay')?.remove(); startScreenShare();" style="background:#dc2626;">
                    <i class="fas fa-share-alt"></i> Share Screen
                </button>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    function setupScreenCapturePipeline() {
        if (!screenStream) return;
        if (!screenVideoEl) {
            screenVideoEl = document.createElement('video');
            screenVideoEl.muted = true;
            screenVideoEl.playsInline = true;
            screenVideoEl.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;';
            document.body.appendChild(screenVideoEl);
        }
        if (!screenCanvasEl) {
            screenCanvasEl = document.createElement('canvas');
            screenCanvasEl.width = 960;
            screenCanvasEl.height = 540;
        }
        screenVideoEl.srcObject = screenStream;
        screenVideoEl.play().catch(() => {});
        if (screenCaptureInterval) clearInterval(screenCaptureInterval);
        screenCaptureInterval = setInterval(sendScreenSnapshot, 500);
        screenVideoEl.onloadedmetadata = () => sendScreenSnapshot();
        setTimeout(sendScreenSnapshot, 250);
        setTimeout(sendScreenSnapshot, 900);
    }

    function stopScreenCapturePipeline() {
        if (screenCaptureInterval) clearInterval(screenCaptureInterval);
        screenCaptureInterval = null;
        screenImageCapture = null;
    }

    function startScreenHeartbeat() {
        if (screenHeartbeatInterval) clearInterval(screenHeartbeatInterval);
        sendScreenHeartbeat(true);
        screenHeartbeatInterval = setInterval(() => sendScreenHeartbeat(true), 2000);
    }

    function stopScreenHeartbeat() {
        if (screenHeartbeatInterval) clearInterval(screenHeartbeatInterval);
        screenHeartbeatInterval = null;
        sendScreenHeartbeat(false);
    }

    async function sendScreenHeartbeat(active = true) {
        if (isPreview || !examId) return;
        const formData = new URLSearchParams();
        formData.append('action', 'screen_heartbeat');
        formData.append('exam_id', examId);
        formData.append('active', active ? '1' : '0');
        try {
            await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString(),
                keepalive: true
            });
        } catch (error) {
            console.error('Screen heartbeat failed:', error);
        }
    }

    async function drawScreenFrame(ctx, frameWidth, frameHeight) {
        let source = null;
        let sourceWidth = frameWidth;
        let sourceHeight = frameHeight;

        if (screenImageCapture && typeof screenImageCapture.grabFrame === 'function') {
            try {
                source = await screenImageCapture.grabFrame();
                sourceWidth = source.width || frameWidth;
                sourceHeight = source.height || frameHeight;
            } catch (error) {
                source = null;
            }
        }

        if (!source) {
            if (!screenVideoEl || screenVideoEl.readyState < 2) return false;
            source = screenVideoEl;
            sourceWidth = screenVideoEl.videoWidth || frameWidth;
            sourceHeight = screenVideoEl.videoHeight || frameHeight;
        }

        const ratio = Math.min(frameWidth / sourceWidth, frameHeight / sourceHeight);
        const drawWidth = Math.max(1, Math.floor(sourceWidth * ratio));
        const drawHeight = Math.max(1, Math.floor(sourceHeight * ratio));
        ctx.fillStyle = '#111827';
        ctx.fillRect(0, 0, frameWidth, frameHeight);
        ctx.drawImage(source, (frameWidth - drawWidth) / 2, (frameHeight - drawHeight) / 2, drawWidth, drawHeight);
        if (source && typeof source.close === 'function') source.close();
        return true;
    }

    async function sendScreenSnapshot(captureType = 'live', notes = '') {
        if (!screenSharingActive || !screenCanvasEl) return;
        if (captureType === 'live' && screenSnapshotInFlight) return;
        if (captureType === 'live') screenSnapshotInFlight = true;
        const frameWidth = captureType === 'live' ? 800 : 1280;
        const frameHeight = captureType === 'live' ? 450 : 720;
        const ctx = screenCanvasEl.getContext('2d');
        screenCanvasEl.width = frameWidth;
        screenCanvasEl.height = frameHeight;
        try {
            const drewFrame = await drawScreenFrame(ctx, frameWidth, frameHeight);
            if (!drewFrame) return;
            const snapshot = screenCanvasEl.toDataURL('image/jpeg', captureType === 'live' ? 0.42 : 0.72);
            const formData = new FormData();
            formData.append('action', 'screen_snapshot');
            formData.append('exam_id', examId);
            formData.append('snapshot', snapshot);
            formData.append('capture_type', captureType);
            if (notes) formData.append('notes', notes);
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            if (!response.ok) {
                throw new Error(`Snapshot upload failed with HTTP ${response.status}`);
            }
            const result = await response.json().catch(() => null);
            if (!result || !result.success) {
                throw new Error(result?.error || 'Snapshot upload failed');
            }
        } catch (error) {
            console.error('Snapshot upload failed:', error);
            if (captureType === 'live') sendScreenHeartbeat(true);
        } finally {
            if (captureType === 'live') screenSnapshotInFlight = false;
        }
    }

    function startProctorCommandPolling() {
        if (proctorCommandInterval) clearInterval(proctorCommandInterval);
        proctorCommandInterval = setInterval(pollProctorCommands, 3000);
        pollProctorCommands();
    }

    async function pollProctorCommands() {
        if (isPreview || !examId) return;
        const formData = new URLSearchParams();
        formData.append('action', 'poll_proctor_commands');
        formData.append('exam_id', examId);
        try {
            const response = await fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            });
            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch (parseError) {
                throw new Error(`Compiler returned an invalid response:\n${text.slice(0, 1000)}`);
            }
            if (!result.success || !Array.isArray(result.commands)) return;
            result.commands.forEach(command => {
                if (command.command_type === 'warning') {
                    showToast(command.message || 'Warning from lecturer', 'warning');
                    showLecturerMessage(command.message || 'Warning from lecturer');
                }
                if (command.command_type === 'lock') {
                    localStorage.setItem('screen_locked_' + examId, 'true');
                    lockExamFromLecturer(command.message || 'Your exam screen has been locked by the lecturer.');
                }
                if (command.command_type === 'unlock') {
                    unlockExamScreen(command.message || 'Unlocked by lecturer');
                }
            });
        } catch (error) {
            console.error('Command polling failed:', error);
        }
    }

    function showLecturerMessage(message) {
        const box = document.getElementById('lecturerMessageBox') || document.createElement('div');
        box.id = 'lecturerMessageBox';
        box.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:100001;background:#111827;color:white;border:1px solid #f59e0b;border-radius:14px;padding:16px;max-width:360px;box-shadow:0 18px 40px rgba(0,0,0,.35);';
        box.innerHTML = `<strong style="color:#fbbf24;display:block;margin-bottom:6px;"><i class="fas fa-bell"></i> Lecturer Message</strong><div style="line-height:1.45;">${escapeHtml(message)}</div><button onclick="this.parentElement.remove()" style="margin-top:10px;background:#f59e0b;color:#111827;border:0;border-radius:8px;padding:8px 12px;cursor:pointer;">OK</button>`;
        if (!box.parentElement) document.body.appendChild(box);
    }

    function unlockExamScreen(message = 'Exam unlocked') {
        examLocked = false;
        examLockedByViolation = false;
        screenSharingLocked = false;
        localStorage.removeItem('screen_locked_' + examId);
        localStorage.removeItem('exam_lock_reason_' + examId);
        localStorage.removeItem('exam_lock_time_' + examId);
        const locked = document.getElementById('examLockedOverlay');
        if (locked) locked.remove();
        showToast(message, 'success');
        startExamTimer();
        if (!screenSharingActive) setTimeout(showShareRequiredOverlay, 400);
    }

    function reportViolation(reason) {
        if (isPreview || !examId) return;
        const formData = new URLSearchParams();
        formData.append('action', 'report_violation');
        formData.append('exam_id', examId);
        formData.append('reason', reason);
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData.toString()
        }).catch(error => console.error('Violation report failed:', error));
    }

    function showLockedScreen(message = 'Your screen has been locked by the lecturer or because of a rule violation.') {
        let locked = document.getElementById('examLockedOverlay');
        if (!locked) {
            locked = document.createElement('div');
            locked.id = 'examLockedOverlay';
            locked.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:99999;color:white;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px;';
            locked.innerHTML = `
                <div style="max-width:480px;width:100%;">
                    <i class="fas fa-lock" style="font-size:72px;color:#ef4444;margin-bottom:18px;"></i>
                    <h1>Exam Locked</h1>
                    <p id="examLockedMessage" style="color:#d1d5db;margin:10px 0 18px;">${escapeHtml(message)}</p>
                    <p style="color:#fbbf24;margin:0;">Wait for your lecturer to unlock your screen from the proctoring page.</p>
                </div>
            `;
            document.body.appendChild(locked);
        } else {
            const msg = document.getElementById('examLockedMessage');
            if (msg) msg.textContent = message;
        }
    }


    // Restore screen sharing on page load/refresh
    function restoreScreenSharing() {
        console.log("Checking for screen share restoration...");

        const wasActive = localStorage.getItem('screen_sharing_active');
        const savedExamId = localStorage.getItem('screen_sharing_exam_id');
        const isLocked = localStorage.getItem('screen_locked_' + examId);
        const startTime = localStorage.getItem('screen_sharing_start_time');

        // Check if screen share was active within the last 5 seconds (prevents old stale states)
        let isValid = true;
        if (startTime) {
            const elapsed = Date.now() - parseInt(startTime);
            if (elapsed > 30000) { // Older than 30 seconds
                isValid = false;
                localStorage.removeItem('screen_sharing_active');
                localStorage.removeItem('screen_sharing_start_time');
            }
        }

        if (isLocked === 'true') {
            screenSharingLocked = true;
            examLocked = true;
            showToast('Exam locked by lecturer. Please contact your instructor.', 'error');
            showLockedScreen();
            return;
        }

        if ((wasActive === 'true' && savedExamId === examId && isValid) && !isPreview && !examLocked) {
            console.log("Screen sharing was active before refresh; asking once to resume.");
            setTimeout(showShareRequiredOverlay, 800);
        } else if (!isPreview && !examLocked && !screenShareStartedThisPage) {
            setTimeout(showShareRequiredOverlay, 800);
        }
    }

    window.addEventListener('load', () => {
        startProctorCommandPolling();
    });

    // Also save state before page unload
    window.addEventListener('beforeunload', () => {
        pageIsUnloading = true;
        if (screenSharingActive) {
            localStorage.setItem('screen_sharing_active', 'true');
            localStorage.setItem('screen_sharing_exam_id', examId);
            localStorage.setItem('screen_sharing_start_time', Date.now().toString());
        }
    });

    // ============================================
    // GLOBAL VARIABLES - LOADED FROM DATABASE
    // ============================================
    // ========== GLOBAL VARIABLES (MUST BE FIRST) ==========
    let mainQuestions = [];
    let answers = {};
    let flaggedQuestions = new Set();
    let currentQuestionIndex = 0;
    let currentSubQuestionIndex = -1;
    let showingMainQuestion = true;
    let timeLeft = 0;
    let timerInterval = null;
    let monacoEditor = null;
    let openFiles = [];
    let activeFileIndex = 0;
    let currentLanguage = 'python';
    let examLocked = false;
    let examLockedByViolation = false;
    let screenStream = null;
    let screenSharingActive = false;
    let screenSharingLocked = false;
    let tabSwitchCount = 0;

    // THESE ARE CRITICAL - Get from PHP
    const dbQuestions = <?php echo $questionsJson; ?>;
    const dbExam = <?php echo $examJson; ?>;
    const isPreview = <?php echo $previewJson; ?>;
    const examId = <?php echo json_encode($examId); ?>;
    const studentId = <?php echo json_encode($studentId); ?>;
    const studentName = <?php echo json_encode($studentName); ?>;
    const studentIdentifier = <?php echo json_encode($studentData['student_id'] ?? $studentId); ?>;
    const CODE_EXECUTOR_URL = '../backend-php/code_executor.php';

    function loadQuestions() {
        if (dbQuestions && dbQuestions.length > 0) {
            mainQuestions = dbQuestions.map((q, idx) => ({
                id: q.id || ('Q' + idx),
                type: q.type || 'code',
                text: q.text || q.question_text || '',
                marks: q.marks || 5,
                compulsory: q.compulsory || false,
                language: q.language || 'python',
                subQuestions: q.subQuestions || null,
                hasSubQuestions: q.hasSubQuestions || false,
                testCases: q.testCases || [],
                expectedOutput: q.expectedOutput || '',
                gradingMode: q.gradingMode || 'auto'
            }));
        }

        if (dbExam) {
            document.getElementById('courseName').textContent = dbExam.title || 'Exam';
            document.getElementById('courseCode').textContent = dbExam.course_code || 'Course';
            const examCodeDisplay = document.getElementById('examCodeDisplay');
            if (examCodeDisplay) examCodeDisplay.textContent = dbExam.exam_id || buildDisplayExamCode(dbExam);
            document.getElementById('studentIdDisplay').textContent = studentIdentifier;

        }

        // Load saved answers from localStorage
        loadSavedAnswers();
        loadSavedProgress();
        loadFlaggedQuestions();
        renderQuestionButtons();
        renderCurrentQuestion();
    }

    // DEBUG: Check localStorage on page load
    console.log("=== DEBUG INFO ===");
    console.log("examId:", examId);
    console.log("isPreview:", isPreview);
    console.log("Stored start time:", localStorage.getItem('exam_start_time_' + examId));
    console.log("Stored time left:", localStorage.getItem('exam_timeLeft_' + examId));
    console.log("=================");

    function updateDateTime() {
        document.getElementById('datetime').textContent = new Date().toLocaleString();
    }
    setInterval(updateDateTime, 1000);
    updateDateTime();

    // ============================================
    // CLEAR ANSWER FUNCTION
    // ============================================
    function clearCurrentAnswer() {
        showConfirmDialog('Clear answer?', 'This will clear only the selected question answer.', clearSelectedAnswerNow);
    }

    function clearSelectedAnswerNow() {
        const q = mainQuestions[currentQuestionIndex];
        if (!q) return;

        const answerKey = getCurrentAnswerKey();
        delete answers[answerKey];
        saveAnswers();

        // Reset editor if needed
        if (monacoEditor && openFiles[activeFileIndex]) {
            monacoEditor.setValue('');
            openFiles[activeFileIndex].content = '';
        }

        renderCurrentQuestion();
        renderQuestionButtons();
        updateProgress();
        showToast('Answer cleared', 'info');
    }

    function getCurrentAnswerKey() {
        const q = mainQuestions[currentQuestionIndex];
        if (!showingMainQuestion && currentSubQuestionIndex >= 0 && q.subQuestions && q.subQuestions[
                currentSubQuestionIndex]) {
            return q.subQuestions[currentSubQuestionIndex].id;
        }
        return q.id;
    }
    // ============================================
    // REAL CODE EXECUTION ENGINE
    // ============================================
    async function executeCode(code, language) {
        try {
            const formData = new FormData();
            formData.append('action', 'execute_code');
            formData.append('code', code);
            formData.append('language', language);
            formData.append('exam_id', examId);
            formData.append('input', document.getElementById('programInput')?.value || '');
            formData.append('files', JSON.stringify(openFiles.map(file => ({
                name: file.name,
                language: file.language,
                content: file.content || '',
                active: file === openFiles[activeFileIndex]
            }))));

            const response = await fetch(CODE_EXECUTOR_URL, {
                method: 'POST',
                body: formData
            });

            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch (parseError) {
                return {
                    output: null,
                    error: `Compiler endpoint returned an invalid response:\n${text.slice(0, 1000)}`
                };
            }

            if (result.success) {
                return {
                    output: result.output,
                    error: result.error,
                    execution_time: result.execution_time
                };
            } else {
                return {
                    output: null,
                    error: result.error || 'Execution failed'
                };
            }
        } catch (error) {
            console.error('Execution error:', error);
            return {
                output: null,
                error: error.message
            };
        }
    }

    // For web languages (HTML/CSS/JS)
    function createWebPreview(code, language) {
        if (language === 'html') {
            return code;
        } else if (language === 'css') {
            return `<!DOCTYPE html>
        <html>
        <head><style>${code}</style></head>
        <body><div style="padding:20px"><h1>CSS Preview</h1><p>Your CSS styles are applied above.</p><button>Test Button</button></div></body>
        </html>`;
        } else if (language === 'javascript' || language === 'js') {
            return `<!DOCTYPE html>
        <html>
        <head><title>JavaScript Output</title></head>
        <body>
        <div id="output" style="padding:20px;font-family:monospace;white-space:pre-wrap;"></div>
        <script>
        (function() {
            const outputDiv = document.getElementById('output');
            const originalLog = console.log;
            console.log = function(...args) {
                outputDiv.innerHTML += args.join(' ') + '\\n';
                originalLog.apply(console, args);
            };
            try {
                ${code}
            } catch(e) {
                outputDiv.innerHTML += 'Error: ' + e.message;
            }
        })();
        <\/script>
        </body>
        </html>`;
        }
        return `<pre style="padding:20px">${escapeHtml(code)}</pre>`;
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    function init() {
        console.log("Initializing exam interface...");
        console.log("Exam ID:", examId);
        console.log("Preview mode:", isPreview);
        console.log("dbExam:", dbExam);

        loadExamTheme();
        loadQuestions();
        startExamTimer(); // Use the correct timer function
        history.pushState(null, '', location.href);

        // ADD THIS DEBUG CHECK
        console.log("Timer interval after start:", timerInterval);
        console.log("timeLeft value:", timeLeft);

        initResizeHandles();
        disableCopyPaste();

        if (!isPreview) {
            restoreScreenSharing();
        }
        updateScreenShareButton();

        initCompiler();

        // Check if exam was locked before
        const wasLocked = localStorage.getItem('screen_locked_' + examId);
        if (wasLocked === 'true') {
            showLockedScreen();
        }

        // Check if already submitted
        const alreadySubmitted = localStorage.getItem('exam_submitted_' + examId);
        if (alreadySubmitted === 'true') {
            showMessageBox("You have already submitted this exam.");
            return;
        }

        // Auto-save every 30 seconds
        setInterval(function() {
            if (!examLocked && !examLockedByViolation) {
                saveAnswers();
                saveProgress();
            }
        }, 30000);
    }



    function disableCopyPaste() {
        document.querySelectorAll('input, textarea').forEach(el => {
            el.addEventListener('copy', (e) => e.preventDefault());
            el.addEventListener('cut', (e) => e.preventDefault());
            el.addEventListener('paste', (e) => e.preventDefault());
        });
    }

    function loadSavedAnswers() {
        const saved = localStorage.getItem('exam_answers_' + examId);
        if (saved) answers = JSON.parse(saved);
    }

    window.addEventListener('popstate', () => {
        if (!localStorage.getItem('exam_submitted_' + examId)) {
            history.pushState(null, '', location.href);
            showToast('Please submit the exam before leaving this page.', 'warning');
        }
    });

    function buildDisplayExamCode(exam) {
        const course = String(exam?.course_code || 'EXAM').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 6);
        const idPart = String(exam?.id || Date.now()).padStart(4, '0');
        return `EX-${course}-${idPart}`;
    }

    function saveAnswers() {
        localStorage.setItem('exam_answers_' + examId, JSON.stringify(answers));
    }

    function loadSavedProgress() {
        const saved = localStorage.getItem('exam_progress_' + examId);
        if (saved) currentQuestionIndex = parseInt(saved) || 0;
    }

    function saveProgress() {
        localStorage.setItem('exam_progress_' + examId, currentQuestionIndex);
    }

    function loadFlaggedQuestions() {
        const saved = localStorage.getItem('flagged_questions_' + examId);
        if (saved) flaggedQuestions = new Set(JSON.parse(saved));
    }

    function saveFlaggedQuestions() {
        localStorage.setItem('flagged_questions_' + examId, JSON.stringify([...flaggedQuestions]));
    }

    // ============================================
    // RESIZE HANDLES
    // ============================================
    let isResizing = false,
        startX = 0,
        startLeftWidth = 0;
    let isResizingCoding = false,
        startCodingX = 0,
        startPreviewWidth = 0;

    function initResizeHandles() {
        const leftPanel = document.getElementById('leftPanel');
        const handle = document.getElementById('resizeHandle');
        if (!handle || !leftPanel) return;

        handle.addEventListener('mousedown', (e) => {
            isResizing = true;
            startX = e.clientX;
            startLeftWidth = leftPanel.offsetWidth;
            document.body.style.cursor = 'col-resize';
            handle.classList.add('active');
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isResizing) return;
            const deltaX = e.clientX - startX;
            let newLeftWidth = startLeftWidth + deltaX;
            const maxWidth = window.innerWidth * 0.5;
            newLeftWidth = Math.max(280, Math.min(maxWidth, newLeftWidth));
            leftPanel.style.width = newLeftWidth + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isResizing) {
                isResizing = false;
                document.body.style.cursor = '';
                handle.classList.remove('active');
            }
        });
    }

    function initCodingResize() {
        const previewContainer = document.querySelector('.preview-container');
        const handle = document.querySelector('.coding-resize-handle');
        if (!handle || !previewContainer) return;

        handle.addEventListener('mousedown', (e) => {
            isResizingCoding = true;
            startCodingX = e.clientX;
            startPreviewWidth = previewContainer.offsetWidth;
            document.body.style.cursor = 'col-resize';
            handle.classList.add('active');
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isResizingCoding) return;
            const deltaX = startCodingX - e.clientX;
            let newPreviewWidth = startPreviewWidth + deltaX;
            const maxWidth = window.innerWidth * 0.6;
            newPreviewWidth = Math.max(280, Math.min(maxWidth, newPreviewWidth));
            previewContainer.style.width = newPreviewWidth + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isResizingCoding) {
                isResizingCoding = false;
                document.body.style.cursor = '';
                handle.classList.remove('active');
            }
        });
    }

    // ============================================
    // RENDER FUNCTIONS
    // ============================================
    function renderQuestionButtons() {
        const container = document.getElementById('questionButtons');
        if (!container) return;

        container.innerHTML = mainQuestions.map((q, idx) => {
            const answer = answers[q.id];
            const isAnswered = answer && answer.value && ((answer.value.code || '').trim() !== '' || (answer.value.files || []).some(f => (f.content || '').trim() !== ''));
            let additionalClass = isAnswered ? 'answered' : 'unanswered';
            if (idx === currentQuestionIndex) additionalClass += ' active';
            if (flaggedQuestions.has(q.id)) additionalClass += ' flagged';
            return `<button class="q-nav-btn ${additionalClass}" onclick="jumpToQuestion(${idx})">${idx + 1}</button>`;
        }).join('');
    }

    function renderCurrentQuestion() {
        if (mainQuestions.length === 0) {
            document.getElementById('questionText').innerHTML =
                '<p style="color:red;">No questions found for this exam.</p>';
            return;
        }

        const q = mainQuestions[currentQuestionIndex];
        if (!q) return;

        // Update header info
        document.getElementById('questionId').textContent = `Q${currentQuestionIndex + 1}`;
        document.getElementById('questionUniqueId').textContent = q.uniqueId || `UID-${q.id}`;
        document.getElementById('questionMarks').textContent = `${q.marks || 0} marks`;
        document.getElementById('questionType').textContent = 'CODING';
        document.getElementById('questionTypeBox').textContent = 'Coding Problem';
        document.getElementById('pageIndicator').textContent =
            `Question ${currentQuestionIndex + 1} of ${mainQuestions.length}`;

        // Update navigation buttons
        document.getElementById('prevBtn').disabled = currentQuestionIndex === 0;
        document.getElementById('nextBtn').disabled = currentQuestionIndex === mainQuestions.length - 1;

        // Update flag button
        const flagBtn = document.getElementById('flagBtn');
        if (flagBtn && flaggedQuestions.has(q.id)) {
            flagBtn.classList.add('active');
        } else if (flagBtn) {
            flagBtn.classList.remove('active');
        }

        // Build question text
        let questionHtml = '';
        const hasSubQuestions = q.hasSubQuestions && q.subQuestions && q.subQuestions.length > 0;

        if (hasSubQuestions) {
            questionHtml = `<p style="margin-bottom: 15px; text-align: justify;">${escapeHtml(q.text || '')}</p>`;
            document.getElementById('subquestionSection').style.display = 'block';
            document.getElementById('showMainBtn').style.display = showingMainQuestion ? 'none' : 'block';

            renderSubquestionButtons(q.subQuestions);

            if (showingMainQuestion) {
                questionHtml += `<div style="margin-top: 15px;"><strong>Sub-questions:</strong><br>`;
                q.subQuestions.forEach((sq, idx) => {
                    questionHtml += `
                <div class="subquestion-item">
                    <span class="subquestion-letter">${getSubQuestionLetter(idx)}</span>
                    <span class="subquestion-text">${escapeHtml(sq.text)}</span>
                    <br><small style="color:#888;">Marks: ${sq.marks || 0}</small>
                </div>
            `;
                });
                questionHtml += `</div>`;
            }

            if (!showingMainQuestion && currentSubQuestionIndex >= 0 && q.subQuestions[currentSubQuestionIndex]) {
                const sq = q.subQuestions[currentSubQuestionIndex];
                questionHtml += `
            <div class="subquestion-item">
                <span class="subquestion-letter">${getSubQuestionLetter(currentSubQuestionIndex)}</span>
                <span class="subquestion-text">${escapeHtml(sq.text)}</span>
                <br><small style="color:#888;">Marks: ${sq.marks || 0}</small>
            </div>
        `;
            }
        } else {
            document.getElementById('subquestionSection').style.display = 'none';
            showingMainQuestion = true;
            questionHtml = `<p style="text-align: justify;">${escapeHtml(q.text || '')}</p>`;
        }

        document.getElementById('questionText').innerHTML = questionHtml;

        // Determine current language - IMPORTANT for syntax highlighting
        let currentLang = q.language || 'python';
        if (!showingMainQuestion && currentSubQuestionIndex >= 0 && q.subQuestions && q.subQuestions[
                currentSubQuestionIndex]) {
            currentLang = q.subQuestions[currentSubQuestionIndex].language || q.language || 'python';
        }

        // After determining currentLang, add this:
        currentLanguage = currentLang.toLowerCase();

        // Update the compiler when language changes
        if (typeof updateCompilerForLanguage === 'function') {
            updateCompilerForLanguage(currentLanguage);
        }
        // Update currentLanguage
        currentLanguage = currentLang.toLowerCase();
        console.log("Setting language to:", currentLanguage);

        // Render coding interface
        renderCodingInterface(q, currentLang, !showingMainQuestion && currentSubQuestionIndex >= 0 ?
            q.subQuestions[currentSubQuestionIndex] : null);

        updateProgress();
    }

    function getQuestionTypeName(type) {
        return 'Coding Problem';
    }

    function getSubQuestionLetter(index) {
        const letters = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
        return `${letters[index]})`;
    }

    function renderSubquestionButtons(subQuestions) {
        const container = document.getElementById('subqButtons');
        if (!container) return;

        container.innerHTML = subQuestions.map((sq, idx) =>
            `<button class="subq-btn ${currentSubQuestionIndex === idx && !showingMainQuestion ? 'active' : ''}" onclick="selectSubQuestion(${idx})">${getSubQuestionLetter(idx)}</button>`
        ).join('');
    }

    function selectSubQuestion(index) {
        showingMainQuestion = false;
        currentSubQuestionIndex = index;
        openFiles = [];
        if (monacoEditor) {
            monacoEditor.dispose();
            monacoEditor = null;
        }
        renderCurrentQuestion();
    }

    function showMainQuestion() {
        showingMainQuestion = true;
        currentSubQuestionIndex = -1;
        openFiles = [];
        if (monacoEditor) {
            monacoEditor.dispose();
            monacoEditor = null;
        }
        renderCurrentQuestion();
    }

    // ============================================
    // CODING INTERFACE
    // ============================================
    function renderCodingInterface(q, language, subQuestion) {
        const container = document.getElementById('rightPanel');
        if (!container) {
            console.error("Right panel container not found");
            return;
        }

        currentLanguage = language || q.language || 'python';

        const savedAnswer = getSavedAnswer();
        let savedCode = '';
        let savedFiles = [];

        if (savedAnswer && savedAnswer.value) {
            if (typeof savedAnswer.value === 'object' && savedAnswer.value.code) {
                savedCode = savedAnswer.value.code;
                savedFiles = savedAnswer.value.files || [];
            } else if (typeof savedAnswer.value === 'string') {
                savedCode = savedAnswer.value;
            }
        }

        if (savedFiles.length === 0) {
            savedFiles = [];
            activeFileIndex = -1;
        } else {
            activeFileIndex = savedFiles.findIndex(f => f.active);
            if (activeFileIndex === -1) activeFileIndex = 0;
        }

        openFiles = savedFiles;
        const outputType = getOutputType(currentLanguage);

        // Build HTML
        container.innerHTML = `
        <div class="coding-split">
            <div class="editor-container">
                <div class="file-tabs" id="fileTabs"></div>
                <div id="monacoEditor" class="monaco-container"></div>
                <div id="emptyEditorState" class="empty-editor-state" style="display:none;">
                    <i class="fas fa-file-circle-plus" style="font-size:42px;color:#007acc;"></i>
                    <strong>No file created for this question yet</strong>
                    <span>Create your own project files and folders like VS Code. Suggested filename: ${escapeHtml(suggestFileNameFromQuestion())}</span>
                    <button class="add-file-btn" onclick="addNewFile()"><i class="fas fa-plus"></i> Create First File</button>
                </div>
            </div>
            <div class="coding-resize-handle" id="codingResizeHandle"></div>
            <div class="preview-container">
                <div class="preview-header">
                    <div class="output-tabs">
                        <button class="output-tab active" data-output-tab="console" onclick="switchOutputTab('console')"><i class="fas fa-terminal"></i> Console</button>
                        <button class="output-tab" data-output-tab="browser" onclick="switchOutputTab('browser')"><i class="fas fa-globe"></i> Browser</button>
                        <button class="output-tab" data-output-tab="ui" onclick="switchOutputTab('ui')"><i class="fas fa-window-restore"></i> UI</button>
                    </div>
                    <span id="testResultStatus" style="font-size: 11px;"></span>
                </div>
                <div id="consoleOutput" class="output-area">Click 'Execute' to run your code...</div>
                <iframe id="webPreview" class="web-preview-frame" title="Live Preview" sandbox="allow-same-origin allow-scripts allow-popups allow-forms"></iframe>
                <div id="uiOutput" class="ui-output-area" style="display:none;">
                    <i class="fas fa-window-maximize" style="font-size:34px;color:#888;"></i>
                    <span>Desktop GUI windows cannot open inside the cloud exam server. Use Browser for web UI output; Java Swing and Windows Forms are checked as source/compile tasks.</span>
                </div>
                <textarea id="programInput" class="stdin-panel" aria-hidden="true"></textarea>
            </div>
        </div>
    `;

        // Render file tabs
        renderFileTabs();
        const previewContainer = container.querySelector('.preview-container');
        if (previewContainer) {
            ['pointerdown', 'mousedown', 'touchstart', 'focusin', 'wheel'].forEach(eventName => {
                previewContainer.addEventListener(eventName, markOutputInteraction, true);
            });
            bindSafeOutputFrame(container.querySelector('#webPreview'));
        }

        // Initialize Monaco editor after DOM is ready
        if (typeof monaco !== 'undefined' || document.querySelector('#monacoEditor')) {
            setTimeout(() => {
                initMonacoEditor();
                initCodingResize();
                updateLivePreview();
                selectOutputTabForLanguage(currentLanguage);
            }, 200);
        } else {
            // Wait for Monaco to load
            const checkMonaco = setInterval(() => {
                if (typeof require !== 'undefined') {
                    clearInterval(checkMonaco);
                    setTimeout(() => {
                        initMonacoEditor();
                        initCodingResize();
                        updateLivePreview();
                        selectOutputTabForLanguage(currentLanguage);
                    }, 200);
                }
            }, 100);
        }
    }

    function getSavedAnswer() {
        const answerKey = getCurrentAnswerKey();
        return answers[answerKey];
    }

    function getFileExtension(language) {
        const extensions = {
            'c': 'c',
            'cpp': 'cpp',
            'csharp': 'cs',
            'java': 'java',
            'html': 'html',
            'css': 'css',
            'javascript': 'js',
            'js': 'js',
            'php': 'php',
            'python': 'py',
            'py': 'py',
            'vbnet': 'vb',
            'sql': 'sql'
        };
        return extensions[language.toLowerCase()] || 'txt';
    }

    function getOutputType(language) {
        const webLanguages = ['html', 'css', 'javascript', 'js', 'php'];
        if (webLanguages.includes(language)) return 'web';
        return 'console';
    }

    function preferredOutputTab(language) {
        const lang = String(language || '').toLowerCase();
        if (['html', 'css', 'javascript', 'js', 'php'].includes(lang)) return 'browser';
        return 'console';
    }

    function switchOutputTab(tab) {
        const consoleOutput = document.getElementById('consoleOutput');
        const webPreview = document.getElementById('webPreview');
        const uiOutput = document.getElementById('uiOutput');
        if (tab === 'browser' || tab === 'ui' || tab === 'console') markOutputInteraction();
        document.querySelectorAll('.output-tab').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.outputTab === tab);
        });
        if (consoleOutput) consoleOutput.style.display = tab === 'console' ? 'block' : 'none';
        if (webPreview) webPreview.style.display = tab === 'browser' ? 'block' : 'none';
        if (uiOutput) uiOutput.style.display = tab === 'ui' ? 'flex' : 'none';
    }

    function selectOutputTabForLanguage(language) {
        switchOutputTab(preferredOutputTab(language));
    }

    function renderFileTabs() {
        const container = document.getElementById('fileTabs');
        if (!container) return;

        if (openFiles.length === 0) {
            container.innerHTML = `
                <button class="add-file-btn" onclick="addNewFile()"><i class="fas fa-plus"></i> Create File</button>
                <button class="add-file-btn" onclick="addNewFolder()"><i class="fas fa-folder-plus"></i> Create Folder</button>
            `;
            const editorContainer = document.getElementById('monacoEditor');
            const emptyState = document.getElementById('emptyEditorState');
            if (editorContainer) editorContainer.style.display = 'none';
            if (emptyState) emptyState.style.display = 'flex';
            return;
        }

        const editorContainer = document.getElementById('monacoEditor');
        const emptyState = document.getElementById('emptyEditorState');
        if (editorContainer) editorContainer.style.display = 'block';
        if (emptyState) emptyState.style.display = 'none';

        container.innerHTML = openFiles.map((file, idx) => `
        <div class="file-tab ${file.active ? 'active' : ''}" onclick="switchFile(${idx})">
            <i class="fas fa-file-code"></i> ${escapeHtml(file.name)}
            <button onclick="event.stopPropagation(); renameFile(${idx})" style="background:none; border:none; color:inherit; cursor:pointer;">✏️</button>
            <button onclick="event.stopPropagation(); closeFile(${idx})" style="background:none; border:none; color:inherit; cursor:pointer;">✖</button>
        </div>
    `).join('') + `
        <button class="add-file-btn" onclick="addNewFile()"><i class="fas fa-plus"></i> New File</button>
        <button class="add-file-btn" onclick="addNewFolder()"><i class="fas fa-folder-plus"></i> New Folder</button>`;
    }

    function normalizeProjectPath(path) {
        return String(path || '')
            .replace(/\\/g, '/')
            .replace(/(^|\/)\.\.(?=\/|$)/g, '')
            .replace(/[^A-Za-z0-9_\-.\/]/g, '_')
            .replace(/^\/+|\/+$/g, '');
    }

    function suggestFileNameFromQuestion() {
        const q = mainQuestions[currentQuestionIndex] || {};
        const text = String(q.text || 'solution').toLowerCase();
        const words = (text.match(/[a-z0-9]+/g) || [])
            .filter(word => word.length > 2 && !['write', 'program', 'that', 'the', 'and', 'with', 'from', 'user', 'code'].includes(word))
            .slice(0, 3);
        const base = words.length ? words.join('_') : 'solution';
        return `${base}.${getFileExtension(currentLanguage)}`;
    }

    function addNewFile() {
        const fileName = normalizeProjectPath(prompt('Enter file path (e.g., Main.java, index.html, src/app.js):', suggestFileNameFromQuestion()));
        if (!fileName) return;
        if (openFiles.some(file => file.name.toLowerCase() === fileName.toLowerCase())) {
            showToast('A file with that name already exists', 'warning');
            return;
        }

        const extension = fileName.split('.').pop().toLowerCase();
        let language = currentLanguage;

        // Map extensions to languages for Monaco
        const langMap = {
            'c': 'c',
            'cpp': 'cpp',
            'cc': 'cpp',
            'cxx': 'cpp',
            'cs': 'csharp',
            'java': 'java',
            'html': 'html',
            'htm': 'html',
            'css': 'css',
            'js': 'javascript',
            'mjs': 'javascript',
            'ts': 'typescript',
            'php': 'php',
            'py': 'python',
            'pyw': 'python',
            'vb': 'vbnet',
            'json': 'json',
            'xml': 'xml',
            'sql': 'sql'
        };

        language = langMap[extension] || currentLanguage;

        openFiles.push({
            name: fileName,
            language: language,
            content: '',
            active: false
        });
        switchFile(openFiles.length - 1);
    }

    function addNewFolder() {
        const folderName = normalizeProjectPath(prompt('Enter folder path (e.g., src, components, assets):'));
        if (!folderName) return;
        const starterFile = `${folderName}/README.txt`;
        if (openFiles.some(file => file.name.toLowerCase() === starterFile.toLowerCase())) {
            showToast('Folder already exists', 'warning');
            return;
        }
        openFiles.push({
            name: starterFile,
            language: 'plaintext',
            content: '',
            active: false
        });
        switchFile(openFiles.length - 1);
        showToast('Folder created. Create files inside it using folder/file.ext paths.', 'success');
    }

    function switchFile(index) {
        if (index < 0 || !openFiles[index]) return;
        openFiles.forEach((f, idx) => f.active = idx === index);
        activeFileIndex = index;
        renderFileTabs();

        if (monacoEditor) {
            monacoEditor.setValue(openFiles[activeFileIndex].content);
            monacoEditor.updateOptions({
                language: openFiles[activeFileIndex].language
            });
        } else {
            initMonacoEditor();
        }
    }

    function renameFile(index) {
        const newName = normalizeProjectPath(prompt('Enter new file name:', openFiles[index].name));
        if (newName && newName.trim()) {
            if (openFiles.some((file, idx) => idx !== index && file.name.toLowerCase() === newName.toLowerCase())) {
                showToast('A file with that name already exists', 'warning');
                return;
            }
            openFiles[index].name = newName.trim();
            renderFileTabs();
            saveCurrentAnswerToStorage();
        }
    }

    function closeFile(index) {
        openFiles.splice(index, 1);
        if (openFiles.length === 0) {
            activeFileIndex = -1;
            if (monacoEditor) {
                monacoEditor.dispose();
                monacoEditor = null;
            }
            renderFileTabs();
            saveCurrentAnswerToStorage();
            return;
        }
        if (activeFileIndex >= openFiles.length) activeFileIndex = openFiles.length - 1;
        if (activeFileIndex >= 0) openFiles[activeFileIndex].active = true;
        renderFileTabs();

        if (monacoEditor && openFiles[activeFileIndex]) {
            monacoEditor.setValue(openFiles[activeFileIndex].content);
            const model = monacoEditor.getModel();
            if (model && typeof monaco !== 'undefined') {
                monaco.editor.setModelLanguage(model, mapLanguageToMonaco(openFiles[activeFileIndex].language));
            }
            updateLivePreview();
        }
    }

    function initMonacoEditor() {
        const container = document.getElementById('monacoEditor');
        if (!container) {
            console.error("Monaco container not found");
            setTimeout(() => initMonacoEditor(), 200);
            return;
        }

        if (!openFiles[activeFileIndex]) {
            container.style.display = 'none';
            const emptyState = document.getElementById('emptyEditorState');
            if (emptyState) emptyState.style.display = 'flex';
            return;
        }

        // Clear and style container
        container.innerHTML = '';
        container.style.display = 'block';
        container.style.width = '100%';
        container.style.height = '100%';
        container.style.minHeight = '450px';

        // Make sure Monaco loader is available
        if (typeof require === 'undefined') {
            console.error("Monaco loader not available, loading...");
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs/loader.js';
            script.onload = () => {
                setTimeout(() => initMonacoEditor(), 100);
            };
            document.head.appendChild(script);
            return;
        }

        const activeFile = openFiles[activeFileIndex] || {
            language: currentLanguage,
            content: ''
        };

        // Map language for Monaco (case sensitive!)
        const languageMap = {
            'c': 'c',
            'cpp': 'cpp',
            'csharp': 'csharp',
            'java': 'java',
            'javascript': 'javascript',
            'js': 'javascript',
            'html': 'html',
            'css': 'css',
            'php': 'php',
            'python': 'python',
            'py': 'python',
            'vbnet': 'vb',
            'typescript': 'typescript',
            'json': 'json'
        };

        const monacoLanguage = languageMap[activeFile.language.toLowerCase()] || 'plaintext';
        console.log("Setting language to:", monacoLanguage);

        require.config({
            paths: {
                vs: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs'
            }
        });

        require(['vs/editor/editor.main'], function() {
            if (monacoEditor) {
                monacoEditor.dispose();
                monacoEditor = null;
            }

            // Create editor with VS Code settings
            monacoEditor = monaco.editor.create(container, {
                value: activeFile.content,
                language: monacoLanguage,
                theme: 'vs-dark',
                automaticLayout: true,
                fontSize: 14,
                fontFamily: 'Consolas, "Courier New", monospace',
                fontWeight: 'normal',
                lineHeight: 22,
                letterSpacing: 0.5,
                lineNumbers: 'on',
                lineNumbersMinChars: 3,
                glyphMargin: true,
                folding: true,
                foldingStrategy: 'auto',
                showFoldingControls: 'always',
                matchBrackets: 'always',
                autoClosingBrackets: 'always',
                autoClosingQuotes: 'always',
                autoIndent: 'full',
                formatOnPaste: true,
                formatOnType: true,
                suggestOnTriggerCharacters: true,
                acceptSuggestionOnEnter: 'on',
                tabCompletion: 'on',
                wordBasedSuggestions: true,
                parameterHints: {
                    enabled: true
                },
                quickSuggestions: {
                    other: true,
                    comments: false,
                    strings: false
                },
                scrollbar: {
                    vertical: 'visible',
                    horizontal: 'visible',
                    verticalScrollbarSize: 10,
                    horizontalScrollbarSize: 10,
                    alwaysConsumeMouseWheel: false
                },
                minimap: {
                    enabled: true,
                    scale: 1,
                    showSlider: 'mouseover'
                },
                renderWhitespace: 'selection',
                renderLineHighlight: 'all',
                stickyScroll: {
                    enabled: true
                },
                smoothScrolling: true,
                cursorBlinking: 'smooth',
                cursorSmoothCaretAnimation: 'on',
                multiCursorModifier: 'alt',
                dragAndDrop: true,
                links: true,
                colorDecorators: true,
                lightbulb: {
                    enabled: true
                }
            });

            // Register custom theme with better colors
            monaco.editor.defineTheme('custom-dark', {
                base: 'vs-dark',
                inherit: true,
                rules: [{
                        token: 'comment',
                        foreground: '6a9955',
                        fontStyle: 'italic'
                    },
                    {
                        token: 'keyword',
                        foreground: '569cd6'
                    },
                    {
                        token: 'keyword.control',
                        foreground: 'c586c0'
                    },
                    {
                        token: 'keyword.operator',
                        foreground: 'd4d4d4'
                    },
                    {
                        token: 'string',
                        foreground: 'ce9178'
                    },
                    {
                        token: 'string.key',
                        foreground: '9cdcfe'
                    },
                    {
                        token: 'number',
                        foreground: 'b5cea8'
                    },
                    {
                        token: 'function',
                        foreground: 'dcdcaa'
                    },
                    {
                        token: 'function.declaration',
                        foreground: 'dcdcaa'
                    },
                    {
                        token: 'variable',
                        foreground: '9cdcfe'
                    },
                    {
                        token: 'variable.parameter',
                        foreground: '9cdcfe'
                    },
                    {
                        token: 'class',
                        foreground: '4ec9b0'
                    },
                    {
                        token: 'interface',
                        foreground: '4ec9b0'
                    },
                    {
                        token: 'type',
                        foreground: '4ec9b0'
                    },
                    {
                        token: 'type.parameter',
                        foreground: '4ec9b0'
                    },
                    {
                        token: 'constant',
                        foreground: '9cdcfe'
                    },
                    {
                        token: 'constant.language',
                        foreground: '569cd6'
                    },
                    {
                        token: 'support.function',
                        foreground: 'dcdcaa'
                    },
                    {
                        token: 'support.class',
                        foreground: '4ec9b0'
                    },
                    {
                        token: 'support.type',
                        foreground: '4ec9b0'
                    },
                    {
                        token: 'storage',
                        foreground: '569cd6'
                    },
                    {
                        token: 'storage.type',
                        foreground: '569cd6'
                    },
                    {
                        token: 'entity.name',
                        foreground: '9cdcfe'
                    },
                    {
                        token: 'entity.name.function',
                        foreground: 'dcdcaa'
                    },
                    {
                        token: 'entity.name.type',
                        foreground: '4ec9b0'
                    },
                    {
                        token: 'meta.definition',
                        foreground: '9cdcfe'
                    },
                    {
                        token: 'meta.function',
                        foreground: 'dcdcaa'
                    },
                    {
                        token: 'meta.class',
                        foreground: '4ec9b0'
                    },
                    {
                        token: 'punctuation',
                        foreground: 'd4d4d4'
                    },
                    {
                        token: 'punctuation.definition.string',
                        foreground: 'ce9178'
                    },
                    {
                        token: 'punctuation.definition.comment',
                        foreground: '6a9955'
                    }
                ],
                colors: {
                    'editor.background': '#1e1e1e',
                    'editor.foreground': '#d4d4d4',
                    'editor.lineHighlightBackground': '#2a2a2a',
                    'editorCursor.foreground': '#aeafad',
                    'editorWhitespace.foreground': '#3a3a3a',
                    'editorIndentGuide.background': '#3a3a3a',
                    'editorIndentGuide.activeBackground': '#5a5a5a',
                    'editor.selectionBackground': '#264f78',
                    'editor.inactiveSelectionBackground': '#3a3d41',
                    'editor.selectionHighlightBackground': '#add6ff26',
                    'editor.wordHighlightBackground': '#575757',
                    'editor.wordHighlightStrongBackground': '#004972',
                    'editor.findMatchBackground': '#515c6a',
                    'editor.findMatchHighlightBackground': '#ea5c0050',
                    'editorHoverWidget.background': '#252526',
                    'editorHoverWidget.border': '#454545',
                    'editorSuggestWidget.background': '#252526',
                    'editorSuggestWidget.border': '#454545',
                    'editorSuggestWidget.selectedBackground': '#04395e',
                    'editorSuggestWidget.highlightForeground': '#0097fb',
                    'editorBracketMatch.background': '#006400',
                    'editorBracketMatch.border': '#00ff00',
                    'editorLineNumber.foreground': '#858585',
                    'editorLineNumber.activeForeground': '#c6c6c6'
                }
            });

            monaco.editor.setTheme('custom-dark');

            // Force syntax highlighting refresh
            setTimeout(() => {
                if (monacoEditor) {
                    const model = monacoEditor.getModel();
                    if (model) monaco.editor.setModelLanguage(model, monacoLanguage);
                }
            }, 100);

            // Handle content changes
            monacoEditor.onDidChangeModelContent(() => {
                if (openFiles[activeFileIndex]) {
                    openFiles[activeFileIndex].content = monacoEditor.getValue();
                    saveCurrentAnswerToStorage();
                    updateLivePreview();
                }
            });

            // Add keyboard shortcuts
            monacoEditor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, function() {
                saveCurrentAnswerToStorage();
                showToast('Code saved', 'success');
            });

            console.log("Monaco editor initialized with language:", monacoLanguage);
        });
    }
    // Helper function to map languages to Monaco format
    function mapLanguageToMonaco(language) {
        const langMap = {
            'c': 'c',
            'cpp': 'cpp',
            'csharp': 'csharp',
            'java': 'java',
            'javascript': 'javascript',
            'js': 'javascript',
            'html': 'html',
            'css': 'css',
            'php': 'php',
            'python': 'python',
            'py': 'python',
            'vbnet': 'vb',
            'typescript': 'typescript',
            'json': 'json',
            'xml': 'xml',
            'sql': 'sql'
        };
        return langMap[language.toLowerCase()] || 'plaintext';
    }

    function saveCurrentAnswerToStorage() {
        const answerKey = getCurrentAnswerKey();
        answers[answerKey] = {
            value: {
                code: openFiles[activeFileIndex]?.content || '',
                files: openFiles,
                language: currentLanguage
            },
            type: 'coding',
            savedAt: new Date().toISOString()
        };
        saveAnswers();
        updateProgress();
        renderQuestionButtons();
    }
    // ============================================
    // COMPILER PANEL TOGGLE (Replaces Output Panel)
    // ============================================
    let compilerActive = false;
    let originalPreviewContainer = null;

    function toggleCompilerPanel() {
        const activeFile = openFiles[activeFileIndex];
        const code = activeFile.content;
        const language = currentLanguage;

        if (!code || code.trim() === '') {
            showToast('No code to test. Please write some code first.', 'warning');
            return;
        }

        if (!compilerActive) {
            // Store the current code for copying
            window.currentTestCode = code;
            window.currentTestLanguage = language;

            // Get the preview container (right side panel)
            const codingSplit = document.querySelector('.coding-split');
            const previewContainer = document.querySelector('.preview-container');

            if (!previewContainer) {
                showToast('Preview container not found', 'error');
                return;
            }

            // Store original preview container
            originalPreviewContainer = previewContainer.cloneNode(true);

            // Get compiler URL based on language
            const compilerUrl = getCompilerUrlForLanguage(language);

            // Replace preview container with compiler iframe
            previewContainer.innerHTML = '';
            previewContainer.className = 'compiler-panel';
            previewContainer.innerHTML = `
            <div class="compiler-header">
                <span><i class="fas fa-external-link-alt"></i> ${language.toUpperCase()} Online Compiler</span>
                <div>
                    <button onclick="copyCodeToCompiler()" id="copyCodeBtn"><i class="fas fa-copy"></i> Copy Code</button>
                    <button onclick="closeCompilerPanel()" style="background: #ef4444; margin-left: 8px;"><i class="fas fa-times"></i> Close</button>
                </div>
            </div>
            <iframe src="${compilerUrl}" class="compiler-frame" title="Online Compiler"></iframe>
            <div class="compiler-note">
                <i class="fas fa-info-circle"></i> Paste your copied code into the compiler and click Run to test
            </div>
        `;

            compilerActive = true;
            document.getElementById('testOnlineBtn').innerHTML =
                '<i class="fas fa-times-circle"></i> Close Compiler';
            showToast(`${language.toUpperCase()} compiler opened - copy your code to test`, 'info');

            // Auto copy code to clipboard
            setTimeout(() => {
                copyCodeToCompiler();
            }, 500);

        } else {
            closeCompilerPanel();
        }
    }

    function closeCompilerPanel() {
        const previewContainer = document.querySelector('.preview-container');
        if (!previewContainer) return;

        // Restore original preview container
        if (originalPreviewContainer) {
            previewContainer.parentNode.replaceChild(originalPreviewContainer, previewContainer);
            originalPreviewContainer = null;
        }

        compilerActive = false;
        document.getElementById('testOnlineBtn').innerHTML = '<i class="fas fa-external-link-alt"></i> Test Online';
        showToast('Back to code editor', 'success');
    }

    function copyCodeToCompiler() {
        if (window.currentTestCode) {
            navigator.clipboard.writeText(window.currentTestCode);
            showToast('Code copied to clipboard! Paste it in the compiler and click Run.', 'success');
        } else {
            const activeFile = openFiles[activeFileIndex];
            if (activeFile && activeFile.content) {
                navigator.clipboard.writeText(activeFile.content);
                showToast('Code copied to clipboard! Paste it in the compiler and click Run.', 'success');
            }
        }
    }

    function getCompilerUrlForLanguage(language) {
        const lang = language.toLowerCase();

        // Best free online compilers for each language
        const compilers = {
            'python': 'https://www.programiz.com/python-programming/online-compiler/',
            'java': 'https://www.programiz.com/java-programming/online-compiler/',
            'javascript': 'https://www.programiz.com/javascript/online-compiler/',
            'js': 'https://www.programiz.com/javascript/online-compiler/',
            'cpp': 'https://www.programiz.com/cpp-programming/online-compiler/',
            'c': 'https://www.programiz.com/c-programming/online-compiler/',
            'html': 'https://www.programiz.com/html/online-compiler/',
            'css': 'https://www.programiz.com/css/online-compiler/',
            'php': 'https://www.programiz.com/php/online-compiler/',
            'csharp': 'https://www.programiz.com/csharp/online-compiler/',
            'cs': 'https://www.programiz.com/csharp/online-compiler/',
            'ruby': 'https://www.programiz.com/ruby/online-compiler/',
            'go': 'https://www.programiz.com/golang/online-compiler/',
            'swift': 'https://www.programiz.com/swift/online-compiler/',
            'kotlin': 'https://www.programiz.com/kotlin/online-compiler/',
            'r': 'https://www.programiz.com/r/online-compiler/'
        };

        // Fallback to OneCompiler if language not found
        const fallbackCompilers = {
            'python': 'https://onecompiler.com/python',
            'java': 'https://onecompiler.com/java',
            'javascript': 'https://onecompiler.com/javascript',
            'cpp': 'https://onecompiler.com/cpp',
            'c': 'https://onecompiler.com/c',
            'php': 'https://onecompiler.com/php',
            'html': 'https://onecompiler.com/html',
            'css': 'https://onecompiler.com/css'
        };

        return compilers[lang] || fallbackCompilers[lang] || `https://onecompiler.com/${lang}`;
    }

    // Also add a function to copy code from the editor
    function copyCurrentCode() {
        const activeFile = openFiles[activeFileIndex];
        if (activeFile && activeFile.content) {
            navigator.clipboard.writeText(activeFile.content);
            showToast('Code copied to clipboard!', 'success');
        } else {
            showToast('No code to copy', 'warning');
        }
    }

    function getActiveCodeForCompiler() {
        if (monacoEditor && openFiles[activeFileIndex]) {
            openFiles[activeFileIndex].content = monacoEditor.getValue();
            saveCurrentAnswerToStorage();
        }

        const activeFile = openFiles[activeFileIndex];
        if (!activeFile) {
            return {
                code: '',
                language: (currentLanguage || 'python').toLowerCase()
            };
        }

        const language = (activeFile.language || currentLanguage || 'python').toLowerCase();
        const webLanguages = ['html', 'css', 'javascript', 'js'];
        const code = webLanguages.includes(language) ? buildLinkedWebProject() : (activeFile.content || '');

        return {
            code,
            language
        };
    }

    function getOnlineCompilerUrl(language, code) {
        const lang = (language || 'python').toLowerCase();
        const encodedCode = encodeURIComponent(code || '');
        const jdoodleUrls = {
            java: `https://www.jdoodle.com/online-java-compiler/?code=${encodedCode}`,
            python: `https://www.jdoodle.com/python3-programming-online/?code=${encodedCode}`,
            javascript: `https://www.jdoodle.com/javascript-online-editor/?code=${encodedCode}`,
            js: `https://www.jdoodle.com/javascript-online-editor/?code=${encodedCode}`,
            cpp: `https://www.jdoodle.com/online-cpp-compiler/?code=${encodedCode}`,
            c: `https://www.jdoodle.com/c-online-compiler/?code=${encodedCode}`,
            php: `https://www.jdoodle.com/php-online-editor/?code=${encodedCode}`,
            html: `https://www.jdoodle.com/html-css-js-online-editor/?code=${encodedCode}`,
            csharp: `https://www.jdoodle.com/online-csharp-compiler/?code=${encodedCode}`,
            cs: `https://www.jdoodle.com/online-csharp-compiler/?code=${encodedCode}`
        };
        const oneCompiler = {
            python: 'https://onecompiler.com/python',
            java: 'https://onecompiler.com/java',
            javascript: 'https://onecompiler.com/javascript',
            js: 'https://onecompiler.com/javascript',
            cpp: 'https://onecompiler.com/cpp',
            c: 'https://onecompiler.com/c',
            php: 'https://onecompiler.com/php',
            html: 'https://onecompiler.com/html',
            csharp: 'https://onecompiler.com/csharp',
            cs: 'https://onecompiler.com/csharp',
            vbnet: 'https://onecompiler.com/vb'
        };

        return code.length < 6000 && jdoodleUrls[lang] ? jdoodleUrls[lang] : (oneCompiler[lang] || `https://onecompiler.com/${lang}`);
    }

    function openOnlineCompilerForActiveFile() {
        showToast('The local compiler is active. Use Execute to run code in the console panel.', 'info');
    }

    // ============================================
    // TOGGLE BETWEEN MONACO AND ONLINE COMPILER
    // ============================================
    let compilerMode = 'monaco'; // 'monaco' or 'compiler'

    function toggleOnlineCompiler() {
        const rightPanel = document.getElementById('rightPanel');
        const monacoContainer = document.getElementById('monacoEditor');
        const compilerIframe = document.getElementById('onlineCompilerFrame');

        if (compilerMode === 'monaco') {
            // Switch to online compiler
            const activeFile = openFiles[activeFileIndex];
            const code = activeFile.content;
            const language = currentLanguage;

            if (!code || code.trim() === '') {
                showToast('No code to test', 'warning');
                return;
            }

            // Get compiler URL based on language
            const compilerUrl = getCompilerUrl(language, code);

            // Create iframe if it doesn't exist
            if (!compilerIframe) {
                const iframe = document.createElement('iframe');
                iframe.id = 'onlineCompilerFrame';
                iframe.style.width = '100%';
                iframe.style.height = '100%';
                iframe.style.border = 'none';
                iframe.style.backgroundColor = '#1e1e1e';
                rightPanel.appendChild(iframe);
            }

            // Hide Monaco, show iframe
            if (monacoContainer) monacoContainer.style.display = 'none';
            document.getElementById('onlineCompilerFrame').style.display = 'block';
            document.getElementById('onlineCompilerFrame').src = compilerUrl;

            compilerMode = 'compiler';
            document.getElementById('testOnlineBtn').innerHTML = '<i class="fas fa-arrow-left"></i> Back to Editor';
            showToast(`Opened ${language.toUpperCase()} compiler - paste your code to test`, 'info');

            // Store code for pasting instruction
            window.compilerCode = code;
            showToast('Copy your code and paste it in the compiler', 'info');

        } else {
            // Switch back to Monaco editor
            if (monacoContainer) monacoContainer.style.display = 'block';
            if (compilerIframe) compilerIframe.style.display = 'none';

            compilerMode = 'monaco';
            document.getElementById('testOnlineBtn').innerHTML =
                '<i class="fas fa-external-link-alt"></i> Test Online';
            showToast('Back to code editor', 'success');

            // Refresh Monaco editor
            if (monacoEditor) {
                monacoEditor.layout();
            }
        }
    }

    function getCompilerUrl(language, code) {
        const lang = language.toLowerCase();

        // Encode code for URL
        const encodedCode = encodeURIComponent(code);

        const compilers = {
            'python': `https://www.programiz.com/python-programming/online-compiler/`,
            'java': `https://www.programiz.com/java-programming/online-compiler/`,
            'javascript': `https://www.programiz.com/javascript/online-compiler/`,
            'js': `https://www.programiz.com/javascript/online-compiler/`,
            'cpp': `https://www.programiz.com/cpp-programming/online-compiler/`,
            'c': `https://www.programiz.com/c-programming/online-compiler/`,
            'html': `https://www.programiz.com/html/online-compiler/`,
            'php': `https://www.programiz.com/php/online-compiler/`
        };

        // Alternative compilers if Programiz doesn't work for a language
        const altCompilers = {
            'python': `https://onecompiler.com/python`,
            'java': `https://onecompiler.com/java`,
            'javascript': `https://onecompiler.com/javascript`,
            'cpp': `https://onecompiler.com/cpp`,
            'c': `https://onecompiler.com/c`
        };

        return compilers[lang] || altCompilers[lang] || `https://onecompiler.com/${lang}`;
    }

    // Modified version that opens in iframe within same page
    function openCompilerInPage() {
        const activeFile = openFiles[activeFileIndex];
        const code = activeFile.content;
        const language = currentLanguage;

        if (!code || code.trim() === '') {
            showToast('No code to test. Please write some code first.', 'warning');
            return;
        }

        // Create a modal with embedded compiler
        const modal = document.createElement('div');
        modal.id = 'compilerModal';
        modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--bg);
        z-index: 100000;
        display: flex;
        flex-direction: column;
    `;

        const lang = language.toLowerCase();
        let compilerUrl = '';

        // Choose best compiler for each language
        const compilerMap = {
            'python': 'https://www.programiz.com/python-programming/online-compiler/',
            'java': 'https://www.programiz.com/java-programming/online-compiler/',
            'javascript': 'https://www.programiz.com/javascript/online-compiler/',
            'js': 'https://www.programiz.com/javascript/online-compiler/',
            'cpp': 'https://www.programiz.com/cpp-programming/online-compiler/',
            'c': 'https://www.programiz.com/c-programming/online-compiler/',
            'html': 'https://www.programiz.com/html/online-compiler/',
            'css': 'https://www.programiz.com/css/online-compiler/',
            'php': 'https://www.programiz.com/php/online-compiler/',
            'csharp': 'https://www.programiz.com/csharp/online-compiler/',
            'cs': 'https://www.programiz.com/csharp/online-compiler/'
        };

        compilerUrl = compilerMap[lang] || `https://onecompiler.com/${lang}`;

        modal.innerHTML = `
        <div style="background: var(--panel); padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border);">
            <div>
                <strong style="color: var(--text);">${language.toUpperCase()} Online Compiler</strong>
                <span style="color: var(--muted); margin-left: 10px; font-size: 12px;">Paste your code below to test</span>
            </div>
            <div>
                <button id="copyCodeBtn" style="background: var(--blue); color: white; border: none; padding: 6px 12px; border-radius: 6px; margin-right: 10px; cursor: pointer;">
                    <i class="fas fa-copy"></i> Copy Code
                </button>
                <button id="closeCompilerBtn" style="background: var(--danger); color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer;">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
        <iframe src="${compilerUrl}" style="flex: 1; width: 100%; border: none;"></iframe>
    `;

        document.body.appendChild(modal);

        // Add event listeners
        document.getElementById('closeCompilerBtn').onclick = () => {
            modal.remove();
            // Refresh Monaco when closing
            if (monacoEditor) {
                setTimeout(() => monacoEditor.layout(), 100);
            }
        };

        document.getElementById('copyCodeBtn').onclick = () => {
            navigator.clipboard.writeText(code);
            showToast('Code copied! Paste it in the compiler area.', 'success');
        };

        // Prevent escape key from closing immediately
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('compilerModal')) {
                modal.remove();
            }
        });
    }

    // ============================================
    // LOCKED COMPILER - NO NEW TABS
    // ============================================
    let currentCompilerLanguage = 'python';
    let compilerFrame = null;

    // Compilers that DO NOT open new tabs (stay within iframe)
    const safeCompilerUrls = {
        'python': 'https://www.online-python.com/',
        'java': 'https://www.online-java.com/',
        'javascript': 'https://www.online-javascript.com/',
        'js': 'https://www.online-javascript.com/',
        'cpp': 'https://www.online-cpp.com/',
        'c': 'https://www.online-c.com/',
        'php': 'https://www.online-php.com/',
        'html': 'https://www.online-html.com/',
        'css': 'https://www.online-css.com/',
        'csharp': 'https://www.online-csharp.com/',
        'cs': 'https://www.online-csharp.com/'
    };

    // Alternative: Use Replit embedded (also safe)
    const replitUrls = {
        'python': 'https://replit.com/languages/python3',
        'java': 'https://replit.com/languages/java',
        'javascript': 'https://replit.com/languages/javascript',
        'cpp': 'https://replit.com/languages/cpp',
        'c': 'https://replit.com/languages/c',
        'php': 'https://replit.com/languages/php',
        'html': 'https://replit.com/languages/html'
    };

    function initCompiler() {
        compilerFrame = document.getElementById('compilerFrame');

        // Prevent iframe from opening new tabs
        if (compilerFrame) {
            // Sandbox to prevent navigation and new windows
            compilerFrame.setAttribute('sandbox',
                'allow-same-origin allow-scripts allow-popups allow-forms allow-modals');
            // Also set security policy
            compilerFrame.setAttribute('csp',
                "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';"
            );
        }

        const initialLanguage = (currentLanguage || 'python').toLowerCase();
        updateCompilerLanguage(initialLanguage);
    }

    function updateCompilerLanguage(language) {
        const langKey = language.toLowerCase();
        currentCompilerLanguage = langKey;

        // Get current code from editor
        const activeFile = openFiles[activeFileIndex];
        const currentCode = activeFile ? activeFile.content : '';
        const encodedCode = encodeURIComponent(currentCode);

        // Compilers that accept code via URL parameters
        const compilerUrls = {
            'java': `https://www.jdoodle.com/online-java-compiler/?code=${encodedCode}`,
            'python': `https://www.jdoodle.com/python3-programming-online/?code=${encodedCode}`,
            'javascript': `https://www.jdoodle.com/javascript-online-editor/?code=${encodedCode}`,
            'js': `https://www.jdoodle.com/javascript-online-editor/?code=${encodedCode}`,
            'cpp': `https://www.jdoodle.com/online-cpp-compiler/?code=${encodedCode}`,
            'c': `https://www.jdoodle.com/c-online-compiler/?code=${encodedCode}`,
            'php': `https://www.jdoodle.com/php-online-editor/?code=${encodedCode}`,
            'html': `https://www.jdoodle.com/html-css-js-online-editor/?code=${encodedCode}`,
            'csharp': `https://www.jdoodle.com/online-csharp-compiler/?code=${encodedCode}`,
            'cs': `https://www.jdoodle.com/online-csharp-compiler/?code=${encodedCode}`
        };

        // Fallback to online-python.com (supports POST but not URL params)
        const fallbackUrls = {
            'python': 'https://www.online-python.com/',
            'java': 'https://www.online-java.com/',
            'javascript': 'https://www.online-javascript.com/',
            'js': 'https://www.online-javascript.com/',
            'cpp': 'https://www.online-cpp.com/',
            'c': 'https://www.online-c.com/',
            'php': 'https://www.online-php.com/',
            'html': 'https://www.online-html.com/'
        };

        let compilerUrl = compilerUrls[langKey] || fallbackUrls[langKey] || `https://onecompiler.com/${langKey}`;

        if (compilerFrame) {
            const compilerTitle = document.getElementById('compilerTitle');
            compilerTitle.innerHTML =
                `<i class="fas fa-spinner fa-pulse"></i> Loading ${langKey.toUpperCase()} compiler...`;
            compilerFrame.src = compilerUrl;
            compilerFrame.onload = function() {
                compilerTitle.innerHTML =
                    `<i class="fas fa-code"></i> ${langKey.toUpperCase()} Compiler - Click Run to execute`;
            };
        }
    }

    function refreshCompiler() {
        if (compilerFrame) {
            const currentSrc = compilerFrame.src;
            compilerFrame.src = 'about:blank';
            setTimeout(() => {
                compilerFrame.src = currentSrc;
            }, 100);
            showToast('Compiler refreshed', 'success');
        }
    }

    // Monitor for any new tab attempts
    document.addEventListener('click', function(e) {
        // Check if any link inside iframe tries to open new tab
        if (compilerFrame) {
            try {
                const iframeDoc = compilerFrame.contentDocument;
                if (iframeDoc) {
                    const links = iframeDoc.querySelectorAll('a[target="_blank"]');
                    links.forEach(link => {
                        link.setAttribute('target', '_self');
                    });
                }
            } catch (e) {}
        }
    });

    const nativeWindowOpen = window.open.bind(window);

    function isAllowedExamWindow(url) {
        return !url || url.includes('about:blank');
    }

    // Block new windows during the exam.
    window.open = function(url, name, specs) {
        if (!isAllowedExamWindow(url)) {
            showToast('Opening new windows is not allowed during exam', 'error');
            return null;
        }
        return nativeWindowOpen(url, name, specs);
    };

    function pasteAndRun() {
        copyCodeToCompiler();
        showToast('Code copied! Now paste it into the compiler editor and click Run.', 'success');
    }

    function codeLikelyNeedsInput(code, language) {
        const lang = String(language || '').toLowerCase();
        const consoleLang = ['c', 'cpp', 'java', 'python', 'php', 'csharp', 'cs', 'vbnet', 'vb'];
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
            while ((match = pattern.exec(before)) !== null) {
                prompt = match[1];
            }
        }

        if (!prompt && String(language).toLowerCase().includes('python')) {
            const inputMatch = code.slice(index, index + 160).match(/input\s*\(\s*["']((?:\\.|[^"'\\])*)["']/);
            if (inputMatch) prompt = inputMatch[1];
        }

        if (!prompt && String(language).toLowerCase().includes('php')) {
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
                groups.push({
                    count: countScanfSpecifiers(match[1]),
                    prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}`)
                });
            }
        } else if (lang === 'cpp') {
            const regex = /cin\s*((?:>>\s*[^;]+)+);/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({
                    count: (match[1].match(/>>/g) || []).length,
                    prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}`)
                });
            }
        } else if (lang === 'java') {
            const regex = /\.\s*next(?:Int|Double|Float|Long|Short|Byte|Boolean|Line)?\s*\(/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({
                    count: 1,
                    prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}`)
                });
            }
        } else if (lang === 'python' || lang === 'py') {
            const regex = /input\s*\(/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({
                    count: 1,
                    prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}`)
                });
            }
        } else if (lang === 'php') {
            const regex = /readline\s*\(/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({
                    count: 1,
                    prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}`)
                });
            }
        } else if (['csharp', 'cs', 'vbnet', 'vb'].includes(lang)) {
            const regex = /(Console\.ReadLine|ReadLine)\s*\(/g;
            let match;
            while ((match = regex.exec(code)) !== null) {
                groups.push({
                    count: 1,
                    prompt: lastPromptBefore(code, match.index, lang, `Input ${groups.length + 1}`)
                });
            }
        }

        const total = groups.reduce((sum, group) => sum + group.count, 0);
        const combined = groups.length === 1 && groups[0].count > 1;
        return {
            needsInput: total > 0,
            combined,
            groups,
            total
        };
    }

    function formatConsoleOutput(output) {
        return String(output || '')
            .replace(/\r\n/g, '\n')
            .replace(/:\s+(?=(Enter|After|Before|First|Second|Result|Output|Sum|Difference|Product|Quotient)\b)/g, ':\n')
            .trimEnd();
    }

    function inputPromptsFromPlan(plan) {
        const prompts = [];
        (plan.groups || []).forEach((group, groupIndex) => {
            const basePrompt = cleanInputPrompt(group.prompt, `Input ${groupIndex + 1}`);
            for (let i = 0; i < Math.max(1, group.count || 1); i++) {
                prompts.push(group.count > 1 ? `${basePrompt} ${i + 1}` : basePrompt);
            }
        });
        return prompts;
    }

    function renderTerminalPrompt(transcript, prompt) {
        const consoleOutput = document.getElementById('consoleOutput');
        if (!consoleOutput) return null;
        consoleOutput.innerHTML = `
            <div class="terminal-session">
                <pre class="terminal-transcript" id="terminalTranscript">${escapeHtml(transcript)}</pre>
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
        if (!plan.needsInput) return Promise.resolve('');

        switchOutputTab('console');
        return new Promise(resolve => {
            const prompts = inputPromptsFromPlan(plan);
            const values = [];
            let transcript = 'run:\n';
            let index = 0;

            const askNext = () => {
                const prompt = prompts[index] || `Input ${index + 1}:`;
                const input = renderTerminalPrompt(transcript, prompt);
                if (!input) {
                    resolve('');
                    return;
                }
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
                        const consoleOutput = document.getElementById('consoleOutput');
                        if (consoleOutput) {
                            consoleOutput.innerHTML = `
                                <div class="terminal-session">
                                    <pre class="terminal-transcript">${escapeHtml(transcript + 'Running...\n')}</pre>
                                </div>
                            `;
                        }
                        resolve(programInput);
                    } else {
                        askNext();
                    }
                });
            };

            askNext();
        });
    }

    function formatConsoleOutputWithInputEcho(output, input, code, language, success = true) {
        let formatted = formatConsoleOutput(output || '');
        const values = String(input || '').replace(/\r\n/g, '\n').split('\n').filter(value => value.length > 0);
        const prompts = inputPromptsFromPlan(buildInputPlan(code, language));

        prompts.forEach((prompt, index) => {
            if (!values[index]) return;
            const escapedPrompt = prompt.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const pattern = new RegExp(`${escapedPrompt}\\s*`, 'i');
            if (pattern.test(formatted)) {
                formatted = formatted.replace(pattern, `${prompt} ${values[index]}\n`);
            }
        });

        if (values.length && !prompts.some(prompt => formatted.toLowerCase().includes(prompt.toLowerCase()))) {
            const transcript = prompts.map((prompt, index) => `${prompt} ${values[index] || ''}`).join('\n');
            formatted = `${transcript}\n${formatted}`.trimEnd();
        }

        if (success && formatted && !/Code Execution Successful/i.test(formatted)) {
            formatted += '\n\n=== Code Execution Successful ===';
        }

        return formatted || (success ? 'Program finished successfully with no output.\n\n=== Code Execution Successful ===' : '');
    }

    async function preflightCodeSyntax(code, language) {
        const formData = new FormData();
        formData.append('code', code);
        formData.append('language', language);
        formData.append('check_only', '1');
        formData.append('files', JSON.stringify(openFiles.map(file => ({
            name: file.name,
            language: file.language,
            content: file.content || '',
            active: file === openFiles[activeFileIndex]
        }))));

        const response = await fetch(CODE_EXECUTOR_URL, {
            method: 'POST',
            body: formData
        });
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (error) {
            return {
                success: false,
                error: `Compiler endpoint returned an invalid response:\n${text.slice(0, 1000)}`
            };
        }
    }

    async function runCode() {
        if (!monacoEditor) {
            showToast('Create a file before running code', 'warning');
            return;
        }

        const activeFile = openFiles[activeFileIndex];
        if (!activeFile) {
            showToast('Create a file before running code', 'warning');
            switchOutputTab('console');
            const consoleOutput = document.getElementById('consoleOutput');
            if (consoleOutput) consoleOutput.textContent = 'No file created. Use Create File first.';
            return;
        }

        activeFile.content = monacoEditor.getValue();
        saveCurrentAnswerToStorage();

        const code = activeFile.content;
        const language = activeFile.language;
        const langKey = String(language || '').toLowerCase();
        const consoleOutput = document.getElementById('consoleOutput');
        const webPreview = document.getElementById('webPreview');
        let programInput = '';

        if (!code || code.trim() === '') {
            showToast('No code to execute', 'warning');
            if (consoleOutput) consoleOutput.textContent = 'No code to execute.';
            return;
        }

        if (['html', 'css', 'javascript', 'js'].includes(langKey)) {
            if (webPreview) {
                webPreview.srcdoc = buildLinkedWebProject();
                bindSafeOutputFrame(webPreview);
                markOutputInteraction();
                switchOutputTab('browser');
                showToast('Preview ready', 'success');
            }
            return;
        }

        switchOutputTab('console');
        if (consoleOutput) consoleOutput.textContent = 'Checking code...\n';

        const needsInput = codeLikelyNeedsInput(code, language);
        if (needsInput) {
            const preflight = await preflightCodeSyntax(code, language);
            if (!preflight.success) {
                const preflightError = preflight.error || preflight.output || 'Syntax or compile error detected.';
                if (consoleOutput) consoleOutput.textContent = `Error:\n${preflightError}`;
                showToast('Fix the code error before entering input', 'error');
                return;
            }
            programInput = await collectProgramInputInConsole(code, language);
        }

        showToast('Executing code...', 'info');
        if (consoleOutput) consoleOutput.textContent = 'Running...\n';

        try {
            const formData = new FormData();
            formData.append('code', code);
            formData.append('language', language);
            formData.append('input', programInput);
            formData.append('files', JSON.stringify(openFiles.map(file => ({
                name: file.name,
                language: file.language,
                content: file.content || '',
                active: file === openFiles[activeFileIndex]
            }))));

            const response = await fetch(CODE_EXECUTOR_URL, {
                method: 'POST',
                body: formData
            });
            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch (parseError) {
                result = {
                    success: false,
                    error: `Compiler endpoint returned an invalid response:\n${text.slice(0, 1000)}`
                };
            }

            if (result.error) {
                switchOutputTab('console');
                if (consoleOutput) consoleOutput.textContent = `Error:\n${result.error}`;
                showToast('Execution failed', 'error');
                return;
            }

            const rawOutput = formatConsoleOutput(result.output || 'Program finished successfully with no output.');
            if (shouldRenderAsHtml(rawOutput, language) && webPreview) {
                webPreview.srcdoc = rawOutput;
                bindSafeOutputFrame(webPreview);
                markOutputInteraction();
                switchOutputTab('browser');
            } else {
                const output = formatConsoleOutputWithInputEcho(rawOutput, programInput, code, language, true);
                switchOutputTab(preferredOutputTab(language) === 'ui' ? 'ui' : 'console');
                const uiOutput = document.getElementById('uiOutput');
                if (preferredOutputTab(language) === 'ui' && uiOutput) {
                    uiOutput.innerHTML = `<pre style="text-align:left;width:100%;white-space:pre-wrap;">${escapeHtml(output)}</pre>`;
                }
                if (consoleOutput) consoleOutput.textContent = output;
            }
            showToast('Code executed', 'success');
        } catch (error) {
            switchOutputTab('console');
            if (consoleOutput) consoleOutput.textContent = `Connection error: ${error.message}`;
            showToast('Connection error', 'error');
        }
    }
    async function runTestCases() {
        const q = mainQuestions[currentQuestionIndex];
        const visibleTestCases = (q.testCases || []).filter(tc => !tc.hidden);
        if (visibleTestCases.length === 0) return;

        const activeFile = openFiles[activeFileIndex];
        if (!activeFile) {
            showToast('Create a file before running test cases', 'warning');
            return;
        }
        const code = activeFile.content;
        const language = activeFile.language;
        const consoleOutput = document.getElementById('consoleOutput');

        consoleOutput.textContent += '\n\n🧪 Running Test Cases...\n' + '='.repeat(50) + '\n';

        let passedCount = 0;
        let totalMarks = 0;
        let earnedMarks = 0;

        for (let i = 0; i < visibleTestCases.length; i++) {
            const tc = visibleTestCases[i];
            consoleOutput.textContent += `\nTest Case ${i + 1}: Input: ${tc.input || '(none)'}\n`;
            consoleOutput.textContent += `Expected: ${tc.expected}\n`;

            try {
                // Execute code with input
                let result;

                if (tc.input) {
                    // For languages that support stdin
                    result = await executeCodeWithInput(code, language, tc.input);
                } else {
                    const formData = new FormData();
                    formData.append('action', 'execute_code');
                    formData.append('code', code);
                    formData.append('language', language);
                    const response = await fetch(CODE_EXECUTOR_URL, {
                        method: 'POST',
                        body: formData
                    });
                    result = await response.json();
                }

                const output = (result.output || '').trim();
                const expected = (tc.expected || '').trim();

                consoleOutput.textContent += `Got: ${output}\n`;

                if (output === expected) {
                    consoleOutput.textContent += `✅ PASSED\n`;
                    passedCount++;
                    earnedMarks += (tc.marks || 0);
                } else {
                    consoleOutput.textContent += `❌ FAILED - Expected: ${expected}\n`;
                }
                totalMarks += (tc.marks || 0);
            } catch (error) {
                consoleOutput.textContent += `❌ ERROR: ${error.message}\n`;
                totalMarks += (tc.marks || 0);
            }
        }

        consoleOutput.textContent += '\n' + '='.repeat(50) + '\n';
        consoleOutput.textContent += `Test Results: ${passedCount}/${visibleTestCases.length} passed\n`;
        consoleOutput.textContent += `Score: ${earnedMarks}/${totalMarks} marks\n`;

        const resultStatus = document.getElementById('testResultStatus');
        if (resultStatus) {
            if (passedCount === visibleTestCases.length) {
                resultStatus.innerHTML =
                    '<i class="fas fa-check-circle" style="color:#4ec9b0;"></i> All tests passed!';
                resultStatus.style.color = '#4ec9b0';
            } else {
                resultStatus.innerHTML =
                    `<i class="fas fa-times-circle" style="color:#f48771;"></i> ${passedCount}/${visibleTestCases.length} tests passed`;
                resultStatus.style.color = '#f48771';
            }
        }
    }

    async function executeCodeWithInput(code, language, input) {
        const formData = new FormData();
        formData.append('action', 'execute_code');
        formData.append('code', code);
        formData.append('language', language);
        formData.append('input', input);
        formData.append('files', JSON.stringify(openFiles.map(file => ({
            name: file.name,
            language: file.language,
            content: file.content || '',
            active: file === openFiles[activeFileIndex]
        }))));

        const response = await fetch(CODE_EXECUTOR_URL, {
            method: 'POST',
            body: formData
        });

        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (parseError) {
            return {
                success: false,
                output: '',
                error: `Compiler returned an invalid response:\n${text.slice(0, 1000)}`
            };
        }
    }

    function createWebPreview(code, language) {
        let htmlContent = '';
        if (language === 'html') {
            htmlContent = code;
        } else if (language === 'css') {
            htmlContent =
                `<!DOCTYPE html><html><head><style>${code}</style></head><body><div style="padding:20px"><h1>CSS Preview</h1><p>Your CSS styles are applied above.</p><button>Test Button</button></div></body></html>`;
        } else if (language === 'javascript' || language === 'js') {
            htmlContent =
                `<!DOCTYPE html><html><head><title>JavaScript Output</title></head><body><div id="output" style="padding:20px;font-family:monospace;white-space:pre-wrap;"></div><script>${code}\nif(typeof console !== 'undefined' && console.log) {\n const originalLog = console.log;\n const outputDiv = document.getElementById('output');\n console.log = function(...args) {\n outputDiv.innerHTML += args.join(' ') + '\\n';\n originalLog.apply(console, args);\n };\n}\n<\/script></body></html>`;
        } else {
            htmlContent =
                `<!DOCTYPE html><html><head><title>Preview</title></head><body><pre style="padding:20px">${escapeHtml(code)}</pre></body></html>`;
        }
        return htmlContent;
    }

    function buildLinkedWebProject() {
        const byName = Object.fromEntries(openFiles.map(file => [file.name.toLowerCase(), file.content || '']));
        let html = byName['index.html'] || openFiles.find(f => f.language === 'html')?.content || '';
        const css = Object.entries(byName).filter(([name]) => name.endsWith('.css')).map(([, content]) => content).join('\n');
        const js = Object.entries(byName).filter(([name]) => name.endsWith('.js')).map(([, content]) => content).join('\n');
        const php = Object.entries(byName).filter(([name]) => name.endsWith('.php')).map(([name, content]) => `<!-- PHP file: ${name}\n${content.replaceAll('-->', '--&gt;')}\n-->`).join('\n');
        if (!html) {
            html = '<!DOCTYPE html><html><head><title>Live Preview</title></head><body><div id="app"></div></body></html>';
        }
        if (css) html = html.includes('</head>') ? html.replace('</head>', `<style>${css}</style></head>`) : `<style>${css}</style>${html}`;
        if (js) html = html.includes('</body>') ? html.replace('</body>', `<script>${js}<\/script></body>`) : `${html}<script>${js}<\/script>`;
        if (php) html += php;
        return html;
    }

    function shouldRenderAsHtml(output, language) {
        const normalized = String(output || '').trim().toLowerCase();
        return ['html', 'php'].includes(String(language || '').toLowerCase()) ||
            /^<!doctype html/.test(normalized) ||
            /^<html[\s>]/.test(normalized) ||
            /<(table|form|canvas|svg|script|style|div|section|main|body)[\s>]/.test(normalized);
    }

    function updateLivePreview() {
        const webPreview = document.getElementById('webPreview');
        if (!webPreview) return;
        const activeFile = openFiles[activeFileIndex];
        if (!activeFile) return;
        const outputType = getOutputType(activeFile.language || currentLanguage);
        if (outputType !== 'web') return;
        webPreview.srcdoc = buildLinkedWebProject();
        bindSafeOutputFrame(webPreview);
        markOutputInteraction();
    }

    function resetCode() {
        if (monacoEditor) {
            monacoEditor.setValue('');
            openFiles[activeFileIndex].content = '';
            saveCurrentAnswerToStorage();
            showToast('Code cleared', 'info');
        }
    }

    function submitProgramInput() {
        // Handle program input if needed
        const inputField = document.getElementById('programInput');
        if (inputField && inputField.value) {
            showToast(`Input submitted: ${inputField.value}`, 'info');
            inputField.value = '';
        }
    }

    // ============================================
    // ANSWER SAVING
    // ============================================
    function formatStructuredInstructions(text) {
        const lines = String(text || '').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
        if (lines.length === 0) return '<p>No instructions were provided for this exam.</p>';
        const isBulletList = lines.some(line => /^[-*]\s+/.test(line));
        const tag = isBulletList ? 'ul' : 'ol';
        const items = lines.map(line => {
            const clean = line
                .replace(/^\d+[.)]\s+/, '')
                .replace(/^[a-zA-Z][.)]\s+/, '')
                .replace(/^(i|ii|iii|iv|v|vi|vii|viii|ix|x)[.)]\s+/i, '')
                .replace(/^[-*]\s+/, '');
            return `<li>${escapeHtml(clean)}</li>`;
        }).join('');
        return `<${tag} style="text-align:left;line-height:1.7;margin:0;padding-left:28px;">${items}</${tag}>`;
    }

    function showInstructionsModal() {
        const existing = document.getElementById('instructionsModal');
        if (existing) existing.remove();
        const modal = document.createElement('div');
        modal.id = 'instructionsModal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10060;display:flex;align-items:center;justify-content:center;padding:22px;';
        modal.innerHTML = `
            <div style="width:min(640px,96vw);max-height:86vh;overflow:auto;background:var(--panel-bg,#252526);color:var(--text,#fff);border:1px solid var(--border,#3e3e42);border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.38);padding:28px;">
                <h2 style="margin:0 0 18px;text-align:center;font-weight:900;letter-spacing:.06em;">INSTRUCTIONS</h2>
                <div>${formatStructuredInstructions(dbExam?.instructions || '')}</div>
                <div style="display:flex;justify-content:center;margin-top:24px;">
                    <button class="submit-exam-top" type="button" onclick="document.getElementById('instructionsModal')?.remove()">Close</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
    }

    function saveCurrentAnswer() {
        saveCurrentAnswerToStorage();
        updateProgress();
        renderQuestionButtons();
        showToast('All answers saved', 'success');
    }

    function updateProgress() {
        const total = mainQuestions.length;

        const answered = mainQuestions.filter(q => {
            const answer = answers[q.id];
            return answer && answer.value && ((answer.value.code || '').trim() !== '' || (answer.value.files || []).some(f => (f.content || '').trim() !== ''));
        }).length;
        const percentage = total > 0 ? (answered / total) * 100 : 0;
        document.getElementById('progressFill').style.width = `${percentage}%`;
        document.getElementById('progressText').textContent = `${answered} of ${total} answered`;
    }

    function jumpToQuestion(index) {
        if (index < 0 || index >= mainQuestions.length) return;
        currentQuestionIndex = index;
        showingMainQuestion = true;
        currentSubQuestionIndex = -1;
        openFiles = [];
        if (monacoEditor) {
            monacoEditor.dispose();
            monacoEditor = null;
        }
        saveProgress();
        renderCurrentQuestion();
    }

    function prevQuestion() {
        if (currentQuestionIndex > 0) jumpToQuestion(currentQuestionIndex - 1);
    }

    function nextQuestion() {
        if (currentQuestionIndex < mainQuestions.length - 1) jumpToQuestion(currentQuestionIndex + 1);
    }

    function flagCurrentQuestion() {
        const q = mainQuestions[currentQuestionIndex];
        if (flaggedQuestions.has(q.id)) {
            flaggedQuestions.delete(q.id);
            showToast('Flag removed', 'info');
        } else {
            flaggedQuestions.add(q.id);
            showToast('Question flagged for review', 'info');
        }
        saveFlaggedQuestions();
        renderQuestionButtons();
        document.getElementById('flagBtn')?.classList.toggle('active', flaggedQuestions.has(q.id));
    }

    function saveProgressManual() {
        saveProgress();
        saveAnswers();
        showToast('Progress saved', 'success');
    }

    function showInteractiveFinishDialog() {
        // Create interactive modal
        const modal = document.createElement('div');
        modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.95);
        z-index: 10000000;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: monospace;
    `;

        // Calculate progress for display
        const totalQuestions = mainQuestions.length;
        const answeredCount = Object.keys(answers).length;
        const percentageComplete = (answeredCount / totalQuestions) * 100;

        modal.innerHTML = `
        <div style="background: #2d2d30; border-radius: 20px; padding: 40px; max-width: 600px; width: 90%; border: 2px solid #007acc;">
            <div style="text-align: center; margin-bottom: 30px;">
                <i class="fas fa-clipboard-list" style="font-size: 60px; color: #007acc;"></i>
                <h2 style="color: #fff; margin-top: 20px;">Submit Your Exam</h2>
            </div>
            
            <div style="margin-bottom: 30px;">
                <div style="background: #252526; padding: 20px; border-radius: 12px; margin-bottom: 15px;">
                    <h3 style="color: #4ec9b0; margin-bottom: 15px;">Exam Summary</h3>
                    <p style="color: #ccc; margin: 10px 0;"><strong>Total Questions:</strong> ${totalQuestions}</p>
                    <p style="color: #ccc; margin: 10px 0;"><strong>Answered:</strong> ${answeredCount}</p>
                    <p style="color: #ccc; margin: 10px 0;"><strong>Progress:</strong> ${percentageComplete.toFixed(1)}%</p>
                    <div style="background: #3e3e42; height: 8px; border-radius: 4px; margin-top: 10px;">
                        <div style="background: #4ec9b0; width: ${percentageComplete}%; height: 100%; border-radius: 4px;"></div>
                    </div>
                </div>
                
                <div style="background: #252526; padding: 20px; border-radius: 12px;">
                    <h3 style="color: #f48771; margin-bottom: 15px;">⚠️ Important</h3>
                    <p style="color: #ccc; margin: 5px 0;">• You cannot modify answers after submission</p>
                    <p style="color: #ccc; margin: 5px 0;">• Your answers will be saved permanently</p>
                    <p style="color: #ccc; margin: 5px 0;">• Lecturers will be notified immediately</p>
                    <p style="color: #ccc; margin: 5px 0;">• A copy will be saved to your records</p>
                </div>
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: center;">
                <button id="finalSubmitBtn" style="background: #4ec9b0; color: #1e1e1e; border: none; padding: 14px 28px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 16px;">
                    <i class="fas fa-check-circle"></i> Final Submit
                </button>
                <button id="reviewAnswersBtn" style="background: #3b82f6; color: white; border: none; padding: 14px 28px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 16px;">
                    <i class="fas fa-search"></i> Review Answers
                </button>
                <button id="cancelFinishBtn" style="background: #3e3e42; color: #ccc; border: none; padding: 14px 28px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 16px;">
                    <i class="fas fa-arrow-left"></i> Continue Exam
                </button>
            </div>
        </div>
    `;

        document.body.appendChild(modal);

        // Handle final submit
        document.getElementById('finalSubmitBtn').onclick = async () => {
            modal.remove();
            showConfirmDialog('Submit exam?', 'Are you absolutely sure? This action cannot be undone.', () => submitExam(false, true));
        };

        // Review answers
        document.getElementById('reviewAnswersBtn').onclick = () => {
            modal.remove();
            showAnswerReview();
        };

        // Cancel and continue
        document.getElementById('cancelFinishBtn').onclick = () => {
            modal.remove();
            showToast('Continue with your exam', 'info');
        };
    }

    function showAnswerReview() {
        const reviewModal = document.createElement('div');
        reviewModal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.95);
        z-index: 10000000;
        overflow: auto;
        padding: 20px;
    `;

        let answersHtml =
            '<div style="max-width: 800px; margin: 0 auto;"><h2 style="color: #fff; text-align: center; margin-bottom: 30px;">Your Answers</h2>';

        mainQuestions.forEach((q, idx) => {
            const answer = answers[q.id];
            const hasAnswer = answer && answer.value && answer.value.code;
            answersHtml += `
            <div style="background: #2d2d30; padding: 20px; border-radius: 12px; margin-bottom: 15px; border-left: 4px solid ${hasAnswer ? '#4ec9b0' : '#f48771'}">
                <h3 style="color: #007acc; margin-bottom: 10px;">Question ${idx + 1}</h3>
                <p style="color: #ccc; margin-bottom: 10px;">${escapeHtml(q.text.substring(0, 200))}${q.text.length > 200 ? '...' : ''}</p>
                <p style="color: ${hasAnswer ? '#4ec9b0' : '#f48771'}">
                    <i class="fas ${hasAnswer ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                    ${hasAnswer ? 'Answered' : 'Not Answered'}
                </p>
            </div>
        `;
        });

        answersHtml += `
        <div style="text-align: center; margin-top: 30px;">
            <button onclick="this.closest('div').remove()" style="background: #007acc; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer;">Close Review</button>
        </div>
    </div>`;

        reviewModal.innerHTML = answersHtml;
        document.body.appendChild(reviewModal);
    }

    async function submitExam(isAutoSubmit = false, confirmed = false) {
        // Check if already submitted
        const alreadySubmitted = localStorage.getItem('exam_submitted_' + examId);
        if (alreadySubmitted === 'true') {
            showMessageBox("This exam has already been submitted.");
            return;
        }

        // Show confirmation
        if (!isAutoSubmit && !confirmed) {
            showConfirmDialog('Submit exam?', 'You cannot change answers after submission.', () => submitExam(false, true));
            return;
        }

        // Disable buttons
        document.querySelectorAll('button').forEach(btn => btn.disabled = true);
        showToast("Submitting your exam...", "info");

        // Prepare submission data
        const submissionData = {
            exam_id: examId,
            student_id: studentId,
            answers: buildSubmissionAnswers(),
            submitted_at: new Date().toISOString()
        };

        try {
            const formData = new FormData();
            formData.append('action', 'submit_exam');
            formData.append('exam_id', examId);
            formData.append('course_code', dbExam?.course_code || '');
            formData.append('answers', JSON.stringify(buildSubmissionAnswers()));
            if (isAutoSubmit) formData.append('auto_submit', '1');

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                // Mark as submitted
                localStorage.setItem('exam_submitted_' + examId, 'true');
                localStorage.removeItem('exam_start_time_' + examId);
                localStorage.removeItem('exam_timeLeft_' + examId);
                localStorage.removeItem('screen_locked_' + examId);
                localStorage.removeItem('screen_sharing_active');
                localStorage.removeItem('exam_answers_' + examId);
                localStorage.removeItem('exam_flags_' + examId);

                // Stop screen sharing
                if (screenStream) {
                    screenStream.getTracks().forEach(track => track.stop());
                }
                if (timerInterval) clearInterval(timerInterval);

                showMessageBox("Exam submitted successfully!");
                window.location.href = 'student_dashboard.php';
            } else {
                showMessageBox("Submission failed: " + (result.error || "Unknown error"));
                document.querySelectorAll('button').forEach(btn => btn.disabled = false);
            }

        } catch (error) {
            console.error("Submit error:", error);
            showMessageBox("Network error. Your answers are saved locally.");
            localStorage.setItem('exam_backup_' + examId, JSON.stringify(submissionData));
            document.querySelectorAll('button').forEach(btn => btn.disabled = false);
        }
    }

    function buildSubmissionAnswers() {
        saveCurrentAnswerToStorage();
        const submission = {};
        mainQuestions.forEach((q, index) => {
            const saved = answers[q.id];
            if (saved && saved.value) {
                submission[q.id] = {
                    ...saved,
                    question_number: index + 1,
                    score: 0
                };
                return;
            }
            submission[q.id] = {
                type: 'code',
                question_number: index + 1,
                value: {
                    code: 'unanswered',
                    files: [{
                        name: answerFileNameForQuestion(q, index),
                        language: 'text',
                        content: 'unanswered',
                        active: true
                    }]
                },
                score: 0,
                unanswered: true,
                savedAt: new Date().toISOString()
            };
        });
        return submission;
    }

    function answerFileNameForQuestion(question, index) {
        const text = String(question?.text || question?.question_text || `question_${index + 1}`).toLowerCase();
        const words = text
            .replace(/[^a-z0-9\s]/g, ' ')
            .split(/\s+/)
            .filter(word => word.length > 2 && !['the', 'and', 'for', 'with', 'that', 'this', 'write', 'program', 'accepts', 'prints', 'their'].includes(word))
            .slice(0, 4);
        const base = words.length ? words.join('_') : `question_${index + 1}`;
        return `${base}.${getFileExtension(question?.language || 'text')}`;
    }

    function showMessageBox(message) {
        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-container" style="text-align:center;">
                <h2 style="margin-bottom:12px;">Qoda PU</h2>
                <p style="color:var(--text); line-height:1.5; margin-bottom:20px;">${escapeHtml(message)}</p>
                <button class="bottom-btn" onclick="this.closest('.modal-overlay').remove()" style="background:#3b82f6;">OK</button>
            </div>
        `;
        document.body.appendChild(modal);
    }

    function showConfirmDialog(title, message, onConfirm) {
        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-container">
                <h2 style="margin-bottom:10px;">${escapeHtml(title)}</h2>
                <p style="color:var(--text); line-height:1.5; margin-bottom:22px;">${escapeHtml(message)}</p>
                <div style="display:flex; gap:12px; justify-content:flex-end;">
                    <button class="bottom-btn" style="background:#3e3e42;" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                    <button class="bottom-btn" style="background:#dc2626;" id="confirmDialogOk">Confirm</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        modal.querySelector('#confirmDialogOk').onclick = () => {
            modal.remove();
            onConfirm();
        };
    }
    // Add these helper functions
    async function showEnhancedConfirmationDialog() {
        return new Promise((resolve) => {
            const dialog = document.createElement('div');
            dialog.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.9);
            z-index: 1000000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: monospace;
        `;

            dialog.innerHTML = `
            <div style="background: #2d2d30; border-radius: 16px; padding: 40px; max-width: 500px; text-align: center; border: 1px solid #007acc;">
                <i class="fas fa-check-circle" style="font-size: 64px; color: #4ec9b0; margin-bottom: 20px;"></i>
                <h2 style="color: #fff; margin-bottom: 20px;">Submit Exam?</h2>
                <p style="color: #ccc; margin-bottom: 30px;">Are you sure you want to submit your exam? You won't be able to make changes after submission.</p>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button id="confirmSubmitBtn" style="background: #4ec9b0; color: #1e1e1e; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: bold;">Yes, Submit</button>
                    <button id="cancelSubmitBtn" style="background: #3e3e42; color: #ccc; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer;">Cancel</button>
                </div>
            </div>
        `;

            document.body.appendChild(dialog);

            document.getElementById('confirmSubmitBtn').onclick = () => {
                dialog.remove();
                resolve(true);
            };

            document.getElementById('cancelSubmitBtn').onclick = () => {
                dialog.remove();
                resolve(false);
            };
        });
    }

    async function notifyLecturers(submissionData) {
        try {
            const notifyData = new FormData();
            notifyData.append('action', 'notify_lecturers');
            notifyData.append('submission_data', JSON.stringify(submissionData));

            await fetch(window.location.origin + '/backend-php/notify_lecturers.php', {
                method: 'POST',
                body: notifyData
            });
        } catch (e) {
            console.log('Lecturer notification failed:', e);
        }
    }

    function queueSubmissionForLater(submissionData) {
        let pending = JSON.parse(localStorage.getItem('exam_submission_pending') || '[]');
        pending.push(submissionData);
        localStorage.setItem('exam_submission_pending', JSON.stringify(pending));
    }

    function showSubmissionSuccess(earned, possible, percentage) {
        const successDiv = document.createElement('div');
        successDiv.style.cssText = `
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: #2d2d30;
        border-radius: 16px;
        padding: 40px;
        text-align: center;
        z-index: 1000000;
        border: 2px solid #4ec9b0;
        box-shadow: 0 0 50px rgba(0,0,0,0.5);
    `;

        successDiv.innerHTML = `
        <i class="fas fa-check-circle" style="font-size: 80px; color: #4ec9b0; margin-bottom: 20px;"></i>
        <h2 style="color: #fff;">Exam Submitted Successfully!</h2>
        <p style="color: #ccc; margin: 20px 0;">Your score: ${earned}/${possible} (${percentage.toFixed(1)}%)</p>
        <p style="color: #888; font-size: 12px;">Redirecting to dashboard...</p>
    `;

        document.body.appendChild(successDiv);
        setTimeout(() => successDiv.remove(), 3000);
    }



    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    // Initialize everything
    init();

    // Helper function for escaping HTML
    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    </script>
</body>

</html>
