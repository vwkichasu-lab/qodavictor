const { spawn } = require('child_process');
const crypto = require('crypto');
const http = require('http');
const httpProxy = require('http-proxy');
const mysql = require('mysql2/promise');
const { Server } = require('socket.io');

const publicPort = parseInt(process.env.PORT || '8080', 10);
const phpPort = parseInt(process.env.QODA_PHP_PORT || '8081', 10);
const phpHost = '127.0.0.1';

const socketSecret = [
  process.env.QODA_SOCKET_SECRET || '',
  process.env.APP_KEY || '',
  process.env.RAILWAY_ENVIRONMENT_ID || '',
  process.env.DB_NAME || '',
  process.env.DB_USER || '',
  process.env.DB_PASS || '',
  '/var/www/html/backend-php/lib',
].filter(Boolean).join('|');

const dbConfig = {
  host: process.env.DB_HOST || '127.0.0.1',
  port: parseInt(process.env.DB_PORT || '3306', 10),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || 'qoda_db',
  waitForConnections: true,
  connectionLimit: 8,
  charset: 'utf8mb4',
};

const pool = mysql.createPool(dbConfig);

function base64UrlDecode(value) {
  const padded = value + '='.repeat((4 - (value.length % 4)) % 4);
  return Buffer.from(padded.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

function base64UrlEncode(buffer) {
  return Buffer.from(buffer).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function verifyToken(token) {
  if (!token || !token.includes('.')) throw new Error('Missing socket token');
  const [payload, signature] = token.split('.');
  const expected = base64UrlEncode(crypto.createHmac('sha256', socketSecret).update(payload).digest());
  if (Buffer.byteLength(signature) !== Buffer.byteLength(expected)) {
    throw new Error('Invalid socket token length');
  }
  if (!crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) {
    throw new Error('Invalid socket token');
  }
  const claims = JSON.parse(base64UrlDecode(payload).toString('utf8'));
  if (!claims.exp || Number(claims.exp) < Math.floor(Date.now() / 1000)) {
    throw new Error('Expired socket token');
  }
  return claims;
}

function streamRoom(examId, studentId, sessionId) {
  return `screen:${examId}:${studentId}:${sessionId}`;
}

function monitoringRoom(examId) {
  return `monitor:${examId}`;
}

async function ensureScreenShareTable() {
  await pool.execute(`
    CREATE TABLE IF NOT EXISTS screen_share_sessions (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      student_id INT NOT NULL,
      exam_id INT NOT NULL,
      session_id VARCHAR(191) NOT NULL,
      is_sharing TINYINT(1) NOT NULL DEFAULT 0,
      started_at DATETIME NULL,
      stopped_at DATETIME NULL,
      last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_screen_share_session (student_id, exam_id, session_id),
      INDEX idx_exam_sharing (exam_id, is_sharing, last_updated),
      INDEX idx_student_exam (student_id, exam_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  `);
}

async function setSharingStatus({ examId, studentId, sessionId, isSharing }) {
  await pool.execute(
    `INSERT INTO screen_share_sessions
       (student_id, exam_id, session_id, is_sharing, started_at, stopped_at, last_updated)
     VALUES (?, ?, ?, ?, IF(? = 1, NOW(), NULL), IF(? = 1, NULL, NOW()), NOW())
     ON DUPLICATE KEY UPDATE
       is_sharing = VALUES(is_sharing),
       started_at = IF(VALUES(is_sharing) = 1 AND started_at IS NULL, NOW(), started_at),
       stopped_at = IF(VALUES(is_sharing) = 1, NULL, NOW()),
       last_updated = NOW()`,
    [studentId, examId, sessionId, isSharing ? 1 : 0, isSharing ? 1 : 0, isSharing ? 1 : 0],
  );
}

async function getSharingStudents(examId) {
  const [rows] = await pool.execute(
    `SELECT sss.student_id, sss.exam_id, sss.session_id, sss.is_sharing, sss.started_at, sss.last_updated,
            COALESCE(st.full_name, u.full_name, CONCAT('Student ', sss.student_id)) AS full_name,
            COALESCE(st.student_id, CAST(sss.student_id AS CHAR)) AS student_identifier,
            COALESCE(e.title, e.course_name, CONCAT('Exam ', sss.exam_id)) AS exam_title
     FROM screen_share_sessions sss
     LEFT JOIN students st ON st.id = sss.student_id OR st.user_id = sss.student_id
     LEFT JOIN users u ON u.id = st.user_id
     LEFT JOIN exams e ON e.id = sss.exam_id
     WHERE sss.exam_id = ? AND sss.is_sharing = 1 AND sss.last_updated >= (NOW() - INTERVAL 30 SECOND)
     ORDER BY sss.last_updated DESC`,
    [examId],
  );
  return rows;
}

async function canMonitorExam(claims, examId) {
  if (claims.role === 'admin') return true;
  const lecturerId = Number(claims.lecturer_id || claims.user_id || 0);
  if (!lecturerId) return false;
  try {
    const [rows] = await pool.execute(
      `SELECT id FROM exams WHERE id = ? AND (lecturer_id = ? OR created_by = ?) LIMIT 1`,
      [examId, lecturerId, lecturerId],
    );
    if (rows.length > 0) return true;
  } catch (error) {
    console.error('monitor exam ownership check fallback:', error.message);
  }
  try {
    const [rows] = await pool.execute(
      `SELECT id FROM exams WHERE id = ? AND lecturer_id = ? LIMIT 1`,
      [examId, lecturerId],
    );
    return rows.length > 0;
  } catch (error) {
    console.error('monitor exam ownership check failed:', error.message);
    return false;
  }
}

const php = spawn('php', [
  '-S',
  `${phpHost}:${phpPort}`,
  '-t',
  '/var/www/html',
  '/var/www/html/server_router.php',
], {
  cwd: '/var/www/html',
  stdio: 'inherit',
  env: process.env,
});

php.on('exit', code => {
  console.error(`PHP server exited with code ${code}`);
  process.exit(code || 1);
});

const proxy = httpProxy.createProxyServer({
  target: `http://${phpHost}:${phpPort}`,
  ws: false,
});

const server = http.createServer((req, res) => {
  proxy.web(req, res, err => {
    console.error('PHP proxy error:', err.message);
    res.writeHead(502);
    res.end('QODA backend unavailable');
  });
});

const io = new Server(server, {
  path: '/socket.io',
  cors: { origin: true, credentials: true },
  maxHttpBufferSize: 1e7,
});

io.use((socket, next) => {
  try {
    socket.data.claims = verifyToken(socket.handshake.auth?.token || socket.handshake.query?.token);
    next();
  } catch (error) {
    next(error);
  }
});

io.on('connection', socket => {
  const claims = socket.data.claims;
  console.log('[socket] connected', socket.id, claims.role, {
    exam_id: claims.exam_id || null,
    student_id: claims.student_id || null,
    lecturer_id: claims.lecturer_id || null,
  });

  socket.on('student-start-screen', async payload => {
    if (claims.role !== 'student') return socket.emit('screen-share-error', 'Student token required');
    const examId = String(payload?.exam_id || claims.exam_id || '');
    const studentId = String(claims.student_id || '');
    const sessionId = String(payload?.session_id || claims.session_id || socket.id);
    if (!examId || !studentId || examId !== String(claims.exam_id)) return socket.emit('screen-share-error', 'Invalid screen share identity');
    console.log('[screen] student-start-screen', { socket: socket.id, examId, studentId, sessionId });

    socket.join(streamRoom(examId, studentId, sessionId));
    socket.data.examId = examId;
    socket.data.studentId = studentId;
    socket.data.sessionId = sessionId;
    await setSharingStatus({ examId, studentId, sessionId, isSharing: true });
    if (socket.data.shareHeartbeat) clearInterval(socket.data.shareHeartbeat);
    socket.data.shareHeartbeat = setInterval(() => {
      setSharingStatus({ examId, studentId, sessionId, isSharing: true })
        .catch(error => console.error('screen share heartbeat failed:', error.message));
    }, 10000);
    io.to(monitoringRoom(examId)).emit('screen-share-started', {
      exam_id: examId,
      student_id: studentId,
      session_id: sessionId,
    });
    const viewers = await io.in(monitoringRoom(examId)).fetchSockets();
    console.log('[screen] viewers-ready-for-student', { examId, studentId, sessionId, viewers: viewers.length });
    viewers.forEach(viewer => {
      socket.emit('viewer-ready', {
        viewer_socket_id: viewer.id,
        exam_id: examId,
        student_id: studentId,
        session_id: sessionId,
      });
    });
  });

  socket.on('student-join-screen-share', payload => {
    socket.emit('screen-share-error', 'Use student-start-screen for live video signaling');
  });

  socket.on('lecturer-join-monitoring', async payload => {
    if (!['lecturer', 'admin'].includes(claims.role)) return socket.emit('screen-share-error', 'Lecturer token required');
    const examId = String(payload?.exam_id || claims.exam_id || '');
    if (!examId || (claims.exam_id && examId !== String(claims.exam_id))) return socket.emit('screen-share-error', 'Invalid monitoring exam');
    if (!(await canMonitorExam(claims, examId))) {
      console.log('[screen] lecturer-monitor-denied', { socket: socket.id, examId, lecturer_id: claims.lecturer_id || null });
      return socket.emit('screen-share-error', 'Not authorized to monitor this exam');
    }
    socket.join(monitoringRoom(examId));
    socket.data.examId = examId;
    const roster = await getSharingStudents(examId);
    console.log('[screen] lecturer-join-monitoring', { socket: socket.id, examId, roster: roster.length });
    socket.emit('screen-share-roster', roster);
    roster.forEach(row => {
      io.to(streamRoom(row.exam_id, row.student_id, row.session_id)).emit('viewer-ready', {
        viewer_socket_id: socket.id,
        exam_id: String(row.exam_id),
        student_id: String(row.student_id),
        session_id: String(row.session_id),
      });
    });
  });

  socket.on('webrtc-offer', payload => {
    console.log('[webrtc] offer', {
      from: socket.id,
      exam_id: payload.exam_id,
      student_id: payload.student_id,
      session_id: payload.session_id,
      viewer_socket_id: payload.viewer_socket_id || null,
    });
    if (payload.viewer_socket_id) {
      io.to(payload.viewer_socket_id).emit('webrtc-offer', {
        ...payload,
        student_socket_id: socket.id,
      });
      return;
    }
    io.to(monitoringRoom(payload.exam_id)).emit('webrtc-offer', {
      ...payload,
      student_socket_id: socket.id,
    });
  });

  socket.on('offer', payload => socket.emit('screen-share-error', 'Use webrtc-offer for live video signaling'));

  socket.on('webrtc-answer', payload => {
    console.log('[webrtc] answer', {
      from: socket.id,
      student_id: payload.student_id,
      student_socket_id: payload.student_socket_id || null,
    });
    if (payload.student_socket_id) io.to(payload.student_socket_id).emit('webrtc-answer', { ...payload, from: socket.id });
  });

  socket.on('answer', payload => socket.emit('screen-share-error', 'Use webrtc-answer for live video signaling'));

  socket.on('webrtc-ice-candidate', payload => {
    console.log('[webrtc] ice-candidate', {
      from: socket.id,
      to: payload.to || payload.viewer_socket_id || payload.student_socket_id || null,
      student_id: payload.student_id || null,
    });
    if (payload.to) {
      io.to(payload.to).emit('webrtc-ice-candidate', { ...payload, from: socket.id });
      return;
    }
    if (payload.viewer_socket_id) {
      io.to(payload.viewer_socket_id).emit('webrtc-ice-candidate', { ...payload, from: socket.id });
      return;
    }
    if (payload.student_socket_id) {
      io.to(payload.student_socket_id).emit('webrtc-ice-candidate', { ...payload, from: socket.id });
      return;
    }
    io.to(streamRoom(payload.exam_id, payload.student_id, payload.session_id)).emit('webrtc-ice-candidate', { ...payload, from: socket.id });
  });

  socket.on('ice-candidate', payload => socket.emit('screen-share-error', 'Use webrtc-ice-candidate for live video signaling'));

  socket.on('screen-share-stopped', async payload => {
    const examId = String(payload?.exam_id || socket.data.examId || '');
    const studentId = String(socket.data.studentId || payload?.student_id || '');
    const sessionId = String(socket.data.sessionId || payload?.session_id || '');
    if (examId && studentId && sessionId) {
      if (socket.data.shareHeartbeat) {
        clearInterval(socket.data.shareHeartbeat);
        socket.data.shareHeartbeat = null;
      }
      await setSharingStatus({ examId, studentId, sessionId, isSharing: false });
      console.log('[screen] screen-share-stopped', { socket: socket.id, examId, studentId, sessionId });
      io.to(monitoringRoom(examId)).emit('screen-share-stopped', { exam_id: examId, student_id: studentId, session_id: sessionId });
    }
  });

  socket.on('disconnect', async () => {
    console.log('[socket] disconnected', socket.id, claims.role);
    if (socket.data.shareHeartbeat) {
      clearInterval(socket.data.shareHeartbeat);
      socket.data.shareHeartbeat = null;
    }
    if (claims.role === 'student' && socket.data.examId && socket.data.studentId && socket.data.sessionId) {
      await setSharingStatus({
        examId: socket.data.examId,
        studentId: socket.data.studentId,
        sessionId: socket.data.sessionId,
        isSharing: false,
      }).catch(error => console.error('disconnect status update failed:', error.message));
      io.to(monitoringRoom(socket.data.examId)).emit('screen-share-stopped', {
        exam_id: socket.data.examId,
        student_id: socket.data.studentId,
        session_id: socket.data.sessionId,
      });
    }
  });
});

ensureScreenShareTable()
  .then(() => {
    server.listen(publicPort, '0.0.0.0', () => {
      console.log(`QODA realtime server listening on ${publicPort}, PHP on ${phpPort}`);
    });
  })
  .catch(error => {
    console.error('Failed to initialize realtime server:', error);
    process.exit(1);
  });
