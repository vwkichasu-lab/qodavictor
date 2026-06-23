    // ============================================
    // QUESTION REVIEW SYSTEM WITH CODE TESTING
    // ============================================

    let currentReviewSubmission = null;
    let currentQuestions = [];
    let currentScores = {};

    // Open question review modal
    async function openQuestionReview(submissionId) {
        showLoading('Loading submission...');
        try {
            const result = await apiRequest('get_submission_questions', {
                submission_id: submissionId
            });

            if (result.success && result.data) {
                currentReviewSubmission = result.data;
                currentQuestions = result.data.questions;

                // Initialize scores
                currentScores = {};
                currentQuestions.forEach((q, idx) => {
                    currentScores[idx] = q.savedScore || 0;
                });

                // Set header info
                document.getElementById('reviewStudentName').innerHTML =
                    `<i class="fas fa-user-graduate"></i> ${escapeHTML(currentReviewSubmission.student_name)} (${escapeHTML(currentReviewSubmission.student_id)})`;
                document.getElementById('reviewExamInfo').innerHTML =
                    `${escapeHTML(currentReviewSubmission.exam_title)} | Submitted: ${new Date(currentReviewSubmission.submitted_at).toLocaleString()}`;

                // Build question list sidebar
                buildQuestionSidebar();

                // Show total score
                updateTotalScoreDisplay();

                // Show modal
                document.getElementById('questionReviewModal').style.display = 'flex';
            } else {
                toast('❌ Failed to load submission: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            toast('❌ Error loading submission');
        } finally {
            hideLoading();
        }
    }

    // Build question sidebar (LEFT PANEL)
    function buildQuestionSidebar() {
        const sidebar = document.getElementById('questionListSidebar');
        if (!sidebar) return;

        let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';

        currentQuestions.forEach((question, idx) => {
            const isAnswered = question.answer && question.answer !== 'No answer provided';
            const statusIcon = isAnswered ? '✅' : '❌';
            const score = currentScores[idx] || 0;
            const maxMarks = question.marks;

            html += `
        <div class="question-list-item" onclick="loadQuestion(${idx})" 
             style="padding: 12px; background: var(--panel); border-radius: 8px; cursor: pointer; border: 1px solid var(--border);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>Question ${question.number}</strong>
                    <div style="font-size: 11px; margin-top: 3px;">
                        ${statusIcon} ${escapeHTML(question.language || 'text').toUpperCase()}
                    </div>
                </div>
                <div style="text-align: right;">
                    <span style="font-size: 14px; font-weight: bold; color: #3b82f6;">${score}</span>
                    <span style="font-size: 12px;">/${maxMarks}</span>
                </div>
            </div>
        </div>
        `;
        });

        html += '</div>';
        sidebar.innerHTML = html;
    }

    // Load specific question into RIGHT PANEL with code editor
    function loadQuestion(questionIndex) {
        const question = currentQuestions[questionIndex];
        if (!question) return;

        const contentArea = document.getElementById('questionContentArea');

        const escapedCode = escapeHTML(question.answer);

        let html = `
    <div style="max-width: 100%;">
        <!-- Question Header -->
        <div style="background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <h3 style="margin: 0;">Question ${question.number}</h3>
                <span class="tag" style="background: white; color: #3b82f6;">${escapeHTML(question.language || 'text').toUpperCase()} | ${question.marks} marks</span>
            </div>
        </div>
        
        <!-- Question Text -->
        <div style="background: var(--bg); padding: 20px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #3b82f6;">
            <h4 style="margin-top: 0;"><i class="fas fa-question-circle"></i> Question:</h4>
            <p style="margin-bottom: 0; line-height: 1.6;">${escapeHTML(question.text)}</p>
        </div>
        
        <!-- Student's Code with Run Button -->
        <div style="margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h4><i class="fas fa-code"></i> Student's Answer:</h4>
                <button class="btn info small" onclick="openCodeTester('${escapeHTML(question.answer).replace(/'/g, "\\'")}', '${question.language}')">
                    <i class="fas fa-play"></i> Test This Code
                </button>
            </div>
            <div style="background: #1e1e1e; border-radius: 12px; overflow: hidden;">
                <div style="background: #2d2d2d; padding: 8px 15px; border-bottom: 1px solid #3d3d3d;">
                    <span style="color: #858585; font-size: 12px;">${escapeHTML(question.language || 'code')}</span>
                </div>
                <pre style="margin: 0; padding: 20px; color: #d4d4d4; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.5; overflow-x: auto; white-space: pre-wrap;">${escapedCode || '// No code provided'}</pre>
            </div>
        </div>
        
        <!-- Grading Section -->
        <div style="background: var(--bg); padding: 20px; border-radius: 12px; margin-top: 20px;">
            <h4><i class="fas fa-star"></i> Grade This Question</h4>
            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end;">
                <div style="flex: 1; min-width: 150px;">
                    <label>Marks Awarded (max ${question.marks}):</label>
                    <input type="number" id="scoreInput_${questionIndex}" 
                           value="${currentScores[questionIndex]}" 
                           min="0" max="${question.marks}" step="0.5"
                           onchange="updateScore(${questionIndex}, this.value)"
                           style="width: 100%; padding: 10px; border-radius: 8px; border: 2px solid var(--border);">
                </div>
                <div style="flex: 2;">
                    <label>Feedback (optional):</label>
                    <input type="text" id="feedbackInput_${questionIndex}" 
                           placeholder="Add feedback for this question..."
                           style="width: 100%; padding: 10px; border-radius: 8px; border: 2px solid var(--border);">
                </div>
                <div>
                    <button class="btn primary" onclick="saveQuestionScore(${questionIndex})">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </div>
        </div>
    </div>
    `;

        contentArea.innerHTML = html;
    }


    // Update score temporarily
    function updateScore(questionIndex, value) {
        const score = roundToWholeNumber(value);
        currentScores[questionIndex] = score;
        updateTotalScoreDisplay();

        // Update the input field to show rounded value
        const inputField = document.getElementById(`scoreInput_${questionIndex}`);
        if (inputField) {
            inputField.value = score;
        }

        // Update the sidebar display
        const sidebarItem = document.querySelectorAll('.question-list-item')[questionIndex];
        if (sidebarItem) {
            const scoreSpan = sidebarItem.querySelector('span:first-child');
            if (scoreSpan) {
                scoreSpan.textContent = score;
            }
        }
    }

    // Update total score display
    function updateTotalScoreDisplay() {
        const total = Object.values(currentScores).reduce((sum, score) => sum + score, 0);
        const totalMarks = currentQuestions.reduce((sum, q) => sum + q.marks, 0);

        document.getElementById('totalScoreDisplay').textContent = total;
        document.getElementById('totalMarksDisplay').textContent = ` / ${totalMarks} marks`;
    }

    // Save individual question score
    async function saveQuestionScore(questionIndex) {
        const score = currentScores[questionIndex];
        const feedback = document.getElementById(`feedbackInput_${questionIndex}`)?.value || '';

        showLoading('Saving score...');
        try {
            const result = await apiRequest('save_question_score', {
                submission_id: currentReviewSubmission.submission_id,
                question_number: questionIndex + 1,
                score: score,
                feedback: feedback
            });

            if (result.success) {
                toast(`✅ Score saved! Total: ${result.total_score}`);
                updateTotalScoreDisplay();

                // Update the submission's total score in the background
                if (currentReviewSubmission) {
                    currentReviewSubmission.total_marks = result.total_score;
                }
            } else {
                toast('❌ Failed to save score: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            toast('❌ Error saving score');
        } finally {
            hideLoading();
        }
    }

    // Save all scores at once
    async function saveAllScores() {
        showLoading('Saving all scores...');
        let saved = 0;

        for (let i = 0; i < currentQuestions.length; i++) {
            const score = currentScores[i];
            const feedback = document.getElementById(`feedbackInput_${i}`)?.value || '';

            try {
                await apiRequest('save_question_score', {
                    submission_id: currentReviewSubmission.submission_id,
                    question_number: i + 1,
                    score: score,
                    feedback: feedback
                });
                saved++;
            } catch (error) {
                console.error(`Error saving question ${i + 1}:`, error);
            }
        }

        toast(`✅ Saved ${saved} of ${currentQuestions.length} questions`);
        hideLoading();
    }

    // Open code tester modal
    function openCodeTester(code, language) {
        const modal = document.getElementById('codeTesterModal');
        const editor = document.getElementById('testCodeEditor');
        const langSelect = document.getElementById('testLanguage');

        if (editor) {
            editor.value = code;
        }

        // Set language
        let langValue = 'javascript';
        if (language.toLowerCase().includes('python')) langValue = 'python';
        else if (language.toLowerCase().includes('html')) langValue = 'html';
        else if (language.toLowerCase().includes('java')) langValue = 'python';
        else if (language.toLowerCase().includes('php')) langValue = 'python';
        else langValue = 'javascript';

        if (langSelect) langSelect.value = langValue;

        if (modal) modal.style.display = 'flex';
    }

    // Close code tester modal
    function closeCodeTesterModal() {
        const modal = document.getElementById('codeTesterModal');
        if (modal) modal.style.display = 'none';
    }

    // Run code test
    function runCodeTest() {
        const code = document.getElementById('testCodeEditor').value;
        const language = document.getElementById('testLanguage').value;
        const iframe = document.getElementById('testOutputFrame');

        if (!iframe) return;

        if (language === 'javascript') {
            // Create a safe execution environment
            let output = '';
            const originalLog = console.log;
            console.log = function(...args) {
                output += args.map(arg => {
                    if (typeof arg === 'object') return JSON.stringify(arg, null, 2);
                    return String(arg);
                }).join(' ') + '\n';
            };

            try {
                const result = eval(code);
                if (result !== undefined && output === '') {
                    output = String(result);
                }
                iframe.srcdoc = `
                <html>
                <head><style>body{background:#1e1e1e;color:#d4d4d4;font-family:monospace;padding:15px;margin:0;}</style></head>
                <body><pre style="margin:0;white-space:pre-wrap;">${escapeHTML(output) || 'No output'}</pre></body>
                </html>
            `;
            } catch (e) {
                iframe.srcdoc = `
                <html>
                <head><style>body{background:#1e1e1e;color:#ef4444;font-family:monospace;padding:15px;margin:0;}</style></head>
                <body><pre style="margin:0;">Error: ${escapeHTML(e.message)}</pre></body>
                </html>
            `;
            }

            console.log = originalLog;
        } else if (language === 'html') {
            iframe.srcdoc = code;
        } else {
            iframe.srcdoc = `
            <html>
            <head><style>body{background:#1e1e1e;color:#fbbf24;font-family:monospace;padding:15px;margin:0;}</style></head>
            <body><pre style="margin:0;">⚠️ ${language.toUpperCase()} execution requires backend support.\n\nYour code:\n${escapeHTML(code)}</pre></body>
            </html>
        `;
        }
    }

    // Close question review modal
    function closeQuestionReviewModal() {
        const modal = document.getElementById('questionReviewModal');
        if (modal) modal.style.display = 'none';
        currentReviewSubmission = null;
        currentQuestions = [];
        currentScores = {};
    }

    // Download submission as ZIP with folder structure
    async function downloadSubmission(submissionId) {
        showLoading('Preparing submission files...');
        try {
            // Create a direct link download without AJAX to avoid blob issues
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;
            form.target = '_blank';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'download_submission_zip';

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'submission_id';
            idInput.value = submissionId;

            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();

            // Clean up
            setTimeout(() => {
                document.body.removeChild(form);
            }, 1000);

            toast('✅ Download started! The ZIP file will contain organized folders for each question.');
        } catch (error) {
            console.error('Download error:', error);
            toast('❌ Download failed: ' + error.message);
        } finally {
            setTimeout(() => hideLoading(), 1500);
        }
    }

    // ============================================
    // COURSE CARDS VIEW - First screen
    // ============================================

    let currentExpandedCourse = null;
    let currentCourseData = null;

    function courseAccentColor(courseCode, courseName = '') {
        const palette = ['#2563eb', '#059669', '#dc2626', '#7c3aed', '#ea580c', '#0891b2', '#be123c', '#4f46e5'];
        const key = `${courseCode}-${courseName}`;
        let hash = 0;
        for (let i = 0; i < key.length; i++) hash = ((hash << 5) - hash) + key.charCodeAt(i);
        return palette[Math.abs(hash) % palette.length];
    }

    function submittedDateValue(submission) {
        return submission?.submitted_exact || submission?.submittedAt || submission?.submitted_at || submission?.updated_at || '';
    }

    function parseQodaDate(value) {
        if (!value) return null;
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatQodaDateTime(value) {
        const date = parseQodaDate(value);
        return date ? date.toLocaleString() : 'Not set';
    }

    function submissionStatusPriority(status) {
        status = normalizeSubmissionStatus(status);
        if (['GRADED', 'MARKED', 'MANUALLY_GRADED'].includes(status)) return 5;
        if (status === 'AUTO_GRADED') return 4;
        if (['SUBMITTED', 'TIMED_OUT'].includes(status)) return 3;
        return 1;
    }

    function dedupeSubmissionsByExamStudent(submissions) {
        const map = new Map();
        (submissions || []).forEach(sub => {
            const status = normalizeSubmissionStatus(sub.status);
            if (['IN_PROGRESS', 'DRAFT', 'AUTOSAVED'].includes(status)) return;
            const studentKey = sub.student_id || sub.student_identifier || sub.student_name || 'unknown';
            const key = `${sub.exam_id || 'exam'}::${studentKey}`;
            const existing = map.get(key);
            if (!existing) {
                map.set(key, sub);
                return;
            }
            const currentPriority = submissionStatusPriority(sub.status);
            const existingPriority = submissionStatusPriority(existing.status);
            const currentTime = parseQodaDate(submittedDateValue(sub))?.getTime() || 0;
            const existingTime = parseQodaDate(submittedDateValue(existing))?.getTime() || 0;
            if (currentPriority > existingPriority || (currentPriority === existingPriority && (currentTime > existingTime || Number(sub.id || 0) > Number(existing.id || 0)))) {
                map.set(key, sub);
            }
        });
        return Array.from(map.values());
    }

    function getExamInstanceMeta(sub) {
        const examDetail = examDetailsCache[sub.exam_id] || sub || {};
        const startValue = examDetail.start_datetime || sub.start_datetime || '';
        const endValue = examDetail.end_datetime || sub.end_datetime || '';
        const startDate = parseQodaDate(startValue);
        const endDate = parseQodaDate(endValue);
        const courseCode = examDetail.course_code || sub.course_code || 'Unknown';
        const courseName = examDetail.course_name || sub.course_name || examDetail.title || sub.exam_title || 'Unknown Course';
        return {
            examId: String(sub.exam_id || examDetail.id || ''),
            courseCode,
            courseName,
            examCode: examDetail.exam_code || sub.exam_code || examDetail.exam_id || '',
            level: examDetail.level || sub.level || 'N/A',
            semester: examDetail.semester || sub.semester || 'N/A',
            schoolType: examDetail.school_type || sub.school_type || 'Regular',
            academicYear: examDetail.academic_year || sub.academic_year || `${new Date().getFullYear()}/${new Date().getFullYear() + 1}`,
            examType: examDetail.exam_type || sub.exam_type || '',
            startValue,
            endValue,
            startLabel: formatQodaDateTime(startValue),
            endLabel: formatQodaDateTime(endValue),
            month: startDate ? startDate.toLocaleString(undefined, { month: 'long' }) : 'Not set',
            year: startDate ? String(startDate.getFullYear()) : '',
            dateLabel: startDate ? startDate.toLocaleDateString() : 'Not set',
            timeLabel: startDate ? startDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Not set',
            endTimeLabel: endDate ? endDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Not set',
            examDetail
        };
    }

    function getCourseGroupKey(meta) {
        return `${meta.courseCode}::${meta.courseName}`;
    }

    function getExamInstanceKey(meta) {
        return [
            meta.examId,
            meta.courseCode,
            meta.courseName,
            meta.startValue || 'no-start',
            meta.endValue || 'no-end',
            meta.schoolType,
            meta.semester,
            meta.academicYear
        ].join('::');
    }

    function getClassScoreForSubmission(sub) {
        const grading = getSubmissionGrading(sub);
        if (sub.class_score !== undefined && sub.class_score !== null && sub.class_score !== '') {
            return clampScore(sub.class_score, 0, 40);
        }
        if (grading.class_score !== undefined && grading.class_score !== null && grading.class_score !== '') {
            return clampScore(grading.class_score, 0, 40);
        }
        return clampScore(studentClassScores[`${sub.exam_id}:${sub.student_identifier}`] ?? studentClassScores[sub.student_identifier] ?? 0, 0, 40);
    }

    // Render course cards (first view - shows which courses have submissions)
    // Render course cards (first view - ONLY CARDS, no filters or tables)
    function renderCourseCardsView(submissions) {
        const container = document.getElementById('submissionsContainer');
        if (!container) return;

        // Group submitted rows by course, then by exact exam instance.
        const groupedByCourse = {};
        dedupeSubmissionsByExamStudent(submissions).forEach(sub => {
            const meta = getExamInstanceMeta(sub);
            const courseKey = getCourseGroupKey(meta);
            const instanceKey = getExamInstanceKey(meta);

            if (!groupedByCourse[courseKey]) {
                groupedByCourse[courseKey] = {
                    courseKey: courseKey,
                    courseCode: meta.courseCode,
                    courseName: meta.courseName,
                    level: meta.level,
                    semester: meta.semester,
                    submissions: [],
                    instances: {},
                    examDetail: meta.examDetail
                };
            }
            if (!groupedByCourse[courseKey].instances[instanceKey]) {
                groupedByCourse[courseKey].instances[instanceKey] = {
                    instanceKey,
                    isExamInstance: true,
                    parentCourseKey: courseKey,
                    courseKey: instanceKey,
                    courseCode: meta.courseCode,
                    courseName: meta.courseName,
                    level: meta.level,
                    semester: meta.semester,
                    academicYear: meta.academicYear,
                    schoolType: meta.schoolType,
                    month: meta.month,
                    year: meta.year,
                    startLabel: meta.startLabel,
                    endLabel: meta.endLabel,
                    dateLabel: meta.dateLabel,
                    timeLabel: meta.timeLabel,
                    endTimeLabel: meta.endTimeLabel,
                    examCode: meta.examCode,
                    examId: meta.examId,
                    startValue: meta.startValue,
                    endValue: meta.endValue,
                    submissions: [],
                    examDetail: meta.examDetail
                };
            }
            groupedByCourse[courseKey].submissions.push(sub);
            groupedByCourse[courseKey].instances[instanceKey].submissions.push(sub);
        });

        // Store globally
        window.courseGroupsData = groupedByCourse;

        // Simple search and filter for courses only
        let html = `
        <div class="course-search-bar" style="background: var(--bg); border-radius: 12px; padding: 20px; margin-bottom: 25px; border: 1px solid var(--border);">
            <h3 style="margin: 0 0 15px 0;"><i class="fas fa-chalkboard-teacher"></i> My Courses</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                <div class="field" style="margin-bottom: 0;">
                    <label><i class="fas fa-search"></i> Search Course</label>
                    <input type="text" id="courseSearchInput" placeholder="Search by course code or name..." 
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);"
                        onkeyup="filterCourseCards()">
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label><i class="fas fa-graduation-cap"></i> Filter by Level</label>
                    <select id="levelFilterSelect" onchange="filterCourseCards()" 
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        <option value="all">All Levels</option>
                        <option value="100">100 Level</option>
                        <option value="200">200 Level</option>
                        <option value="300">300 Level</option>
                        <option value="400">400 Level</option>
                        <option value="500">500 Level</option>
                    </select>
                </div>
            </div>
            <div style="margin-top: 15px;">
                <button class="btn" onclick="resetCourseFilters()">
                    <i class="fas fa-undo"></i> Reset Filters
                </button>
            </div>
        </div>
        <div class="courses-grid">
    `;

        // Filter courses
        let filteredCourses = Object.entries(groupedByCourse);
        const searchTerm = document.getElementById('courseSearchInput')?.value.toLowerCase() || '';
        const levelFilter = document.getElementById('levelFilterSelect')?.value || 'all';

        if (searchTerm) {
            filteredCourses = filteredCourses.filter(([code, course]) =>
                course.courseCode.toLowerCase().includes(searchTerm) ||
                course.courseName.toLowerCase().includes(searchTerm)
            );
        }
        if (levelFilter !== 'all') {
            filteredCourses = filteredCourses.filter(([code, course]) => course.level === levelFilter);
        }

        if (filteredCourses.length === 0) {
            container.innerHTML = `
            <div style="text-align: center; padding: 60px;">
                <i class="fas fa-search" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <h3>No courses found</h3>
                <p>Try adjusting your search filters</p>
                <button class="btn primary" onclick="resetCourseFilters()">Reset Filters</button>
            </div>
        `;
            return;
        }

        for (const [courseKey, course] of filteredCourses) {
            const courseCode = course.courseCode;
            const courseSubmissions = dedupeSubmissionsByExamStudent(course.submissions);
            const instances = Object.values(course.instances || {});

            // Calculate statistics
            let totalScore = 0;
            let gradedCount = courseSubmissions.filter(s => isGradedSubmission(s.status)).length;
            let totalStudents = courseSubmissions.length;

            courseSubmissions.forEach(sub => {
                totalScore += getSubmissionScoreParts(sub).totalScore;
            });

            const averageScore = totalStudents > 0 ? roundToWholeNumber(totalScore / totalStudents) : 0;
            const progressPercent = (gradedCount / totalStudents) * 100;

            const levelColor = courseAccentColor(courseCode, course.courseName);

            html += `
            <div class="course-card level-${course.level}" onclick='showCourseDetails(${JSON.stringify(courseKey)})'>
                <div class="course-card-header" style="background: linear-gradient(135deg, ${levelColor}, ${levelColor}dd);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <h3 style="margin: 0; font-size: 20px;">
                                <i class="fas fa-code"></i> ${escapeHTML(course.courseName)}
                            </h3>
                            <p style="margin: 8px 0 0 0; opacity: 0.9; font-size: 14px;">
                                <i class="fas fa-tag"></i> ${escapeHTML(courseCode)}
                            </p>
                        </div>
                        <div class="course-level-badge" style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-weight: bold;">
                            Level ${escapeHTML(course.level)}
                        </div>
                    </div>
                </div>
                
                <div class="course-card-stats" style="display: flex; justify-content: space-between; padding: 20px;">
                    <div class="course-stat" style="text-align: center; flex: 1;">
                        <div class="course-stat-value" style="font-size: 28px; font-weight: 700; color: ${levelColor};">${totalStudents}</div>
                        <div class="course-stat-label" style="font-size: 11px; color: var(--muted); text-transform: uppercase;">Students</div>
                    </div>
                    <div class="course-stat" style="text-align: center; flex: 1;">
                        <div class="course-stat-value" style="font-size: 28px; font-weight: 700; color: ${levelColor};">${averageScore}%</div>
                        <div class="course-stat-label" style="font-size: 11px; color: var(--muted); text-transform: uppercase;">Avg Score</div>
                    </div>
                    <div class="course-stat" style="text-align: center; flex: 1;">
                        <div class="course-stat-value" style="font-size: 28px; font-weight: 700; color: ${levelColor};">${gradedCount}/${totalStudents}</div>
                        <div class="course-stat-label" style="font-size: 11px; color: var(--muted); text-transform: uppercase;">Graded</div>
                    </div>
                </div>
                
                <div style="padding: 0 20px;">
                    <div class="progress" style="height: 6px; background: var(--border); border-radius: 10px;">
                        <div class="progress-bar" style="width: ${progressPercent}%; background: ${levelColor}; height: 100%; border-radius: 10px;"></div>
                    </div>
                </div>
                
                <div class="course-card-footer" style="padding: 15px 20px; background: var(--bg); display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 12px; color: var(--muted);">
                        <i class="fas fa-calendar"></i> ${instances.length} exam instance${instances.length === 1 ? '' : 's'}
                    </div>
                    <div style="font-size: 12px; color: ${levelColor};">
                        Click to view <i class="fas fa-arrow-right"></i>
                    </div>
                </div>
            </div>
        `;
        }

        html += `</div>`;
        container.innerHTML = html;
    }

    // Show full submission page for selected course
    function showCourseDetails(courseCode) {
        const course = window.courseGroupsData[courseCode];
        if (!course) {
            toast('❌ Course not found');
            return;
        }

        currentExpandedCourse = courseCode;
        currentCourseData = course;

        // Save state for page refresh
        sessionStorage.setItem('currentSubmissionView', 'course_detail');
        sessionStorage.setItem('currentCourseCode', courseCode);

        renderExamInstancesPage(course);
    }

    function renderExamInstancesPage(course) {
        const container = document.getElementById('submissionsContainer');
        if (!container) return;
        const instances = Object.values(course.instances || {}).sort((a, b) => {
            const ad = parseQodaDate(a.startValue || a.startLabel)?.getTime() || 0;
            const bd = parseQodaDate(b.startValue || b.startLabel)?.getTime() || 0;
            return bd - ad;
        });
        const color = courseAccentColor(course.courseCode, course.courseName);
        let html = `
        <div style="margin-bottom:20px;">
            <button class="back-to-courses-btn" onclick="goBackToCourses()">
                <i class="fas fa-arrow-left"></i> Back to All Courses
            </button>
        </div>
        <div style="background:linear-gradient(135deg, ${color}, ${color}dd); color:white; border-radius:16px; padding:24px; margin-bottom:20px;">
            <h2 style="margin:0 0 6px 0;">${escapeHTML(course.courseName)}</h2>
            <div style="opacity:.95;">${escapeHTML(course.courseCode)} | ${instances.length} exam instance${instances.length === 1 ? '' : 's'}</div>
        </div>
        <div class="courses-grid">`;

        if (instances.length === 0) {
            html += `
            <div style="grid-column:1/-1; text-align:center; padding:50px; background:var(--panel); border:1px solid var(--border); border-radius:16px;">
                <i class="fas fa-folder-open" style="font-size:42px; color:var(--muted);"></i>
                <h3>No submitted exam instances yet</h3>
            </div>`;
        }

        instances.forEach(instance => {
            const submissions = dedupeSubmissionsByExamStudent(instance.submissions);
            const graded = submissions.filter(s => isGradedSubmission(s.status)).length;
            const avg = submissions.length ? roundToWholeNumber(submissions.reduce((sum, sub) => sum + getSubmissionScoreParts(sub).totalScore, 0) / submissions.length) : 0;
            html += `
            <div class="course-card" style="cursor:auto;">
                <div class="course-card-header" style="background:linear-gradient(135deg, ${color}, ${color}dd);">
                    <h3 style="margin:0; font-size:18px;">${escapeHTML(instance.examCode || ('Exam #' + instance.examId))}</h3>
                    <p style="margin:8px 0 0 0; opacity:.95;">${escapeHTML(instance.schoolType)} | ${escapeHTML(instance.semester)} | ${escapeHTML(instance.academicYear)}</p>
                </div>
                <div style="padding:18px; display:grid; gap:10px;">
                    <div><strong>Date:</strong> ${escapeHTML(instance.dateLabel)} (${escapeHTML(instance.month)} ${escapeHTML(instance.year)})</div>
                    <div><strong>Time:</strong> ${escapeHTML(instance.timeLabel)} - ${escapeHTML(instance.endTimeLabel)}</div>
                    <div><strong>Students Submitted:</strong> ${submissions.length}</div>
                    <div><strong>Graded:</strong> ${graded}/${submissions.length} | <strong>Average:</strong> ${avg}%</div>
                </div>
                <div style="padding:0 18px 18px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button class="btn primary" onclick='showExamInstanceDetails(${JSON.stringify(course.courseKey)}, ${JSON.stringify(instance.instanceKey)})'>
                        <i class="fas fa-table"></i> View Score Sheet
                    </button>
                    <button class="btn success" onclick='publishSingleExamInstance(${JSON.stringify(instance.examId)}, ${JSON.stringify(instance.courseName)})'>
                        <i class="fas fa-bullhorn"></i> Publish Result
                    </button>
                    <button class="btn danger" onclick='deleteExamInstanceSubmissions(${JSON.stringify(instance.examId)}, ${JSON.stringify(instance.courseCode)})'>
                        <i class="fas fa-trash"></i> Delete Submissions
                    </button>
                </div>
            </div>`;
        });

        html += `</div>`;
        container.innerHTML = html;
    }

    function showExamInstanceDetails(courseKey, instanceKey) {
        const course = window.courseGroupsData[courseKey];
        const instance = course?.instances?.[instanceKey];
        if (!instance) {
            toast('Exam instance not found');
            return;
        }
        currentCourseData = instance;
        sessionStorage.setItem('currentSubmissionView', 'exam_instance');
        sessionStorage.setItem('currentCourseCode', courseKey);
        sessionStorage.setItem('currentExamInstanceKey', instanceKey);
        sessionStorage.setItem('currentSubmissionExamId', String(instance.examId || ''));
        renderFullSubmissionPage(instance);
    }

    async function publishSingleExamInstance(examId, courseName) {
        if (!confirm(`Publish results for ${courseName}? Students will be able to view them.`)) return;
        showLoading('Publishing result...');
        const result = await apiRequest('publish_results', { exam_id: examId });
        hideLoading();
        toast(result.success ? 'Result published' : ('Publish failed: ' + (result.error || 'Unknown error')));
    }

    async function deleteExamInstanceSubmissions(examId, courseCode) {
        if (!confirm(`Delete all submissions for this ${courseCode} exam instance? This cannot be undone.`)) return;
        showLoading('Deleting submissions...');
        const result = await apiRequest('delete_course_submissions', { exam_ids: JSON.stringify([Number(examId)]) });
        hideLoading();
        if (result.success) {
            toast(`Deleted ${result.deleted || 0} submission(s)`);
            await loadSubmissions();
        } else {
            toast('Delete failed: ' + (result.error || 'Unknown error'));
        }
    }

    // Render the complete submission page (all filters, stats, table) for a specific course
    function renderFullSubmissionPage(course) {
        const container = document.getElementById('submissionsContainer');
        const courseSubmissions = dedupeSubmissionsByExamStudent(course.submissions);
        const examDetail = course.examDetail;

        // Filter submissions for this course only
        const filteredSubmissions = originalSubmissionsData.filter(s => {
            const examDetail2 = examDetailsCache[s.exam_id];
            const subCourseCode = examDetail2 ? examDetail2.course_code : (s.course_code || '');
            const subCourseName = examDetail2 ? (examDetail2.course_name || examDetail2.title) : (s.course_name || s.exam_title || '');
            return subCourseCode === course.courseCode && subCourseName === course.courseName;
        });

        // Calculate statistics for this course
        let totalScoreSum = 0;
        let passed = 0;
        let grades = {
            A: 0,
            Bplus: 0,
            B: 0,
            Cplus: 0,
            C: 0,
            Dplus: 0,
            D: 0,
            E: 0
        };

        courseSubmissions.forEach(sub => {
            const { totalScore } = getSubmissionScoreParts(sub);
            totalScoreSum += totalScore;

            if (totalScore >= 50) passed++;

            const gradeInfo = getGradeInfo(totalScore);
            if (gradeInfo.grade === 'A') grades.A++;
            else if (gradeInfo.grade === 'B+') grades.Bplus++;
            else if (gradeInfo.grade === 'B') grades.B++;
            else if (gradeInfo.grade === 'C+') grades.Cplus++;
            else if (gradeInfo.grade === 'C') grades.C++;
            else if (gradeInfo.grade === 'D+') grades.Dplus++;
            else if (gradeInfo.grade === 'D') grades.D++;
            else grades.E++;
        });

        const studentCount = courseSubmissions.length;
        const average = studentCount > 0 ? roundToWholeNumber(totalScoreSum / studentCount) : 0;
        const passRate = studentCount > 0 ? roundToWholeNumber((passed / studentCount) * 100) : 0;

        // Check if all students have class scores
        const allHaveClassScores = courseSubmissions.every(sub => getSubmissionGrading(sub).class_score !== undefined ||
            studentClassScores[`${sub.exam_id}:${sub.student_identifier}`] !== undefined ||
            studentClassScores[sub.student_identifier] !== undefined);

        // Get course details for header
        const schoolName = examDetail?.school_name || 'Pentecost University';
        const programme = examDetail?.programme || examDetail?.department || 'IT';
        const schoolType = examDetail?.school_type || 'Regular';
        const level = examDetail?.level || course.level;
        const semester = examDetail?.semester || course.semester;
        const academicYear = examDetail?.academic_year || `${new Date().getFullYear()}/${new Date().getFullYear() + 1}`;
        const intake = examDetail?.intake || '1';
        const courseName = examDetail?.course_name || examDetail?.title || course.courseName;
        const courseCode = examDetail?.course_code || course.courseCode;
        const courseKey = course.courseKey || `${courseCode}::${courseName}`;

        let html = `
        <!-- Back Button -->
        <div style="margin-bottom: 20px;">
            <button class="back-to-courses-btn" onclick="goBackToCourses()">
                <i class="fas fa-arrow-left"></i> Back to All Courses
            </button>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar" style="background: var(--bg); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--border);">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 15px;">
                <div class="field" style="margin-bottom: 0;">
                    <label><i class="fas fa-user-graduate"></i> Search by Student</label>
                    <input type="text" id="filterStudentSearch" placeholder="Search student name or ID..." 
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);"
                        onkeyup="filterCourseSubmissions()">
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label><i class="fas fa-filter"></i> Filter by Status</label>
                    <select id="filterStatus" onchange="filterCourseSubmissions()"
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        <option value="all">All Status</option>
                        <option value="SUBMITTED">Submitted</option>
                        <option value="GRADED">Graded</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: space-between;">
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="btn" onclick="resetCourseFilters()" style="background: var(--bg);">
                        <i class="fas fa-undo"></i> Reset Filters
                    </button>
                    <button class="btn success" onclick='exportCourseToExcel(${JSON.stringify(courseKey)})'
                        style="background: linear-gradient(135deg, #10b981, #059669); color: white;">
                        <i class="fas fa-file-excel"></i> Export Marks as CSV
                    </button>
                    <button class="btn primary" onclick="refreshSubmissions()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn info" onclick="openCalculatorModal()"
                        style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white;">
                        <i class="fas fa-calculator"></i> Grade Calculator
                    </button>
                    <button class="btn warn" onclick='downloadCourseSubmissions(${JSON.stringify(courseKey)})'
                        style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white;">
                        <i class="fas fa-folder-download"></i> Download All Submissions (ZIP)
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Class Score Entry Section -->
        <div class="course-class-score-panel" style="background: linear-gradient(135deg, rgba(59,130,246,0.1), rgba(139,92,246,0.1)); border-radius: 12px; padding: 20px; margin: 20px 0; border: 1px solid var(--border);">
            <h4 style="margin: 0 0 15px 0;"><i class="fas fa-chart-line"></i> Class Score Entry (Max 40 points)</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                <div class="field" style="margin-bottom: 0;">
                    <label>Select Student:</label>
                    <select id="classScoreStudentSelect" style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        <option value="">-- Select Student --</option>
                        ${courseSubmissions.map(sub => `<option value="${sub.student_identifier}">${escapeHTML(sub.student_name)} (${sub.student_identifier})</option>`).join('')}
                    </select>
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label>Class Score (0 - 40):</label>
                    <input type="number" id="classScoreInput" min="0" max="40" step="1" value="0"
                        style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                </div>
                <div style="display: flex; align-items: flex-end; gap: 10px;">
                    <button class="btn primary" onclick='updateClassScoreForCourse(${JSON.stringify(courseKey)})' style="flex: 1;">
                        <i class="fas fa-save"></i> Apply Class Score
                    </button>
                    <button class="btn success" onclick='markAllCourseAsGraded(${JSON.stringify(courseKey)})' style="flex: 1;" ${!allHaveClassScores ? 'disabled' : ''}>
                        <i class="fas fa-check-double"></i> Mark All as Graded
                    </button>
                </div>
            </div>
            <div class="hint" style="margin-top: 10px;">
                <i class="fas fa-info-circle"></i> Exam score (max 60) is calculated from student's submission. Class score (max 40) is added by lecturer. Total = 100.
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px;">
            <div class="stat-card blue" style="padding: 20px; border-radius: 16px; background: var(--panel); border: 1px solid var(--border);">
                <div class="stat-label">TOTAL STUDENTS</div>
                <div class="stat-value" style="font-size: 32px;">${studentCount}</div>
            </div>
            <div class="stat-card green" style="padding: 20px; border-radius: 16px; background: var(--panel); border: 1px solid var(--border);">
                <div class="stat-label">PASSED (≥50)</div>
                <div class="stat-value" style="font-size: 32px; color: #10b981;">${passed}</div>
                <small>${passRate}% pass rate</small>
            </div>
            <div class="stat-card red" style="padding: 20px; border-radius: 16px; background: var(--panel); border: 1px solid var(--border);">
                <div class="stat-label">FAILED (<50)</div>
                <div class="stat-value" style="font-size: 32px; color: #ef4444;">${studentCount - passed}</div>
            </div>
            <div class="stat-card orange" style="padding: 20px; border-radius: 16px; background: var(--panel); border: 1px solid var(--border);">
                <div class="stat-label">AVERAGE SCORE</div>
                <div class="stat-value" style="font-size: 32px;">${average}</div>
            </div>
        </div>
        
        <!-- Grade Distribution -->
        <div class="course-grade-distribution-panel" style="background: var(--panel); border-radius: 16px; padding: 20px; margin: 20px 0; border: 1px solid var(--border);">
            <h4 style="margin: 0 0 15px 0;"><i class="fas fa-chart-pie"></i> Grade Distribution</h4>
            <div style="display: flex; gap: 15px; flex-wrap: wrap; justify-content: space-between;">
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-a" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">A</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.A}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-bplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">B+</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Bplus}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-b" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">B</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.B}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-cplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">C+</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Cplus}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-c" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">C</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.C}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-dplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">D+</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Dplus}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-d" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">D</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.D}</div>
                </div>
                <div style="text-align: center; min-width: 70px; padding: 10px;  border-radius: 12px;">
                    <div class="tag grade-e" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">E</div>
                    <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.E}</div>
                </div>
            </div>
        </div>
        
        <!-- Course Header with Score Sheet -->
        <div style="background: linear-gradient(135deg, #1e3a5f, #3b82f6); color: white; border-radius: 16px; margin-bottom: 20px; overflow: hidden;">
            <div style="text-align: center; padding: 20px;">
                <h1 style="margin: 0; font-size: 24px;">${escapeHTML(schoolName)}</h1>
                <h2 style="margin: 10px 0; font-size: 20px;">SCORE SHEET</h2>
                <p style="margin: 5px 0; font-size: 14px;">${escapeHTML(programme)} - ${escapeHTML(schoolType)}</p>
                <p style="margin: 5px 0; font-size: 14px;">
                    Level: ${escapeHTML(level)} | Semester: ${escapeHTML(semester)} | Academic Year: ${escapeHTML(academicYear)} | Intake: ${escapeHTML(intake)}
                </p>
                <p style="margin: 5px 0; font-size: 14px; font-weight: bold;">
                    Course: ${escapeHTML(courseName)} (${escapeHTML(courseCode)})
                </p>
            </div>
            <div style="display: flex; justify-content: flex-end; padding: 10px 20px 20px 20px;">
                <button class="btn" onclick='downloadCourseSubmissions(${JSON.stringify(courseKey)})' 
                    style="background: white; color: #3b82f6; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer;">
                    <i class="fas fa-download"></i> Download Course ZIP
                </button>
            </div>
        </div>
        
        <!-- Submissions Table -->
        <div style="overflow-x: auto; border-radius: 12px; border: 1px solid var(--border);">
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #000;" id="courseSubmissionsTable">
                <thead>
                    <tr>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">S/N</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Student ID</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Student Name</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Class Score (40%)</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Exam Score (60%)</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Total Score (100%)</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Grade</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">GP</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Status</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Submitted</th>
                        <th style="border: 1px solid #000; padding: 12px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="submissionsTableBody">
    `;

        let sn = 1;
        for (const sub of courseSubmissions) {
            const studentName = sub.student_name || 'Unknown Student';
            const studentId = sub.student_identifier || 'N/A';
            const status = sub.status || 'SUBMITTED';

            const { classScore, examScore, totalScore } = getSubmissionScoreParts(sub);
            const gradeInfo = getGradeInfo(totalScore);

            let statusClass = 'status-pending';
            let statusText = status;
            if (isGradedSubmission(status)) {
                statusClass = 'status-published';
                statusText = '✓ Graded';
            } else if (normalizeSubmissionStatus(status) === 'SUBMITTED') {
                statusClass = 'status-pending';
                statusText = '📝 Submitted';
            }

            const scoreColor = totalScore >= 70 ? '#10b981' : (totalScore >= 50 ? '#f59e0b' : '#ef4444');
            const submittedLabel = formatQodaDateTime(submittedDateValue(sub));

            html += `
            <tr data-student-name="${studentName.toLowerCase()}" data-student-id="${studentId.toLowerCase()}" data-status="${status}">
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">${sn++}</td>
                <td style="border: 1px solid #000; padding: 12px;">
                    <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px;">${escapeHTML(studentId)}</code>
                </td>
                <td style="border: 1px solid #000; padding: 12px;">
                    <strong>${escapeHTML(studentName)}</strong>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                        <strong style="color: #f59e0b; font-size: 18px;">${classScore}</strong>
                        <button class="btn small" onclick='editClassScoreForCourse(${JSON.stringify(studentId)}, ${JSON.stringify(studentName)}, ${JSON.stringify(courseKey)})' 
                            style="padding: 2px 8px; font-size: 11px;">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                    </div>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <strong style="font-size: 18px;">${examScore}</strong>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <div style="font-weight: 700; font-size: 22px; color: ${scoreColor};">${totalScore}</div>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <span class="tag ${gradeInfo.class}" style="font-size: 18px; font-weight: 700; padding: 6px 12px;">${gradeInfo.grade}</span>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <strong>${gradeInfo.gradePoint.toFixed(1)}</strong>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <span class="tag ${statusClass}">${statusText}</span>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                    <small>${escapeHTML(submittedLabel)}</small>
                </td>
                <td style="border: 1px solid #000; padding: 12px; text-align: center; white-space: nowrap;">
                    <button class="btn primary small" onclick="openQuestionReview(${sub.id})" 
                        style="padding: 6px 12px; margin: 2px;">
                        <i class="fas fa-eye"></i> Review & Mark
                    </button>
                    <button class="btn danger small" onclick="deleteSubmissionFromCurrentView(${sub.id})"
                        style="padding: 6px 12px; margin: 2px;">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <button class="btn success small" onclick="downloadSubmission(${sub.id})" 
                        style="padding: 6px 12px; margin: 2px;">
                        <i class="fas fa-download"></i> Download ZIP
                    </button>
                </td>
            </tr>
        `;
        }

        html += `
                </tbody>
                <tfoot style="background: #f9f9f9;">
                    <tr>
                        <td colspan="10" style="border: 1px solid #000; padding: 8px; text-align: right;"><strong>Total Students:</strong></td>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center;"><strong>${studentCount}</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;

        container.innerHTML = html;
        const courseTable = container.querySelector('#courseSubmissionsTable');
        const courseClassPanel = container.querySelector('.course-class-score-panel');
        const courseGradePanel = container.querySelector('.course-grade-distribution-panel');
        const tableWrap = courseTable?.closest('div');
        if (tableWrap && courseClassPanel) {
            tableWrap.insertAdjacentElement('afterend', courseClassPanel);
        }
        if (courseClassPanel && courseGradePanel) {
            courseClassPanel.insertAdjacentElement('afterend', courseGradePanel);
        } else if (tableWrap && courseGradePanel) {
            tableWrap.insertAdjacentElement('afterend', courseGradePanel);
        }

        // Store current course code for filters
        window.currentCourseCode = courseCode;
    }

    async function deleteSubmissionFromCurrentView(submissionId) {
        if (!confirm('Delete this student submission? This cannot be undone.')) return;
        showLoading('Deleting submission...');
        const result = await apiRequest('delete_student_submission', { submission_id: submissionId });
        hideLoading();
        if (result.success) {
            toast('Submission deleted');
            await loadSubmissions();
        } else {
            toast('Delete failed: ' + (result.error || 'Unknown error'));
        }
    }

    // Filter submissions within the current course view
    function filterCourseSubmissions() {
        const searchTerm = document.getElementById('filterStudentSearch')?.value.toLowerCase() || '';
        const statusFilter = document.getElementById('filterStatus')?.value || 'all';
        const tableBody = document.getElementById('submissionsTableBody');

        if (!tableBody) return;

        const rows = tableBody.querySelectorAll('tr');
        let visibleCount = 0;

        rows.forEach(row => {
            const studentName = row.getAttribute('data-student-name') || '';
            const studentId = row.getAttribute('data-student-id') || '';
            const status = row.getAttribute('data-status') || '';

            let matchesSearch = true;
            let matchesStatus = true;

            if (searchTerm) {
                matchesSearch = studentName.includes(searchTerm) || studentId.includes(searchTerm);
            }

            if (statusFilter !== 'all') {
                matchesStatus = status === statusFilter;
            }

            if (matchesSearch && matchesStatus) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Show/hide no results message
        let noResultsMsg = document.getElementById('noFilterResults');
        if (visibleCount === 0 && (searchTerm || statusFilter !== 'all')) {
            if (!noResultsMsg) {
                noResultsMsg = document.createElement('div');
                noResultsMsg.id = 'noFilterResults';
                noResultsMsg.style.cssText = 'text-align: center; padding: 40px; color: var(--muted);';
                noResultsMsg.innerHTML =
                    '<i class="fas fa-search" style="font-size: 48px; margin-bottom: 10px;"></i><p>No students found matching your filters</p>';
                if (tableBody.parentNode.parentNode) {
                    tableBody.parentNode.parentNode.insertBefore(noResultsMsg, tableBody.parentNode.nextSibling);
                }
            }
            noResultsMsg.style.display = 'block';
        } else if (noResultsMsg) {
            noResultsMsg.style.display = 'none';
        }
    }

    // Update class score for a student in the current course
    async function updateClassScoreForCourse(courseCode) {
        const select = document.getElementById('classScoreStudentSelect');
        const scoreInput = document.getElementById('classScoreInput');

        const studentId = select.value;
        let classScore = roundToWholeNumber(scoreInput.value);

        if (!studentId) {
            toast('❌ Please select a student');
            return;
        }

        if (classScore < 0) classScore = 0;
        if (classScore > 40) {
            toast('❌ Class score cannot exceed 40');
            return;
        }

        const course = window.courseGroupsData[courseCode] || currentCourseData;
        const submission = course.submissions.find(s => s.student_identifier === studentId);

        if (!submission) {
            toast('❌ Submission not found');
            return;
        }

        await persistClassScore(submission, classScore);
        saveClassScores();

        // Refresh the view
        renderFullSubmissionPage(course);

        toast(`✅ Class score ${classScore}/40 applied for ${submission.student_name}`);

        scoreInput.value = 0;
        select.value = "";
    }

    // Edit class score for a student
    async function editClassScoreForCourse(studentId, studentName, courseCode) {
        const course = window.courseGroupsData[courseCode] || currentCourseData;
        const submissionForScore = course?.submissions?.find(s => s.student_identifier === studentId);
        const currentScore = submissionForScore ? getClassScoreForSubmission(submissionForScore) : (studentClassScores[studentId] || 0);
        const newScore = prompt(`Enter class score for ${studentName} (0-40):`, currentScore);

        if (newScore !== null) {
            let scoreNum = roundToWholeNumber(newScore);
            if (isNaN(scoreNum)) {
                toast('❌ Please enter a valid number');
                return;
            }
            if (scoreNum < 0) scoreNum = 0;
            if (scoreNum > 40) {
                toast('❌ Class score cannot exceed 40');
                return;
            }
            const submission = course.submissions.find(s => s.student_identifier === studentId);
            if (submission) {
                await persistClassScore(submission, scoreNum);
                saveClassScores();
            }

            renderFullSubmissionPage(course);
            toast(`✅ Class score updated to ${scoreNum}/40 for ${studentName}`);
        }
    }

    // Go back to courses list
    function goBackToCourses() {
        currentExpandedCourse = null;
        currentCourseData = null;

        // Clear session storage
        sessionStorage.removeItem('currentSubmissionView');
        sessionStorage.removeItem('currentCourseCode');
        sessionStorage.removeItem('currentExamInstanceKey');
        sessionStorage.removeItem('currentSubmissionExamId');

        // Clear search inputs
        const courseSearch = document.getElementById('courseSearchInput');
        const levelFilter = document.getElementById('levelFilterSelect');
        if (courseSearch) courseSearch.value = '';
        if (levelFilter) levelFilter.value = 'all';

        // Re-render the course cards
        if (originalSubmissionsData) {
            renderCourseCardsView(originalSubmissionsData);
        } else {
            loadSubmissions();
        }
    }

    // Filter course cards
    function filterCourseCards() {
        if (originalSubmissionsData && !currentExpandedCourse) {
            renderCourseCardsView(originalSubmissionsData);
        }
    }

    // Reset course filters
    function resetCourseFilters() {
        const searchInput = document.getElementById('courseSearchInput');
        const levelFilter = document.getElementById('levelFilterSelect');
        const studentSearch = document.getElementById('filterStudentSearch');
        const statusFilter = document.getElementById('filterStatus');

        if (searchInput) searchInput.value = '';
        if (levelFilter) levelFilter.value = 'all';
        if (studentSearch) studentSearch.value = '';
        if (statusFilter) statusFilter.value = 'all';

        if (currentExpandedCourse && currentCourseData) {
            filterCourseSubmissions();
        } else {
            filterCourseCards();
        }
        toast('Filters reset');
    }

    // Mark all submissions in a course as graded
    async function markAllCourseAsGraded(courseCode) {
        const course = window.courseGroupsData[courseCode] || currentCourseData;
        if (!course) return;

        const courseSubmissions = course.submissions;

        // Check if all students have class scores
        const allHaveClassScores = courseSubmissions.every(s => getSubmissionGrading(s).class_score !== undefined ||
            studentClassScores[`${s.exam_id}:${s.student_identifier}`] !== undefined ||
            studentClassScores[s.student_identifier] !== undefined);

        if (!allHaveClassScores) {
            toast('❌ Please fill in class scores for all students in this course first');
            return;
        }

        if (!confirm(`Mark ALL ${courseSubmissions.length} submissions as graded for ${course.courseName}?`))
            return;

        showLoading(`Marking ${courseSubmissions.length} submissions...`);
        let count = 0;

        for (const submission of courseSubmissions) {
            if (submission.status !== 'GRADED') {
                submission.status = 'GRADED';

                const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(submission.percentage) || 0));
                const classScore = getClassScoreForSubmission(submission);
                const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
                const gradeInfo = getGradeInfo(totalScore);

                await apiRequest('update_submission_grade', {
                    submission_id: submission.id,
                    status: 'GRADED',
                    total_score: totalScore,
                    class_score: classScore,
                    exam_score: examScore,
                    grade: gradeInfo.grade,
                    grade_point: gradeInfo.gradePoint
                });
                count++;
            }
        }

        saveGradedStatus();

        // Refresh the view
        renderFullSubmissionPage(course);

        toast(`✅ ${count} submissions marked as graded for ${course.courseName}`);
        hideLoading();
    }

    // Download all submissions for a course
    async function downloadCourseSubmissions(courseCode) {
        const course = window.courseGroupsData[courseCode] || currentCourseData;
        if (!course) return;

        const courseSubmissions = course.submissions;

        if (!courseSubmissions || courseSubmissions.length === 0) {
            toast('❌ No submissions found for this course');
            return;
        }

        if (!confirm(`Download ${courseSubmissions.length} submission(s) for ${course.courseName}?`)) {
            return;
        }

        showLoading(`Downloading ${courseSubmissions.length} submissions for ${course.courseName}...`);

        try {
            for (const sub of courseSubmissions) {
                await new Promise((resolve) => {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    form.target = '_blank';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'download_submission_zip';

                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'submission_id';
                    idInput.value = sub.id;

                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                    setTimeout(() => {
                        document.body.removeChild(form);
                        resolve();
                    }, 800);
                });
            }
            toast(`✅ ${courseSubmissions.length} submissions downloaded for ${course.courseName}`);
        } catch (error) {
            console.error('Download error:', error);
            toast('❌ Download failed: ' + error.message);
        } finally {
            setTimeout(() => hideLoading(), 2000);
        }
    }

    // Export course to Excel
    function exportCourseToExcel(courseCode) {
        const course = window.courseGroupsData[courseCode] || currentCourseData;
        if (!course) return;

        const exportData = course.submissions.map(sub => {
            const { classScore, examScore, totalScore } = getSubmissionScoreParts(sub);
            const gradeInfo = getGradeInfo(totalScore);

            return {
                'Student ID': sub.student_identifier || 'N/A',
                'Student Name': sub.student_name || 'Unknown',
                'Course Code': course.courseCode,
                'Exam Title': sub.exam_title || 'Unknown',
                'Class Score (max 40)': classScore,
                'Exam Score (max 60)': examScore,
                'Total Score (max 100)': totalScore,
                'Grade': gradeInfo.grade,
                'Grade Point': gradeInfo.gradePoint.toFixed(1),
                'Status': sub.status || 'SUBMITTED'
            };
        });

        const ws = XLSX.utils.json_to_sheet(exportData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, course.courseCode);

        XLSX.writeFile(wb,
            `${course.courseCode}_marks_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.xlsx`);
        toast(`📊 Exported ${exportData.length} student marks for ${course.courseName}`);
    }


    // Update the openQuestionReview function to include the IDE button
    function openFullIDE(submissionId, studentName, studentId) {
        const url =
            `IDEcompiler.php?submission_id=${submissionId}&student_name=${encodeURIComponent(studentName)}&student_id=${encodeURIComponent(studentId)}`;
        window.open(url, '_blank', 'width=1400,height=900,resizable=yes,scrollbars=yes');
    }



    // Results and score-sheet functions moved to web-client/js/lecturer-results.js

    // Student management functions moved to web-client/js/lecturer-students.js

    // ============================================
    // 8. SUBMISSIONS FUNCTIONS
    // ============================================

    function getSubs() {
        return readJSON(K_SUBS, []);
    }

    function setSubs(subs) {
        writeJSON(K_SUBS, subs);
    }

    async function loadSubmissions() {
        const data = await apiRequest('get_submissions');
        if (data.success && data.data) {
            renderSubmissionsFromDB(data.data);
        } else {
            renderSubmissions();
        }
    }


    // ============================================
    // COMPLETE GRADING SYSTEM - Exam 60% + Class 40%
    // ============================================

    let originalSubmissionsData = [];
    let studentClassScores = {};
    let examDetailsCache = {};

    function normalizeSubmissionStatus(status) {
        return String(status || '').toUpperCase();
    }

    function isGradedSubmission(status) {
        return ['GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED'].includes(normalizeSubmissionStatus(status));
    }

    function getSubmissionGrading(submission) {
        try {
            const answers = typeof submission.answers === 'string' ? JSON.parse(submission.answers || '{}') : (submission.answers || {});
            if (answers.grading || answers._grading) return answers.grading || answers._grading;
            if (answers._auto_grading) {
                return {
                    raw_question_score: answers._auto_grading.total_score,
                    exam_score: answers._auto_grading.exam_score_60,
                    total_score: answers._auto_grading.exam_score_60,
                    percentage: answers._auto_grading.percentage
                };
            }
            return {};
        } catch (error) {
            return {};
        }
    }

    async function persistClassScore(submission, classScore) {
        classScore = clampScore(classScore, 0, 40);
        const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(submission.percentage) || 0));
        const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
        const gradeInfo = getGradeInfo(totalScore);

        submission.status = 'GRADED';
        studentClassScores[`${submission.exam_id}:${submission.student_identifier}`] = classScore;
        studentClassScores[submission.student_identifier] = classScore;

        const result = await apiRequest('update_submission_grade', {
            submission_id: submission.id,
            status: 'GRADED',
            total_score: totalScore,
            class_score: classScore,
            exam_score: examScore,
            grade: gradeInfo.grade,
            grade_point: gradeInfo.gradePoint
        });

        if (result && result.success && result.grade) {
            submission.class_score = result.grade.class_score;
            submission.exam_score = result.grade.exam_score;
            submission.total_score = result.grade.total_score;
            submission.grade = result.grade.grade;
            submission.grade_point = result.grade.grade_point;
            submission.status = result.grade.status || 'GRADED';
        }

        return { examScore, totalScore, gradeInfo };
    }

    // Load submissions
    async function loadSubmissions() {
        console.log("Loading submissions...");

        const container = document.getElementById('submissionsContainer');
        if (!container) {
            console.error("Submissions container not found!");
            return;
        }

        container.innerHTML = `
        <div style="text-align:center; padding:60px;">
            <div class="spinner"></div>
            <p style="margin-top:10px;">Loading courses and submissions...</p>
        </div>
    `;

        try {
            const result = await apiRequest('get_submissions');
            console.log("Submissions API response:", result);

            if (result.success && result.data) {
                originalSubmissionsData = result.data;

                // Load exam details for each unique exam
                const uniqueExamIds = [...new Set(originalSubmissionsData.map(s => s.exam_id))];
                for (const examId of uniqueExamIds) {
                    if (!examDetailsCache[examId]) {
                        const examResult = await apiRequest('get_exam_details', {
                            exam_id: examId
                        });
                        if (examResult.success) {
                            examDetailsCache[examId] = examResult.data;
                        }
                    }
                }

                loadSavedClassScores();
                populateStudentSelects();

                if (result.data.length === 0) {
                    showEmptySubmissionsState();
                } else {
                    renderCourseCardsView(originalSubmissionsData);
                    const requestedView = sessionStorage.getItem('currentSubmissionView');
                    const requestedExamId = sessionStorage.getItem('currentSubmissionExamId');
                    const requestedCourseKey = sessionStorage.getItem('currentCourseCode');
                    const requestedInstanceKey = sessionStorage.getItem('currentExamInstanceKey');

                    if (requestedView === 'exam_instance') {
                        const groups = window.courseGroupsData || {};
                        let restored = false;
                        if (requestedCourseKey && requestedInstanceKey && groups[requestedCourseKey]?.instances?.[requestedInstanceKey]) {
                            showExamInstanceDetails(requestedCourseKey, requestedInstanceKey);
                            restored = true;
                        } else if (requestedExamId) {
                            for (const course of Object.values(groups)) {
                                const instance = Object.values(course.instances || {}).find(item => String(item.examId) === String(requestedExamId));
                                if (instance) {
                                    showExamInstanceDetails(course.courseKey, instance.instanceKey);
                                    restored = true;
                                    break;
                                }
                            }
                        }
                        if (!restored && currentExpandedCourse && window.courseGroupsData[currentExpandedCourse]) {
                            showCourseDetails(currentExpandedCourse);
                        }
                    } else if (currentExpandedCourse && window.courseGroupsData[currentExpandedCourse]) {
                        showCourseDetails(currentExpandedCourse);
                    }
                }
            } else {
                console.error("API Error:", result.error);
                showErrorSubmissionsState(result.error);
            }
        } catch (error) {
            console.error("Network Error:", error);
            showErrorSubmissionsState(error.message);
        }
    }

    function loadSavedClassScores() {
        studentClassScores = {};
        originalSubmissionsData.forEach(sub => {
            const grading = getSubmissionGrading(sub);
            if (sub.student_identifier && grading.class_score !== undefined) {
                studentClassScores[`${sub.exam_id}:${sub.student_identifier}`] = clampScore(grading.class_score, 0, 40);
                studentClassScores[sub.student_identifier] = clampScore(grading.class_score, 0, 40);
            }
        });

        const saved = localStorage.getItem('studentClassScores');
        if (saved) {
            const fallbackScores = JSON.parse(saved);
            Object.keys(fallbackScores).forEach(studentId => {
                if (studentClassScores[studentId] === undefined) {
                    studentClassScores[studentId] = clampScore(fallbackScores[studentId], 0, 40);
                }
            });
        }
    }

    function saveClassScores() {
        localStorage.setItem('studentClassScores', JSON.stringify(studentClassScores));
    }

    function loadGradedStatus() {
        const saved = localStorage.getItem('studentGradedStatus');
        if (saved) {
            const gradedStatus = JSON.parse(saved);
            originalSubmissionsData.forEach(sub => {
                if (gradedStatus[sub.id]) {
                    sub.status = 'GRADED';
                }
            });
        }
    }

    function saveGradedStatus() {
        const gradedStatus = {};
        originalSubmissionsData.forEach(sub => {
            if (sub.status === 'GRADED') {
                gradedStatus[sub.id] = true;
            }
        });
        localStorage.setItem('studentGradedStatus', JSON.stringify(gradedStatus));
    }

    function populateStudentSelects() {
        const select1 = document.getElementById('classScoreStudentSelect');
        const select2 = document.getElementById('calcStudentSelect');

        if (!select1 && !select2) return;

        const students = {};
        originalSubmissionsData.forEach(sub => {
            const studentId = sub.student_identifier;
            const studentName = sub.student_name;
            if (studentId && !students[studentId]) {
                const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
                students[studentId] = {
                    id: studentId,
                    name: studentName,
                    submissionId: sub.id,
                    examScore: examScore,
                    examId: sub.exam_id
                };
            }
        });

        const optionsHtml = '<option value="">-- Select Student --</option>' +
            Object.values(students).map(student => {
                const currentScore = studentClassScores[student.id] || 0;
                return `<option value="${student.id}" data-exam-score="${student.examScore}" data-exam-id="${student.examId}">
                ${escapeHTML(student.name)} (${student.id}) - Exam: ${student.examScore}/60 | Class: ${currentScore}/40
            </option>`;
            }).join('');

        if (select1) select1.innerHTML = optionsHtml;
        if (select2) select2.innerHTML = optionsHtml;
    }

    function convertTo60Scale(percentage) {
        return clampScore((percentage * 60) / 100, 0, 60);
    }

    function convertTo100Scale(exam60, class40) {
        return clampScore(clampScore(exam60 || 0, 0, 60) + clampScore(class40 || 0, 0, 40), 0, 100);
    }

    function getGradeInfo(totalScore) {
        // Ensure totalScore is an integer
        totalScore = roundToWholeNumber(totalScore);

        if (totalScore >= 80) return {
            grade: 'A',
            gradePoint: 4.0,
            class: 'grade-a'
        };
        if (totalScore >= 75) return {
            grade: 'B+',
            gradePoint: 3.5,
            class: 'grade-bplus'
        };
        if (totalScore >= 70) return {
            grade: 'B',
            gradePoint: 3.0,
            class: 'grade-b'
        };
        if (totalScore >= 65) return {
            grade: 'C+',
            gradePoint: 2.5,
            class: 'grade-cplus'
        };
        if (totalScore >= 60) return {
            grade: 'C',
            gradePoint: 2.0,
            class: 'grade-c'
        };
        if (totalScore >= 55) return {
            grade: 'D+',
            gradePoint: 1.5,
            class: 'grade-dplus'
        };
        if (totalScore >= 50) return {
            grade: 'D',
            gradePoint: 1.0,
            class: 'grade-d'
        };
        return {
            grade: 'E',
            gradePoint: 0.0,
            class: 'grade-e'
        };
    }

    async function updateClassScoreForStudent() {
        const select = document.getElementById('classScoreStudentSelect');
        const scoreInput = document.getElementById('classScoreInput');

        const studentId = select.value;
        let classScore = roundToWholeNumber(scoreInput.value);

        if (!studentId) {
            toast('❌ Please select a student');
            return;
        }

        if (classScore < 0) classScore = 0;
        if (classScore > 40) {
            toast('❌ Class score cannot exceed 40');
            return;
        }

        const submission = originalSubmissionsData.find(s => s.student_identifier === studentId);
        if (!submission) {
            toast('❌ Submission not found');
            return;
        }

        studentClassScores[studentId] = classScore;
        saveClassScores();

        const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(submission.percentage) || 0));
        const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
        const gradeInfo = getGradeInfo(totalScore);

        if (true) {
            submission.status = 'GRADED';
            saveGradedStatus();

            await apiRequest('update_submission_grade', {
                submission_id: submission.id,
                status: 'GRADED',
                total_score: totalScore,
                class_score: classScore,
                exam_score: examScore,
                grade: gradeInfo.grade,
                grade_point: gradeInfo.gradePoint
            });
        }

        renderGroupedSubmissions(originalSubmissionsData);
        displayMarksSummary();
        populateStudentSelects();

        toast(`✅ Class score ${classScore}/40 applied. Total: ${totalScore}/100 - Grade: ${gradeInfo.grade}`);

        scoreInput.value = 0;
        select.value = "";
    }

    function updateCalculatorDisplay() {
        const select = document.getElementById('calcStudentSelect');
        const selectedOption = select.options[select.selectedIndex];
        const examScoreField = document.getElementById('calcExamScoreField');
        const classScoreField = document.getElementById('calcClassScoreField');

        let examScore = roundToWholeNumber(examScoreField.value);
        let classScore = roundToWholeNumber(classScoreField.value);

        if (select.value && selectedOption && selectedOption.dataset.examScore) {
            const preExamScore = roundToWholeNumber(selectedOption.dataset.examScore);
            if (examScoreField.value === '0') {
                examScore = preExamScore;
                examScoreField.value = preExamScore;
            }
        }

        if (examScore < 0) examScore = 0;
        if (examScore > 60) examScore = 60;
        if (classScore < 0) classScore = 0;
        if (classScore > 40) classScore = 40;

        const totalScore = examScore + classScore;
        const gradeInfo = getGradeInfo(totalScore);

        document.getElementById('calcTotalScore').textContent = totalScore;
        document.getElementById('calcGradeDisplay').textContent = gradeInfo.grade;
        document.getElementById('calcGradePointDisplay').textContent =
            `Grade Point: ${gradeInfo.gradePoint.toFixed(1)}`;

        const gradeSpan = document.getElementById('calcGradeDisplay');
        if (gradeSpan) {
            if (totalScore >= 80) gradeSpan.style.color = '#10b981';
            else if (totalScore >= 70) gradeSpan.style.color = '#3b82f6';
            else if (totalScore >= 60) gradeSpan.style.color = '#f59e0b';
            else if (totalScore >= 50) gradeSpan.style.color = '#f97316';
            else gradeSpan.style.color = '#ef4444';
        }
    }

    async function markAllAsGraded() {
        if (!confirm('Mark all submissions as graded?')) return;

        let count = 0;
        for (const submission of originalSubmissionsData) {
            if (submission.status !== 'GRADED') {
                submission.status = 'GRADED';

                const examScore = convertTo60Scale(parseFloat(submission.percentage) || 0);
                const classScore = studentClassScores[submission.student_identifier] || 0;
                const totalScore = convertTo100Scale(examScore, classScore);
                const gradeInfo = getGradeInfo(totalScore);

                await apiRequest('update_submission_grade', {
                    submission_id: submission.id,
                    status: 'GRADED',
                    total_score: totalScore,
                    class_score: classScore,
                    exam_score: examScore,
                    grade: gradeInfo.grade,
                    grade_point: gradeInfo.gradePoint
                });
                count++;
            }
        }

        saveGradedStatus();
        renderGroupedSubmissions(originalSubmissionsData);
        displayMarksSummary();
        toast(`✅ ${count} submissions marked as graded`);
    }

    // Render submissions grouped by course with exam details
    function renderGroupedSubmissions(submissions) {
        const container = document.getElementById('submissionsContainer');
        if (!container) return;

        const groupedByCourse = {};
        submissions.forEach(sub => {
            const examId = sub.exam_id;
            const examDetail = examDetailsCache[examId];
            const courseKey = examDetail ? `${examDetail.course_code} - ${examDetail.title}` : (sub
                .course_code || sub.exam_title || 'Unknown Course');

            if (!groupedByCourse[courseKey]) {
                groupedByCourse[courseKey] = {
                    submissions: [],
                    examDetail: examDetail
                };
            }
            groupedByCourse[courseKey].submissions.push(sub);
        });

        let html = '';

        for (const [courseDisplay, groupData] of Object.entries(groupedByCourse)) {
            const courseSubmissions = groupData.submissions;
            const examDetail = groupData.examDetail;

            // Get exam details
            const schoolName = examDetail?.school_name || 'SCHOOL NAME';
            const programme = examDetail?.programme || examDetail?.department || 'PROGRAMME';
            const schoolType = examDetail?.school_type || 'REGULAR';
            const level = examDetail?.level || 'N/A';
            const semester = examDetail?.semester || 'SEMESTER';
            const academicYear = examDetail?.academic_year ||
                `${new Date().getFullYear()}/${new Date().getFullYear() + 1}`;
            const intake = examDetail?.intake || '1';
            const courseName = examDetail?.title || courseDisplay;
            const courseCode = examDetail?.course_code || 'N/A';

            let courseTotal = 0;
            let passedCount = 0;

            courseSubmissions.forEach(sub => {
                const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
                const classScore = studentClassScores[sub.student_identifier] || 0;
                const totalScore = convertTo100Scale(examScore, classScore);
                courseTotal += totalScore;
                if (totalScore >= 50) passedCount++;
            });

            const courseAverage = courseSubmissions.length > 0 ? (courseTotal / courseSubmissions.length).toFixed(1) :
                0;
            const passRate = courseSubmissions.length > 0 ? ((passedCount / courseSubmissions.length) * 100).toFixed(
                1) : 0;

            html += `
        <div class="course-group" style="margin-bottom: 30px; border: 1px solid var(--border); border-radius: 16px; overflow: hidden;">
            <!-- Course Header with Exam Details -->
            <div style="background: linear-gradient(135deg, #1e3a5f, #3b82f6); color: white; padding: 20px;">
                <div style="text-align: center; margin-bottom: 15px;">
                    <h1 style="margin: 0; font-size: 24px;">${escapeHTML(schoolName)}</h1>
                    <h2 style="margin: 10px 0; font-size: 20px;">SCORE SHEET</h2>
                    <p style="margin: 5px 0; font-size: 14px;">${escapeHTML(programme)} - ${escapeHTML(schoolType)}</p>
                    <p style="margin: 5px 0; font-size: 14px;">
                        Level: ${escapeHTML(level)} | Semester: ${escapeHTML(semester)} | Academic Year: ${escapeHTML(academicYear)} | Intake: ${escapeHTML(intake)}
                    </p>
                    <p style="margin: 5px 0; font-size: 14px; font-weight: bold;">
                        Course: ${escapeHTML(courseName)} (${escapeHTML(courseCode)})
                    </p>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 15px;">
                    <div>
                        <div style="font-size: 13px; opacity: 0.9;">
                            📊 ${courseSubmissions.length} student(s) | 
                            📈  Average: ${Math.round(courseAverage)}% |
                            ✅ Pass Rate: ${passRate}%
                        </div>
                    </div>
                    <button class="btn" onclick="downloadCourseSubmissionsByExam('${escapeHTML(courseCode).replace(/'/g, "\\'")}', '${escapeHTML(courseName).replace(/'/g, "\\'")}')" 
                        style="background: white; color: #3b82f6; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer;">
                        <i class="fas fa-download"></i> Download Course ZIP
                    </button>
                </div>
            </div>
            
            <!-- Submissions Table -->
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; border: 1px solid #000;">
                    <thead>
                        <tr style="background: #f0f0f0;">
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">S/N</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Student ID</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Student Name</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Class Score (40%)</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Exam Score (60%)</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Total Score (100%)</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Grade</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">GP</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Status</th>
                            <th style="border: 1px solid #000; padding: 12px; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

            let sn = 1;
            for (const sub of courseSubmissions) {
                const studentName = sub.student_name || 'Unknown Student';
                const studentId = sub.student_identifier || 'N/A';
                const status = sub.status || 'SUBMITTED';

                let examScoreRaw = parseFloat(sub.percentage) || 0;
                let examScore = convertTo60Scale(examScoreRaw);
                const classScore = studentClassScores[studentId] || 0;
                const totalScore = convertTo100Scale(examScore, classScore);
                const gradeInfo = getGradeInfo(totalScore);

                let statusClass = 'status-pending';
                let statusText = status;
                if (isGradedSubmission(status)) {
                    statusClass = 'status-published';
                    statusText = '✓ Graded';
                } else if (normalizeSubmissionStatus(status) === 'SUBMITTED') {
                    statusClass = 'status-pending';
                    statusText = '📝 Submitted';
                }

                const scoreColor = totalScore >= 70 ? '#10b981' : (totalScore >= 50 ? '#f59e0b' : '#ef4444');

                html += `
                <tr>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">${sn++}</td>
                    <td style="border: 1px solid #000; padding: 12px;">
                        <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px;">${escapeHTML(studentId)}</code>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px;">
                        <strong>${escapeHTML(studentName)}</strong>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                            <strong style="color: #f59e0b; font-size: 18px;">${classScore}</strong>
                            <button class="btn small" onclick="editClassScore('${studentId}', '${escapeHTML(studentName)}')" 
                                style="padding: 2px 8px; font-size: 11px;">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </div>
                    </td>
<td style="border: 1px solid #000; padding: 12px; text-align: center;">
    <strong style="font-size: 18px;">${examScore}</strong>
</td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <div style="font-weight: 700; font-size: 22px; color: ${scoreColor};">${totalScore}</div>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <span class="tag ${gradeInfo.class}" style="font-size: 18px; font-weight: 700; padding: 6px 12px;">${gradeInfo.grade}</span>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <strong>${gradeInfo.gradePoint.toFixed(1)}</strong>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center;">
                        <span class="tag ${statusClass}">${statusText}</span>
                    </td>
                    <td style="border: 1px solid #000; padding: 12px; text-align: center; white-space: nowrap;">
                        <button class="btn primary small" onclick="openQuestionReview(${sub.id})" 
                            style="padding: 6px 12px; margin: 2px;">
                            <i class="fas fa-eye"></i> Review & Mark
                        </button>
                        
                        
                        
                        <button class="btn success small" onclick="downloadSubmission(${sub.id})" 
                            style="padding: 6px 12px; margin: 2px;">
                            <i class="fas fa-download"></i> Download ZIP
                        </button>
                    </td>
                </tr>
            `;
            }

            html += `
                    </tbody>
                    <tfoot style="background: #f9f9f9;">
                        <tr>
                            <td colspan="9" style="border: 1px solid #000; padding: 8px; text-align: right;"><strong>Total Students:</strong></td>
                            <td style="border: 1px solid #000; padding: 8px; text-align: center;"><strong>${courseSubmissions.length}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        `;
        }

        container.innerHTML = html;
    }

    // Replace the existing openQuestionReview function with this:
    function openQuestionReview(submissionId) {
        // Open the IDE Compiler in a new window
        const url = `IDEcompiler.php?submission_id=${submissionId}`;
        window.open(url, '_blank', 'width=1400,height=900,resizable=yes,scrollbars=yes,toolbar=no,menubar=no');
    }
    // Update saveAllScores to update the exam score in the table
    async function saveAllScores() {
        showLoading('Saving all scores...');
        let saved = 0;
        let totalScore = 0;
        let totalMarks = 0;

        for (let i = 0; i < currentQuestions.length; i++) {
            const score = currentScores[i];
            const feedback = document.getElementById(`feedbackInput_${i}`)?.value || '';
            const maxMarks = currentQuestions[i].marks;

            totalScore += score;
            totalMarks += maxMarks;

            try {
                await apiRequest('save_question_score_simple', {
                    submission_id: currentReviewSubmission.submission_id,
                    question_number: i + 1,
                    score: score,
                    feedback: feedback
                });
                saved++;
            } catch (error) {
                console.error(`Error saving question ${i + 1}:`, error);
            }
        }

        // Calculate percentage (exam score as percentage)
        const examPercentage = totalMarks > 0 ? (totalScore / totalMarks) * 100 : 0;

        // Update the submission in the database with the exam score
        await apiRequest('update_submission_scores', {
            submission_id: currentReviewSubmission.submission_id,
            total_score: totalScore,
            percentage: examPercentage
        });

        // Reload submissions to update the table
        await loadSubmissions();

        toast(
            `✅ Saved ${saved} of ${currentQuestions.length} questions. Exam score: ${examPercentage.toFixed(1)}%`
        );
        hideLoading();
        closeQuestionReviewModal();
    }

    // Round to nearest whole number
    function roundToWholeNumber(value) {
        return Math.round(parseFloat(value) || 0);
    }

    // Update the updateScore function
    function updateScore(questionIndex, value) {
        const score = roundToWholeNumber(value);
        currentScores[questionIndex] = score;
        updateTotalScoreDisplay();

        // Update the input field to show rounded value
        const inputField = document.getElementById(`scoreInput_${questionIndex}`);
        if (inputField) {
            inputField.value = score;
        }

        // Update the sidebar display
        const sidebarItem = document.querySelectorAll('.question-list-item')[questionIndex];
        if (sidebarItem) {
            const scoreSpan = sidebarItem.querySelector('span:first-child');
            if (scoreSpan) {
                scoreSpan.textContent = score;
            }
        }
    }

    // Update updateClassScoreForStudent function
    async function updateClassScoreForStudent() {
        const select = document.getElementById('classScoreStudentSelect');
        const scoreInput = document.getElementById('classScoreInput');

        const studentId = select.value;
        let classScore = roundToWholeNumber(scoreInput.value);

        if (!studentId) {
            toast('❌ Please select a student');
            return;
        }

        if (classScore < 0) classScore = 0;
        if (classScore > 40) {
            toast('❌ Class score cannot exceed 40');
            return;
        }

        const submission = originalSubmissionsData.find(s => s.student_identifier === studentId);
        if (!submission) {
            toast('❌ Submission not found');
            return;
        }

        studentClassScores[studentId] = classScore;
        saveClassScores();

        const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(submission.percentage) || 0));
        const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
        const gradeInfo = getGradeInfo(totalScore);

        if (true) {
            submission.status = 'GRADED';
            saveGradedStatus();

            await apiRequest('update_submission_grade', {
                submission_id: submission.id,
                status: 'GRADED',
                total_score: totalScore,
                class_score: classScore,
                exam_score: examScore,
                grade: gradeInfo.grade,
                grade_point: gradeInfo.gradePoint
            });
        }

        renderGroupedSubmissions(originalSubmissionsData);
        displayMarksSummary();
        populateStudentSelects();

        toast(`✅ Class score ${classScore}/40 applied. Total: ${totalScore}/100 - Grade: ${gradeInfo.grade}`);

        scoreInput.value = 0;
        select.value = "";
    }









    async function editClassScore(studentId, studentName) {
        const currentScore = studentClassScores[studentId] || 0;
        const newScore = prompt(`Enter class score for ${studentName} (0-40):`, currentScore);

        if (newScore !== null) {
            let scoreNum = parseFloat(newScore);
            if (isNaN(scoreNum)) {
                toast('❌ Please enter a valid number');
                return;
            }
            if (scoreNum < 0) scoreNum = 0;
            if (scoreNum > 40) {
                toast('❌ Class score cannot exceed 40');
                return;
            }

            const submission = originalSubmissionsData.find(s => s.student_identifier === studentId);
            if (submission) {
                await persistClassScore(submission, scoreNum);
                saveClassScores();
            }

            renderGroupedSubmissions(originalSubmissionsData);
            displayMarksSummary();
            populateStudentSelects();
            toast(`✅ Class score updated to ${scoreNum}/40 for ${studentName}`);
        }
    }

    function filterSubmissions() {
        const studentSearch = document.getElementById('filterStudentSearch')?.value.toLowerCase() || '';
        const courseSearch = document.getElementById('filterCourseSearch')?.value.toLowerCase() || '';
        const statusFilter = document.getElementById('filterStatus')?.value || 'all';

        let filtered = [...originalSubmissionsData];

        if (studentSearch) {
            filtered = filtered.filter(sub =>
                (sub.student_name && sub.student_name.toLowerCase().includes(studentSearch)) ||
                (sub.student_identifier && sub.student_identifier.toLowerCase().includes(studentSearch))
            );
        }

        if (courseSearch) {
            filtered = filtered.filter(sub => {
                const examDetail = examDetailsCache[sub.exam_id];
                const courseCode = examDetail?.course_code || sub.course_code || '';
                const courseTitle = examDetail?.title || sub.exam_title || '';
                return courseCode.toLowerCase().includes(courseSearch) || courseTitle.toLowerCase().includes(
                    courseSearch);
            });
        }

        if (statusFilter !== 'all') {
            filtered = filtered.filter(sub => {
                const subStatus = sub.status || 'SUBMITTED';
                return subStatus === statusFilter;
            });
        }

        if (filtered.length === 0) {
            const container = document.getElementById('submissionsContainer');
            if (container) {
                container.innerHTML = `
                <div style="text-align: center; padding: 60px;">
                    <i class="fas fa-search" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                    <h3>No matching submissions</h3>
                    <p>Try adjusting your search filters</p>
                    <button class="btn" onclick="resetSubmissionsFilters()">Reset Filters</button>
                </div>
            `;
            }
        } else {
            renderGroupedSubmissions(filtered);
            displayMarksSummary();
        }
    }

    function resetSubmissionsFilters() {
        const studentSearch = document.getElementById('filterStudentSearch');
        const courseSearch = document.getElementById('filterCourseSearch');
        const statusFilter = document.getElementById('filterStatus');

        if (studentSearch) studentSearch.value = '';
        if (courseSearch) courseSearch.value = '';
        if (statusFilter) statusFilter.value = 'all';

        renderGroupedSubmissions(originalSubmissionsData);
        displayMarksSummary();
        populateStudentSelects();
        toast('Filters reset');
    }

    function refreshSubmissions() {
        loadSubmissions();
        toast('🔄 Refreshing submissions...');
    }

    function displayMarksSummary() {
        if (!originalSubmissionsData || originalSubmissionsData.length === 0) return;

        let totalScoreSum = 0;
        let passed = 0;
        let grades = {
            A: 0,
            Bplus: 0,
            B: 0,
            Cplus: 0,
            C: 0,
            Dplus: 0,
            D: 0,
            E: 0
        };

        originalSubmissionsData.forEach(sub => {
            const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = convertTo100Scale(examScore, classScore);
            totalScoreSum += totalScore;

            if (totalScore >= 50) passed++;

            const gradeInfo = getGradeInfo(totalScore);
            if (gradeInfo.grade === 'A') grades.A++;
            else if (gradeInfo.grade === 'B+') grades.Bplus++;
            else if (gradeInfo.grade === 'B') grades.B++;
            else if (gradeInfo.grade === 'C+') grades.Cplus++;
            else if (gradeInfo.grade === 'C') grades.C++;
            else if (gradeInfo.grade === 'D+') grades.Dplus++;
            else if (gradeInfo.grade === 'D') grades.D++;
            else grades.E++;
        });

        const studentCount = originalSubmissionsData.length;
        const average = studentCount > 0 ? Math.round(totalScoreSum / studentCount) : 0;
        const passRate = studentCount > 0 ? Math.round((passed / studentCount) * 100) : 0;
        let summaryDiv = document.querySelector('.marks-summary');
        if (!summaryDiv) {
            const container = document.getElementById('submissionsContainer');
            if (container && container.parentNode) {
                summaryDiv = document.createElement('div');
                summaryDiv.className = 'marks-summary';
                container.parentNode.insertBefore(summaryDiv, container);
            }
        }

        if (summaryDiv) {
            summaryDiv.innerHTML = `
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px;">
                <div class="stat-card blue" style="padding: 20px; border-radius: 16px;">
                    <div class="stat-label">Total Students</div>
                    <div class="stat-value" style="font-size: 32px;">${studentCount}</div>
                </div>
                <div class="stat-card green" style="padding: 20px; border-radius: 16px;">
                    <div class="stat-label">Passed (≥50)</div>
                    <div class="stat-value" style="font-size: 32px; color: #10b981;">${passed}</div>
                    <small>${passRate}% pass rate</small>
                </div>
                <div class="stat-card red" style="padding: 20px; border-radius: 16px;">
                    <div class="stat-label">Failed (<50)</div>
                    <div class="stat-value" style="font-size: 32px; color: #ef4444;">${studentCount - passed}</div>
                </div>
                <div class="stat-card orange" style="padding: 20px; border-radius: 16px;">
                    <div class="stat-label">Average Score</div>
                    <div class="stat-value" style="font-size: 32px;">${average}</div>
                </div>
            </div>
            
            <div style="background: var(--panel); border-radius: 16px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--border);">
                <h4 style="margin: 0 0 15px 0;"><i class="fas fa-chart-pie"></i> Grade Distribution</h4>
                <div style="display: flex; gap: 15px; flex-wrap: wrap; justify-content: space-between;">
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-a" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">A</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.A}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-bplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">B+</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Bplus}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-b" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">B</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.B}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-cplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">C+</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Cplus}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-c" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">C</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.C}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-dplus" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">D+</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.Dplus}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-d" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">D</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.D}</div>
                    </div>
                    <div style="text-align: center; min-width: 70px; padding: 10px; background: var(--bg); border-radius: 12px;">
                        <div class="tag grade-e" style="font-size: 20px; font-weight: 700; padding: 8px 16px;">E</div>
                        <div style="font-size: 24px; font-weight: 700; margin-top: 8px;">${grades.E}</div>
                    </div>
                </div>
            </div>
        `;
        }
    }

    // Calculator functions
    function openCalculatorModal() {
        populateStudentSelects();
        const modal = document.getElementById('calculatorModal');
        if (modal) {
            modal.style.display = 'flex';
            updateCalculatorDisplay();
        }
    }

    function closeCalculatorModal() {
        const modal = document.getElementById('calculatorModal');
        if (modal) modal.style.display = 'none';
    }

    function updateCalculatorDisplay() {
        const select = document.getElementById('calcStudentSelect');
        const selectedOption = select.options[select.selectedIndex];
        const examScoreField = document.getElementById('calcExamScoreField');
        const classScoreField = document.getElementById('calcClassScoreField');

        let examScore = roundToWholeNumber(examScoreField.value);
        let classScore = roundToWholeNumber(classScoreField.value);

        if (select.value && selectedOption && selectedOption.dataset.examScore) {
            const preExamScore = roundToWholeNumber(selectedOption.dataset.examScore);
            if (examScoreField.value === '0' || examScoreField.value === '') {
                examScore = preExamScore;
                examScoreField.value = preExamScore;
            }
        }

        if (examScore < 0) examScore = 0;
        if (examScore > 60) examScore = 60;
        if (classScore < 0) classScore = 0;
        if (classScore > 40) classScore = 40;

        const totalScore = examScore + classScore;
        const gradeInfo = getGradeInfo(totalScore);

        document.getElementById('calcTotalScore').textContent = totalScore; // Removed .toFixed(1)
        document.getElementById('calcGradeDisplay').textContent = gradeInfo.grade;
        document.getElementById('calcGradePointDisplay').textContent =
            `Grade Point: ${gradeInfo.gradePoint.toFixed(1)}`;

        const gradeSpan = document.getElementById('calcGradeDisplay');
        if (gradeSpan) {
            if (totalScore >= 80) gradeSpan.style.color = '#10b981';
            else if (totalScore >= 70) gradeSpan.style.color = '#3b82f6';
            else if (totalScore >= 60) gradeSpan.style.color = '#f59e0b';
            else if (totalScore >= 50) gradeSpan.style.color = '#f97316';
            else gradeSpan.style.color = '#ef4444';
        }
    }






    // Execute code in the tester modal
    function executeCode() {
        const code = document.getElementById('testCodeEditor').value;
        const language = document.getElementById('currentLanguage').innerText;
        const outputDiv = document.getElementById('testOutput');
        const resultsDiv = document.getElementById('testResultsList');

        outputDiv.innerHTML =
            '<div style="color: #fbbf24;"><i class="fas fa-spinner fa-spin"></i> Executing code...</div>';

        // Send to backend for execution
        fetch('/api/execute_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    code: code,
                    language: language
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    outputDiv.innerHTML =
                        `<pre style="margin: 0; color: #d4d4d4;">${escapeHTML(data.output)}</pre>`;
                    if (data.testResults) {
                        displayTestResults(data.testResults);
                    }
                } else {
                    outputDiv.innerHTML =
                        `<pre style="margin: 0; color: #ef4444;">Error: ${escapeHTML(data.error)}</pre>`;
                }
            })
            .catch(error => {
                outputDiv.innerHTML =
                    `<pre style="margin: 0; color: #ef4444;">Execution failed: ${escapeHTML(error.message)}</pre>`;
            });
    }



    // Print Score Sheet Functions
    function openPrintModal() {
        const select = document.getElementById('printCourseSelect');
        if (select) {
            const courses = [];
            for (const sub of originalSubmissionsData) {
                const examDetail = examDetailsCache[sub.exam_id];
                if (examDetail && !courses.find(c => c.code === examDetail.course_code)) {
                    courses.push({
                        code: examDetail.course_code,
                        name: examDetail.title,
                        examId: sub.exam_id
                    });
                }
            }
            select.innerHTML = '<option value="">-- Select Course --</option>' +
                courses.map(course => `<option value="${course.examId}" data-course-code="${escapeHTML(course.code)}" data-course-name="${escapeHTML(course.name)}">
                ${escapeHTML(course.code)} - ${escapeHTML(course.name)}
            </option>`).join('');
        }
        const modal = document.getElementById('printModal');
        if (modal) modal.style.display = 'flex';
    }

    function closePrintModal() {
        const modal = document.getElementById('printModal');
        if (modal) modal.style.display = 'none';
    }

    function closePrintPreviewModal() {
        const modal = document.getElementById('printPreviewModal');
        if (modal) modal.style.display = 'none';
    }

    function generatePrintPreview() {
        const select = document.getElementById('printCourseSelect');
        const examId = select.value;
        const courseCode = select.options[select.selectedIndex]?.dataset.courseCode || '';
        const courseName = select.options[select.selectedIndex]?.dataset.courseName || '';

        if (!examId) {
            toast('❌ Please select a course');
            return;
        }

        const examDetail = examDetailsCache[examId];
        const courseSubmissions = originalSubmissionsData.filter(sub => sub.exam_id == examId);

        if (courseSubmissions.length === 0) {
            toast('❌ No submissions found for this course');
            return;
        }

        const schoolName = examDetail?.school_name || 'SCHOOL NAME';
        const programme = examDetail?.programme || examDetail?.department || 'PROGRAMME';
        const schoolType = examDetail?.school_type || 'REGULAR';
        const level = examDetail?.level || 'N/A';
        const semester = examDetail?.semester || 'SEMESTER';
        const academicYear = examDetail?.academic_year || `${new Date().getFullYear()}/${new Date().getFullYear() + 1}`;
        const intake = examDetail?.intake || '1';

        let tableRows = '';
        let sn = 1;
        courseSubmissions.forEach(sub => {
            const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = convertTo100Scale(examScore, classScore);
            const gradeInfo = getGradeInfo(totalScore);

            tableRows += `
            <tr style="border: 1px solid #000;">
                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${sn++}</td>
                <td style="border: 1px solid #000; padding: 8px;">${escapeHTML(sub.student_identifier || 'N/A')}</td>
                <td style="border: 1px solid #000; padding: 8px;">${escapeHTML(sub.student_name || 'Unknown')}</td>
                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${classScore}</td>
                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${examScore}</td>
                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${totalScore}</td>
                <td style="border: 1px sodeInfo.grade}</td>
            </tr>
        `;
        });

        const printHtml = `
        <div id="scoreSheetPrint" style="font-family: Arial, sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h1 style="margin: 0; font-size: 28px;">${escapeHTML(schoolName)}</h1>
                <h2 style="margin: 10px 0; font-size: 24px;">SCORE SHEET</h2>
                <p style="margin: 5px 0; font-size: 14px;">${escapeHTML(programme)} - ${escapeHTML(schoolType)}</p>
                <p style="margin: 5px 0; font-size: 14px;">
                    Level: ${escapeHTML(level)} | Semester: ${escapeHTML(semester)} | Academic Year: ${escapeHTML(academicYear)} | Intake: ${escapeHTML(intake)}
                </p>
                <p style="margin: 5px 0; font-size: 14px; font-weight: bold;">
                    Course: ${escapeHTML(courseName)} (${escapeHTML(courseCode)})
                </p>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; border: 1px solid #000;">
                <thead>
                    <tr style="background: #f0f0f0;">
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">S/N</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Student ID</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Student Name</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Class Score (40%)</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Exam Score (60%)</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Total Score (100%)</th>
                        <th style="border: 1px solid #000; padding: 10px; text-align: center;">Grade</th>
                    </tr>
                </thead>
                <tbody>
                    ${tableRows}
                </tbody>
                <tfoot>
                    <tr style="background: #f9f9f9;">
                        <td colspan="6" style="border: 1px solid #000; padding: 8px; text-align: right;"><strong>Total Students:</strong></td>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center;"><strong>${courseSubmissions.length}</strong></td>
                    </tr>
                </tfoot>
            </table>
            
            <div style="margin-top: 40px;">
                <div style="display: flex; justify-content: space-between; margin-top: 30px;">
                    <div style="text-align: center;">
                        <p>_____________________</p>
                        <p>Lecturer's Signature</p>
                        <p>Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                    <div style="text-align: center;">
                        <p>_____________________</p>
                        <p>Head of Department</p>
                        <p>Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                    <div style="text-align: center;">
                        <p>_____________________</p>
                        <p>Dean's Signature</p>
                        <p>Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                </div>
            </div>
        </div>
    `;

        const previewContent = document.getElementById('printPreviewContent');
        if (previewContent) previewContent.innerHTML = printHtml;

        closePrintModal();
        const previewModal = document.getElementById('printPreviewModal');
        if (previewModal) previewModal.style.display = 'flex';
    }

    function printScoreSheet() {
        const printContent = document.getElementById('scoreSheetPrint');
        if (!printContent) return;

        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
        <html>
            <head>
                <title>Score Sheet</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #000; padding: 8px; text-align: center; }
                    th { background: #f0f0f0; }
                </style>
            </head>
            <body>
                ${printContent.outerHTML}
                <script>window.onload = function() { window.print(); window.close(); }<\/script>
            </body>
        </html>
    `);
        printWindow.document.close();
    }

    function exportMarksToCSV() {
        if (!originalSubmissionsData || originalSubmissionsData.length === 0) {
            toast('❌ No submissions to export');
            return;
        }

        const exportData = originalSubmissionsData.map(sub => {
            const examScore = convertTo60Scale(parseFloat(sub.percentage) || 0);
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = convertTo100Scale(examScore, classScore);
            const gradeInfo = getGradeInfo(totalScore);

            return {
                'Student ID': sub.student_identifier || 'N/A',
                'Student Name': sub.student_name || 'Unknown',
                'Course Code': sub.course_code || 'N/A',
                'Exam Title': sub.exam_title || 'Unknown',
                'Class Score (max 40)': classScore,
                'Exam Score (max 60)': examScore,
                'Total Score (max 100)': totalScore,
                'Grade': gradeInfo.grade,
                'Grade Point': gradeInfo.gradePoint.toFixed(1),
                'Status': sub.status || 'SUBMITTED'
            };
        });

        const ws = XLSX.utils.json_to_sheet(exportData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Marks_Sheet');

        const currentDate = new Date();
        const fileName = `marks_sheet_${currentDate.toISOString().slice(0, 19).replace(/:/g, '-')}.xlsx`;
        XLSX.writeFile(wb, fileName);

        toast(`📊 Exported ${exportData.length} student marks`);
    }



    async function downloadCourseSubmissionsByExam(courseCode, courseName) {
        const courseSubmissions = originalSubmissionsData.filter(sub => sub.course_code === courseCode);

        if (!courseSubmissions || courseSubmissions.length === 0) {
            toast('❌ No submissions found for this course');
            return;
        }

        // Confirm before downloading
        if (!confirm(`Download ${courseSubmissions.length} submission(s) for ${courseName}?`)) {
            return;
        }

        showLoading(`Downloading ${courseSubmissions.length} submissions for ${courseName}...`);

        try {
            for (let i = 0; i < courseSubmissions.length; i++) {
                const sub = courseSubmissions[i];
                // Add delay between downloads to prevent browser blocking
                await new Promise((resolve) => {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    form.target = '_blank';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'download_submission_zip';

                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'submission_id';
                    idInput.value = sub.id;

                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                    setTimeout(() => {
                        document.body.removeChild(form);
                        resolve();
                    }, 800);
                });
            }
            toast(`✅ ${courseSubmissions.length} submissions downloaded for ${courseName}`);
        } catch (error) {
            console.error('Download error:', error);
            toast('❌ Download failed: ' + error.message);
        } finally {
            setTimeout(() => hideLoading(), 2000);
        }
    }


    // Add this to your JavaScript section (around line where other download functions are)

    async function downloadFullCourseSubmissions() {
        // Get unique courses from submissions
        const courses = {};
        originalSubmissionsData.forEach(sub => {
            const examDetail = examDetailsCache[sub.exam_id];
            const courseKey = examDetail ? examDetail.course_code : sub.course_code;
            const courseName = examDetail ? (examDetail.course_name || examDetail.title) : (sub.course_name || sub.exam_title);
            if (courseKey && !courses[courseKey]) {
                courses[courseKey] = {
                    code: courseKey,
                    name: courseName,
                    submissions: []
                };
            }
            if (courseKey && courses[courseKey]) {
                courses[courseKey].submissions.push(sub);
            }
        });

        if (Object.keys(courses).length === 0) {
            toast('❌ No courses with submissions found');
            return;
        }

        // Create course selection modal
        const modalHtml = `
        <div id="courseSelectModal" class="modal" style="display: flex;">
            <div class="modal-content" style="width: 500px; max-width: 90%;">
                <h3><i class="fas fa-folder-download"></i> Select Course to Download</h3>
                <div class="field">
                    <label>Choose Course:</label>
                    <select id="courseSelect" style="width: 100%; padding: 12px; border-radius: 10px; border: 2px solid var(--border);">
                        <option value="">-- Select a course --</option>
                        ${Object.values(courses).map(course => 
                            `<option value="${escapeHTML(course.code)}">${escapeHTML(course.code)} - ${escapeHTML(course.name)} (${course.submissions.length} submissions)</option>`
                        ).join('')}
                    </select>
                </div>
                <div class="modal-actions" style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn" onclick="closeCourseSelectModal()">Cancel</button>
                    <button class="btn primary" onclick="downloadSelectedCourse()">Download ZIP</button>
                </div>
            </div>
        </div>
    `;

        // Remove existing modal if any
        const existingModal = document.getElementById('courseSelectModal');
        if (existingModal) existingModal.remove();

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // Store courses globally for access
        window.selectedCoursesData = courses;
    }

    function closeCourseSelectModal() {
        const modal = document.getElementById('courseSelectModal');
        if (modal) modal.remove();
    }

    async function downloadSelectedCourse() {
        const select = document.getElementById('courseSelect');
        const selectedCode = select.value;

        if (!selectedCode) {
            toast('❌ Please select a course');
            return;
        }

        const course = window.selectedCoursesData[selectedCode];
        if (!course || course.submissions.length === 0) {
            toast('❌ No submissions found for this course');
            closeCourseSelectModal();
            return;
        }

        closeCourseSelectModal();

        showLoading(`Downloading ${course.submissions.length} submissions for ${course.code}...`);

        try {
            for (const sub of course.submissions) {
                await new Promise((resolve) => {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    form.target = '_blank';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'download_submission_zip';

                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'submission_id';
                    idInput.value = sub.id;

                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                    setTimeout(() => {
                        document.body.removeChild(form);
                        resolve();
                    }, 800);
                });
            }
            toast(`✅ ${course.submissions.length} submissions downloaded for ${course.code}`);
        } catch (error) {
            console.error('Download error:', error);
            toast('❌ Download failed: ' + error.message);
        } finally {
            setTimeout(() => hideLoading(), 2000);
        }
    }




    function showEmptySubmissionsState() {
        const container = document.getElementById('submissionsContainer');
        if (container) {
            container.innerHTML = `
            <div style="text-align:center; padding:60px;">
                <div style="font-size:48px; margin-bottom:15px;">📭</div>
                <h3>No Submissions Yet</h3>
                <p style="color:var(--muted);">When students submit exams, they will appear here.</p>
            </div>
        `;
        }
    }

    function showErrorSubmissionsState(errorMsg) {
        const container = document.getElementById('submissionsContainer');
        if (container) {
            container.innerHTML = `
            <div style="text-align:center; padding:60px;">
                <div style="font-size:48px; margin-bottom:15px;">❌</div>
                <h3>Error Loading Submissions</h3>
                <p style="color:var(--muted);">${escapeHTML(errorMsg || 'Unknown error')}</p>
                <button class="btn primary" onclick="loadSubmissions()" style="margin-top:15px;">
                    <i class="fas fa-sync-alt"></i> Try Again
                </button>
            </div>
        `;
        }
    }





    // ============================================
    // 9. MARKING FUNCTIONS
    // ============================================

    function renderMarking() {
        const subs = getSubs();
        const exams = getExams();
        const container = document.getElementById('markingList');
        if (!container) return;

        if (subs.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:40px;">📭 No submissions to mark.</div>';
            return;
        }

        container.innerHTML = subs.map(s => {
            const ex = exams.find(e => e.id === s.examId);
            const pendingItems = s.items?.filter(it => it.manualScore === undefined) || [];
            const {
                grade,
                class: gradeClass
            } = calculateGrade(s.scoreTotal || s.scoreAuto || 0, s.totalMarks || 1);
            return `<div class="qcard"><div class="qhead"><div><div class="qtitle">${escapeHTML(ex?.title || s.examId)}</div><div class="qmeta">Student: ${escapeHTML(s.studentId)} | Submitted: ${new Date(s.submittedAtISO).toLocaleString()}</div></div><div><span class="tag ${s.status === 'MARKED' ? 'status-published' : ''}">${s.status || 'PENDING'}</span></div></div><div style="margin:10px 0;"><p><strong>Auto Score:</strong> ${s.scoreAuto || 0} / ${s.totalMarks}</p><p><strong>Manual Score:</strong> ${s.scoreManual || 0} / ${s.totalMarks}</p><p><strong>Total:</strong> ${(s.scoreTotal || s.scoreAuto || 0)} / ${s.totalMarks}</p><p><strong>Grade:</strong> <span class="tag ${gradeClass}">${grade}</span></p><p><strong>Pending Items:</strong> ${pendingItems.length}</p></div><div style="display:flex; gap:8px;"><button class="btn primary" onclick="openMarkingModal('${s.id}')">✏️ Mark Manually</button><button class="btn ok" onclick="publishSubmission('${s.id}')">🚀 Publish</button></div></div>`;
        }).join('');
    }

    function openManualMarking() {
        const subs = getSubs().filter(s => s.status !== 'MARKED');
        if (subs.length === 0) {
            toast('✅ All submissions are marked');
            return;
        }
        openMarkingModal(subs[0].id);
    }

    function closeMarkingModal() {
        const modal = document.getElementById('markingModal');
        if (modal) modal.style.display = 'none';
    }

    function closePreviewModal() {
        const modal = document.getElementById('previewModal');
        if (modal) modal.style.display = 'none';
    }

    async function autoGradeSubmission(submissionId) {
        toast('🤖 Auto-grading in progress...');
        try {
            const result = await apiRequest('auto_grade_submission', {
                submission_id: submissionId
            });
            if (result.success) {
                toast(
                    `✅ Auto-graded! Score: ${result.total_score}/${result.total_marks || '?'} (${result.percentage}%)`
                );
                loadSubmissions();
            } else {
                toast('❌ ' + (result.error || 'Auto-grading failed'));
            }
        } catch (error) {
            toast('❌ Network error. Please try again.');
        }
    }

    // ============================================
    // 10. RESULTS FUNCTIONS
    // ============================================

    function calculateGrade(score, total) {
        const percentage = (score / total) * 100;
        if (percentage >= 80) return {
            grade: 'A',
            class: 'grade-a'
        };
        if (percentage >= 75) return {
            grade: 'B+',
            class: 'grade-bplus'
        };
        if (percentage >= 70) return {
            grade: 'B',
            class: 'grade-b'
        };
        if (percentage >= 65) return {
            grade: 'C+',
            class: 'grade-cplus'
        };
        if (percentage >= 60) return {
            grade: 'C',
            class: 'grade-c'
        };
        if (percentage >= 55) return {
            grade: 'D+',
            class: 'grade-dplus'
        };
        if (percentage >= 50) return {
            grade: 'D',
            class: 'grade-d'
        };
        return {
            grade: 'E',
            class: 'grade-e'
        };
    }

    function renderResults() {
        const subs = getSubs();
        const exams = getExams();
        const students = getStudents();
        const scores = subs.map(s => s.scoreTotal || s.scoreAuto || 0);
        const mean = scores.reduce((a, b) => a + b, 0) / (scores.length || 1);
        const sorted = [...scores].sort((a, b) => a - b);
        const median = sorted[Math.floor(sorted.length / 2)] || 0;
        const variance = scores.reduce((a, b) => a + Math.pow(b - mean, 2), 0) / (scores.length || 1);
        const stdDev = Math.sqrt(variance).toFixed(2);
        const passCount = scores.filter(s => s >= 50).length;
        const passRate = ((passCount / (scores.length || 1)) * 100).toFixed(1);

        const meanScoreEl = document.getElementById('meanScore');
        const medianScoreEl = document.getElementById('medianScore');
        const stdDevEl = document.getElementById('stdDev');
        const passRateEl = document.getElementById('passRate');

        if (meanScoreEl) meanScoreEl.textContent = mean.toFixed(2);
        if (medianScoreEl) medianScoreEl.textContent = median.toFixed(2);
        if (stdDevEl) stdDevEl.textContent = stdDev;
        if (passRateEl) passRateEl.textContent = passRate + '%';

        updateLineChart(scores);
        updateBellCurve(scores);
        updateCorrelationChart(scores);
        updateRegressionChart(scores);

        const rows = subs.map((s, index) => {
            const ex = exams.find(e => e.id === s.examId);
            const student = students.find(st => st.id === s.studentId);
            const total = s.totalMarks || 0;
            const score = (s.scoreTotal ?? s.scoreAuto ?? 0);
            const {
                grade,
                class: gradeClass
            } = calculateGrade(score, total);
            const statusClass = s.status === 'MARKED' ? 'status-published' : '';
            return `<tr><td>${index + 1}</td><td><span class="tag">${escapeHTML(s.studentId)}</span><br><small>${escapeHTML(student?.fullName || '')}</small></td><td>${escapeHTML(student?.level || '—')}</td><td>${escapeHTML(ex?.courseCode || '')}</td><td>2025</td><td>Semester 2</span></td><td><input type="number" id="classScore_${s.id}" value="0" min="0" max="100" style="width:70px;" onchange="updateClassScore('${s.id}', this.value)"></td><td><b>${score.toFixed(1)}</b> / ${total}</td><td><b>${(score + (parseFloat(document.getElementById('classScore_' + s.id)?.value || 0))).toFixed(1)}</b> / ${total + 100}</span></td><td><span class="tag ${gradeClass}">${grade}</span></td><td><span class="tag ${statusClass}">${s.published ? 'PUBLISHED' : s.status}</span></td></tr>`;
        }).join('');

        const resultsTable = document.getElementById("resultsTable");
        if (resultsTable) {
            resultsTable.innerHTML =
                `<thead><tr><th>S/N</th><th>Student</th><th>Level</th><th>Course</th><th>Year</th><th>Semester</th><th>Class Score</th><th>Exam Score</th><th>Total</th><th>Grade</th><th>Status</th></tr></thead><tbody>${rows || '<tr><td colspan="11" style="text-align:center">📭 No results yet. </td></tr>'}</tbody>`;
        }
    }

    function calculateGrades() {
        renderResults();
        toast('📊 Grades calculated using PU grading system');
    }

    function publishAllResults() {
        const subs = getSubs();
        subs.forEach(s => {
            s.published = true;
            s.publishedAt = new Date().toISOString();
        });
        setSubs(subs);
        toast("🚀 All results published");
        renderResults();
    }


    async function exportResults(format) {
        const examId = document.getElementById('exportExamSelect')?.value;
        if (!examId) {
            toast('❌ Please select an exam to export');
            return;
        }

        const result = await apiRequest('get_exam_for_export', {
            exam_id: examId
        });
        if (!result.success) {
            toast('❌ Failed to load exam data');
            return;
        }

        const exam = result.data;
        const submissions = result.submissions || [];

        // Prepare export data with strict template
        const exportData = submissions.map(sub => ({
            'SCHOOL NAME': exam.school_name?.toUpperCase() || '',
            'FACULTY': exam.faculty_name?.toUpperCase() || '',
            'EXAM TYPE': exam.exam_type?.toUpperCase() || '',
            'COURSE NAME': exam.title?.toUpperCase() || '',
            'COURSE CODE': exam.course_code?.toUpperCase() || '',
            'LEVEL': exam.level?.toUpperCase() || '',
            'SCHOOL TYPE': exam.school_type?.toUpperCase() || '',
            'STUDENT ID': sub.student_identifier || '',
            'STUDENT NAME': sub.student_name || '',
            'SCORE': sub.percentage || sub.total_score || '',
            'GRADE': calculateGradeLetter(sub.percentage || sub.total_score || 0),
            'STATUS': sub.status || 'PENDING'
        }));

        if (format === 'excel' || format === 'csv') {
            const ws = XLSX.utils.json_to_sheet(exportData);
            // Center all cells
            const range = XLSX.utils.decode_range(ws['!ref'] || 'A1:A1');
            for (let R = range.s.r; R <= range.e.r; ++R) {
                for (let C = range.s.c; C <= range.e.c; ++C) {
                    const cellAddress = XLSX.utils.encode_cell({
                        r: R,
                        c: C
                    });
                    if (!ws[cellAddress]) continue;
                    ws[cellAddress].s = {
                        alignment: {
                            horizontal: 'center',
                            vertical: 'center'
                        }
                    };
                }
            }
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Exam_Results');
            const fileName = format === 'csv' ? 'exam_results.csv' : 'exam_results.xlsx';
            XLSX.writeFile(wb, fileName);
        } else if (format === 'pdf') {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'landscape'
            });

            // Add header with school info
            doc.setFontSize(16);
            doc.text(exam.school_name?.toUpperCase() || '', 148, 15, {
                align: 'center'
            });
            doc.setFontSize(12);
            doc.text(exam.faculty_name?.toUpperCase() || '', 148, 22, {
                align: 'center'
            });
            doc.text(`${exam.exam_type?.toUpperCase() || ''} - ${exam.title?.toUpperCase() || ''}`, 148, 29, {
                align: 'center'
            });

            // Add table
            doc.autoTable({
                head: [Object.keys(exportData[0] || {})],
                body: exportData.map(row => Object.values(row)),
                startY: 35,
                theme: 'grid',
                styles: {
                    halign: 'center',
                    cellPadding: 3,
                    fontSize: 8
                },
                headStyles: {
                    fillColor: [59, 130, 246],
                    textColor: [255, 255, 255],
                    fontStyle: 'bold'
                }
            });

            doc.save('exam_results.pdf');
        }

        toast(`📊 Results exported as ${format.toUpperCase()}`);
    }

    function calculateGradeLetter(percentage) {
        if (percentage >= 80) return 'A';
        if (percentage >= 75) return 'B+';
        if (percentage >= 70) return 'B';
        if (percentage >= 65) return 'C+';
        if (percentage >= 60) return 'C';
        if (percentage >= 55) return 'D+';
        if (percentage >= 50) return 'D';
        return 'E';
    }

    function exportResults(format) {
        const subs = getSubs();
        const exams = getExams();
        const students = getStudents();
        const data = subs.map((s, index) => {
            const ex = exams.find(e => e.id === s.examId);
            const student = students.find(st => st.id === s.studentId);
            const score = s.scoreTotal || s.scoreAuto || 0;
            const total = s.totalMarks || 0;
            const {
                grade
            } = calculateGrade(score, total);
            return {
                'S/N': index + 1,
                'Student ID': s.studentId,
                'Full Name': student?.fullName || '',
                'Level': student?.level || '',
                'Course': ex?.courseCode || '',
                'Academic Year': '2025',
                'Semester': '2',
                'Class Score': 0,
                'Exam Score': score.toFixed(1),
                'Total Score': score.toFixed(1),
                'Grade': grade,
                'Status': s.published ? 'Published' : s.status
            };
        });

        if (format === 'excel') {
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Results");
            XLSX.writeFile(wb, "exam_results.xlsx");
        } else if (format === 'csv') {
            const ws = XLSX.utils.json_to_sheet(data);
            const csv = XLSX.utils.sheet_to_csv(ws);
            const blob = new Blob([csv], {
                type: 'text/csv'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'exam_results.csv';
            a.click();
        } else if (format === 'pdf') {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF();
            doc.text('Exam Results', 20, 20);
            doc.autoTable({
                html: '#resultsTable'
            });
            doc.save('exam_results.pdf');
        }
        toast(`📊 Results exported as ${format.toUpperCase()}`);
    }

    function printResults() {
        window.print();
    }

    function updateLineChart(scores) {
        const ctx = document.getElementById('lineChart')?.getContext('2d');
        if (!ctx) return;
        if (lineChart) lineChart.destroy();

        lineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Exam 1', 'Exam 2', 'Exam 3', 'Exam 4', 'Exam 5'],
                datasets: [{
                    label: 'Average Score',
                    data: [72, 75, 78, 82, 85],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Performance Trend'
                    }
                }
            }
        });
    }

    function updateBellCurve(scores) {
        const ctx = document.getElementById('bellCurve')?.getContext('2d');
        if (!ctx) return;
        if (bellCurve) bellCurve.destroy();

        const bins = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        scores.forEach(score => {
            const binIndex = Math.floor(score / 10);
            if (binIndex >= 0 && binIndex < 10) bins[binIndex]++;
        });

        bellCurve = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['0-10', '11-20', '21-30', '31-40', '41-50', '51-60', '61-70', '71-80', '81-90',
                    '91-100'
                ],
                datasets: [{
                    label: 'Grade Distribution',
                    data: bins,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139,92,246,0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#8b5cf6'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Grade Distribution (Bell Curve)'
                    }
                }
            }
        });
    }

    function updateCorrelationChart(scores) {
        const ctx = document.getElementById('correlationChart2')?.getContext('2d');
        if (!ctx) return;
        if (correlationChart) correlationChart.destroy();

        const studyTime = scores.map((_, i) => i * 2);
        correlationChart = new Chart(ctx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Study Time vs Score',
                    data: studyTime.map((time, i) => ({
                        x: time,
                        y: scores[i]
                    })),
                    backgroundColor: 'rgba(59,130,246,0.6)'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Study Time vs Performance Correlation'
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Study Time (hours)'
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Score'
                        }
                    }
                }
            }
        });
    }

    function updateRegressionChart(scores) {
        const ctx = document.getElementById('regressionChart')?.getContext('2d');
        if (!ctx) return;
        if (regressionChart) regressionChart.destroy();

        const x = Array.from({
            length: scores.length
        }, (_, i) => i);
        const meanX = x.reduce((a, b) => a + b, 0) / x.length;
        const meanY = scores.reduce((a, b) => a + b, 0) / scores.length;
        const slope = x.reduce((sum, xi, i) => sum + (xi - meanX) * (scores[i] - meanY), 0) / x.reduce((sum, xi) =>
            sum + Math.pow(xi - meanX, 2), 0);
        const intercept = meanY - slope * meanX;
        const regressionLine = x.map(xi => slope * xi + intercept);

        regressionChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: Array.from({
                    length: scores.length
                }, (_, i) => `Student ${i + 1}`),
                datasets: [{
                    label: 'Actual Scores',
                    data: scores,
                    borderColor: '#3b82f6',
                    backgroundColor: 'transparent',
                    pointRadius: 4
                }, {
                    label: 'Regression Line',
                    data: regressionLine,
                    borderColor: '#ef4444',
                    backgroundColor: 'transparent',
                    borderDash: [5, 5],
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Score Regression Analysis'
                    }
                }
            }
        });
    }

    function updateClassScore(subId, value) {
        console.log(`Class score for ${subId}: ${value}`);
    }

