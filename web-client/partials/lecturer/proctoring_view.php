<!-- PROCTORING (Enhanced) -->
            <section id="view-proctoring" class="view">
                <div class="panel">
                    <div class="panel-title">
                        <i class="fas fa-video"></i> Proctoring
                        <small>AI-powered exam supervision with live screen sharing</small>
                    </div>
                    <div class="crumb">Home / Proctoring</div>

                    <div class="proctoring-control-panel">
                        <div class="proctoring-select-card">
                            <label for="proctoringExamSelect"><i class="fas fa-clipboard-list"></i> Select Exam To Proctor</label>
                            <select id="proctoringExamSelect" class="proctoring-exam-select" onchange="loadProctoringData()">
                                <option value="">Select Exam to Proctor</option>
                            </select>
                        </div>
                        <div class="proctoring-actions-card">
                            <div class="proctoring-actions-title">Live Proctoring Controls</div>
                            <div class="proctoring-button-grid">
                                <button class="btn primary" onclick="startProctoring()">
                                    <i class="fas fa-play"></i> Start
                                </button>
                                <button class="btn warn" onclick="controlProctoringExam('pause')">
                                    <i class="fas fa-pause"></i> Pause Exam
                                </button>
                                <button class="btn ok" onclick="controlProctoringExam('resume')">
                                    <i class="fas fa-play"></i> Resume Exam
                                </button>
                                <button class="btn primary" onclick="promptAddProctoringTimeAll()">
                                    <i class="fas fa-clock"></i> Add Time To All
                                </button>
                                <button class="btn" onclick="promptProctoringMessageAll()">
                                    <i class="fas fa-bullhorn"></i> Message All
                                </button>
                                <button class="btn ok" onclick="unlockAllScreens()">
                                    <i class="fas fa-unlock"></i> Unlock All
                                </button>
                                <button class="btn warn" onclick="sendWarningToAllProctoring()">
                                    <i class="fas fa-exclamation-triangle"></i> Warn All
                                </button>
                                <button class="btn" onclick="refreshProctoring()">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                                <button class="btn" onclick="viewViolationEvidence()">
                                    <i class="fas fa-folder-open"></i> Evidence
                                </button>
                                <button class="btn info" onclick="reviewProctoredCourses()">
                                    <i class="fas fa-history"></i> Review Courses
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Proctoring Stats -->
                    <div id="proctoringStats" class="stats-grid" style="margin-bottom: 20px;">
                        <div class="stat-card blue">
                            <div class="stat-icon"><i class="fas fa-users"></i></div>
                            <div class="stat-label">Students Writing</div>
                            <div class="stat-value" id="studentsWriting">0</div>
                        </div>
                        <div class="stat-card green">
                            <div class="stat-icon"><i class="fas fa-desktop"></i></div>
                            <div class="stat-label">Screens Sharing</div>
                            <div class="stat-value" id="screensSharing">0</div>
                        </div>
                        <div class="stat-card orange">
                            <div class="stat-icon"><i class="fas fa-eye-slash"></i></div>
                            <div class="stat-label">Screens NOT Sharing</div>
                            <div class="stat-value" id="screensNotSharing">0</div>
                        </div>
                        <div class="stat-card purple">
                            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div class="stat-label">Violations</div>
                            <div class="stat-value" id="violationCount">0</div>
                        </div>
                    </div>

                    <div id="lockedStudentsPanel" class="qcard" style="display:none; margin-bottom: 20px; padding: 16px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;">
                            <strong style="color:var(--text);"><i class="fas fa-lock"></i> Locked Screens</strong>
                            <small style="color:var(--muted);">Click a student to review the evidence that led to the lock.</small>
                        </div>
                        <div id="lockedStudentsList" style="display:flex; flex-wrap:wrap; gap:10px;"></div>
                    </div>

                    <!-- Student Grid View -->
                    <div id="proctoringGrid" class="student-grid"
                        style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; align-items: start;">
                        <div style="text-align: center; padding: 60px; grid-column: 1/-1;">
                            <i class="fas fa-desktop"
                                style="font-size: 48px; color: var(--muted); margin-bottom: 15px;"></i>
                            <p style="color: var(--muted);">Select an exam and start proctoring to see student screens
                            </p>
                        </div>
                    </div>
                </div>
            </section>

