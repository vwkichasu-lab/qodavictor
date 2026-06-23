            <!-- STUDENT DETAILS PAGE -->
            <section id="view-student-details" class="view">
                <div class="panel">
                    <div class="panel-title">📋 Student Details <small>Complete student information</small></div>
                    <div class="crumb">Home / Student Details</div>

                    <div
                        style="background: var(--bg); border-radius: 12px; padding: 15px; margin-bottom: 20px; border: 1px solid var(--border);">
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-search"></i> Search</label>
                                <input type="text" id="studentDetailsSearchInput" class="form-input"
                                    placeholder="Search by ID, Name, Programme...">
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-graduation-cap"></i> Level</label>
                                <select id="studentDetailsLevelFilter" class="form-input">
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
                                <select id="studentDetailsProgrammeFilter" class="form-input">
                                    <option value="all">All Programmes</option>
                                    <option value="Computer Science">Computer Science</option>
                                    <option value="Information Technology">Information Technology</option>
                                    <option value="SIndustal Software Engineering">Industral Software Engineering
                                    </option>
                                    <option value="NCCE">NCCE</option>
                                    <option value="Pre Engineering">Pre Engineering</option>
                                    <option value="Health Information Management">Health Information Management</option>
                                </select>
                            </div>
                            <div class="field" style="margin-bottom: 0;">
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="studentDetailsStatusFilter" class="form-input">
                                    <option value="all">All Status</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                            <button type="button" class="btn" onclick="applyStudentDetailsFilters()">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <button type="button" class="btn btn-outline" onclick="resetStudentDetailsFilters()">
                                <i class="fas fa-undo"></i> Reset Filters
                            </button>
                            <button class="btn ok" onclick="exportStudentDetailsToExcel()">
                                <i class="fas fa-file-excel"></i> Export to Excel
                            </button>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="table" id="studentDetailsTable">
                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Student ID</th>
                                    <th>Full Name</th>
                                    <th>Level</th>
                                    <th>Programme</th>
                                    <th>Course Name</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentDetailsBody">
                                <tr>
                                    <td colspan="11" style="text-align:center">Loading......</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

