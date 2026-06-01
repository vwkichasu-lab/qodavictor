import prisma from '../utils/db.js';
import { successResponse, errorResponse } from '../utils/response.js';
import { logAudit, AuditActions } from '../utils/audit.js';

export const ExamsController = {
  /**
   * Create a new exam
   */
  async create(req, res) {
    try {
      const {
        title,
        description,
        instructions,
        durationMins,
        startAt,
        endAt,
        academicYear,
        semester,
        courseId,
        questions,
      } = req.body;

      const lecturerId = req.user.lecturerId;

      const exam = await prisma.exam.create({
        data: {
          title,
          description,
          instructions,
          durationMins: parseInt(durationMins),
          startAt: startAt ? new Date(startAt) : null,
          endAt: endAt ? new Date(endAt) : null,
          academicYear,
          semester,
          courseId,
          lecturerId,
          status: 'DRAFT',
          questions: {
            create: questions?.map((q, index) => ({
              type: q.type,
              text: q.text,
              marks: q.marks || 10,
              order: index,
              imageUrl: q.imageUrl,
              options: q.options,
              correctAnswer: q.correctAnswer,
              starterCode: q.starterCode,
              testCases: q.testCases,
              rubric: q.rubric,
              keywords: q.keywords,
            })) || [],
          },
        },
        include: {
          questions: true,
          course: true,
        },
      });

      await logAudit(req.user.id, AuditActions.CREATE_EXAM, 'Exam', exam.id, null, req);

      return successResponse(res, exam, 'Exam created successfully', 201);
    } catch (error) {
      console.error('Create exam error:', error);
      return errorResponse(res, 'Failed to create exam', 500);
    }
  },

  /**
   * Get all exams with filters
   */
  async getAll(req, res) {
    try {
      const { 
        page = 1, 
        limit = 20, 
        status, 
        courseId, 
        academicYear,
        published,
        search,
      } = req.query;
      
      const skip = (parseInt(page) - 1) * parseInt(limit);

      const where = {};
      
      if (status) where.status = status;
      if (courseId) where.courseId = courseId;
      if (academicYear) where.academicYear = academicYear;
      if (published !== undefined) where.published = published === 'true';
      
      if (search) {
        where.OR = [
          { title: { contains: search, mode: 'insensitive' } },
          { description: { contains: search, mode: 'insensitive' } },
        ];
      }

      // If lecturer, only show their exams
      if (req.user.role === 'LECTURER') {
        where.lecturerId = req.user.lecturerId;
      }

      const [exams, total] = await Promise.all([
        prisma.exam.findMany({
          where,
          skip,
          take: parseInt(limit),
          include: {
            course: {
              select: { id: true, name: true, code: true },
            },
            lecturer: {
              include: {
                user: {
                  select: { name: true },
                },
              },
            },
            _count: {
              select: { questions: true, submissions: true },
            },
          },
          orderBy: { createdAt: 'desc' },
        }),
        prisma.exam.count({ where }),
      ]);

      return successResponse(res, {
        exams,
        pagination: {
          page: parseInt(page),
          limit: parseInt(limit),
          total,
          pages: Math.ceil(total / parseInt(limit)),
        },
      });
    } catch (error) {
      console.error('Get exams error:', error);
      return errorResponse(res, 'Failed to fetch exams', 500);
    }
  },

  /**
   * Get a single exam with all details
   */
  async getById(req, res) {
    try {
      const { id } = req.params;
      const { includeQuestions = true } = req.query;

      const exam = await prisma.exam.findUnique({
        where: { id },
        include: {
          course: true,
          lecturer: {
            include: {
              user: {
                select: { name: true, email: true },
              },
            },
          },
          questions: includeQuestions === 'true' ? {
            orderBy: { order: 'asc' },
          } : false,
          _count: {
            select: { submissions: true },
          },
        },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      // If student, filter out sensitive data
      if (req.user.role === 'STUDENT') {
        const studentExam = {
          ...exam,
          questions: exam.questions?.map(q => ({
            id: q.id,
            type: q.type,
            text: q.text,
            marks: q.marks,
            order: q.order,
            imageUrl: q.imageUrl,
            // Remove correct answers and marking schemes
            options: q.options?.map(opt => ({
              id: opt.id,
              text: opt.text,
              // Don't include isCorrect
            })),
            starterCode: q.starterCode,
          })),
        };
        return successResponse(res, studentExam);
      }

      return successResponse(res, exam);
    } catch (error) {
      console.error('Get exam error:', error);
      return errorResponse(res, 'Failed to fetch exam', 500);
    }
  },

  /**
   * Update an exam
   */
  async update(req, res) {
    try {
      const { id } = req.params;
      const {
        title,
        description,
        instructions,
        durationMins,
        startAt,
        endAt,
        academicYear,
        semester,
        courseId,
        status,
      } = req.body;

      const exam = await prisma.exam.findUnique({
        where: { id },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized to update this exam', 403);
      }

      const updatedExam = await prisma.exam.update({
        where: { id },
        data: {
          title,
          description,
          instructions,
          durationMins: durationMins ? parseInt(durationMins) : undefined,
          startAt: startAt ? new Date(startAt) : undefined,
          endAt: endAt ? new Date(endAt) : undefined,
          academicYear,
          semester,
          courseId,
          status,
        },
        include: {
          course: true,
          questions: {
            orderBy: { order: 'asc' },
          },
        },
      });

      await logAudit(req.user.id, AuditActions.UPDATE_EXAM, 'Exam', id, null, req);

      return successResponse(res, updatedExam, 'Exam updated successfully');
    } catch (error) {
      console.error('Update exam error:', error);
      return errorResponse(res, 'Failed to update exam', 500);
    }
  },

  /**
   * Delete an exam
   */
  async delete(req, res) {
    try {
      const { id } = req.params;

      const exam = await prisma.exam.findUnique({
        where: { id },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized to delete this exam', 403);
      }

      await prisma.exam.delete({
        where: { id },
      });

      await logAudit(req.user.id, AuditActions.DELETE_EXAM, 'Exam', id, null, req);

      return successResponse(res, null, 'Exam deleted successfully');
    } catch (error) {
      console.error('Delete exam error:', error);
      return errorResponse(res, 'Failed to delete exam', 500);
    }
  },

  /**
   * Publish/unpublish an exam
   */
  async togglePublish(req, res) {
    try {
      const { id } = req.params;
      const { publish } = req.body;

      const exam = await prisma.exam.findUnique({
        where: { id },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      const updatedExam = await prisma.exam.update({
        where: { id },
        data: {
          published: publish,
          status: publish ? 'PUBLISHED' : 'DRAFT',
        },
      });

      await logAudit(
        req.user.id,
        publish ? AuditActions.PUBLISH_EXAM : AuditActions.UNPUBLISH_EXAM,
        'Exam',
        id,
        null,
        req
      );

      return successResponse(
        res,
        updatedExam,
        publish ? 'Exam published successfully' : 'Exam unpublished'
      );
    } catch (error) {
      console.error('Toggle publish error:', error);
      return errorResponse(res, 'Failed to update exam status', 500);
    }
  },

  /**
   * Add a question to an exam
   */
  async addQuestion(req, res) {
    try {
      const { id } = req.params;
      const questionData = req.body;

      const exam = await prisma.exam.findUnique({
        where: { id },
        include: { questions: true },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      const question = await prisma.question.create({
        data: {
          examId: id,
          type: questionData.type,
          text: questionData.text,
          marks: questionData.marks || 10,
          order: exam.questions.length,
          imageUrl: questionData.imageUrl,
          options: questionData.options,
          correctAnswer: questionData.correctAnswer,
          starterCode: questionData.starterCode,
          testCases: questionData.testCases,
          rubric: questionData.rubric,
          keywords: questionData.keywords,
        },
      });

      return successResponse(res, question, 'Question added successfully', 201);
    } catch (error) {
      console.error('Add question error:', error);
      return errorResponse(res, 'Failed to add question', 500);
    }
  },

  /**
   * Update a question
   */
  async updateQuestion(req, res) {
    try {
      const { id, questionId } = req.params;
      const questionData = req.body;

      const question = await prisma.question.update({
        where: { id: questionId },
        data: {
          type: questionData.type,
          text: questionData.text,
          marks: questionData.marks,
          imageUrl: questionData.imageUrl,
          options: questionData.options,
          correctAnswer: questionData.correctAnswer,
          starterCode: questionData.starterCode,
          testCases: questionData.testCases,
          rubric: questionData.rubric,
          keywords: questionData.keywords,
        },
      });

      return successResponse(res, question, 'Question updated successfully');
    } catch (error) {
      console.error('Update question error:', error);
      return errorResponse(res, 'Failed to update question', 500);
    }
  },

  /**
   * Delete a question
   */
  async deleteQuestion(req, res) {
    try {
      const { id, questionId } = req.params;

      await prisma.question.delete({
        where: { id: questionId },
      });

      return successResponse(res, null, 'Question deleted successfully');
    } catch (error) {
      console.error('Delete question error:', error);
      return errorResponse(res, 'Failed to delete question', 500);
    }
  },

  /**
   * Reorder questions
   */
  async reorderQuestions(req, res) {
    try {
      const { id } = req.params;
      const { questionOrders } = req.body;

      await prisma.$transaction(
        questionOrders.map(({ questionId, order }) =>
          prisma.question.update({
            where: { id: questionId },
            data: { order },
          })
        )
      );

      return successResponse(res, null, 'Questions reordered successfully');
    } catch (error) {
      console.error('Reorder questions error:', error);
      return errorResponse(res, 'Failed to reorder questions', 500);
    }
  },

  /**
   * Get exam statistics
   */
  async getStats(req, res) {
    try {
      const { id } = req.params;

      const stats = await prisma.$transaction(async (tx) => {
        const totalSubmissions = await tx.submission.count({
          where: { examId: id },
        });

        const submitted = await tx.submission.count({
          where: { 
            examId: id,
            status: { in: ['SUBMITTED', 'GRADED'] },
          },
        });

        const inProgress = await tx.submission.count({
          where: { 
            examId: id,
            status: 'IN_PROGRESS',
          },
        });

        const results = await tx.result.findMany({
          where: { examId: id },
          select: { percentage: true },
        });

        const averageScore = results.length > 0
          ? results.reduce((sum, r) => sum + r.percentage, 0) / results.length
          : 0;

        const passCount = results.filter(r => r.percentage >= 50).length;

        return {
          totalSubmissions,
          submitted,
          inProgress,
          averageScore: Math.round(averageScore * 100) / 100,
          passRate: results.length > 0 ? Math.round((passCount / results.length) * 100) : 0,
          totalGraded: results.length,
        };
      });

      return successResponse(res, stats);
    } catch (error) {
      console.error('Get exam stats error:', error);
      return errorResponse(res, 'Failed to fetch statistics', 500);
    }
  },

  /**
   * Get upcoming exams for a student
   */
  async getUpcoming(req, res) {
    try {
      const studentId = req.user.studentId;
      const now = new Date();

      const student = await prisma.student.findUnique({
        where: { id: studentId },
        include: {
          courses: {
            select: { courseId: true },
          },
        },
      });

      const courseIds = student.courses.map(c => c.courseId);

      const exams = await prisma.exam.findMany({
        where: {
          courseId: { in: courseIds },
          published: true,
          startAt: { gte: now },
          status: { in: ['PUBLISHED', 'ONGOING'] },
        },
        include: {
          course: {
            select: { name: true, code: true },
          },
          _count: {
            select: { questions: true },
          },
        },
        orderBy: { startAt: 'asc' },
      });

      return successResponse(res, exams);
    } catch (error) {
      console.error('Get upcoming exams error:', error);
      return errorResponse(res, 'Failed to fetch upcoming exams', 500);
    }
  },

  /**
   * Get available exams for a student (that they can take)
   */
  async getAvailable(req, res) {
    try {
      const studentId = req.user.studentId;
      const now = new Date();

      const student = await prisma.student.findUnique({
        where: { id: studentId },
        include: {
          courses: {
            select: { courseId: true },
          },
        },
      });

      const courseIds = student.courses.map(c => c.courseId);

      // Get exams with their submission status
      const exams = await prisma.exam.findMany({
        where: {
          courseId: { in: courseIds },
          published: true,
        },
        include: {
          course: {
            select: { name: true, code: true },
          },
          submissions: {
            where: { studentId },
            select: { id: true, status: true, submittedAt: true },
          },
          _count: {
            select: { questions: true },
          },
        },
        orderBy: { startAt: 'desc' },
      });

      // Format with status
      const formattedExams = exams.map(exam => ({
        ...exam,
        submission: exam.submissions[0] || null,
        submissions: undefined,
      }));

      return successResponse(res, formattedExams);
    } catch (error) {
      console.error('Get available exams error:', error);
      return errorResponse(res, 'Failed to fetch available exams', 500);
    }
  },
};

export default ExamsController;
