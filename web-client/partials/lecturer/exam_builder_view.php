            <!-- BUILDER - CODING QUESTIONS ONLY -->
            <section id="view-builder" class="view">
                <div class="panel">
                    <div class="panel-title">
                        ✏️ Exam Builder
                        <small id="builderMeta">Create New Exam</small>
                    </div>
                    <div class="crumb" id="builderCrumb">Home / Create Exam</div>

                    <!-- REQUIRED FIELDS WARNING -->
                    <div class="readonly-warning" style="margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><strong>Note:</strong> Fields marked with <span style="color:#ef4444;">*</span> are
                            required. You must fill all required fields before publishing the exam.</span>
                    </div>

                    <!-- STICKY ACTION BUTTONS -->
                    <div class="sticky-actions">
                        <button class="btn primary" onclick="saveDraftExam()">
                            <i class="fas fa-save"></i> Save Draft
                        </button>
                        <button class="btn warn" onclick="publishExam()">
                            <i class="fas fa-check-circle"></i> Publish Exam
                        </button>
                        <button class="btn primary" onclick="previewExam()">
                            <i class="fas fa-eye"></i> Preview Exam
                        </button>
                        <button class="btn" onclick="toggleShuffle()" id="shuffleBtn">
                            <i class="fas fa-random"></i> <span id="shuffleBtnText">Shuffle Questions: OFF</span>
                        </button>
                        <button class="btn danger" onclick="deleteCurrentExam()">
                            <i class="fas fa-trash-alt"></i> Delete Exam
                        </button>
                    </div>

                    <!-- ==================== SECTION 1: BASIC EXAM INFO ==================== -->
                    <div class="panel" style="margin-bottom: 20px;">
                        <div class="panel-title">📋 Basic Exam Information</div>
                        <div class="rowgrid">
                            <!-- Exam Title -->
                            <div class="field required">
                                <label>📌 Exam Title <span class="required-star"></span></label>
                                <input id="bTitle" type="text"
                                    placeholder="e.g., Final Examination - Introduction to Programming"
                                    onchange="updateExamField('title', this.value)">
                            </div>

                            <!-- Course Code -->
                            <div class="field required">
                                <label>📚 Course Code <span class="required-star"></span></label>
                                <input id="bCode" type="text" placeholder="e.g., CS101"
                                    onchange="updateExamField('courseCode', this.value); if (document.getElementById('enrolledStudentsListContainer')?.style.display !== 'none') loadEnrolledStudentsForExam();">
                                <small>Only students enrolled in this course can access this exam</small>
                            </div>

                            <!-- Exam Code -->
                            <div class="field" style="display:none;">
                                <label>🔢 Exam Code</label>
                                <input type="text" id="exam_code" placeholder="e.g., EXAM-2024-001"
                                    onchange="updateExamField('exam_code', this.value)">
                                <small>Optional custom exam code</small>
                            </div>

                            <!-- Exam Schedule -->
                            <div class="field">
                                <label>Exam Date</label>
                                <input id="bExamDate" type="date"
                                    onchange="syncStartDateTimeFromParts(); syncEndTimeFromDuration(); syncCutoffFromGrace();">
                                <small>Calendar date for this examination</small>
                            </div>

                            <div class="field">
                                <label>Start Time</label>
                                <input id="bStartTime" type="time"
                                    onchange="syncStartDateTimeFromParts(); syncEndTimeFromDuration(); syncCutoffFromGrace();">
                                <small>Time students may begin writing</small>
                            </div>

                            <!-- Duration -->
                            <div class="field required">
                                <label>⏱️ Duration (minutes) <span class="required-star"></span></label>
                                <input id="bDuration" type="number" min="1" value="180" placeholder="180"
                                    onchange="updateExamField('durationMins', parseInt(this.value) || 180); syncEndTimeFromDuration(); syncCutoffFromGrace();">
                            </div>

                            <!-- Start Date/Time -->
                            <div class="field">
                                <label>📅 Start Date/Time</label>
                                <input id="bStartAt" type="datetime-local"
                                    onchange="updateExamField('startAtISO', this.value); syncSchedulePartsFromStart(); syncDurationFromStartEnd(); syncCutoffFromGrace();">
                                <small>Leave empty for immediate availability</small>
                            </div>

                            <div class="field">
                                <label>End Date/Time</label>
                                <input id="bEndAt" type="datetime-local"
                                    onchange="updateExamField('endAtISO', this.value); syncDurationFromStartEnd(); syncCutoffFromGrace();">
                                <small>Leave empty to use the duration from the start time</small>
                            </div>

                            <div class="field">
                                <label>Grace Period (Optional)</label>
                                <input id="bGracePeriod" type="number" min="0" value="0" placeholder="0"
                                    onchange="updateExamField('gracePeriodMinutes', parseInt(this.value) || 0); syncCutoffFromGrace();">
                                <small>Extra minutes allowed after the exam end before final cut-off</small>
                            </div>

                            <div class="field">
                                <label>Cut-Off Date and Time</label>
                                <input id="bCutoffAt" type="datetime-local"
                                    onchange="updateExamField('cutoffAtISO', this.value);">
                                <small>Students cannot submit after this time unless you extend it</small>
                            </div>

                            <!-- Exam Password -->
                            <div class="field">
                                <label>🔐 Exam Password</label>
                                <div class="password-field-wrap">
                                    <input type="password" id="examPassword" placeholder="Create a password for students"
                                        autocomplete="new-password"
                                        onchange="updateExamField('exam_password', this.value)">
                                    <button type="button" class="password-toggle-btn" onclick="toggleExamPasswordVisibility()" aria-label="View password">
                                        <i id="examPasswordEye" class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small>Students must enter this password to start the exam (optional)</small>
                            </div>

                            <!-- Questions to answer -->
                            <div class="field">
                                <label>📝 Questions to answer</label>
                                <input id="bQuestionsToAnswer" type="number" min="0" value="0"
                                    onchange="updateExamField('questionsToAnswer', parseInt(this.value) || 0)"
                                    oninput="updateExamField('questionsToAnswer', parseInt(this.value) || 0)">
                                <div class="hint">0 = all questions, or specify number like 5</div>
                                <div class="hint" id="questionAnswerRuleHint">Answer all questions. Total marks will update after questions are added.</div>
                            </div>
                        </div>

                        <!-- Instructions -->
                        <div class="field required">
                            <label>📝 Instructions (visible to students) <span class="required-star"></span></label>
                            <div class="structured-toolbar">
                                <button type="button" class="btn small" onclick="insertStructuredLine('bInstructions', 'number')"><i class="fas fa-list-ol"></i> Numbering</button>
                                <button type="button" class="btn small" onclick="insertStructuredLine('bInstructions', 'bullet')"><i class="fas fa-list-ul"></i> Bullets</button>
                            </div>
                            <textarea id="bInstructions" rows="3"
                                placeholder="- Answer ALL questions.&#10;- Each question carries equal marks.&#10;- Time management is important."
                                onkeydown="handleStructuredTextareaKeydown(event, this, 'instructions')"
                                onchange="updateExamField('instructions', this.value)"></textarea>
                        </div>
                    </div>

                    <!-- ==================== SECTION 2: SCHOOL INFORMATION ==================== -->
                    <div class="panel" style="margin-bottom: 20px; display:none;">
                        <div class="panel-title">🏛️ Institution Information</div>

                        <!-- School Logo -->
                        <div class="field">
                            <label>🏫 School Logo</label>
                            <input type="file" id="schoolLogo" accept="image/*"
                                onchange="handleSchoolLogoUpload(event)">
                            <div id="schoolLogoPreview" style="margin-top: 10px; display: none;">
                                <img id="schoolLogoImg"
                                    style="max-width: 100px; max-height: 100px; border-radius: 8px;">
                            </div>
                            <small>Upload your institution's logo (visible to students during exam)</small>
                        </div>

                        <div class="rowgrid">
                            <!-- School Name -->
                            <div class="field required">
                                <label>🏛️ School Name <span class="required-star"></span></label>
                                <input type="text" id="school_name" placeholder="e.g., University A+"
                                    onchange="updateExamField('school_name', this.value)">
                            </div>

                            <!-- Faculty Name -->
                            <div class="field required">
                                <label>📚 Faculty Name <span class="required-star"></span></label>
                                <input type="text" id="faculty_name"
                                    placeholder="e.g., Faculty of Science and Technology"
                                    onchange="updateExamField('faculty_name', this.value)">
                            </div>

                            <!-- Department -->
                            <div class="field required">
                                <label>📖 Department <span class="required-star"></span></label>
                                <input type="text" id="department" placeholder="e.g., Department of Computer Science"
                                    onchange="updateExamField('department', this.value)">
                            </div>

                            <!-- School Type -->
                            <div class="field required">
                                <label>🏫 School Type <span class="required-star"></span></label>
                                <select id="institution_school_type" onchange="updateExamField('school_type', this.value)">
                                    <option value="">Select School Type</option>
                                    <option value="Regular">Regular</option>
                                    <option value="Weekend">Weekend</option>
                                    <option value="Evening">Evening</option>
                                    <option value="Distance Learning">Distance Learning</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ==================== SECTION 3: ACADEMIC INFORMATION ==================== -->
                    <div class="panel" style="margin-bottom: 20px;">
                        <div class="panel-title">🎓 Academic Information</div>
                        <div class="rowgrid">
                            <div class="field required">
                                <label>School Type <span class="required-star"></span></label>
                                <select id="school_type" onchange="updateExamField('school_type', this.value)">
                                    <option value="">Select School Type</option>
                                    <option value="Regular">Regular</option>
                                    <option value="Weekend">Weekend</option>
                                    <option value="Evening">Evening</option>
                                    <option value="Distance Learning">Distance Learning</option>
                                </select>
                            </div>

                            <!-- Level -->
                            <div class="field required">
                                <label>🎓 Level <span class="required-star"></span></label>
                                <select id="level" onchange="updateExamField('level', this.value); if (document.getElementById('enrolledStudentsListContainer')?.style.display !== 'none') loadEnrolledStudentsForExam();">
                                    <option value="">Select Level</option>
                                    <option value="100">100 Level</option>
                                    <option value="200">200 Level</option>
                                    <option value="300">300 Level</option>
                                    <option value="400">400 Level</option>
                                    <option value="500">500 Level</option>
                                </select>
                            </div>

                            <!-- Semester -->
                            <div class="field required">
                                <label>📅 Semester <span class="required-star"></span></label>
                                <select id="semester" onchange="updateExamField('semester', this.value)">
                                    <option value="">Select Semester</option>
                                    <option value="First Semester">First Semester</option>
                                    <option value="Second Semester">Second Semester</option>
                                    <option value="Summer School">Summer School</option>
                                </select>
                            </div>

                            <div class="field">
                                <label>Academic Year / Session</label>
                                <input id="academic_year" type="text" placeholder="e.g., 2026/2027"
                                    onchange="updateExamField('academic_year', this.value)">
                            </div>

                            <!-- Exam Type -->
                            <div class="field required">
                                <label>📝 Exam Type <span class="required-star"></span></label>
                                <select id="exam_type" onchange="updateExamField('exam_type', this.value)">
                                    <option value="">Select Exam Type</option>
                                    <option value="End of Semester">End of Semester Examination</option>
                                    <option value="Mid-Semester">Mid-Semester Examination</option>
                                    <option value="Quiz">Quiz</option>
                                    <option value="Assignment">Assignment</option>
                                    <option value="Continuous Assessment">Continuous Assessment</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ==================== SECTION 4: GRADING OPTIONS ==================== -->
                    <div class="panel" style="margin-bottom: 20px;">
                        <div class="panel-title">⚙️ Grading Options</div>
                        <div class="field" style="max-width: 360px; margin-bottom: 15px;">
                            <label>Grading Mode</label>
                            <select id="gradingMode" onchange="setGradingMode(this.value)">
                                <option value="auto">Full Auto-grading</option>
                                <option value="manual">Full Manual grading</option>
                                <option value="hybrid">Both Auto and Manual</option>
                            </select>
                            <small>Auto scores submissions, manual leaves marking to the lecturer, and both auto-scores before lecturer review.</small>
                        </div>
                        <div style="display: none;">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="enableAutoGrading"
                                    onchange="toggleAutoGrading(this.checked)">
                                <span>🤖 Enable Automatic Grading</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="enablePartialGrading"
                                    onchange="togglePartialGrading(this.checked)">
                                <span>📊 Enable Partial Grading</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="showCorrectAnswers"
                                    onchange="toggleShowCorrectAnswers(this.checked)">
                                <span>✅ Show Correct Answers After Submission</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="allowReview" checked
                                    onchange="toggleAllowReview(this.checked)">
                                <span>🔄 Allow Review Before Submission</span>
                            </label>
                        </div>
                        <div class="hint" style="margin-top: 10px;">
                            <strong>Note:</strong> Coding questions can be auto-graded using test cases.
                        </div>
                    </div>

                    <!-- ==================== SECTION 5: STUDENT VISIBILITY ==================== -->
                    <div class="panel" style="margin-bottom: 20px;">
                        <div class="panel-title">👨🎓 Student Access Control</div>
                        <div class="field">
                            <button class="btn primary" id="toggleStudentListBtn"
                                onclick="toggleStudentVisibilityList()" style="margin-bottom: 10px;">
                                📋 Show/Hide Enrolled Students
                            </button>
                            <div id="enrolledStudentsListContainer" style="display: none; margin-top: 10px;">
                                <div style="background: var(--bg); border-radius: 12px; overflow-x: auto;">
                                    <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
                                        <thead>
                                            <tr
                                                style="background: var(--panel); border-bottom: 2px solid var(--border);">
                                                <th style="padding: 12px; text-align: left;">Student</th>
                                                <th style="padding: 12px; text-align: left;">Level</th>
                                                <th style="padding: 12px; text-align: left;">Course</th>
                                                <th style="padding: 12px; text-align: center;">Visibility</th>
                                            </tr>
                                        </thead>
                                        <tbody id="enrolledStudentsTable">
                                            <tr>
                                                <td colspan="4" style="text-align: center; padding: 40px;">
                                                    <div class="spinner" style="width: 30px; height: 30px;"></div>
                                                    Loading students...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="hint" style="margin-top: 10px;">
                                <strong>Note:</strong> Only students enrolled in this course will see the exam. Use the
                                visibility
                                controls above to hide/show the exam for specific students.
                            </div>
                        </div>
                    </div>

                    <!-- ==================== SECTION 6: QUESTIONS ==================== -->
                    <div class="questions-container">
                        <div class="panel-title" style="text-align: center; justify-content: center;">
                            📜🖋️📁📃 EXAM QUESTIONS 📜🖋️📁📃
                        </div>
                        <div id="qList" style="display: none;"></div>

                        <div id="noQuestionsMessage" class="no-questions-container">
                            <div class="no-questions-icon">📝</div>
                            <h3 class="no-questions-title">No Coding Questions Yet</h3>
                            <p class="no-questions-text">Click below to start adding coding questions.</p>
                            <div class="quick-questions">
                                <button class="quick-question-btn code" onclick="addFirstQuestion('code')">
                                    <i class="fas fa-code" style="margin-right: 8px;"></i> Add Coding Question
                                </button>
                                <button class="quick-question-btn code" onclick="openQuestionBankModal()">
                                    <i class="fas fa-database" style="margin-right: 8px;"></i> Question Bank
                                </button>
                            </div>
                        </div>

                        <div id="qtypeButtonBar" class="qtype-button-bar">
                            <div class="qtype-button-bar-title">
                                <i class="fas fa-plus-circle" style="margin-right: 8px; color: var(--blue);"></i>
                                Add More Coding Questions
                            </div>
                            <div class="qtype-buttons">
                                <button class="qtype-btn code" onclick="addQuestion('code')">
                                    <i class="fas fa-code"></i> Coding Question
                                </button>
                                <button class="qtype-btn code" onclick="openQuestionBankModal()">
                                    <i class="fas fa-database"></i> Question Bank
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="hint" style="margin-top: 20px; text-align: center;">
                        <strong>💡 Tip:</strong> Click on the coding question button above to add a coding question. You
                        can
                        reorder, duplicate, or delete questions as needed.
                    </div>
                </div>
            </section>

