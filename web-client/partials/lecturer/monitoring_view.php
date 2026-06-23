            <!-- MONITORING -->
            <section id="view-monitoring" class="view">
                <div class="panel">
                    <div class="panel-title">👁️ Live Monitoring <small>Real-time student screens</small></div>
                    <div class="crumb">Home / Monitoring</div>

                    <div class="toolbar">
                        <select id="monitoringExamSelect" onchange="loadMonitoringData()">
                            <option value="">Select Exam to Monitor</option>
                        </select>
                        <button class="btn danger" onclick="sendWarningToAll()">⚠️ Send Warning to All</button>
                        <button class="btn danger" onclick="lockAllScreens()">🔒 Lock All Screens</button>
                        <button class="btn primary" onclick="refreshMonitoring()">🔄 Refresh</button>
                    </div>

                    <div class="toolbar" style="margin-top:12px;">
                        <button class="btn warn" onclick="controlMonitoringExam('pause')"><i class="fas fa-pause"></i> Pause Exam</button>
                        <button class="btn ok" onclick="controlMonitoringExam('resume')"><i class="fas fa-play"></i> Resume Exam</button>
                        <button class="btn primary" onclick="promptAddMonitoringTime()"><i class="fas fa-plus"></i> Add Time</button>
                        <button class="btn" onclick="promptExtendMonitoringCutoff()"><i class="fas fa-calendar-plus"></i> Extend Cut-Off</button>
                        <button class="btn" onclick="promptMonitoringAnnouncement()"><i class="fas fa-bullhorn"></i> Send Announcement</button>
                        <button class="btn danger" onclick="forceSubmitSelectedMonitoringStudent()"><i class="fas fa-paper-plane"></i> Force Submit Selected Student</button>
                    </div>

                    <div id="monitoringStats" class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Total Registered</div>
                            <div class="stat-value" id="registeredStudents">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Currently Online</div>
                            <div class="stat-value" id="activeStudents">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Currently Writing</div>
                            <div class="stat-value" id="writingStudents">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Submitted</div>
                            <div class="stat-value" id="completedStudents">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Disconnected</div>
                            <div class="stat-value" id="disconnectedStudents">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Flagged</div>
                            <div class="stat-value" id="cheatingCount">0</div>
                        </div>
                    </div>

                    <div id="studentMonitoringGrid" class="student-grid"></div>
                </div>
            </section>

