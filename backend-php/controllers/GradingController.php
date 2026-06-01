<?php
/**
 * Grading Controller
 */

class GradingController {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Get submissions for grading
     */
    public function index() {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT s.*, e.title as exam_title, 
                       u.name as student_name, u.userId as student_number
                FROM submissions s
                JOIN exams e ON s.examId = e.id
                JOIN users u ON s.studentId = u.id
                WHERE s.status IN ('SUBMITTED', 'GRADING')
                ORDER BY s.submittedAt DESC
            ");
            $stmt->execute();
            $submissions = $stmt->fetchAll();

            successResponse($submissions);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch submissions: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Grade a submission
     */
    public function grade() {
        $user = authorize(['LECTURER', 'ADMIN']);
        $input = $_REQUEST;

        if (empty($input['submissionId']) || empty($input['grades'])) {
            errorResponse('Submission ID and grades are required', 400);
        }

        try {
            $this->db->beginTransaction();

            $totalScore = 0;
            $maxScore = 0;

            foreach ($input['grades'] as $grade) {
                $stmt = $this->db->prepare("
                    UPDATE answers 
                    SET score = ?, feedback = ?, gradedBy = ?, gradedAt = NOW(), updatedAt = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $grade['score'],
                    $grade['feedback'] ?? null,
                    $user->userId,
                    $grade['answerId']
                ]);

                $totalScore += $grade['score'];
                $maxScore += $grade['maxScore'] ?? 10;
            }

            // Update submission status
            $stmt = $this->db->prepare("
                UPDATE submissions 
                SET status = 'GRADED', updatedAt = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$input['submissionId']]);

            // Calculate percentage and grade
            $percentage = $maxScore > 0 ? ($totalScore / $maxScore) * 100 : 0;
            $letterGrade = $this->calculateGrade($percentage);

            // Update or create result
            $stmt = $this->db->prepare("
                SELECT id FROM results WHERE submissionId = ?
            ");
            $stmt->execute([$input['submissionId']]);
            $existingResult = $stmt->fetch();

            if ($existingResult) {
                $stmt = $this->db->prepare("
                    UPDATE results 
                    SET totalScore = ?, maxScore = ?, percentage = ?, grade = ?, updatedAt = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$totalScore, $maxScore, $percentage, $letterGrade, $existingResult['id']]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT examId, studentId FROM submissions WHERE id = ?
                ");
                $stmt->execute([$input['submissionId']]);
                $submission = $stmt->fetch();

                $stmt = $this->db->prepare("
                    INSERT INTO results (examId, studentId, submissionId, totalScore, maxScore, percentage, grade, createdAt, updatedAt)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $submission['examId'],
                    $submission['studentId'],
                    $input['submissionId'],
                    $totalScore,
                    $maxScore,
                    $percentage,
                    $letterGrade
                ]);
            }

            $this->db->commit();

            successResponse([
                'totalScore' => $totalScore,
                'maxScore' => $maxScore,
                'percentage' => $percentage,
                'grade' => $letterGrade
            ], 'Grading completed successfully');
        } catch (PDOException $e) {
            $this->db->rollBack();
            errorResponse('Failed to grade submission: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Auto-grade MCQ questions
     */
    public function autoGrade() {
        $user = authorize(['LECTURER', 'ADMIN']);
        $input = $_REQUEST;

        if (empty($input['submissionId'])) {
            errorResponse('Submission ID is required', 400);
        }

        try {
            // Get submission with answers and questions
            $stmt = $this->db->prepare("
                SELECT a.*, q.type, q.correctAnswer, q.marks
                FROM answers a
                JOIN questions q ON a.questionId = q.id
                WHERE a.submissionId = ?
            ");
            $stmt->execute([$input['submissionId']]);
            $answers = $stmt->fetchAll();

            $totalScore = 0;
            $maxScore = 0;
            $gradedAnswers = [];

            foreach ($answers as $answer) {
                $maxScore += $answer['marks'];
                
                // Auto-grade MCQ and True/False
                if (in_array($answer['type'], ['MCQ', 'TRUE_FALSE'])) {
                    $correctAnswer = json_decode($answer['correctAnswer'], true);
                    $studentAnswer = $answer['answerText'];

                    $isCorrect = false;
                    if ($answer['type'] === 'MCQ') {
                        $isCorrect = ($studentAnswer === $correctAnswer['index'] ?? null);
                    } else {
                        $isCorrect = (strtoupper($studentAnswer) === strtoupper($correctAnswer));
                    }

                    $score = $isCorrect ? $answer['marks'] : 0;
                    $totalScore += $score;

                    $stmt = $this->db->prepare("
                        UPDATE answers 
                        SET score = ?, autoGraded = true, gradedAt = NOW(), updatedAt = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$score, $answer['id']]);

                    $gradedAnswers[] = [
                        'answerId' => $answer['id'],
                        'score' => $score,
                        'maxScore' => $answer['marks']
                    ];
                }
            }

            successResponse([
                'gradedAnswers' => $gradedAnswers,
                'totalScore' => $totalScore,
                'maxScore' => $maxScore
            ], 'Auto-grading completed');
        } catch (PDOException $e) {
            errorResponse('Failed to auto-grade: ' . $e->getMessage(), 500);
        }
    }

    private function calculateGrade($percentage) {
        if ($percentage >= 90) return 'A';
        if ($percentage >= 80) return 'B';
        if ($percentage >= 70) return 'C';
        if ($percentage >= 60) return 'D';
        return 'F';
    }
}
