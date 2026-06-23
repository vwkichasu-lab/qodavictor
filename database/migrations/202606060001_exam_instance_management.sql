-- QODA lecturer exam-instance, question-bank, and grading support.
-- Safe to run after the foundation migration. The PHP pages also auto-create
-- these objects when possible, but this keeps the database upgrade explicit.

ALTER TABLE exams
    ADD COLUMN IF NOT EXISTS academic_year VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS results_published TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS results_published_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS grace_period_minutes INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS cutoff_datetime DATETIME NULL,
    ADD COLUMN IF NOT EXISTS exam_control_status VARCHAR(30) NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS pause_started_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS paused_seconds_total INT NOT NULL DEFAULT 0;

ALTER TABLE exam_submissions
    ADD COLUMN IF NOT EXISTS submitted TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS submittedAt DATETIME NULL,
    ADD COLUMN IF NOT EXISTS graded_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS graded_by INT NULL,
    ADD COLUMN IF NOT EXISTS manual_feedback TEXT NULL,
    ADD COLUMN IF NOT EXISTS execution_results JSON NULL,
    ADD COLUMN IF NOT EXISTS ai_feedback MEDIUMTEXT NULL,
    ADD COLUMN IF NOT EXISTS auto_graded_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS submission_folder VARCHAR(255) NULL;

ALTER TABLE exam_submissions
    MODIFY answers LONGTEXT NULL,
    MODIFY ai_feedback MEDIUMTEXT NULL,
    ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS exam_question_grading (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_index INT NOT NULL,
    marking_scheme TEXT NULL,
    test_cases JSON NULL,
    ai_score DECIMAL(10,2) DEFAULT 0,
    ai_feedback TEXT NULL,
    manual_score DECIMAL(10,2) DEFAULT NULL,
    manual_feedback TEXT NULL,
    score_source ENUM('ai','manual') DEFAULT 'ai',
    graded_at DATETIME NULL,
    UNIQUE KEY uq_submission_question (submission_id, question_index),
    INDEX idx_question_grading_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS question_bank (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT NULL,
    source_exam_id INT NULL,
    source_question_index INT NULL,
    course_code VARCHAR(50) NULL,
    course_name VARCHAR(255) NULL,
    language VARCHAR(50) NULL,
    semester VARCHAR(100) NULL,
    academic_year VARCHAR(50) NULL,
    difficulty VARCHAR(50) NULL,
    title VARCHAR(255) NOT NULL,
    prompt LONGTEXT NOT NULL,
    question_json LONGTEXT NULL,
    marks DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_question_bank_filters (lecturer_id, course_code, language, semester, academic_year),
    INDEX idx_question_bank_source (source_exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS compiler_run_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NULL,
    exam_id INT NULL,
    student_id INT NULL,
    question_index INT NULL,
    actor_role VARCHAR(30) NULL,
    language VARCHAR(50) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    input_summary TEXT NULL,
    output_summary TEXT NULL,
    error_summary TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_compiler_run_logs_submission (submission_id, question_index),
    INDEX idx_compiler_run_logs_exam (exam_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
