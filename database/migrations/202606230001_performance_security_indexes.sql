-- Performance indexes for the main production flows.
-- These are intentionally non-destructive and safe to run on existing data.

CREATE INDEX IF NOT EXISTS idx_users_user_id_role ON users (user_id, role);
CREATE INDEX IF NOT EXISTS idx_users_status_role ON users (status, role);

CREATE INDEX IF NOT EXISTS idx_students_student_id ON students (student_id);
CREATE INDEX IF NOT EXISTS idx_students_lecturer_level ON students (lecturer_id, level);
CREATE INDEX IF NOT EXISTS idx_students_user_id ON students (user_id);

CREATE INDEX IF NOT EXISTS idx_exams_lecturer_status ON exams (lecturer_id, status);
CREATE INDEX IF NOT EXISTS idx_exams_created_status ON exams (created_by, status);
CREATE INDEX IF NOT EXISTS idx_exams_course_level ON exams (course_code, level, semester);
CREATE INDEX IF NOT EXISTS idx_exams_publish_window ON exams (is_published, start_datetime, end_datetime);

CREATE INDEX IF NOT EXISTS idx_exam_submissions_exam_student ON exam_submissions (exam_id, student_id);
CREATE INDEX IF NOT EXISTS idx_exam_submissions_status_submitted ON exam_submissions (status, submitted, submitted_at);
CREATE INDEX IF NOT EXISTS idx_exam_submissions_student_identifier ON exam_submissions (student_identifier);

CREATE INDEX IF NOT EXISTS idx_final_grades_exam_student ON exam_final_grades (exam_id, student_id);
CREATE INDEX IF NOT EXISTS idx_final_grades_status_source ON exam_final_grades (status, score_source);

CREATE INDEX IF NOT EXISTS idx_screen_captures_exam_student_type ON screen_captures (exam_id, student_id, capture_type);
CREATE INDEX IF NOT EXISTS idx_suspicious_logs_exam_student_time ON suspicious_logs (exam_id, student_id, created_at);
CREATE INDEX IF NOT EXISTS idx_proctor_commands_exam_student ON proctor_commands (exam_id, student_id, delivered_at);
