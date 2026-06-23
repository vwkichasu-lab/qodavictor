            <!-- PROFILE -->
            <section id="view-profile" class="view">
                <div class="panel">
                    <div class="panel-title">👤 Profile</div>
                    <div class="crumb">Home / Profile</div>

                    <div class="rowgrid">
                        <div class="field">
                            <label>Profile Picture</label>
                            <input type="file" id="profilePicInput" accept="image/*"
                                onchange="previewProfilePic(event)" />
                            <div style="margin-top:10px">
                                <img id="profilePicDisplay"
                                    src="<?php echo isset($lecturerData['profile_pic']) ? htmlspecialchars($lecturerData['profile_pic']) : ''; ?>"
                                    style="max-width:150px; border-radius:10%; display:<?php echo empty($lecturerData['profile_pic']) ? 'none' : 'block'; ?>;" />
                            </div>
                            <small style="font-size: 11px; color: var(--muted);">Click to change profile picture</small>
                        </div>

                        <div class="field">
                            <label>Full Name <span style="color: var(--success);">(Editable)</span></label>
                            <input id="profileName"
                                value="<?php echo isset($lecturerData['full_name']) ? htmlspecialchars($lecturerData['full_name']) : ''; ?>"
                                placeholder="Not assigned yet" />
                        </div>
                    </div>

                    <div style="background: var(--bg); border-radius: 12px; padding: 15px; margin: 15px 0;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                            <i class="fas fa-user-edit" style="color: var(--success);"></i>
                            <span style="font-size: 13px; font-weight: 600; color: var(--success);">You can update your lecturer profile details here.</span>
                        </div>
                        <div class="rowgrid">
                            <div class="field">
                                <label>Staff ID</label>
                                <input id="profileStaffId"
                                    value="<?php echo isset($lecturerData['staff_id']) ? htmlspecialchars($lecturerData['staff_id']) : ''; ?>"
                                    class="form-input" placeholder="e.g., PULC/IT/00001" readonly />
                                <small style="font-size: 11px; color: var(--muted);">Staff ID is your permanent lecturer identifier.</small>
                            </div>
                            <div class="field">
                                <label>Department</label>
                                <input id="profileDepartment"
                                    value="<?php echo isset($lecturerData['department']) ? htmlspecialchars($lecturerData['department']) : ''; ?>"
                                    class="form-input" placeholder="e.g., Computer Science" />
                            </div>
                            <div class="field">
                                <label>Faculty</label>
                                <input id="profileFaculty"
                                    value="<?php echo isset($lecturerData['faculty']) ? htmlspecialchars($lecturerData['faculty']) : ''; ?>"
                                    class="form-input" placeholder="e.g., Science and Technology" />
                            </div>
                            <div class="field">
                                <label>Email <span style="color: var(--success);">(Editable)</span></label>
                                <input id="profileEmail" type="email"
                                    value="<?php echo isset($lecturerData['email']) ? htmlspecialchars($lecturerData['email']) : ''; ?>"
                                    placeholder="Not assigned yet" />
                            </div>
                        </div>
                    </div>

                    <div class="panel-title">📚 Teaching Assignments <span
                            style="font-size: 12px; color: var(--success);">(Editable)</span></div>
                    <div class="rowgrid">
                        <div class="field">
                            <label>Levels Taught</label>
                            <input id="profileLevels"
                                value="<?php echo isset($lecturerData['levels_taught']) ? htmlspecialchars($lecturerData['levels_taught']) : ''; ?>"
                                placeholder="e.g., 100, 200, 300" />
                            <small style="font-size: 11px; color: var(--muted);">Comma-separated list of levels</small>
                        </div>
                        <div class="field">
                            <label>Course Codes</label>
                            <input id="profileClasses"
                                value="<?php echo isset($lecturerData['classes']) ? htmlspecialchars($lecturerData['classes']) : ''; ?>"
                                placeholder="e.g., CS101, MATH201" />
                        </div>
                        <div class="field">
                            <label>Courses</label>
                            <textarea id="profileCourses" class="form-input" rows="3"
                                placeholder="e.g., Introduction to Programming, Data Structures"><?php echo isset($lecturerData['courses']) ? htmlspecialchars($lecturerData['courses']) : ''; ?></textarea>
                            <small style="font-size: 11px; color: var(--muted);">One course per line or
                                comma-separated</small>
                        </div>
                    </div>

                    <div style="background: var(--bg); border-radius: 12px; padding: 16px; margin: 16px 0;">
                        <div class="panel-title" style="margin-bottom: 12px;">Add Teaching Course</div>
                        <div class="rowgrid">
                            <div class="field">
                                <label>Course Code</label>
                                <input id="teachingCourseCode" class="form-input" placeholder="e.g., PBIT102" />
                            </div>
                            <div class="field">
                                <label>Course Name</label>
                                <input id="teachingCourseName" class="form-input" placeholder="e.g., C Programming" />
                            </div>
                            <div class="field">
                                <label>Level</label>
                                <select id="teachingCourseLevel" class="form-input">
                                    <option value="">Select level</option>
                                    <option value="100">Level 100</option>
                                    <option value="200">Level 200</option>
                                    <option value="300">Level 300</option>
                                    <option value="400">Level 400</option>
                                    <option value="500">Level 500</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn primary" onclick="saveTeachingCourse()" style="margin-top: 10px;">
                            <i class="fas fa-plus"></i> Add Course
                        </button>
                        <div id="teachingCoursesList" style="display:flex; flex-wrap:wrap; gap:8px; margin-top:12px;"></div>
                    </div>

                    <button class="btn primary" onclick="saveProfile()" style="margin-bottom: 30px;">
                        <i class="fas fa-save"></i> Save Profile Changes
                    </button>

                    <div class="panel-title" style="margin-top: 20px;">Add Lecturer Account</div>
                    <div style="background: var(--bg); border-radius: 12px; padding: 20px; margin-top: 10px;">
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                            <i class="fas fa-user-plus" style="color: var(--accent-blue);"></i>
                            <span style="font-size: 13px; font-weight: 600; color: var(--muted);">
                                Create another lecturer account. QODA will generate the lecturer ID and default password.
                            </span>
                        </div>
                        <div class="rowgrid">
                            <div class="field">
                                <label>Title</label>
                                <select id="newLecturerTitle" class="form-input">
                                    <option value="Mr.">Mr.</option>
                                    <option value="Mrs.">Mrs.</option>
                                    <option value="Miss">Miss</option>
                                    <option value="Dr.">Dr.</option>
                                    <option value="Prof.">Prof.</option>
                                    <option value="Rev.">Rev.</option>
                                    <option value="Ing.">Ing.</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Full Name</label>
                                <input id="newLecturerName" class="form-input" placeholder="e.g., Ama Mensah" />
                            </div>
                            <div class="field">
                                <label>Email</label>
                                <input id="newLecturerEmail" type="email" class="form-input" placeholder="e.g., ama@example.com" />
                            </div>
                            <div class="field">
                                <label>Department</label>
                                <input id="newLecturerDepartment" class="form-input" placeholder="e.g., Information Technology" />
                                <small style="font-size: 11px; color: var(--muted);">Used to generate an ID like PULC/IT/00001.</small>
                            </div>
                        </div>
                        <button class="btn primary" onclick="createLecturerAccount(event)" style="margin-top: 10px;">
                            <i class="fas fa-user-plus"></i> Create Lecturer
                        </button>
                        <div id="newLecturerResult" class="hint" style="display:none; margin-top:14px;"></div>
                    </div>

                    <div class="panel-title" style="margin-top: 20px;">🔒 Change Password</div>
                    <div style="background: var(--bg); border-radius: 12px; padding: 20px; margin-top: 10px;">
                        <div class="rowgrid">
                            <div class="field">
                                <label>Current Password</label>
                                <div style="position: relative;">
                                    <input type="password" id="currentPassword" class="form-input"
                                        placeholder="Enter your current password" style="padding-right: 45px;" />
                                    <button type="button" onclick="togglePasswordVisibility('currentPassword')"
                                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted);">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="field">
                                <label>New Password</label>
                                <div style="position: relative;">
                                    <input type="password" id="newPassword" class="form-input"
                                        placeholder="Enter new password (min 6 characters)"
                                        style="padding-right: 45px;" />
                                    <button type="button" onclick="togglePasswordVisibility('newPassword')"
                                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted);">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="field">
                                <label>Confirm New Password</label>
                                <div style="position: relative;">
                                    <input type="password" id="confirmPassword" class="form-input"
                                        placeholder="Confirm new password" style="padding-right: 45px;" />
                                    <button type="button" onclick="togglePasswordVisibility('confirmPassword')"
                                        style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted);">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="hint" style="margin-top: 10px;">
                            <i class="fas fa-info-circle"></i> Password must be at least 6 characters long.
                        </div>
                        <button class="btn primary" onclick="changeLecturerPassword()" style="margin-top: 15px;">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </div>

                    <div class="hint" style="margin-top: 20px;">
                        <strong>Note:</strong> Lecturer accounts now manage their own profile details.
                    </div>
                    <button class="btn danger" onclick="deleteLecturerAccount()" style="margin-top: 14px;">
                        <i class="fas fa-user-times"></i> Delete My Account
                    </button>
                </div>
            </section>
