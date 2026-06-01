import prisma from './db.js';

export const logAudit = async (userId, action, entityType, entityId = null, details = null, req = null) => {
  try {
    await prisma.auditLog.create({
      data: {
        userId,
        action,
        entityType,
        entityId,
        details,
        ipAddress: req?.ip,
        userAgent: req?.headers['user-agent'],
      },
    });
  } catch (error) {
    console.error('Audit log error:', error);
  }
};

export const AuditActions = {
  // User actions
  CREATE_USER: 'CREATE_USER',
  UPDATE_USER: 'UPDATE_USER',
  DELETE_USER: 'DELETE_USER',
  LOGIN: 'LOGIN',
  LOGOUT: 'LOGOUT',
  
  // Exam actions
  CREATE_EXAM: 'CREATE_EXAM',
  UPDATE_EXAM: 'UPDATE_EXAM',
  DELETE_EXAM: 'DELETE_EXAM',
  PUBLISH_EXAM: 'PUBLISH_EXAM',
  UNPUBLISH_EXAM: 'UNPUBLISH_EXAM',
  
  // Submission actions
  START_EXAM: 'START_EXAM',
  SUBMIT_EXAM: 'SUBMIT_EXAM',
  AUTO_SUBMIT: 'AUTO_SUBMIT',
  
  // Grading actions
  AUTO_GRADE: 'AUTO_GRADE',
  MANUAL_GRADE: 'MANUAL_GRADE',
  PUBLISH_RESULTS: 'PUBLISH_RESULTS',
  
  // Security actions
  SECURITY_VIOLATION: 'SECURITY_VIOLATION',
  LOCK_SCREEN: 'LOCK_SCREEN',
  RELEASE_SCREEN: 'RELEASE_SCREEN',
};
