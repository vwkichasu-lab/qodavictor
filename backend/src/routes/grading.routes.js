import { Router } from 'express';
import GradingController from '../controllers/grading.controller.js';
import { authenticate, authorize } from '../middleware/auth.middleware.js';

const router = Router();

router.use(authenticate);
router.use(authorize(['LECTURER', 'ADMIN']));

// Auto-grading
router.post('/auto/:submissionId', GradingController.autoGrade);
router.post('/auto-all/:examId', GradingController.autoGradeAll);

// Manual grading
router.post('/manual/:answerId', GradingController.manualGrade);
router.get('/pending/:examId', GradingController.getPendingReview);

// Marking schemes
router.get('/scheme/:examId', GradingController.getMarkingScheme);
router.put('/scheme/:questionId', GradingController.updateMarkingScheme);

export default router;
