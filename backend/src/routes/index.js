import { Router } from 'express';
import authRoutes from './auth.routes.js';
import studentRoutes from './students.routes.js';
import examRoutes from './exams.routes.js';
import submissionRoutes from './submissions.routes.js';
import gradingRoutes from './grading.routes.js';
import resultRoutes from './results.routes.js';
import securityRoutes from './security.routes.js';
import auditRoutes from './audit.routes.js';

const router = Router();

// Health check
router.get('/health', (req, res) => {
  res.json({ status: 'OK', timestamp: new Date().toISOString() });
});

// Routes
router.use('/auth', authRoutes);
router.use('/students', studentRoutes);
router.use('/exams', examRoutes);
router.use('/submissions', submissionRoutes);
router.use('/grading', gradingRoutes);
router.use('/results', resultRoutes);
router.use('/security', securityRoutes);
router.use('/audit', auditRoutes);

export default router;
