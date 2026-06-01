import { Router } from 'express';
import StudentsController from '../controllers/students.controller.js';
import { authenticate, authorize } from '../middleware/auth.middleware.js';

const router = Router();

// All routes require authentication
router.use(authenticate);

// Routes for lecturers and admins
router.get('/', authorize(['LECTURER', 'ADMIN']), StudentsController.getAll);
router.post('/', authorize(['LECTURER', 'ADMIN']), StudentsController.create);
router.post('/bulk-import', authorize(['LECTURER', 'ADMIN']), StudentsController.bulkImport);
router.get('/export', authorize(['LECTURER', 'ADMIN']), StudentsController.export);

// Individual student routes
router.get('/:id', authorize(['LECTURER', 'ADMIN', 'STUDENT']), StudentsController.getById);
router.put('/:id', authorize(['LECTURER', 'ADMIN']), StudentsController.update);
router.delete('/:id', authorize(['LECTURER', 'ADMIN']), StudentsController.delete);
router.post('/:id/reset-password', authorize(['LECTURER', 'ADMIN']), StudentsController.resetPassword);
router.get('/:id/stats', authorize(['LECTURER', 'ADMIN', 'STUDENT']), StudentsController.getStats);

export default router;
