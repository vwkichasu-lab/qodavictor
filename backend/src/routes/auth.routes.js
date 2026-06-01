import { Router } from 'express';
import bcrypt from 'bcrypt';
import jwt from 'jsonwebtoken';
import prisma from '../utils/db.js';
import { successResponse, errorResponse } from '../utils/response.js';

const router = Router();

// Register
router.post('/register', async (req, res) => {
  try {
    const { userId, name, email, password, role, ...profileData } = req.body;

    // Check if user exists
    const existingUser = await prisma.user.findFirst({
      where: {
        OR: [{ userId }, { email }],
      },
    });

    if (existingUser) {
      return errorResponse(res, 'User already exists', 409);
    }

    // Hash password
    const hashedPassword = await bcrypt.hash(password, 10);

    // Create user with role-specific profile
    const user = await prisma.$transaction(async (tx) => {
      const newUser = await tx.user.create({
        data: {
          userId,
          name,
          email,
          password: hashedPassword,
          role,
        },
      });

      // Create role-specific profile
      if (role === 'STUDENT') {
        await tx.student.create({
          data: {
            userId: newUser.id,
            studentId: profileData.studentId || userId,
            level: profileData.level || '100',
            academicYear: profileData.academicYear || new Date().getFullYear().toString(),
            department: profileData.department,
          },
        });
      } else if (role === 'LECTURER') {
        await tx.lecturer.create({
          data: {
            userId: newUser.id,
            lecturerId: profileData.lecturerId || userId,
            department: profileData.department,
            title: profileData.title,
          },
        });
      } else if (role === 'ADMIN') {
        await tx.admin.create({
          data: {
            userId: newUser.id,
            adminId: profileData.adminId || userId,
            superAdmin: profileData.superAdmin || false,
          },
        });
      }

      return newUser;
    });

    return successResponse(res, {
      id: user.id,
      userId: user.userId,
      name: user.name,
      email: user.email,
      role: user.role,
    }, 'User registered successfully', 201);
  } catch (error) {
    console.error('Register error:', error);
    return errorResponse(res, 'Failed to register user', 500);
  }
});

// Login
router.post('/login', async (req, res) => {
  try {
    const { userId, password } = req.body;

    // Find user
    const user = await prisma.user.findFirst({
      where: {
        OR: [{ userId }, { email: userId }],
      },
      include: {
        student: { select: { id: true, level: true, academicYear: true } },
        lecturer: { select: { id: true, department: true } },
        admin: { select: { id: true, superAdmin: true } },
      },
    });

    if (!user) {
      return errorResponse(res, 'Invalid credentials', 401);
    }

    // Check password
    const isValid = await bcrypt.compare(password, user.password);
    if (!isValid) {
      return errorResponse(res, 'Invalid credentials', 401);
    }

    // Generate token
    const token = jwt.sign(
      { userId: user.id, role: user.role },
      process.env.JWT_SECRET,
      { expiresIn: '24h' }
    );

    return successResponse(res, {
      token,
      user: {
        id: user.id,
        userId: user.userId,
        name: user.name,
        email: user.email,
        role: user.role,
        profile: user.student || user.lecturer || user.admin,
      },
    }, 'Login successful');
  } catch (error) {
    console.error('Login error:', error);
    return errorResponse(res, 'Failed to login', 500);
  }
});

// Get current user
router.get('/me', async (req, res) => {
  try {
    const token = req.headers.authorization?.split(' ')[1];
    if (!token) {
      return errorResponse(res, 'Not authenticated', 401);
    }

    const decoded = jwt.verify(token, process.env.JWT_SECRET);
    const user = await prisma.user.findUnique({
      where: { id: decoded.userId },
      include: {
        student: { select: { id: true, studentId: true, level: true, academicYear: true, department: true } },
        lecturer: { select: { id: true, lecturerId: true, department: true, title: true } },
        admin: { select: { id: true, adminId: true, superAdmin: true } },
      },
    });

    if (!user) {
      return errorResponse(res, 'User not found', 404);
    }

    return successResponse(res, {
      id: user.id,
      userId: user.userId,
      name: user.name,
      email: user.email,
      role: user.role,
      profile: user.student || user.lecturer || user.admin,
    });
  } catch (error) {
    console.error('Get me error:', error);
    return errorResponse(res, 'Failed to get user', 500);
  }
});

// Change password
router.post('/change-password', async (req, res) => {
  try {
    const { userId, currentPassword, newPassword } = req.body;

    const user = await prisma.user.findFirst({
      where: { OR: [{ userId }, { email: userId }] },
    });

    if (!user) {
      return errorResponse(res, 'User not found', 404);
    }

    const isValid = await bcrypt.compare(currentPassword, user.password);
    if (!isValid) {
      return errorResponse(res, 'Current password is incorrect', 400);
    }

    const hashedPassword = await bcrypt.hash(newPassword, 10);
    await prisma.user.update({
      where: { id: user.id },
      data: { password: hashedPassword },
    });

    return successResponse(res, null, 'Password changed successfully');
  } catch (error) {
    console.error('Change password error:', error);
    return errorResponse(res, 'Failed to change password', 500);
  }
});

export default router;
