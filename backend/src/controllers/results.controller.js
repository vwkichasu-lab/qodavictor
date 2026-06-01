import prisma from '../utils/db.js';
import { successResponse, errorResponse } from '../utils/response.js';
import { logAudit, AuditActions } from '../utils/audit.js';

export const ResultsController = {
  /**
   * Publish results for an exam
   */
  async publish(req, res) {
    try {
      const { examId } = req.params;

      const exam = await prisma.exam.findUnique({
        where: { id: examId },
        include: {
          results: true,
        },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      // Update all results to published
      await prisma.result.updateMany({
        where: { examId },
        data: {
          published: true,
          publishedAt: new Date(),
          publishedBy: req.user.id,
        },
      });

      // Update exam
      await prisma.exam.update({
        where: { id: examId },
        data: { resultsPublished: true },
      });

      await logAudit(req.user.id, AuditActions.PUBLISH_RESULTS, 'Exam', examId, null, req);

      return successResponse(res, null, 'Results published successfully');
    } catch (error) {
      console.error('Publish results error:', error);
      return errorResponse(res, 'Failed to publish results', 500);
    }
  },

  /**
   * Unpublish results
   */
  async unpublish(req, res) {
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

      await prisma.result.updateMany({
        where: { examId },
        data: {
          published: false,
          publishedAt: null,
          publishedBy: null,
        },
      });

      await prisma.exam.update({
        where: { id: examId },
        data: { resultsPublished: false },
      });

      return successResponse(res, null, 'Results unpublished');
    } catch (error) {
      console.error('Unpublish results error:', error);
      return errorResponse(res, 'Failed to unpublish results', 500);
    }
  },

  /**
   * Get results for an exam (lecturer view)
   */
  async getByExam(req, res) {
    try {
      const { examId } = req.params;
      const { page = 1, limit = 50, search } = req.query;
      const skip = (parseInt(page) - 1) * parseInt(limit);

      const exam = await prisma.exam.findUnique({
        where: { id: examId },
        include: { course: true },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      const where = { examId };

      const [results, total] = await Promise.all([
        prisma.result.findMany({
          where,
          skip,
          take: parseInt(limit),
          include: {
            student: {
              include: {
                user: { select: { name: true, userId: true } },
              },
            },
            submission: {
              select: { submittedAt: true, autoSubmitted: true },
            },
          },
          orderBy: { percentage: 'desc' },
        }),
        prisma.result.count({ where }),
      ]);

      // Calculate statistics
      const stats = {
        total: results.length,
        average: results.length > 0 
          ? Math.round(results.reduce((sum, r) => sum + r.percentage, 0) / results.length * 100) / 100
          : 0,
        highest: results.length > 0 ? Math.max(...results.map(r => r.percentage)) : 0,
        lowest: results.length > 0 ? Math.min(...results.map(r => r.percentage)) : 0,
        passCount: results.filter(r => r.percentage >= 50).length,
      };

      return successResponse(res, {
        exam: {
          id: exam.id,
          title: exam.title,
          course: exam.course,
          academicYear: exam.academicYear,
        },
        results,
        stats,
        pagination: {
          page: parseInt(page),
          limit: parseInt(limit),
          total,
          pages: Math.ceil(total / parseInt(limit)),
        },
      });
    } catch (error) {
      console.error('Get results error:', error);
      return errorResponse(res, 'Failed to fetch results', 500);
    }
  },

  /**
   * Get my results (student view)
   */
  async getMyResults(req, res) {
    try {
      const studentId = req.user.studentId;
      const { page = 1, limit = 20 } = req.query;
      const skip = (parseInt(page) - 1) * parseInt(limit);

      const [results, total] = await Promise.all([
        prisma.result.findMany({
          where: {
            studentId,
            published: true,
          },
          skip,
          take: parseInt(limit),
          include: {
            exam: {
              include: {
                course: { select: { name: true, code: true } },
              },
            },
          },
          orderBy: { createdAt: 'desc' },
        }),
        prisma.result.count({
          where: {
            studentId,
            published: true,
          },
        }),
      ]);

      // Calculate overall statistics
      const allResults = await prisma.result.findMany({
        where: { studentId, published: true },
        select: { percentage: true },
      });

      const stats = {
        totalExams: allResults.length,
        averageScore: allResults.length > 0
          ? Math.round(allResults.reduce((sum, r) => sum + r.percentage, 0) / allResults.length * 100) / 100
          : 0,
        highestScore: allResults.length > 0 ? Math.max(...allResults.map(r => r.percentage)) : 0,
        lowestScore: allResults.length > 0 ? Math.min(...allResults.map(r => r.percentage)) : 0,
      };

      return successResponse(res, {
        results,
        stats,
        pagination: {
          page: parseInt(page),
          limit: parseInt(limit),
          total,
          pages: Math.ceil(total / parseInt(limit)),
        },
      });
    } catch (error) {
      console.error('Get my results error:', error);
      return errorResponse(res, 'Failed to fetch results', 500);
    }
  },

  /**
   * Get detailed result for a submission
   */
  async getDetail(req, res) {
    try {
      const { resultId } = req.params;

      const result = await prisma.result.findUnique({
        where: { id: resultId },
        include: {
          exam: {
            include: {
              course: true,
              questions: {
                orderBy: { order: 'asc' },
              },
            },
          },
          student: {
            include: {
              user: { select: { name: true, userId: true } },
            },
          },
          submission: {
            include: {
              answers: {
                include: { question: true },
              },
            },
          },
        },
      });

      if (!result) {
        return errorResponse(res, 'Result not found', 404);
      }

      // Check permissions
      if (req.user.role === 'STUDENT') {
        if (result.studentId !== req.user.studentId) {
          return errorResponse(res, 'Not authorized', 403);
        }
        if (!result.published) {
          return errorResponse(res, 'Results not yet published', 403);
        }
      }

      if (req.user.role === 'LECTURER' && result.exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      return successResponse(res, result);
    } catch (error) {
      console.error('Get result detail error:', error);
      return errorResponse(res, 'Failed to fetch result details', 500);
    }
  },

  /**
   * Export results to CSV
   */
  async exportCSV(req, res) {
    try {
      const { examId } = req.params;

      const exam = await prisma.exam.findUnique({
        where: { id: examId },
        include: { course: true },
      });

      if (!exam) {
        return errorResponse(res, 'Exam not found', 404);
      }

      // Check permissions
      if (req.user.role === 'LECTURER' && exam.lecturerId !== req.user.lecturerId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      const results = await prisma.result.findMany({
        where: { examId },
        include: {
          student: {
            include: {
              user: { select: { name: true, userId: true } },
            },
          },
          submission: {
            select: { submittedAt: true },
          },
        },
        orderBy: { percentage: 'desc' },
      });

      // Format for CSV
      const csvData = results.map(r => ({
        'Course Name': exam.course.name,
        'Course Code': exam.course.code,
        'Exam Title': exam.title,
        'Academic Year': exam.academicYear,
        'Student ID': r.student.user.userId,
        'Student Name': r.student.user.name,
        'Score': r.totalScore,
        'Max Score': r.maxScore,
        'Percentage': r.percentage + '%',
        'Grade': r.grade,
        'Submitted At': r.submission?.submittedAt?.toISOString() || '',
      }));

      return successResponse(res, csvData);
    } catch (error) {
      console.error('Export CSV error:', error);
      return errorResponse(res, 'Failed to export results', 500);
    }
  },

  /**
   * Get result statistics
   */
  async getStatistics(req, res) {
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

      const results = await prisma.result.findMany({
        where: { examId },
        select: { percentage: true, grade: true },
      });

      // Calculate grade distribution
      const gradeDistribution = {
        A: 0, B: 0, C: 0, D: 0, F: 0,
      };
      results.forEach(r => {
        gradeDistribution[r.grade]++;
      });

      // Calculate score ranges
      const ranges = {
        '90-100': 0,
        '80-89': 0,
        '70-79': 0,
        '60-69': 0,
        '50-59': 0,
        'Below 50': 0,
      };
      results.forEach(r => {
        if (r.percentage >= 90) ranges['90-100']++;
        else if (r.percentage >= 80) ranges['80-89']++;
        else if (r.percentage >= 70) ranges['70-79']++;
        else if (r.percentage >= 60) ranges['60-69']++;
        else if (r.percentage >= 50) ranges['50-59']++;
        else ranges['Below 50']++;
      });

      const stats = {
        total: results.length,
        average: results.length > 0
          ? Math.round(results.reduce((sum, r) => sum + r.percentage, 0) / results.length * 100) / 100
          : 0,
        median: results.length > 0
          ? results.sort((a, b) => a.percentage - b.percentage)[Math.floor(results.length / 2)].percentage
          : 0,
        highest: results.length > 0 ? Math.max(...results.map(r => r.percentage)) : 0,
        lowest: results.length > 0 ? Math.min(...results.map(r => r.percentage)) : 0,
        passRate: results.length > 0
          ? Math.round((results.filter(r => r.percentage >= 50).length / results.length) * 100)
          : 0,
        gradeDistribution,
        scoreRanges: ranges,
      };

      return successResponse(res, stats);
    } catch (error) {
      console.error('Get statistics error:', error);
      return errorResponse(res, 'Failed to fetch statistics', 500);
    }
  },
};

export default ResultsController;
