            <!-- DASHBOARD -->
            <section id="view-dashboard" class="view active">
                <div class="panel">
                    <div class="panel-title">📊 Dashboard Overview <small>Real-time statistics</small></div>

                    <!-- Course Selector -->
                    <div class="dashboard-course-selector">
                        <label><i class="fas fa-chart-line"></i> View Analytics For:</label>
                        <select id="dashboardCourseSelect">
                            <option value="all">All Courses (Overall)</option>
                        </select>
                        <button onclick="updateDashboardWithCourse()">
                            <i class="fas fa-sync-alt"></i> Apply & Update
                        </button>
                        <button onclick="refreshAllDashboardStats()"
                            style="background: linear-gradient(135deg, #6b7280, #4b5563);">
                            <i class="fas fa-redo-alt"></i> Refresh All
                        </button>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card blue" onclick="showCourseStatsModal()" style="cursor: pointer;">
                            <div class="stat-icon"><i class="fas fa-users"></i></div>
                            <div class="stat-label">Total Students</div>
                            <div class="stat-value" id="statTotalStudents">0</div>
                            <div class="progress">
                                <div class="progress-bar" id="studentProgressBar" style="width:0%"></div>
                            </div>
                            <small id="statActiveStudents">Active: 0 | Inactive: 0</small>
                        </div>
                        <div class="stat-card green" onclick="go('exams')" style="cursor: pointer;">
                            <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                            <div class="stat-label">Total Exams</div>
                            <div class="stat-value" id="statTotalExams">0</div>
                            <div class="progress">
                                <div class="progress-bar" id="examProgressBar" style="width:0%"></div>
                            </div>
                            <small id="statExamStatus">Published: 0 </small>
                        </div>
                        <div class="stat-card purple" onclick="showSubmissionsStatsModal()" style="cursor: pointer;">
                            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="stat-label">Submissions</div>
                            <div class="stat-value" id="statTotalSubmissions">0</div>
                            <div class="progress">
                                <div class="progress-bar" id="submissionProgressBar" style="width:0%"></div>
                            </div>
                            <small id="statSubmissionStatus">Marked: 0 | Pending: 0</small>
                        </div>
                        <div class="stat-card orange" onclick="showAverageScoreModal()" style="cursor: pointer;">
                            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                            <div class="stat-label">Average Score</div>
                            <div class="stat-value" id="statAvgScore">0%</div>
                            <div class="progress">
                                <div class="progress-bar" id="avgScoreBar" style="width:0%"></div>
                            </div>
                            <small>Pass Rate: <span id="statPassRate">0</span>%</small>
                        </div>
                    </div>

                    <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr);">
                        <div class="stat-card">
                            <div class="stat-label">🎓 PU Grading System Distribution</div>
                            <div style="overflow-x: auto; margin-top: 15px;">
                                <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                                    <thead>
                                        <tr style="border-bottom: 2px solid var(--border);">
                                            <th style="text-align: left; padding: 8px 5px;">Grade</th>
                                            <th style="text-align: left; padding: 8px 5px;">Mark Range</th>
                                            <th style="text-align: left; padding: 8px 5px;">Description</th>
                                            <th style="text-align: left; padding: 8px 5px;">GP</th>
                                            <th style="text-align: center; padding: 8px 5px;">Count</th>
                                            <th style="text-align: center; padding: 8px 5px;">%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #10b981;">A</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">80-100</span></td>
                                            <td style="padding: 6px 5px;">Excellent</span></td>
                                            <td style="padding: 6px 5px;">4.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeACount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeAPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #34d399;">B+</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">75-79</span></td>
                                            <td style="padding: 6px 5px;">Very Good</span></td>
                                            <td style="padding: 6px 5px;">3.5</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeBplusCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeBplusPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #3b82f6;">B</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">70-74</span></td>
                                            <td style="padding: 6px 5px;">Good</span></td>
                                            <td style="padding: 6px 5px;">3.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="GradeBCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeBPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #8b5cf6;">C+</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">65-69</span></td>
                                            <td style="padding: 6px 5px;">Average</span></td>
                                            <td style="padding: 6px 5px;">2.5</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeCplusCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeCplusPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #f59e0b;">C</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">60-64</span></td>
                                            <td style="padding: 6px 5px;">Fair</span></td>
                                            <td style="padding: 6px 5px;">2.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="GradeCCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeCPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #f97316;">D+</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">55-59</span></td>
                                            <td style="padding: 6px 5px;">Barely Satisfactory</span></td>
                                            <td style="padding: 6px 5px;">1.5</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeDplusCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeDplusPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #fbbf24;">D</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">50-54</span></td>
                                            <td style="padding: 6px 5px;">Weak Pass</span></td>
                                            <td style="padding: 6px 5px;">1.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="GradeDCount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeDPercent">0</span>%</span></td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 6px 5px;"><strong style="color: #ef4444;">E</strong>
                                            </td>
                                            <td style="padding: 6px 5px;">0-49</span></td>
                                            <td style="padding: 6px 5px;">Fail</span></td>
                                            <td style="padding: 6px 5px;">0.0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="GradeECount">0</span></td>
                                            <td style="padding: 6px 5px; text-align: center;"><span
                                                    id="gradeEPercent">0</span>%</span></td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr style="border-top: 2px solid var(--border); background: var(--bg);">
                                            <td colspan="4" style="padding: 8px 5px;"><strong>Total</strong></td>
                                            <td style="padding: 8px 5px; text-align: center;"><strong
                                                    id="totalGradedStudents">0</strong></td>
                                            <td style="padding: 8px 5px; text-align: center;"><strong>100%</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">📊 Performance Metrics</div>
                            <div style="margin-top: 15px;">
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>🏆 Highest Score</span>
                                    <span id="statHighestScore" style="font-weight: 700; color: #10b981;">0%</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>📉 Lowest Score</span>
                                    <span id="statLowestScore" style="font-weight: 700; color: #ef4444;">0%</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>📊 Mean Score</span>
                                    <span id="statMeanScore"
                                        style="font-weight: 700; color: var(--accent-blue);">0%</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>📈 Median Score</span>
                                    <span id="statMedianScore"
                                        style="font-weight: 700; color: var(--accent-blue);">0%</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>📐 Standard Deviation</span>
                                    <span id="statStdDev" style="font-weight: 700;">0</span>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-bottom: 12px; padding: 10px; background: var(--bg); border-radius: 8px;">
                                    <span>✅ Pass Rate (≥50%)</span>
                                    <span id="statPassRateMetric" style="font-weight: 700; color: #10b981;">0%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="charts-row">
                        <div class="chart-card">
                            <h3>📈 Performance Trend</h3>
                            <div class="chart-container">
                                <canvas id="performanceLineChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3>🔔 Grade Distribution (Bell Curve)</h3>
                            <div class="chart-container">
                                <canvas id="gradeBellCurve"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="charts-row">
                        <div class="chart-card">
                            <h3>📊 Score Correlation Analysis</h3>
                            <div class="chart-container">
                                <canvas id="correlationScatterChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3>📉 Regression Analysis</h3>
                            <div class="chart-container">
                                <canvas id="regressionChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="table-section">
                        <div class="table-header"
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                            <h3>📋 Recent Submissions</h3>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <select id="recentSubmissionsCourseFilter"
                                    style="padding: 8px; border-radius: 8px; border: 1px solid var(--border); ">
                                    <option value="all">All Courses</option>
                                </select>
                                <select id="recentSubmissionsPeriodFilter"
                                    style="padding: 8px; border-radius: 8px; border: 1px solid var(--border); ">
                                    <option value="day">Today</option>
                                    <option value="week">This Week</option>
                                    <option value="month">This Month</option>
                                    <option value="year">This Year</option>
                                    <option value="all">All Time</option>
                                </select>
                                <button class="btn btn-outline"
                                    onclick="updateRecentSubmissionsWithFilters()">Apply</button>
                                <button class="btn btn-outline" onclick="go('submissions')">View All
                                    Submissions</button>
                            </div>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Course</th>
                                        <th>Score</th>
                                        <th>Grade</th>
                                        <th>Grade Point</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody id="recentSubmissionsTable">
                                    <tr>
                                        <td colspan="6" style="text-align:center">Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

