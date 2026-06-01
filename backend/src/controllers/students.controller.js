import bcrypt from 'bcrypt';
import prisma from '../utils/db.js';
import { successResponse, errorResponse } from '../utils/response.js';
import { logAudit, AuditActions } from '../utils/audit.js';

export const StudentsController = {
  /**
   * Create a new student
   */
  async create(req, res) {
    try {
      const {
        userId,
        name,
        email,
        password,
        studentId,
        level,
        academicYear,
        department,
        courseIds,
      } = req.body;

      // Check if user already exists
      const existingUser = await prisma.user.findFirst({
        where: {
          OR: [{ userId }, { email }],
        },
      });

      if (existingUser) {
        return errorResponse(res, 'User with this ID or email already exists', 409);
      }

      // Check if student ID already exists
      const existingStudent = await prisma.student.findUnique({
        where: { studentId },
      });

      if (existingStudent) {
        return errorResponse(res, 'Student ID already exists', 409);
      }

      // Hash password
      const hashedPassword = await bcrypt.hash(password, 10);

      // Create user and student in transaction
      const result = await prisma.$transaction(async (tx) => {
        // Create user
        const user = await tx.user.create({
          data: {
            userId,
            name,
            email,
            password: hashedPassword,
            role: 'STUDENT',
          },
        });

        // Create student profile
        const student = await tx.student.create({
          data: {
            userId: user.id,
            studentId,
            level,
            academicYear,
            department,
          },
          include: {
            user: {
              select: { id: true, userId: true, name: true, email: true },
            },
          },
        });

        // Enroll in courses if provided
        if (courseIds && courseIds.length > 0) {
          await tx.studentCourse.createMany({
            data: courseIds.map(courseId => ({
              studentId: student.id,
              courseId,
            })),
          });
        }

        return student;
      });

      await logAudit(req.user?.id, AuditActions.CREATE_USER, 'Student', result.id, { studentId }, req);

      return successResponse(res, result, 'Student created successfully', 201);
    } catch (error) {
      console.error('Create student error:', error);
      return errorResponse(res, 'Failed to create student', 500);
    }
  },

  /**
   * Get all students with pagination
   */
  async getAll(req, res) {
    try {
      const { page = 1, limit = 20, search, level, academicYear } = req.query;
      const skip = (parseInt(page) - 1) * parseInt(limit);

      const where = {};
      
      if (search) {
        where.user = {
          OR: [
            { name: { contains: search, mode: 'insensitive' } },
            { userId: { contains: search, mode: 'insensitive' } },
            { email: { contains: search, mode: 'insensitive' } },
          ],
        };
      }

      if (level) where.level = level;
      if (academicYear) where.academicYear = academicYear;

      const [students, total] = await Promise.all([
        prisma.student.findMany({
          where,
          skip,
          take: parseInt(limit),
          include: {
            user: {
              select: { id: true, userId: true, name: true, email: true, isActive: true },
            },
            courses: {
              include: {
                course: {
                  select: { id: true, name: true, code: true },
                },
              },
            },
            _count: {
              select: { submissions: true },
            },
          },
          orderBy: { createdAt: 'desc' },
        }),
        prisma.student.count({ where }),
      ]);

      return successResponse(res, {
        students,
        pagination: {
          page: parseInt(page),
          limit: parseInt(limit),
          total,
          pages: Math.ceil(total / parseInt(limit)),
        },
      });
    } catch (error) {
      console.error('Get students error:', error);
      return errorResponse(res, 'Failed to fetch students', 500);
    }
  },

  /**
   * Get a single student
   */
  async getById(req, res) {
    try {
      const { id } = req.params;

      const student = await prisma.student.findUnique({
        where: { id },
        include: {
          user: {
            select: { id: true, userId: true, name: true, email: true, avatar: true, isActive: true },
          },
          courses: {
            include: {
              course: true,
            },
          },
          submissions: {
            include: {
              exam: {
                select: { id: true, title: true, course: { select: { name: true, code: true } } },
              },
              result: true,
            },
            orderBy: { createdAt: 'desc' },
          },
          results: {
            include: {
              exam: {
                select: { title: true, course: { select: { name: true, code: true } } },
              },
            },
          },
        },
      });

      if (!student) {
        return errorResponse(res, 'Student not found', 404);
      }

      return successResponse(res, student);
    } catch (error) {
      console.error('Get student error:', error);
      return errorResponse(res, 'Failed to fetch student', 500);
    }
  },

  /**
   * Update a student
   */
  async update(req, res) {
    try {
      const { id } = req.params;
      const { name, email, level, academicYear, department, isActive, courseIds } = req.body;

      const student = await prisma.student.findUnique({
        where: { id },
        include: { user: true },
      });

      if (!student) {
        return errorResponse(res, 'Student not found', 404);
      }

      const result = await prisma.$transaction(async (tx) => {
        // Update user
        await tx.user.update({
          where: { id: student.userId },
          data: { name, email, isActive },
        });

        // Update student
        const updatedStudent = await tx.student.update({
          where: { id },
          data: { level, academicYear, department },
          include: {
            user: {
              select: { id: true, userId: true, name: true, email: true, isActive: true },
            },
          },
        });

        // Update course enrollments if provided
        if (courseIds) {
          await tx.studentCourse.deleteMany({
            where: { studentId: id },
          });

          if (courseIds.length > 0) {
            await tx.studentCourse.createMany({
              data: courseIds.map(courseId => ({
                studentId: id,
                courseId,
              })),
            });
          }
        }

        return updatedStudent;
      });

      await logAudit(req.user?.id, AuditActions.UPDATE_USER, 'Student', id, null, req);

      return successResponse(res, result, 'Student updated successfully');
    } catch (error) {
      console.error('Update student error:', error);
      return errorResponse(res, 'Failed to update student', 500);
    }
  },

  /**
   * Delete a student
   */
  async delete(req, res) {
    try {
      const { id } = req.params;

      const student = await prisma.student.findUnique({
        where: { id },
      });

      if (!student) {
        return errorResponse(res, 'Student not found', 404);
      }

      await prisma.$transaction(async (tx) => {
        // Delete related records first
        await tx.studentCourse.deleteMany({
          where: { studentId: id },
        });

        await tx.studyGroupMember.deleteMany({
          where: { studentId: id },
        });

        // Delete student (cascades to user)
        await tx.student.delete({
          where: { id },
        });

        await tx.user.delete({
          where: { id: student.userId },
        });
      });

      await logAudit(req.user?.id, AuditActions.DELETE_USER, 'Student', id, null, req);

      return successResponse(res, null, 'Student deleted successfully');
    } catch (error) {
      console.error('Delete student error:', error);
      return errorResponse(res, 'Failed to delete student', 500);
    }
  },

  /**
   * Reset student password
   */
  async resetPassword(req, res) {
    try {
      const { id } = req.params;
      const { newPassword } = req.body;

      const student = await prisma.student.findUnique({
        where: { id },
      });

      if (!student) {
        return errorResponse(res, 'Student not found', 404);
      }

      const hashedPassword = await bcrypt.hash(newPassword, 10);

      await prisma.user.update({
        where: { id: student.userId },
        data: { password: hashedPassword },
      });

      return successResponse(res, null, 'Password reset successfully');
    } catch (error) {
      console.error('Reset password error:', error);
      return errorResponse(res, 'Failed to reset password', 500);
    }
  },

  /**
   * Bulk import students from CSV
   */
  async bulkImport(req, res) {
    try {
      const { students, defaultPassword } = req.body;

      if (!students || !Array.isArray(students) || students.length === 0) {
        return errorResponse(res, 'No students provided', 400);
      }

      const results = {
        success: [],
        failed: [],
      };

      for (const studentData of students) {
        try {
          const {
            userId,
            name,
            email,
            studentId,
            level,
            academicYear,
            department,
          } = studentData;

          // Check if user exists
          const existingUser = await prisma.user.findFirst({
            where: {
              OR: [{ userId }, { email }],
            },
          });

          if (existingUser) {
            results.failed.push({ data: studentData, reason: 'User already exists' });
            continue;
          }

          const hashedPassword = await bcrypt.hash(defaultPassword || studentId, 10);

          await prisma.$transaction(async (tx) => {
            const user = await tx.user.create({
              data: {
                userId,
                name,
                email,
                password: hashedPassword,
                role: 'STUDENT',
              },
            });

            await tx.student.create({
              data: {
                userId: user.id,
                studentId,
                level,
                academicYear,
                department,
              },
            });
          });

          results.success.push(studentData);
        } catch (err) {
          results.failed.push({ data: studentData, reason: err.message });
        }
      }

      await logAudit(req.user?.id, AuditActions.CREATE_USER, 'Student', null, { count: results.success.length }, req);

      return successResponse(res, results, `Imported ${results.success.length} students, ${results.failed.length} failed`);
    } catch (error) {
      console.error('Bulk import error:', error);
      return errorResponse(res, 'Failed to import students', 500);
    }
  },

  /**
   * Export students to CSV format
   */
  async export(req, res) {
    try {
      const { level, academicYear } = req.query;

      const where = {};
      if (level) where.level = level;
      if (academicYear) where.academicYear = academicYear;

      const students = await prisma.student.findMany({
        where,
        include: {
          user: {
            select: { userId: true, name: true, email: true },
          },
          courses: {
            include: {
              course: { select: { code: true, name: true } },
            },
          },
        },
      });

      const exportData = students.map(s => ({
        userId: s.user.userId,
        name: s.user.name,
        email: s.user.email,
        studentId: s.studentId,
        level: s.level,
        academicYear: s.academicYear,
        department: s.department,
        courses: s.courses.map(c => c.course.code).join(', '),
      }));

      return successResponse(res, exportData);
    } catch (error) {
      console.error('Export students error:', error);
      return errorResponse(res, 'Failed to export students', 500);
    }
  },

  /**
   * Get student statistics
   */
  async getStats(req, res) {
    try {
      const { id } = req.params;

      const stats = await prisma.$transaction(async (tx) => {
        const totalExams = await tx.submission.count({
          where: { studentId: id },
        });

        const completedExams = await tx.submission.count({
          where: { 
            studentId: id,
            status: { in: ['SUBMITTED', 'GRADED'] },
          },
        });

        const results = await tx.result.findMany({
          where: { studentId: id },
          select: { percentage: true },
        });

        const averageScore = results.length > 0
          ? results.reduce((sum, r) => sum + r.percentage, 0) / results.length
          : 0;

        return {
          totalExams,
          completedExams,
          averageScore: Math.round(averageScore * 100) / 100,
          totalResults: results.length,
        };
      });

      return successResponse(res, stats);
    } catch (error) {
      console.error('Get student stats error:', error);
      return errorResponse(res, 'Failed to fetch statistics', 500);
    }
  },
};

export default StudentsController;
