    // ============================================
    // 6. STUDENT MANAGEMENT FUNCTIONS
    // ============================================

    function getStudents() {
        return readJSON(K_STUDENTS, []);
    }

    function saveStudents(students) {
        writeJSON(K_STUDENTS, students);
    }

    async function loadStudents() {
        try {
            const result = await apiRequest('get_students');
            if (result.success && result.data) {
                allStudents = result.data;
                allStudentsDetails = result.data;
                filteredStudents = [...allStudents];
                filteredStudentDetails = [...allStudentsDetails];
                renderStudentsTable();
                renderStudentDetailsTable();
            } else {
                renderStudentsTableEmpty();
                renderStudentDetailsTableEmpty();
            }
        } catch (error) {
            console.error('Error loading students:', error);
            renderStudentsTableEmpty();
            renderStudentDetailsTableEmpty();
        }
    }

    function renderStudentsTable() {
        const tbody = document.getElementById('studentsTableBody');
        if (!tbody) return;

        if (!filteredStudents || filteredStudents.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="9" style="text-align:center; padding:40px;">👥 No students found. Click "Add New Student" or "Import Students" to get started.您</td></tr>';
            return;
        }

        tbody.innerHTML = filteredStudents.map((s, index) => {
            const statusClass = s.status === 'Active' ? 'status-active' : 'status-inactive';

            // Get course information correctly
            let courseCode = s.course_code || '—';
            let courseName = s.course_name || '—';
            let enrolledCoursesList = s.enrolled_courses_names || s.enrolled_courses || '—';

            // If enrolled_courses_names exists and has multiple courses, show tooltip
            const hasMultipleCourses = enrolledCoursesList !== '—' && enrolledCoursesList.includes(',');
            const courseDisplay = hasMultipleCourses ?
                `<span title="Enrolled in: ${escapeHTML(enrolledCoursesList)}" style="cursor: help;">
                ${escapeHTML(courseCode)} <i class="fas fa-info-circle" style="font-size: 11px; color: var(--blue);"></i>
             </span>` :
                escapeHTML(courseCode);

            return `
            <tr class="student-row">
                <td>${index + 1}</td>
                <td><span class="tag">${escapeHTML(s.student_id || '—')}</span></td>
                <td><b>${escapeHTML(s.full_name || '—')}</b></td>
                <td>${escapeHTML(s.level || '—')}</td>
                <td>${escapeHTML(s.programme || '—')}</td>
                <td><code style="background: var(--bg); padding: 4px 8px; border-radius: 6px; font-size: 12px;">${courseDisplay}</code></td>
                <td><small>${escapeHTML(courseName)}</small></td>
                <td><span class="tag ${statusClass}">${s.status || 'Active'}</span></td>
                <td class="action-buttons" style="white-space: nowrap;">
                    <button class="action-btn" onclick="viewStudentCourses(${s.id})" title="View All Courses"><i class="fas fa-book"></i></button>
                    <button class="action-btn" onclick="editStudentById(${s.id})" title="Edit Student"><i class="fas fa-edit"></i></button>
                    <button class="action-btn" onclick="showMigrateLevelModal(${s.id}, '${escapeHTML(s.full_name)}', '${escapeHTML(s.level || '')}')" title="Migrate Level"><i class="fas fa-level-up-alt"></i></button>
                    <button class="action-btn" onclick="showEnrollModal(${s.id}, '${escapeHTML(s.full_name)}')" title="Enroll in Additional Course"><i class="fas fa-plus-circle"></i></button>
                    <button class="action-btn" onclick="resetStudentPasswordById(${s.id})" title="Reset Password"><i class="fas fa-key"></i></button>
                    <button class="action-btn" onclick="deleteStudentById(${s.id})" title="Delete" style="color: #ef4444;"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        }).join('');
    }

    async function loadStudents() {
        try {
            const result = await apiRequest('get_students');
            if (result.success && result.data) {
                // The API now returns course information directly
                allStudents = result.data;
                allStudentsDetails = [...allStudents];
                filteredStudents = [...allStudents];
                filteredStudentDetails = [...allStudentsDetails];
                renderStudentsTable();
                renderStudentDetailsTable();
            } else {
                renderStudentsTableEmpty();
                renderStudentDetailsTableEmpty();
            }
        } catch (error) {
            console.error('Error loading students:', error);
            renderStudentsTableEmpty();
            renderStudentDetailsTableEmpty();
        }
    }

    function renderStudentsTableEmpty() {
        const tbody = document.getElementById('studentsTableBody');
        if (tbody) {
            tbody.innerHTML =
                '<tr><td colspan="7" style="text-align:center; padding:40px;">❌ Failed to load students. Please refresh the page. </td></tr>';
        }
    }

    function renderStudentDetailsTable() {
        const tbody = document.getElementById('studentDetailsBody');
        if (!tbody) return;

        if (!filteredStudentDetails || filteredStudentDetails.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" style="text-align:center; padding:40px;">👥 No students found.</td></tr>';
            return;
        }

        tbody.innerHTML = filteredStudentDetails.map((s, index) => {
            const statusClass = s.status === 'Active' ? 'status-active' : 'status-inactive';
            const courseCodes = s.enrolled_courses && s.enrolled_courses !== '—' ? s.enrolled_courses : (s.course_code || '—');
            const courseNames = s.enrolled_courses_names && s.enrolled_courses_names !== '—' ? s.enrolled_courses_names : (s.course_name || '—');
            const courseName = `${courseCodes} - ${courseNames}`;

            return `
            <tr class="student-row">
                <td>${index + 1}</td>
                <td><span class="tag">${escapeHTML(s.student_id || '—')}</span></td>
                <td><b>${escapeHTML(s.full_name || '—')}</b></td>
                <td>${escapeHTML(s.level || '—')}</td>
                <td>${escapeHTML(s.programme || '—')}</td>
                <td><small>${escapeHTML(courseName)}</small></td>
                <td><span class="tag ${statusClass}">${s.status || 'Active'}</span></td>
                <td class="action-buttons">
                    <button class="action-btn" onclick="viewStudentDetails(${s.id})" title="View Details"><i class="fas fa-eye"></i></button>
                    <button class="action-btn" onclick="editStudentById(${s.id})" title="Edit Student"><i class="fas fa-edit"></i></button>
                    <button class="action-btn" onclick="resetStudentPasswordById(${s.id})" title="Reset Password"><i class="fas fa-key"></i></button>
                </td>
            </tr>
        `;
        }).join('');
    }

    function renderStudentDetailsTableEmpty() {
        const tbody = document.getElementById('studentDetailsBody');
        if (tbody) {
            tbody.innerHTML =
                '<tr><td colspan="11" style="text-align:center; padding:40px;">👥 No students found. </td></tr>';
        }
    }

    function applyFilters() {
        const searchTerm = document.getElementById('studentSearchInput')?.value.toLowerCase() || '';
        const levelFilter = document.getElementById('levelFilter')?.value || 'all';
        const programmeFilter = document.getElementById('programmeFilter')?.value || 'all';
        const statusFilter = document.getElementById('statusFilter')?.value || 'all';

        filteredStudents = [...allStudents];

        if (searchTerm) {
            filteredStudents = filteredStudents.filter(s =>
                (s.student_id && s.student_id.toLowerCase().includes(searchTerm)) ||
                (s.full_name && s.full_name.toLowerCase().includes(searchTerm)) ||
                (s.email && s.email.toLowerCase().includes(searchTerm))
            );
        }
        if (levelFilter !== 'all') filteredStudents = filteredStudents.filter(s => s.level === levelFilter);
        if (programmeFilter !== 'all') filteredStudents = filteredStudents.filter(s => s.programme === programmeFilter);
        if (statusFilter !== 'all') filteredStudents = filteredStudents.filter(s => s.status === statusFilter);

        renderStudentsTable();
        toast(`Showing ${filteredStudents.length} of ${allStudents.length} students`);
    }

    function applyStudentDetailsFilters() {
        const searchTerm = document.getElementById('studentDetailsSearchInput')?.value.toLowerCase() || '';
        const levelFilter = document.getElementById('studentDetailsLevelFilter')?.value || 'all';
        const programmeFilter = document.getElementById('studentDetailsProgrammeFilter')?.value || 'all';
        const statusFilter = document.getElementById('studentDetailsStatusFilter')?.value || 'all';

        filteredStudentDetails = [...allStudentsDetails];

        if (searchTerm) {
            filteredStudentDetails = filteredStudentDetails.filter(s =>
                (s.student_id && s.student_id.toLowerCase().includes(searchTerm)) ||
                (s.full_name && s.full_name.toLowerCase().includes(searchTerm)) ||
                (s.programme && s.programme.toLowerCase().includes(searchTerm))
            );
        }
        if (levelFilter !== 'all') filteredStudentDetails = filteredStudentDetails.filter(s => s.level === levelFilter);
        if (programmeFilter !== 'all') filteredStudentDetails = filteredStudentDetails.filter(s => s.programme ===
            programmeFilter);
        if (statusFilter !== 'all') filteredStudentDetails = filteredStudentDetails.filter(s => s.status ===
            statusFilter);

        renderStudentDetailsTable();
        toast(`Showing ${filteredStudentDetails.length} of ${allStudentsDetails.length} students`);
    }

    function resetFilters() {
        const searchInput = document.getElementById('studentSearchInput');
        const levelFilter = document.getElementById('levelFilter');
        const programmeFilter = document.getElementById('programmeFilter');
        const statusFilter = document.getElementById('statusFilter');

        if (searchInput) searchInput.value = '';
        if (levelFilter) levelFilter.value = 'all';
        if (programmeFilter) programmeFilter.value = 'all';
        if (statusFilter) statusFilter.value = 'all';

        filteredStudents = [...allStudents];
        renderStudentsTable();
        toast('Filters reset. Showing all students');
    }

    function resetStudentDetailsFilters() {
        const searchInput = document.getElementById('studentDetailsSearchInput');
        const levelFilter = document.getElementById('studentDetailsLevelFilter');
        const programmeFilter = document.getElementById('studentDetailsProgrammeFilter');
        const statusFilter = document.getElementById('studentDetailsStatusFilter');

        if (searchInput) searchInput.value = '';
        if (levelFilter) levelFilter.value = 'all';
        if (programmeFilter) programmeFilter.value = 'all';
        if (statusFilter) statusFilter.value = 'all';

        filteredStudentDetails = [...allStudentsDetails];
        renderStudentDetailsTable();
        toast('Filters reset. Showing all students');
    }

    function showMigrateLevelModal(studentId, studentName, currentLevel = '') {
        document.getElementById('migrateStudentId').value = studentId;
        document.getElementById('migrateStudentName').textContent =
            `${studentName || 'Student'}${currentLevel ? ' - current level ' + currentLevel : ''}`;
        const levelSelect = document.getElementById('migrateStudentLevel');
        if (levelSelect && currentLevel) levelSelect.value = currentLevel;
        const modal = document.getElementById('migrateLevelModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeMigrateLevelModal() {
        const modal = document.getElementById('migrateLevelModal');
        if (modal) modal.style.display = 'none';
    }

    async function submitMigrateLevel() {
        const studentId = document.getElementById('migrateStudentId')?.value;
        const level = document.getElementById('migrateStudentLevel')?.value;
        if (!studentId || !level) {
            toast('Please select a student and level');
            return;
        }
        if (!(await confirmPopup(`Move this student to Level ${level}?`, 'Migrate level', 'Migrate'))) return;
        const result = await apiRequest('migrate_student_level', {
            student_id: studentId,
            level
        });
        if (result.success) {
            toast(result.message || `Student moved to Level ${level}`);
            closeMigrateLevelModal();
            await loadStudents();
            loadDashboardStats();
        } else {
            toast('❌ ' + (result.error || 'Failed to migrate student'));
        }
    }

    async function bulkMigrateShownStudents() {
        const visibleStudents = (Array.isArray(filteredStudents) && filteredStudents.length ? filteredStudents : allStudents)
            .filter(student => student && student.id);
        if (!visibleStudents.length) {
            toast('No students are currently shown to migrate');
            return;
        }

        const level = String(prompt('Move all currently shown students to which level? Enter 100, 200, 300, 400, or 500:', '200') || '').trim();
        if (!['100', '200', '300', '400', '500'].includes(level)) {
            toast('Please enter a valid level: 100, 200, 300, 400, or 500');
            return;
        }

        const confirmed = await confirmPopup(
            `Move ${visibleStudents.length} currently shown student(s) to Level ${level}?`,
            'Bulk migrate students',
            'Migrate All'
        );
        if (!confirmed) return;

        const result = await apiRequest('bulk_migrate_student_level', {
            level,
            student_ids: JSON.stringify(visibleStudents.map(student => student.id))
        });

        if (result.success) {
            toast(result.message || `Migrated ${visibleStudents.length} student(s) to Level ${level}`);
            await loadStudents();
            loadDashboardStats();
        } else {
            toast('Failed to migrate students: ' + (result.error || 'Unknown error'));
        }
    }

    function showAddStudentModal() {
        resetStudentForm();
        const modal = document.getElementById('studentModal');
        if (modal) modal.style.display = 'flex';
    }

    function addStudentCourseRow(code = '', name = '') {
        const list = document.getElementById('studentCoursesList');
        if (!list) return;
        const row = document.createElement('div');
        row.className = 'student-course-row';
        row.style.cssText = 'display:grid;grid-template-columns:1fr 1.5fr auto;gap:8px;margin-bottom:8px;align-items:center;';
        row.innerHTML = `
            <input type="text" class="form-input student-course-code" placeholder="Course Code" value="${escapeHTML(code)}" required>
            <input type="text" class="form-input student-course-name" placeholder="Course Name" value="${escapeHTML(name)}" required>
            <button type="button" class="btn danger" onclick="removeStudentCourseRow(this)" title="Remove course"><i class="fas fa-trash"></i></button>
        `;
        list.appendChild(row);
    }

    function removeStudentCourseRow(button) {
        const row = button.closest('.student-course-row');
        if (row) row.remove();
        if (!document.querySelector('.student-course-row')) addStudentCourseRow('', '');
    }

    function setStudentCourseRows(courses) {
        const list = document.getElementById('studentCoursesList');
        if (!list) return;
        list.innerHTML = '';
        const normalized = Array.isArray(courses) && courses.length ? courses : [{code: '', name: ''}];
        normalized.forEach(course => addStudentCourseRow(course.code || course.course_code || '', course.name || course.course_name || ''));
    }

    function collectStudentCourses() {
        const rows = Array.from(document.querySelectorAll('.student-course-row'));
        return rows.map(row => ({
            code: row.querySelector('.student-course-code')?.value.trim() || '',
            name: row.querySelector('.student-course-name')?.value.trim() || ''
        })).filter(course => course.code || course.name);
    }

    function resetStudentForm() {
        const title = document.getElementById('studentModalTitle');
        if (title) title.innerHTML = '<i class="fas fa-user-graduate"></i> Add New Student';

        const studentId = document.getElementById('studentId');
        const studentFullName = document.getElementById('studentFullName');
        const studentLevel = document.getElementById('studentLevel');
        const studentProgramme = document.getElementById('studentProgramme');
        const studentStatus = document.getElementById('studentStatus');
        const studentUsername = document.getElementById('studentUsername');
        const studentPassword = document.getElementById('studentPassword');

        if (studentId) {
            studentId.value = '';
            studentId.readOnly = false;
            studentId.style.backgroundColor = '';
            studentId.style.opacity = '';
        }
        if (studentFullName) studentFullName.value = '';
        if (studentLevel) studentLevel.value = '';
        if (studentProgramme) studentProgramme.value = '';
        if (studentStatus) studentStatus.value = 'Active';
        if (studentUsername) studentUsername.value = '';
        if (studentPassword) studentPassword.value = '';
        setStudentCourseRows([{code: '', name: ''}]);

        // Remove required field error styling
        const requiredFields = ['studentId', 'studentFullName', 'studentLevel', 'studentProgramme'];
        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.classList.remove('validation-error');
        });

        currentStudentId = null;
    }

    function closeStudentModal() {
        const modal = document.getElementById('studentModal');
        if (modal) modal.style.display = 'none';
        resetStudentForm();
    }

    async function saveStudent(event) {
        event.preventDefault();

        const id = document.getElementById('studentId')?.value.trim();
        const fullName = document.getElementById('studentFullName')?.value.trim();
        const level = document.getElementById('studentLevel')?.value;
        const programme = document.getElementById('studentProgramme')?.value;
        const status = document.getElementById('studentStatus')?.value;
        const courses = collectStudentCourses();
        const firstCourse = courses[0] || {code: '', name: ''};
        const courseCode = firstCourse.code;
        const courseName = firstCourse.name;

        const errorDiv = document.getElementById('studentFormError');
        if (errorDiv) {
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
        }

        // Validate all required fields
        const errors = [];
        if (!id) errors.push('Student ID is required');
        if (!fullName) errors.push('Full Name is required');
        if (!level) errors.push('Level is required');
        if (!programme) errors.push('Programme is required');
        if (courses.length === 0) errors.push('At least one course is required');
        courses.forEach((course, index) => {
            if (!course.code || !course.name) errors.push(`Course ${index + 1} needs both code and name`);
        });

        if (errors.length > 0) {
            if (errorDiv) {
                errorDiv.textContent = '❌ ' + errors.join(', ');
                errorDiv.style.display = 'block';
                setTimeout(() => errorDiv.style.display = 'none', 5000);
            }
            return;
        }

        const submitBtn = event.submitter || event.target.querySelector('button[type="submit"]');
        const originalText = submitBtn ? submitBtn.innerHTML : 'Save';
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
        }

        try {
            const formData = new FormData();
            if (currentStudentId) {
                formData.append('action', 'update_student');
                formData.append('student_db_id', currentStudentId);
            } else {
                formData.append('action', 'add_student');
            }
            formData.append('student_id', id);
            formData.append('full_name', fullName);
            formData.append('level', level);
            formData.append('programme', programme);
            formData.append('status', status);
            formData.append('course_code', courseCode);
            formData.append('course_name', courseName);
            formData.append('courses', JSON.stringify(courses));

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                toast('✅ Student saved and enrolled in course: ' + courseCode + ' - ' + courseName);
                closeStudentModal();
                await loadStudents(); // This will now fetch course info properly
                resetStudentForm();
                loadDashboardStats();
            } else {
                if (errorDiv) {
                    errorDiv.textContent = '❌ ' + (result.error || 'Failed to save student');
                    errorDiv.style.display = 'block';
                    setTimeout(() => errorDiv.style.display = 'none', 5000);
                }
            }
        } catch (error) {
            console.error('Error:', error);
            if (errorDiv) {
                errorDiv.textContent = '❌ Network error: ' + error.message;
                errorDiv.style.display = 'block';
                setTimeout(() => errorDiv.style.display = 'none', 5000);
            }
        } finally {
            if (submitBtn) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        }
    }

    async function viewStudentCourses(studentId) {
        try {
            const result = await apiRequest('get_student_courses', {
                student_id: studentId
            });
            if (result.success && result.data) {
                let coursesHtml =
                    '<div style="max-height: 300px; overflow-y: auto;"><table class="table" style="width:100%"><thead><tr><th>Course Code</th><th>Course Name</th><th>Enrolled By</th><th>Date</th></tr></thead><tbody>';
                result.data.forEach(course => {
                    coursesHtml += `
                    <tr>
                        <td><code>${escapeHTML(course.course_code)}</code></td>
                        <td>${escapeHTML(course.course_name)}</td>
                        <td>${escapeHTML(course.lecturer_name || '—')}</td>
                        <td>${new Date(course.enrolled_at).toLocaleDateString()}</td>
                    </tr>
                `;
                });
                coursesHtml += '</tbody></table></div>';

                // Find student name
                const student = allStudents.find(s => s.id == studentId);
                const studentName = student ? student.full_name : 'Student';

                const content = `
                <h4>${escapeHTML(studentName)} - Enrolled Courses</h4>
                ${coursesHtml}
            `;

                const contentDiv = document.getElementById('viewStudentContent');
                if (contentDiv) contentDiv.innerHTML = content;

                const modal = document.getElementById('viewStudentModal');
                if (modal) modal.style.display = 'flex';
            } else {
                toast('❌ No courses found for this student');
            }
        } catch (error) {
            console.error('Error loading student courses:', error);
            toast('❌ Failed to load courses');
        }
    }



    async function editStudentById(id) {
        console.log("Edit button clicked for student ID:", id);
        try {
            const result = await apiRequest('get_students');
            if (result.success && result.data) {
                allStudents = result.data;
                const student = allStudents.find(s => s.id == id);
                if (!student) {
                    toast('❌ Student not found');
                    return;
                }

                currentStudentId = student.id;
                const title = document.getElementById('studentModalTitle');
                if (title) title.innerHTML = '<i class="fas fa-edit"></i> Edit Student';

                const studentIdInput = document.getElementById('studentId');
                const studentFullName = document.getElementById('studentFullName');
                const studentLevel = document.getElementById('studentLevel');
                const studentProgramme = document.getElementById('studentProgramme');
                const studentStatus = document.getElementById('studentStatus');
                const studentUsername = document.getElementById('studentUsername');
                const studentPassword = document.getElementById('studentPassword');

                if (studentIdInput) studentIdInput.value = student.student_id || '';
                if (studentFullName) studentFullName.value = student.full_name || '';
                if (studentLevel) studentLevel.value = student.level || '';
                if (studentProgramme) studentProgramme.value = student.programme || '';
                if (studentStatus) studentStatus.value = student.status || 'Active';
                if (studentUsername) studentUsername.value = student.student_id || '';
                if (studentPassword) studentPassword.value = student.student_id || '';
                setStudentCourseRows(student.courses && student.courses.length ? student.courses : [{
                    code: student.course_code && student.course_code !== '—' ? student.course_code : '',
                    name: student.course_name && student.course_name !== '—' ? student.course_name : ''
                }]);

                if (studentIdInput) {
                    studentIdInput.readOnly = true;
                    studentIdInput.style.backgroundColor = 'var(--disabled-bg, #f0f0f0)';
                    studentIdInput.style.opacity = '0.7';
                }

                const modal = document.getElementById('studentModal');
                if (modal) modal.style.display = 'flex';
            } else {
                toast('❌ Failed to load student data');
            }
        } catch (error) {
            console.error('Error loading student:', error);
            toast('❌ Error loading student data');
        }
    }

    async function deleteStudentById(id) {
        if (!confirm('Delete this student? This action cannot be undone.')) return;
        try {
            const result = await apiRequest('delete_student', {
                student_id: id
            });
            if (result.success) {
                toast('🗑 Student deleted');
                await loadStudents();
            } else {
                toast('❌ ' + (result.error || 'Failed to delete student'));
            }
        } catch (error) {
            toast('❌ Network error. Please try again.');
        }
    }

    async function resetStudentPasswordById(id) {
        if (!confirm('Reset password to Student ID? The student will need to change it after login.')) return;
        try {
            const result = await apiRequest('reset_student_password', {
                student_id: id
            });
            if (result.success) {
                toast('✅ Password reset to Student ID');
            } else {
                toast('❌ ' + (result.error || 'Failed to reset password'));
            }
        } catch (error) {
            toast('❌ Network error. Please try again.');
        }
    }

    function viewStudentDetails(id) {
        const student = allStudents.find(s => s.id === id);
        if (!student) {
            toast('❌ Student not found');
            return;
        }

        const statusClass = student.status === 'Active' ? 'status-active' : 'status-inactive';
        const courseCodes = student.enrolled_courses && student.enrolled_courses !== '—' ? student.enrolled_courses : (student.course_code || '—');
        const courseNames = student.enrolled_courses_names && student.enrolled_courses_names !== '—' ? student.enrolled_courses_names : (student.course_name || '—');

        const content = `
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
            <div class="info-card"><div class="info-label"><i class="fas fa-id-card"></i> Student ID</div><div class="info-value">${escapeHTML(student.student_id || '—')}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-user"></i> Full Name</div><div class="info-value">${escapeHTML(student.full_name || '—')}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-graduation-cap"></i> Level</div><div class="info-value">${escapeHTML(student.level || '—')}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-building"></i> Programme</div><div class="info-value">${escapeHTML(student.programme || '—')}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-code"></i> Course Codes</div><div class="info-value">${escapeHTML(courseCodes)}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-book"></i> Course Names</div><div class="info-value">${escapeHTML(courseNames)}</div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-toggle-on"></i> Status</div><div class="info-value"><span class="tag ${statusClass}">${student.status || 'Active'}</span></div></div>
            <div class="info-card"><div class="info-label"><i class="fas fa-key"></i> Default Password</div><div class="info-value">${escapeHTML(student.student_id || '—')}</div></div>
        </div>`;

        const contentDiv = document.getElementById('viewStudentContent');
        if (contentDiv) contentDiv.innerHTML = content;

        const modal = document.getElementById('viewStudentModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeViewStudentModal() {
        const modal = document.getElementById('viewStudentModal');
        if (modal) modal.style.display = 'none';
    }

    async function enrollStudentInCourse(studentId, courseCode, courseName) {
        try {
            const result = await apiRequest('enroll_student_course', {
                student_id: studentId,
                course_code: courseCode,
                course_name: courseName
            });
            if (result.success) {
                toast('✅ Student enrolled in ' + courseCode);
                loadStudents();
                return true;
            } else {
                toast('❌ ' + (result.error || 'Enrollment failed'));
                return false;
            }
        } catch (error) {
            console.error('Error enrolling student:', error);
            toast('❌ Network error');
            return false;
        }
    }

    function showEnrollModal(studentId, studentName) {
        const courseCode = prompt(`Enter course code to enroll "${studentName}" in:`);
        if (courseCode) {
            const courseName = prompt(`Enter course name for ${courseCode}:`);
            if (courseName) {
                enrollStudentInCourse(studentId, courseCode, courseName);
            }
        }
    }

    async function viewStudentCourses(studentId) {
        try {
            const result = await apiRequest('get_student_courses', {
                student_id: studentId
            });
            if (result.success && result.data) {
                let coursesHtml =
                    '<div style="max-height: 300px; overflow-y: auto;"><table class="table" style="width:100%"><thead><tr><th>Course Code</th><th>Course Name</th><th>Enrolled By</th><th>Date</th></tr></thead><tbody>';
                result.data.forEach(course => {
                    coursesHtml +=
                        `<tr><td>${escapeHTML(course.course_code)}</td><td>${escapeHTML(course.course_name)}</td><td>${escapeHTML(course.lecturer_name || '—')}</td><td>${new Date(course.enrolled_at).toLocaleDateString()}</td></tr>`;
                });
                coursesHtml += '</tbody></table></div>';
                const contentDiv = document.getElementById('viewStudentContent');
                if (contentDiv) contentDiv.innerHTML = coursesHtml;

                const modal = document.getElementById('viewStudentModal');
                if (modal) modal.style.display = 'flex';
            }
        } catch (error) {
            console.error('Error loading student courses:', error);
            toast('❌ Failed to load courses');
        }
    }

    function exportStudentsToExcel() {
        const data = filteredStudents;
        if (!data || data.length === 0) {
            toast('❌ No students to export');
            return;
        }

        const exportData = data.map((s, index) => ({
            'S/N': index + 1,
            'Student ID': s.student_id || '—',
            'Full Name': s.full_name || '—',
            'Level': s.level || '—',
            'Programme': s.programme || '—',
            'Status': s.status || 'Active'
        }));

        const ws = XLSX.utils.json_to_sheet(exportData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Students');
        XLSX.writeFile(wb, `students_export_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.xlsx`);
        toast(`📊 Exported ${data.length} students to Excel`);
    }

    function exportStudentDetailsToExcel() {
        const data = filteredStudentDetails;
        if (!data || data.length === 0) {
            toast('❌ No students to export');
            return;
        }

        const exportData = data.map((s, index) => ({
            'S/N': index + 1,
            'Student ID': s.student_id || '—',
            'Full Name': s.full_name || '—',
            'Level': s.level || '—',
            'Programme': s.programme || '—',
            'Status': s.status || 'Active'
        }));

        const ws = XLSX.utils.json_to_sheet(exportData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'StudentDetails');
        XLSX.writeFile(wb, `student_details_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.xlsx`);
        toast(`📊 Exported ${data.length} student records to Excel`);
    }

    // ============================================
    // DASHBOARD WITH COURSE SELECTION & REAL-TIME UPDATES
    // ============================================

    let currentDashboardCourse = 'all';
    let dashboardChartInstances = {
        performanceLineChart: null,
        gradeBellCurve: null,
        correlationScatterChart: null,
        regressionChart: null
    };

    // Populate course selector dropdown
    function populateDashboardCourseSelector() {
        const select = document.getElementById('dashboardCourseSelect');
        if (!select) return;

        let courses = new Set();
        originalSubmissionsData.forEach(sub => {
            const examDetail = examDetailsCache[sub.exam_id];
            const courseCode = examDetail ? examDetail.course_code : (sub.course_code || '');
            const courseName = examDetail ? (examDetail.course_name || examDetail.title) : (sub.course_name || sub.exam_title || '');
            if (courseCode && courseName) {
                courses.add(JSON.stringify({
                    code: courseCode,
                    name: courseName
                }));
            }
        });

        let options = '<option value="all">All Courses (Overall)</option>';
        courses.forEach(courseStr => {
            const course = JSON.parse(courseStr);
            options +=
                `<option value="${escapeHTML(course.code)}">${escapeHTML(course.name)} (${escapeHTML(course.code)})</option>`;
        });

        select.innerHTML = options;

        // Also populate recent submissions course filter
        const recentSelect = document.getElementById('recentSubmissionsCourseFilter');
        if (recentSelect) {
            recentSelect.innerHTML = '<option value="all">All Courses</option>' +
                Array.from(courses).map(courseStr => {
                    const course = JSON.parse(courseStr);
                    return `<option value="${escapeHTML(course.code)}">${escapeHTML(course.name)} (${escapeHTML(course.code)})</option>`;
                }).join('');
        }
    }

    // Update dashboard with selected course
    async function updateDashboardWithCourse() {
        const select = document.getElementById('dashboardCourseSelect');
        currentDashboardCourse = select ? select.value : 'all';
        await loadDashboardStats();
        toast(
            `📊 Dashboard updated for ${currentDashboardCourse === 'all' ? 'All Courses' : currentDashboardCourse}`
        );
    }

    // Refresh all dashboard stats
    async function refreshAllDashboardStats() {
        showLoading('Refreshing dashboard data...');
        await loadDashboardStats();
        await loadSubmissions();
        await loadExamsList();
        await loadStudents();
        hideLoading();
        toast('✅ All dashboard data refreshed');
    }

    // Get submissions filtered by course
    function getSubmissionsByCourse(courseCode) {
        if (courseCode === 'all') {
            return originalSubmissionsData;
        }
        return originalSubmissionsData.filter(sub => {
            const examDetail = examDetailsCache[sub.exam_id];
            const subCourseCode = examDetail ? examDetail.course_code : (sub.course_code || '');
            return subCourseCode === courseCode;
        });
    }

    // Get filtered submissions for dashboard
    function getFilteredSubmissionsForDashboard() {
        return getSubmissionsByCourse(currentDashboardCourse);
    }

    // Load dashboard stats with course filtering
    async function loadDashboardStats() {
        try {
            const filteredSubmissions = getFilteredSubmissionsForDashboard();

            // Students stats (always overall since students aren't course-specific in this view)
            const studentsResult = await apiRequest('get_students');
            const students = studentsResult.success && studentsResult.data ? studentsResult.data : [];
            const totalStudents = students.length;
            const activeStudents = students.filter(s => s.status === 'Active').length;

            const totalStudentsEl = document.getElementById('statTotalStudents');
            const activeStudentsEl = document.getElementById('statActiveStudents');
            const studentProgressBar = document.getElementById('studentProgressBar');
            if (totalStudentsEl) totalStudentsEl.textContent = totalStudents;
            if (activeStudentsEl) activeStudentsEl.innerHTML =
                `Active: ${activeStudents} | Inactive: ${totalStudents - activeStudents}`;
            if (studentProgressBar) studentProgressBar.style.width = (activeStudents / Math.max(totalStudents, 1) *
                100) + '%';

            // Exams stats
            const examsResult = await apiRequest('get_exams');
            const exams = examsResult.success && examsResult.data ? examsResult.data : [];
            const totalExams = exams.length;
            const publishedExams = exams.filter(e => e.published === 1 || e.status === 'published').length;

            const totalExamsEl = document.getElementById('statTotalExams');
            const examStatusEl = document.getElementById('statExamStatus');
            const examProgressBar = document.getElementById('examProgressBar');
            if (totalExamsEl) totalExamsEl.textContent = totalExams;
            if (examStatusEl) examStatusEl.innerHTML = `Published: ${publishedExams} `;
            if (examProgressBar) examProgressBar.style.width = (publishedExams / Math.max(totalExams, 1) * 100) +
                '%';

            // Submissions stats (filtered by course)
            const totalSubmissions = filteredSubmissions.length;
            const markedSubmissions = filteredSubmissions.filter(s => s.status === 'GRADED' || s.status ===
                'MARKED').length;

            const totalSubmissionsEl = document.getElementById('statTotalSubmissions');
            const submissionStatusEl = document.getElementById('statSubmissionStatus');
            const submissionProgressBar = document.getElementById('submissionProgressBar');
            if (totalSubmissionsEl) totalSubmissionsEl.textContent = totalSubmissions;
            if (submissionStatusEl) submissionStatusEl.innerHTML =
                `Marked: ${markedSubmissions} | Pending: ${totalSubmissions - markedSubmissions}`;
            if (submissionProgressBar) submissionProgressBar.style.width = (markedSubmissions / Math.max(
                totalSubmissions, 1) * 100) + '%';

            // Calculate scores from filtered submissions
            const scores = [];
            filteredSubmissions.forEach(sub => {
                const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
                const classScore = studentClassScores[sub.student_identifier] || 0;
                const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
                scores.push(totalScore);
            });

            const avgScore = scores.length > 0 ? roundToWholeNumber(scores.reduce((a, b) => a + b, 0) / scores
                .length) : 0;
            const passCount = scores.filter(s => s >= 50).length;
            const passRate = scores.length > 0 ? roundToWholeNumber((passCount / scores.length) * 100) : 0;
            const highestScore = scores.length > 0 ? Math.max(...scores) : 0;
            const lowestScore = scores.length > 0 ? Math.min(...scores) : 0;

            // Calculate mean, median, standard deviation
            const mean = scores.length > 0 ? scores.reduce((a, b) => a + b, 0) / scores.length : 0;
            const sorted = [...scores].sort((a, b) => a - b);
            const median = sorted.length > 0 ? sorted[Math.floor(sorted.length / 2)] : 0;
            const variance = scores.length > 0 ? scores.reduce((a, b) => a + Math.pow(b - mean, 2), 0) / scores
                .length : 0;
            const stdDev = Math.sqrt(variance).toFixed(2);

            const avgScoreEl = document.getElementById('statAvgScore');
            const avgScoreBar = document.getElementById('avgScoreBar');
            const passRateEl = document.getElementById('statPassRate');
            const highestScoreEl = document.getElementById('statHighestScore');
            const lowestScoreEl = document.getElementById('statLowestScore');
            const passRateMetricEl = document.getElementById('statPassRateMetric');
            const meanScoreEl = document.getElementById('statMeanScore');
            const medianScoreEl = document.getElementById('statMedianScore');
            const stdDevEl = document.getElementById('statStdDev');

            if (avgScoreEl) avgScoreEl.textContent = avgScore + '%';
            if (avgScoreBar) avgScoreBar.style.width = avgScore + '%';
            if (passRateEl) passRateEl.textContent = passRate;
            if (highestScoreEl) highestScoreEl.textContent = highestScore + '%';
            if (lowestScoreEl) lowestScoreEl.textContent = lowestScore + '%';
            if (passRateMetricEl) passRateMetricEl.textContent = passRate + '%';
            if (meanScoreEl) meanScoreEl.textContent = Math.round(mean) + '%';
            if (medianScoreEl) medianScoreEl.textContent = median + '%';
            if (stdDevEl) stdDevEl.textContent = stdDev;

            // Grade distribution
            let grades = {
                A: 0,
                Bplus: 0,
                B: 0,
                Cplus: 0,
                C: 0,
                Dplus: 0,
                D: 0,
                E: 0
            };
            scores.forEach(score => {
                const gradeInfo = getGradeInfo(score);
                if (gradeInfo.grade === 'A') grades.A++;
                else if (gradeInfo.grade === 'B+') grades.Bplus++;
                else if (gradeInfo.grade === 'B') grades.B++;
                else if (gradeInfo.grade === 'C+') grades.Cplus++;
                else if (gradeInfo.grade === 'C') grades.C++;
                else if (gradeInfo.grade === 'D+') grades.Dplus++;
                else if (gradeInfo.grade === 'D') grades.D++;
                else grades.E++;
            });
            const totalGraded = scores.length;

            document.getElementById('gradeACount').textContent = grades.A;
            document.getElementById('gradeAPercent').textContent = totalGraded > 0 ? Math.round((grades.A /
                totalGraded) * 100) : 0;
            document.getElementById('gradeBplusCount').textContent = grades.Bplus;
            document.getElementById('gradeBplusPercent').textContent = totalGraded > 0 ? Math.round((grades.Bplus /
                totalGraded) * 100) : 0;
            document.getElementById('GradeBCount').textContent = grades.B;
            document.getElementById('gradeBPercent').textContent = totalGraded > 0 ? Math.round((grades.B /
                totalGraded) * 100) : 0;
            document.getElementById('gradeCplusCount').textContent = grades.Cplus;
            document.getElementById('gradeCplusPercent').textContent = totalGraded > 0 ? Math.round((grades.Cplus /
                totalGraded) * 100) : 0;
            document.getElementById('GradeCCount').textContent = grades.C;
            document.getElementById('gradeCPercent').textContent = totalGraded > 0 ? Math.round((grades.C /
                totalGraded) * 100) : 0;
            document.getElementById('gradeDplusCount').textContent = grades.Dplus;
            document.getElementById('gradeDplusPercent').textContent = totalGraded > 0 ? Math.round((grades.Dplus /
                totalGraded) * 100) : 0;
            document.getElementById('GradeDCount').textContent = grades.D;
            document.getElementById('gradeDPercent').textContent = totalGraded > 0 ? Math.round((grades.D /
                totalGraded) * 100) : 0;
            document.getElementById('GradeECount').textContent = grades.E;
            document.getElementById('gradeEPercent').textContent = totalGraded > 0 ? Math.round((grades.E /
                totalGraded) * 100) : 0;
            document.getElementById('totalGradedStudents').textContent = totalGraded;

            // Update charts
            updatePerformanceLineChartWithData(filteredSubmissions);
            updateGradeBellCurveWithData(scores);
            updateCorrelationScatterChartWithData(scores);
            updateRegressionChartWithData(scores);

            // Update recent submissions
            updateRecentSubmissionsWithFilters();

        } catch (error) {
            console.error('Error loading dashboard stats:', error);
        }
    }

    // Update Performance Line Chart
    function updatePerformanceLineChartWithData(submissions) {
        const ctx = document.getElementById('performanceLineChart')?.getContext('2d');
        if (!ctx) return;
        if (dashboardChartInstances.performanceLineChart) dashboardChartInstances.performanceLineChart.destroy();

        // Group by month
        const monthlyData = {};
        submissions.forEach(sub => {
            const date = new Date(sub.submitted_at || sub.submittedAt);
            const month = date.toLocaleString('default', {
                month: 'short',
                year: 'numeric'
            });
            if (!monthlyData[month]) {
                monthlyData[month] = {
                    total: 0,
                    count: 0
                };
            }
            const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
            monthlyData[month].total += totalScore;
            monthlyData[month].count++;
        });

        const months = Object.keys(monthlyData).sort((a, b) => new Date(a) - new Date(b));
        const avgScores = months.map(m => monthlyData[m].count > 0 ? roundToWholeNumber(monthlyData[m].total /
            monthlyData[m].count) : 0);

        dashboardChartInstances.performanceLineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Average Score (%)',
                    data: avgScores,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79,70,229,0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#4f46e5',
                    pointBorderColor: '#fff',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.raw}%`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Score (%)'
                        }
                    }
                }
            }
        });
    }

    // Update Grade Bell Curve
    function updateGradeBellCurveWithData(scores) {
        const ctx = document.getElementById('gradeBellCurve')?.getContext('2d');
        if (!ctx) return;
        if (dashboardChartInstances.gradeBellCurve) dashboardChartInstances.gradeBellCurve.destroy();

        const bins = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        scores.forEach(score => {
            const binIndex = Math.floor(score / 10);
            if (binIndex >= 0 && binIndex < 10) bins[binIndex]++;
        });

        dashboardChartInstances.gradeBellCurve = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['0-10', '11-20', '21-30', '31-40', '41-50', '51-60', '61-70', '71-80', '81-90',
                    '91-100'
                ],
                datasets: [{
                    label: 'Number of Students',
                    data: bins,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139,92,246,0.2)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#8b5cf6',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
    }

    // Update Correlation Scatter Chart
    function updateCorrelationScatterChartWithData(scores) {
        const ctx = document.getElementById('correlationScatterChart')?.getContext('2d');
        if (!ctx) return;
        if (dashboardChartInstances.correlationScatterChart) dashboardChartInstances.correlationScatterChart.destroy();

        const scatterData = scores.map((score, i) => ({
            x: Math.floor(Math.random() * 40) + 5,
            y: score
        }));

        dashboardChartInstances.correlationScatterChart = new Chart(ctx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Study Time vs Exam Score',
                    data: scatterData,
                    backgroundColor: '#3b82f6',
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `Study: ${ctx.raw.x}h, Score: ${ctx.raw.y}%`
                        }
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Study Time (hours)'
                        },
                        min: 0,
                        max: 50
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Exam Score (%)'
                        },
                        min: 0,
                        max: 100
                    }
                }
            }
        });
    }

    // Update Regression Chart
    function updateRegressionChartWithData(scores) {
        const ctx = document.getElementById('regressionChart')?.getContext('2d');
        if (!ctx) return;
        if (dashboardChartInstances.regressionChart) dashboardChartInstances.regressionChart.destroy();

        const xValues = scores.map((_, i) => i + 1);
        const meanX = xValues.reduce((a, b) => a + b, 0) / xValues.length;
        const meanY = scores.reduce((a, b) => a + b, 0) / scores.length;
        const slope = xValues.reduce((sum, xi, i) => sum + (xi - meanX) * (scores[i] - meanY), 0) / xValues.reduce((sum,
            xi) => sum + Math.pow(xi - meanX, 2), 0);
        const intercept = meanY - slope * meanX;
        const regressionLine = xValues.map(xi => slope * xi + intercept);

        dashboardChartInstances.regressionChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: xValues.map(i => `Student ${i}`),
                datasets: [{
                        label: 'Actual Scores',
                        data: scores,
                        borderColor: '#3b82f6',
                        backgroundColor: 'transparent',
                        pointRadius: 4,
                        showLine: false
                    },
                    {
                        label: 'Regression Line',
                        data: regressionLine,
                        borderColor: '#ef4444',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        pointRadius: 0,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        title: {
                            display: true,
                            text: 'Score (%)'
                        },
                        min: 0,
                        max: 100
                    }
                }
            }
        });
    }

    // Update recent submissions with filters
    function updateRecentSubmissionsWithFilters() {
        const courseFilter = document.getElementById('recentSubmissionsCourseFilter')?.value || 'all';
        const periodFilter = document.getElementById('recentSubmissionsPeriodFilter')?.value || 'all';

        let filtered = getSubmissionsByCourse(courseFilter);

        const now = new Date();
        filtered = filtered.filter(sub => {
            const subDate = new Date(sub.submitted_at || sub.submittedAt);
            if (periodFilter === 'day') return subDate.toDateString() === now.toDateString();
            if (periodFilter === 'week') {
                const weekAgo = new Date(now.setDate(now.getDate() - 7));
                return subDate >= weekAgo;
            }
            if (periodFilter === 'month') {
                const monthAgo = new Date(now.setMonth(now.getMonth() - 1));
                return subDate >= monthAgo;
            }
            if (periodFilter === 'year') {
                const yearAgo = new Date(now.setFullYear(now.getFullYear() - 1));
                return subDate >= yearAgo;
            }
            return true;
        });

        // Sort by date (newest first) and take top 10
        filtered.sort((a, b) => new Date(b.submitted_at || b.submittedAt) - new Date(a.submitted_at || a.submittedAt));
        const recent = filtered.slice(0, 10);

        const tbody = document.getElementById('recentSubmissionsTable');
        if (!tbody) return;

        if (!recent || recent.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="6" style="text-align:center; padding:40px;">No submissions found.</td></tr>';
            return;
        }

        tbody.innerHTML = recent.map(sub => {
            const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
            const classScore = studentClassScores[sub.student_identifier] || 0;
            const totalScore = roundToWholeNumber(convertTo100Scale(examScore, classScore));
            const gradeInfo = getGradeInfo(totalScore);
            const examDetail = examDetailsCache[sub.exam_id];
            const courseName = examDetail ? (examDetail.course_name || examDetail.title) : (sub.course_name || sub.exam_title || 'Unknown');
            const date = new Date(sub.submitted_at || sub.submittedAt);
            const formattedDate = date.toLocaleString();

            return `<tr>
            <td><b>${escapeHTML(sub.student_name || sub.student_identifier)}</b></td>
            <td>${escapeHTML(courseName)}</td>
            <td><strong>${totalScore}%</strong></td>
            <td><span class="tag ${gradeInfo.class}">${gradeInfo.grade}</span></td>
            <td>${gradeInfo.gradePoint.toFixed(1)}</td>
            <td><small>${formattedDate}</small></td>
        </tr>`;
        }).join('');
    }

    // Modal Functions
    function showCourseStatsModal() {
        const modal = document.getElementById('courseStatsModal');
        if (!modal) return;

        const courses = window.courseGroupsData || {};
        let html =
            '<table class="course-stats-table"><thead><tr><th>Course Code</th><th>Course Name</th><th>Level</th><th>Students</th><th>Avg Score</th><th>Pass Rate</th></tr></thead><tbody>';

        for (const [code, course] of Object.entries(courses)) {
            let totalScore = 0;
            let passed = 0;
            course.submissions.forEach(sub => {
                const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
                const classScore = studentClassScores[sub.student_identifier] || 0;
                const total = roundToWholeNumber(convertTo100Scale(examScore, classScore));
                totalScore += total;
                if (total >= 50) passed++;
            });
            const avg = course.submissions.length > 0 ? roundToWholeNumber(totalScore / course.submissions.length) : 0;
            const passRate = course.submissions.length > 0 ? roundToWholeNumber((passed / course.submissions.length) *
                100) : 0;

            html += `<tr>
            <td><code>${escapeHTML(code)}</code></td>
            <td>${escapeHTML(course.courseName)}</td>
            <td>Level ${escapeHTML(course.level)}</td>
            <td>${course.submissions.length}</td>
            <td class="course-stat-score">${avg}%</td>
            <td class="course-stat-pass">${passRate}%</td>
        </tr>`;
        }

        html += '</tbody></table>';
        document.getElementById('courseStatsContent').innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeCourseStatsModal() {
        document.getElementById('courseStatsModal').style.display = 'none';
    }

    function showSubmissionsStatsModal() {
        const modal = document.getElementById('submissionsStatsModal');
        if (!modal) return;

        const courses = window.courseGroupsData || {};
        let html =
            '<table class="course-stats-table"><thead><tr><th>Course Code</th><th>Course Name</th><th>Total Submissions</th><th>Graded</th><th>Pending</th><th>Completion %</th></tr></thead><tbody>';

        for (const [code, course] of Object.entries(courses)) {
            const total = course.submissions.length;
            const graded = course.submissions.filter(s => s.status === 'GRADED').length;
            const pending = total - graded;
            const percent = total > 0 ? roundToWholeNumber((graded / total) * 100) : 0;

            html += `<tr>
            <td><code>${escapeHTML(code)}</code></td>
            <td>${escapeHTML(course.courseName)}</td>
            <td>${total}</td>
            <td style="color: #10b981; font-weight: bold;">${graded}</td>
            <td style="color: #f59e0b;">${pending}</td>
            <td><div class="progress" style="height: 6px; width: 100px;"><div class="progress-bar" style="width: ${percent}%;"></div></div> ${percent}%</td>
        </tr>`;
        }

        html += '</tbody></table>';
        document.getElementById('submissionsStatsContent').innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeSubmissionsStatsModal() {
        document.getElementById('submissionsStatsModal').style.display = 'none';
    }

    function showAverageScoreModal() {
        const modal = document.getElementById('averageScoreModal');
        if (!modal) return;

        const courses = window.courseGroupsData || {};
        let html =
            '<table class="course-stats-table"><thead><tr><th>Course Code</th><th>Course Name</th><th>Average Score</th><th>Pass Rate</th><th>Highest Score</th><th>Lowest Score</th></tr></thead><tbody>';

        for (const [code, course] of Object.entries(courses)) {
            let scores = [];
            course.submissions.forEach(sub => {
                const examScore = roundToWholeNumber(convertTo60Scale(parseFloat(sub.percentage) || 0));
                const classScore = studentClassScores[sub.student_identifier] || 0;
                const total = roundToWholeNumber(convertTo100Scale(examScore, classScore));
                scores.push(total);
            });
            const avg = scores.length > 0 ? roundToWholeNumber(scores.reduce((a, b) => a + b, 0) / scores.length) : 0;
            const passed = scores.filter(s => s >= 50).length;
            const passRate = scores.length > 0 ? roundToWholeNumber((passed / scores.length) * 100) : 0;
            const highest = scores.length > 0 ? Math.max(...scores) : 0;
            const lowest = scores.length > 0 ? Math.min(...scores) : 0;

            html += `<tr>
            <td><code>${escapeHTML(code)}</code></td>
            <td>${escapeHTML(course.courseName)}</td>
            <td class="course-stat-score">${avg}%</td>
            <td class="course-stat-pass">${passRate}%</td>
            <td style="color: #10b981;">${highest}%</td>
            <td style="color: #ef4444;">${lowest}%</td>
        </tr>`;
        }

        html += '</tbody></table>';
        document.getElementById('averageScoreContent').innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeAverageScoreModal() {
        document.getElementById('averageScoreModal').style.display = 'none';
    }

    // Auto-refresh dashboard every 30 seconds
    let dashboardRefreshInterval = null;

    function startDashboardAutoRefresh() {
        if (dashboardRefreshInterval) clearInterval(dashboardRefreshInterval);
        dashboardRefreshInterval = setInterval(() => {
            if (document.getElementById('view-dashboard').classList.contains('active')) {
                loadDashboardStats();
                console.log("Auto-refreshed dashboard at:", new Date().toLocaleTimeString());
            }
        }, 30000);
    }

    // Call this in DOMContentLoaded
    function initDashboard() {
        startDashboardAutoRefresh();
    }

