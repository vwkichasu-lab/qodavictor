import SecurityService from '../services/security.service.js';
import { successResponse, errorResponse } from '../utils/response.js';
import { logAudit, AuditActions } from '../utils/audit.js';

export const SecurityController = {
  /**
   * Log a security event
   */
  async logEvent(req, res) {
    try {
      const { examId, eventType, severity, description, metadata } = req.body;
      const studentId = req.user.studentId;
      const userId = req.user.id;

      const event = await SecurityService.logEvent({
        studentId,
        userId,
        examId,
        eventType,
        severity,
        description,
        metadata,
      });

      // Check if student should be locked
      const shouldLock = await SecurityService.shouldLockStudent(studentId, examId);

      return successResponse(res, {
        event,
        shouldLock,
      }, 'Event logged');
    } catch (error) {
      console.error('Log security event error:', error);
      return errorResponse(res, 'Failed to log event', 500);
    }
  },

  /**
   * Record a violation
   */
  async recordViolation(req, res) {
    try {
      const { examId, violationType, details } = req.body;
      const studentId = req.user.studentId;

      const result = await SecurityService.recordViolation({
        studentId,
        examId,
        violationType,
        details,
      });

      return successResponse(res, result, 'Violation recorded');
    } catch (error) {
      console.error('Record violation error:', error);
      return errorResponse(res, 'Failed to record violation', 500);
    }
  },

  /**
   * Get security events for an exam
   */
  async getExamEvents(req, res) {
    try {
      const { examId } = req.params;
      const { severity, resolved, studentId } = req.query;

      const events = await SecurityService.getExamEvents(examId, {
        severity,
        resolved: resolved !== undefined ? resolved === 'true' : undefined,
        studentId,
      });

      return successResponse(res, events);
    } catch (error) {
      console.error('Get exam events error:', error);
      return errorResponse(res, 'Failed to fetch events', 500);
    }
  },

  /**
   * Get security events for a student
   */
  async getStudentEvents(req, res) {
    try {
      const { studentId } = req.params;
      const { examId } = req.query;

      // Check permissions
      if (req.user.role === 'STUDENT' && req.user.studentId !== studentId) {
        return errorResponse(res, 'Not authorized', 403);
      }

      const events = await SecurityService.getStudentEvents(studentId, examId);

      return successResponse(res, events);
    } catch (error) {
      console.error('Get student events error:', error);
      return errorResponse(res, 'Failed to fetch events', 500);
    }
  },

  /**
   * Resolve a security event
   */
  async resolveEvent(req, res) {
    try {
      const { eventId } = req.params;
      const { resolution } = req.body;

      const event = await SecurityService.resolveEvent(
        eventId,
        req.user.id,
        resolution
      );

      await logAudit(req.user.id, AuditActions.RELEASE_SCREEN, 'SecurityEvent', eventId, null, req);

      return successResponse(res, event, 'Event resolved');
    } catch (error) {
      console.error('Resolve event error:', error);
      return errorResponse(res, 'Failed to resolve event', 500);
    }
  },

  /**
   * Get security statistics for an exam
   */
  async getExamStats(req, res) {
    try {
      const { examId } = req.params;

      const stats = await SecurityService.getExamStats(examId);

      return successResponse(res, stats);
    } catch (error) {
      console.error('Get exam stats error:', error);
      return errorResponse(res, 'Failed to fetch statistics', 500);
    }
  },

  /**
   * Check if student is locked
   */
  async checkLockStatus(req, res) {
    try {
      const { examId } = req.params;
      const studentId = req.user.studentId;

      const isLocked = await SecurityService.shouldLockStudent(studentId, examId);
      const warningCount = await SecurityService.getWarningCount(studentId, examId);

      return successResponse(res, {
        isLocked,
        warningCount,
      });
    } catch (error) {
      console.error('Check lock status error:', error);
      return errorResponse(res, 'Failed to check lock status', 500);
    }
  },

  /**
   * Lock a student (admin/lecturer action)
   */
  async lockStudent(req, res) {
    try {
      const { studentId, examId } = req.params;
      const { reason } = req.body;

      const event = await SecurityService.logEvent({
        studentId,
        examId,
        eventType: 'SUSPICIOUS_ACTIVITY',
        severity: 'CRITICAL',
        description: reason || 'Manual lock by administrator',
      });

      await logAudit(req.user.id, AuditActions.LOCK_SCREEN, 'Student', studentId, { examId }, req);

      return successResponse(res, event, 'Student locked');
    } catch (error) {
      console.error('Lock student error:', error);
      return errorResponse(res, 'Failed to lock student', 500);
    }
  },

  /**
   * Unlock a student
   */
  async unlockStudent(req, res) {
    try {
      const { studentId, examId } = req.params;

      // Resolve all unresolved events for this student/exam
      const events = await SecurityService.getStudentEvents(studentId, examId);
      
      for (const event of events.filter(e => !e.resolved)) {
        await SecurityService.resolveEvent(
          event.id,
          req.user.id,
          'Manually unlocked by administrator'
        );
      }

      await logAudit(req.user.id, AuditActions.RELEASE_SCREEN, 'Student', studentId, { examId }, req);

      return successResponse(res, null, 'Student unlocked');
    } catch (error) {
      console.error('Unlock student error:', error);
      return errorResponse(res, 'Failed to unlock student', 500);
    }
  },
};

export default SecurityController;
