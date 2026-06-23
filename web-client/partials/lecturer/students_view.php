            <!-- STUDENTS PAGE -->
            <section id="view-students" class="view">
                <div class="panel">
                    <div class="panel-title">👥 Student Management <small>Add, Edit, Remove, Import, Export
                            students</small></div>
                    <div class="crumb">Home / Students</div>

                    <div
                        style="background: var(--bg); border-radius: 12px; padding: 15px; margin-bottom: 20px; border: 1px solid var(--border);">
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-search"></i> Search</label>
                                <input type="text" id="studentSearchInput" class="form-input"
                                    placeholder="Search by name or ID...">
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-graduation-cap"></i> Level</label>
                                <select id="levelFilter" class="form-input">
                                    <option value="all">All Levels</option>
                                    <option value="100">100 Level</option>
                                    <option value="200">200 Level</option>
                                    <option value="300">300 Level</option>
                                    <option value="400">400 Level</option>
                                    <option value="500">500 Level</option>
                                </select>
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-building"></i> Programme</label>
                                <select id="programmeFilter" class="form-input">
                                    <option value="all">All Programmes</option>
                                    <option value="Computer Science">Computer Science</option>
                                    <option value="Information Technology">Information Technology</option>
                                    <option value="Industrial Software Engineering">Industrial Software Engineering
                                    </option>
                                    <option value="NCCE">NCCE</option>
                                    <option value="Pre Engineering">Pre Engineering</option>
                                    <option value="Health Information Management">Health Information Management</option>
                                </select>
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="statusFilter" class="form-input">
                                    <option value="all">All Status</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div
                            style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap; justify-content: space-between;">
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button class="btn primary" onclick="showAddStudentModal()">
                                    <i class="fas fa-plus"></i> Add Single Student
                                </button>
                                <button class="btn success" onclick="showImportModal()">
                                    <i class="fas fa-file-upload"></i> Import Students (Bulk)
                                </button>
                                <button type="button" class="btn" onclick="applyFilters()">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <button type="button" class="btn btn-outline" onclick="resetFilters()">
                                    <i class="fas fa-undo"></i> Reset Filters
                                </button>
                                <button type="button" class="btn warn" onclick="showActiveStudentSessionsModal()">
                                    <i class="fas fa-desktop"></i> Active Sessions
                                </button>
                                <button type="button" class="btn primary" onclick="bulkMigrateShownStudents()">
                                    <i class="fas fa-level-up-alt"></i> Migrate All Shown
                                </button>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button class="btn ok" onclick="exportStudentsToExcelWithTemplate()">
                                    <i class="fas fa-file-excel"></i> Export to Excel
                                </button>
                                <button class="btn info" onclick="downloadImportTemplate()">
                                    <i class="fas fa-download"></i> Download Template
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Student ID</th>
                                    <th>Full Name</th>
                                    <th>Level</th>
                                    <th>Programme</th>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentsTableBody">
                                <tr>
                                    <td colspan="9" style="text-align:center; padding:40px;">Loading students...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

