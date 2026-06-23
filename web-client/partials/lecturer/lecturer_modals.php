    <div id="fullScreenProctorModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 100vw; height: 100vh; display:flex; flex-direction:column; padding:0; overflow:hidden;">
            <div class="modal-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 18px; border-bottom:1px solid var(--border);">
                <div>
                    <h3 id="fullScreenStudentName" style="margin:0;">Student Screen</h3>
                    <div id="fullScreenStudentId" style="font-size:12px; color:var(--muted); margin-top:4px;"></div>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                    <span id="violationTag" class="tag danger" style="display:none;"></span>
                    <button class="btn warn" onclick="sendWarningToStudent()"><i class="fas fa-exclamation-triangle"></i> Warn</button>
                    <button class="btn danger" onclick="lockStudentScreenFromModal()"><i class="fas fa-lock"></i> Lock</button>
                    <button class="btn ok" onclick="unlockStudentScreenFromModal()"><i class="fas fa-unlock"></i> Unlock</button>
                    <button class="btn primary" onclick="takeSnapshotFromModal()"><i class="fas fa-camera"></i> Screenshot</button>
                    <button class="btn" onclick="closeFullScreenProctorModal()"><i class="fas fa-times"></i> Close</button>
                </div>
            </div>
            <div id="fullScreenContent" style="flex:1; min-height:0; background:#000; display:flex; align-items:stretch; justify-content:stretch; overflow:hidden;"></div>
        </div>
    </div>

    <div id="questionBankModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 1100px; max-width: 96%; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px;">
                <div>
                    <h3 style="margin:0;"><i class="fas fa-database"></i> Question Bank</h3>
                    <small style="color:var(--muted);">Reuse previous questions, then edit them before publishing the new exam.</small>
                </div>
                <button type="button" class="btn" onclick="closeQuestionBankModal()"><i class="fas fa-times"></i> Close</button>
            </div>
            <div class="rowgrid" style="margin-bottom:14px;">
                <div class="field">
                    <label>Search</label>
                    <input id="qbSearch" type="text" placeholder="Topic, keyword, question text..." oninput="debouncedQuestionBankSearch()">
                </div>
                <div class="field">
                    <label>Course Code</label>
                    <input id="qbCourse" type="text" placeholder="e.g., PBIT102" oninput="debouncedQuestionBankSearch()">
                </div>
                <div class="field">
                    <label>Language</label>
                    <select id="qbLanguage" onchange="loadQuestionBank()">
                        <option value="">All Languages</option>
                    </select>
                </div>
                <div class="field">
                    <label>Semester</label>
                    <select id="qbSemester" onchange="loadQuestionBank()">
                        <option value="">All Semesters</option>
                        <option value="First Semester">First Semester</option>
                        <option value="Second Semester">Second Semester</option>
                        <option value="Summer School">Summer School</option>
                    </select>
                </div>
                <div class="field">
                    <label>Year / Session</label>
                    <input id="qbYear" type="text" placeholder="2026 or 2026/2027" oninput="debouncedQuestionBankSearch()">
                </div>
                <div class="field">
                    <label>Difficulty</label>
                    <select id="qbDifficulty" onchange="loadQuestionBank()">
                        <option value="">Any</option>
                        <option value="easy">Easy</option>
                        <option value="medium">Medium</option>
                        <option value="hard">Hard</option>
                    </select>
                </div>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
                <button class="btn primary" onclick="loadQuestionBank()"><i class="fas fa-search"></i> Search Bank</button>
                <button class="btn" onclick="clearQuestionBankFilters()"><i class="fas fa-undo"></i> Reset</button>
            </div>
            <div id="questionBankResults" class="question-bank-grid">
                <div class="empty-state" style="grid-column:1/-1;">Search the bank to find reusable questions.</div>
            </div>
        </div>
    </div>

    <!-- Student Modal -->
    <div id="studentModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 700px; max-width: 90%; max-height: 85vh; overflow-y: auto;">
            <div class="modal-header"
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                <h3 id="studentModalTitle" style="font-size: 20px; font-weight: 700; color: var(--accent-blue);">
                    <i class="fas fa-user-graduate"></i> Add New Student
                </h3>
                <button type="button" onclick="closeStudentModal()"
                    style="background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-secondary);">&times;</button>
            </div>

            <form id="studentForm" onsubmit="saveStudent(event)">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">

                    <div>
                        <div class="field required">
                            <label><i class="fas fa-id-card"></i> Student ID <span class="required-star"></span></label>
                            <input type="text" id="studentId" class="form-input" placeholder="e.g., STU001" required>
                            <small style="font-size: 11px; color: var(--muted);">Default password will be set to Student
                                ID</small>
                        </div>

                        <div class="field required">
                            <label><i class="fas fa-user"></i> Full Name <span class="required-star"></span></label>
                            <input type="text" id="studentFullName" class="form-input" placeholder="e.g., John Doe"
                                required>
                        </div>

                        <div class="field required" style="grid-column: 1 / -1;">
                            <label><i class="fas fa-book"></i> Courses <span class="required-star"></span></label>
                            <div id="studentCoursesList"></div>
                            <button type="button" class="btn btn-outline" onclick="addStudentCourseRow('', '')" style="margin-top: 8px;">
                                <i class="fas fa-plus"></i> Add Course
                            </button>
                            <small>Add one or more course code/name pairs for this student.</small>
                        </div>
                    </div>
                    <div id="studentFormError"
                        style="display:none; background:#fee2e2; color:#dc2626; padding:12px; border-radius:8px; margin-bottom:15px;">
                    </div>

                    <div>
                        <div class="field required">
                            <label><i class="fas fa-graduation-cap"></i> Programme <span
                                    class="required-star"></span></label>
                            <select id="studentProgramme" class="form-input" required>
                                <option value="">Select Programme</option>
                                <option value="Computer Science">Computer Science</option>
                                <option value="Information Technology">Information Technology</option>
                                <option value="SIndustal Software Engineering">Industral Software Engineering
                                </option>
                                <option value="NCCE">NCCE</option>
                                <option value="Pre Engineering">Pre Engineering</option>
                                <option value="Health Information Management">Health Information Management</option>
                            </select>
                        </div>

                        <div class="field required">
                            <label><i class="fas fa-layer-group"></i> Level <span class="required-star"></span></label>
                            <select id="studentLevel" class="form-input" required>
                                <option value="">Select Level</option>
                                <option value="100">100 Level</option>
                                <option value="200">200 Level</option>
                                <option value="300">300 Level</option>
                                <option value="400">400 Level</option>
                                <option value="500">500 Level</option>
                            </select>
                        </div>

                        <div class="field">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="studentStatus" class="form-input">
                                <option value="Active">✅ Active</option>
                                <option value="Inactive">❌ Inactive</option>
                            </select>
                        </div>

                        <div class="field">
                            <label><i class="fas fa-key"></i> Auto-generated Credentials</label>
                            <input type="text" id="studentUsername" class="form-input" readonly
                                style="background: var(--disabled-bg, #f0f0f0);" placeholder="Auto-generated">
                            <input type="text" id="studentPassword" class="form-input" readonly
                                style="background: var(--disabled-bg, #f0f0f0); margin-top: 8px;"
                                placeholder="Auto-generated">
                            <small style="font-size: 11px; color: var(--muted);">Username and password are set to
                                Student ID</small>
                        </div>
                    </div>
                </div>

                <div class="hint"
                    style="margin-top: 20px; padding: 12px; background: rgba(79, 70, 229, 0.1); border-radius: 10px; border-left: 4px solid var(--accent-blue);">
                    <i class="fas fa-info-circle"></i> <strong>Note:</strong> Default password will be set to the
                    Student ID. Student can change after first login.
                </div>

                <div class="modal-actions"
                    style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 25px; padding-top: 15px; border-top: 1px solid var(--border);">
                    <button type="button" class="btn btn-outline" onclick="closeStudentModal()"
                        style="padding: 10px 24px;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" style="padding: 10px 24px;">
                        <i class="fas fa-save"></i> Save Student
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="migrateLevelModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 420px; max-width: 92%;">
            <div class="modal-header"
                style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--border);">
                <h3 style="font-size:20px;font-weight:700;color:var(--accent-blue);">
                    <i class="fas fa-level-up-alt"></i> Migrate Student Level
                </h3>
                <button type="button" onclick="closeMigrateLevelModal()"
                    style="background:none;border:0;font-size:28px;cursor:pointer;color:var(--text-secondary);">&times;</button>
            </div>
            <input type="hidden" id="migrateStudentId">
            <p id="migrateStudentName" style="font-weight:700;margin-bottom:14px;"></p>
            <div class="field">
                <label>New Level</label>
                <select id="migrateStudentLevel" class="form-input">
                    <option value="100">100 Level</option>
                    <option value="200">200 Level</option>
                    <option value="300">300 Level</option>
                    <option value="400">400 Level</option>
                    <option value="500">500 Level</option>
                </select>
            </div>
            <div class="modal-actions" style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
                <button type="button" class="btn btn-outline" onclick="closeMigrateLevelModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitMigrateLevel()">Migrate Level</button>
            </div>
        </div>
    </div>

    <!-- Question Preview Modal -->
    <div id="previewModal" class="modal" style="display:none;">
        <div class="modal-content" style="width:800px; max-width:95%;">
            <h3>📋 Exam Preview</h3>
            <div id="previewContent" style="max-height:70vh; overflow-y:auto; padding:10px;"></div>
            <div class="modal-actions">
                <button class="btn" onclick="closePreviewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Marking Modal -->
    <div id="markingModal" class="modal" style="display:none;">
        <div class="modal-content" style="width:800px; max-width:95%;">
            <h3 id="markingModalTitle">Mark Submission</h3>
            <div id="markingContent" style="max-height:70vh; overflow-y:auto; padding:10px;"></div>
            <div class="modal-actions">
                <button class="btn ok" onclick="saveManualMarks()">Save Marks</button>
                <button class="btn" onclick="closeMarkingModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Screen View Modal -->
    <div id="screenModal" class="modal" style="display:none;">
        <div class="modal-content" style="width:90%; height:90%; max-width:1200px;">
            <h3 id="screenModalTitle">Student Screen</h3>
            <div id="screenContent" style="height:80%; overflow-y:auto; padding:10px;">
                <video
                    src="https://player.vimeo.com/external/370795553.sd.mp4?s=3f4f7a6e7c6f7f6e7c6f7f6e7c6f7f6e&profile_id=165&oauth2_token_id=57447761"
                    autoplay loop controls style="width:100%; height:auto;"></video>
            </div>
            <div class="modal-actions">
                <button class="btn" onclick="closeScreenModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- View Student Details Modal -->
    <div id="viewStudentModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 600px; max-width: 90%; max-height: 85vh; overflow-y: auto;">
            <div class="modal-header"
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                <h3 id="viewStudentModalTitle" style="font-size: 20px; font-weight: 700; color: var(--accent-blue);">
                    <i class="fas fa-user-graduate"></i> Student Details
                </h3>
                <button type="button" onclick="closeViewStudentModal()"
                    style="background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-secondary);">&times;</button>
            </div>
            <div id="viewStudentContent">
            </div>
            <div class="modal-actions"
                style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border);">
                <button type="button" class="btn btn-outline" onclick="closeViewStudentModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Exam Preview Modal -->
    <div id="examPreviewModal" class="modal" style="display:none;">
        <div class="modal-content"
            style="width:95%; max-width:920px; max-height:92vh; overflow-y:auto; padding:0; border-radius:20px;">
            <div
                style="position:sticky; top:0; z-index:10; background:var(--panel); border-bottom:1px solid var(--border); padding:16px 24px; display:flex; justify-content:space-between; align-items:center; border-radius:20px 20px 0 0;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div
                        style="width:36px; height:36px; background:linear-gradient(135deg,#4f46e5,#06b6d4); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-eye" style="color:#fff; font-size:16px;"></i>
                    </div>
                    <div>
                        <div style="font-weight:700; font-size:16px; color:var(--text);">Exam Preview</div>
                        <div style="font-size:12px; color:var(--muted);">Student view — read-only</div>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <button onclick="document.getElementById('examPreviewModal').style.display='none'"
                        style="width:36px; height:36px; background:none; border:1px solid var(--border); border-radius:10px; cursor:pointer; font-size:18px; color:var(--muted); display:flex; align-items:center; justify-content:center;">
                        &times;
                    </button>
                </div>
            </div>
            <div id="examPreviewContent" style="padding:24px;"></div>
        </div>
    </div>
    <!-- Import Students Modal -->
    <div id="importModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 700px; max-width: 90%; max-height: 85vh; overflow-y: auto;">
            <div class="modal-header"
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                <h3 style="font-size: 20px; font-weight: 700; color: var(--accent-blue);">
                    <i class="fas fa-file-upload"></i> Import Students (Bulk)
                </h3>
                <button type="button" onclick="closeImportModal()"
                    style="background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-secondary);">&times;</button>
            </div>

            <div class="import-instructions"
                style="background: rgba(59,130,246,0.1); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0;"><i class="fas fa-info-circle"></i> Instructions:</h4>
                <ul style="margin: 0; padding-left: 20px; font-size: 13px;">
                    <li>Download the template using the button below</li>
                    <li>Fill in student details (Student ID, Full Name, Level, Programme, Course Code, Course Name,
                        Status)</li>
                    <li>Level must be: 100, 200, 300, 400, or 500</li>
                    <li>Status must be: Active or Inactive</li>
                    <li>Maximum 500 students per import</li>
                    <li>Duplicate Student IDs will be skipped</li>
                </ul>
            </div>

            <div style="margin-bottom: 20px;">
                <button class="btn primary" onclick="downloadImportTemplate()">
                    <i class="fas fa-download"></i> Download CSV Template
                </button>
            </div>

            <div class="field">
                <label><i class="fas fa-file-csv"></i> Choose CSV/Excel File</label>
                <input type="file" id="importFile" accept=".csv, .xlsx, .xls"
                    style="padding: 10px; border: 2px solid var(--border); border-radius: 10px; width: 100%;">
                <small style="display: block; margin-top: 5px;">Supported formats: CSV, Excel (.xlsx, .xls)</small>
            </div>

            <div class="field">
                <label><i class="fas fa-book"></i> Default Course (Optional)</label>
                <input type="text" id="defaultCourseCode" placeholder="e.g., CS101"
                    style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border);">
                <small>If course code is empty in file, this will be used</small>
            </div>

            <div class="field">
                <label><i class="fas fa-book-open"></i> Default Course Name (Optional)</label>
                <input type="text" id="defaultCourseName" placeholder="e.g., Introduction to Programming"
                    style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border);">
            </div>

            <div id="importProgress" style="display: none; margin: 15px 0;">
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar" id="importProgressBar" style="width: 0%;"></div>
                </div>
                <p id="importStatus" style="font-size: 13px; margin-top: 8px;">Processing...</p>
            </div>

            <div id="importResults"
                style="display: none; margin: 15px 0; padding: 12px; border-radius: 8px; max-height: 200px; overflow-y: auto;">
            </div>

            <div class="modal-actions"
                style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border);">
                <button type="button" class="btn btn-outline" onclick="closeImportModal()">Cancel</button>
                <button type="button" class="btn primary" onclick="importStudents()">
                    <i class="fas fa-upload"></i> Import Students
                </button>
            </div>
        </div>
    </div>

    <!-- Question Review Modal -->
    <div id="questionReviewModal" class="modal" style="display:none;">
        <div class="modal-content"
            style="width: 90%; max-width: 1200px; height: 90vh; padding: 0; display: flex; flex-direction: column;">
            <div
                style="padding: 20px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 id="reviewStudentName" style="margin: 0;">Student Submission</h2>
                    <p id="reviewExamInfo" style="margin: 5px 0 0 0; opacity: 0.9;"></p>
                </div>
                <button onclick="closeQuestionReviewModal()"
                    style="background: none; border: none; color: white; font-size: 28px; cursor: pointer;">&times;</button>
            </div>

            <div style="display: flex; flex: 1; overflow: hidden;">
                <!-- Question List Sidebar (LEFT PANEL) -->
                <div
                    style="width: 280px; background: var(--bg); border-right: 1px solid var(--border); overflow-y: auto; padding: 15px;">
                    <h4 style="margin-bottom: 15px;">Questions</h4>
                    <div id="questionListSidebar"></div>
                </div>

                <!-- Main Content Area (RIGHT PANEL - CODE EDITOR) -->
                <div style="flex: 1; overflow-y: auto; padding: 20px;">
                    <div id="questionContentArea">
                        <div style="text-align: center; padding: 60px;">
                            <i class="fas fa-code"
                                style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                            <p>Select a question from the left to view and grade</p>
                        </div>
                    </div>
                </div>
            </div>

            <div
                style="padding: 15px 20px; border-top: 1px solid var(--border); background: var(--panel); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>Total Score: </strong>
                    <span id="totalScoreDisplay" style="font-size: 20px; font-weight: bold; color: #3b82f6;">0</span>
                    <span id="totalMarksDisplay"></span>
                </div>
                <div>

                    <button class="btn" onclick="closeQuestionReviewModal()">Close</button>
                    <button class="btn primary" onclick="saveAllScores()">Save All Scores</button>
                </div>
            </div>
        </div>
    </div>


    <!-- Enhanced Code Tester Modal with Multi-Language Support -->
    <div id="codeTesterModal" class="modal" style="display:none;">
        <div class="modal-content"
            style="width: 95%; max-width: 1400px; height: 85vh; padding: 0; display: flex; flex-direction: column;">
            <div
                style="padding: 15px 20px; background: linear-gradient(135deg, #10b981, #059669); color: white; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;"><i class="fas fa-code"></i> Code Tester & Grader</h3>
                <div>
                    <span id="languageBadge"
                        style="background: rgba(255,255,255,0.2); padding: 5px 12px; border-radius: 20px; margin-right: 10px; font-size: 12px;">
                        <i class="fas fa-tag"></i> <span id="currentLanguage">Python</span>
                    </span>
                    <button id="runCodeBtn" onclick="executeCode()"
                        style="background: white; color: #059669; border: none; padding: 8px 20px; border-radius: 8px; margin-right: 10px; cursor: pointer; font-weight: bold;">
                        <i class="fas fa-play"></i> Run Code
                    </button>
                    <button onclick="closeCodeTesterModal()"
                        style="background: none; border: none; color: white; font-size: 28px; cursor: pointer;">&times;</button>
                </div>
            </div>

            <div style="display: flex; flex: 1; overflow: hidden; flex-wrap: wrap;">
                <!-- Left Panel: Code Editor -->
                <div
                    style="flex: 1; min-width: 300px; display: flex; flex-direction: column; border-right: 1px solid var(--border);">
                    <div
                        style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <strong><i class="fas fa-laptop-code"></i> Code Editor</strong>
                        </div>
                        <div>
                            <label style="font-size: 12px;">Input (stdin):</label>
                            <textarea id="testInput" rows="2"
                                style="width: 200px; padding: 5px; font-family: monospace; font-size: 11px;"
                                placeholder="Optional input..."></textarea>
                        </div>
                    </div>
                    <textarea id="testCodeEditor"
                        style="flex: 1; padding: 15px; font-family: 'Courier New', monospace; font-size: 13px; background: #1e1e1e; color: #d4d4d4; border: none; resize: none; outline: none; min-height: 200px;"></textarea>
                </div>

                <!-- Right Panel: Output & Grading -->
                <div style="flex: 1; min-width: 300px; display: flex; flex-direction: column;">
                    <div style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                        <strong><i class="fas fa-terminal"></i> Output</strong>
                        <span id="executionStatus" style="margin-left: 10px; font-size: 11px;"></span>
                    </div>
                    <div id="testOutput"
                        style="flex: 0.5; overflow: auto; padding: 15px; background: #1e1e1e; font-family: monospace; font-size: 13px; color: #d4d4d4; min-height: 150px;">
                        Click "Run Code" to see output...
                    </div>

                    <!-- Test Results & Grading -->
                    <div id="testResultsPanel"
                        style="flex: 0.5; border-top: 1px solid var(--border); overflow-y: auto; background: var(--bg); min-height: 150px;">
                        <div style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                            <strong><i class="fas fa-chart-line"></i> Test Results & Grading</strong>
                            <span id="totalScoreDisplay"
                                style="float: right; font-size: 16px; font-weight: bold;"></span>
                        </div>
                        <!-- Enhanced Code Tester Modal with Multi-Language Support -->
                        <div id="codeTesterModal" class="modal" style="display:none;">
                            <div class="modal-content"
                                style="width: 95%; max-width: 1400px; height: 85vh; padding: 0; display: flex; flex-direction: column;">
                                <div
                                    style="padding: 15px 20px; background: linear-gradient(135deg, #10b981, #059669); color: white; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center;">
                                    <h3 style="margin: 0;"><i class="fas fa-code"></i> Code Tester & Grader</h3>
                                    <div>
                                        <span id="languageBadge"
                                            style="background: rgba(255,255,255,0.2); padding: 5px 12px; border-radius: 20px; margin-right: 10px; font-size: 12px;">
                                            <i class="fas fa-tag"></i> <span id="currentLanguage">Python</span>
                                        </span>
                                        <button id="runCodeBtn" onclick="executeCode()"
                                            style="background: white; color: #059669; border: none; padding: 8px 20px; border-radius: 8px; margin-right: 10px; cursor: pointer; font-weight: bold;">
                                            <i class="fas fa-play"></i> Run Code
                                        </button>
                                        <button onclick="closeCodeTesterModal()"
                                            style="background: none; border: none; color: white; font-size: 28px; cursor: pointer;">&times;</button>
                                    </div>
                                </div>

                                <div style="display: flex; flex: 1; overflow: hidden; flex-wrap: wrap;">
                                    <!-- Left Panel: Code Editor -->
                                    <div
                                        style="flex: 1; min-width: 300px; display: flex; flex-direction: column; border-right: 1px solid var(--border);">
                                        <div
                                            style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                                            <strong><i class="fas fa-laptop-code"></i> Code Editor</strong>
                                        </div>
                                        <textarea id="testCodeEditor"
                                            style="flex: 1; padding: 15px; font-family: 'Courier New', monospace; font-size: 13px; background: #1e1e1e; color: #d4d4d4; border: none; resize: none; outline: none;"></textarea>
                                    </div>

                                    <!-- Right Panel: Output & Grading -->
                                    <div style="flex: 1; min-width: 300px; display: flex; flex-direction: column;">
                                        <div
                                            style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                                            <strong><i class="fas fa-terminal"></i> Output</strong>
                                        </div>
                                        <div id="testOutput"
                                            style="flex: 0.5; overflow: auto; padding: 15px; background: #1e1e1e; font-family: monospace; font-size: 13px; color: #d4d4d4;">
                                            Click "Run Code" to see output...
                                        </div>

                                        <!-- Test Results & Grading -->
                                        <div id="testResultsPanel"
                                            style="flex: 0.5; border-top: 1px solid var(--border); overflow-y: auto; background: var(--bg);">
                                            <div
                                                style="padding: 10px; background: var(--bg); border-bottom: 1px solid var(--border);">
                                                <strong><i class="fas fa-chart-line"></i> Test Results &
                                                    Grading</strong>
                                            </div>
                                            <div id="testResultsList" style="padding: 10px;">
                                                <div style="text-align: center; color: var(--muted); padding: 20px;">
                                                    Run the code to see test results
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="testResultsList" style="padding: 10px;">
                            <div style="text-align: center; color: var(--muted); padding: 20px;">
                                Run the code to see test results
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                style="padding: 10px 15px; border-top: 1px solid var(--border); background: var(--panel); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <span id="suggestedScoreDisplay" style="font-size: 14px;"></span>
                </div>
                <div>
                    <button class="btn" onclick="closeCodeTesterModal()">Close</button>
                    <button class="btn primary" id="applyScoreBtn" onclick="applySuggestedScoreToGrade()"
                        style="background: #10b981;">
                        <i class="fas fa-check-circle"></i> Apply Score to Grade
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Course Stats Modal -->
    <div id="courseStatsModal" class="modal" style="display:none;">
        <div class="modal-content course-stats-modal">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3><i class="fas fa-chart-line"></i> Course Performance Summary</h3>
                <button onclick="closeCourseStatsModal()"
                    style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
            </div>
            <div id="courseStatsContent"></div>
            <div class="modal-actions">
                <button class="btn" onclick="closeCourseStatsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Submissions Stats Modal -->
    <div id="submissionsStatsModal" class="modal" style="display:none;">
        <div class="modal-content course-stats-modal">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3><i class="fas fa-check-circle"></i> Submissions Summary</h3>
                <button onclick="closeSubmissionsStatsModal()"
                    style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
            </div>
            <div id="submissionsStatsContent"></div>
            <div class="modal-actions">
                <button class="btn" onclick="closeSubmissionsStatsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Average Score Modal -->
    <div id="averageScoreModal" class="modal" style="display:none;">
        <div class="modal-content course-stats-modal">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3><i class="fas fa-chart-line"></i> Course Average Scores & Pass Rates</h3>
                <button onclick="closeAverageScoreModal()"
                    style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
            </div>
            <div id="averageScoreContent"></div>
            <div class="modal-actions">
                <button class="btn" onclick="closeAverageScoreModal()">Close</button>
            </div>
        </div>
    </div>
