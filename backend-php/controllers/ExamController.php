<?php
/**
 * Exam Controller
 */

class ExamController {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Get all exams (for lecturer/admin)
     */
    public function index() {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT e.*, c.name as course_name, c.code as course_code,
                       u.name as lecturer_name
                FROM exams e
                JOIN courses c ON e.courseId = c.id
                JOIN users u ON e.lecturerId = u.id
                ORDER BY e.createdAt DESC
            ");
            $stmt->execute();
            $exams = $stmt->fetchAll();

            successResponse($exams);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch exams: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get upcoming exams for student
     */
    public function upcoming() {
        $user = authenticate();
        
        if ($user->role !== 'STUDENT') {
            errorResponse('Unauthorized', 403);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT e.*, c.name as course_name, c.code as course_code,
                       u.name as lecturer_name
                FROM exams e
                JOIN courses c ON e.courseId = c.id
                JOIN users u ON e.lecturerId = u.id
                WHERE e.published = true 
                AND (e.startAt IS NULL OR e.startAt > NOW())
                AND e.status != 'ARCHIVED'
                ORDER BY e.startAt ASC
            ");
            $stmt->execute();
            $exams = $stmt->fetchAll();

            successResponse($exams);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch upcoming exams: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get available exams for student
     */
    public function available() {
        $user = authenticate();
        
        if ($user->role !== 'STUDENT') {
            errorResponse('Unauthorized', 403);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT e.*, c.name as course_name, c.code as course_code,
                       u.name as lecturer_name
                FROM exams e
                JOIN courses c ON e.courseId = c.id
                JOIN users u ON e.lecturerId = u.id
                WHERE e.published = true 
                AND e.status IN ('PUBLISHED', 'ONGOING')
                AND (e.endAt IS NULL OR e.endAt > NOW())
                ORDER BY e.startAt ASC
            ");
            $stmt->execute();
            $exams = $stmt->fetchAll();

            successResponse($exams);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch available exams: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get single exam by ID
     */
    public function show($id) {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT e.*, c.name as course_name, c.code as course_code,
                       u.name as lecturer_name
                FROM exams e
                JOIN courses c ON e.courseId = c.id
                JOIN users u ON e.lecturerId = u.id
                WHERE e.id = ?
            ");
            $stmt->execute([$id]);
            $exam = $stmt->fetch();

            if (!$exam) {
                errorResponse('Exam not found', 404);
            }

            // Get questions if user is lecturer/admin or exam is published
            if ($user->role === 'LECTURER' || $user->role === 'ADMIN' || $exam['published']) {
                $stmt = $this->db->prepare("SELECT * FROM questions WHERE examId = ? ORDER BY \"order\" ASC");
                $stmt->execute([$id]);
                $questions = $stmt->fetchAll();
                $exam['questions'] = $questions;
            }

            successResponse($exam);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch exam: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create new exam
     */
    public function store() {
        $user = authorize(['LECTURER', 'ADMIN']);
        $input = $_REQUEST;

        $required = ['title', 'courseId', 'durationMins', 'academicYear'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                errorResponse("Missing required field: $field", 400);
            }
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO exams (title, description, instructions, durationMins, startAt, endAt,
                                  status, published, resultsPublished, academicYear, semester,
                                  courseId, lecturerId, createdAt, updatedAt)
                VALUES (?, ?, ?, ?, ?, ?, 'DRAFT', false, false, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $input['title'],
                $input['description'] ?? null,
                $input['instructions'] ?? '',
                $input['durationMins'],
                $input['startAt'] ?? null,
                $input['endAt'] ?? null,
                $input['academicYear'],
                $input['semester'] ?? null,
                $input['courseId'],
                $user->userId
            ]);

            $examId = $this->db->lastInsertId();
            successResponse(['id' => $examId], 'Exam created successfully', 201);
        } catch (PDOException $e) {
            errorResponse('Failed to create exam: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update exam
     */
    public function update($id) {
        $user = authorize(['LECTURER', 'ADMIN']);
        $input = $_REQUEST;

        try {
            $fields = [];
            $values = [];

            $allowedFields = ['title', 'description', 'instructions', 'durationMins', 'startAt', 'endAt', 'status', 'semester'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $fields[] = "$field = ?";
                    $values[] = $input[$field];
                }
            }

            if (empty($fields)) {
                errorResponse('No fields to update', 400);
            }

            $values[] = $id;
            $stmt = $this->db->prepare("UPDATE exams SET " . implode(', ', $fields) . ", updatedAt = NOW() WHERE id = ?");
            $stmt->execute($values);

            successResponse(null, 'Exam updated successfully');
        } catch (PDOException $e) {
            errorResponse('Failed to update exam: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete exam
     */
    public function destroy($id) {
        $user = authorize(['LECTURER', 'ADMIN']);

        try {
            $stmt = $this->db->prepare("DELETE FROM exams WHERE id = ?");
            $stmt->execute([$id]);

            successResponse(null, 'Exam deleted successfully');
        } catch (PDOException $e) {
            errorResponse('Failed to delete exam: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Publish/unpublish exam
     */
    public function publish($id) {
        $user = authorize(['LECTURER', 'ADMIN']);
        $input = $_REQUEST;

        $published = $input['published'] ?? true;

        try {
            $stmt = $this->db->prepare("UPDATE exams SET published = ?, status = 'PUBLISHED', updatedAt = NOW() WHERE id = ?");
            $stmt->execute([$published, $id]);

            successResponse(null, $published ? 'Exam published successfully' : 'Exam unpublished successfully');
        } catch (PDOException $e) {
            errorResponse('Failed to publish exam: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get exam stats
     */
    public function stats($id) {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM submissions WHERE examId = ?) as total_submissions,
                    (SELECT COUNT(*) FROM submissions WHERE examId = ? AND status = 'GRADED') as graded_count,
                    (SELECT AVG(totalScore) FROM results WHERE examId = ?) as average_score
            ");
            $stmt->execute([$id, $id, $id]);
            $stats = $stmt->fetch();

            successResponse($stats);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch stats: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Add question to exam
     */
    public function addQuestion($examId) {
        $user = authorize(['LECTURER', 'ADMIN']);
        $input = $_REQUEST;

        $required = ['type', 'text', 'marks'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                errorResponse("Missing required field: $field", 400);
            }
        }

        try {
            // Get next order number
            $stmt = $this->db->prepare("SELECT COALESCE(MAX(\"order\"), 0) + 1 as next_order FROM questions WHERE examId = ?");
            $stmt->execute([$examId]);
            $result = $stmt->fetch();
            $order = $result['next_order'];

            $stmt = $this->db->prepare("
                INSERT INTO questions (examId, type, text, marks, \"order\", imageUrl, options, 
                                      \"correctAnswer\", \"starterCode\", \"testCases\", rubric, keywords, createdAt, updatedAt)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $examId,
                $input['type'],
                $input['text'],
                $input['marks'],
                $order,
                $input['imageUrl'] ?? null,
                $input['options'] ?? null,
                $input['correctAnswer'] ?? null,
                $input['starterCode'] ?? null,
                $input['testCases'] ?? null,
                $input['rubric'] ?? null,
                $input['keywords'] ?? null
            ]);

            $questionId = $this->db->lastInsertId();
            successResponse(['id' => $questionId], 'Question added successfully', 201);
        } catch (PDOException $e) {
            errorResponse('Failed to add question: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update question
     */
    public function updateQuestion($examId, $questionId) {
        $user = authorize(['LECTURER', 'ADMIN']);
        $input = $_REQUEST;

        try {
            $fields = [];
            $values = [];

            $allowedFields = ['type', 'text', 'marks', 'imageUrl', 'options', 'correctAnswer', 'starterCode', 'testCases', 'rubric', 'keywords'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $fields[] = "$field = ?";
                    $values[] = $input[$field];
                }
            }

            if (empty($fields)) {
                errorResponse('No fields to update', 400);
            }

            $values[] = $questionId;
            $stmt = $this->db->prepare("UPDATE questions SET " . implode(', ', $fields) . ", updatedAt = NOW() WHERE id = ?");
            $stmt->execute($values);

            successResponse(null, 'Question updated successfully');
        } catch (PDOException $e) {
            errorResponse('Failed to update question: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete question
     */
    public function deleteQuestion($examId, $questionId) {
        $user = authorize(['LECTURER', 'ADMIN']);

        try {
            $stmt = $this->db->prepare("DELETE FROM questions WHERE id = ?");
            $stmt->execute([$questionId]);

            successResponse(null, 'Question deleted successfully');
        } catch (PDOException $e) {
            errorResponse('Failed to delete question: ' . $e->getMessage(), 500);
        }
    }
}
