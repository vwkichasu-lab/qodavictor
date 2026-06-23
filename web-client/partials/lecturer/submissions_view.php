            <!-- SUBMISSIONS -->
            <section id="view-submissions" class="view">
                <div class="panel">
                    <div class="panel-title">📤 Submissions <small>Student answers + auto scores</small></div>
                    <div class="crumb">Home / Submissions</div>

                    <!-- Calculator Modal -->
                    <div id="calculatorModal" class="modal" style="display:none;">
                        <div class="modal-content" style="width: 450px;">
                            <h3><i class="fas fa-calculator"></i> Grade Calculator</h3>
                            <div class="field">
                                <label>Select Student:</label>
                                <select id="calcStudentSelect"
                                    style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border);">
                                    <option value="">-- Select Student --</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Class Score (max 40):</label>
                                <input type="number" id="calcClassScoreField" min="0" max="40" step="0.5" value="0">
                            </div>
                            <div class="field">
                                <label>Exam Score (max 60):</label>
                                <input type="number" id="calcExamScoreField" min="0" max="60" step="0.5" value="0">
                            </div>
                            <div class="divider"></div>
                            <div style="background: var(--bg); padding: 15px; border-radius: 12px; text-align: center;">
                                <h4>Total Score: <span id="calcTotalScore"
                                        style="color: #3b82f6; font-size: 28px;">0</span> / 100</h4>
                                <h4>Grade: <span id="calcGradeDisplay" style="color: #10b981;">E</span></h4>
                                <div id="calcGradePointDisplay">Grade Point: 0.0</div>
                            </div>
                            <div class="modal-actions">
                                <button class="btn" onclick="closeCalculatorModal()">Close</button>
                                <button class="btn primary" onclick="updateCalculatorDisplay()">Calculate</button>
                            </div>
                        </div>
                    </div>

                    <!-- Print Modal -->
                    <div id="printModal" class="modal" style="display:none;">
                        <div class="modal-content" style="width: 800px; max-width: 95%;">
                            <h3><i class="fas fa-print"></i> Print Score Sheet</h3>
                            <div class="field">
                                <label>Select Course:</label>
                                <select id="printCourseSelect"
                                    style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid var(--border);">
                                    <option value="">-- Select Course --</option>
                                </select>
                            </div>
                            <div class="modal-actions">
                                <button class="btn" onclick="closePrintModal()">Cancel</button>
                                <button class="btn primary" onclick="generatePrintPreview()">Generate Score
                                    Sheet</button>
                            </div>
                        </div>
                    </div>

                    <!-- Print Preview Modal -->
                    <div id="printPreviewModal" class="modal" style="display:none;">
                        <div class="modal-content"
                            style="width: 1000px; max-width: 95%; max-height: 90vh; overflow-y: auto;">
                            <div id="printPreviewContent"></div>
                            <div class="modal-actions">
                                <button class="btn" onclick="closePrintPreviewModal()">Close</button>
                                <button class="btn primary" onclick="printScoreSheet()">Print / Save as PDF</button>
                            </div>
                        </div>
                    </div>


                    <!-- Submissions Container -->
                    <div id="submissionsContainer"></div>
                </div>
            </section>

