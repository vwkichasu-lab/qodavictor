import { Router } from 'express';
import SecurityController from '../controllers/security.controller.js';
import { authenticate, authorize } from '../middleware/auth.middleware.js';

const router = Router();

router.use(authenticate);

// Student routes - log violations
router.post('/log', authorize(['STUDENT']), SecurityController.logEvent);
router.post('/violation', authorize(['STUDENT']), SecurityController.recordViolation);
router.get('/lock-status/:examId', authorize(['STUDENT']), SecurityController.checkLockStatus);

// Lecturer/Admin routes
router.get('/events/:examId', authorize(['LECTURER', 'ADMIN']), SecurityController.getExamEvents);
router.get('/events/student/:studentId', SecurityController.getStudentEvents);
router.post('/events/:eventId/resolve', authorize(['LECTURER', 'ADMIN']), SecurityController.resolveEvent);
router.get('/stats/:examId', authorize(['LECTURER', 'ADMIN']), SecurityController.getExamStats);
router.post('/lock/:studentId/:examId', authorize(['LECTURER', 'ADMIN']), SecurityController.lockStudent);
router.post('/unlock/:studentId/:examId', authorize(['LECTURER', 'ADMIN']), SecurityController.unlockStudent);

export default router;
