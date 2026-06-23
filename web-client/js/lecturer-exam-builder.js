    // ============================================
    // 4. EXAM MANAGEMENT FUNCTIONS
    // ============================================

    function getExams() {
        return readJSON(K_EXAMS, []);
    }

    function setExams(e) {
        writeJSON(K_EXAMS, e);
    }

    function findExam(id) {
        const numericId = parseInt(id);
        return getExams().find(e => parseInt(e.id) === numericId) || null;
    }

    function getDraftBackups() {
        return readJSON(K_EXAM_DRAFTS, []);
    }

    function setDraftBackups(drafts) {
        writeJSON(K_EXAM_DRAFTS, drafts);
    }

    function getLocalDraftById(id) {
        const numericId = parseInt(id);
        return getDraftBackups().find(exam => parseInt(exam.id) === numericId) || null;
    }

    function backupExamDraft(exam = null) {
        const target = exam || findExam(currentExamId);
        if (!target || !target.id) return;
        const copy = JSON.parse(JSON.stringify(target));
        copy.localDraftSavedAt = new Date().toISOString();
        const drafts = getDraftBackups().filter(draft => parseInt(draft.id) !== parseInt(copy.id));
        drafts.unshift(copy);
        setDraftBackups(drafts.slice(0, 25));
        localStorage.setItem(K_LAST_BUILDER_EXAM, String(copy.id));
        localStorage.setItem("currentView", "builder");
        localStorage.setItem("currentRouteParams", JSON.stringify({ id: copy.id }));
    }

    function removeDraftBackup(...ids) {
        const numericIds = ids.map(id => parseInt(id)).filter(id => !Number.isNaN(id));
        if (!numericIds.length) return;
        setDraftBackups(getDraftBackups().filter(draft => !numericIds.includes(parseInt(draft.id))));
    }

    function mergeExamsWithLocalDrafts(serverExams) {
        const merged = [...serverExams];
        const byId = new Map(merged.map((exam, index) => [parseInt(exam.id), index]));
        const localOnly = [];
        const localOnlyIds = new Set();
        [...getExams(), ...getDraftBackups()].forEach(draft => {
            const id = parseInt(draft?.id);
            if (Number.isNaN(id)) return;
            if (id >= 0) return;
            const existingIndex = byId.get(id);
            if (existingIndex === undefined) {
                if (!localOnlyIds.has(id)) {
                    localOnlyIds.add(id);
                    localOnly.push(draft);
                }
                return;
            }
            merged[existingIndex] = { ...merged[existingIndex], ...draft };
        });
        const publishedServerIds = serverExams
            .filter(exam => exam.published == 1 || String(exam.status || '').toLowerCase() === 'published')
            .map(exam => exam.id);
        removeDraftBackup(...publishedServerIds);
        return [...localOnly, ...merged];
    }

    async function loadExamsList() {
        showLoading('Loading exams...');
        try {
            const data = await apiRequest('get_exams');
            if (data.success && data.data) {
                const exams = data.data.map(exam => {
                    let questions = [];
                    if (exam.questions) {
                        if (typeof exam.questions === 'string') {
                            try {
                                questions = JSON.parse(exam.questions);
                            } catch (e) {
                                console.error("Failed to parse questions:", e);
                                questions = [];
                            }
                        } else if (Array.isArray(exam.questions)) {
                            questions = exam.questions;
                        }
                    }
                    return {
                        id: exam.id,
                        exam_code: exam.exam_code || exam.exam_id || '',
                        title: exam.title || '',
                        courseName: exam.course_name || exam.course_title || '',
                        courseCode: exam.course_code || '',
                        durationMins: exam.duration_minutes || 180,
                        startAtISO: exam.start_datetime || '',
                        endAtISO: exam.end_datetime || '',
                        gracePeriodMinutes: parseInt(exam.grace_period_minutes || 0, 10),
                        cutoffAtISO: exam.cutoff_datetime || '',
                        examControlStatus: exam.exam_control_status || 'active',
                        instructions: exam.instructions || '',
                        markingScheme: exam.marking_scheme || '',
                        published: exam.published == 1,
                        questions: questions,
                        questionsToAnswer: exam.questions_to_answer || 0,
                        shuffleEnabled: exam.shuffle_enabled == 1,
                        gradingMode: exam.grading_mode || 'auto',
                        exam_password: exam.exam_password || '',
                        school_name: exam.school_name || '',
                        faculty_name: exam.faculty_name || '',
                        department: exam.department || '',
                        semester: exam.semester || '',
                        exam_type: exam.exam_type || '',
                        school_type: exam.school_type || '',
                        level: exam.level || '',
                        academic_year: exam.academic_year || '',
                        auto_grading_enabled: exam.auto_grading_enabled || 0,
                        partial_grading_enabled: exam.partial_grading_enabled || 0,
                        show_correct_answers: exam.show_correct_answers || 0,
                        allow_review: exam.allow_review !== null ? exam.allow_review : 1,
                        assignedStudentsCount: parseInt(exam.assigned_students_count || 0, 10),
                        submittedStudentsCount: parseInt(exam.submitted_students_count || 0, 10),
                        resultsPublished: parseInt(exam.results_published || 0, 10) === 1
                    };
                });
                const mergedExams = mergeExamsWithLocalDrafts(exams);
                setExams(mergedExams);
                renderExamsTable(mergedExams);
                populateProctoringExamSelect(mergedExams);
                populateMonitoringExamSelect(mergedExams);
                return mergedExams;
            } else {
                toast('❌ Failed to load exams: ' + (data.error || 'Unknown error'));
                renderExamsTable([]);
                return [];
            }
        } catch (error) {
            console.error('Error loading exams:', error);
            toast('❌ Error loading exams');
            renderExamsTable([]);
            return [];
        } finally {
            hideLoading();
        }
    }

    async function viewSubmissionDetails(submissionId) {
        showLoading('Loading submission details...');
        try {
            const result = await apiRequest('get_submission_details', {
                submission_id: submissionId
            });

            if (result.success && result.data) {
                const sub = result.data;
                let content = `
                <div style="padding: 20px;">
                    <div style="margin-bottom: 20px;">
                        <h3>${escapeHTML(sub.exam_title)}</h3>
                        <p><strong>Student:</strong> ${escapeHTML(sub.student_name)} (${escapeHTML(sub.student_identifier)})</p>
                        <p><strong>Submitted:</strong> ${new Date(sub.submitted_at).toLocaleString()}</p>
                        <p><strong>Score:</strong> ${sub.total_score} / ${sub.total_marks} (${sub.percentage}%)</p>
                        <p><strong>Status:</strong> ${sub.status}</p>
                    </div>
                    <div id="submissionAnswers">
                        <h4>Student Answers</h4>
            `;

                const answers = typeof sub.answers === 'string' ? JSON.parse(sub.answers) : sub.answers;

                for (const [qId, answer] of Object.entries(answers)) {
                    content += `
                    <div style="background: var(--bg); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                        <div style="font-weight: bold; margin-bottom: 10px;">Question ID: ${escapeHTML(qId)}</div>
                        <div style="margin-bottom: 10px;">
                            <strong>Answer:</strong>
                            <pre style="background: #1e1e1e; padding: 10px; border-radius: 8px; overflow-x: auto; color: #d4d4d4;">${escapeHTML(JSON.stringify(answer, null, 2))}</pre>
                        </div>
                    </div>
                `;
                }

                content += `</div></div>`;

                document.getElementById('previewContent').innerHTML = content;
                document.getElementById('previewModal').style.display = 'flex';
            }

        } catch (error) {
            console.error('Error:', error);
            toast('❌ Failed to load submission details');
        } finally {
            hideLoading();
        }
    }

    function renderExamsTable(exams) {
        const tableContainer = document.getElementById("examsTable");
        if (!tableContainer) return;

        if (!exams || exams.length === 0) {
            tableContainer.innerHTML =
                `<tbody><tr><td colspan="8" class="empty-state">📭 No exams yet. Click "Create New Exam" to get started!</td></tr></tbody>`;
            return;
        }

        const now = new Date();

        const rows = exams.map(exam => {
            // Determine exam state
            let examState = 'draft';
            let stateClass = 'status-draft';
            let canEdit = true;
            let canDelete = true;
            let completedDateTime = '';
            let showCompletedButton = false;

            if (exam.published) {
                const startTime = exam.startAtISO ? new Date(exam.startAtISO) : null;
                const endTime = exam.endTime ? new Date(exam.endTime) : (startTime ? new Date(startTime
                    .getTime() + (exam.durationMins * 60000)) : null);

                if (startTime && startTime > now) {
                    examState = 'scheduled';
                    stateClass = 'status-scheduled';
                    canEdit = true;
                    canDelete = true;
                } else if (startTime && startTime <= now && (!endTime || endTime > now)) {
                    examState = 'ongoing';
                    stateClass = 'status-ongoing';
                    canEdit = false;
                    canDelete = false;
                } else if (endTime && endTime <= now) {
                    examState = 'completed';
                    stateClass = 'status-completed';
                    canEdit = false;
                    canDelete = true;
                    showCompletedButton = true;
                    // Format the completion date/time
                    completedDateTime = endTime.toLocaleString();
                } else {
                    examState = 'published';
                    stateClass = 'status-published';
                    canEdit = true;
                    canDelete = true;
                }
            }

            let countdown = '';
            if (examState === 'scheduled' && exam.startAtISO) {
                const startTime = new Date(exam.startAtISO);
                const diffMs = startTime - now;
                if (diffMs > 0) {
                    const hours = Math.floor(diffMs / 3600000);
                    const minutes = Math.floor((diffMs % 3600000) / 60000);
                    countdown = `<span class="timer">Starts in: ${hours}h ${minutes}m</span>`;
                }
            } else if (examState === 'ongoing' && exam.durationMins) {
                const startTime = new Date(exam.startAtISO);
                const endTime = new Date(startTime.getTime() + (exam.durationMins * 60000));
                const diffMs = endTime - now;
                if (diffMs > 0) {
                    const minutes = Math.floor(diffMs / 60000);
                    const seconds = Math.floor((diffMs % 60000) / 1000);
                    countdown = `<span class="timer warning">Ends in: ${minutes}m ${seconds}s</span>`;
                }
            }

            const totalMarks = calculateEffectiveExamMarks(exam);

            return `
            <tr>
                <td><b style="color:var(--blue)">${escapeHTML(exam.title || '(untitled)')}</b><br><span class="small">📚 ${escapeHTML(exam.courseCode || 'No course')}</span>${examState !== 'draft' ? '<br>' + countdown : ''}${examState === 'completed' ? '<br><small style="color:var(--muted);">📅 Completed: ' + completedDateTime + '</small>' : ''}</td>
                <td><span class="tag">${escapeHTML(exam.id)}</span></td>
                <td>${(exam.questions || []).length} qns / <b>${totalMarks}</b> marks</span></td>
                <td><span class="tag ${stateClass}">${examState.toUpperCase()}</span></td>
                <td style="display:flex;gap:6px; flex-wrap:wrap;">
                    <button class="btn primary" onclick="openBuilder(${exam.id})" ${!canEdit ? 'disabled' : ''}>✏️ Edit</button>
                    <button class="btn" onclick="previewCompletedExam(${exam.id})" title="Preview exam as students see it">👁️ Preview</button>
                   <button class="btn" onclick="copyExamLinkFixed(currentExamId, document.getElementById('bTitle')?.value || 'Exam')">
    <i class="fas fa-link"></i> Copy Exam Link
</button>
                    ${showCompletedButton ?
                        `<button class="btn success" disabled style="background:#10b981; opacity:0.7;">
                            ✅ Completed <i class="fas fa-check-circle"></i>
                        </button>` :
                        `<button class="btn ${exam.published && examState !== 'ongoing' ? 'warn' : 'ok'}" onclick="togglePublish(${exam.id})" ${!canEdit ? 'disabled' : ''}>
                            ${exam.published ? (examState === 'ongoing' ? '🔴 Ongoing' : '📦 Unpublish') : '🚀 Publish'}
                        </button>`
                    }
                    <button class="btn danger" onclick="deleteExam(${exam.id})" ${!canDelete ? 'disabled' : ''}>🗑 Delete</button>
                </td>
            </tr>`;
        }).join('');

        tableContainer.innerHTML =
            `<thead><tr><th>Exam</th><th>ID</th><th>Questions/Marks</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows}</tbody>`;

    }

    function renderExamsTable(exams) {
        const tableContainer = document.getElementById("examsTable");
        if (!tableContainer) return;

        if (!exams || exams.length === 0) {
            tableContainer.innerHTML =
                `<div class="empty-state" style="grid-column:1/-1;">No exams yet. Click "Create New Exam" to get started.</div>`;
            return;
        }

        tableContainer.innerHTML = exams.map(exam => {
            const examState = getExamRuntimeState(exam);
            const stateLabel = examState === 'scheduled' ? 'Upcoming' :
                examState === 'ongoing' ? 'Ongoing' :
                examState === 'completed' ? 'Written/Completed' :
                examState === 'published' ? 'Published' : 'Draft';
            const stateClass = `status-${examState}`;
            const dates = getExamCardDateParts(exam);
            const totalMarks = calculateEffectiveExamMarks(exam);
            const assigned = parseInt(exam.assignedStudentsCount || exam.assigned_students_count || 0, 10);
            const submitted = parseInt(exam.submittedStudentsCount || exam.submitted_students_count || 0, 10);
            const title = exam.title || '(untitled)';
            const courseName = exam.courseName || exam.course_name || title;
            const courseCode = exam.courseCode || exam.course_code || 'No course';
            const examCode = exam.exam_code || exam.exam_id || exam.id;
            const canDelete = examState !== 'ongoing';

            return `
            <article class="exam-created-card ${stateClass}" onclick="showExamDetailsPopup(event, ${exam.id})" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){showExamDetailsPopup(event, ${exam.id}); event.preventDefault();}">
                <div class="exam-created-header">
                    <div>
                        <h3 class="exam-created-title">${escapeHTML(courseName)}</h3>
                        <div class="exam-created-subtitle">${escapeHTML(courseCode)}</div>
                    </div>
                    <span class="tag ${stateClass}">${escapeHTML(stateLabel)}</span>
                </div>
                <div class="exam-created-hint">
                    <span>Click card to view details</span>
                    <span class="exam-created-toggle">Open details</span>
                </div>
                <div class="exam-created-details" id="examDetails_${exam.id}" hidden>
                    <div class="exam-created-meta">
                        <div class="exam-created-meta-item"><small>Exam Title</small><strong>${escapeHTML(title)}</strong></div>
                        <div class="exam-created-meta-item"><small>Exam Code</small><strong>${escapeHTML(String(examCode))}</strong></div>
                        <div class="exam-created-meta-item"><small>Date</small><strong>${escapeHTML(dates.date)}</strong></div>
                        <div class="exam-created-meta-item"><small>Start Time</small><strong>${escapeHTML(dates.startTime)}</strong></div>
                        <div class="exam-created-meta-item"><small>End Time</small><strong>${escapeHTML(dates.endTime)}</strong></div>
                        <div class="exam-created-meta-item"><small>Month / Year</small><strong>${escapeHTML(dates.monthYear)}</strong></div>
                        <div class="exam-created-meta-item"><small>Semester</small><strong>${escapeHTML(exam.semester || 'Not set')}</strong></div>
                        <div class="exam-created-meta-item"><small>School Type</small><strong>${escapeHTML(exam.school_type || 'Not set')}</strong></div>
                        <div class="exam-created-meta-item"><small>Academic Year</small><strong>${escapeHTML(exam.academic_year || dates.year || 'Not set')}</strong></div>
                        <div class="exam-created-meta-item"><small>Questions / Marks</small><strong>${(exam.questions || []).length} / ${totalMarks}</strong></div>
                        <div class="exam-created-meta-item"><small>Assigned / Submitted</small><strong>${assigned} / ${submitted}</strong></div>
                    </div>
                    <div class="exam-created-actions">
                        <button class="btn" onclick="previewCompletedExam(${exam.id})"><i class="fas fa-eye"></i> View Details</button>
                        <button class="btn primary" onclick="openBuilder(${exam.id})"><i class="fas fa-edit"></i> Edit Exam</button>
                        <button class="btn" onclick="openExamSubmissions(${exam.id})"><i class="fas fa-inbox"></i> View Submissions</button>
                        <button class="btn ok" onclick="openExamProctoring(${exam.id})"><i class="fas fa-desktop"></i> Monitor Exam</button>
                        <button class="btn" onclick="copyExamLinkFixed(${exam.id}, decodeURIComponent('${encodeURIComponent(title)}'))"><i class="fas fa-link"></i> Copy Link</button>
                        <button class="btn warn" onclick="reuseExam(${exam.id})"><i class="fas fa-copy"></i> Reuse Exam</button>
                        ${examState === 'ongoing' ? `<button class="btn warn" onclick="adjustExamTime(${exam.id})"><i class="fas fa-clock"></i> Adjust Time</button>` : ''}
                        <button class="btn danger" onclick="deleteExam(${exam.id})" ${!canDelete ? 'disabled' : ''}><i class="fas fa-trash"></i> Delete Exam</button>
                    </div>
                </div>
            </article>`;
        }).join('');
    }

    function toggleExamCardDetails(event, examId) {
        showExamDetailsPopup(event, examId);
    }

    function closeExamDetailsPopup() {
        const modal = document.getElementById('examDetailsPopup');
        if (modal) modal.remove();
    }

    function showExamDetailsPopup(event, examId) {
        const interactive = event?.target?.closest?.('button, a, input, select, textarea, .exam-created-actions');
        if (interactive) return;

        const exam = findExam(examId);
        if (!exam) {
            toast('Exam not found');
            return;
        }

        closeExamDetailsPopup();
        const examState = getExamRuntimeState(exam);
        const stateLabel = examState === 'scheduled' ? 'Upcoming' :
            examState === 'ongoing' ? 'Ongoing' :
            examState === 'completed' ? 'Written/Completed' :
            examState === 'published' ? 'Published' : 'Draft';
        const dates = getExamCardDateParts(exam);
        const totalMarks = calculateEffectiveExamMarks(exam);
        const assigned = parseInt(exam.assignedStudentsCount || exam.assigned_students_count || 0, 10);
        const submitted = parseInt(exam.submittedStudentsCount || exam.submitted_students_count || 0, 10);
        const title = exam.title || '(untitled)';
        const courseName = exam.courseName || exam.course_name || title;
        const courseCode = exam.courseCode || exam.course_code || 'No course';
        const examCode = exam.exam_code || exam.exam_id || exam.id;
        const canDelete = examState !== 'ongoing';

        const modal = document.createElement('div');
        modal.id = 'examDetailsPopup';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.66);z-index:10070;display:flex;align-items:center;justify-content:center;padding:22px;';
        modal.innerHTML = `
            <div style="background:#ffffff;color:#0f172a;border-radius:18px;max-width:860px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 30px 80px rgba(15,23,42,.35);border:1px solid #dbe3ef;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:22px 24px;border-bottom:1px solid #e2e8f0;background:#f8fafc;border-radius:18px 18px 0 0;">
                    <div>
                        <h2 style="margin:0 0 6px;font-size:24px;color:#0f172a;">${escapeHTML(courseName)}</h2>
                        <div style="font-weight:800;color:#475569;">${escapeHTML(courseCode)} | ${escapeHTML(title)}</div>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                        <span class="tag status-${examState}">${escapeHTML(stateLabel)}</span>
                        <button class="btn" onclick="closeExamDetailsPopup()">Close</button>
                    </div>
                </div>
                <div style="padding:22px 24px;">
                    <div class="exam-created-meta" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
                        <div class="exam-created-meta-item"><small>Exam Code</small><strong>${escapeHTML(String(examCode))}</strong></div>
                        <div class="exam-created-meta-item"><small>Date</small><strong>${escapeHTML(dates.date)}</strong></div>
                        <div class="exam-created-meta-item"><small>Start Time</small><strong>${escapeHTML(dates.startTime)}</strong></div>
                        <div class="exam-created-meta-item"><small>End Time</small><strong>${escapeHTML(dates.endTime)}</strong></div>
                        <div class="exam-created-meta-item"><small>Month / Year</small><strong>${escapeHTML(dates.monthYear)}</strong></div>
                        <div class="exam-created-meta-item"><small>Semester</small><strong>${escapeHTML(exam.semester || 'Not set')}</strong></div>
                        <div class="exam-created-meta-item"><small>School Type</small><strong>${escapeHTML(exam.school_type || 'Not set')}</strong></div>
                        <div class="exam-created-meta-item"><small>Academic Year</small><strong>${escapeHTML(exam.academic_year || dates.year || 'Not set')}</strong></div>
                        <div class="exam-created-meta-item"><small>Questions / Marks</small><strong>${(exam.questions || []).length} / ${totalMarks}</strong></div>
                        <div class="exam-created-meta-item"><small>Assigned / Submitted</small><strong>${assigned} / ${submitted}</strong></div>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:20px;">
                        <button class="btn" onclick="closeExamDetailsPopup(); previewCompletedExam(${exam.id})"><i class="fas fa-eye"></i> Preview</button>
                        <button class="btn primary" onclick="closeExamDetailsPopup(); openBuilder(${exam.id})"><i class="fas fa-edit"></i> Edit Exam</button>
                        <button class="btn" onclick="closeExamDetailsPopup(); openExamSubmissions(${exam.id})"><i class="fas fa-inbox"></i> View Submissions</button>
                        <button class="btn ok" onclick="closeExamDetailsPopup(); openExamProctoring(${exam.id})"><i class="fas fa-desktop"></i> Monitor Exam</button>
                        <button class="btn" onclick="copyExamLinkFixed(${exam.id}, decodeURIComponent('${encodeURIComponent(title)}'))"><i class="fas fa-link"></i> Copy Link</button>
                        <button class="btn warn" onclick="closeExamDetailsPopup(); reuseExam(${exam.id})"><i class="fas fa-copy"></i> Reuse Exam</button>
                        ${examState === 'ongoing' ? `<button class="btn warn" onclick="closeExamDetailsPopup(); adjustExamTime(${exam.id})"><i class="fas fa-clock"></i> Adjust Time</button>` : ''}
                        <button class="btn danger" onclick="closeExamDetailsPopup(); deleteExam(${exam.id})" ${!canDelete ? 'disabled' : ''}><i class="fas fa-trash"></i> Delete Exam</button>
                    </div>
                </div>
            </div>
        `;
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeExamDetailsPopup();
        });
        document.body.appendChild(modal);
    }

    function parseExamDateTime(value) {
        if (!value) return null;
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function getExamCardDateParts(exam) {
        const start = parseExamDateTime(exam.startAtISO || exam.start_datetime);
        let end = parseExamDateTime(exam.endAtISO || exam.end_datetime);
        if (start && (!end || end <= start)) {
            end = new Date(start.getTime() + ((parseInt(exam.durationMins || exam.duration_minutes, 10) || 180) * 60000));
        }
        const dateFormatter = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
        const timeFormatter = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });
        return {
            date: start ? dateFormatter.format(start) : 'Not scheduled',
            startTime: start ? timeFormatter.format(start) : 'Not set',
            endTime: end ? timeFormatter.format(end) : 'Not set',
            monthYear: start ? start.toLocaleString(undefined, { month: 'long', year: 'numeric' }) : 'Not set',
            year: start ? String(start.getFullYear()) : ''
        };
    }

    function openExamSubmissions(examId) {
        sessionStorage.setItem('currentSubmissionView', 'exam_instance');
        sessionStorage.setItem('currentSubmissionExamId', String(examId));
        go('submissions');
        loadSubmissions().then(() => {
            const groups = window.courseGroupsData || {};
            for (const course of Object.values(groups)) {
                const instance = Object.values(course.instances || {}).find(item => String(item.examId) === String(examId));
                if (instance) {
                    showExamInstanceDetails(course.courseKey, instance.instanceKey);
                    return;
                }
            }
        });
    }

    function openExamProctoring(examId) {
        go('proctoring');
        const select = document.getElementById('proctoringExamSelect');
        if (select) {
            select.value = String(examId);
            if (typeof startProctoring === 'function') startProctoring();
        }
    }

    async function adjustExamTime(examId) {
        const minutesRaw = prompt('Enter minutes to adjust the exam time. Use +15 to add time or -10 to reduce time:', '+15');
        if (minutesRaw === null) return;
        const delta = parseInt(minutesRaw, 10);
        if (!Number.isFinite(delta) || delta === 0) {
            toast('Please enter a valid positive or negative number of minutes.');
            return;
        }
        const reason = prompt('Reason for this time adjustment:', 'Lecturer time adjustment') || 'Lecturer time adjustment';
        const result = await apiRequest('adjust_exam_time', {
            exam_id: examId,
            delta_minutes: delta,
            reason
        });
        if (result.success) {
            toast(`Exam time adjusted by ${delta} minute(s).`);
            await loadExamsList();
        } else {
            toast('Unable to adjust time: ' + (result.error || 'Unknown error'));
        }
    }

    async function reuseExam(examId) {
        const approved = await confirmPopup(
            'Reuse this exam as a new draft? You can change the date, time, school type, semester, academic year, and assigned students before publishing.',
            'Reuse exam',
            'Create Draft'
        );
        if (!approved) return;
        const source = getExams().find(exam => String(exam.id) === String(examId));
        if (!source) {
            toast('Exam not found.');
            return;
        }
        const copy = JSON.parse(JSON.stringify(source));
        copy.id = -Date.now();
        copy.title = `${source.title || 'Exam'} (Reuse Draft)`;
        copy.exam_code = '';
        copy.published = false;
        copy.startAtISO = '';
        copy.endAtISO = '';
        copy.gracePeriodMinutes = 0;
        copy.cutoffAtISO = '';
        copy.submittedStudentsCount = 0;
        copy.assignedStudentsCount = 0;
        copy.questions = (copy.questions || []).map(question => ({ ...question, id: uid('Q') }));
        const exams = getExams();
        exams.unshift(copy);
        setExams(exams);
        currentExamId = copy.id;
        populateBuilderForm(copy);
        renderQuestions();
        go('builder', { id: copy.id });
        toast('Reusable draft created. Set the new schedule and publish when ready.');
    }

    function getExamRuntimeState(exam) {
        if (!exam || !exam.published) return 'draft';
        const now = new Date();
        const start = exam.startAtISO ? new Date(exam.startAtISO.replace(' ', 'T')) : null;
        let end = exam.endAtISO ? new Date(exam.endAtISO.replace(' ', 'T')) : null;
        if (start && (!end || end <= start)) {
            end = new Date(start.getTime() + ((parseInt(exam.durationMins) || 180) * 60000));
        }
        if (start && start > now) return 'scheduled';
        if (start && start <= now && (!end || end > now)) return 'ongoing';
        if (!start && exam.published) return 'published';
        return 'completed';
    }

    function populateProctoringExamSelect(exams = getExams()) {
        const select = document.getElementById('proctoringExamSelect');
        if (!select) return;
        const selected = select.value;
        const proctorable = exams.filter(exam => ['ongoing', 'published'].includes(getExamRuntimeState(exam)));
        select.innerHTML = '<option value="">Select Exam to Proctor</option>' + proctorable.map(exam =>
            `<option value="${exam.id}">${escapeHTML(exam.title || 'Untitled Exam')} (${escapeHTML(exam.courseCode || 'No course')})</option>`
        ).join('');
        if (selected && proctorable.some(exam => String(exam.id) === String(selected))) {
            select.value = selected;
        }
    }

    function populateMonitoringExamSelect(exams = getExams()) {
        const select = document.getElementById('monitoringExamSelect');
        if (!select) return;
        const selected = select.value;
        const monitorable = exams.filter(exam => exam.published || ['ongoing', 'published', 'scheduled'].includes(getExamRuntimeState(exam)));
        select.innerHTML = '<option value="">Select Exam to Monitor</option>' + monitorable.map(exam =>
            `<option value="${exam.id}">${escapeHTML(exam.title || 'Untitled Exam')} (${escapeHTML(exam.courseCode || 'No course')})</option>`
        ).join('');
        if (selected && monitorable.some(exam => String(exam.id) === String(selected))) {
            select.value = selected;
        }
    }

    async function loadMonitoringData() {
        const select = document.getElementById('monitoringExamSelect');
        const examId = select ? select.value : '';
        currentMonitoringExam = examId || null;
        if (monitoringInterval) {
            clearInterval(monitoringInterval);
            monitoringInterval = null;
        }
        if (!currentMonitoringExam) {
            activeMonitoringStudents = [];
            updateMonitoringDashboard([]);
            renderMonitoringGrid([]);
            return;
        }
        await refreshMonitoring();
        monitoringInterval = setInterval(refreshMonitoring, 5000);
    }

    async function refreshMonitoring() {
        if (!currentMonitoringExam) {
            const select = document.getElementById('monitoringExamSelect');
            currentMonitoringExam = select ? select.value : null;
        }
        if (!currentMonitoringExam) return;

        try {
            const examResult = await apiRequest('get_exam_details', { exam_id: currentMonitoringExam });
            if (!examResult.success || !examResult.data) {
                toast('Could not load exam monitoring details.');
                return;
            }
            const studentsResult = await apiRequest('get_course_students', {
                course_code: examResult.data.course_code,
                exam_id: currentMonitoringExam
            });
            const sessionsResult = await apiRequest('get_active_sessions', { exam_id: currentMonitoringExam });
            const students = studentsResult.success && Array.isArray(studentsResult.data) ? studentsResult.data : [];
            const sessions = sessionsResult.success && Array.isArray(sessionsResult.data) ? sessionsResult.data : [];
            activeMonitoringStudents = students.map(student => {
                const session = sessions.find(row => String(row.student_id) === String(student.id));
                const submitted = isSubmittedProctorRecord(session);
                const online = !!session && (parseInt(session.screen_sharing_active || 0, 10) === 1 || !!session.last_activity);
                const flagged = session ? parseInt(session.violations || 0, 10) : 0;
                return {
                    id: student.id,
                    student_id: student.student_id,
                    full_name: student.full_name,
                    level: student.level,
                    online,
                    writing: online && !submitted,
                    submitted,
                    disconnected: !online && !submitted,
                    flagged,
                    lastActivity: session ? (session.latest_snapshot_at || session.last_activity || '') : ''
                };
            });
            updateMonitoringDashboard(activeMonitoringStudents);
            renderMonitoringGrid(activeMonitoringStudents);
        } catch (error) {
            console.error('Monitoring refresh failed:', error);
            toast('Live monitoring refresh failed.');
        }
    }

    function updateMonitoringDashboard(students) {
        const total = students.length;
        const online = students.filter(student => student.online).length;
        const writing = students.filter(student => student.writing).length;
        const submitted = students.filter(student => student.submitted).length;
        const disconnected = students.filter(student => student.disconnected).length;
        const flagged = students.filter(student => student.flagged > 0).length;
        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };
        setText('registeredStudents', total);
        setText('activeStudents', online);
        setText('writingStudents', writing);
        setText('completedStudents', submitted);
        setText('disconnectedStudents', disconnected);
        setText('cheatingCount', flagged);
        setText('warningCount', flagged);
    }

    function renderMonitoringGrid(students) {
        const grid = document.getElementById('studentMonitoringGrid');
        if (!grid) return;
        if (!students.length) {
            grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><i class="fas fa-user-clock"></i><p>Select an exam or wait for enrolled students to appear.</p></div>';
            return;
        }
        grid.innerHTML = students.map(student => {
            const state = student.submitted ? 'Submitted' : (student.writing ? 'Writing' : (student.online ? 'Online' : 'Disconnected'));
            const color = student.submitted ? '#10b981' : (student.writing ? '#2563eb' : (student.online ? '#14b8a6' : '#ef4444'));
            return `
                <label class="student-monitor-card ${student.flagged > 0 ? 'warning' : ''}" style="display:block;cursor:pointer;">
                    <input type="radio" name="monitoringStudent" value="${student.id}" style="margin-right:8px;">
                    <strong>${escapeHTML(student.full_name || 'Student')}</strong>
                    <div style="color:var(--muted);font-size:12px;margin:4px 0;">${escapeHTML(student.student_id || '')} ${student.level ? '- Level ' + escapeHTML(student.level) : ''}</div>
                    <span class="tag" style="background:${color};color:white;">${state}</span>
                    ${student.flagged > 0 ? `<span class="tag" style="background:#f59e0b;color:#111827;">${student.flagged} flag(s)</span>` : ''}
                    <div style="font-size:12px;color:var(--muted);margin-top:8px;">Last activity: ${student.lastActivity ? escapeHTML(new Date(String(student.lastActivity).replace(' ', 'T')).toLocaleString()) : 'No activity yet'}</div>
                </label>
            `;
        }).join('');
    }

    async function controlMonitoringExam(control, extra = {}) {
        if (!currentMonitoringExam) {
            toast('Select an exam to control first.');
            return;
        }
        const result = await apiRequest('manage_exam_time', {
            exam_id: currentMonitoringExam,
            control,
            ...extra
        });
        toast(result.success ? (result.message || 'Exam timing updated.') : (result.error || 'Could not update exam timing.'));
        if (result.success) refreshMonitoring();
    }

    function promptAddMonitoringTime() {
        const minutes = parseInt(prompt('Add how many minutes? Use 5, 10, 15, or any custom number.', '10') || '0', 10);
        if (minutes > 0) controlMonitoringExam('add_time', { minutes });
    }

    function promptExtendMonitoringCutoff() {
        const value = prompt('Enter the new cut-off date and time in YYYY-MM-DD HH:MM format.');
        if (value) controlMonitoringExam('extend_cutoff', { cutoff_datetime: value.replace('T', ' ') });
    }

    function promptMonitoringAnnouncement() {
        const message = prompt('Announcement to send to all students in this exam:');
        if (message) controlMonitoringExam('announcement', { message });
    }

    function sendWarningToAll() {
        if (!currentMonitoringExam) {
            toast('Select an exam first.');
            return;
        }
        controlMonitoringExam('announcement', {
            message: 'Warning: Please follow exam rules. Screen sharing and tab switching are being monitored.'
        });
    }

    async function lockAllScreens() {
        if (!currentMonitoringExam) {
            toast('Select an exam first.');
            return;
        }
        const targets = activeMonitoringStudents.filter(student => student.online || student.writing);
        if (!targets.length) {
            toast('No online students to lock right now.');
            return;
        }
        if (!(await confirmPopup(`Lock screens for ${targets.length} online student(s)?`, 'Lock screens', 'Lock Screens'))) return;
        for (const student of targets) {
            await apiRequest('lock_student_screen', {
                exam_id: currentMonitoringExam,
                student_id: student.id
            });
        }
        toast('Screen lock command sent to online students.');
        refreshMonitoring();
    }

    async function forceSubmitSelectedMonitoringStudent() {
        if (!currentMonitoringExam) {
            toast('Select an exam first.');
            return;
        }
        const selected = document.querySelector('input[name="monitoringStudent"]:checked');
        if (!selected) {
            toast('Select a student card first.');
            return;
        }
        if (!(await confirmPopup('Force submit this student from the latest saved answers?', 'Force submit student', 'Force Submit'))) return;
        const result = await apiRequest('force_submit_student', {
            exam_id: currentMonitoringExam,
            student_id: selected.value
        });
        toast(result.success ? 'Student force-submitted from latest saved answers.' : (result.error || 'Force submit failed.'));
        refreshMonitoring();
    }

    function copyExamLinkFixed(examId, examTitle) {
        // Get the correct base URL - this ensures it works from any page
        const currentPath = window.location.pathname;
        const baseUrl = window.location.origin;
        let examInterfacePath = '';

        // Determine the correct path to exam_interface.php
        if (currentPath.includes('/lecturer/')) {
            examInterfacePath = baseUrl + '/student/exam_interface.php';
        } else if (currentPath.includes('/admin/')) {
            examInterfacePath = baseUrl + '/student/exam_interface.php';
        } else {
            // Default - go up one directory level
            const pathParts = currentPath.split('/');
            pathParts.pop(); // Remove current file name
            examInterfacePath = baseUrl + pathParts.join('/') + '/exam_interface.php';
        }

        // Create the full exam URL
        const examUrl = `${examInterfacePath}?exam_id=${examId}`;

        // Copy to clipboard
        navigator.clipboard.writeText(examUrl).then(() => {
            // Show success message with the URL
            toast('✅ Exam link copied');

            // Optional: Show a modal with the link for easy copying
        }).catch(() => {
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = examUrl;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            toast('✅ Exam link copied');
        });
    }

    copyExamLinkFixed = function(examId, examTitle) {
        const currentPath = window.location.pathname;
        const pathParts = currentPath.split('/');
        pathParts.pop();
        const examUrl = `${window.location.origin}${pathParts.join('/')}/exam_interface.php?exam_id=${examId}`;
        navigator.clipboard.writeText(examUrl).catch(() => {
            const textarea = document.createElement('textarea');
            textarea.value = examUrl;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        });
    };

    // Optional: Show a modal with the link for easy copying
    function showLinkModal(examUrl, examTitle) {
        // Create modal if it doesn't exist
        let linkModal = document.getElementById('linkModal');
        if (!linkModal) {
            linkModal = document.createElement('div');
            linkModal.id = 'linkModal';
            linkModal.className = 'modal';
            linkModal.style.display = 'none';
            linkModal.innerHTML = `
            <div class="modal-content" style="width: 500px; max-width: 90%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: var(--text);">
                        <i class="fas fa-link" style="color: #3b82f6;"></i> Exam Link
                    </h3>
                    <button onclick="closeLinkModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--muted);">&times;</button>
                </div>
                <div style="margin-bottom: 15px;">
                    <p style="color: var(--text); margin-bottom: 5px;">
                        <strong>${escapeHTML(examTitle)}</strong>
                    </p>
                    <p style="color: var(--muted); font-size: 12px; margin-bottom: 10px;">
                        Share this link with students to access the exam:
                    </p>
                    <div style="background: var(--bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border); word-break: break-all;">
                        <code style="font-size: 12px; color: var(--text);">${escapeHTML(examUrl)}</code>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn" onclick="closeLinkModal()">Close</button>
                    <button class="btn primary" onclick="copyToClipboard('${escapeHTML(examUrl)}')">
                        <i class="fas fa-copy"></i> Copy Again
                    </button>
                </div>
            </div>
        `;
            document.body.appendChild(linkModal);
        } else {
            // Update existing modal content
            const modalContent = linkModal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: var(--text);">
                        <i class="fas fa-link" style="color: #3b82f6;"></i> Exam Link
                    </h3>
                    <button onclick="closeLinkModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--muted);">&times;</button>
                </div>
                <div style="margin-bottom: 15px;">
                    <p style="color: var(--text); margin-bottom: 5px;">
                        <strong>${escapeHTML(examTitle)}</strong>
                    </p>
                    <p style="color: var(--muted); font-size: 12px; margin-bottom: 10px;">
                        Share this link with students to access the exam:
                    </p>
                    <div style="background: var(--bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border); word-break: break-all;">
                        <code style="font-size: 12px; color: var(--text);">${escapeHTML(examUrl)}</code>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn" onclick="closeLinkModal()">Close</button>
                    <button class="btn primary" onclick="copyToClipboard('${escapeHTML(examUrl)}')">
                        <i class="fas fa-copy"></i> Copy Again
                    </button>
                </div>
            `;
            }
        }

        linkModal.style.display = 'flex';
    }

    function closeLinkModal() {
        const modal = document.getElementById('linkModal');
        if (modal) modal.style.display = 'none';
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            toast('✅ Link copied to clipboard again!');
        }).catch(() => {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            toast('✅ Link copied to clipboard!');
        });
    }


    function showEqualMarksModal() {
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.style.display = 'flex';
        modal.innerHTML = `
        <div class="modal-content" style="width: 500px;">
            <h3><i class="fas fa-balance-scale"></i> Equal Marks Distribution</h3>
            <div class="field">
                <label>Select Exam:</label>
                <select id="equalMarksExamSelect" style="width:100%; padding: 10px;">
                    <option value="">Select an exam...</option>
                    ${getExams().filter(e => e.published).map(e => `<option value="${e.id}">${escapeHTML(e.title)}</option>`).join('')}
                </select>
            </div>
            <div class="field">
                <label>Marks per Question:</label>
                <input type="number" id="equalMarksValue" min="1" max="100" step="0.5" style="width:100%; padding: 10px;">
            </div>
            <div class="modal-actions">
                <button class="btn" onclick="this.parentElement.parentElement.parentElement.remove()">Cancel</button>
                <button class="btn primary" onclick="applyEqualMarks()">Apply to All Questions</button>
            </div>
        </div>
    `;
        document.body.appendChild(modal);
    }

    function applyEqualMarks() {
        const examId = document.getElementById('equalMarksExamSelect').value;
        const marksValue = parseFloat(document.getElementById('equalMarksValue').value);

        if (!examId || !marksValue || marksValue <= 0) {
            toast('❌ Please select an exam and enter valid marks');
            return;
        }

        const exams = getExams();
        const exam = exams.find(e => e.id == examId);
        if (exam && exam.questions) {
            exam.questions.forEach(q => {
                q.marks = marksValue;
                if (q.subQuestions && q.subQuestions.length > 0) {
                    q.subQuestions.forEach(sq => sq.marks = marksValue);
                }
            });
            setExams(exams);
            saveExamToDatabase();
            renderQuestions();
            toast(`✅ Applied ${marksValue} marks to all questions in ${exam.title}`);
        }
        closeModal();
    }

    async function newExam() {
        const approved = await confirmPopup(
            'Open a blank exam builder?\nNothing will be saved to the database until you click Save Draft or Publish Exam.',
            'Create new exam',
            'Open Builder'
        );
        if (!approved) return;

        const tempId = -Date.now();
        const exams = getExams();
        const exam = {
            id: tempId,
            exam_code: `EX-DRAFT-${Math.abs(tempId)}`,
            title: '',
            courseCode: '',
            durationMins: 180,
            startAtISO: '',
            endAtISO: '',
            gracePeriodMinutes: 0,
            cutoffAtISO: '',
            instructions: '- Attempt ALL questions...',
            markingScheme: '',
            published: false,
            questions: [],
            questionsToAnswer: 0,
            shuffleEnabled: false,
            gradingMode: 'auto',
            exam_password: '',
            school_name: '',
            faculty_name: '',
            department: '',
            semester: '',
            exam_type: '',
            school_type: '',
            academic_year: '',
            level: '',
            auto_grading_enabled: 1,
            partial_grading_enabled: 0,
            show_correct_answers: 0,
            allow_review: 1
        };

        exams.unshift(exam);
        setExams(exams);
        backupExamDraft(exam);
        currentExamId = tempId;
        sessionStorage.setItem('currentExamId', tempId);
        localStorage.setItem(K_LAST_BUILDER_EXAM, String(tempId));
        sessionStorage.setItem('currentView', 'builder');
        sessionStorage.setItem('currentRouteParams', JSON.stringify({ id: tempId }));
        resetExamBuilderForm();
        populateBuilderForm(exam);
        renderQuestions();
        go('builder', { id: tempId });
        toast('Blank exam opened. Save Draft only when you want it stored.');
        return;

        showLoading('Creating new exam...');
        try {
            const result = await apiRequest('create_exam', {
                title: '',
                course_code: '',
                duration: 180,
                start_datetime: '',
                end_datetime: '',
                instructions: '- Attempt ALL questions...',
                marking_scheme: '',
                questions_to_answer: 0,
                shuffle_enabled: 0,
                grading_mode: 'auto'
            });

            if (result.success) {
                toast('✅ New exam created');
                await loadExamsList();
                const examId = result.exam_id;
                sessionStorage.setItem('currentExamId', examId);
                sessionStorage.setItem('currentView', 'builder');
                sessionStorage.setItem('currentRouteParams', JSON.stringify({
                    id: examId
                }));
                openBuilder(examId);
            } else {
                toast('❌ Failed to create exam: ' + (result.error || 'Unknown error'));
                hideLoading();
            }
        } catch (error) {
            console.error('Error creating exam:', error);
            toast('❌ Error creating exam');
            hideLoading();
        }
    }

    async function openBuilder(examId) {
        console.log("Opening builder for exam ID:", examId);
        showLoading('Loading exam...');

        try {
            const localDraft = getLocalDraftById(examId) || findExam(examId);
            if (localDraft && (parseInt(examId) < 0 || localDraft.localDraftSavedAt)) {
                const exams = getExams();
                const existingIndex = exams.findIndex(exam => parseInt(exam.id) === parseInt(localDraft.id));
                if (existingIndex >= 0) exams[existingIndex] = localDraft;
                else exams.unshift(localDraft);
                setExams(exams);
                currentExamId = parseInt(localDraft.id);
                sessionStorage.setItem('currentExamId', currentExamId);
                localStorage.setItem(K_LAST_BUILDER_EXAM, String(currentExamId));
                sessionStorage.setItem('currentView', 'builder');
                sessionStorage.setItem('currentRouteParams', JSON.stringify({ id: currentExamId }));
                populateBuilderForm(localDraft);
                renderQuestions();
                go('builder', { id: currentExamId });
                hideLoading();
                return;
            }

            const result = await apiRequest('get_exams');

            if (result.success && result.data) {
                const exams = result.data.map(exam => {
                    let questions = [];
                    if (exam.questions) {
                        if (typeof exam.questions === 'string') {
                            try {
                                questions = JSON.parse(exam.questions);
                            } catch (e) {
                                console.error("Failed to parse questions:", e);
                                questions = [];
                            }
                        } else if (Array.isArray(exam.questions)) {
                            questions = exam.questions;
                        }
                    }
                    return {
                        id: exam.id,
                        exam_code: exam.exam_code || exam.exam_id || '',
                        title: exam.title || '',
                        courseName: exam.course_name || exam.course_title || '',
                        courseCode: exam.course_code || '',
                        durationMins: exam.duration_minutes || 180,
                        startAtISO: exam.start_datetime || '',
                        endAtISO: exam.end_datetime || '',
                        instructions: exam.instructions || '',
                        markingScheme: exam.marking_scheme || '',
                        published: exam.published == 1,
                        questions: questions,
                        questionsToAnswer: exam.questions_to_answer || 0,
                        shuffleEnabled: exam.shuffle_enabled == 1,
                        gradingMode: exam.grading_mode || 'auto',
                        exam_password: exam.exam_password || '',
                        school_name: exam.school_name || '',
                        faculty_name: exam.faculty_name || '',
                        department: exam.department || '',
                        semester: exam.semester || '',
                        exam_type: exam.exam_type || '',
                        school_type: exam.school_type || '',
                        level: exam.level || '',
                        academic_year: exam.academic_year || '',
                        auto_grading_enabled: exam.auto_grading_enabled || 0,
                        partial_grading_enabled: exam.partial_grading_enabled || 0,
                        show_correct_answers: exam.show_correct_answers || 0,
                        allow_review: exam.allow_review !== null ? exam.allow_review : 1,
                        assignedStudentsCount: parseInt(exam.assigned_students_count || 0, 10),
                        submittedStudentsCount: parseInt(exam.submitted_students_count || 0, 10),
                        resultsPublished: parseInt(exam.results_published || 0, 10) === 1
                    };
                });

                const mergedExams = mergeExamsWithLocalDrafts(exams);
                setExams(mergedExams);
                const exam = mergedExams.find(e => parseInt(e.id) === parseInt(examId));

                if (exam) {
                    console.log("Found exam with questions:", exam.questions.length);
                    currentExamId = parseInt(exam.id);
                    sessionStorage.setItem('currentExamId', currentExamId);
                    localStorage.setItem(K_LAST_BUILDER_EXAM, String(currentExamId));
                    sessionStorage.setItem('currentView', 'builder');
                    populateBuilderForm(exam);
                    setTimeout(() => {
                        renderQuestions();
                        console.log("Questions rendered after timeout");
                    }, 100);
                    go('builder', {
                        id: examId
                    });
                    hideLoading();
                    return;
                }
            }

            toast("❌ Exam not found");
            go('exams');
            hideLoading();

        } catch (error) {
            console.error('Error loading exam:', error);
            hideLoading();
            toast("❌ Error loading exam");
            go('exams');
        }
    }

    function populateBuilderForm(exam) {
        console.log("Populating builder for exam:", exam);

        if (!exam) {
            console.error("No exam provided to populateBuilderForm");
            return;
        }

        currentExamId = parseInt(exam.id);
        sessionStorage.setItem('currentExamId', currentExamId);
        sessionStorage.setItem('currentView', 'builder');

        if (!exam.questions) {
            exam.questions = [];
        }

        // Populate form fields AND update exam object
        const titleInput = document.getElementById("bTitle");
        const codeInput = document.getElementById("bCode");
        const durationInput = document.getElementById("bDuration");
        const instructionsInput = document.getElementById("bInstructions");
        const examDateInput = document.getElementById("bExamDate");
        const startTimeInput = document.getElementById("bStartTime");
        const startAtInput = document.getElementById("bStartAt");
        const endAtInput = document.getElementById("bEndAt");
        const graceInput = document.getElementById("bGracePeriod");
        const cutoffInput = document.getElementById("bCutoffAt");
        const questionsToAnswerInput = document.getElementById("bQuestionsToAnswer");
        const examPasswordInput = document.getElementById("examPassword");
        const schoolNameInput = document.getElementById("school_name");
        const facultyNameInput = document.getElementById("faculty_name");
        const departmentInput = document.getElementById("department");
        const semesterSelect = document.getElementById("semester");
        const academicYearInput = document.getElementById("academic_year");
        const examTypeSelect = document.getElementById("exam_type");
        const schoolTypeSelect = document.getElementById("school_type");
        const levelSelect = document.getElementById("level");

        // Set values and update exam object
        if (titleInput) {
            titleInput.value = exam.title || "";
            updateExamField('title', exam.title || "");
        }
        if (codeInput) {
            codeInput.value = exam.courseCode || "";
            updateExamField('courseCode', exam.courseCode || "");
        }
        if (durationInput) {
            durationInput.value = exam.durationMins || 180;
            updateExamField('durationMins', exam.durationMins || 180);
        }
        if (instructionsInput) {
            instructionsInput.value = exam.instructions || "";
            updateExamField('instructions', exam.instructions || "");
        }
        if (startAtInput) {
            startAtInput.value = exam.startAtISO ? exam.startAtISO.slice(0, 16) : "";
            updateExamField('startAtISO', exam.startAtISO || "");
        }
        if (examDateInput || startTimeInput) {
            syncSchedulePartsFromStart();
        }
        if (endAtInput) {
            endAtInput.value = exam.endAtISO ? exam.endAtISO.slice(0, 16) : "";
            updateExamField('endAtISO', exam.endAtISO || "");
        }
        if (graceInput) {
            graceInput.value = parseInt(exam.gracePeriodMinutes || 0, 10);
            updateExamField('gracePeriodMinutes', parseInt(exam.gracePeriodMinutes || 0, 10));
        }
        if (cutoffInput) {
            cutoffInput.value = exam.cutoffAtISO ? exam.cutoffAtISO.slice(0, 16) : "";
            updateExamField('cutoffAtISO', exam.cutoffAtISO || "");
        }
        if (questionsToAnswerInput) {
            questionsToAnswerInput.value = exam.questionsToAnswer || 0;
            updateExamField('questionsToAnswer', exam.questionsToAnswer || 0);
        }
        if (examPasswordInput) {
            examPasswordInput.value = "";
            examPasswordInput.placeholder = exam.exam_password ? "Password is set. Enter a new password only if changing it." : "Create a password for students";
            updateExamField('exam_password', "");
        }
        if (schoolNameInput && exam.school_name) {
            schoolNameInput.value = exam.school_name;
            updateExamField('school_name', exam.school_name);
        }
        if (facultyNameInput && exam.faculty_name) {
            facultyNameInput.value = exam.faculty_name;
            updateExamField('faculty_name', exam.faculty_name);
        }
        if (departmentInput && exam.department) {
            departmentInput.value = exam.department;
            updateExamField('department', exam.department);
        }
        if (semesterSelect && exam.semester) {
            semesterSelect.value = exam.semester;
            updateExamField('semester', exam.semester);
        }
        if (academicYearInput) {
            academicYearInput.value = exam.academic_year || "";
            updateExamField('academic_year', exam.academic_year || "");
        }
        if (examTypeSelect && exam.exam_type) {
            examTypeSelect.value = exam.exam_type;
            updateExamField('exam_type', exam.exam_type);
        }
        if (schoolTypeSelect && exam.school_type) {
            schoolTypeSelect.value = exam.school_type;
            updateExamField('school_type', exam.school_type);
        }
        if (levelSelect && exam.level) {
            levelSelect.value = exam.level;
            updateExamField('level', exam.level);
        }
        if (exam.gradingMode) {
            gradingMode = exam.gradingMode;
            const gradingModeSelect = document.getElementById('gradingMode');
            if (gradingModeSelect) gradingModeSelect.value = gradingMode;
        }

        const autoGrading = document.getElementById('enableAutoGrading');
        if (autoGrading) autoGrading.checked = exam.auto_grading_enabled == 1 || gradingMode === 'auto' || gradingMode === 'hybrid';
        const partialGrading = document.getElementById('enablePartialGrading');
        if (partialGrading) partialGrading.checked = exam.partial_grading_enabled == 1 || gradingMode === 'hybrid';
        const showAnswers = document.getElementById('showCorrectAnswers');
        if (showAnswers) showAnswers.checked = exam.show_correct_answers == 1;
        const allowReview = document.getElementById('allowReview');
        if (allowReview) allowReview.checked = exam.allow_review !== 0 && exam.allow_review !== '0';

        shuffleEnabled = exam.shuffleEnabled || false;
        const shuffleBtn = document.getElementById('shuffleBtn');
        if (shuffleBtn) {
            const btnText = document.getElementById('shuffleBtnText');
            if (btnText) {
                btnText.textContent = shuffleEnabled ? 'Shuffle Questions: ON' : 'Shuffle Questions: OFF';
            }
        }

        const builderMeta = document.getElementById("builderMeta");
        if (builderMeta) {
            builderMeta.textContent = exam.published ? "Published" : "Draft";
        }

        const builderCrumb = document.getElementById("builderCrumb");
        if (builderCrumb) {
            const crumbCode = exam.exam_code || exam.examCode || exam.exam_id || exam.examId || exam.code || exam.id;
            builderCrumb.innerHTML =
                `Home / Create / Edit / <span style="color:var(--blue);font-weight:600">${escapeHTML(String(crumbCode || 'Draft'))}</span>`;
        }

        console.log("Builder form populated, exam has", exam.questions.length, "questions");

        // Force save to localStorage
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx] = exam;
            setExams(exams);
            console.log("Exam saved to localStorage after population");
        }
    }

    function syncDurationFromStartEnd() {
        const startField = document.getElementById('bStartAt');
        const endField = document.getElementById('bEndAt');
        const durationField = document.getElementById('bDuration');
        if (!startField || !endField || !durationField || !startField.value || !endField.value) return;
        const start = new Date(startField.value);
        const end = new Date(endField.value);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) return;
        const minutes = Math.max(1, Math.round((end - start) / 60000));
        durationField.value = minutes;
        updateExamField('durationMins', minutes);
        updateExamField('endAtISO', endField.value);
    }

    function syncSchedulePartsFromStart() {
        const startField = document.getElementById('bStartAt');
        const dateField = document.getElementById('bExamDate');
        const timeField = document.getElementById('bStartTime');
        if (!startField || !startField.value) return;
        const [datePart, timePart = ''] = startField.value.split('T');
        if (dateField) dateField.value = datePart || '';
        if (timeField) timeField.value = timePart.slice(0, 5);
    }

    function syncStartDateTimeFromParts() {
        const startField = document.getElementById('bStartAt');
        const dateField = document.getElementById('bExamDate');
        const timeField = document.getElementById('bStartTime');
        if (!startField || !dateField || !timeField || !dateField.value || !timeField.value) return;
        startField.value = `${dateField.value}T${timeField.value}`;
        updateExamField('startAtISO', startField.value);
    }

    function syncEndTimeFromDuration() {
        const startField = document.getElementById('bStartAt');
        const endField = document.getElementById('bEndAt');
        const durationField = document.getElementById('bDuration');
        if (!startField || !endField || !durationField || !startField.value) return;
        const start = new Date(startField.value);
        const minutes = parseInt(durationField.value, 10) || 180;
        if (Number.isNaN(start.getTime())) return;
        const end = new Date(start.getTime() + minutes * 60000);
        endField.value = toDateTimeLocalValue(end);
        updateExamField('durationMins', minutes);
        updateExamField('endAtISO', endField.value);
    }

    function syncCutoffFromGrace() {
        const endField = document.getElementById('bEndAt');
        const cutoffField = document.getElementById('bCutoffAt');
        const graceField = document.getElementById('bGracePeriod');
        if (!endField || !cutoffField || !graceField || !endField.value) return;
        const grace = parseInt(graceField.value, 10) || 0;
        if (grace <= 0 && cutoffField.value) {
            updateExamField('cutoffAtISO', cutoffField.value);
            return;
        }
        const end = new Date(endField.value);
        if (Number.isNaN(end.getTime())) return;
        const cutoff = new Date(end.getTime() + grace * 60000);
        cutoffField.value = toDateTimeLocalValue(cutoff);
        updateExamField('cutoffAtISO', cutoffField.value);
    }

    function toDateTimeLocalValue(date) {
        const pad = value => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    async function saveExamToDatabase() {
        if (!currentExamId) {
            console.log("No exam selected to save");
            return false;
        }

        const exams = getExams();
        const exam = exams.find(e => parseInt(e.id) === parseInt(currentExamId));

        if (!exam) {
            console.log("Exam not found in localStorage");
            return false;
        }

        backupExamDraft(exam);

        try {
            const questionsJSON = JSON.stringify(exam.questions || []);
            const selectedGradingMode = exam.gradingMode || document.getElementById('gradingMode')?.value || 'auto';
            const autoGradingEnabled = selectedGradingMode === 'manual' ? 0 : 1;
            const partialGradingEnabled = selectedGradingMode === 'hybrid' ? 1 : 0;
            console.log("Saving questions:", exam.questions.length, "questions");

            if (parseInt(currentExamId) < 0) {
                const created = await apiRequest('create_exam', {
                    title: exam.title || '',
                    course_code: exam.courseCode || '',
                    duration: exam.durationMins || 180,
                    start_datetime: exam.startAtISO || '',
                    end_datetime: exam.endAtISO || '',
                    grace_period_minutes: exam.gracePeriodMinutes || 0,
                    cutoff_datetime: exam.cutoffAtISO || '',
                    instructions: exam.instructions || '',
                    marking_scheme: exam.markingScheme || '',
                    questions: questionsJSON,
                    questions_to_answer: exam.questionsToAnswer || 0,
                    shuffle_enabled: exam.shuffleEnabled ? 1 : 0,
                    grading_mode: selectedGradingMode,
                    school_name: exam.school_name || '',
                    faculty_name: exam.faculty_name || '',
                    department: exam.department || '',
                    semester: exam.semester || '',
                    exam_type: exam.exam_type || '',
                    school_type: exam.school_type || '',
                    academic_year: exam.academic_year || '',
                    level: exam.level || '',
                    exam_password: exam.exam_password || '',
                    auto_grading_enabled: autoGradingEnabled,
                    partial_grading_enabled: partialGradingEnabled,
                    show_correct_answers: 0,
                    allow_review: 1
                });

                if (!created.success) {
                    console.error("Failed to create draft:", created.error);
                    return false;
                }

                const oldId = currentExamId;
                currentExamId = parseInt(created.exam_id);
                removeDraftBackup(oldId);
                exam.id = currentExamId;
                exam.exam_code = created.exam_code || exam.exam_code || '';
                const tempIndex = exams.findIndex(e => parseInt(e.id) === parseInt(oldId));
                if (tempIndex >= 0) exams[tempIndex] = exam;
                setExams(exams);
                backupExamDraft(exam);
                sessionStorage.setItem('currentExamId', currentExamId);
                localStorage.setItem(K_LAST_BUILDER_EXAM, String(currentExamId));
                sessionStorage.setItem('currentRouteParams', JSON.stringify({ id: currentExamId }));
            }

            const result = await apiRequest('update_exam', {
                exam_id: currentExamId,
                title: exam.title || '',
                course_code: exam.courseCode || '',
                duration: exam.durationMins || 180,
                start_datetime: exam.startAtISO || '',
                end_datetime: exam.endAtISO || '',
                grace_period_minutes: exam.gracePeriodMinutes || 0,
                cutoff_datetime: exam.cutoffAtISO || '',
                instructions: exam.instructions || '',
                marking_scheme: exam.markingScheme || '',
                questions: questionsJSON,
                questions_to_answer: exam.questionsToAnswer || 0,
                shuffle_enabled: exam.shuffleEnabled ? 1 : 0,
                grading_mode: selectedGradingMode,
                school_name: exam.school_name || '',
                faculty_name: exam.faculty_name || '',
                department: exam.department || '',
                semester: exam.semester || '',
                exam_type: exam.exam_type || '',
                school_type: exam.school_type || '',
                academic_year: exam.academic_year || '',
                level: exam.level || '',
                exam_password: exam.exam_password || '',
                auto_grading_enabled: autoGradingEnabled,
                partial_grading_enabled: partialGradingEnabled,
                show_correct_answers: 0,
                allow_review: 1,
                published: exam.published ? 1 : 0
            });

            if (result.success) {
                console.log("Exam saved to database successfully");
                const exams = getExams();
                const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
                if (idx >= 0) {
                    exams[idx] = exam;
                    setExams(exams);
                    backupExamDraft(exam);
                }
                return true;
            } else {
                console.error("Failed to save exam:", result.error);
                return false;
            }
        } catch (error) {
            console.error("Error saving exam:", error);
            return false;
        }
    }

    async function saveDraftExam() {
        const approved = await confirmPopup(
            'Save this unfinished exam as a draft in the database?',
            'Save draft',
            'Save Draft'
        );
        if (!approved) return;
        showLoading(true);
        try {
            const saved = await saveExamToDatabase();
            showMessage(saved ? 'Draft saved. You can continue setting it later.' : 'Unable to save draft right now.', saved ? 'success' : 'error');
        } finally {
            showLoading(false);
        }
    }

    async function deleteExam(examId) {
        const approved = await confirmPopup(
            "Delete this exam?\nThis action cannot be undone. All student submissions will also be deleted.",
            "Delete exam",
            "Delete Exam"
        );
        if (!approved) return;
        const originalConfirm = window.confirm;
        window.confirm = () => true;
        setTimeout(() => { window.confirm = originalConfirm; }, 0);
        if (!confirm(
                "⚠️ Delete this exam? This action cannot be undone. All student submissions will also be deleted."))
            return;
        showLoading('Deleting exam...');
        try {
            const result = await apiRequest('delete_exam', {
                exam_id: examId
            });
            if (result.success) {
                toast('🗑 Exam deleted successfully');
                const exams = getExams().filter(e => parseInt(e.id) != parseInt(examId));
                setExams(exams);
                if (currentExamId == examId) {
                    currentExamId = null;
                    sessionStorage.removeItem('currentExamId');
                }
                await loadExamsList();
                go('exams');
            } else {
                toast('❌ ' + (result.error || 'Failed to delete exam'));
            }
        } catch (error) {
            console.error('Delete error:', error);
            toast('❌ Network error. Please try again.');
        } finally {
            hideLoading();
        }
    }

    function deleteCurrentExam() {
        if (currentExamId) {
            deleteExam(currentExamId);
        } else {
            toast("❌ No exam to delete");
        }
    }

    function getQuestionMarks(question) {
        if (!question) return 0;
        if (question.hasSubQuestions && Array.isArray(question.subQuestions) && question.subQuestions.length) {
            return question.subQuestions.reduce((sum, subQuestion) => sum + (parseFloat(subQuestion.marks) || 0), 0);
        }
        return parseFloat(question.marks) || 0;
    }

    function getQuestionAnswerLimit(examOrQuestions, explicitLimit) {
        const questions = Array.isArray(examOrQuestions) ? examOrQuestions : (examOrQuestions?.questions || []);
        const rawLimit = explicitLimit !== undefined ? explicitLimit : (examOrQuestions?.questionsToAnswer ?? examOrQuestions?.questions_to_answer ?? 0);
        const parsedLimit = parseInt(rawLimit, 10) || 0;
        return parsedLimit > 0 ? Math.min(parsedLimit, questions.length) : questions.length;
    }

    function calculateEffectiveExamMarks(examOrQuestions, explicitLimit) {
        const questions = Array.isArray(examOrQuestions) ? examOrQuestions : (examOrQuestions?.questions || []);
        const limit = getQuestionAnswerLimit(examOrQuestions, explicitLimit);
        const compulsory = questions.filter(q => !!q.compulsory);
        const optionalSlots = Math.max(0, limit - compulsory.length);
        const optionalMarks = questions
            .filter(q => !q.compulsory)
            .map(getQuestionMarks)
            .sort((a, b) => b - a)
            .slice(0, optionalSlots);
        return compulsory.reduce((sum, q) => sum + getQuestionMarks(q), 0) +
            optionalMarks.reduce((sum, marks) => sum + marks, 0);
    }

    function describeQuestionAnswerRule(examOrQuestions, explicitLimit) {
        const questions = Array.isArray(examOrQuestions) ? examOrQuestions : (examOrQuestions?.questions || []);
        const limit = getQuestionAnswerLimit(examOrQuestions, explicitLimit);
        if (!questions.length || limit >= questions.length) return 'Answer all questions';
        const compulsoryCount = questions.filter(q => !!q.compulsory).length;
        return compulsoryCount > 0 ?
            `Answer ${limit} questions including ${compulsoryCount} compulsory question(s)` :
            `Answer any ${limit} question(s)`;
    }

    function refreshQuestionAnswerRuleHint() {
        const hint = document.getElementById('questionAnswerRuleHint');
        if (!hint || !currentExamId) return;
        const exam = findExam(currentExamId);
        if (!exam) return;
        const qta = parseInt(document.getElementById('bQuestionsToAnswer')?.value) || 0;
        exam.questionsToAnswer = qta;
        hint.textContent = `${describeQuestionAnswerRule(exam, qta)}. Total obtainable marks: ${calculateEffectiveExamMarks(exam, qta)}.`;
    }

    async function togglePublish(examId) {
        const selectedExam = getExams().find(e => parseInt(e.id) === parseInt(examId));
        const isPublished = !!selectedExam?.published;
        const approved = await confirmPopup(
            isPublished ? 'Unpublish this exam? Students will no longer see it as available.' : 'Publish this exam? Enrolled and visible students will see it on their dashboard.',
            isPublished ? 'Unpublish exam' : 'Publish exam',
            isPublished ? 'Unpublish' : 'Publish'
        );
        if (!approved) return;

        const result = await apiRequest('toggle_publish', {
            exam_id: examId
        });
        if (result.success) {
            toast("📦 Status updated");
            loadExamsList();
        } else {
            const exams = getExams();
            const i = exams.findIndex(e => parseInt(e.id) === parseInt(examId));
            if (i < 0) return;
            exams[i].published = !exams[i].published;
            setExams(exams);
            toast(exams[i].published ? "🚀 Published" : "📦 Unpublished");
        }
    }

    async function publishExam() {
        console.log("===== PUBLISH EXAM STARTED =====");

        if (!currentExamId) {
            showMessage("❌ No exam selected", "error");
            return;
        }

        // ========== CAPTURE ALL FORM FIELDS ==========
        const title = document.getElementById('bTitle')?.value || '';
        const courseCode = document.getElementById('bCode')?.value || '';
        const duration = parseInt(document.getElementById('bDuration')?.value) || 180;
        const instructions = document.getElementById('bInstructions')?.value || '';
        const startDatetime = document.getElementById('bStartAt')?.value || null;
        const endDatetime = document.getElementById('bEndAt')?.value || null;
        const gracePeriod = parseInt(document.getElementById('bGracePeriod')?.value) || 0;
        const cutoffDatetime = document.getElementById('bCutoffAt')?.value || null;
        const questionsToAnswer = parseInt(document.getElementById('bQuestionsToAnswer')?.value) || 0;
        const examPassword = document.getElementById('examPassword')?.value || '';

        // Academic Information
        const semester = document.getElementById('semester')?.value || '';
        const academicYear = document.getElementById('academic_year')?.value || '';
        const examType = document.getElementById('exam_type')?.value || '';
        const schoolType = document.getElementById('school_type')?.value || '';
        const level = document.getElementById('level')?.value || '';

        saveAllFormDataToExam();

        // Get questions
        const exams = getExams();
        const exam = exams.find(e => parseInt(e.id) === parseInt(currentExamId));
        const questions = exam?.questions || [];
        const selectedGradingMode = document.getElementById('gradingMode')?.value || exam?.gradingMode || 'auto';
        const autoGradingEnabled = selectedGradingMode === 'manual' ? 0 : 1;
        const partialGradingEnabled = selectedGradingMode === 'hybrid' ? 1 : 0;
        const showCorrectAnswers = 0;
        const allowReview = 1;

        const totalMarks = calculateEffectiveExamMarks(questions, questionsToAnswer);

        // ========== VALIDATION ==========
        const errors = [];
        if (!title) errors.push('Exam Title is required');
        if (!courseCode) errors.push('Course Code is required');
        if (!duration || duration <= 0) errors.push('Valid Duration is required');
        if (!instructions) errors.push('Instructions are required');
        if (!semester) errors.push('Semester is required');
        if (!examType) errors.push('Exam Type is required');
        if (!schoolType) errors.push('School Type is required');
        if (!level) errors.push('Level is required');
        if (!questions || questions.length === 0) errors.push('At least one question is required');

        if (errors.length > 0) {
            showMessage("❌ Please fix:\n" + errors.join('\n'), "error");
            return;
        }

        const approved = await confirmPopup(
            `Publish "${title}" for ${courseCode}?\n${describeQuestionAnswerRule(questions, questionsToAnswer)}. Total obtainable marks: ${totalMarks}.\nStudents who are enrolled and visible will see it according to the start and end time.`,
            'Publish exam',
            'Publish Exam'
        );
        if (!approved) return;

        // ========== PREPARE DATA ==========
        const publishData = {
            action: 'publish_exam',
            exam_id: currentExamId,
            title: title,
            course_code: courseCode,
            duration: duration,
            start_datetime: startDatetime,
            end_datetime: endDatetime,
            grace_period_minutes: gracePeriod,
            cutoff_datetime: cutoffDatetime,
            instructions: instructions,
            marking_scheme: exam?.markingScheme || '',
            questions: JSON.stringify(questions),
            questions_to_answer: questionsToAnswer,
            shuffle_enabled: exam?.shuffleEnabled ? 1 : 0,
            grading_mode: selectedGradingMode,
            school_name: '',
            faculty_name: '',
            department: '',
            semester: semester,
            academic_year: academicYear,
            exam_type: examType,
            school_type: schoolType,
            level: level,
            exam_password: examPassword,
            total_marks: totalMarks,
            auto_grading_enabled: autoGradingEnabled,
            partial_grading_enabled: partialGradingEnabled,
            show_correct_answers: showCorrectAnswers,
            allow_review: allowReview
        };

        console.log("📤 Sending to API:", publishData);

        showLoading(true);

        try {
            const result = await apiRequest('publish_exam', publishData);
            console.log("✅ API Response:", result);

            if (result.success) {
                removeDraftBackup(currentExamId, result.exam_id);
                localStorage.removeItem(K_LAST_BUILDER_EXAM);
                // Show success message
                showMessage(result.warning ? "⚠️ " + result.message : "✅ " + (result.message || "Exam Published Successfully!"), result.warning ? "info" : "success");

                // Reset the form
                resetExamBuilderForm();

                // Refresh exams list
                await loadExamsList();
                go('exams');

            } else {
                showMessage("❌ " + (result.error || 'Failed to publish exam'), "error");
            }
        } catch (error) {
            console.error('❌ Publish error:', error);
            showMessage("❌ Network error: " + error.message, "error");
        } finally {
            showLoading(false);
        }
    }

    // ========== IMPROVED MESSAGE FUNCTION ==========
    function showMessage(message, type = 'info') {
        // Create a modal/dialog for success message
        if (type === 'success') {
            // Create a nice success modal
            const modal = document.createElement('div');
            modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10001;
            animation: fadeIn 0.3s ease;
        `;
            modal.innerHTML = `
            <div style="background: var(--panel); border-radius: 20px; padding: 40px; max-width: 400px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
                <div style="font-size: 60px; margin-bottom: 15px;">✅</div>
                <h2 style="color: var(--text); margin-bottom: 10px;">Success!</h2>
                <p style="color: var(--muted); margin-bottom: 20px;">${message}</p>
                <button onclick="this.parentElement.parentElement.remove()" 
                    style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 12px 30px; border-radius: 30px; cursor: pointer; font-size: 14px; font-weight: 600;">
                    OK
                </button>
            </div>
        `;
            document.body.appendChild(modal);

            // Auto close after 3 seconds
            setTimeout(() => {
                if (modal && modal.parentElement) {
                    modal.remove();
                }
            }, 3000);
        } else {
            toast(message);
        }
    }

    // ========== IMPROVED LOADING FUNCTION ==========
    function showLoading(show) {
        let loader = document.getElementById('globalLoader');
        if (show) {
            if (!loader) {
                loader = document.createElement('div');
                loader.id = 'globalLoader';
                loader.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.7);
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-direction: column;
                color: white;
            `;
                loader.innerHTML = `
                <div class="spinner" style="width: 50px; height: 50px;"></div>
                <div style="margin-top: 20px; font-size: 16px;">Publishing exam...</div>
            `;
                document.body.appendChild(loader);
            } else {
                loader.style.display = 'flex';
            }
        } else {
            if (loader) {
                loader.style.display = 'none';
            }
        }
    }

    // ========== NEW FUNCTION: Reset all form fields ==========
    function resetExamBuilderForm() {
        console.log("🔄 Resetting exam builder form...");

        // Reset text inputs
        const textFields = ['bTitle', 'bCode', 'examPassword', 'bInstructions'];
        textFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.value = '';
        });

        // Reset school information fields
        const schoolFields = ['school_name', 'faculty_name', 'department'];
        schoolFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.value = '';
        });

        // Reset dropdowns to first option (empty/default)
        const dropdowns = ['semester', 'exam_type', 'school_type', 'level'];
        dropdowns.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.selectedIndex = 0;
        });

        // Reset number fields to defaults
        const durationField = document.getElementById('bDuration');
        if (durationField) durationField.value = '180';

        const questionsToAnswerField = document.getElementById('bQuestionsToAnswer');
        if (questionsToAnswerField) questionsToAnswerField.value = '0';

        // Reset datetime field
        const startAtField = document.getElementById('bStartAt');
        if (startAtField) startAtField.value = '';

        const endAtField = document.getElementById('bEndAt');
        if (endAtField) endAtField.value = '';

        // Reset checkboxes
        const autoGrading = document.getElementById('enableAutoGrading');
        if (autoGrading) autoGrading.checked = false;

        const partialGrading = document.getElementById('enablePartialGrading');
        if (partialGrading) partialGrading.checked = false;

        const showAnswers = document.getElementById('showCorrectAnswers');
        if (showAnswers) showAnswers.checked = false;

        const allowReview = document.getElementById('allowReview');
        if (allowReview) allowReview.checked = true;

        // Reset shuffle button
        shuffleEnabled = false;
        const shuffleBtnText = document.getElementById('shuffleBtnText');
        if (shuffleBtnText) shuffleBtnText.textContent = 'Shuffle Questions: OFF';

        // Clear questions container
        const qList = document.getElementById('qList');
        if (qList) {
            qList.innerHTML = '';
            qList.style.display = 'none';
        }

        // Show no questions message
        const noQuestionsMsg = document.getElementById('noQuestionsMessage');
        if (noQuestionsMsg) noQuestionsMsg.style.display = 'block';

        // Hide qtype button bar
        const qtypeBar = document.getElementById('qtypeButtonBar');
        if (qtypeBar) qtypeBar.classList.remove('visible');

        // Reset builder meta text
        const builderMeta = document.getElementById('builderMeta');
        if (builderMeta) builderMeta.textContent = 'Create New Exam';

        // Reset crumb
        const builderCrumb = document.getElementById('builderCrumb');
        if (builderCrumb) builderCrumb.innerHTML = 'Home / Create Exam';

        // Clear current exam ID
        currentExamId = null;
        sessionStorage.removeItem('currentExamId');

        // Hide student visibility list if open
        const studentListContainer = document.getElementById('enrolledStudentsListContainer');
        if (studentListContainer) studentListContainer.style.display = 'none';
        studentListVisible = false;

        // Remove validation error styling
        document.querySelectorAll('.validation-error').forEach(el => {
            el.classList.remove('validation-error');
        });

        console.log("✅ Form reset complete");
    }

    // ========== NEW FUNCTION: Create a new exam after publish ==========
    async function createNewExamAfterPublish() {
        console.log("🆕 Creating new exam...");

        try {
            const result = await apiRequest('create_exam_advanced', {
                title: 'New Exam',
                course_code: '',
                duration: 180,
                instructions: '- Attempt ALL questions...',
                questions: '[]'
            });

            if (result.success) {
                const newExamId = result.exam_id;
                console.log("✅ New exam created with ID:", newExamId);

                // Set new current exam ID
                currentExamId = newExamId;
                sessionStorage.setItem('currentExamId', currentExamId);

                // Clear any existing questions from localStorage
                const exams = getExams();
                const newExam = exams.find(e => parseInt(e.id) === parseInt(newExamId));
                if (newExam) {
                    newExam.questions = [];
                    setExams(exams);
                }

                // Update builder meta
                const builderMeta = document.getElementById('builderMeta');
                if (builderMeta) builderMeta.textContent = 'Create New Exam';

                // Focus on the first field (Exam Title)
                const titleField = document.getElementById('bTitle');
                if (titleField) {
                    titleField.focus();
                    titleField.placeholder = 'e.g., Final Examination - Introduction to Programming';
                }

                toast('✅ New exam ready! Fill in the details below.');

                // Reload exams list in background
                await loadExamsList();

            } else {
                console.error("Failed to create new exam:", result.error);
                toast('⚠️ Exam published but could not create new form. Refresh the page.');
            }
        } catch (error) {
            console.error("Error creating new exam:", error);
            toast('⚠️ Exam published. Please refresh to create another exam.');
        }
    }

    // ========== Helper: Clear questions from the builder ==========
    function clearAllQuestions() {
        if (currentExamId) {
            const exams = getExams();
            const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
            if (idx >= 0) {
                exams[idx].questions = [];
                setExams(exams);
            }
        }
        renderQuestions();
    }

    function showValidationErrors(errors) {
        if (!errors || errors.length === 0) return;

        // Remove existing error container if any
        const existingContainer = document.getElementById('validationErrorsContainer');
        if (existingContainer) existingContainer.remove();

        // Create error container
        const errorContainer = document.createElement('div');
        errorContainer.id = 'validationErrorsContainer';
        errorContainer.style.cssText = `
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 10000;
            max-width: 400px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            animation: slideInRight 0.3s ease-out;
        `;

        errorContainer.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 20px;"></i>
                <strong style="font-size: 16px;">Cannot Publish Exam</strong>
                <button onclick="this.parentElement.parentElement.remove()" 
                    style="margin-left: auto; background: none; border: none; color: white; cursor: pointer; font-size: 18px;">
                    &times;
                </button>
            </div>
            <ul style="margin: 0; padding-left: 20px;">
                ${errors.map(err => `<li style="margin: 5px 0;">${escapeHTML(err)}</li>`).join('')}
            </ul>
        `;

        document.body.appendChild(errorContainer);

        // Auto-remove after 8 seconds
        setTimeout(() => {
            if (errorContainer && errorContainer.parentElement) {
                errorContainer.remove();
            }
        }, 8000);
    }

    function showSuccessMessage(title, message) {
        // Create success modal
        let successModal = document.getElementById('successModal');
        if (!successModal) {
            successModal = document.createElement('div');
            successModal.id = 'successModal';
            successModal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10001;
                animation: fadeIn 0.3s ease;
            `;
            document.body.appendChild(successModal);
        }

        successModal.innerHTML = `
            <div style="background: var(--panel); border-radius: 20px; padding: 30px; max-width: 400px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
                <div style="font-size: 60px; margin-bottom: 15px;">✅</div>
                <h2 style="color: var(--text); margin-bottom: 10px;">${escapeHTML(title)}</h2>
                <p style="color: var(--muted); margin-bottom: 20px;">${escapeHTML(message)}</p>
                <button onclick="document.getElementById('successModal').remove()" 
                    class="btn primary" style="padding: 12px 30px;">
                    OK
                </button>
            </div>
        `;

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (successModal && successModal.parentElement) {
                successModal.remove();
            }
        }, 5000);
    }

    // Add real-time validation on input fields
    const validationFields = ['bTitle', 'bCode', 'bDuration', 'bInstructions', 'semester', 'exam_type', 'school_type', 'level'
    ];
    validationFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', function() {
                this.classList.remove('validation-error');
                // Hide error container when user starts fixing
                const errorContainer = document.getElementById('validationErrorsContainer');
                if (errorContainer) errorContainer.remove();
            });
            field.addEventListener('change', function() {
                this.classList.remove('validation-error');
                const errorContainer = document.getElementById('validationErrorsContainer');
                if (errorContainer) errorContainer.remove();
            });
        }
    });

    function previewExam() {
        if (!currentExamId) {
            toast('No exam selected');
            return;
        }
        previewExamFromList(currentExamId);
    }

    function previewExamFromList(examId) {
        const exam = findExam(examId);
        if (!exam) {
            toast('❌ Exam not found');
            return;
        }

        const totalMarks = calculateEffectiveExamMarks(exam);
        const questionCount = (exam.questions || []).length;
        const durationText = exam.durationMins ? `${Math.floor(exam.durationMins / 60)}h ${exam.durationMins % 60}m` :
            'N/A';

        let questionsHtml = '';
        if (!exam.questions || exam.questions.length === 0) {
            questionsHtml = `<div style="text-align:center; padding:40px; color:var(--muted);">
                <i class="fas fa-question-circle" style="font-size:40px; margin-bottom:10px;"></i>
                <p>No questions added yet.</p>
            </div>`;
        } else {
            questionsHtml = exam.questions.map((q, idx) => {
                const qMarks = q.hasSubQuestions && q.subQuestions?.length ?
                    q.subQuestions.reduce((s, sq) => s + (parseFloat(sq.marks) || 0), 0) :
                    parseFloat(q.marks) || 0;

                let answerArea = '';
                if (q.type === 'code') {
                    answerArea = `
                        <div style="margin-top:12px;">
                            <div style="font-size:12px; font-weight:600; color:var(--muted); margin-bottom:6px;">
                                <i class="fas fa-terminal"></i> Student Code (${escapeHTML(q.language || 'Code')}):
                            </div>
                            <textarea rows="6" disabled placeholder="Student types code here..."
                                style="width:100%; padding:12px; border-radius:10px; border:2px solid var(--border); background:var(--input-bg); color:var(--text); font-family:monospace; font-size:13px; resize:vertical;"></textarea>
                        </div>`;

                    if (q.hasSubQuestions && q.subQuestions?.length) {
                        answerArea += '<div style="margin-top:14px;">' + q.subQuestions.map((sq, si) => {
                            const prefix = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'][si] || (
                                si + 1);
                            return `<div style="background:var(--bg); border-radius:10px; padding:14px; margin-bottom:10px; border:1px solid var(--border);">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <strong style="color:var(--blue);">${prefix})</strong>
                                    <span class="tag" style="background:var(--blue); color:#fff;">${sq.marks} marks</span>
                                </div>
                                <p style="margin:0 0 8px; color:var(--text);">${escapeHTML(sq.text || '(no text)')}</p>
                                ${sq.hint ? `<small style="color:var(--muted);"><i class="fas fa-lightbulb"></i> Hint: ${escapeHTML(sq.hint)}</small>` : ''}
                                <textarea rows="3" disabled placeholder="Student answer area..." style="width:100%; margin-top:8px; padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--input-bg); color:var(--text); font-family:monospace; font-size:13px;"></textarea>
                            </div>`;
                        }).join('') + '</div>';
                    }
                }

                return `
                <div style="background:var(--panel); border:2px solid var(--border); border-radius:14px; padding:20px; margin-bottom:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <span style="font-weight:700; font-size:15px; color:var(--text);">Q${idx + 1}.</span>
                            <span style="background:linear-gradient(135deg,#10b981,#059669); color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600;">CODING</span>
                            ${q.compulsory ? '<span style="background:#ef4444; color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600;">COMPULSORY</span>' : ''}
                        </div>
                        <span style="background:var(--blue); color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;">${qMarks} marks</span>
                    </div>
                    <p style="margin:0 0 4px; color:var(--text); font-size:15px; line-height:1.6;">${escapeHTML(q.text || '(no question text)')}</p>
                    ${answerArea}
                </div>`;
            }).join('');
        }

        const logoHtml = '';

        const previewContent = document.getElementById('examPreviewContent');
        if (!previewContent) return;

        previewContent.innerHTML = `
            <div style="max-width:860px; margin:0 auto;">
                <!-- Exam Header -->
                <div style="background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; border-radius:16px; padding:28px 24px; margin-bottom:24px; text-align:center;">
                    ${logoHtml}
                    <div style="font-size:13px; opacity:.85; margin-bottom:4px;">${escapeHTML(exam.school_name || '')}</div>
                    <div style="font-size:12px; opacity:.75; margin-bottom:12px;">${escapeHTML(exam.faculty_name || '')} ${exam.department ? '| ' + escapeHTML(exam.department) : ''}</div>
                    <h2 style="margin:0 0 6px; font-size:22px; font-weight:700;">${escapeHTML(exam.title || 'Exam')}</h2>
                    <div style="font-size:13px; opacity:.85;">${escapeHTML(exam.courseCode || '')} ${exam.exam_type ? '| ' + escapeHTML(exam.exam_type) : ''} ${exam.semester ? '| ' + escapeHTML(exam.semester) : ''}</div>
                </div>

                <!-- Exam Meta Bar -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin-bottom:24px;">
                    <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Duration</div>
                        <div style="font-size:18px; font-weight:700; color:var(--blue);">⏱ ${durationText}</div>
                    </div>
                    <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Questions</div>
                        <div style="font-size:18px; font-weight:700; color:var(--blue);">📝 ${questionCount}</div>
                    </div>
                    <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Total Marks</div>
                        <div style="font-size:18px; font-weight:700; color:var(--blue);">⭐ ${totalMarks}</div>
                    </div>
                    ${exam.level ? `<div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                        <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Level</div>
                        <div style="font-size:18px; font-weight:700; color:var(--blue);">🎓 ${escapeHTML(exam.level)}</div>
                    </div>` : ''}
                </div>

                <!-- Instructions -->
                ${exam.instructions ? `<div style="background:rgba(59,130,246,.06); border-left:4px solid #3b82f6; border-radius:0 12px 12px 0; padding:16px 20px; margin-bottom:24px;">
                    <div style="font-weight:700; margin-bottom:8px; color:var(--text);">📋 Instructions</div>
                    <pre style="margin:0; font-family:inherit; white-space:pre-wrap; color:var(--text); font-size:14px; line-height:1.6;">${escapeHTML(exam.instructions)}</pre>
                </div>` : ''}

                <!-- Questions -->
                <div style="margin-bottom:24px;">${questionsHtml}</div>

                <!-- Submit bar (disabled in preview) -->
                <div style="background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; opacity:.6;">
                    <span style="color:var(--muted); font-size:13px;"><i class="fas fa-info-circle"></i> Preview mode — submission disabled</span>
                    <button disabled style="padding:10px 24px; background:#3b82f6; color:#fff; border:none; border-radius:30px; font-weight:600; cursor:not-allowed; opacity:.5;">Submit Exam</button>
                </div>
            </div>`;

        document.getElementById('examPreviewModal').style.display = 'flex';
    }

    function copyExamLink(examId, examTitle) {
        const base = window.location.origin + window.location.pathname.replace('lecturer_dashboard.php', '');
        const link = `${base}student_exam.php?exam_id=${examId}`;
        navigator.clipboard.writeText(link).then(() => {
            toast(`🔗 Exam link copied: ${link}`);
        }).catch(() => {
            // Fallback
            const el = document.createElement('textarea');
            el.value = link;
            el.style.position = 'fixed';
            el.style.opacity = '0';
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            toast(`🔗 Link copied to clipboard`);
        });
    }

    copyExamLink = function(examId, examTitle) {
        const base = window.location.origin + window.location.pathname.replace('lecturer_dashboard.php', '');
        const link = `${base}exam_interface.php?exam_id=${examId}`;
        navigator.clipboard.writeText(link).catch(() => {
            const el = document.createElement('textarea');
            el.value = link;
            el.style.position = 'fixed';
            el.style.opacity = '0';
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
        });
    };

    function toggleShuffle() {
        shuffleEnabled = !shuffleEnabled;
        const btn = document.getElementById('shuffleBtn');
        const btnText = document.getElementById('shuffleBtnText');
        if (btn) {
            if (btnText) {
                btnText.textContent = shuffleEnabled ? 'Shuffle Questions: ON' : 'Shuffle Questions: OFF';
            } else {
                btn.innerHTML = shuffleEnabled ?
                    '<i class="fas fa-random"></i> Shuffle Questions: ON' :
                    '<i class="fas fa-random"></i> Shuffle Questions: OFF';
            }
        }
        toast(shuffleEnabled ? 'Question shuffling enabled' : 'Question shuffling disabled');

        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx].shuffleEnabled = shuffleEnabled;
            setExams(exams);
            saveExamToDatabase();
        }
    }

    function setGradingMode(mode) {
        gradingMode = mode;
        toast(`✅ Grading mode set to: ${mode}`);
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx].gradingMode = mode;
            setExams(exams);
            saveExamToDatabase();
        }
    }

    function startAutoSave() {
        if (autoSaveInterval) clearInterval(autoSaveInterval);
        autoSaveInterval = setInterval(() => {
            if (currentExamId && document.getElementById('view-builder') && document.getElementById(
                    'view-builder').classList.contains('active')) {
                saveExamToDatabase();
                console.log("Auto-saved exam at:", new Date().toLocaleTimeString());
            }
        }, 30000);
    }

    // ============================================
    // 5. QUESTION MANAGEMENT FUNCTIONS - CODING ONLY
    // ============================================

    function addQuestion(type) {
        console.log("Adding question of type:", type);
        if (type !== 'code') {
            toast("Only coding questions are supported");
            return;
        }

        let examId = currentExamId;
        if (!examId) {
            const savedId = sessionStorage.getItem('currentExamId');
            if (savedId) {
                examId = parseInt(savedId);
                currentExamId = examId;
            }
        }

        if (!examId) {
            toast("❌ Please create or select an exam first");
            go('exams');
            return;
        }

        let exams = getExams();
        let idx = exams.findIndex(e => parseInt(e.id) === parseInt(examId));

        if (idx < 0) {
            showLoading('Loading exam data...');
            loadExamsList().then(() => {
                exams = getExams();
                idx = exams.findIndex(e => parseInt(e.id) === parseInt(examId));
                if (idx >= 0) {
                    addQuestionToExam(exams, idx);
                } else {
                    hideLoading();
                    toast("❌ Exam not found. Please create a new exam.");
                    go('exams');
                }
            });
            return;
        }

        addQuestionToExam(exams, idx);
    }

    async function addFirstQuestion(type) {
        console.log("Adding first coding question");
        if (type !== 'code') {
            toast("Only coding questions are supported");
            return;
        }

        let examId = currentExamId;
        if (!examId) {
            const savedId = sessionStorage.getItem('currentExamId');
            if (savedId) {
                examId = parseInt(savedId);
                currentExamId = examId;
            }
        }

        if (!examId) {
            toast("❌ Please create or select an exam first");
            go('exams');
            return;
        }

        let exams = getExams();
        let idx = exams.findIndex(e => parseInt(e.id) === parseInt(examId));

        if (idx < 0) {
            showLoading('Loading exam data...');
            await loadExamsList();
            exams = getExams();
            idx = exams.findIndex(e => parseInt(e.id) === parseInt(examId));

            if (idx < 0) {
                hideLoading();
                toast("❌ Exam not found. Please create a new exam.");
                go('exams');
                return;
            }
        }

        const q = createCodeQuestionObject();
        exams[idx].questions.push(q);
        setExams(exams);
        await saveExamToDatabase();

        const noQuestionsMsg = document.getElementById("noQuestionsMessage");
        if (noQuestionsMsg) {
            noQuestionsMsg.style.display = "none";
        }

        const qList = document.getElementById("qList");
        if (qList) {
            qList.style.display = "grid";
        }

        const qtypeBar = document.getElementById("qtypeButtonBar");
        if (qtypeBar) {
            qtypeBar.classList.add("visible");
        }

        renderQuestions();
        toast("✅ Coding question added");
        hideLoading();
    }

    function createCodeQuestionObject() {
        return {
            id: uid("Q"),
            type: "code",
            text: "",
            marks: 5,
            compulsory: false,
            language: "Python",
            testCases: [],
            expectedOutput: "",
            modelSolution: "",
            subQuestionStyle: "letters",
            gradingMode: "auto"
        };
    }

    function monacoLanguageFromQuestion(language) {
        const lang = String(language || '').toLowerCase();
        if (lang.includes('python')) return 'python';
        if (lang.includes('javascript')) return 'javascript';
        if (lang.includes('php')) return 'php';
        if (lang.includes('java')) return 'java';
        if (lang.includes('c++') || lang.includes('cpp')) return 'cpp';
        if (lang.includes('c#') || lang.includes('csharp')) return 'csharp';
        if (lang.includes('vb')) return 'vb';
        if (lang.includes('sql')) return 'sql';
        if (lang.includes('html/css/js')) return 'html';
        if (lang === 'c') return 'c';
        if (lang.includes('html')) return 'html';
        if (lang.includes('css')) return 'css';
        return 'plaintext';
    }

    function qodaLanguageExtension(language) {
        const lang = monacoLanguageFromQuestion(language);
        const extensions = {
            python: 'py',
            javascript: 'js',
            php: 'php',
            java: 'java',
            cpp: 'cpp',
            csharp: 'cs',
            vb: 'vb',
            sql: 'sql',
            html: 'html',
            css: 'css',
            c: 'c'
        };
        return extensions[lang] || 'txt';
    }

    function qodaCodeFileName(q, field) {
        const ext = qodaLanguageExtension(q?.language || 'plaintext');
        return `${field === 'starterCode' ? 'starter' : 'solution'}.${ext}`;
    }

    function addQuestionToExam(exams, idx) {
        const q = createCodeQuestionObject();
        exams[idx].questions.push(q);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase().then(() => console.log("Question saved to database"));
        toast("✅ Coding question added");
        hideLoading();

        setTimeout(() => {
            const qList = document.getElementById("qList");
            if (qList && qList.lastChild) {
                qList.lastChild.scrollIntoView({
                    behavior: "smooth",
                    block: "start"
                });
            }
        }, 100);
    }

    function renderQuestions() {
        console.log("Rendering questions for exam ID:", currentExamId);

        if (!currentExamId) {
            console.error("No currentExamId");
            return;
        }

        const exam = findExam(currentExamId);
        const wrap = document.getElementById("qList");
        const noQuestionsMsg = document.getElementById("noQuestionsMessage");
        const qtypeBar = document.getElementById("qtypeButtonBar");

        console.log("Found exam:", exam);
        console.log("Questions:", exam ? exam.questions : "No exam");

        if (!exam) {
            console.error("Exam not found");
            if (currentExamId) {
                openBuilder(currentExamId);
            }
            return;
        }

        if (!exam.questions) {
            exam.questions = [];
        }

        if (exam.questions.length === 0) {
            if (wrap) {
                wrap.innerHTML = "";
                wrap.style.display = "none";
            }
            if (noQuestionsMsg) {
                noQuestionsMsg.style.display = "block";
            }
            if (qtypeBar) {
                qtypeBar.classList.remove("visible");
            }
            return;
        }

        if (noQuestionsMsg) {
            noQuestionsMsg.style.display = "none";
        }

        if (qtypeBar) {
            qtypeBar.classList.add("visible");
        }

        if (wrap) {
            wrap.style.display = "grid";
            wrap.innerHTML = exam.questions.map((q, idx) =>
                renderCodingQuestionCard(q, idx, exam.questions.length)
            ).join('');
            console.log(`Rendered ${exam.questions.length} coding questions`);
            refreshQuestionAnswerRuleHint();
            setTimeout(initQodaModelSolutionEditors, 0);
        }
    }

    function renderCodingQuestionCard(q, idx, totalQuestions) {
        q.hasSubQuestions = false;
        q.subQuestions = [];
        // Calculate total marks
        let totalMarks = parseFloat(q.marks) || 0;

        let html = `
        <div class="coding-question-card" style="background: var(--panel); border-radius: 16px; padding: 24px; margin-bottom: 20px; border: 2px solid var(--border);">
            <div class="qhead" style="display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                <div style="flex: 1;">
                    <div class="qtitle" style="font-weight: 700; font-size: 16px; color: var(--text); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="cursor: grab; font-size: 18px;" class="drag-handle" title="Drag to reorder">⋮⋮</span>
                        <span>Q${idx + 1}</span>
                        <span class="question-type-badge" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">CODING</span>
                        ${q.compulsory ? '<span class="compulsory-badge" style="background: var(--compulsory-badge); color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 600;">COMPULSORY</span>' : ''}
                        <button class="btn small" onclick="toggleCompulsory('${q.id}')" style="margin-left: 8px; padding: 4px 8px; font-size: 11px;">${q.compulsory ? '❌ Make Optional' : '⭐ Make Compulsory'}</button>
                    </div>
                    <div class="qmeta" style="font-size: 12px; color: var(--muted); margin-top: 8px;">
                        Marks: <input type="number" value="${totalMarks}" style="width: 70px; padding: 4px 8px;" onchange="updateQuestion('${q.id}', 'marks', parseFloat(this.value))">
                        <span style="margin-left: 10px;">Language: ${escapeHTML(q.language || 'Python')}</span>
                    </div>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button class="btn" onclick="moveQ('${q.id}', -1)" ${idx === 0 ? 'disabled' : ''} title="Move Up">↑</button>
                    <button class="btn" onclick="moveQ('${q.id}', 1)" ${idx === totalQuestions - 1 ? 'disabled' : ''} title="Move Down">↓</button>
                    <button class="btn primary" onclick="duplicateQuestion('${q.id}')" title="Duplicate">📋 Duplicate</button>
                    <button class="btn danger" onclick="removeQuestion('${q.id}')" title="Delete">🗑 Delete</button>
                </div>
            </div>
            
            <!-- Question Text -->
            <div class="field" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                    <i class="fas fa-question-circle"></i> Question
                </label>
                <div class="structured-toolbar">
                    <button type="button" class="btn small" onclick="insertQuestionStructuredLine('${q.id}', 'text', 'number')"><i class="fas fa-list-ol"></i> Numbering</button>
                    <button type="button" class="btn small" onclick="insertQuestionStructuredLine('${q.id}', 'text', 'bullet')"><i class="fas fa-list-ul"></i> Bullets</button>
                </div>
                <textarea id="questionText_${q.id}" onchange="updateQuestion('${q.id}', 'text', this.value)" onkeydown="handleStructuredTextareaKeydown(event, this, 'text', '${q.id}')" rows="4"
                    style="width: 100%; padding: 12px; border-radius: 12px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 14px;"
                    placeholder="Enter the coding question here...">${escapeHTML(q.text || '')}</textarea>
            </div>
            
            <!-- Has Sub-questions Toggle -->
            <div class="field" style="margin-bottom: 20px; display:none;">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                    <input type="checkbox" id="hasSubQuestions_${q.id}" ${q.hasSubQuestions ? 'checked' : ''} 
                        onchange="toggleSubQuestions('${q.id}', this.checked)" style="width: 20px; height: 20px; cursor: pointer;">
                    <span style="font-size: 14px; font-weight: 600;">
                        <i class="fas fa-list-ol"></i> This question has sub-questions (a, b, c, ...)
                    </span>
                </label>
                <small style="display: block; margin-top: 5px; color: var(--muted);">Enable this if you want to break down the question into parts</small>
            </div>`;

        if (q.hasSubQuestions) {
            html += `
            <div id="subquestionsSection_${q.id}" style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <label style="font-size: 14px; font-weight: 600;">
                        <i class="fas fa-list-ol"></i> Sub-Questions
                    </label>
                    <button class="btn primary" onclick="addSubQuestion('${q.id}')" style="padding: 6px 12px;">
                        <i class="fas fa-plus"></i> Add Sub-Question
                    </button>
                </div>
                <div id="subquestionsList_${q.id}" class="subquestions-list">
                    ${renderSubQuestionsList(q)}
                </div>
            </div>`;
        } else {
            html += `
            <div id="singleMarksSection_${q.id}" style="margin-bottom: 20px;">
                <div class="marks-input-wrapper" style="max-width: 200px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                        <i class="fas fa-star"></i> Marks
                    </label>
                    <input type="number" value="${q.marks || 5}" min="0" step="0.5" 
                        onchange="updateQuestion('${q.id}', 'marks', parseFloat(this.value))"
                        class="marks-input"
                        style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 14px;">
                </div>
            </div>`;
        }

        html += `
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div class="field">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                        <i class="fas fa-code"></i> Programming Language
                    </label>
                    <select onchange="updateCodeLanguage('${q.id}', this.value)" 
                        style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text);">
                        ${codingLanguagesList.map(lang => 
                            `<option value="${lang}" ${(q.language === lang) ? 'selected' : ''}>${lang}</option>`
                        ).join('')}
                    </select>
                </div>
                
                <div class="field">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                        <i class="fas fa-robot"></i> Grading Mode
                    </label>
                    <select onchange="updateQuestion('${q.id}', 'gradingMode', this.value)" 
                        style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text);">
                        <option value="auto" ${q.gradingMode === 'auto' ? 'selected' : ''}>🤖 Auto-grading (Test Cases)</option>
                        <option value="manual" ${q.gradingMode === 'manual' ? 'selected' : ''}>✏️ Manual Grading Only</option>
                        <option value="hybrid" ${q.gradingMode === 'hybrid' ? 'selected' : ''}>🔄 Hybrid (Auto + Manual Review)</option>
                    </select>
                    <small style="display: block; margin-top: 5px; color: var(--muted);">
                        Auto: System grades based on test cases | Manual: Lecturer grades | Hybrid: Auto-grade then manual review
                    </small>
                </div>
            </div>`;

        if (q.gradingMode !== 'manual') {
            html += `
            <div class="field" style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                    <label style="font-size: 14px; font-weight: 600;">
                        <i class="fas fa-vial"></i> Test Cases (for Auto-grading)
                    </label>
                    <button class="btn small" onclick="addTestCase('${q.id}')" style="padding: 4px 12px;">
                        <i class="fas fa-plus"></i> Add Test Case
                    </button>
                </div>
                <div id="testCasesList_${q.id}">
                    ${renderTestCasesList(q)}
                </div>
                <small style="display: block; margin-top: 8px; color: var(--muted);">
                    <i class="fas fa-info-circle"></i> Define test cases to automatically validate student code
                </small>
            </div>`;
        }

        html += `
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div class="field">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                        <i class="fas fa-terminal"></i> Expected Output
                    </label>
                    <textarea onchange="updateQuestion('${q.id}', 'expectedOutput', this.value)" rows="6"
                        style="width: 100%; padding: 12px; border-radius: 12px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text); font-family: monospace;"
                        placeholder="Only enter the expected console/browser output here.">${escapeHTML(q.expectedOutput || '')}</textarea>
                </div>
                <div class="field">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                        <i class="fas fa-code"></i> Model Solution
                    </label>
                    <textarea onchange="updateQuestion('${q.id}', 'modelSolution', this.value)" rows="6"
                        style="width: 100%; padding: 12px; border-radius: 12px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text); font-family: monospace;"
                        placeholder="Enter a correct reference solution for AI/autograding review.">${escapeHTML(q.modelSolution || '')}</textarea>
                </div>
            </div>
            
            <div class="field" style="margin-bottom: 0;">
                <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">Change Question Type</label>
                <select onchange="changeType('${q.id}', this.value)" style="width: 100%; padding: 12px; border-radius: 12px; border: 2px solid var(--border); background: var(--input-bg); color: var(--text);">
                    <option value="code" ${q.type == 'code' ? 'selected' : ''}>Coding</option>
                </select>
            </div>
        </div>`;



        return html;
    }

    function renderSubQuestionsList(q) {
        if (!q.subQuestions || q.subQuestions.length === 0) {
            return `<div class="empty-subquestions" style="text-align: center; padding: 30px; background: var(--bg); border-radius: 12px; border: 2px dashed var(--border);">
                <i class="fas fa-plus-circle" style="font-size: 40px; color: var(--muted); margin-bottom: 10px;"></i>
                <p style="color: var(--muted);">No sub-questions yet. Click "Add Sub-Question" to create parts like a), b), c)...</p>
            </div>`;
        }

        let html = '<div style="display: flex; flex-direction: column; gap: 15px;">';

        q.subQuestions.forEach((sq, idx) => {
            const prefix = getSubQuestionPrefix(idx);
            html += `
            <div class="subquestion-item" data-subq-id="${sq.id}" style="background: var(--bg); border-radius: 12px; padding: 15px; border: 1px solid var(--border); position: relative;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="subquestion-prefix" style="font-weight: 700; font-size: 16px; color: var(--blue); background: rgba(59,130,246,0.1); padding: 4px 12px; border-radius: 20px;">${prefix}</span>
                        <span class="subquestion-id" style="font-size: 11px; color: var(--muted);">ID: ${sq.id}</span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn danger small" onclick="removeSubQuestion('${q.id}', '${sq.id}')" style="padding: 4px 10px;">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </div>
                
                <div class="field" style="margin-bottom: 12px;">
                    <label style="font-size: 13px; font-weight: 600; margin-bottom: 5px; display: block;">Question Text</label>
                    <textarea onchange="updateSubQuestion('${q.id}', '${sq.id}', 'text', this.value)" rows="2" 
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 13px;">${escapeHTML(sq.text || '')}</textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                    <div class="field" style="margin-bottom: 0;">
                        <label style="font-size: 12px; font-weight: 600;">Marks</label>
                        <input type="number" value="${sq.marks || 0}" min="0" step="0.5" 
                            onchange="updateSubQuestion('${q.id}', '${sq.id}', 'marks', parseFloat(this.value))"
                            class="subquestion-marks"
                            style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                    </div>
                    
                    <div class="field" style="margin-bottom: 0;">
                        <label style="font-size: 12px; font-weight: 600;">Expected Output</label>
                        <input type="text" value="${escapeHTML(sq.expectedOutput || '')}" 
                            onchange="updateSubQuestion('${q.id}', '${sq.id}', 'expectedOutput', this.value)"
                            placeholder="Expected result..."
                            style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                    </div>
                    
                    <div class="field" style="margin-bottom: 0;">
                        <label style="font-size: 12px; font-weight: 600;">Hint (Optional)</label>
                        <input type="text" value="${escapeHTML(sq.hint || '')}" 
                            onchange="updateSubQuestion('${q.id}', '${sq.id}', 'hint', this.value)"
                            placeholder="Provide a hint..."
                            style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                    </div>
                </div>
            </div>`;
        });

        html += '</div>';
        return html;
    }

    function renderTestCasesList(q) {
        if (!q.testCases || q.testCases.length === 0) {
            return `<div style="text-align: center; padding: 20px; background: var(--bg); border-radius: 8px; border: 1px dashed var(--border);">
                <small style="color: var(--muted);"><i class="fas fa-info-circle"></i> No test cases. Add test cases to enable auto-grading.</small>
            </div>`;
        }

        let html = '<div style="display: flex; flex-direction: column; gap: 10px;">';

        q.testCases.forEach((tc, idx) => {
            html += `
            <div style="display: flex; gap: 10px; align-items: center; background: var(--bg); padding: 12px; border-radius: 8px; flex-wrap: wrap;">
                <div style="flex: 2; min-width: 150px;">
                    <input type="text" placeholder="Input" value="${escapeHTML(tc.input || '')}" 
                        onchange="updateTestCase('${q.id}', ${idx}, 'input', this.value)"
                        style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                </div>
                <div style="flex: 2; min-width: 150px;">
                    <input type="text" placeholder="Expected Output" value="${escapeHTML(tc.expected || tc.expectedOutput || '')}"
                        onchange="updateTestCase('${q.id}', ${idx}, 'expected', this.value)"
                        style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                </div>
                <div style="width: 100px;">
                    <input type="number" placeholder="Marks" value="${tc.marks || 0}" min="0" step="0.5"
                        onchange="updateTestCase('${q.id}', ${idx}, 'marks', parseFloat(this.value))"
                        style="width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);">
                </div>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--text);">
                    <input type="checkbox" ${tc.hidden ? 'checked' : ''} onchange="updateTestCase('${q.id}', ${idx}, 'hidden', this.checked)">
                    ${tc.hidden ? 'Hidden Test Case' : 'Visible Test Case'}
                </label>
                <button class="btn danger small" onclick="removeTestCase('${q.id}', ${idx})" style="padding: 6px 12px;">
                    <i class="fas fa-times"></i> Remove
                </button>
            </div>`;
        });

        html += '</div>';
        return html;
    }

    function getSubQuestionPrefix(index) {
        const letters = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's',
            't'
        ];
        const roman = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi', 'xii', 'xiii', 'xiv', 'xv'];

        if (index < 20) {
            return `${letters[index]})`;
        } else {
            return `${roman[index - 20]})`;
        }
    }

    function toggleSubQuestions(questionId, hasSubQuestions) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        q.hasSubQuestions = hasSubQuestions;

        if (hasSubQuestions) {
            if (!q.subQuestions || q.subQuestions.length === 0) {
                q.subQuestions = [{
                    id: uid("SQ"),
                    text: "",
                    marks: 5,
                    expectedOutput: "",
                    hint: ""
                }];
            }
            if (q.marks && !q.savedSingleMark) {
                q.savedSingleMark = q.marks;
            }
        } else {
            if (q.savedSingleMark) {
                q.marks = q.savedSingleMark;
            } else if (!q.marks || q.marks === 0) {
                q.marks = 5;
            }
            q.subQuestions = [];
        }

        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
    }

    function addSubQuestion(questionId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        if (!q.subQuestions) q.subQuestions = [];

        const newSubQ = {
            id: uid("SQ"),
            text: "",
            marks: 5,
            expectedOutput: "",
            hint: ""
        };

        q.subQuestions.push(newSubQ);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`✅ Sub-question added`);
    }

    function removeSubQuestion(questionId, subQuestionId) {
        if (!confirm('Remove this sub-question?')) return;

        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q || !q.subQuestions) return;

        q.subQuestions = q.subQuestions.filter(sq => sq.id !== subQuestionId);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`🗑 Sub-question removed`);
    }

    function updateSubQuestion(questionId, subQuestionId, field, value) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q || !q.subQuestions) return;

        const sq = q.subQuestions.find(x => x.id === subQuestionId);
        if (sq) {
            sq[field] = value;
            setExams(exams);
            saveExamToDatabase();
            if (field === 'marks') {
                renderQuestions();
            }
        }
    }

    function addTestCase(questionId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        if (!q.testCases) q.testCases = [];

        q.testCases.push({
            input: "",
            expected: "",
            marks: 5,
            hidden: false
        });

        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`✅ Test case added`);
    }

    function removeTestCase(questionId, testCaseIndex) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q || !q.testCases) return;

        q.testCases.splice(testCaseIndex, 1);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`🗑 Test case removed`);
    }

    function updateTestCase(questionId, testCaseIndex, field, value) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q || !q.testCases || !q.testCases[testCaseIndex]) return;

        q.testCases[testCaseIndex][field] = value;
        setExams(exams);
        saveExamToDatabase();
        if (field === 'hidden') renderQuestions();
    }

    let questionBankSearchTimer = null;
    let questionBankItems = [];

    function openQuestionBankModal() {
        if (!currentExamId) {
            toast('Open or create an exam before using the question bank.');
            return;
        }
        populateQuestionBankLanguageFilter();
        const currentExam = findExam(currentExamId);
        const courseInput = document.getElementById('qbCourse');
        const semesterInput = document.getElementById('qbSemester');
        const yearInput = document.getElementById('qbYear');
        if (courseInput && currentExam?.courseCode && !courseInput.value) courseInput.value = currentExam.courseCode;
        if (semesterInput && currentExam?.semester && !semesterInput.value) semesterInput.value = currentExam.semester;
        if (yearInput && currentExam?.academic_year && !yearInput.value) yearInput.value = currentExam.academic_year;
        const modal = document.getElementById('questionBankModal');
        if (modal) modal.style.display = 'flex';
        loadQuestionBank();
    }

    function closeQuestionBankModal() {
        const modal = document.getElementById('questionBankModal');
        if (modal) modal.style.display = 'none';
    }

    function populateQuestionBankLanguageFilter() {
        const select = document.getElementById('qbLanguage');
        if (!select || select.dataset.ready === '1') return;
        select.innerHTML = '<option value="">All Languages</option>' + codingLanguagesList.map(lang =>
            `<option value="${escapeHTML(lang)}">${escapeHTML(lang)}</option>`
        ).join('');
        select.dataset.ready = '1';
    }

    function debouncedQuestionBankSearch() {
        clearTimeout(questionBankSearchTimer);
        questionBankSearchTimer = setTimeout(loadQuestionBank, 350);
    }

    async function loadQuestionBank() {
        const results = document.getElementById('questionBankResults');
        if (!results) return;
        results.innerHTML = '<div class="empty-state" style="grid-column:1/-1;">Loading question bank...</div>';
        const response = await apiRequest('get_question_bank', {
            search: document.getElementById('qbSearch')?.value || '',
            course_code: document.getElementById('qbCourse')?.value || '',
            language: document.getElementById('qbLanguage')?.value || '',
            semester: document.getElementById('qbSemester')?.value || '',
            academic_year: document.getElementById('qbYear')?.value || '',
            difficulty: document.getElementById('qbDifficulty')?.value || ''
        });
        if (!response.success) {
            results.innerHTML = `<div class="empty-state" style="grid-column:1/-1;">${escapeHTML(response.error || 'Could not load question bank.')}</div>`;
            return;
        }
        questionBankItems = response.data || [];
        if (!questionBankItems.length) {
            results.innerHTML = '<div class="empty-state" style="grid-column:1/-1;">No matching questions found.</div>';
            return;
        }
        results.innerHTML = questionBankItems.map((item, index) => `
            <article class="question-bank-card">
                <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
                    <strong>${escapeHTML(item.title || `Question ${index + 1}`)}</strong>
                    <span class="tag">${escapeHTML(item.language || 'Code')}</span>
                </div>
                <div class="small" style="margin:8px 0;">
                    ${escapeHTML(item.course_code || 'No course')} | ${escapeHTML(item.semester || 'No semester')} | ${escapeHTML(item.academic_year || item.year || 'No year')}
                </div>
                <div style="max-height:120px; overflow:auto; white-space:pre-wrap; color:var(--text); font-size:13px; margin-bottom:10px;">
                    ${escapeHTML(item.prompt || '')}
                </div>
                <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                    <span class="small">${parseFloat(item.marks || 0)} marks ${item.difficulty ? '| ' + escapeHTML(item.difficulty) : ''}</span>
                    <button class="btn primary small" onclick="reuseQuestionFromBank(${index})"><i class="fas fa-plus"></i> Use Question</button>
                </div>
            </article>
        `).join('');
    }

    function clearQuestionBankFilters() {
        ['qbSearch', 'qbCourse', 'qbYear'].forEach(id => {
            const field = document.getElementById(id);
            if (field) field.value = '';
        });
        ['qbLanguage', 'qbSemester', 'qbDifficulty'].forEach(id => {
            const field = document.getElementById(id);
            if (field) field.value = '';
        });
        loadQuestionBank();
    }

    async function reuseQuestionFromBank(index) {
        const item = questionBankItems[index];
        if (!item || !currentExamId) return;
        const exams = getExams();
        const examIndex = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (examIndex < 0) return;
        const original = item.question || {};
        const question = JSON.parse(JSON.stringify(original));
        question.id = uid('Q');
        question.text = question.text || item.prompt || '';
        question.title = question.title || item.title || '';
        question.type = question.type || 'code';
        question.language = question.language || item.language || 'Python';
        question.marks = parseFloat(question.marks || item.marks || 5);
        question.testCases = Array.isArray(question.testCases) ? question.testCases : [];
        question.gradingMode = question.gradingMode || 'auto';
        exams[examIndex].questions = exams[examIndex].questions || [];
        exams[examIndex].questions.push(question);
        setExams(exams);
        renderQuestions();
        await saveExamToDatabase();
        toast('Question added from bank. You can edit it before publishing.');
    }

    function updateCodeLanguage(questionId, language) {
        console.log("Updating language to:", language);

        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) {
            console.error("Exam not found");
            return;
        }

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) {
            console.error("Question not found");
            return;
        }

        q.language = language;

        setExams(exams);
        backupExamDraft(exams[idx]);
        saveExamToDatabase().then(() => {
            console.log("Exam saved with new language");
        });
        renderQuestions();
        toast(`Language changed to ${language}`);
    }

    function previewCodeQuestion(questionId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        let totalMarks = 0;
        if (q.hasSubQuestions && q.subQuestions && q.subQuestions.length > 0) {
            totalMarks = q.subQuestions.reduce((sum, sq) => sum + (sq.marks || 0), 0);
        } else {
            totalMarks = q.marks || 0;
        }

        let previewHtml = `
        <div style="padding: 20px; max-width: 900px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end)); color: white; padding: 25px; border-radius: 16px; margin-bottom: 25px; text-align: center;">
                <h2 style="margin: 0; font-size: 24px;">📝 Coding Question</h2>
                <p style="margin: 10px 0 0 0; opacity: 0.9;">Total Marks: ${totalMarks}</p>
            </div>
            
            <div style="background: var(--panel); border-radius: 16px; padding: 25px; border: 1px solid var(--border); margin-bottom: 25px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                    <h3 style="margin: 0; color: var(--text);">
                        <i class="fas fa-code"></i> Question ${q.id}
                    </h3>
                    <span style="background: var(--blue); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        ${q.language || 'Programming'}
                    </span>
                </div>
                
                <div style="margin-bottom: 25px;">
                    <div style="background: var(--bg); padding: 20px; border-radius: 12px; border-left: 4px solid var(--blue);">
                        <p style="margin: 0; line-height: 1.6; color: var(--text); font-size: 16px;">
                            ${escapeHTML(q.text || 'No question text provided.')}
                        </p>
                    </div>
                </div>`;

        if (q.hasSubQuestions && q.subQuestions && q.subQuestions.length > 0) {
            previewHtml += `
                <div style="margin-bottom: 25px;">
                    <h4 style="margin-bottom: 15px; color: var(--text);">
                        <i class="fas fa-list-ol"></i> Sub-Questions:
                    </h4>
                    <div style="padding-left: 20px;">`;

            q.subQuestions.forEach((sq, idx) => {
                const prefix = getSubQuestionPrefix(idx);
                previewHtml += `
                        <div style="margin-bottom: 25px; background: var(--bg); padding: 15px; border-radius: 12px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <strong style="font-size: 16px; color: var(--blue);">${prefix}</strong>
                                <span class="tag" style="background: var(--blue); color: white; padding: 2px 10px; border-radius: 12px; font-size: 11px;">
                                    ${sq.marks} marks
                                </span>
                            </div>
                            <p style="margin: 0 0 10px 0; line-height: 1.5;">${escapeHTML(sq.text || 'No description')}</p>
                            ${sq.hint ? `<div style="background: rgba(59,130,246,0.1); padding: 10px; border-radius: 8px; margin-top: 10px;">
                                <small><i class="fas fa-lightbulb"></i> Hint: ${escapeHTML(sq.hint)}</small>
                            </div>` : ''}
                            
                            <div style="margin-top: 15px;">
                                <label style="font-size: 13px; font-weight: 600;">Your Answer:</label>
                                <textarea rows="3" placeholder="Write your answer here..." 
                                    style="width: 100%; margin-top: 5px; padding: 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text);"></textarea>
                            </div>
                        </div>`;
            });

            previewHtml += `
                    </div>
                </div>`;
        }

        previewHtml += `
            <div style="margin-bottom: 25px;">
                <label style="font-size: 14px; font-weight: 600;">Your Answer / Code:</label>
                <textarea rows="8" placeholder="Write your code here..." 
                    style="width: 100%; margin-top: 8px; padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-family: monospace;"></textarea>
            </div>`;

        if (q.testCases && q.testCases.length > 0 && q.gradingMode !== 'manual') {
            previewHtml += `
                <div style="margin-top: 20px; padding: 15px; background: rgba(34,197,94,0.1); border-radius: 12px; border-left: 4px solid #22c55e;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <i class="fas fa-check-circle" style="color: #22c55e;"></i>
                        <strong style="color: var(--text);">Auto-Grading Information</strong>
                    </div>
                    <p style="margin: 0; font-size: 13px; color: var(--muted);">
                        Your code will be tested against ${q.testCases.length} test case(s). 
                        Each passing test case earns the allocated marks.
                    </p>
                </div>`;
        }

        previewHtml += `
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 20px;">
                <button class="btn" style="padding: 12px 24px;">
                    <i class="fas fa-save"></i> Save Draft
                </button>
                <button class="btn primary" style="padding: 12px 24px; background: var(--blue); color: white;">
                    <i class="fas fa-paper-plane"></i> Submit Answer
                </button>
            </div>
            
            <div style="margin-top: 30px; padding: 15px; background: var(--bg); border-radius: 12px; text-align: center; border: 1px dashed var(--border);">
                <small style="color: var(--muted);">
                    <i class="fas fa-info-circle"></i> This is how students will see this question. 
                    The actual exam interface may include a timer and additional features.
                </small>
            </div>
        </div>`;

        const previewContent = document.getElementById('previewContent');
        if (previewContent) previewContent.innerHTML = previewHtml;

        const previewModal = document.getElementById('previewModal');
        if (previewModal) {
            previewModal.style.display = 'flex';
            const modalContent = previewModal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.style.width = '90%';
                modalContent.style.maxWidth = '1000px';
            }
        }
    }

    function removeQuestion(qId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;
        exams[idx].questions = exams[idx].questions.filter(q => q.id !== qId);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast("🗑 Removed");
    }

    function updateQuestion(qId, field, value) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === qId);
        if (!q) return;

        q[field] = value;
        setExams(exams);
        if (field === 'marks' || field === 'compulsory') {
            refreshQuestionAnswerRuleHint();
        }
        saveExamToDatabase().then(() => {
            console.log(`Question ${qId} updated: ${field} = ${value}`);
        });
    }

    function moveQ(qId, dir) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;
        const qs = exams[idx].questions;
        const i = qs.findIndex(q => q.id === qId);
        const j = i + dir;
        if (i < 0 || j < 0 || j >= qs.length) return;
        [qs[i], qs[j]] = [qs[j], qs[i]];
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast("📋 Reordered");
    }

    function duplicateQuestion(qId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;
        const q = exams[idx].questions.find(x => x.id === qId);
        if (!q) return;
        const newQ = JSON.parse(JSON.stringify(q));
        newQ.id = uid("Q");
        exams[idx].questions.splice(idx + 1, 0, newQ);
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast("📋 Duplicated");
    }

    function toggleCompulsory(qId) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;
        const q = exams[idx].questions.find(x => x.id === qId);
        if (!q) return;
        q.compulsory = !q.compulsory;
        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(q.compulsory ? "⭐ Question marked as compulsory" : "❌ Question now optional");
    }

    function changeType(qId, newType) {
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === qId);
        if (!q) return;

        if (newType !== 'code') {
            toast("Only coding questions are supported");
            return;
        }

        // Reset type-specific properties
        delete q.correctText;
        delete q.keywords;
        delete q.rubric;
        delete q.language;
        delete q.allowMultipleLangs;
        delete q.testCases;
        delete q.pairs;
        delete q.correctIndices;
        delete q.items;
        delete q.correctOrder;
        delete q.tolerance;
        delete q.precision;
        delete q.unit;
        delete q.subQuestions;
        delete q.hasSubQuestions;
        delete q.expectedOutput;
        delete q.modelSolution;
        delete q.subQuestionStyle;
        delete q.gradingMode;

        q.type = newType;
        q.language = "Python";
        q.testCases = [];
        q.hasSubQuestions = false;
        q.subQuestions = [];
        q.expectedOutput = "";
        q.modelSolution = "";
        q.subQuestionStyle = "letters";
        q.gradingMode = "auto";

        setExams(exams);
        renderQuestions();
        saveExamToDatabase();
        toast(`🔄 Changed to ${newType}`);
    }

