-- QODA final grade and profile foundation.
-- This migration keeps grading records compact and ensures profile/course fields
-- exist on both local MySQL and Railway MySQL deployments.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS submission_id INT NOT NULL;
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS exam_id INT NOT NULL;
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS student_id INT NOT NULL;
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS class_score DECIMAL(5,2) DEFAULT 0;
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS exam_score DECIMAL(5,2) DEFAULT 0;
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS total_score DECIMAL(5,2) DEFAULT 0;
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS grade VARCHAR(5) NULL;
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS grade_point DECIMAL(3,1) DEFAULT 0;
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'GRADED';
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS score_source VARCHAR(30) DEFAULT 'manual';
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS graded_by INT NULL;
ALTER TABLE exam_final_grades ADD COLUMN IF NOT EXISTS graded_at DATETIME NULL;

ALTER TABLE exam_submissions ENGINE=InnoDB;
ALTER TABLE exam_submissions ROW_FORMAT=DYNAMIC;
ALTER TABLE exam_submissions MODIFY answers LONGTEXT NULL;
ALTER TABLE exam_submissions ADD COLUMN IF NOT EXISTS class_score DECIMAL(5,2) DEFAULT 0;
ALTER TABLE exam_submissions ADD COLUMN IF NOT EXISTS exam_score DECIMAL(5,2) DEFAULT 0;
ALTER TABLE exam_submissions ADD COLUMN IF NOT EXISTS grade VARCHAR(5) NULL;
ALTER TABLE exam_submissions ADD COLUMN IF NOT EXISTS grade_point DECIMAL(3,1) DEFAULT 0;
ALTER TABLE exam_submissions ADD COLUMN IF NOT EXISTS ai_feedback MEDIUMTEXT NULL;
ALTER TABLE exam_submissions ADD COLUMN IF NOT EXISTS execution_results JSON NULL;

ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(120) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS title VARCHAR(50) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS staff_id VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE users MODIFY profile_pic MEDIUMTEXT NULL;

ALTER TABLE students ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE students MODIFY profile_pic MEDIUMTEXT NULL;

CREATE TABLE IF NOT EXISTS lecturer_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT NOT NULL,
    course_code VARCHAR(50) NOT NULL,
    course_name VARCHAR(255) NOT NULL,
    level VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lecturer_course_level (lecturer_id, course_code, level),
    INDEX idx_lecturer_courses_lecturer (lecturer_id),
    INDEX idx_lecturer_courses_code_level (course_code, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realtime_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recipient_id INT NOT NULL,
    recipient_role VARCHAR(30) NOT NULL,
    notification_type VARCHAR(80) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    payload JSON NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_realtime_notifications_recipient (recipient_id, recipient_role, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_level_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    old_level VARCHAR(50) NULL,
    new_level VARCHAR(50) NOT NULL,
    changed_by INT NULL,
    reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student_level_history_student (student_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
