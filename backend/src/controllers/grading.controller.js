import prisma from '../utils/db.js';
import { successResponse, errorResponse } from '../utils/response.js';
import { logAudit, AuditActions } from '../utils/audit.js';
import MarkingService from '../services/marking.service.js';

export const GradingController = {
  /**
   * Auto-grade a submission
   */
  async autoGrade(req, res) {
    try {
      const { submissionId } = req.params;

      const submission = await prisma.submission.findUnique({
        where: { id: submissionId },
        include: { exam: true },
      });

      if (!submission) {
        return errorResponse(res, 'Submission not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && submission.exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      const result = await MarkingService.gradeSubmission(submissionId);

      await logAudit(req.user.id, AuditActions.AUTO_GRADE, 'Submission', submissionId, null, req);

      return successResponse(res, result, 'Auto-grading completed');
    } catch (error) {
      console.error('Auto-grade error:', error);
      return errorResponse(res, 'Failed to auto-grade', 500);
    }
  },

  /**
   * Auto-grade all submissions for an exam
   */
  async autoGradeAll(req, res) {
    try {
      const { examId } = req.params;

      const exam = await prisma.exam.findUnique({
        where: { id: examId },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      const submissions = await prisma.submission.findMany({
        where: {
          examId,
          status: { in: ['SUBMITTED', 'GRADING'] },
        },
      });

      const results = [];
      for (const submission of submissions) {
        try {
          const result = await MarkingService.gradeSubmission(submission.id);
          results.push({ submissionId: submission.id, success: true, result });
        } catch (err) {
          results.push({ submissionId: submission.id, success: false, error: err.message });
        }
      }

      await logAudit(req.user.id, AuditActions.AUTO_GRADE, 'Exam', examId, { count: submissions.length }, req);

      return successResponse(res, {
        graded: results.filter(r => r.success).length,
        failed: results.filter(r => !r.success).length,
        results,
      }, 'Auto-grading completed for all submissions');
    } catch (error) {
      console.error('Auto-grade all error:', error);
      return errorResponse(res, 'Failed to auto-grade submissions', 500);
    }
  },

  /**
   * Manual grade an answer
   */
  async manualGrade(req, res) {
    try {
      const { answerId } = req.params;
      const { score, feedback } = req.body;

      const answer = await prisma.answer.findUnique({
        where: { id: answerId },
        include: {
          submission: {
            include: { exam: true },
          },
        },
      });

      if (!answer) {
        return errorResponse(res, 'Answer not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && answer.submission.exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      const updatedAnswer = await prisma.answer.update({
        where: { id: answerId },
        data: {
          score: parseFloat(score),
          feedback,
          autoGraded: false,
          needsManualReview: false,
          gradedBy: req.user.id,
          gradedAt: new Date(),
        },
      });

      // Update submission total score
      await this.updateSubmissionResult(answer.submissionId);

      await logAudit(req.user.id, AuditActions.MANUAL_GRADE, 'Answer', answerId, { score }, req);

      return successResponse(res, updatedAnswer, 'Answer graded successfully');
    } catch (error) {
      console.error('Manual grade error:', error);
      return errorResponse(res, 'Failed to grade answer', 500);
    }
  },

  /**
   * Get answers needing manual review
   */
  async getPendingReview(req, res) {
    try {
      const { examId } = req.params;
      const { page = 1, limit = 20 } = req.query;
      const skip = (parseInt(page) - 1) * parseInt(limit);

      const exam = await prisma.exam.findUnique({
        where: { id: examId },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      const [answers, total] = await Promise.all([
        prisma.answer.findMany({
          where: {
            submission: { examId },
            OR: [
              { needsManualReview: true },
              { question: { type: 'ESSAY' } },
              { question: { type: 'CODING' } },
            ],
          },
          include: {
            question: true,
            submission: {
              include: {
                student: {
                  include: {
                    user: { select: { name: true, userId: true } },
                  },
                },
              },
            },
          },
          skip,
          take: parseInt(limit),
          orderBy: { createdAt: 'desc' },
        }),
        prisma.answer.count({
          where: {
            submission: { examId },
            OR: [
              { needsManualReview: true },
              { question: { type: 'ESSAY' } },
              { question: { type: 'CODING' } },
            ],
          },
        }),
      ]);

      return successResponse(res, {
        answers,
        pagination: {
          page: parseInt(page),
          limit: parseInt(limit),
          total,
          pages: Math.ceil(total / parseInt(limit)),
        },
      });
    } catch (error) {
      console.error('Get pending review error:', error);
      return errorResponse(res, 'Failed to fetch pending reviews', 500);
    }
  },

  /**
   * Get marking scheme for an exam
   */
  async getMarkingScheme(req, res) {
    try {
      const { examId } = req.params;

      const exam = await prisma.exam.findUnique({
        where: { id: examId },
        include: {
          questions: {
            include: {
              markingScheme: true,
            },
            orderBy: { order: 'asc' },
          },
        },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      return successResponse(res, exam.questions);
    } catch (error) {
      console.error('Get marking scheme error:', error);
      return errorResponse(res, 'Failed to fetch marking scheme', 500);
    }
  },

  /**
   * Update marking scheme for a question
   */
  async updateMarkingScheme(req, res) {
    try {
      const { questionId } = req.params;
      const { rules } = req.body;

      const question = await prisma.question.findUnique({
        where: { id: questionId },
        include: { exam: true },
      });

      if (!question) {
        return errorResponse(res, 'Question not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && question.exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      const scheme = await MarkingService.createMarkingScheme(questionId, rules);

      return successResponse(res, scheme, 'Marking scheme updated');
    } catch (error) {
      console.error('Update marking scheme error:', error);
      return errorResponse(res, 'Failed to update marking scheme', 500);
    }
  },

  /**
   * Update submission result after grading
   */
  async updateSubmissionResult(submissionId) {
    const submission = await prisma.submission.findUnique({
      where: { id: submissionId },
      include: {
        answers: true,
        exam: true,
      },
    });

    if (!submission) return;

    const totalScore = submission.answers.reduce((sum, a) => sum + (a.score || 0), 0);
    const maxScore = submission.answers.reduce((sum, a) => sum + a.maxScore, 0);
    const percentage = maxScore > 0 ? (totalScore / maxScore) * 100 : 0;

    // Determine grade
    let grade = 'F';
    if (percentage >= 80) grade = 'A';
    else if (percentage >= 70) grade = 'B';
    else if (percentage >= 60) grade = 'C';
    else if (percentage >= 50) grade = 'D';

    // Create or update result
    await prisma.result.upsert({
      where: {
        submissionId: submissionId,
      },
      update: {
        totalScore,
        maxScore,
        percentage,
        grade,
        answerBreakdown: submission.answers.map(a => ({
          questionId: a.questionId,
          score: a.score,
          maxScore: a.maxScore,
        })),
      },
      create: {
        examId: submission.examId,
        studentId: submission.studentId,
        submissionId: submissionId,
        totalScore,
        maxScore,
        percentage,
        grade,
        answerBreakdown: submission.answers.map(a => ({
          questionId: a.questionId,
          score: a.score,
          maxScore: a.maxScore,
        })),
      },
    });

    // Update submission status
    await prisma.submission.update({
      where: { id: submissionId },
      data: { status: 'GRADED' },
    });
  },
};

export default GradingController;
