CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(190) NOT NULL UNIQUE,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_audit_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    actor_role VARCHAR(50) NULL,
    event_type VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id VARCHAR(120) NULL,
    details JSON NULL,
    ip_address VARCHAR(64) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_actor (actor_id, actor_role),
    INDEX idx_audit_event (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(190) NOT NULL,
    ip_address VARCHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_attempt_at DATETIME NOT NULL,
    UNIQUE KEY uq_login_rate (identifier, ip_address),
    INDEX idx_login_lock (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS result_publications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    course_code VARCHAR(50) NOT NULL,
    semester VARCHAR(100) NULL,
    published_by INT NULL,
    published_at DATETIME NOT NULL,
    notes TEXT NULL,
    INDEX idx_result_publication_exam (exam_id),
    INDEX idx_result_publication_course (course_code, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_result_appeals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    submission_id INT NULL,
    message TEXT NOT NULL,
    status ENUM('open','reviewing','resolved','rejected') NOT NULL DEFAULT 'open',
    lecturer_response TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_result_appeal_student (student_id),
    INDEX idx_result_appeal_exam (exam_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS question_bank (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT NULL,
    course_code VARCHAR(50) NULL,
    language VARCHAR(50) NULL,
    title VARCHAR(255) NOT NULL,
    prompt LONGTEXT NOT NULL,
    starter_files JSON NULL,
    test_cases JSON NULL,
    marks DECIMAL(10,2) NOT NULL DEFAULT 0,
    tags JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_question_bank_course (course_code, language),
    INDEX idx_question_bank_lecturer (lecturer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS exam_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT NULL,
    name VARCHAR(255) NOT NULL,
    course_code VARCHAR(50) NULL,
    settings JSON NOT NULL,
    questions JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exam_template_lecturer (lecturer_id),
    INDEX idx_exam_template_course (course_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS compiler_run_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    actor_role VARCHAR(50) NULL,
    exam_id INT NULL,
    submission_id INT NULL,
    language VARCHAR(50) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    execution_time_ms INT NULL,
    error_summary TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_compiler_run_exam (exam_id, submission_id),
    INDEX idx_compiler_run_actor (actor_id, actor_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backup_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requested_by INT NULL,
    backup_type VARCHAR(50) NOT NULL DEFAULT 'database',
    status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
    file_path VARCHAR(500) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_backup_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
