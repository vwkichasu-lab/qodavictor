import { Router } from 'express';
import ExamsController from '../controllers/exams.controller.js';
import { authenticate, authorize } from '../middleware/auth.middleware.js';

const router = Router();

router.use(authenticate);

// Student routes
router.get('/upcoming', authorize(['STUDENT']), ExamsController.getUpcoming);
router.get('/available', authorize(['STUDENT']), ExamsController.getAvailable);

// Lecturer/Admin routes
router.get('/', ExamsController.getAll);
router.post('/', authorize(['LECTURER', 'ADMIN']), ExamsController.create);
router.get('/:id', ExamsController.getById);
router.put('/:id', authorize(['LECTURER', 'ADMIN']), ExamsController.update);
router.delete('/:id', authorize(['LECTURER', 'ADMIN']), ExamsController.delete);
router.post('/:id/publish', authorize(['LECTURER', 'ADMIN']), ExamsController.togglePublish);
router.get('/:id/stats', ExamsController.getStats);

// Question management
router.post('/:id/questions', authorize(['LECTURER', 'ADMIN']), ExamsController.addQuestion);
router.put('/:id/questions/:questionId', authorize(['LECTURER', 'ADMIN']), ExamsController.updateQuestion);
router.delete('/:id/questions/:questionId', authorize(['LECTURER', 'ADMIN']), ExamsController.deleteQuestion);
router.post('/:id/reorder', authorize(['LECTURER', 'ADMIN']), ExamsController.reorderQuestions);

export default router;
