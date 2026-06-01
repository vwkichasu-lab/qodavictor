<?php
/**
 * Submission Controller
 */

class SubmissionController {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Get all submissions
     */
    public function index() {
        $user = authenticate();

        try {
            if ($user->role === 'STUDENT') {
                $stmt = $this->db->prepare("
                    SELECT s.*, e.title as exam_title, c.code as course_code
                    FROM submissions s
                    JOIN exams e ON s.examId = e.id
                    JOIN courses c ON e.courseId = c.id
                    WHERE s.studentId = ?
                    ORDER BY s.createdAt DESC
                ");
                $stmt->execute([$user->userId]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT s.*, e.title as exam_title, c.code as course_code,
                           u.name as student_name, u.userId as student_number
                    FROM submissions s
                    JOIN exams e ON s.examId = e.id
                    JOIN courses c ON e.courseId = c.id
                    JOIN users u ON s.studentId = u.id
                    ORDER BY s.createdAt DESC
                ");
                $stmt->execute();
            }

            $submissions = $stmt->fetchAll();
            successResponse($submissions);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch submissions: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get single submission
     */
    public function show($id) {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT s.*, e.title as exam_title, e.durationMins
                FROM submissions s
                JOIN exams e ON s.examId = e.id
                WHERE s.id = ?
            ");
            $stmt->execute([$id]);
            $submission = $stmt->fetch();

            if (!$submission) {
                errorResponse('Submission not found', 404);
            }

            // Get answers
            $stmt = $this->db->prepare("
                SELECT a.*, q.text as question_text, q.type as question_type, q.marks
                FROM answers a
                JOIN questions q ON a.questionId = q.id
                WHERE a.submissionId = ?
            ");
            $stmt->execute([$id]);
            $submission['answers'] = $stmt->fetchAll();

            successResponse($submission);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch submission: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Start exam submission
     */
    public function start() {
        $user = authenticate();
        $input = $_REQUEST;

        if (empty($input['examId'])) {
            errorResponse('Exam ID is required', 400);
        }

        $examId = $input['examId'];

        try {
            // Check if exam exists and is available
            $stmt = $this->db->prepare("SELECT * FROM exams WHERE id = ? AND published = true");
            $stmt->execute([$examId]);
            $exam = $stmt->fetch();

            if (!$exam) {
                errorResponse('Exam not found or not available', 404);
            }

            // Check if already submitted
            $stmt = $this->db->prepare("SELECT * FROM submissions WHERE examId = ? AND studentId = ?");
            $stmt->execute([$examId, $user->userId]);
            $existing = $stmt->fetch();

            if ($existing && $existing['status'] !== 'IN_PROGRESS') {
                errorResponse('Exam already submitted', 400);
            }

            // Create or update submission
            if ($existing) {
                $stmt = $this->db->prepare("UPDATE submissions SET startedAt = NOW(), status = 'IN_PROGRESS' WHERE id = ?");
                $stmt->execute([$existing['id']]);
                $submissionId = $existing['id'];
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO submissions (examId, studentId, startedAt, status, createdAt, updatedAt)
                    VALUES (?, ?, NOW(), 'IN_PROGRESS', NOW(), NOW())
                ");
                $stmt->execute([$examId, $user->userId]);
                $submissionId = $this->db->lastInsertId();
            }

            // Get questions
            $stmt = $this->db->prepare("SELECT * FROM questions WHERE examId = ? ORDER BY \"order\" ASC");
            $stmt->execute([$examId]);
            $questions = $stmt->fetchAll();

            successResponse([
                'submissionId' => $submissionId,
                'exam' => $exam,
                'questions' => $questions
            ], 'Exam started successfully');
        } catch (PDOException $e) {
            errorResponse('Failed to start exam: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit exam
     */
    public function submit() {
        $user = authenticate();
        $input = $_REQUEST;

        if (empty($input['submissionId']) || empty($input['answers'])) {
            errorResponse('Submission ID and answers are required', 400);
        }

        try {
            $this->db->beginTransaction();

            // Update submission status
            $stmt = $this->db->prepare("
                UPDATE submissions 
                SET submittedAt = NOW(), status = 'SUBMITTED', updatedAt = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$input['submissionId']]);

            // Save answers
            foreach ($input['answers'] as $answer) {
                if (empty($answer['questionId'])) continue;

                // Check if answer exists
                $stmt = $this->db->prepare("SELECT id FROM answers WHERE submissionId = ? AND questionId = ?");
                $stmt->execute([$input['submissionId'], $answer['questionId']]);
                $existingAnswer = $stmt->fetch();

                $maxScore = $answer['marks'] ?? 10;

                if ($existingAnswer) {
                    $stmt = $this->db->prepare("
                        UPDATE answers 
                        SET answerText = ?, answerData = ?, updatedAt = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $answer['answerText'] ?? null,
                        json_encode($answer['answerData'] ?? null),
                        $existingAnswer['id']
                    ]);
                } else {
                    $stmt = $this->db->prepare("
                        INSERT INTO answers (submissionId, questionId, answerText, answerData, maxScore, createdAt, updatedAt)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $input['submissionId'],
                        $answer['questionId'],
                        $answer['answerText'] ?? null,
                        json_encode($answer['answerData'] ?? null),
                        $maxScore
                    ]);
                }
            }

            $this->db->commit();

            successResponse(null, 'Exam submitted successfully');
        } catch (PDOException $e) {
            $this->db->rollBack();
            errorResponse('Failed to submit exam: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Auto-save answers
     */
    public function autoSave() {
        $user = authenticate();
        $input = $_REQUEST;

        if (empty($input['submissionId']) || empty($input['answers'])) {
            errorResponse('Submission ID and answers are required', 400);
        }

        try {
            foreach ($input['answers'] as $answer) {
                if (empty($answer['questionId'])) continue;

                $stmt = $this->db->prepare("SELECT id, maxScore FROM answers WHERE submissionId = ? AND questionId = ?");
                $stmt->execute([$input['submissionId'], $answer['questionId']]);
                $existingAnswer = $stmt->fetch();

                if ($existingAnswer) {
                    $stmt = $this->db->prepare("
                        UPDATE answers 
                        SET answerText = ?, answerData = ?, updatedAt = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $answer['answerText'] ?? null,
                        json_encode($answer['answerData'] ?? null),
                        $existingAnswer['id']
                    ]);
                } else {
                    $stmt = $this->db->prepare("
                        INSERT INTO answers (submissionId, questionId, answerText, answerData, maxScore, createdAt, updatedAt)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $input['submissionId'],
                        $answer['questionId'],
                        $answer['answerText'] ?? null,
                        json_encode($answer['answerData'] ?? null),
                        $answer['marks'] ?? 10
                    ]);
                }
            }

            successResponse(null, 'Answers saved');
        } catch (PDOException $e) {
            errorResponse('Failed to save answers: ' . $e->getMessage(), 500);
        }
    }
}
