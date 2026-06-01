import prisma from '../utils/db.js';

export class SecurityService {
  /**
   * Log a security event
   */
  static async logEvent(data) {
    const {
      studentId,
      userId,
      examId,
      eventType,
      severity,
      description,
      screenshotUrl,
      metadata,
    } = data;

    const event = await prisma.securityEvent.create({
      data: {
        studentId,
        userId,
        examId,
        eventType,
        severity,
        description,
        screenshotUrl,
        metadata,
      },
    });

    // If high severity, notify lecturers/admins in real-time
    if (severity === 'HIGH' || severity === 'CRITICAL') {
      this.notifyAdmins(event);
    }

    return event;
  }

  /**
   * Get security events for an exam
   */
  static async getExamEvents(examId, filters = {}) {
    const { severity, resolved, studentId } = filters;

    const where = { examId };
    if (severity) where.severity = severity;
    if (resolved !== undefined) where.resolved = resolved;
    if (studentId) where.studentId = studentId;

    const events = await prisma.securityEvent.findMany({
      where,
      include: {
        student: {
          include: {
            user: {
              select: { name: true, userId: true },
            },
          },
        },
      },
      orderBy: { createdAt: 'desc' },
    });

    return events;
  }

  /**
   * Get security events for a student
   */
  static async getStudentEvents(studentId, examId = null) {
    const where = { studentId };
    if (examId) where.examId = examId;

    const events = await prisma.securityEvent.findMany({
      where,
      orderBy: { createdAt: 'desc' },
    });

    return events;
  }

  /**
   * Resolve a security event
   */
  static async resolveEvent(eventId, resolvedBy, resolution) {
    const event = await prisma.securityEvent.update({
      where: { id: eventId },
      data: {
        resolved: true,
        resolvedBy,
        resolvedAt: new Date(),
        resolution,
      },
    });

    return event;
  }

  /**
   * Check if student should be locked out
   */
  static async shouldLockStudent(studentId, examId) {
    const recentEvents = await prisma.securityEvent.findMany({
      where: {
        studentId,
        examId,
        resolved: false,
        createdAt: {
          gte: new Date(Date.now() - 30 * 60 * 1000), // Last 30 minutes
        },
      },
    });

    const criticalCount = recentEvents.filter(e => e.severity === 'CRITICAL').length;
    const highCount = recentEvents.filter(e => e.severity === 'HIGH').length;

    // Lock if: 1 critical OR 3+ high severity events
    return criticalCount >= 1 || highCount >= 3;
  }

  /**
   * Get security statistics for an exam
   */
  static async getExamStats(examId) {
    const stats = await prisma.securityEvent.groupBy({
      by: ['eventType', 'severity'],
      where: { examId },
      _count: { id: true },
    });

    const totalEvents = await prisma.securityEvent.count({
      where: { examId },
    });

    const unresolvedEvents = await prisma.securityEvent.count({
      where: { examId, resolved: false },
    });

    return {
      totalEvents,
      unresolvedEvents,
      breakdown: stats,
    };
  }

  /**
   * Notify admins/lecturers of high severity events
   */
  static async notifyAdmins(event) {
    // This would integrate with WebSocket or push notifications
    // For now, just log it
    console.log(`[SECURITY ALERT] ${event.severity}: ${event.description}`);
  }

  /**
   * Validate exam access for student
   */
  static async validateExamAccess(studentId, examId) {
    const submission = await prisma.submission.findUnique({
      where: {
        examId_studentId: {
          examId,
          studentId,
        },
      },
    });

    if (submission?.status === 'SUBMITTED' || submission?.status === 'GRADED') {
      return {
        allowed: false,
        reason: 'Exam already submitted',
      };
    }

    // Check for lock status
    const isLocked = await this.shouldLockStudent(studentId, examId);
    if (isLocked) {
      return {
        allowed: false,
        reason: 'Account locked due to security violations',
      };
    }

    return {
      allowed: true,
      submission,
    };
  }

  /**
   * Record a violation from the client
   */
  static async recordViolation(data) {
    const {
      studentId,
      examId,
      violationType,
      details,
    } = data;

    const severityMap = {
      'TAB_SWITCH': 'MEDIUM',
      'COPY_ATTEMPT': 'HIGH',
      'PASTE_ATTEMPT': 'HIGH',
      'RIGHT_CLICK': 'MEDIUM',
      'DEVTOOLS_OPEN': 'CRITICAL',
      'FULLSCREEN_EXIT': 'HIGH',
      'MOUSE_LEAVE': 'LOW',
      'MULTIPLE_FACES': 'CRITICAL',
      'SUSPICIOUS_ACTIVITY': 'HIGH',
      'TIMEOUT_WARNING': 'LOW',
    };

    const event = await this.logEvent({
      studentId,
      examId,
      eventType: violationType,
      severity: severityMap[violationType] || 'MEDIUM',
      description: details?.message || `${violationType} detected`,
      metadata: details,
    });

    // Check if student should be locked
    const shouldLock = await this.shouldLockStudent(studentId, examId);

    return {
      event,
      shouldLock,
      warningCount: await this.getWarningCount(studentId, examId),
    };
  }

  /**
   * Get warning count for student in exam
   */
  static async getWarningCount(studentId, examId) {
    return await prisma.securityEvent.count({
      where: {
        studentId,
        examId,
        resolved: false,
      },
    });
  }
}

export default SecurityService;
