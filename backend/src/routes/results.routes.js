import { Router } from 'express';
import ResultsController from '../controllers/results.controller.js';
import { authenticate, authorize } from '../middleware/auth.middleware.js';

const router = Router();

router.use(authenticate);

// Student routes
router.get('/my-results', authorize(['STUDENT']), ResultsController.getMyResults);

// Lecturer/Admin routes
router.post('/publish/:examId', authorize(['LECTURER', 'ADMIN']), ResultsController.publish);
router.post('/unpublish/:examId', authorize(['LECTURER', 'ADMIN']), ResultsController.unpublish);
router.get('/exam/:examId', ResultsController.getByExam);
router.get('/exam/:examId/export', authorize(['LECTURER', 'ADMIN']), ResultsController.exportCSV);
router.get('/exam/:examId/statistics', ResultsController.getStatistics);

// Shared routes
router.get('/detail/:resultId', ResultsController.getDetail);

export default router;
