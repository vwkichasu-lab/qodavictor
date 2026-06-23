<?php

function qodaGradeInfoShared(float $score): array
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

function qodaTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        error_log('qoda table availability check failed for ' . $table . ': ' . $error->getMessage());
        return false;
    }
}

function qodaTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    $key = $table;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $columns = [];
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $columns[$column] = true;
        }
    } catch (Throwable $error) {
        error_log('qoda column availability check failed for ' . $table . ': ' . $error->getMessage());
    }
    $cache[$key] = $columns;
    return $columns;
}

function qodaTryEnsureFinalGradeTable(PDO $pdo): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        qodaEnsureFinalGradeTable($pdo);
        $available = true;
    } catch (Throwable $error) {
        $available = qodaTableExists($pdo, 'exam_final_grades');
        error_log('qoda final grade table unavailable; falling back to exam_submissions: ' . $error->getMessage());
    }

    return $available;
}

function qodaEnsureFinalGradeTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_final_grades (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            submission_id INT NOT NULL,
            exam_id INT NOT NULL,
            student_id INT NOT NULL,
            raw_question_score DECIMAL(10,2) DEFAULT 0,
            percentage DECIMAL(6,2) DEFAULT 0,
            class_score DECIMAL(5,2) DEFAULT 0,
            exam_score DECIMAL(5,2) DEFAULT 0,
            total_score DECIMAL(5,2) DEFAULT 0,
            grade VARCHAR(5) NULL,
            grade_point DECIMAL(3,1) DEFAULT 0,
            status VARCHAR(50) DEFAULT 'GRADED',
            score_source VARCHAR(30) DEFAULT 'manual',
            graded_by INT NULL,
            graded_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_exam_final_submission (submission_id),
            INDEX idx_exam_final_exam_student (exam_id, student_id),
            INDEX idx_exam_final_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = [
        'submission_id' => 'INT NOT NULL',
        'exam_id' => 'INT NOT NULL',
        'student_id' => 'INT NOT NULL',
        'raw_question_score' => 'DECIMAL(10,2) DEFAULT 0',
        'percentage' => 'DECIMAL(6,2) DEFAULT 0',
        'class_score' => 'DECIMAL(5,2) DEFAULT 0',
        'exam_score' => 'DECIMAL(5,2) DEFAULT 0',
        'total_score' => 'DECIMAL(5,2) DEFAULT 0',
        'grade' => 'VARCHAR(5) NULL',
        'grade_point' => 'DECIMAL(3,1) DEFAULT 0',
        'status' => "VARCHAR(50) DEFAULT 'GRADED'",
        'score_source' => "VARCHAR(30) DEFAULT 'manual'",
        'graded_by' => 'INT NULL',
        'graded_at' => 'DATETIME NULL',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    $check = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_final_grades' AND COLUMN_NAME = ?
    ");

    foreach ($columns as $column => $definition) {
        $check->execute([$column]);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE exam_final_grades ADD COLUMN `$column` $definition");
        }
    }
}

function qodaFallbackUpdateSubmissionGrade(PDO $pdo, int $submissionId, array $values): void
{
    $columns = qodaTableColumns($pdo, 'exam_submissions');
    $sets = [];
    $params = [];

    $simpleValues = [
        'percentage' => $values['percentage'],
        'class_score' => $values['class_score'],
        'exam_score' => $values['exam_score'],
        'total_score' => $values['total_score'],
        'grade' => $values['grade'],
        'grade_point' => $values['grade_point'],
        'status' => $values['status'],
    ];

    foreach ($simpleValues as $column => $value) {
        if (isset($columns[$column])) {
            $sets[] = "`$column` = ?";
            $params[] = $value;
        }
    }

    if (isset($columns['submitted'])) {
        $sets[] = "`submitted` = 1";
    }
    if (isset($columns['graded_at'])) {
        $sets[] = "`graded_at` = NOW()";
    }
    if (isset($columns['updated_at'])) {
        $sets[] = "`updated_at` = NOW()";
    }
    if (isset($columns['submitted_at'])) {
        $sets[] = "`submitted_at` = COALESCE(`submitted_at`, NOW())";
    }
    if (isset($columns['submittedAt'])) {
        $sets[] = "`submittedAt` = COALESCE(`submittedAt`, NOW())";
    }

    if (!$sets) {
        throw new RuntimeException('No compact grade columns are available on exam_submissions.');
    }

    $params[] = $submissionId;
    $stmt = $pdo->prepare('UPDATE exam_submissions SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);
}

function qodaRepairSubmissionStorage(PDO $pdo): void
{
    static $alreadyChecked = false;
    if ($alreadyChecked) {
        return;
    }
    $alreadyChecked = true;

    if (getenv('QODA_ALLOW_HEAVY_ALTERS') !== '1') {
        return;
    }

    $repairs = [
        "ALTER TABLE exam_submissions ENGINE=InnoDB",
        "ALTER TABLE exam_submissions ROW_FORMAT=DYNAMIC",
        "ALTER TABLE exam_submissions MODIFY answers LONGTEXT NULL",
        "ALTER TABLE exam_submissions MODIFY ai_feedback MEDIUMTEXT NULL",
        "ALTER TABLE exam_submissions MODIFY execution_results JSON NULL",
    ];

    foreach ($repairs as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $error) {
            error_log('qoda submission storage repair skipped: ' . $error->getMessage());
        }
    }
}

