    // ============================================
    // 17. MONITORING & PROCTORING FUNCTIONS
    // ============================================

    const PROCTOR_SUBMITTED_STATUSES = new Set([
        'SUBMITTED',
        'TIMED_OUT',
        'GRADED',
        'MARKED',
        'AUTO_GRADED',
        'MANUALLY_GRADED'
    ]);
    const PROCTOR_LIVE_FRAME_MAX_AGE_MS = 90000;
    const PROCTOR_POLL_INTERVAL_MS = 350;
    const proctorSubmittedRemovalTimers = {};
    let proctoringLiveLoopActive = false;

    function proctorFrameAgeMs(timestamp) {
        if (!timestamp) return null;
        const value = String(timestamp);
        const localValue = value.includes('T') ? value : value.replace(' ', 'T');
        let frameTime = new Date(localValue).getTime();
        if (!Number.isFinite(frameTime) || Math.abs(Date.now() - frameTime) > 3600000) {
            const utcValue = value.includes('T') ? value : value.replace(' ', 'T') + 'Z';
            frameTime = new Date(utcValue).getTime();
        }
        return Number.isFinite(frameTime) ? Date.now() - frameTime : null;
    }

    function isFreshProctorFrame(timestamp) {
        const ageMs = proctorFrameAgeMs(timestamp);
        return ageMs !== null && ageMs >= -5000 && ageMs <= PROCTOR_LIVE_FRAME_MAX_AGE_MS;
    }

    function isSubmittedProctorRecord(record) {
        if (!record) return false;
        const status = String(record.submission_status || '').toUpperCase();
        return parseInt(record.is_submitted || 0) === 1 ||
            parseInt(record.submitted || 0) === 1 ||
            !!record.submitted_at ||
            !!record.submittedAt ||
            PROCTOR_SUBMITTED_STATUSES.has(status);
    }

    function markStudentSubmittedAndRemove(studentId, record = {}) {
        const studentIndex = activeProctoringStudents.findIndex(s => String(s.id) === String(studentId));
        if (studentIndex < 0) return;

        const student = activeProctoringStudents[studentIndex];
        student.isSubmitted = true;
        student.status = 'submitted';
        student.submissionStatus = record.submission_status || 'submitted';
        student.submittedAt = record.submitted_at || record.submittedAt || new Date().toISOString();

        const card = document.querySelector(`.student-proctor-card[data-student-id="${studentId}"]`);
        if (card && card.dataset.submittedRemoving !== '1') {
            card.dataset.submittedRemoving = '1';
            card.style.border = '2px solid #10b981';
            card.style.boxShadow = '0 0 0 4px rgba(16,185,129,0.12)';
            card.innerHTML = `
                <div style="min-height: 240px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px; text-align: center; background: var(--panel); color: var(--text);">
                    <div style="width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #10b981; color: white; font-size: 30px;">
                        <i class="fas fa-check"></i>
                    </div>
                    <strong style="font-size: 18px;">Submitted</strong>
                    <span style="color: var(--muted);">${escapeHTML(student.full_name || 'Student')} has submitted and left proctoring.</span>
                </div>
            `;
        }

        clearTimeout(proctorSubmittedRemovalTimers[studentId]);
        proctorSubmittedRemovalTimers[studentId] = setTimeout(() => {
            activeProctoringStudents = activeProctoringStudents.filter(s => String(s.id) !== String(studentId));
            delete proctorSubmittedRemovalTimers[studentId];
            updateProctoringStats(activeProctoringStudents);
            renderProctoringGrid(activeProctoringStudents);
        }, card ? 1200 : 0);
    }

    // Load proctoring data when exam is selected
    async function loadProctoringData() {
        const examSelect = document.getElementById('proctoringExamSelect');
        const examId = examSelect ? examSelect.value : null;

        if (!examId) {
            document.getElementById('proctoringGrid').innerHTML = `
            <div style="text-align: center; padding: 60px; grid-column: 1/-1;">
                <i class="fas fa-desktop" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <p style="color: var(--muted);">Please select an exam to start proctoring</p>
            </div>
        `;
            return;
        }

        currentProctoringExam = examId;

        try {
            // Get exam details
            const examResult = await apiRequest('get_exam_details', {
                exam_id: examId
            });
            if (!examResult.success) {
                toast('❌ Failed to load exam details');
                return;
            }

            // Get enrolled students for this exam
            const courseCode = examResult.data.course_code;
            const studentsResult = await apiRequest('get_course_students', {
                course_code: courseCode,
                exam_id: examId
            });

            if (studentsResult.success && studentsResult.data) {
                // Get active exam sessions from server
                const sessionsResult = await apiRequest('get_active_sessions', {
                    exam_id: examId
                });
                const activeSessions = sessionsResult.success ? sessionsResult.data : [];

                activeProctoringStudents = studentsResult.data.map(student => {
                    const session = activeSessions.find(s => s.student_id == student.id);
                    const isSharing = !!(session && parseInt(session.screen_sharing_active || 0) === 1);
                    const hasFreshFrame = !!(session && session.snapshot);
                    return {
                        id: student.id,
                        student_id: student.student_id,
                        full_name: student.full_name,
                        exam_title: examResult.data.title || examResult.data.course_name || examResult.data.course_code || 'Exam',
                        level: student.level,
                        programme: student.programme,
                        screenSharing: isSharing,
                        socketSharing: isSharing && !!(session && session.socket_session_id),
                        socketSessionId: session ? (session.socket_session_id || '') : '',
                        status: isSharing ? 'active' : 'offline',
                        violationCount: session ? parseInt(session.violations || 0) : 0,
                        lastActivity: session ? (session.latest_socket_at || session.latest_snapshot_at || session.last_activity) : null,
                        snapshot: hasFreshFrame ? (session.snapshot || '') : '',
                        snapshotId: hasFreshFrame ? (session.snapshot_id || '') : '',
                        lastRenderedSnapshotId: '',
                        latestSnapshotAt: session ? (session.latest_snapshot_at || '') : '',
                        screenLocked: session ? !!parseInt(session.screen_locked || 0) : false,
                        isSubmitted: isSubmittedProctorRecord(session),
                        submissionStatus: session ? (session.submission_status || '') : '',
                        submittedAt: session ? (session.submitted_at || session.submittedAt || '') : ''
                    };
                }).filter(student => !student.isSubmitted);

                lastViolationTotalsByStudent = {};
                activeProctoringStudents.forEach(student => {
                    lastViolationTotalsByStudent[String(student.id)] = student.violationCount || 0;
                });

                updateProctoringStats(activeProctoringStudents);
                renderLockedStudentsList(activeProctoringStudents);
                renderProctoringGrid(activeProctoringStudents);
                connectScreenMonitorSocket(examId);
                startScreenPolling();
            } else {
                renderEmptyProctoringGrid();
            }
        } catch (error) {
            console.error('Error loading proctoring data:', error);
            toast('❌ Error loading proctoring data');
        }
    }

    function renderProctoringGrid(students) {
        const grid = document.getElementById('proctoringGrid');
        if (!grid) return;
        Object.keys(proctorGridWebrtcPeers).forEach(stopGridWebrtcStream);

        if (!students || students.length === 0) {
            grid.innerHTML = `
            <div style="text-align: center; padding: 60px; grid-column: 1/-1;">
                <i class="fas fa-users" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <p style="color: var(--muted);">No active students in this exam. Submitted students leave the live proctoring grid automatically.</p>
            </div>
        `;
            return;
        }

        grid.innerHTML = students.map(student => {
            const sharingStatus = student.screenSharing;
            const hasFreshFrame = sharingStatus && !!student.snapshot;
            const statusColor = sharingStatus ? '#10b981' : '#ef4444';
            const statusText = sharingStatus ? 'Sharing Screen' : 'Not Sharing';
            const activityTime = student.lastActivity ? new Date(student.lastActivity).toLocaleTimeString() :
                'N/A';

            return `
            <div class="student-proctor-card" id="proctorCard_${student.id}" data-student-id="${student.id}" style="
                background: var(--panel);
                border-radius: 16px;
                overflow: hidden;
                border: 2px solid ${sharingStatus ? '#10b981' : '#ef4444'};
                transition: transform 0.2s, box-shadow 0.2s;
                cursor: pointer;
            ">
                <!-- Screen Preview -->
                <div class="screen-preview-container" style="
                    position: relative;
                    background: #1e1e1e;
                    aspect-ratio: 16 / 9;
                    min-height: 220px;
                    overflow: hidden;
                " onclick="openLiveScreenPreview(${student.id})" title="Click to view larger live screen">
                    <div id="screenPreview_${student.id}" style="
                        width: 100%;
                        height: 100%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        background: #1e1e1e;
                    ">
                        <video id="screen-video-${student.id}" autoplay playsinline muted style="width: 100%; height: 100%; object-fit: contain; image-rendering: auto; background:#000; display:none;"></video>
                        <img id="screenImg_${student.id}" alt="Live shared screen" style="display:none;width:100%;height:100%;object-fit:contain;background:#000;">
                        <div id="screenPlaceholder_${student.id}" style="text-align: center; color: #9ca3af;">
                                <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#14b8a6);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;color:white;font-size:28px;font-weight:800;">
                                    ${escapeHTML((student.full_name || '?').trim().charAt(0).toUpperCase())}
                                </div>
                                <p style="margin:0 0 4px;">${sharingStatus ? (student.latestSnapshotAt ? 'Live feed paused' : 'Screen shared, waiting for live frame') : 'Screen not shared'}</p>
                                <small>${escapeHTML(student.full_name || 'Student')}</small>
                        </div>
                    </div>
                    <div id="liveBadge_${student.id}" class="live-badge" style="position: absolute; top: 10px; right: 10px; background: #ef4444; color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; ${sharingStatus ? '' : 'display:none;'}"><i class="fas fa-circle" style="font-size: 8px; margin-right: 4px;"></i> LIVE</div>
                    <div id="webrtcStatus_${student.id}" style="position:absolute;left:10px;bottom:10px;background:rgba(15,23,42,.86);color:#bfdbfe;border:1px solid rgba(96,165,250,.55);padding:4px 8px;border-radius:6px;font-size:11px;font-weight:800;${sharingStatus ? '' : 'display:none;'}">CONNECTING LIVE VIDEO</div>
                    ${student.screenLocked ? '<div style="position:absolute;bottom:10px;right:10px;background:#111827;color:#fbbf24;border:1px solid #fbbf24;padding:5px 9px;border-radius:8px;font-size:11px;font-weight:800;"><i class="fas fa-lock"></i> LOCKED</div>' : ''}
                    ${student.violationCount > 0 ? `<div class="violation-badge" style="position: absolute; top: 10px; left: 10px; background: #ef4444; color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;"><i class="fas fa-exclamation-triangle"></i> ${student.violationCount}</div>` : ''}
                </div>
                
                <!-- Student Info -->
                <div style="padding: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div>
                            <strong style="font-size: 16px; color: var(--text);">${escapeHTML(student.full_name)}</strong>
                            <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">ID: ${escapeHTML(student.student_id)}</div>
                            <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">Exam: ${escapeHTML(student.exam_title || 'Exam')}</div>
                        </div>
                        <span class="tag" id="screenStatusTag_${student.id}" style="background: ${statusColor}; color: white;">
                            <i class="fas ${sharingStatus ? 'fa-desktop' : 'fa-eye-slash'}"></i> ${statusText}
                        </span>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 10px; font-size: 12px; color: var(--muted);">
                        <span><i class="fas fa-graduation-cap"></i> Level ${student.level || 'N/A'}</span>
                        <span id="screenFrameTime_${student.id}"><i class="fas fa-clock"></i> Last live frame: ${activityTime}</span>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div style="padding: 12px 15px; border-top: 1px solid var(--border); display: flex; flex-wrap: wrap; gap: 8px;">
                    <button class="btn warn small" onclick="event.stopPropagation(); sendWarningToStudentById(${student.id})" style="flex: 1 1 120px; padding: 6px 12px;">
                        <i class="fas fa-exclamation-triangle"></i> Warn
                    </button>
                    <button class="btn danger small" onclick="event.stopPropagation(); lockStudentScreenById(${student.id})" style="flex: 1 1 120px; padding: 6px 12px;">
                        <i class="fas fa-lock"></i> Lock
                    </button>
                    <button class="btn ok small" onclick="event.stopPropagation(); unlockStudentScreenById(${student.id})" style="flex: 1 1 120px; padding: 6px 12px;">
                        <i class="fas fa-unlock"></i> Unlock
                    </button>
                    <button class="btn primary small" onclick="event.stopPropagation(); promptAddTimeToStudent(${student.id})" style="flex: 1 1 120px; padding: 6px 12px;">
                        <i class="fas fa-clock"></i> Add Time
                    </button>
                    <button class="btn small" onclick="event.stopPropagation(); promptMessageStudent(${student.id})" style="flex: 1 1 120px; padding: 6px 12px;">
                        <i class="fas fa-comment-alt"></i> Message
                    </button>
                    <button class="btn small" onclick="event.stopPropagation(); viewViolationEvidence(${student.id})" style="flex: 1 1 120px; padding: 6px 12px;">
                        <i class="fas fa-folder-open"></i> Evidence
                    </button>
                </div>
            </div>
        `;
        }).join('');

        startScreenPolling();
        fetchScreenUpdates();
        setTimeout(() => {
            (students || []).forEach(student => {
                if (student.screenSharing) ensureGridWebrtcStream(student);
            });
        }, 100);
    }

    function renderEmptyProctoringGrid() {
        const grid = document.getElementById('proctoringGrid');
        if (grid) {
            grid.innerHTML = `
            <div style="text-align: center; padding: 60px; grid-column: 1/-1;">
                <i class="fas fa-desktop" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <p style="color: var(--muted);">No students found for this exam</p>
            </div>
        `;
        }
    }

    function startProctoring() {
        if (!currentProctoringExam) {
            toast('❌ Please select an exam first');
            return;
        }

        if (proctoringInterval) {
            clearTimeout(proctoringInterval);
        }

        // Poll quickly so live grid frames and submitted students update without waiting.
        proctoringLiveLoopActive = false;
        proctoringInterval = null;

        toast('✅ Proctoring started - Monitoring student screens');
        connectScreenMonitorSocket(currentProctoringExam);
        startScreenPolling(true);
    }

    function stopProctoring() {
        proctoringLiveLoopActive = false;
        if (proctoringInterval) {
            clearTimeout(proctoringInterval);
            proctoringInterval = null;
        }
        toast('⏹️ Proctoring stopped');
    }

    function refreshProctoring() {
        loadProctoringData();
        toast('🔄 Refreshed');
    }

    async function fetchScreenUpdates() {
        if (!currentProctoringExam) return;

        try {
            const result = await apiRequest('get_screen_updates', {
                exam_id: currentProctoringExam
            });

            if (result.success && result.data) {
                // Update each student's screen preview
                for (const update of result.data) {
                    if (isSubmittedProctorRecord(update)) {
                        markStudentSubmittedAndRemove(update.student_id, update);
                        continue;
                    }

                    const student = activeProctoringStudents.find(s => String(s.id) === String(update.student_id));
                    if (student) {
                        const hasFreshFrame = !!update.snapshot;
                        const socketLive = !!student.socketSharing || !!document.getElementById(`screen-video-${student.id}`)?.srcObject;
                        const isSharing = socketLive || parseInt(update.screen_sharing_active || 0) === 1 || (!!update.snapshot && isFreshProctorFrame(update.captured_at));
                        student.screenSharing = isSharing;
                        student.socketSharing = socketLive || (!!update.socket_session_id && parseInt(update.screen_sharing_active || 0) === 1);
                        student.socketSessionId = update.socket_session_id || student.socketSessionId || '';
                        student.status = student.screenSharing ? 'active' : 'offline';
                        student.lastActivity = update.latest_socket_at || update.captured_at || update.last_heartbeat_at || student.lastActivity;
                        const previousViolations = lastViolationTotalsByStudent[String(student.id)] ?? (student.violationCount || 0);
                        student.violationCount = parseInt(update.violations || student.violationCount || 0);
                        if (student.violationCount > previousViolations) {
                            toast(`⚠️ Violation recorded for ${student.full_name || student.student_id || 'student'}`);
                        }
                        lastViolationTotalsByStudent[String(student.id)] = student.violationCount;
                        student.snapshot = hasFreshFrame ? (update.snapshot || '') : '';
                        student.snapshotId = hasFreshFrame ? (update.snapshot_id || '') : '';
                        student.snapshotHash = hasFreshFrame ? (update.snapshot_hash || '') : '';
                        student.latestSnapshotAt = update.captured_at || student.latestSnapshotAt || '';
                        student.screenLocked = parseInt(update.screen_locked || 0) === 1;
                        updateProctorCardLiveState(student);
                    }

                    // Update violation count if needed
                    if (update.violations) {
                        const studentCard = document.querySelector(
                            `.student-proctor-card[data-student-id="${update.student_id}"]`);
                        if (studentCard) {
                            const violationBadge = studentCard.querySelector('.violation-badge');
                            if (violationBadge) {
                                violationBadge.innerHTML =
                                    `<i class="fas fa-exclamation-triangle"></i> ${update.violations}`;
                            } else if (update.violations > 0) {
                                const previewContainer = studentCard.querySelector('.screen-preview-container');
                                if (previewContainer && !previewContainer.querySelector('.violation-badge')) {
                                    const badge = document.createElement('div');
                                    badge.className = 'violation-badge';
                                    badge.style.cssText =
                                        'position: absolute; top: 10px; left: 10px; background: #ef4444; color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;';
                                    badge.innerHTML =
                                        `<i class="fas fa-exclamation-triangle"></i> ${update.violations}`;
                                    previewContainer.appendChild(badge);
                                }
                            }
                        }
                    }
                }

                updateProctoringStats(activeProctoringStudents);
                renderLockedStudentsList(activeProctoringStudents);
            }
        } catch (error) {
            console.error('Error fetching screen updates:', error);
        }
    }

    function updateProctorCardLiveState(student) {
        const card = document.getElementById(`proctorCard_${student.id}`);
        const statusTag = document.getElementById(`screenStatusTag_${student.id}`);
        const liveBadge = document.getElementById(`liveBadge_${student.id}`);
        const placeholder = document.getElementById(`screenPlaceholder_${student.id}`);
        const screenImg = document.getElementById(`screenImg_${student.id}`);
        const frameTime = document.getElementById(`screenFrameTime_${student.id}`);
        const isSharing = !!student.screenSharing;

        if (card) {
            card.style.border = `2px solid ${isSharing ? '#10b981' : '#ef4444'}`;
        }
        if (statusTag) {
            statusTag.style.background = isSharing ? '#10b981' : '#ef4444';
            statusTag.innerHTML = `<i class="fas ${isSharing ? 'fa-video' : 'fa-eye-slash'}"></i> ${isSharing ? 'Sharing Live' : 'Not Sharing'}`;
        }
        if (liveBadge) {
            liveBadge.style.display = isSharing ? 'block' : 'none';
        }
        const video = document.getElementById(`screen-video-${student.id}`);
        let hasLiveVideo = !!video?.srcObject;
        if (screenImg && student.snapshot && isSharing) {
            const snapshotSignature = student.snapshotHash || `${student.snapshot.length}:${student.snapshot.slice(0, 32)}:${student.snapshot.slice(-32)}`;
            const renderKey = `${student.snapshotId || ''}:${student.latestSnapshotAt || student.lastActivity || ''}:${snapshotSignature}`;
            if (student.lastRenderedSnapshotId !== renderKey) {
                screenImg.src = `data:image/jpeg;base64,${student.snapshot}`;
                student.lastRenderedSnapshotId = renderKey;
            }
            screenImg.style.display = 'block';
            if (video) video.style.display = 'none';
            hasLiveVideo = false;
            if (currentFullScreenStudent && String(currentFullScreenStudent.id) === String(student.id)) {
                updateFullScreenStreamFrame(student.snapshot, true);
            }
        } else if (screenImg && !isSharing) {
            screenImg.removeAttribute('src');
            screenImg.style.display = 'none';
            stopGridWebrtcStream(student.id);
            if (currentFullScreenStudent && String(currentFullScreenStudent.id) === String(student.id)) {
                updateFullScreenStreamFrame('', false);
            }
        }
        if (isSharing) {
            ensureGridWebrtcStream(student);
        }
        if (placeholder) {
            placeholder.style.display = (isSharing && (student.snapshot || hasLiveVideo)) ? 'none' : 'block';
            const label = placeholder.querySelector('p');
            if (label) {
                label.textContent = isSharing
                    ? (student.snapshot ? 'Live feed paused' : 'Screen shared, waiting for live frame')
                    : 'Screen not shared';
            }
        }
        if (frameTime) {
            frameTime.innerHTML = hasLiveVideo
                ? `<i class="fas fa-video"></i> Live stream active: ${new Date().toLocaleTimeString()}`
                : (student.snapshot && isSharing
                    ? `<i class="fas fa-tv"></i> Live frame: ${student.latestSnapshotAt ? new Date(student.latestSnapshotAt).toLocaleTimeString() : new Date().toLocaleTimeString()}`
                    : `<i class="fas fa-clock"></i> Live stream: ${isSharing ? 'waiting' : 'not active'}`);
        }
    }

    function waitForGridIceGatheringComplete(peerConnection) {
        if (peerConnection.iceGatheringState === 'complete') return Promise.resolve();
        return new Promise(resolve => {
            const timeout = setTimeout(resolve, 8000);
            peerConnection.addEventListener('icegatheringstatechange', function onStateChange() {
                if (peerConnection.iceGatheringState === 'complete') {
                    clearTimeout(timeout);
                    peerConnection.removeEventListener('icegatheringstatechange', onStateChange);
                    resolve();
                }
            });
        });
    }

    function connectScreenMonitorSocket(examId) {
        if (!window.io || !examId) return null;
        if (screenMonitorSocket && screenMonitorExamId === String(examId)) {
            if (screenMonitorSocket.connected) {
                screenMonitorSocket.emit('lecturer-join-monitoring', { exam_id: String(examId) });
            }
            return screenMonitorSocket;
        }
        if (screenMonitorSocket) {
            screenMonitorSocket.disconnect();
            screenMonitorSocket = null;
        }
        screenMonitorExamId = String(examId);
        screenMonitorSocket = io({
            path: '/socket.io',
            transports: ['polling', 'websocket'],
            upgrade: true,
            reconnection: true,
            reconnectionAttempts: 12,
            reconnectionDelay: 800,
            auth: { token: screenMonitorSocketToken }
        });
        screenMonitorSocket.on('connect', () => {
            console.log('Lecturer screen socket connected', screenMonitorSocket.id);
            screenMonitorSocket.emit('lecturer-join-monitoring', { exam_id: String(examId) });
        });
        screenMonitorSocket.on('screen-share-roster', rows => {
            (rows || []).forEach(row => markStudentSocketSharing(row, true));
        });
        screenMonitorSocket.on('screen-share-started', row => markStudentSocketSharing(row, true));
        screenMonitorSocket.on('screen-share-stopped', row => markStudentSocketSharing(row, false));
        screenMonitorSocket.on('webrtc-offer', payload => answerLecturerScreenOffer(payload));
        screenMonitorSocket.on('webrtc-ice-candidate', async payload => {
            const entry = proctorGridWebrtcPeers[payload?.student_id];
            if (!entry || !entry.pc || !payload?.candidate) return;
            console.log('ICE candidate exchanged: lecturer received', payload);
            try {
                await entry.pc.addIceCandidate(payload.candidate);
            } catch (error) {}
        });
        screenMonitorSocket.on('connect_error', error => {
            console.error('Lecturer screen socket connect_error', error);
            toast('Live screen socket failed: ' + (error.message || 'network error'));
        });
        screenMonitorSocket.on('screen-share-error', message => {
            console.error('Lecturer screen-share-error', message);
            document.querySelectorAll('[id^="webrtcStatus_"]').forEach(el => {
                el.style.display = 'block';
                el.textContent = 'LIVE SOCKET ERROR: ' + message;
                el.style.color = '#fecaca';
                el.style.borderColor = 'rgba(239,68,68,.75)';
            });
            toast('Live screen error: ' + message);
        });
        return screenMonitorSocket;
    }

    function markStudentSocketSharing(row, isSharing) {
        const studentId = String(row?.student_id || '');
        if (!studentId) return;
        const student = activeProctoringStudents.find(s => String(s.id) === studentId);
        if (student) {
            student.screenSharing = !!isSharing;
            student.socketSharing = !!isSharing;
            student.status = isSharing ? 'active' : 'offline';
            student.socketSessionId = row.session_id || student.socketSessionId || '';
            student.lastActivity = row.last_updated || new Date().toISOString();
            updateProctorCardLiveState(student);
            updateProctoringStats(activeProctoringStudents);
        }
        if (!isSharing) stopGridWebrtcStream(studentId);
    }

    function ensureGridWebrtcStream(student) {
        if (!student || !student.id || proctorGridWebrtcPeers[student.id]?.state === 'connected') return;
        const socket = connectScreenMonitorSocket(currentProctoringExam);
        const status = document.getElementById(`webrtcStatus_${student.id}`);
        if (status) {
            status.style.display = 'block';
            status.textContent = socket?.connected ? 'WAITING FOR STUDENT LIVE OFFER' : 'WAITING FOR LIVE SOCKET';
            status.style.color = '#bfdbfe';
            status.style.borderColor = 'rgba(96,165,250,.55)';
        }
        answerLecturerHttpScreenOffer(student);
    }

    function attachLecturerLiveStream(studentId, stream, label = 'Live stream') {
        const video = document.getElementById(`screen-video-${studentId}`);
        if (!video) return;
        video.srcObject = stream;
        video.style.display = 'block';
        const img = document.getElementById(`screenImg_${studentId}`);
        const placeholder = document.getElementById(`screenPlaceholder_${studentId}`);
        const frameTime = document.getElementById(`screenFrameTime_${studentId}`);
        const statusTag = document.getElementById(`screenStatusTag_${studentId}`);
        const status = document.getElementById(`webrtcStatus_${studentId}`);
        const student = activeProctoringStudents.find(s => String(s.id) === String(studentId));
        if (student) {
            student.screenSharing = true;
            student.lastActivity = new Date().toISOString();
        }
        if (img) img.style.display = 'none';
        if (placeholder) placeholder.style.display = 'none';
        if (frameTime) frameTime.innerHTML = `<i class="fas fa-video"></i> Live stream active: ${new Date().toLocaleTimeString()}`;
        if (statusTag) {
            statusTag.style.background = '#10b981';
            statusTag.innerHTML = '<i class="fas fa-video"></i> Sharing Live';
        }
        if (status) {
            status.textContent = `${label.toUpperCase()} CONNECTED`;
            status.style.color = '#bbf7d0';
            status.style.borderColor = 'rgba(34,197,94,.65)';
        }
    }

    async function answerLecturerHttpScreenOffer(student) {
        if (!student || !student.id || !currentProctoringExam || !window.RTCPeerConnection) return;
        const studentId = String(student.id);
        const existing = proctorGridWebrtcPeers[studentId];
        if (existing && ['connected', 'connecting'].includes(existing.state)) return;
        if ((proctorGridWebrtcRetryAt[studentId] || 0) > Date.now()) return;
        proctorGridWebrtcRetryAt[studentId] = Date.now() + 2500;
        const status = document.getElementById(`webrtcStatus_${studentId}`);
        try {
            const result = await apiRequest('webrtc_fetch_student_offer', {
                exam_id: currentProctoringExam,
                student_id: studentId
            });
            if (!result.success || !result.data || !result.data.offer) return;
            const streamKey = result.data.stream_key;
            if (proctorGridWebrtcPeers[studentId]?.streamKey === streamKey && proctorGridWebrtcPeers[studentId]?.state === 'connected') return;
            if (proctorGridWebrtcPeers[studentId]?.pc) {
                try { proctorGridWebrtcPeers[studentId].pc.close(); } catch (error) {}
            }
            if (status) {
                status.style.display = 'block';
                status.textContent = 'CONNECTING LIVE VIDEO';
                status.style.color = '#bfdbfe';
                status.style.borderColor = 'rgba(96,165,250,.55)';
            }
            const peerConnection = new RTCPeerConnection({
                iceServers: QODA_WEBRTC_ICE_SERVERS,
                bundlePolicy: 'max-bundle',
                rtcpMuxPolicy: 'require'
            });
            proctorGridWebrtcPeers[studentId] = { pc: peerConnection, state: 'connecting', streamKey, mode: 'http' };
            peerConnection.ontrack = event => {
                console.log('Lecturer HTTP video ontrack fired', event);
                const stream = event.streams && event.streams[0] ? event.streams[0] : new MediaStream([event.track]);
                proctorGridWebrtcPeers[studentId].state = 'connected';
                attachLecturerLiveStream(studentId, stream, 'Live video');
            };
            peerConnection.onconnectionstatechange = () => {
                const state = peerConnection.connectionState;
                if (state === 'connected') {
                    proctorGridWebrtcPeers[studentId].state = 'connected';
                }
                if (['failed', 'disconnected', 'closed'].includes(state)) {
                    proctorGridWebrtcRetryAt[studentId] = Date.now() + 3000;
                    if (proctorGridWebrtcPeers[studentId]?.pc === peerConnection) {
                        stopGridWebrtcStream(studentId);
                    }
                }
            };
            await peerConnection.setRemoteDescription(JSON.parse(result.data.offer));
            const answer = await peerConnection.createAnswer();
            await peerConnection.setLocalDescription(answer);
            await waitForGridIceGatheringComplete(peerConnection);
            const answerResult = await apiRequest('webrtc_submit_monitor_answer', {
                stream_key: streamKey,
                answer: JSON.stringify(peerConnection.localDescription)
            });
            if (!answerResult.success) {
                throw new Error(answerResult.error || 'Unable to send live answer');
            }
            console.log('Lecturer sent HTTP WebRTC answer', { studentId, streamKey });
            if (status) status.textContent = 'LIVE ANSWER SENT';
        } catch (error) {
            console.error('Lecturer HTTP WebRTC answer failed:', error);
            proctorGridWebrtcRetryAt[studentId] = Date.now() + 4000;
            if (status) {
                status.style.display = 'block';
                status.textContent = 'LIVE VIDEO RETRYING';
                status.style.color = '#fde68a';
                status.style.borderColor = 'rgba(245,158,11,.65)';
            }
            if (proctorGridWebrtcPeers[studentId]?.state !== 'connected') {
                stopGridWebrtcStream(studentId);
            }
        }
    }

    async function answerLecturerScreenOffer(payload) {
        if (!payload?.student_id || !payload?.offer || !payload?.student_socket_id) return;
        console.log('Lecturer received offer', payload);
        const studentId = String(payload.student_id);
        const student = activeProctoringStudents.find(s => String(s.id) === studentId);
        if (student) {
            student.screenSharing = true;
            student.socketSessionId = payload.session_id || student.socketSessionId || '';
            updateProctorCardLiveState(student);
        }
        const video = document.getElementById(`screen-video-${studentId}`);
        if (!video || !window.RTCPeerConnection) return;
        if (proctorGridWebrtcPeers[studentId]?.pc) {
            try { proctorGridWebrtcPeers[studentId].pc.close(); } catch (error) {}
        }
        const status = document.getElementById(`webrtcStatus_${studentId}`);
        if (status) {
            status.style.display = 'block';
            status.textContent = 'CONNECTING LIVE VIDEO';
            status.style.color = '#bfdbfe';
            status.style.borderColor = 'rgba(96,165,250,.55)';
        }
        const peerConnection = new RTCPeerConnection({
            iceServers: QODA_WEBRTC_ICE_SERVERS,
            bundlePolicy: 'max-bundle',
            rtcpMuxPolicy: 'require'
        });
        proctorGridWebrtcPeers[studentId] = { pc: peerConnection, state: 'connecting', sessionId: payload.session_id || '', studentSocketId: payload.student_socket_id };

        peerConnection.onicecandidate = event => {
            if (event.candidate && screenMonitorSocket) {
                console.log('ICE candidate exchanged: lecturer sent', event.candidate);
                screenMonitorSocket.emit('webrtc-ice-candidate', {
                    to: payload.student_socket_id,
                    student_socket_id: payload.student_socket_id,
                    exam_id: String(currentProctoringExam),
                    student_id: studentId,
                    session_id: String(payload.session_id || ''),
                    candidate: event.candidate
                });
            }
        };
        peerConnection.ontrack = event => {
            console.log('Lecturer video ontrack fired', event);
            const stream = event.streams && event.streams[0] ? event.streams[0] : new MediaStream([event.track]);
            proctorGridWebrtcPeers[studentId].state = 'connected';
            attachLecturerLiveStream(studentId, stream, 'Live video');
        };
        peerConnection.onconnectionstatechange = () => {
            const state = peerConnection.connectionState;
            if (state === 'connected') {
                proctorGridWebrtcPeers[studentId].state = 'connected';
            }
            if (state === 'disconnected') {
                const status = document.getElementById(`webrtcStatus_${studentId}`);
                if (status) status.textContent = 'LIVE VIDEO BUFFERING';
                setTimeout(() => {
                    const entry = proctorGridWebrtcPeers[studentId];
                    if (entry && entry.pc === peerConnection && peerConnection.connectionState === 'disconnected') {
                        proctorGridWebrtcRetryAt[studentId] = Date.now() + 2000;
                        stopGridWebrtcStream(studentId);
                    }
                }, 5000);
            }
            if (['failed', 'closed'].includes(state)) {
                stopGridWebrtcStream(studentId);
            }
        };

        try {
            await peerConnection.setRemoteDescription(payload.offer);
            const answer = await peerConnection.createAnswer();
            console.log('Lecturer created answer', answer);
            await peerConnection.setLocalDescription(answer);
            screenMonitorSocket.emit('webrtc-answer', {
                to: payload.student_socket_id,
                student_socket_id: payload.student_socket_id,
                exam_id: String(currentProctoringExam),
                student_id: studentId,
                session_id: String(payload.session_id || ''),
                answer: peerConnection.localDescription
            });
            console.log('Lecturer sent answer', { studentId, studentSocketId: payload.student_socket_id });
        } catch (error) {
            console.error('Lecturer WebRTC answer failed:', error);
            proctorGridWebrtcRetryAt[studentId] = Date.now() + 3000;
            if (status) {
                status.textContent = 'SNAPSHOT FALLBACK';
                status.style.color = '#fde68a';
                status.style.borderColor = 'rgba(245,158,11,.65)';
            }
            stopGridWebrtcStream(studentId);
        }
    }

    function stopGridWebrtcStream(studentId) {
        const entry = proctorGridWebrtcPeers[studentId];
        if (!entry) return;
        try {
            if (entry.pc) entry.pc.close();
        } catch (error) {}
        const video = document.getElementById(`screen-video-${studentId}`);
        if (video) {
            video.srcObject = null;
            video.style.display = 'none';
        }
        const status = document.getElementById(`webrtcStatus_${studentId}`);
        if (status) {
            status.textContent = 'RECONNECTING LIVE VIDEO';
            status.style.color = '#fde68a';
            status.style.borderColor = 'rgba(245,158,11,.65)';
        }
        delete proctorGridWebrtcPeers[studentId];
    }

    function openLiveScreenPreview(studentId) {
        const student = activeProctoringStudents.find(s => String(s.id) === String(studentId));
        if (!student) return;
        const sourceVideo = document.getElementById(`screen-video-${studentId}`);
        const modal = document.getElementById('fullScreenProctorModal');
        const contentDiv = document.getElementById('fullScreenContent');
        const nameEl = document.getElementById('fullScreenStudentName');
        const idEl = document.getElementById('fullScreenStudentId');
        if (!modal || !contentDiv) return;
        currentFullScreenStudent = student;
        if (nameEl) nameEl.innerHTML = `<i class="fas fa-tv"></i> ${escapeHTML(student.full_name || 'Student')} Live Screen`;
        if (idEl) idEl.textContent = `ID: ${student.student_id || student.id} | ${student.screenSharing ? 'Sharing Screen' : 'Not Sharing'}`;
        contentDiv.innerHTML = `
            <div style="width:100%;height:100%;background:#000;display:flex;align-items:center;justify-content:center;position:relative;">
                <img id="fullScreenImg" src="${student.snapshot ? `data:image/jpeg;base64,${student.snapshot}` : ''}" alt="Live shared screen" style="width:100%;height:100%;object-fit:contain;background:#000;${student.snapshot ? '' : 'display:none;'}">
                <video id="largeLiveScreenVideo" autoplay playsinline muted style="width:100%;height:100%;object-fit:contain;background:#000;${student.snapshot ? 'display:none;' : ''}"></video>
                <div id="fullScreenPlaceholder" style="position:absolute;inset:0;display:${student.snapshot ? 'none' : 'flex'};align-items:center;justify-content:center;text-align:center;color:#cbd5e1;font-weight:900;padding:24px;">
                    <div>
                        <i class="fas fa-desktop" style="font-size:44px;margin-bottom:12px;"></i>
                        <p style="margin:0;">${student.screenSharing ? 'Screen shared, waiting for live frame' : 'Screen not shared'}</p>
                        <small>${student.screenSharing ? 'A fresh frame should appear in a moment.' : 'The student must keep screen sharing active.'}</small>
                    </div>
                </div>
                <div id="fullLiveIndicator" class="live-indicator-full" style="position:absolute;top:20px;right:20px;background:#ef4444;color:white;padding:8px 16px;border-radius:8px;font-weight:800;${student.snapshot ? '' : 'display:none;'}">
                    <i class="fas fa-circle" style="font-size:10px;animation:blink 1s infinite;"></i> LIVE
                </div>
            </div>
        `;
        const largeVideo = document.getElementById('largeLiveScreenVideo');
        const placeholder = document.getElementById('fullScreenPlaceholder');
        if (largeVideo && sourceVideo && sourceVideo.srcObject && !student.snapshot) {
            largeVideo.srcObject = sourceVideo.srcObject;
            largeVideo.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        }
        modal.style.display = 'flex';
        startStudentStream(studentId);
    }

    function startScreenPolling() {
        if (proctoringLiveLoopActive) return;
        proctoringLiveLoopActive = true;

        const runLiveGridLoop = async () => {
            if (!proctoringLiveLoopActive || !currentProctoringExam) {
                proctoringLiveLoopActive = false;
                proctoringInterval = null;
                return;
            }
            await fetchScreenUpdates();
            proctoringInterval = setTimeout(runLiveGridLoop, PROCTOR_POLL_INTERVAL_MS);
        };

        runLiveGridLoop();
    }

    function updateProctoringStats(students) {
        const writing = students.length;
        const sharing = students.filter(s => s.screenSharing === true).length;
        const notSharing = writing - sharing;
        const violations = students.reduce((sum, s) => sum + (s.violationCount || 0), 0);

        const studentsWritingEl = document.getElementById('studentsWriting');
        const screensSharingEl = document.getElementById('screensSharing');
        const screensNotSharingEl = document.getElementById('screensNotSharing');
        const violationCountEl = document.getElementById('violationCount');

        if (studentsWritingEl) studentsWritingEl.textContent = writing;
        if (screensSharingEl) screensSharingEl.textContent = sharing;
        if (screensNotSharingEl) screensNotSharingEl.textContent = notSharing;
        if (violationCountEl) violationCountEl.textContent = violations;
    }

    function renderLockedStudentsList(students) {
        const panel = document.getElementById('lockedStudentsPanel');
        const list = document.getElementById('lockedStudentsList');
        if (!panel || !list) return;
        const locked = (students || []).filter(student => student.screenLocked);
        panel.style.display = locked.length ? 'block' : 'none';
        list.innerHTML = locked.map(student => `
            <button class="btn warn small" onclick="viewViolationEvidence(${student.id})" style="display:inline-flex;align-items:center;gap:8px;">
                <i class="fas fa-user-lock"></i>
                <span>${escapeHTML(student.full_name || 'Student')}</span>
                <small style="opacity:.8;">${escapeHTML(student.student_id || '')}</small>
            </button>
        `).join('');
    }

    async function viewViolationEvidence(studentId = null) {
        if (!currentProctoringExam) {
            toast('Please select an exam first');
            return;
        }
        try {
            const payload = { exam_id: currentProctoringExam };
            if (studentId) payload.student_id = studentId;
            const result = await apiRequest('get_violation_evidence', payload);
            if (!result.success) {
                toast('Failed to load evidence');
                return;
            }
            const rows = result.data || [];
            const selectedStudent = studentId ? activeProctoringStudents.find(s => String(s.id) === String(studentId)) : null;
            const grouped = rows.reduce((acc, row) => {
                const key = row.student_id || row.student_identifier || 'unknown';
                if (!acc[key]) {
                    acc[key] = {
                        studentId: row.student_id,
                        identifier: row.student_identifier || '',
                        name: row.full_name || 'Student',
                        rows: []
                    };
                }
                acc[key].rows.push(row);
                return acc;
            }, {});
            const groups = Object.values(grouped);
            const evidenceCards = groups.map(group => `
                <details class="qcard" style="padding:14px;" ${studentId ? 'open' : ''}>
                    <summary style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <span>
                            <strong style="color:var(--text);">${escapeHTML(group.name)}</strong>
                            <small style="display:block;color:var(--muted);">${escapeHTML(group.identifier)} | ${group.rows.length} evidence item(s)</small>
                        </span>
                        <span class="tag" style="background:#ef4444;color:white;"><i class="fas fa-folder-open"></i> Open</span>
                    </summary>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:14px;">
                        ${group.rows.map((row, index) => {
                            const imageSrc = row.snapshot ? `data:image/jpeg;base64,${row.snapshot}` : (row.image_path ? `../${row.image_path}` : '');
                            const downloadName = `${(group.identifier || 'student').replace(/[^A-Za-z0-9_-]/g, '_')}_${(row.event_type || 'evidence').replace(/[^A-Za-z0-9_-]/g, '_')}_${index + 1}.jpg`;
                            return `
                                <div style="border:1px solid var(--border);border-radius:10px;padding:10px;background:var(--panel-2,#0f172a);">
                                    <div style="font-weight:800;color:var(--text);">${escapeHTML(row.event_type || 'Evidence')}</div>
                                    <div class="small">${escapeHTML(row.created_at || '')}</div>
                                    <p style="font-size:12px;color:var(--muted);line-height:1.45;">${escapeHTML(row.details || 'No details recorded.')}</p>
                                    ${imageSrc ? `<img src="${imageSrc}" style="width:100%;border-radius:8px;border:1px solid var(--border);margin-bottom:8px;"><a class="btn small" download="${downloadName}" href="${imageSrc}" style="width:100%;justify-content:center;"><i class="fas fa-download"></i> Download Evidence</a>` : '<div class="empty-state" style="padding:18px;">No snapshot near this event</div>'}
                                </div>
                            `;
                        }).join('')}
                    </div>
                </details>
            `).join('');
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.display = 'flex';
            modal.innerHTML = `
                <div class="modal-content" style="width:min(1100px,96vw);max-height:90vh;overflow:auto;">
                    <div class="modal-header">
                        <h3><i class="fas fa-folder-open"></i> ${selectedStudent ? `${escapeHTML(selectedStudent.full_name)} Evidence` : 'Violation Evidence'}</h3>
                        <button class="btn" onclick="this.closest('.modal').remove()"><i class="fas fa-times"></i> Close</button>
                    </div>
                    ${rows.length ? `<div style="display:grid;gap:14px;">${evidenceCards}</div>` : '<div class="empty-state"><i class="fas fa-check-circle"></i><p>No violations recorded for this exam.</p></div>'}
                </div>
            `;
            document.body.appendChild(modal);
        } catch (error) {
            toast('Network error');
        }
    }

    async function reviewProctoredCourses() {
        try {
            const result = await apiRequest('get_proctored_courses');
            if (!result.success) {
                toast('Failed to load proctored courses');
                return;
            }
            const rows = result.data || [];
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.display = 'flex';
            modal.innerHTML = `
                <div class="modal-content" style="width:min(1000px,96vw);max-height:90vh;overflow:auto;">
                    <div class="modal-header">
                        <h3><i class="fas fa-history"></i> Proctored Courses</h3>
                        <button class="btn" onclick="this.closest('.modal').remove()"><i class="fas fa-times"></i> Close</button>
                    </div>
                    ${rows.length ? `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;">
                        ${rows.map(row => `
                            <div class="qcard" style="padding:14px;">
                                <div style="font-weight:900;color:var(--text);font-size:16px;">${escapeHTML(row.title || 'Exam')}</div>
                                <div class="small">${escapeHTML(row.course_code || '')} | ${escapeHTML(row.semester || '')}</div>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;">
                                    <span class="tag">${row.captured_students || 0} student(s)</span>
                                    <span class="tag" style="background:#ef4444;color:white;">${row.evidence_count || 0} evidence</span>
                                </div>
                                <div class="small">Last proctored: ${escapeHTML(row.last_proctored_at || 'N/A')}</div>
                                <button class="btn primary small" style="width:100%;margin-top:12px;" onclick="currentProctoringExam='${String(row.id).replace(/'/g, '')}'; viewViolationEvidence();">
                                    <i class="fas fa-folder-open"></i> Review Evidence
                                </button>
                            </div>
                        `).join('')}
                    </div>` : '<div class="empty-state"><i class="fas fa-folder-open"></i><p>No proctored courses have evidence yet.</p></div>'}
                </div>
            `;
            document.body.appendChild(modal);
        } catch (error) {
            toast('Network error');
        }
    }

    // View full screen for a specific student
    async function viewFullScreenProctor(studentId) {
        const student = activeProctoringStudents.find(s => s.id == studentId);
        if (!student) {
            toast('❌ Student not found');
            return;
        }

        currentFullScreenStudent = student;

        // Update modal headers
        const nameEl = document.getElementById('fullScreenStudentName');
        const idEl = document.getElementById('fullScreenStudentId');
        const violationTag = document.getElementById('violationTag');

        if (nameEl) nameEl.innerHTML = `<i class="fas fa-user-graduate"></i> ${escapeHTML(student.full_name)}`;
        if (idEl) idEl.textContent =
            `ID: ${student.student_id} | Level: ${student.level || 'N/A'} | Programme: ${student.programme || 'N/A'}`;

        if (violationTag) {
            if (student.violationCount > 0) {
                violationTag.style.display = 'inline-flex';
                violationTag.innerHTML =
                    `<i class="fas fa-exclamation-triangle"></i> Violations: ${student.violationCount}`;
            } else {
                violationTag.style.display = 'none';
            }
        }

        // Show modal
        const modal = document.getElementById('fullScreenProctorModal');
        if (modal) {
            modal.style.display = 'flex';
            if (modal.requestFullscreen && !document.fullscreenElement) {
                modal.requestFullscreen().catch(() => {});
            }

            // Load the full screen stream
            const contentDiv = document.getElementById('fullScreenContent');
            if (contentDiv) {
                contentDiv.innerHTML = `
                <div style="display: flex; justify-content: stretch; align-items: stretch; width: 100%; height: 100%; position: relative; background:#000;">
                    <div id="fullScreenStreamContainer" style="width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; background: #000; overflow:hidden;">
                        <img id="fullScreenImg" src="${student.snapshot ? `data:image/jpeg;base64,${student.snapshot}` : ''}" alt="Screen stream" style="width: 100%; height: 100%; object-fit: contain; image-rendering: auto; background:#000; ${student.snapshot ? '' : 'display:none;'}">
                        <div id="fullScreenPlaceholder" style="text-align:center;color:#9ca3af;padding:30px;${student.snapshot ? 'display:none;' : ''}">
                            <i class="fas fa-desktop" style="font-size:44px;margin-bottom:12px;"></i>
                            <p style="margin:0;font-weight:700;">${student.screenSharing ? 'Screen shared, waiting for live frame' : 'Screen not shared'}</p>
                            <small>${student.screenSharing ? 'A fresh frame should appear in a moment.' : 'The student must keep screen sharing active.'}</small>
                        </div>
                    </div>
                    <div id="fullLiveIndicator" class="live-indicator-full" style="position: absolute; top: 20px; right: 20px; background: #ef4444; color: white; padding: 8px 16px; border-radius: 8px; font-weight: 600; ${student.snapshot ? '' : 'display:none;'}">
                        <i class="fas fa-circle" style="font-size: 10px; animation: blink 1s infinite;"></i> LIVE STREAM
                    </div>
                </div>
            `;
            }

            // Start streaming for this student
            startStudentStream(studentId);
        }
    }

    function updateFullScreenStreamFrame(snapshot, isSharing = true) {
        const fullScreenImg = document.getElementById('fullScreenImg');
        const placeholder = document.getElementById('fullScreenPlaceholder');
        const liveIndicator = document.getElementById('fullLiveIndicator');
        const largeVideo = document.getElementById('largeLiveScreenVideo');
        if (fullScreenImg && snapshot && isSharing) {
            fullScreenImg.src = `data:image/jpeg;base64,${snapshot}`;
            fullScreenImg.style.display = 'block';
            if (largeVideo) largeVideo.style.display = 'none';
        } else if (fullScreenImg && !isSharing) {
            fullScreenImg.removeAttribute('src');
            fullScreenImg.style.display = 'none';
        }
        if (placeholder) {
            placeholder.style.display = snapshot && isSharing ? 'none' : 'block';
        }
        if (liveIndicator) {
            liveIndicator.style.display = snapshot && isSharing ? 'block' : 'none';
        }
    }

    async function startStudentStream(studentId) {
        // Start polling for this specific student's screen
        if (window.studentStreamInterval) {
            clearInterval(window.studentStreamInterval);
        }

        const refreshStudentStream = async () => {
            try {
                const result = await apiRequest('get_student_screen', {
                    student_id: studentId,
                    exam_id: currentProctoringExam
                });

                const isSharing = result.success && result.data && (parseInt(result.data.screen_sharing_active || 0) === 1 || (!!result.data.snapshot && isFreshProctorFrame(result.data.captured_at)));
                const hasFrame = result.success && result.data && result.data.snapshot;
                if (hasFrame) {
                    updateFullScreenStreamFrame(result.data.snapshot, isSharing);

                    // Update violation count if needed
                    if (result.data.violations && currentFullScreenStudent) {
                        const violationTag = document.getElementById('violationTag');
                        if (violationTag) {
                            violationTag.style.display = 'inline-flex';
                            violationTag.innerHTML =
                                `<i class="fas fa-exclamation-triangle"></i> Violations: ${result.data.violations}`;
                        }

                        // Update local student data
                        if (currentFullScreenStudent) {
                            currentFullScreenStudent.violationCount = result.data.violations;
                            const studentIndex = activeProctoringStudents.findIndex(s => s.id ==
                                studentId);
                            if (studentIndex >= 0) {
                                activeProctoringStudents[studentIndex].violationCount = result.data
                                    .violations;
                            }
                        }
                    }
                } else {
                    const placeholder = document.getElementById('fullScreenPlaceholder');
                    updateFullScreenStreamFrame('', false);
                    if (placeholder) {
                        placeholder.style.display = 'block';
                        const label = placeholder.querySelector('p');
                        const small = placeholder.querySelector('small');
                        if (label) label.textContent = isSharing ? 'Screen shared, waiting for live frame' : 'Screen not shared';
                        if (small) small.textContent = isSharing ? 'A fresh frame should appear in a moment.' : 'The student must keep screen sharing active.';
                    }
                }
            } catch (error) {
                console.error('Error fetching student screen:', error);
            }
        };

        refreshStudentStream();
        window.studentStreamInterval = setInterval(refreshStudentStream, PROCTOR_POLL_INTERVAL_MS);
    }

    function closeFullScreenProctorModal() {
        const modal = document.getElementById('fullScreenProctorModal');
        if (modal) modal.style.display = 'none';

        // Stop the stream interval
        if (window.studentStreamInterval) {
            clearInterval(window.studentStreamInterval);
            window.studentStreamInterval = null;
        }
        if (document.fullscreenElement && document.fullscreenElement.id === 'fullScreenProctorModal') {
            document.exitFullscreen().catch(() => {});
        }

        currentFullScreenStudent = null;
    }

    function sendWarningToStudent() {
        if (currentFullScreenStudent) {
            sendWarningToStudentById(currentFullScreenStudent.id);
        }
    }

    async function controlProctoringExam(control, extra = {}) {
        if (!currentProctoringExam) {
            toast('Select an exam to proctor first.');
            return;
        }
        const result = await apiRequest('manage_exam_time', {
            exam_id: currentProctoringExam,
            control,
            ...extra
        });
        toast(result.success ? (result.message || 'Exam control updated.') : (result.error || 'Could not update exam control.'));
        if (result.success) {
            refreshProctoring();
        }
    }

    function promptAddProctoringTimeAll() {
        if (!currentProctoringExam) {
            toast('Select an exam first.');
            return;
        }
        const minutes = parseInt(prompt('Add how many minutes to ALL students?', '10') || '0', 10);
        if (minutes > 0) {
            controlProctoringExam('add_time', { minutes });
        } else if (minutes !== 0) {
            toast('Enter valid minutes.');
        }
    }

    function promptProctoringMessageAll() {
        if (!currentProctoringExam) {
            toast('Select an exam first.');
            return;
        }
        const message = prompt('Message to send to all students in this exam:');
        if (message && message.trim()) {
            controlProctoringExam('announcement', { message: message.trim() });
        }
    }

    async function promptAddTimeToStudent(studentId) {
        if (!currentProctoringExam) {
            toast('Select an exam first.');
            return;
        }
        const student = activeProctoringStudents.find(s => String(s.id) === String(studentId));
        const minutes = parseInt(prompt(`Add how many minutes to ${student?.full_name || 'this student'}?`, '5') || '0', 10);
        if (!Number.isFinite(minutes) || minutes <= 0) {
            toast('Enter valid minutes.');
            return;
        }
        const reason = prompt('Reason for individual extra time:', 'Lecturer granted individual extra time') || 'Lecturer granted individual extra time';
        try {
            const result = await apiRequest('add_student_exam_time', {
                exam_id: currentProctoringExam,
                student_id: studentId,
                minutes,
                reason
            });
            toast(result.success ? (result.message || 'Extra time added.') : (result.error || 'Could not add extra time.'));
        } catch (error) {
            toast('Network error');
        }
    }

    async function promptMessageStudent(studentId) {
        if (!currentProctoringExam) {
            toast('Select an exam first.');
            return;
        }
        const student = activeProctoringStudents.find(s => String(s.id) === String(studentId));
        const message = prompt(`Message to ${student?.full_name || 'this student'}:`);
        if (!message || !message.trim()) return;
        try {
            const result = await apiRequest('send_message_to_student', {
                exam_id: currentProctoringExam,
                student_id: studentId,
                message: message.trim()
            });
            toast(result.success ? 'Message sent to student.' : (result.error || 'Could not send message.'));
        } catch (error) {
            toast('Network error');
        }
    }

    async function sendWarningToStudentById(studentId) {
        try {
            const result = await apiRequest('send_warning_to_student', {
                student_id: studentId,
                exam_id: currentProctoringExam,
                warning: "⚠️ Warning: Please follow exam rules. Screen sharing and tab switching are being monitored."
            });

            if (result.success) {
                toast(`⚠️ Warning sent to student`);

                // Log violation
                const student = activeProctoringStudents.find(s => s.id == studentId);
                if (student) {
                    student.violationCount = (student.violationCount || 0) + 1;
                    updateProctoringStats(activeProctoringStudents);
                    renderProctoringGrid(activeProctoringStudents);
                }
            } else {
                toast('❌ Failed to send warning');
            }
        } catch (error) {
            toast('❌ Network error');
        }
    }

    function sendWarningToAllProctoring() {
        if (!currentProctoringExam) {
            toast('❌ No exam selected');
            return;
        }

        if (confirm('Send warning to ALL students currently taking this exam?')) {
            activeProctoringStudents.forEach(student => {
                if (student.status === 'active') {
                    sendWarningToStudentById(student.id);
                }
            });
            toast('⚠️ Warnings sent to all active students');
        }
    }

    async function lockStudentScreenFromModal() {
        if (currentFullScreenStudent) {
            await lockStudentScreenById(currentFullScreenStudent.id);
        }
    }

    async function unlockStudentScreenFromModal() {
        if (currentFullScreenStudent) {
            await unlockStudentScreenById(currentFullScreenStudent.id);
        }
    }

    async function lockStudentScreenById(studentId) {
        try {
            const result = await apiRequest('lock_student_screen', {
                student_id: studentId,
                exam_id: currentProctoringExam
            });

            if (result.success) {
                toast(`🔒 Screen locked for student`);

                // Update UI to show locked status
                const student = activeProctoringStudents.find(s => s.id == studentId);
                if (student) {
                    student.screenLocked = true;
                    renderProctoringGrid(activeProctoringStudents);
                }
            } else {
                toast('❌ Failed to lock screen');
            }
        } catch (error) {
            toast('❌ Network error');
        }
    }

    async function unlockStudentScreenById(studentId) {
        try {
            const result = await apiRequest('unlock_student_screen', {
                student_id: studentId,
                exam_id: currentProctoringExam
            });

            if (result.success) {
                const student = activeProctoringStudents.find(s => s.id == studentId);
                if (student) student.screenLocked = false;
                toast('Screen unlocked for student');
                renderProctoringGrid(activeProctoringStudents);
            } else {
                toast('Failed to unlock screen');
            }
        } catch (error) {
            toast('Network error');
        }
    }

    async function unlockAllScreens() {
        if (!currentProctoringExam) {
            toast('Please select an exam first');
            return;
        }
        if (!(await confirmPopup('Unlock all locked student screens for this exam?', 'Unlock all screens', 'Unlock All'))) return;
        try {
            const result = await apiRequest('unlock_all_screens', {
                exam_id: currentProctoringExam
            });
            if (result.success) {
                activeProctoringStudents.forEach(student => student.screenLocked = false);
                toast(`Unlocked ${result.count || 0} screen(s)`);
                renderProctoringGrid(activeProctoringStudents);
            } else {
                toast('Failed to unlock screens');
            }
        } catch (error) {
            toast('Network error');
        }
    }

    function takeSnapshotFromModal() {
        if (currentFullScreenStudent) {
            takeSnapshot(currentFullScreenStudent.id);
        }
    }

    async function takeSnapshot(studentId) {
        try {
            const result = await apiRequest('take_snapshot', {
                student_id: studentId,
                exam_id: currentProctoringExam
            });

            if (result.success) {
                toast(`📸 Snapshot captured for student - saved to evidence log`);

                fetchScreenUpdates();

                // Show snapshot preview
                if (result.data && result.data.snapshot) {
                    const snapshotModal = document.createElement('div');
                    snapshotModal.className = 'modal';
                    snapshotModal.style.display = 'flex';
                    snapshotModal.innerHTML = `
                    <div class="modal-content" style="width: 600px;">
                        <h3><i class="fas fa-camera"></i> Evidence Snapshot</h3>
                        <img src="data:image/jpeg;base64,${result.data.snapshot}" style="width: 100%; border-radius: 8px; margin: 15px 0;">
                        <p style="color: var(--muted); font-size: 12px;">Taken at: ${new Date().toLocaleString()}</p>
                        <div class="modal-actions">
                            <button class="btn" onclick="this.parentElement.parentElement.parentElement.remove()">Close</button>
                        </div>
                    </div>
                `;
                    document.body.appendChild(snapshotModal);

                    setTimeout(() => {
                        if (snapshotModal && snapshotModal.parentElement) {
                            snapshotModal.remove();
                        }
                    }, 5000);
                }
            } else {
                toast('❌ ' + (result.error || 'Failed to take snapshot'));
            }
        } catch (error) {
            toast('❌ Network error');
        }
    }

    // Add CSS for animations
    const proctoringStyles = document.createElement('style');
    proctoringStyles.textContent = `
    @keyframes blink {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    
    .student-proctor-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.25);
    }
    
    .live-indicator-full i {
        animation: blink 1s infinite;
    }
`;
    document.head.appendChild(proctoringStyles);

    // ============================================
    // BULK STUDENT IMPORT/EXPORT FUNCTIONS
    // ============================================
