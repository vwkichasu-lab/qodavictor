import { Router } from 'express';
import prisma from '../utils/db.js';
import { successResponse, errorResponse } from '../utils/response.js';
import { authenticate, authorize } from '../middleware/auth.middleware.js';

const router = Router();

router.use(authenticate);
router.use(authorize(['ADMIN']));

// Get audit logs
router.get('/', async (req, res) => {
  try {
    const { page = 1, limit = 50, action, userId, entityType } = req.query;
    const skip = (parseInt(page) - 1) * parseInt(limit);

    const where = {};
    if (action) where.action = action;
    if (userId) where.userId = userId;
    if (entityType) where.entityType = entityType;

    const [logs, total] = await Promise.all([
      prisma.auditLog.findMany({
        where,
        skip,
        take: parseInt(limit),
        include: {
          user: {
            select: { name: true, userId: true, role: true },
          },
        },
        orderBy: { createdAt: 'desc' },
      }),
      prisma.auditLog.count({ where }),
    ]);

    return successResponse(res, {
      logs,
      pagination: {
        page: parseInt(page),
        limit: parseInt(limit),
        total,
        pages: Math.ceil(total / parseInt(limit)),
      },
    });
  } catch (error) {
    console.error('Get audit logs error:', error);
    return errorResponse(res, 'Failed to fetch audit logs', 500);
  }
});

// Get recent activity
router.get('/recent', async (req, res) => {
  try {
    const { limit = 20 } = req.query;

    const logs = await prisma.auditLog.findMany({
      take: parseInt(limit),
      include: {
        user: {
          select: { name: true, userId: true, role: true },
        },
      },
      orderBy: { createdAt: 'desc' },
    });

    return successResponse(res, logs);
  } catch (error) {
    console.error('Get recent activity error:', error);
    return errorResponse(res, 'Failed to fetch recent activity', 500);
  }
});

// Get activity statistics
router.get('/stats', async (req, res) => {
  try {
    const { startDate, endDate } = req.query;
    
    const dateFilter = {};
    if (startDate || endDate) {
      dateFilter.createdAt = {};
      if (startDate) dateFilter.createdAt.gte = new Date(startDate);
      if (endDate) dateFilter.createdAt.lte = new Date(endDate);
    }

    const stats = await Promise.all([
      // Action breakdown
      prisma.auditLog.groupBy({
        by: ['action'],
        where: dateFilter,
        _count: { id: true },
      }),
      // Daily activity
      prisma.$queryRaw`
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM "AuditLog"
        ${startDate || endDate ? prisma.$queryRaw`WHERE ${dateFilter.createdAt ? prisma.$queryRaw`created_at >= ${new Date(startDate)} AND created_at <= ${new Date(endDate)}` : ''}` : ''}
        GROUP BY DATE(created_at)
        ORDER BY date DESC
        LIMIT 30
      `,
    ]);

    return successResponse(res, {
      actionBreakdown: stats[0],
      dailyActivity: stats[1],
    });
  } catch (error) {
    console.error('Get audit stats error:', error);
    return errorResponse(res, 'Failed to fetch statistics', 500);
  }
});

export default router;