function qodaPersistFinalGrade(PDO $pdo, array $gradeData): array
{
    $submissionId = (int)($gradeData['submission_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM exam_submissions WHERE id = ?");
    $stmt->execute([$submissionId]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$submission) {
        throw new RuntimeException('Submission not found');
    }

    $rawQuestionScore = max(0, (float)($gradeData['raw_question_score'] ?? $gradeData['manual_score'] ?? 0));
    $percentage = min(100, max(0, (float)($gradeData['percentage'] ?? 0)));
    $classScore = min(40, max(0, round((float)($gradeData['class_score'] ?? $submission['class_score'] ?? 0))));
    $examScore = min(60, max(0, round((float)($gradeData['exam_score'] ?? (($percentage * 60) / 100)))));
    $totalScore = min(100, max(0, round((float)($gradeData['total_score'] ?? ($examScore + $classScore)))));
    $gradeInfo = qodaGradeInfoShared($totalScore);
    $grade = trim((string)($gradeData['grade'] ?? '')) ?: $gradeInfo['grade'];
    $gradePoint = isset($gradeData['grade_point']) && $gradeData['grade_point'] !== ''
        ? round((float)$gradeData['grade_point'], 1)
        : $gradeInfo['point'];
    $status = trim((string)($gradeData['status'] ?? 'GRADED')) ?: 'GRADED';
    $scoreSource = trim((string)($gradeData['score_source'] ?? 'manual')) ?: 'manual';
    $gradedBy = $gradeData['graded_by'] ?? null;

    $storage = 'exam_submissions';
    if (qodaTryEnsureFinalGradeTable($pdo)) {
        try {
            $insert = $pdo->prepare("
                INSERT INTO exam_final_grades
                    (submission_id, exam_id, student_id, raw_question_score, percentage, class_score, exam_score, total_score, grade, grade_point, status, score_source, graded_by, graded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    raw_question_score = VALUES(raw_question_score),
                    percentage = VALUES(percentage),
                    class_score = VALUES(class_score),
                    exam_score = VALUES(exam_score),
                    total_score = VALUES(total_score),
                    grade = VALUES(grade),
                    grade_point = VALUES(grade_point),
                    status = VALUES(status),
                    score_source = VALUES(score_source),
                    graded_by = VALUES(graded_by),
                    graded_at = NOW()
            ");
            $insert->execute([
                $submissionId,
                (int)$submission['exam_id'],
                (int)$submission['student_id'],
                $rawQuestionScore,
                $percentage,
                $classScore,
                $examScore,
                $totalScore,
                $grade,
                $gradePoint,
                $status,
                $scoreSource,
                $gradedBy
            ]);
            $storage = 'exam_final_grades';
        } catch (Throwable $error) {
            error_log('qoda final grade insert failed; using exam_submissions fallback: ' . $error->getMessage());
        }
    }

    try {
        qodaFallbackUpdateSubmissionGrade($pdo, $submissionId, [
            'percentage' => $percentage,
            'class_score' => $classScore,
            'exam_score' => $examScore,
            'total_score' => $totalScore,
            'grade' => $grade,
            'grade_point' => $gradePoint,
            'status' => $status,
        ]);
    } catch (Throwable $error) {
        if ($storage !== 'exam_final_grades') {
            $storage = 'question_grading_only';
        }
        error_log('qoda compact grade update failed; preserving final grade table when available: ' . $error->getMessage());
    }

    return [
        'submission_id' => $submissionId,
        'exam_id' => (int)$submission['exam_id'],
        'student_id' => (int)$submission['student_id'],
        'raw_question_score' => $rawQuestionScore,
        'percentage' => $percentage,
        'class_score' => $classScore,
        'exam_score' => $examScore,
        'total_score' => $totalScore,
        'grade' => $grade,
        'grade_point' => $gradePoint,
        'status' => $status,
        'storage' => $storage
    ];
}
