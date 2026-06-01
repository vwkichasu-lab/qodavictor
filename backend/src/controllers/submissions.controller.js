import prisma from '../utils/db.js';
import { successResponse, errorResponse } from '../utils/response.js';
import { logAudit, AuditActions } from '../utils/audit.js';
import MarkingService from '../services/marking.service.js';
import SecurityService from '../services/security.service.js';

export const SubmissionsController = {
  /**
   * Start an exam (create submission)
   */
  async startExam(req, res) {
    try {
      const { examId } = req.params;
      const studentId = req.user.studentId;

      // Check if exam is available
      const exam = await prisma.exam.findUnique({
        where: { id: examId },
        include: {
          questions: {
            orderBy: { order: 'asc' },
          },
        },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      if (!exam.published) {
        return errorResponse(res, 'Exam is not published', 403);
      }

      // Check if already submitted
      const existingSubmission = await prisma.submission.findUnique({
        where: {
          examId_studentId: {
            examId,
            studentId,
          },
        },
      });

      if (existingSubmission?.status === 'SUBMITTED' || existingSubmission?.status === 'GRADED') {
        return errorResponse(res, 'You have already submitted this exam', 403);
      }

      // Check security status
      const securityCheck = await SecurityService.validateExamAccess(studentId, examId);
      if (!securityCheck.allowed) {
        return errorResponse(res, securityCheck.reason, 403);
      }

      // Resume existing or create new
      let submission = existingSubmission;
      if (!submission) {
        submission = await prisma.submission.create({
          data: {
            examId,
            studentId,
            status: 'IN_PROGRESS',
            startedAt: new Date(),
          },
        });

        await logAudit(req.user.id, AuditActions.START_EXAM, 'Submission', submission.id, { examId }, req);
      }

      // Return exam data without correct answers
      const studentExam = {
        ...exam,
        questions: exam.questions.map(q => ({
          id: q.id,
          type: q.type,
          text: q.text,
          marks: q.marks,
          order: q.order,
          imageUrl: q.imageUrl,
          options: q.options?.map(opt => ({
            id: opt.id || opt.letter,
            text: opt.text,
            letter: opt.letter,
          })),
          starterCode: q.starterCode,
        })),
      };

      return successResponse(res, {
        submission,
        exam: studentExam,
      }, existingSubmission ? 'Exam resumed' : 'Exam started');
    } catch (error) {
      console.error('Start exam error:', error);
      return errorResponse(res, 'Failed to start exam', 500);
    }
  },

  /**
   * Save answer for a question
   */
  async saveAnswer(req, res) {
    try {
      const { submissionId } = req.params;
      const { questionId, answerText, answerData } = req.body;
      const studentId = req.user.studentId;

      // Verify submission belongs to student
      const submission = await prisma.submission.findFirst({
        where: {
          id: submissionId,
          studentId,
          status: 'IN_PROGRESS',
        },
        include: {
          exam: {
            include: {
              questions: {
                where: { id: questionId },
              },
            },
          },
        },
      });

      if (!submission) {
        return errorResponse(res, 'Submission not found or already submitted', 404);
      }

      const question = submission.exam.questions[0];
      if (!question) {
        return errorResponse(res, 'Question not found in this exam', 404);
      }

      // Upsert answer
      const answer = await prisma.answer.upsert({
        where: {
          submissionId_questionId: {
            submissionId,
            questionId,
          },
        },
        update: {
          answerText,
          answerData,
        },
        create: {
          submissionId,
          questionId,
          answerText,
          answerData,
          maxScore: question.marks,
        },
      });

      return successResponse(res, answer, 'Answer saved');
    } catch (error) {
      console.error('Save answer error:', error);
      return errorResponse(res, 'Failed to save answer', 500);
    }
  },

  /**
   * Submit exam
   */
  async submitExam(req, res) {
    try {
      const { submissionId } = req.params;
      const studentId = req.user.studentId;

      const submission = await prisma.submission.findFirst({
        where: {
          id: submissionId,
          studentId,
          status: 'IN_PROGRESS',
        },
        include: {
          exam: true,
          answers: {
            include: { question: true },
          },
        },
      });

      if (!submission) {
        return errorResponse(res, 'Submission not found or already submitted', 404);
      }

      // Update submission
      const updatedSubmission = await prisma.submission.update({
        where: { id: submissionId },
        data: {
          status: 'SUBMITTED',
          submittedAt: new Date(),
          autoSubmitted: req.body.autoSubmitted || false,
        },
      });

      // Auto-grade the submission
      await MarkingService.gradeSubmission(submissionId);

      await logAudit(
        req.user.id,
        req.body.autoSubmitted ? AuditActions.AUTO_SUBMIT : AuditActions.SUBMIT_EXAM,
        'Submission',
        submissionId,
        { examId: submission.examId },
        req
      );

      return successResponse(res, updatedSubmission, 'Exam submitted successfully');
    } catch (error) {
      console.error('Submit exam error:', error);
      return errorResponse(res, 'Failed to submit exam', 500);
    }
  },

  /**
   * Get submission details
   */
  async getById(req, res) {
    try {
      const { id } = req.params;

      const submission = await prisma.submission.findUnique({
        where: { id },
        include: {
          exam: {
            include: {
              course: true,
              lecturer: {
                include: {
                  user: { select: { name: true } },
                },
              },
            },
          },
          student: {
            include: {
              user: { select: { name: true, userId: true } },
            },
          },
          answers: {
            include: {
              question: true,
            },
            orderBy: {
              question: { order: 'asc' },
          },
          },
          result: true,
        },
      });

      if (!submission) {
        return errorResponse(res, 'Submission not found', 404);
      }

      // Check permissions
      if (req.user.role === 'STUDENT' && submission.studentId !== req.user.studentId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      if (req.user.role === 'LECTURER' && submission.exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      return successResponse(res, submission);
    } catch (error) {
      console.error('Get submission error:', error);
      return errorResponse(res, 'Failed to fetch submission', 500);
    }
  },

  /**
   * Get all submissions for an exam
   */
  async getByExam(req, res) {
    try {
      const { examId } = req.params;
      const { page = 1, limit = 20, status } = req.query;
      const skip = (parseInt(page) - 1) * parseInt(limit);

      const where = { examId };
      if (status) where.status = status;

      const [submissions, total] = await Promise.all([
        prisma.submission.findMany({
          where,
          skip,
          take: parseInt(limit),
          include: {
            student: {
              include: {
                user: { select: { name: true, userId: true } },
              },
            },
            result: true,
            _count: {
              select: { answers: true },
            },
          },
          orderBy: { submittedAt: 'desc' },
        }),
        prisma.submission.count({ where }),
      ]);

      return successResponse(res, {
        submissions,
        pagination: {
          page: parseInt(page),
          limit: parseInt(limit),
          total,
          pages: Math.ceil(total / parseInt(limit)),
        },
      });
    } catch (error) {
      console.error('Get submissions error:', error);
      return errorResponse(res, 'Failed to fetch submissions', 500);
    }
  },

  /**
   * Get my submissions (for student)
   */
  async getMySubmissions(req, res) {
    try {
      const studentId = req.user.studentId;
      const { page = 1, limit = 20 } = req.query;
      const skip = (parseInt(page) - 1) * parseInt(limit);

      const [submissions, total] = await Promise.all([
        prisma.submission.findMany({
          where: { studentId },
          skip,
          take: parseInt(limit),
          include: {
            exam: {
              include: {
                course: { select: { name: true, code: true } },
              },
            },
            result: true,
          },
          orderBy: { createdAt: 'desc' },
        }),
        prisma.submission.count({ where: { studentId } }),
      ]);

      return successResponse(res, {
        submissions,
        pagination: {
          page: parseInt(page),
          limit: parseInt(limit),
          total,
          pages: Math.ceil(total / parseInt(limit)),
        },
      });
    } catch (error) {
      console.error('Get my submissions error:', error);
      return errorResponse(res, 'Failed to fetch submissions', 500);
    }
  },

  /**
   * Get submission progress
   */
  async getProgress(req, res) {
    try {
      const { submissionId } = req.params;
      const studentId = req.user.studentId;

      const submission = await prisma.submission.findFirst({
        where: {
          id: submissionId,
          studentId,
        },
        include: {
          exam: {
            include: {
              questions: {
                select: { id: true },
              },
            },
          },
          answers: {
            select: { questionId: true },
          },
        },
      });

      if (!submission) {
        return errorResponse(res, 'Submission not found', 404);
      }

      const totalQuestions = submission.exam.questions.length;
      const answeredQuestions = submission.answers.length;

      return successResponse(res, {
        totalQuestions,
        answeredQuestions,
        progress: totalQuestions > 0 ? Math.round((answeredQuestions / totalQuestions) * 100) : 0,
        answeredQuestionIds: submission.answers.map(a => a.questionId),
      });
    } catch (error) {
      console.error('Get progress error:', error);
      return errorResponse(res, 'Failed to fetch progress', 500);
    }
  },
};

export default SubmissionsController;
