import jwt from 'jsonwebtoken';
import prisma from '../utils/db.js';

export const authenticate = async (req, res, next) => {
  const token = req.headers.authorization?.split(' ')[1];
  
  if (!token) {
    return res.status(401).json({ error: 'Access denied' });
  }
  
  try {
    const decoded = jwt.verify(token, process.env.JWT_SECRET);
    
    // Get full user details including role-specific IDs
    const user = await prisma.user.findUnique({
      where: { id: decoded.userId },
      include: {
        student: { select: { id: true } },
        lecturer: { select: { id: true } },
        admin: { select: { id: true } },
      },
    });

    if (!user) {
      return res.status(401).json({ error: 'User not found' });
    }

    req.user = {
      id: user.id,
      userId: user.userId,
      name: user.name,
      email: user.email,
      role: user.role,
      studentId: user.student?.id,
      lecturerId: user.lecturer?.id,
      adminId: user.admin?.id,
    };
    
    next();
  } catch (error) {
    res.status(401).json({ error: 'Invalid token' });
  }
};

export const authorize = (roles) => {
  return (req, res, next) => {
    if (!req.user) {
      return res.status(401).json({ error: 'Not authenticated' });
    }

    if (!roles.includes(req.user.role)) {
      return res.status(403).json({ error: 'Not authorized' });
    }

    next();
  };
};
