    // ============================================
    // RESULTS PAGE - Student Results by Semester & Course (No Analytics)
    // ============================================

    let currentSemester = '';
    let currentCourseCode = '';
    let currentSearchTerm = '';
    let currentResultTimeFrame = '';

    // Initialize results page
    async function initResultsPage() {
        console.log("Initializing results page...");

        const container = document.getElementById('resultsContainer');
        if (!container) {
            console.error("resultsContainer not found!");
            return;
        }

        container.innerHTML = `
        <div style="text-align:center; padding:60px;">
            <div class="spinner"></div>
            <p style="margin-top:10px;">Loading student results...</p>
        </div>
    `;

        try {
            // Load all submissions
            const result = await apiRequest('get_submissions');
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
                loadGradedStatus();

                // Get unique courses
                const courses = new Map();
                originalSubmissionsData.forEach(sub => {
                    const examDetail = examDetailsCache[sub.exam_id];
                    if (examDetail && examDetail.course_code) {
                        if (!courses.has(examDetail.course_code)) {
                            courses.set(examDetail.course_code, {
                                code: examDetail.course_code,
                                name: examDetail.title,
                                level: examDetail.level,
                                semester: examDetail.semester
                            });
                        }
                    }
                });

                // Render filter bar
                renderResultsFilters(courses);

            } else {
                container.innerHTML = `
                <div style="text-align:center; padding:60px;">
                    <i class="fas fa-folder-open" style="font-size:48px; margin-bottom:15px;"></i>
                    <h3>No Results Available</h3>
                    <p>No student submissions found.</p>
                    <button class="btn primary" onclick="initResultsPage()">Refresh</button>
                </div>
            `;
            }
        } catch (error) {
            console.error('Error:', error);
            container.innerHTML = `
            <div style="text-align:center; padding:60px;">
                <i class="fas fa-exclamation-triangle" style="font-size:48px; margin-bottom:15px;"></i>
                <h3>Error Loading Results</h3>
                <p>${error.message}</p>
                <button class="btn primary" onclick="initResultsPage()">Try Again</button>
            </div>
        `;
        }
    }

    // Render filter bar for results
    function renderResultsFilters(courses) {
        const container = document.getElementById('resultsContainer');
        if (!container) return;

        let courseOptions = '<option value="">All Course Score Sheets</option>';
        const sortedCourses = Array.from(courses.values()).sort((a, b) => a.code.localeCompare(b.code));
        sortedCourses.forEach(course => {
            courseOptions +=
                `<option value="${course.code}">${course.code} - ${course.name} (Level ${course.level})</option>`;
        });

        const filterHtml = `
        <div class="result-filter-bar" style="
            background: var(--panel);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        ">
            <h3 style="margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-chart-line" style="color: #3b82f6;"></i>
                Student Results Dashboard
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div class="field" style="margin-bottom: 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <i class="fas fa-book"></i> Select Course
                    </label>
                    <select id="resultCourseSelect" style="width: 100%; padding: 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        ${courseOptions}
                    </select>
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <i class="fas fa-calendar-alt"></i> Select Semester
                    </label>
                    <select id="resultSemesterSelect" style="width: 100%; padding: 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        <option value="">-- Select Semester --</option>
                        <option value="First Semester">First Semester</option>
                        <option value="Second Semester">Second Semester</option>
                    </select>
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <i class="fas fa-search"></i> Search Student
                    </label>
                    <input type="text" id="resultSearchInput" placeholder="Search by Student ID or Name..." 
                        style="width: 100%; padding: 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                </div>
                <div class="field" style="margin-bottom: 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <i class="fas fa-clock"></i> Time Frame
                    </label>
                    <select id="resultTimeFrameSelect" style="width: 100%; padding: 12px; border-radius: 10px; border: 2px solid var(--border); background: var(--input-bg);">
                        <option value="">All Dates</option>
                        <option value="today">Today</option>
                        <option value="7">Last 7 Days</option>
                        <option value="30">Last 30 Days</option>
                        <option value="month">This Month</option>
                        <option value="year">This Year</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button class="btn" onclick="resetResultsFilters()">
                    <i class="fas fa-undo"></i> Reset All
                </button>
                <button class="btn primary" onclick="applyResultsFilters()">
                    <i class="fas fa-search"></i> View Results
                </button>
            </div>
        </div>
        <div id="resultsTableContainer"></div>
    `;

        container.innerHTML = filterHtml;
    }

    // Apply filters and render results table
    function applyResultsFilters() {
        const semesterSelect = document.getElementById('resultSemesterSelect');
        const courseSelect = document.getElementById('resultCourseSelect');
        const searchInput = document.getElementById('resultSearchInput');
        const timeFrameSelect = document.getElementById('resultTimeFrameSelect');

        currentSemester = semesterSelect ? semesterSelect.value : '';
        currentCourseCode = courseSelect ? courseSelect.value : '';
        currentSearchTerm = searchInput ? searchInput.value : '';
        currentResultTimeFrame = timeFrameSelect ? timeFrameSelect.value : '';

        renderResultsTable();
    }

    // Reset all filters
    function resetResultsFilters() {
        currentSemester = '';
        currentCourseCode = '';
        currentSearchTerm = '';
        currentResultTimeFrame = '';

        const semesterSelect = document.getElementById('resultSemesterSelect');
        const courseSelect = document.getElementById('resultCourseSelect');
        const searchInput = document.getElementById('resultSearchInput');
        const timeFrameSelect = document.getElementById('resultTimeFrameSelect');

        if (semesterSelect) semesterSelect.value = '';
        if (courseSelect) courseSelect.value = '';
        if (searchInput) searchInput.value = '';
        if (timeFrameSelect) timeFrameSelect.value = '';

        const tableContainer = document.getElementById('resultsTableContainer');
        if (tableContainer) {
            tableContainer.innerHTML = `
            <div style="text-align: center; padding: 60px; background: var(--panel); border-radius: 16px; border: 1px solid var(--border);">
                <i class="fas fa-chart-line" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <h3 style="margin-bottom: 10px;">Ready to View Results</h3>
                <p style="color: var(--muted);">Select a course for course-based printing/exporting, or view all courses together.</p>
            </div>
        `;
        }
    }

    function clampScore(value, min, max) {
        const n = roundToWholeNumber(value);
        return Math.max(min, Math.min(max, n));
    }

    function getSubmissionScoreParts(sub) {
        const grading = getSubmissionGrading(sub);
        const classSource = sub.class_score ?? grading.class_score ?? studentClassScores[`${sub.exam_id}:${sub.student_identifier}`] ?? studentClassScores[sub.student_identifier] ?? 0;
        const classScore = clampScore(classSource, 0, 40);

        let examScore;
        if (sub.exam_score !== undefined && sub.exam_score !== null && sub.exam_score !== '') {
            examScore = clampScore(sub.exam_score, 0, 60);
        } else if (grading.exam_score !== undefined && grading.exam_score !== null && grading.exam_score !== '') {
            examScore = clampScore(grading.exam_score, 0, 60);
        } else {
            examScore = clampScore(convertTo60Scale(parseFloat(sub.percentage) || 0), 0, 60);
        }

        const totalScore = clampScore(examScore + classScore, 0, 100);
        const fallbackGradeInfo = getGradeInfo(totalScore);
        const grade = sub.grade || grading.grade || fallbackGradeInfo.grade;
        const gradePoint = parseFloat(sub.grade_point ?? grading.grade_point ?? fallbackGradeInfo.gradePoint);
        return { classScore, examScore, totalScore, grade, gradePoint };
    }

    function buildResultGroups() {
        let filteredSubmissions = [...originalSubmissionsData];

        if (currentCourseCode) {
            filteredSubmissions = filteredSubmissions.filter(sub => {
                const examDetail = examDetailsCache[sub.exam_id];
                return examDetail && examDetail.course_code === currentCourseCode;
            });
        }

        if (currentSemester) {
            filteredSubmissions = filteredSubmissions.filter(sub => {
                const examDetail = examDetailsCache[sub.exam_id];
                return examDetail && examDetail.semester === currentSemester;
            });
        }

        if (currentResultTimeFrame) {
            const now = new Date();
            const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
            const startOfYear = new Date(now.getFullYear(), 0, 1);
            filteredSubmissions = filteredSubmissions.filter(sub => {
                const examDetail = examDetailsCache[sub.exam_id] || {};
                const examDate = parseQodaDate(examDetail.start_datetime || sub.start_datetime || submittedDateValue(sub));
                if (!examDate) return false;
                if (currentResultTimeFrame === 'today') return examDate >= startOfToday;
                if (currentResultTimeFrame === 'month') return examDate >= startOfMonth;
                if (currentResultTimeFrame === 'year') return examDate >= startOfYear;
                const days = parseInt(currentResultTimeFrame, 10);
                if (!Number.isNaN(days)) {
                    const since = new Date(now.getTime() - (days * 24 * 60 * 60 * 1000));
                    return examDate >= since;
                }
                return true;
            });
        }

        if (currentSearchTerm) {
            const searchLower = currentSearchTerm.toLowerCase();
            filteredSubmissions = filteredSubmissions.filter(sub =>
                String(sub.student_identifier || '').toLowerCase().includes(searchLower) ||
                String(sub.student_name || '').toLowerCase().includes(searchLower)
            );
        }

        const groups = {};
        dedupeSubmissionsByExamStudent(filteredSubmissions).forEach(sub => {
            const examDetail = examDetailsCache[sub.exam_id] || {};
            const courseCode = examDetail.course_code || sub.course_code || 'N/A';
            const courseName = examDetail.course_name || sub.course_name || examDetail.title || sub.exam_title || 'Unknown Course';
            const semester = examDetail.semester || 'N/A';
            const startValue = examDetail.start_datetime || sub.start_datetime || '';
            const schoolType = examDetail.school_type || sub.school_type || 'Weekend';
            const academicYear = examDetail.academic_year || sub.academic_year || '';
            const key = `${courseName}::${courseCode}::${semester}::${schoolType}::${academicYear}::${startValue}::${sub.exam_id}`;

            if (!groups[key]) {
                groups[key] = {
                    key,
                    courseName,
                    courseCode,
                    semester,
                    startDateTime: startValue,
                    dateLabel: formatQodaDateTime(startValue),
                    level: examDetail.level || 'N/A',
                    academicYear,
                    programme: examDetail.programme || examDetail.department || 'BIT',
                    schoolType,
                    schoolName: examDetail.school_name || 'PENTECOST UNIVERSITY',
                    intake: examDetail.intake || '0',
                    examIds: [],
                    submissions: []
                };
            }

            if (!groups[key].examIds.includes(sub.exam_id)) groups[key].examIds.push(sub.exam_id);

            const { classScore, examScore, totalScore, grade, gradePoint } = getSubmissionScoreParts(sub);

            groups[key].submissions.push({
                id: sub.id,
                examId: sub.exam_id,
                studentId: sub.student_identifier || 'N/A',
                studentName: sub.student_name || 'Unknown Student',
                classScore,
                examScore,
                totalScore,
                grade,
                gradePoint,
                status: sub.status || 'SUBMITTED'
            });
        });

        Object.values(groups).forEach(group => {
            group.submissions.sort((a, b) => String(a.studentName).localeCompare(String(b.studentName)));
        });

        return Object.values(groups).sort((a, b) =>
            `${a.courseName} ${a.courseCode} ${a.semester} ${a.startDateTime}`.localeCompare(`${b.courseName} ${b.courseCode} ${b.semester} ${b.startDateTime}`)
        );
    }

    function renderResultsTable() {
        const container = document.getElementById('resultsTableContainer');
        if (!container) return;

        const groups = buildResultGroups();
        window.currentResultGroups = {};
        groups.forEach(group => window.currentResultGroups[group.key] = group);

        if (groups.length === 0) {
            container.innerHTML = `
            <div style="text-align: center; padding: 60px; background: var(--panel); border-radius: 16px; border: 1px solid var(--border);">
                <i class="fas fa-folder-open" style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                <h3 style="margin-bottom: 10px;">No Results Found</h3>
                <p style="color: var(--muted);">No student results found for the selected course, semester, and search.</p>
                <button class="btn primary" onclick="resetResultsFilters()" style="margin-top: 15px;">Clear Filters</button>
            </div>
        `;
            return;
        }

        container.innerHTML = groups.map(renderCourseResultSheet).join('');
    }

    function renderCourseResultSheet(group) {
        const safeSheetName = `${group.courseName}_${group.courseCode}_${group.semester}`.replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 31);
        const keyToken = encodeURIComponent(group.key);

        return `
            <section class="course-score-sheet" data-result-key="${escapeHTML(group.key)}" data-sheet-name="${escapeHTML(safeSheetName)}" style="margin-bottom: 32px;">
                <div class="result-sheet-actions" style="background: linear-gradient(135deg, #1e3a5f, #3b82f6); color: white; border-radius: 16px; padding: 20px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                        <div>
                            <h2 style="margin: 0 0 8px 0;">${escapeHTML(group.courseName)}</h2>
                            <h3 style="margin: 0 0 8px 0;">SCORE SHEET</h3>
                            <p style="margin: 0; opacity: 0.95;">Course Code: ${escapeHTML(group.courseCode)} | Semester: ${escapeHTML(group.semester)} | Level: ${escapeHTML(group.level)}</p>
                            <p style="margin: 6px 0 0; opacity: 0.9;">${escapeHTML(group.schoolType)} | ${escapeHTML(group.academicYear || 'Academic year not set')} | ${escapeHTML(group.dateLabel)}</p>
                            <p style="margin: 6px 0 0; opacity: 0.85;">${group.submissions.length} student submission(s)</p>
                        </div>
                        <div class="result-sheet-actions" style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
                            <button class="btn ok small" onclick="publishCourseResults('${keyToken}')"><i class="fas fa-upload"></i> Publish</button>
                            <button class="btn success small" onclick="exportCourseResultsToExcel('${keyToken}')"><i class="fas fa-file-excel"></i> Excel</button>
                            <button class="btn primary small" onclick="printCourseResults('${keyToken}')"><i class="fas fa-print"></i> Print</button>
                        </div>
                    </div>
                </div>
                ${renderPuScoreSheetTemplate(group, true)}
            </section>
        `;
    }

    function normalizePuSemester(semester) {
        const value = String(semester || '').toLowerCase();
        if (value.includes('first') || value === '1') return '1';
        if (value.includes('second') || value === '2') return '2';
        return String(semester || '');
    }

    function getTemplateGrade(result) {
        if (Number(result.classScore || 0) === 0 && Number(result.examScore || 0) === 0 && Number(result.totalScore || 0) === 0) {
            return 'X';
        }
        return result.grade || getGradeInfo(result.totalScore).grade;
    }

    function getGradeRemark(grade) {
        const remarks = {
            A: 'Excellent',
            'B+': 'Very Good',
            B: 'Good',
            'C+': 'Average',
            C: 'Fair',
            'D+': 'Barely Satisfactory',
            D: 'Weak Pass',
            E: 'Fail',
            X: 'Absent'
        };
        return remarks[grade] || '';
    }

    function buildGradeAnalysis(group) {
        const counts = {};
        group.submissions.forEach(result => {
            const grade = getTemplateGrade(result);
            counts[grade] = (counts[grade] || 0) + 1;
        });
        const order = ['D+', 'B', 'A', 'B+', 'X', 'C+', 'C', 'D', 'E'];
        return order
            .filter(grade => counts[grade])
            .map(grade => ({
                grade,
                count: counts[grade],
                percentage: group.submissions.length > 0 ? ((counts[grade] / group.submissions.length) * 100).toFixed(1) : '0.0',
                remark: getGradeRemark(grade)
            }));
    }

    function renderPuScoreSheetTemplate(group, includeActions = false) {
        const rows = group.submissions.map((result, index) => {
            const grade = getTemplateGrade(result);
            return `
                <tr data-submission-id="${result.id}">
                    <td>${index + 1}</td>
                    <td>${escapeHTML(result.studentId)}</td>
                    <td class="student-name-cell">${escapeHTML(result.studentName)}</td>
                    <td>${result.classScore}</td>
                    <td>${result.examScore}</td>
                    <td>${result.totalScore}</td>
                    <td>${escapeHTML(grade)}</td>
                    ${includeActions ? `<td class="result-actions no-print"><button class="btn primary small" onclick="openQuestionReview(${result.id})"><i class="fas fa-eye"></i> Review</button> <button class="btn danger small" onclick="deleteStudentSubmission(${result.id})"><i class="fas fa-trash"></i> Delete</button></td>` : ''}
                </tr>
            `;
        }).join('');

        const gradeRows = buildGradeAnalysis(group).map(item => `
            <tr>
                <td>${escapeHTML(item.grade)}</td>
                <td>${item.count}</td>
                <td>${item.percentage}</td>
                <td class="remark-cell">${escapeHTML(item.remark)}</td>
            </tr>
        `).join('');

        return `
            <div class="pu-score-template">
                <style>
                    .pu-score-template {
                        background: #fff;
                        color: #000;
                        font-family: Arial, Helvetica, sans-serif;
                        width: 100%;
                        max-width: 1180px;
                        margin: 0 auto;
                        padding: 16px 20px;
                        overflow-x: auto;
                    }
                    .pu-score-template .score-title {
                        text-align: center;
                        font-weight: 700;
                        line-height: 1.22;
                    }
                    .pu-score-template .score-title h1 {
                        font-size: 18px;
                        margin: 0 0 4px;
                        letter-spacing: 0;
                    }
                    .pu-score-template .score-title h2 {
                        font-size: 16px;
                        margin: 0 0 6px;
                        letter-spacing: 0;
                    }
                    .pu-score-template .score-meta {
                        text-align: center;
                        font-size: 12px;
                        line-height: 1.35;
                        margin-bottom: 8px;
                    }
                    .pu-score-template table {
                        border-collapse: collapse;
                        width: 100%;
                        font-size: 11px;
                    }
                    .pu-score-template th,
                    .pu-score-template td {
                        border: 1px solid #111;
                        padding: 4px 5px;
                        text-align: center;
                        vertical-align: middle;
                    }
                    .pu-score-template th {
                        font-weight: 700;
                        background: #fff;
                    }
                    .pu-score-template .student-name-cell,
                    .pu-score-template .remark-cell {
                        text-align: left;
                    }
                    .pu-score-template .main-score-table .col-no { width: 34px; }
                    .pu-score-template .main-score-table .col-student-no { width: 118px; }
                    .pu-score-template .main-score-table .col-name { min-width: 245px; }
                    .pu-score-template .main-score-table .col-score { width: 78px; }
                    .pu-score-template .grade-analysis-title {
                        font-size: 12px;
                        font-weight: 700;
                        margin: 12px 0 4px;
                    }
                    .pu-score-template .grade-analysis-table {
                        width: 430px;
                        max-width: 100%;
                    }
                    .pu-score-template .result-actions {
                        min-width: 150px;
                        white-space: nowrap;
                    }
                    @media print {
                        @page { size: A4 landscape; margin: 9mm; }
                        body { background: #fff !important; }
                        .no-print, .result-sheet-actions, .result-actions { display: none !important; }
                        .pu-score-template {
                            max-width: none;
                            padding: 0;
                            overflow: visible;
                        }
                        .pu-score-template table { font-size: 10.5px; }
                        .pu-score-template th,
                        .pu-score-template td { padding: 3px 4px; }
                    }
                </style>
                <div class="score-title">
                    <h1>${escapeHTML(String(group.schoolName || 'PENTECOST UNIVERSITY').toUpperCase())}</h1>
                    <h2>SCORE SHEET</h2>
                </div>
                <div class="score-meta">
                    <div><strong>${escapeHTML(group.programme || 'BIT')}&nbsp;-&nbsp;${escapeHTML(group.schoolType || 'Weekend')}</strong></div>
                    <div>
                        Level ${escapeHTML(group.level || '')}&nbsp;&nbsp;
                        Semester ${escapeHTML(normalizePuSemester(group.semester))}&nbsp;&nbsp;
                        ${escapeHTML(group.academicYear || '')}&nbsp;&nbsp;
                        Intake: ${escapeHTML(group.intake || '0')}
                    </div>
                    <div><strong>${escapeHTML(group.courseName)} - ${escapeHTML(group.courseCode)}</strong></div>
                </div>
                <table class="main-score-table">
                    <thead>
                        <tr>
                            <th class="col-no">No</th>
                            <th class="col-student-no">Student No</th>
                            <th class="col-name">Student Name</th>
                            <th class="col-score">Class<br>Score<br>40</th>
                            <th class="col-score">Exam<br>Score<br>60</th>
                            <th class="col-score">Total<br>Score<br>100</th>
                            <th class="col-score">Grade</th>
                            ${includeActions ? '<th class="result-actions no-print">Actions</th>' : ''}
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
                <div class="grade-analysis-title">GRADE ANALYSIS</div>
                <table class="grade-analysis-table">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>No. of Students</th>
                            <th>Percentage(%)</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>${gradeRows}</tbody>
                </table>
            </div>
        `;
    }

    function normalizeResultGroupKey(groupKey) {
        try {
            return decodeURIComponent(groupKey);
        } catch (error) {
            return groupKey;
        }
    }

    function getResultGroup(groupKey) {
        groupKey = normalizeResultGroupKey(groupKey);
        return (window.currentResultGroups || {})[groupKey] || null;
    }

    function getResultSheetElement(groupKey) {
        groupKey = normalizeResultGroupKey(groupKey);
        return Array.from(document.querySelectorAll('.course-score-sheet'))
            .find(sheet => sheet.dataset.resultKey === groupKey) || null;
    }

    function exportCourseResultsToExcel(groupKey) {
        const group = getResultGroup(groupKey);
        if (!group) {
            toast('No score sheet to export');
            return;
        }

        const gradeAnalysis = buildGradeAnalysis(group);
        const rows = [
            [String(group.schoolName || 'PENTECOST UNIVERSITY').toUpperCase()],
            ['SCORE SHEET'],
            [`${group.programme || 'BIT'} - ${group.schoolType || 'Weekend'}`],
            [`Level ${group.level || ''}`, `Semester ${normalizePuSemester(group.semester)}`, group.academicYear || '', `Intake: ${group.intake || '0'}`],
            [`${group.courseName} - ${group.courseCode}`],
            [],
            ['No', 'Student No', 'Student Name', 'Class Score 40', 'Exam Score 60', 'Total Score 100', 'Grade']
        ];

        group.submissions.forEach((result, index) => {
            rows.push([
                index + 1,
                result.studentId,
                result.studentName,
                result.classScore,
                result.examScore,
                result.totalScore,
                getTemplateGrade(result)
            ]);
        });

        rows.push([]);
        rows.push(['GRADE ANALYSIS']);
        rows.push(['Grade', 'No. of Students', 'Percentage(%)', 'Remark']);
        gradeAnalysis.forEach(item => {
            rows.push([item.grade, item.count, item.percentage, item.remark]);
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(rows);
        ws['!cols'] = [
            { wch: 6 },
            { wch: 18 },
            { wch: 36 },
            { wch: 14 },
            { wch: 14 },
            { wch: 15 },
            { wch: 10 }
        ];
        ws['!merges'] = [
            { s: { r: 0, c: 0 }, e: { r: 0, c: 6 } },
            { s: { r: 1, c: 0 }, e: { r: 1, c: 6 } },
            { s: { r: 2, c: 0 }, e: { r: 2, c: 6 } },
            { s: { r: 4, c: 0 }, e: { r: 4, c: 6 } },
            { s: { r: rows.length - gradeAnalysis.length - 2, c: 0 }, e: { r: rows.length - gradeAnalysis.length - 2, c: 3 } }
        ];
        const sheetName = `${group.courseName}_${group.courseCode}_${group.semester}`.replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 31) || 'ScoreSheet';
        XLSX.utils.book_append_sheet(wb, ws, sheetName);
        const filename = `${group.courseCode}_${group.semester}_scoresheet_${new Date().toISOString().slice(0, 10)}.xlsx`.replace(/[^A-Za-z0-9_.-]/g, '_');
        XLSX.writeFile(wb, filename);
        toast(`Exported ${group.courseName} (${group.courseCode}) score sheet`);
    }

    function printCourseResults(groupKey) {
        const group = getResultGroup(groupKey);
        const sheet = getResultSheetElement(groupKey);
        if (!group || !sheet) {
            toast('No score sheet to print');
            return;
        }

        const printContent = renderPuScoreSheetTemplate(group, false);
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>${escapeHTML(group.courseCode)} ${escapeHTML(group.semester)} Score Sheet</title>
                    <style>
                        @page { size: A4 landscape; margin: 9mm; }
                        body { margin: 0; background: #fff; }
                    </style>
                </head>
                <body>
                    ${printContent}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    async function publishCourseResults(groupKey) {
        const group = getResultGroup(groupKey);
        if (!group || group.examIds.length === 0) {
            toast('No results to publish for this score sheet');
            return;
        }
        if (!confirm(`Publish ${group.courseName} (${group.courseCode}) - ${group.semester} results? Students will be able to see them.`)) return;

        showLoading('Publishing results...');
        let published = 0;
        for (const examId of group.examIds) {
            const result = await apiRequest('publish_results', { exam_id: examId });
            if (result.success) published++;
        }
        hideLoading();
        toast(`Published ${group.courseName} (${group.courseCode}) result for ${published} exam(s)`);
    }

    async function deleteStudentSubmission(submissionId) {
        const groups = Object.values(window.currentResultGroups || {});
        const submission = groups.flatMap(group => group.submissions).find(item => Number(item.id) === Number(submissionId));
        const label = submission ? `${submission.studentName}'s submission` : 'this student submission';
        if (!confirm(`Delete ${label}? This cannot be undone.`)) return;

        const previousCourse = currentCourseCode;
        const previousSemester = currentSemester;
        const previousSearch = currentSearchTerm;
        const previousTimeFrame = currentResultTimeFrame;
        showLoading('Deleting submission...');
        const result = await apiRequest('delete_student_submission', { submission_id: submissionId });
        hideLoading();
        if (result.success) {
            toast('Submission deleted');
            await initResultsPage();
            currentCourseCode = previousCourse;
            currentSemester = previousSemester;
            currentSearchTerm = previousSearch;
            currentResultTimeFrame = previousTimeFrame;
            const courseSelect = document.getElementById('resultCourseSelect');
            const semesterSelect = document.getElementById('resultSemesterSelect');
            const searchInput = document.getElementById('resultSearchInput');
            const timeFrameSelect = document.getElementById('resultTimeFrameSelect');
            if (courseSelect) courseSelect.value = currentCourseCode;
            if (semesterSelect) semesterSelect.value = currentSemester;
            if (searchInput) searchInput.value = currentSearchTerm;
            if (timeFrameSelect) timeFrameSelect.value = currentResultTimeFrame;
            renderResultsTable();
        } else {
            toast('Delete failed: ' + (result.error || 'Unknown error'));
        }
    }

    async function deleteCourseSubmissions(groupKey) {
        const group = getResultGroup(groupKey);
        if (!group) {
            toast('Course score sheet not found');
            return;
        }
        if (!confirm(`Delete ALL submissions for ${group.courseName} (${group.courseCode}) - ${group.semester}? This cannot be undone.`)) return;

        const previousCourse = currentCourseCode;
        const previousSemester = currentSemester;
        const previousSearch = currentSearchTerm;
        const previousTimeFrame = currentResultTimeFrame;
        showLoading('Deleting course submissions...');
        const result = await apiRequest('delete_course_submissions', { exam_ids: JSON.stringify(group.examIds) });
        hideLoading();
        if (result.success) {
            toast(`Deleted ${result.deleted || 0} submission(s) for ${group.courseCode}`);
            await initResultsPage();
            currentCourseCode = previousCourse;
            currentSemester = previousSemester;
            currentSearchTerm = previousSearch;
            currentResultTimeFrame = previousTimeFrame;
            const courseSelect = document.getElementById('resultCourseSelect');
            const semesterSelect = document.getElementById('resultSemesterSelect');
            const searchInput = document.getElementById('resultSearchInput');
            const timeFrameSelect = document.getElementById('resultTimeFrameSelect');
            if (courseSelect) courseSelect.value = currentCourseCode;
            if (semesterSelect) semesterSelect.value = currentSemester;
            if (searchInput) searchInput.value = currentSearchTerm;
            if (timeFrameSelect) timeFrameSelect.value = currentResultTimeFrame;
            renderResultsTable();
        } else {
            toast('Delete failed: ' + (result.error || 'Unknown error'));
        }
    }
    // Override the renderResults function to use our new results page
    const originalRenderResults = window.renderResults;
    window.renderResults = function() {
        initResultsPage();
    };
