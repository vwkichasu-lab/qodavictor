import { Router } from 'express';
import SubmissionsController from '../controllers/submissions.controller.js';
import { authenticate, authorize } from '../middleware/auth.middleware.js';

const router = Router();

router.use(authenticate);

// Student routes
router.post('/exam/:examId/start', authorize(['STUDENT']), SubmissionsController.startExam);
router.post('/:submissionId/answer', authorize(['STUDENT']), SubmissionsController.saveAnswer);
router.post('/:submissionId/submit', authorize(['STUDENT']), SubmissionsController.submitExam);
router.get('/my-submissions', authorize(['STUDENT']), SubmissionsController.getMySubmissions);
router.get('/:submissionId/progress', authorize(['STUDENT']), SubmissionsController.getProgress);

// Shared routes (students see their own, lecturers see all for their exams)
router.get('/:id', SubmissionsController.getById);
router.get('/exam/:examId', SubmissionsController.getByExam);

export default router;
