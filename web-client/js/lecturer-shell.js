    // ============================================
    // 11. PROFILE FUNCTIONS
    // ============================================

    function qodaLecturerProfileImageSource(serverValue = '') {
        const cached = localStorage.getItem('qoda_lecturer_profile_pic');
        if (serverValue && serverValue.startsWith('data:image/')) return serverValue;
        if (cached) return cached;
        return serverValue || '';
    }

    function applyLecturerProfileImage(src) {
        if (!src) return;
        const profilePicDisplay = document.getElementById('profilePicDisplay');
        const profileIcon = document.querySelector('.profile-icon');
        const smallAvatar = document.querySelector('.profile-avatar-small');
        if (profilePicDisplay) {
            profilePicDisplay.src = src;
            profilePicDisplay.style.display = 'block';
        }
        if (profileIcon) profileIcon.innerHTML = `<img src="${src}" alt="Profile"><span class="tooltip-text">Profile</span>`;
        if (smallAvatar) smallAvatar.innerHTML = `<img src="${src}" alt="Profile">`;
    }

    document.addEventListener('DOMContentLoaded', () => {
        const cachedProfilePic = localStorage.getItem('qoda_lecturer_profile_pic');
        if (cachedProfilePic) applyLecturerProfileImage(cachedProfilePic);
    });

    async function loadProfile() {
        try {
            const result = await apiRequest('get_profile');
            if (result.success && result.data) {
                const profile = result.data;
                const profileName = document.getElementById('profileName');
                const profileStaffId = document.getElementById('profileStaffId');
                const profileEmail = document.getElementById('profileEmail');
                const profileDepartment = document.getElementById('profileDepartment');
                const profileFaculty = document.getElementById('profileFaculty');
                const profileLevels = document.getElementById('profileLevels');
                const profileClasses = document.getElementById('profileClasses');
                const profileCourses = document.getElementById('profileCourses');

                if (profileName) profileName.value = profile.full_name || '';
                if (profileStaffId) profileStaffId.value = profile.staff_id || '';
                if (profileEmail) profileEmail.value = profile.email || '';
                if (profileDepartment) profileDepartment.value = profile.department || '';
                if (profileFaculty) profileFaculty.value = profile.faculty || '';
                if (profileLevels) profileLevels.value = profile.levels_taught || '';
                if (profileClasses) profileClasses.value = profile.classes || '';
                if (profileCourses) profileCourses.value = profile.courses || '';
                renderTeachingCourses(profile.teaching_courses || []);

                const profilePicSource = qodaLecturerProfileImageSource(profile.profile_pic || '');
                if (profilePicSource) applyLecturerProfileImage(profilePicSource);
                else document.getElementById('profilePicDisplay')?.style && (document.getElementById('profilePicDisplay').style.display = 'none');
            }
        } catch (error) {
            console.error('Error loading profile:', error);
        }
    }

    function previewProfilePic(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(e) {
            const imgData = e.target.result;
            localStorage.setItem('qoda_lecturer_profile_pic', imgData);
            applyLecturerProfileImage(imgData);
        };
        reader.readAsDataURL(file);

        uploadProfilePicture(file);
    }

    async function uploadProfilePicture(file) {
        const formData = new FormData();
        formData.append('action', 'upload_profile_pic');
        formData.append('profile_pic', file);
        try {
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success) {
                const src = result.preview_url || result.url;
                if (src) {
                    localStorage.setItem('qoda_lecturer_profile_pic', src);
                    applyLecturerProfileImage(src);
                }
                toast('✅ Profile picture uploaded');
            } else toast('❌ Upload failed: ' + result.error);
        } catch (error) {
            console.error('Upload error:', error);
            toast('❌ Upload failed');
        }
    }

    async function saveProfile() {
        const profilePicDisplay = document.getElementById('profilePicDisplay');
        const profilePic = profilePicDisplay && profilePicDisplay.src ? profilePicDisplay.src : '';

        const prof = {
            full_name: document.getElementById('profileName')?.value.trim() || '',
            email: document.getElementById('profileEmail')?.value.trim() || '',
            department: document.getElementById('profileDepartment')?.value.trim() || '',
            faculty: document.getElementById('profileFaculty')?.value.trim() || '',
            levels_taught: document.getElementById('profileLevels')?.value.trim() || '',
            classes: document.getElementById('profileClasses')?.value.trim() || '',
            courses: document.getElementById('profileCourses')?.value.trim() || '',
            profile_pic: profilePic
        };

        const btn = event ? event.target : document.querySelector('#view-profile .btn.primary');
        const originalText = btn ? btn.innerHTML : 'Save';
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            btn.disabled = true;
        }

        try {
            const result = await apiRequest('update_profile', prof);
            if (result.success) {
                toast('✅ Profile saved successfully');
                loadProfile();
            } else {
                toast('❌ Failed to save profile: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            toast('❌ Network error. Please try again.');
        } finally {
            if (btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
    }

    function renderTeachingCourses(courses) {
        const wrap = document.getElementById('teachingCoursesList');
        if (!wrap) return;
        if (!courses.length) {
            wrap.innerHTML = '<span style="color: var(--muted); font-size: 13px;">No teaching courses added yet.</span>';
            return;
        }
        wrap.innerHTML = courses.map(course => `
            <span style="display:inline-flex; align-items:center; gap:6px; padding:8px 10px; border-radius:999px; background:rgba(59,130,246,.12); color:var(--text); border:1px solid var(--border); font-size:13px;">
                <i class="fas fa-book"></i>
                ${escapeHTML(course.course_code)} - ${escapeHTML(course.course_name)} (Level ${escapeHTML(course.level)})
            </span>
        `).join('');
    }

    async function saveTeachingCourse() {
        const courseCode = document.getElementById('teachingCourseCode')?.value.trim();
        const courseName = document.getElementById('teachingCourseName')?.value.trim();
        const level = document.getElementById('teachingCourseLevel')?.value;
        if (!courseCode || !courseName || !level) {
            toast('❌ Course code, course name, and level are required');
            return;
        }
        try {
            const result = await apiRequest('save_lecturer_course', {
                course_code: courseCode,
                course_name: courseName,
                level
            });
            if (result.success) {
                document.getElementById('teachingCourseCode').value = '';
                document.getElementById('teachingCourseName').value = '';
                document.getElementById('teachingCourseLevel').value = '';
                toast('✅ Teaching course saved');
                loadProfile();
            } else {
                toast('❌ ' + (result.error || 'Could not save teaching course'));
            }
        } catch (error) {
            toast('❌ Network error saving teaching course');
        }
    }

    async function createLecturerAccount(event) {
        const title = document.getElementById('newLecturerTitle')?.value || 'Mr.';
        const fullName = document.getElementById('newLecturerName')?.value.trim() || '';
        const email = document.getElementById('newLecturerEmail')?.value.trim() || '';
        const department = document.getElementById('newLecturerDepartment')?.value.trim() || '';
        const resultBox = document.getElementById('newLecturerResult');

        if (!fullName || !email || !department) {
            toast('Please enter the lecturer name, email, and department.');
            return;
        }

        const btn = event?.currentTarget || event?.target;
        const originalText = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
        }
        if (resultBox) {
            resultBox.style.display = 'none';
            resultBox.innerHTML = '';
        }

        try {
            const result = await apiRequest('create_lecturer', {
                title,
                full_name: fullName,
                email,
                department
            });

            if (!result.success) {
                toast(result.error || 'Could not create lecturer account.');
                return;
            }

            const staffId = result.staff_id || '';
            document.getElementById('newLecturerName').value = '';
            document.getElementById('newLecturerEmail').value = '';
            document.getElementById('newLecturerDepartment').value = '';
            if (resultBox) {
                resultBox.style.display = 'block';
                resultBox.innerHTML = `
                    <strong>Lecturer created successfully.</strong><br>
                    Login ID: <code>${escapeHTML(staffId)}</code><br>
                    Default password: <code>${escapeHTML(result.default_password || staffId)}</code><br>
                    Ask the lecturer to change this password after first login.
                `;
            }
            toast('Lecturer account created successfully.');
        } catch (error) {
            console.error('Create lecturer error:', error);
            toast('Network error creating lecturer account.');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
    }

    async function deleteLecturerAccount() {
        const confirmed = await confirmPopup('Delete your lecturer account? This signs you out and disables this account.', 'Delete Account', 'Delete');
        if (!confirmed) return;
        const password = prompt('Enter your current password to confirm account deletion:');
        if (!password) return;
        try {
            const result = await apiRequest('delete_lecturer_account', { password });
            if (result.success) {
                window.location.href = result.redirect || 'login.php';
            } else {
                toast('❌ ' + (result.error || 'Could not delete account'));
            }
        } catch (error) {
            toast('❌ Network error deleting account');
        }
    }

    async function changeLecturerPassword() {
        const currentPassword = document.getElementById('currentPassword')?.value;
        const newPassword = document.getElementById('newPassword')?.value;
        const confirmPassword = document.getElementById('confirmPassword')?.value;

        if (!currentPassword || !newPassword || !confirmPassword) {
            toast('❌ Please fill all password fields');
            return;
        }

        if (newPassword !== confirmPassword) {
            toast('❌ New passwords do not match');
            return;
        }

        if (newPassword.length < 6) {
            toast('❌ Password must be at least 6 characters');
            return;
        }

        const btn = event ? event.target : document.querySelector('#view-profile .btn.primary');
        const originalText = btn ? btn.innerHTML : 'Update';
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            btn.disabled = true;
        }

        try {
            const result = await apiRequest('change_lecturer_password', {
                current_password: currentPassword,
                new_password: newPassword
            });

            if (result.success) {
                toast('✅ Password changed successfully!');
                const currPass = document.getElementById('currentPassword');
                const newPass = document.getElementById('newPassword');
                const confirmPass = document.getElementById('confirmPassword');
                if (currPass) currPass.value = '';
                if (newPass) newPass.value = '';
                if (confirmPass) confirmPass.value = '';
            } else {
                toast('❌ ' + (result.error || 'Failed to change password'));
            }
        } catch (error) {
            console.error('Error:', error);
            toast('❌ Network error. Please try again.');
        } finally {
            if (btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
    }

    // ============================================
    // 12. EXAM VISIBILITY FUNCTIONS
    // ============================================

    async function loadEnrolledStudentsForExam() {
        const courseCode = document.getElementById('bCode')?.value;
        const level = document.getElementById('level')?.value || '';
        if (!courseCode) {
            const enrolledTable = document.getElementById('enrolledStudentsTable');
            if (enrolledTable) {
                enrolledTable.innerHTML =
                    '<tr><td colspan="5" style="text-align: center;">Please enter a course code first. </td></tr>';
            }
            return;
        }

        try {
            const result = await apiRequest('get_course_students', {
                course_code: courseCode,
                level: level
            });

            if (result.success && result.data && result.data.length > 0) {
                const tbody = document.getElementById('enrolledStudentsTable');
                const visibilityResult = await apiRequest('get_exam_visibility', {
                    exam_id: currentExamId
                });
                const visibilityMap = visibilityResult.success ? visibilityResult.data : {};

                if (tbody) {
                    tbody.innerHTML = result.data.map(student => {
                        const isVisible = visibilityMap[student.id] !== undefined ? visibilityMap[student
                            .id] : true;
                        return `<tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 12px 8px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #4f46e5, #06b6d4); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                                        ${escapeHTML(student.full_name.charAt(0).toUpperCase())}
                                    </div>
                                    <div>
                                        <strong>${escapeHTML(student.full_name)}</strong>
                                        <br><small style="color: var(--muted);">ID: ${escapeHTML(student.student_id)}</small>
                                    </div>
                                </div>
                              </td>
                            <td style="padding: 12px 8px;">Level ${escapeHTML(student.level)}</td>
                            <td style="padding: 12px 8px;">${escapeHTML(courseCode)}</td>
                            <td style="padding: 12px 8px; text-align: center;">
                                <button class="visibility-btn ${isVisible ? 'visible' : 'hidden'}" 
                                    onclick="toggleStudentVisibility(${student.id}, this)" 
                                    data-visible="${isVisible}" 
                                    style="background: ${isVisible ? '#10b981' : '#ef4444'};">
                                    <i class="fas ${isVisible ? 'fa-eye' : 'fa-eye-slash'}"></i>
                                    <span>${isVisible ? 'Visible' : 'Hidden'}</span>
                                </button>
                              </td>
                          </tr>`;
                    }).join('');
                }
            } else {
                const enrolledTable = document.getElementById('enrolledStudentsTable');
                if (enrolledTable) {
                    enrolledTable.innerHTML =
                        '<tr><td colspan="5" style="text-align: center; padding: 40px;">📭 No students enrolled in this course. Please add students first. </td></tr>';
                }
            }
        } catch (error) {
            console.error('Error loading students:', error);
            const enrolledTable = document.getElementById('enrolledStudentsTable');
            if (enrolledTable) {
                enrolledTable.innerHTML =
                    '<tr><td colspan="5" style="text-align: center; padding: 40px;">❌ Error loading students. Please try again. </td></tr>';
            }
        }
    }

    async function toggleStudentVisibility(studentId, button) {
        event.stopPropagation();
        const isCurrentlyVisible = button.classList.contains('visible');
        const newVisibility = !isCurrentlyVisible;

        if (newVisibility) {
            button.classList.remove('hidden');
            button.classList.add('visible');
            button.style.background = '#10b981';
            button.innerHTML = '<i class="fas fa-eye"></i><span>Visible</span>';
            toast(`✅ Student can now see this exam`, 2000);
        } else {
            button.classList.remove('visible');
            button.classList.add('hidden');
            button.style.background = '#ef4444';
            button.innerHTML = '<i class="fas fa-eye-slash"></i><span>Hidden</span>';
            toast(`🔒 Student cannot see this exam`, 2000);
        }

        try {
            await apiRequest('update_exam_visibility', {
                student_id: studentId,
                exam_id: currentExamId,
                visible: newVisibility ? 1 : 0
            });
        } catch (error) {
            console.error('Error saving visibility:', error);
            toast('❌ Failed to save visibility setting', 2000);
            if (newVisibility) {
                button.classList.remove('visible');
                button.classList.add('hidden');
                button.style.background = '#ef4444';
                button.innerHTML = '<i class="fas fa-eye-slash"></i><span>Hidden</span>';
            } else {
                button.classList.remove('hidden');
                button.classList.add('visible');
                button.style.background = '#10b981';
                button.innerHTML = '<i class="fas fa-eye"></i><span>Visible</span>';
            }
        }
    }

    function toggleStudentVisibilityList() {
        studentListVisible = !studentListVisible;
        const container = document.getElementById('enrolledStudentsListContainer');
        if (container) {
            if (studentListVisible) {
                container.style.display = 'block';
                loadEnrolledStudentsForExam();
            } else {
                container.style.display = 'none';
            }
        }
    }

    // ============================================
    // Preview Exam
    // ============================================

    // Add this function to your existing JavaScript code (around line where other preview functions are)
    function previewCompletedExam(examId) {
        const exam = findExam(examId);
        if (!exam) {
            toast('❌ Exam not found');
            return;
        }

        // Calculate total marks
        const totalMarks = calculateEffectiveExamMarks(exam);
        const questionCount = (exam.questions || []).length;
        const durationText = exam.durationMins ? `${Math.floor(exam.durationMins / 60)}h ${exam.durationMins % 60}m` :
            'N/A';

        let questionsHtml = '';
        if (!exam.questions || exam.questions.length === 0) {
            questionsHtml = `<div style="text-align:center; padding:40px; color:var(--muted);">
            <i class="fas fa-question-circle" style="font-size:40px; margin-bottom:10px;"></i>
            <p>No questions added yet.</p>
        </div>`;
        } else {
            questionsHtml = exam.questions.map((q, idx) => {
                const qMarks = q.hasSubQuestions && q.subQuestions?.length ?
                    q.subQuestions.reduce((s, sq) => s + (parseFloat(sq.marks) || 0), 0) :
                    parseFloat(q.marks) || 0;

                let answerArea = '';
                if (q.type === 'code') {
                    answerArea = `
                    <div style="margin-top:12px;">
                        <div style="font-size:12px; font-weight:600; color:var(--muted); margin-bottom:6px;">
                            <i class="fas fa-terminal"></i> Student Code (${escapeHTML(q.language || 'Code')}):
                        </div>
                        <textarea rows="6" disabled placeholder="Student types code here..."
                            style="width:100%; padding:12px; border-radius:10px; border:2px solid var(--border); background:var(--input-bg); color:var(--text); font-family:monospace; font-size:13px; resize:vertical;"></textarea>
                    </div>`;

                    if (q.hasSubQuestions && q.subQuestions?.length) {
                        answerArea += '<div style="margin-top:14px;">' + q.subQuestions.map((sq, si) => {
                            const prefix = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'][si] || (
                                si + 1);
                            return `<div style="background:var(--bg); border-radius:10px; padding:14px; margin-bottom:10px; border:1px solid var(--border);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <strong style="color:var(--blue);">${prefix})</strong>
                                <span class="tag" style="background:var(--blue); color:#fff;">${sq.marks} marks</span>
                            </div>
                            <p style="margin:0 0 8px; color:var(--text);">${escapeHTML(sq.text || '(no text)')}</p>
                            ${sq.hint ? `<small style="color:var(--muted);"><i class="fas fa-lightbulb"></i> Hint: ${escapeHTML(sq.hint)}</small>` : ''}
                            <textarea rows="3" disabled placeholder="Student answer area..." style="width:100%; margin-top:8px; padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--input-bg); color:var(--text); font-family:monospace; font-size:13px;"></textarea>
                        </div>`;
                        }).join('') + '</div>';
                    }
                }

                return `
            <div style="background:var(--panel); border:2px solid var(--border); border-radius:14px; padding:20px; margin-bottom:18px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span style="font-weight:700; font-size:15px; color:var(--text);">Q${idx + 1}.</span>
                        <span style="background:linear-gradient(135deg,#10b981,#059669); color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600;">CODING</span>
                        ${q.compulsory ? '<span style="background:#ef4444; color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600;">COMPULSORY</span>' : ''}
                    </div>
                    <span style="background:var(--blue); color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;">${qMarks} marks</span>
                </div>
                <p style="margin:0 0 4px; color:var(--text); font-size:15px; line-height:1.6;">${escapeHTML(q.text || '(no question text)')}</p>
                ${answerArea}
            </div>`;
            }).join('');
        }

        const logoHtml = '';

        const previewContent = document.getElementById('examPreviewContent');
        if (!previewContent) return;

        // Add exam status badge
        let statusBadge = '';
        const now = new Date();
        const startTime = exam.startAtISO ? new Date(exam.startAtISO) : null;
        const endTime = exam.endAtISO ? new Date(exam.endAtISO) : (startTime ? new Date(startTime.getTime() + (exam.durationMins * 60000)) : null);

        if (endTime && endTime < now) {
            statusBadge =
                '<span style="background:#6b7280; color:white; padding:4px 12px; border-radius:20px; font-size:12px; margin-left:10px;"><i class="fas fa-clock"></i> Exam Ended</span>';
        } else if (startTime && startTime > now) {
            statusBadge =
                '<span style="background:#f59e0b; color:white; padding:4px 12px; border-radius:20px; font-size:12px; margin-left:10px;"><i class="fas fa-calendar-alt"></i> Scheduled</span>';
        } else if (startTime && startTime <= now && (!endTime || endTime > now)) {
            statusBadge =
                '<span style="background:#10b981; color:white; padding:4px 12px; border-radius:20px; font-size:12px; margin-left:10px;"><i class="fas fa-play"></i> Ongoing</span>';
        } else {
            statusBadge =
                '<span style="background:#3b82f6; color:white; padding:4px 12px; border-radius:20px; font-size:12px; margin-left:10px;"><i class="fas fa-check-circle"></i> Published</span>';
        }

        previewContent.innerHTML = `
        <div style="max-width:860px; margin:0 auto;">
            <!-- Preview Mode Banner -->
            <div style="background:linear-gradient(135deg,#8b5cf6,#7c3aed); color:#fff; border-radius:16px; padding:12px 20px; margin-bottom:20px; text-align:center;">
                <i class="fas fa-eye" style="margin-right:8px;"></i> 
                <strong>Preview Mode</strong> - This is how students see the exam
                ${statusBadge}
            </div>
            
            <!-- Exam Header -->
            <div style="background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; border-radius:16px; padding:28px 24px; margin-bottom:24px; text-align:center;">
                ${logoHtml}
                <div style="font-size:13px; opacity:.85; margin-bottom:4px;">${escapeHTML(exam.school_name || '')}</div>
                <div style="font-size:12px; opacity:.75; margin-bottom:12px;">${escapeHTML(exam.faculty_name || '')} ${exam.department ? '| ' + escapeHTML(exam.department) : ''}</div>
                <h2 style="margin:0 0 6px; font-size:22px; font-weight:700;">${escapeHTML(exam.title || 'Exam')}</h2>
                <div style="font-size:13px; opacity:.85;">${escapeHTML(exam.courseCode || '')} ${exam.exam_type ? '| ' + escapeHTML(exam.exam_type) : ''} ${exam.semester ? '| ' + escapeHTML(exam.semester) : ''}</div>
            </div>
            
            <!-- Exam Meta Bar -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin-bottom:24px;">
                <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                    <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Duration</div>
                    <div style="font-size:18px; font-weight:700; color:var(--blue);">⏱ ${durationText}</div>
                </div>
                <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                    <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Questions</div>
                    <div style="font-size:18px; font-weight:700; color:var(--blue);">📝 ${questionCount}</div>
                </div>
                <div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                    <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Total Marks</div>
                    <div style="font-size:18px; font-weight:700; color:var(--blue);">⭐ ${totalMarks}</div>
                </div>
                ${exam.level ? `<div style="background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center;">
                    <div style="font-size:11px; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Level</div>
                    <div style="font-size:18px; font-weight:700; color:var(--blue);">🎓 ${escapeHTML(exam.level)}</div>
                </div>` : ''}
            </div>
            
            <!-- Instructions -->
            ${exam.instructions ? `<div style="background:rgba(59,130,246,.06); border-left:4px solid #3b82f6; border-radius:0 12px 12px 0; padding:16px 20px; margin-bottom:24px;">
                <div style="font-weight:700; margin-bottom:8px; color:var(--text);">📋 Instructions</div>
                <pre style="margin:0; font-family:inherit; white-space:pre-wrap; color:var(--text); font-size:14px; line-height:1.6;">${escapeHTML(exam.instructions)}</pre>
            </div>` : ''}
            
            <!-- Questions -->
            <div style="margin-bottom:24px;">${questionsHtml}</div>
            
            <!-- Submit bar (disabled in preview) -->
            <div style="background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; opacity:.6;">
                <span style="color:var(--muted); font-size:13px;"><i class="fas fa-info-circle"></i> Preview mode — submission disabled</span>
                <button disabled style="padding:10px 24px; background:#3b82f6; color:#fff; border:none; border-radius:30px; font-weight:600; cursor:not-allowed; opacity:.5;">Submit Exam</button>
            </div>
        </div>`;

        document.getElementById('examPreviewModal').style.display = 'flex';
    }


    // ============================================
    // 13. SIDEBAR & NAVIGATION FUNCTIONS
    // ============================================

    function go(route, params = {}) {
        if (!routes.includes(route)) route = "dashboard";

        if (route !== "builder" && currentExamId) {
            saveExamToDatabase();
        }

        if (route === "results") {
            setTimeout(() => {
                initResultsPage();
            }, 100);
        }
        routeState = {
            route,
            params
        };

        localStorage.setItem("currentView", route);
        localStorage.setItem("currentRouteParams", JSON.stringify(params));
        sessionStorage.setItem("currentView", route);
        sessionStorage.setItem("currentRouteParams", JSON.stringify(params));

        if (params.id) {
            sessionStorage.setItem("currentExamId", params.id);
            localStorage.setItem(K_LAST_BUILDER_EXAM, String(params.id));
        }

        document.querySelectorAll(".nav-icon").forEach(icon => {
            const tooltip = icon.getAttribute('data-tooltip');
            const tooltipRoute = (tooltip || '').trim().toLowerCase().replace(/\s+/g, '-');
            if (tooltipRoute === route || (tooltip && tooltip.toLowerCase().includes(route))) {
                icon.classList.add('active');
            } else if (icon.classList.contains('has-submenu') && route === 'builder') {
                icon.classList.add('active');
            } else {
                icon.classList.remove('active');
            }
        });

        document.querySelectorAll(".view").forEach(v => v.classList.remove("active"));
        const el = document.getElementById(`view-${route}`);
        if (el) el.classList.add("active");

        const map = {
            dashboard: "🏠 Dashboard",
            exams: "📝 Exams",
            builder: "✏️ Exam Builder",
            submissions: "📤 Submissions",
            marking: "✅ Marking",
            results: "📊 Results",
            students: "👥 Students",
            "student-details": "📋 Student Details",
            profile: "👤 Profile",
            "add-lecturer": "Add Lecturer",
            proctoring: "👁️ Proctoring"
        };

        const bluebarTitle = document.getElementById("bluebarTitle");
        if (bluebarTitle) {
            const backButton = route !== 'dashboard'
                ? `<button type="button" onclick="qodaDashboardBack()" title="Back" style="margin-right:10px;border:1px solid var(--border);background:var(--card);color:var(--text);border-radius:10px;padding:7px 11px;font-weight:800;cursor:pointer;"><i class="fas fa-arrow-left"></i> Back</button>`
                : '';
            bluebarTitle.innerHTML = `${backButton}<span style="margin-left:0">${map[route] || "Dashboard"}</span>`;
        }

        if (window.history && window.history.pushState) {
            const url = new URL(window.location.href);
            url.hash = route;
            window.history.pushState({}, '', url);
        }

        renderRoute(route, params);
    }

    function renderRoute(route, params) {
        if (route === "dashboard") renderDashboard();
        if (route === "exams") renderExamsList(params || {});
        if (route === "builder") {
            document.querySelectorAll(".view").forEach(v => v.classList.remove("active"));
            const builderView = document.getElementById("view-builder");
            if (builderView) builderView.classList.add("active");
            return;
        }
        if (route === "submissions") {
            currentExpandedCourse = null;
            sessionStorage.removeItem('currentSubmissionView');
            sessionStorage.removeItem('currentCourseCode');
            loadSubmissions();
        }
        if (route === "marking") renderMarking();
        if (route === "results") renderResults();
        if (route === "students") renderStudentsTable();
        if (route === "student-details") renderStudentDetailsTable();
        if (route === "profile") loadProfile();
    }

    function qodaDashboardBack() {
        if (window.history.length > 1) {
            window.history.back();
            return;
        }
        go('dashboard');
    }

    function debugCurrentExam() {
        if (!currentExamId) {
            console.log("No current exam ID");
            return;
        }

        const exams = getExams();
        const exam = exams.find(e => parseInt(e.id) === parseInt(currentExamId));

        if (!exam) {
            console.log("Exam not found");
            return;
        }

        console.log("=== CURRENT EXAM DEBUG ===");
        console.log("ID:", exam.id);
        console.log("Title:", exam.title);
        console.log("Course Code:", exam.courseCode);
        console.log("Duration:", exam.durationMins);
        console.log("Instructions:", exam.instructions);
        console.log("Questions count:", exam.questions?.length || 0);
        console.log("Published:", exam.published);
        console.log("School Name:", exam.school_name);
        console.log("Faculty:", exam.faculty_name);
        console.log("Department:", exam.department);
        console.log("Semester:", exam.semester);
        console.log("Exam Type:", exam.exam_type);
        console.log("Level:", exam.level);
        console.log("Full exam object:", exam);
        console.log("==========================");

        return exam;
    }

    // Add a keyboard shortcut (Ctrl+Shift+D) to debug
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && e.key === 'D') {
            debugCurrentExam();
            toast('Debug info logged to console');
        }
    });

    function renderDashboard() {
        const exams = getExams();
        const subs = getSubs();
        const published = exams.filter(e => e.published).length;
        const marked = subs.filter(s => s.status === "MARKED").length;

        const ctx = document.getElementById('dashboardChart')?.getContext('2d');
        if (ctx) {
            if (dashboardChart) dashboardChart.destroy();
            dashboardChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Exams', 'Published', 'Submissions', 'Marked'],
                    datasets: [{
                        label: 'Count',
                        data: [exams.length, published, subs.length, marked],
                        backgroundColor: ['#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6'],
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
    }



    // Handle school logo upload and convert to base64
    let uploadedSchoolLogo = null;

    function handleSchoolLogoUpload(event) {
        const file = event.target.files[0];
        if (!file) return;

        // Check file size (max 2MB)
        if (file.size > 2 * 1024 * 1024) {
            toast('❌ Logo too large! Maximum 2MB.', 3000);
            return;
        }

        // Check file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            toast('❌ Only JPG, PNG, GIF, or WEBP images allowed.', 3000);
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            uploadedSchoolLogo = e.target.result; // Store base64 string
            const preview = document.getElementById('schoolLogoPreview');
            const img = document.getElementById('schoolLogoImg');
            if (img) img.src = uploadedSchoolLogo;
            if (preview) preview.style.display = 'block';
            console.log("✅ School logo uploaded and converted to base64");
        };
        reader.onerror = function() {
            toast('❌ Failed to read logo file.', 3000);
        };
        reader.readAsDataURL(file);
    }

    function renderExamsList(params) {
        const exams = getExams();
        let filtered = exams;
        if (params.q) {
            const q = params.q.toLowerCase();
            filtered = exams.filter(e => e.title?.toLowerCase().includes(q) || e.courseCode?.toLowerCase().includes(q));
        }
        renderExamsTable(filtered);
    }

    function closeSubmenuPanel() {
        const panel = document.getElementById('submenuPanel');
        if (panel) panel.classList.remove('open');
        document.querySelectorAll('.nav-icon').forEach(icon => icon.classList.remove('active'));
        currentActiveSubmenu = null;
    }

    function handleSubmenuClick(page) {
        go(page);
        closeSubmenuPanel();
    }

    function handleNavClick(element, page) {
        closeSubmenuPanel();
        document.querySelectorAll('.nav-icon').forEach(icon => icon.classList.remove('active'));
        element.classList.add('active');
        go(page);
    }

    function toggleSubmenuPanel(element) {
        const submenuId = element.getAttribute('data-submenu');
        const submenu = submenusData[submenuId];
        if (!submenu) return;
        if (submenu.items && submenu.items.length === 1) {
            closeSubmenuPanel();
            handleNavClick(element, submenu.items[0].page);
            return;
        }

        const panel = document.getElementById('submenuPanel');
        const content = document.getElementById('submenuContent');
        const title = document.getElementById('submenuTitle');

        if (currentActiveSubmenu === submenuId && panel && panel.classList.contains('open')) {
            closeSubmenuPanel();
            return;
        }

        if (title) title.textContent = submenu.title;
        if (content) {
            content.innerHTML = submenu.items.map(item =>
                `<div class="submenu-item" onclick="handleSubmenuClick('${item.page}')">
                    <i class="${item.icon}"></i>
                    <span>${item.label}</span>
                </div>`
            ).join('');
        }

        if (panel) panel.classList.add('open');
        currentActiveSubmenu = submenuId;

        document.querySelectorAll('.nav-icon').forEach(icon => icon.classList.remove('active'));
        element.classList.add('active');
    }

    function toggleMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.toggle('mobile-open');
        if (overlay) overlay.classList.toggle('active');

        if (sidebar && sidebar.classList.contains('mobile-open')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    function toggleSubmenuPanel(element) {
        const submenuId = element.getAttribute('data-submenu');
        const submenu = submenusData[submenuId];
        if (!submenu) return;
        if (submenu.items && submenu.items.length === 1) {
            closeSubmenuPanel();
            handleNavClick(element, submenu.items[0].page);
            return;
        }

        const panel = document.getElementById('submenuPanel');
        const overlay = document.getElementById('submenuOverlay');
        const content = document.getElementById('submenuContent');
        const title = document.getElementById('submenuTitle');

        // If the same submenu is already open, close it
        if (currentActiveSubmenu === submenuId && panel && panel.classList.contains('open')) {
            closeSubmenuPanel();
            return;
        }

        // Close any open submenu first
        if (panel && panel.classList.contains('open')) {
            closeSubmenuPanel();
        }

        // Set new submenu content
        if (title) title.textContent = submenu.title;
        if (content) {
            content.innerHTML = submenu.items.map(item =>
                `<div class="submenu-item" onclick="handleSubmenuClick('${item.page}')">
                <i class="${item.icon}"></i>
                <span>${item.label}</span>
            </div>`
            ).join('');
        }

        // Open the panel
        if (panel) {
            panel.classList.add('open');
        }
        if (overlay) {
            overlay.classList.add('active');
        }

        currentActiveSubmenu = submenuId;

        // Remove active class from all nav icons, then add to current
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });
        element.classList.add('active');
    }

    function closeSubmenuPanel() {
        const panel = document.getElementById('submenuPanel');
        const overlay = document.getElementById('submenuOverlay');

        if (panel) {
            panel.classList.remove('open');
        }
        if (overlay) {
            overlay.classList.remove('active');
        }

        // Remove active class from all nav icons
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });

        currentActiveSubmenu = null;
    }


    // Close submenu panel when clicking outside
    function closeSubmenuPanel() {
        const panel = document.getElementById('submenuPanel');
        const overlay = document.getElementById('submenuOverlay');
        if (panel) {
            panel.classList.remove('open');
        }
        if (overlay) {
            overlay.classList.remove('active');
        }
        // Remove active class from all nav icons
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });
        currentActiveSubmenu = null;
    }

    // Handle submenu item click
    function handleSubmenuClick(page) {
        // First close the submenu panel
        closeSubmenuPanel();
        // Then navigate to the page
        go(page);
    }

    // Toggle submenu panel
    function toggleSubmenuPanel(element) {
        const submenuId = element.getAttribute('data-submenu');
        const submenu = submenusData[submenuId];
        if (!submenu) return;
        if (submenu.items && submenu.items.length === 1) {
            closeSubmenuPanel();
            handleNavClick(element, submenu.items[0].page);
            return;
        }

        const panel = document.getElementById('submenuPanel');
        const content = document.getElementById('submenuContent');
        const title = document.getElementById('submenuTitle');

        // If the same submenu is already open, close it
        if (currentActiveSubmenu === submenuId && panel && panel.classList.contains('open')) {
            closeSubmenuPanel();
            return;
        }

        // Close any open submenu first
        closeSubmenuPanel();

        // Set new submenu content
        if (title) title.textContent = submenu.title;
        if (content) {
            content.innerHTML = submenu.items.map(item =>
                `<div class="submenu-item" onclick="handleSubmenuClick('${item.page}')">
                <i class="${item.icon}"></i>
                <span>${item.label}</span>
            </div>`
            ).join('');
        }

        // Open the panel
        if (panel) panel.classList.add('open');
        currentActiveSubmenu = submenuId;

        // Remove active class from all nav icons, then add to current
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });
        element.classList.add('active');
    }

    // Handle nav icon click (without submenu)
    function handleNavClick(element, page) {
        // Close any open submenu
        closeSubmenuPanel();

        // Remove active class from all nav icons
        document.querySelectorAll('.nav-icon').forEach(icon => {
            icon.classList.remove('active');
        });

        // Add active class to clicked icon
        element.classList.add('active');

        // Navigate to page
        go(page);
    }

    // ============================================
    // 14. THEME FUNCTIONS
    // ============================================

    function loadTheme() {
        const saved = localStorage.getItem('theme');
        if (saved === 'dark') document.body.classList.add('dark');
        else if (saved === 'light') document.body.classList.remove('dark');
    }

    function toggleTheme() {
        document.body.classList.toggle('dark');
        const isDark = document.body.classList.contains('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');

        const icon = document.getElementById('themeIcon');
        if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        refreshQodaModelEditorThemes();
    }

    function updateChartsTheme() {
        if (dashboardChart) dashboardChart.update();
        if (lineChart) lineChart.update();
        if (bellCurve) bellCurve.update();
        if (performanceChart) performanceChart.update();
        if (correlationChart) correlationChart.update();
        if (regressionChart) regressionChart.update();
    }

    // ============================================
    // 15. HEADER NAME
    // ============================================

    function setStaticHeaderText() {
        const el = document.getElementById("headerTyping");
        if (el) el.textContent = "QODA PU";
    }



    function exportJSON() {
        const p = {
            exams: getExams(),
            subs: getSubs(),
            audit: readJSON(K_AUDIT, []),
            students: getStudents()
        };

        const b = new Blob([JSON.stringify(p, null, 2)], {
            type: "application/json"
        });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(b);
        a.download = "qoda-export.json";
        a.click();
        toast("📦 Exported");
    }

    function importJSON() {
        const file = document.getElementById('importDataFile').files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                const data = JSON.parse(e.target.result);
                if (data.exams) writeJSON(K_EXAMS, data.exams);
                if (data.subs) writeJSON(K_SUBS, data.subs);
                if (data.students) writeJSON(K_STUDENTS, data.students);
                toast('✅ Data imported successfully');
                location.reload();
            } catch (err) {
                toast('❌ Invalid import file');
            }
        };
        reader.readAsText(file);
    }

    // Proctoring functions moved to web-client/js/lecturer-proctoring.js

    // Column headers for import/export (must match)
    const STUDENT_CSV_HEADERS = [
        'Student ID',
        'Full Name',
        'Level',
        'Programme',
        'Course Code',
        'Course Name',
        'Status'
    ];

    // Show import modal
    function showImportModal() {
        const modal = document.getElementById('importModal');
        if (modal) {
            modal.style.display = 'flex';
            document.getElementById('importFile').value = '';
            document.getElementById('importProgress').style.display = 'none';
            document.getElementById('importResults').style.display = 'none';
            document.getElementById('defaultCourseCode').value = '';
            document.getElementById('defaultCourseName').value = '';
        }
    }

    function closeImportModal() {
        const modal = document.getElementById('importModal');
        if (modal) modal.style.display = 'none';
    }

    // Download CSV template with headers
    function downloadImportTemplate() {
        // Create data with headers and example row
        const templateData = [{
                'Student ID': 'STU001',
                'Full Name': 'John Doe',
                'Level': '100',
                'Programme': 'Computer Science',
                'Course Code': 'CS101, CS102',
                'Course Name': 'Introduction to Programming, Data Structures',
                'Status': 'Active'
            },
            {
                'Student ID': 'STU002',
                'Full Name': 'Jane Smith',
                'Level': '200',
                'Programme': 'Information Technology',
                'Course Code': 'IT201',
                'Course Name': 'Web Programming',
                'Status': 'Active'
            }
        ];

        // Convert to worksheet
        const ws = XLSX.utils.json_to_sheet(templateData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Student_Template');

        // Download
        XLSX.writeFile(wb, `student_import_template_${new Date().toISOString().slice(0,10)}.xlsx`);
        toast('📥 Template downloaded. Fill in student data and import back.');
    }

    // Export students with exact same headers as import
    async function exportStudentsToExcelWithTemplate() {
        if (!filteredStudents || filteredStudents.length === 0) {
            toast('❌ No students to export');
            return;
        }

        const exportData = filteredStudents.map((student) => {
            return {
                'Student ID': student.student_id || '',
                'Full Name': student.full_name || '',
                'Level': student.level || '',
                'Programme': student.programme || '',
                'Course Code': student.enrolled_courses || student.course_code || '—',
                'Course Name': student.enrolled_courses_names || student.course_name || '—',
                'Status': student.status || 'Active'
            };
        });

        // Create worksheet
        const ws = XLSX.utils.json_to_sheet(exportData);

        // Auto-size columns
        ws['!cols'] = STUDENT_CSV_HEADERS.map(() => ({
            wch: 20
        }));

        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Students');

        // Download
        XLSX.writeFile(wb, `students_export_${new Date().toISOString().slice(0,19).replace(/:/g, '-')}.xlsx`);
        toast(`📊 Exported ${exportData.length} students with course information`);
    }

    // Import students from file
    async function importStudents() {
        const fileInput = document.getElementById('importFile');
        const file = fileInput.files[0];

        if (!file) {
            toast('❌ Please select a file to import');
            return;
        }

        // Show progress
        const progressDiv = document.getElementById('importProgress');
        const progressBar = document.getElementById('importProgressBar');
        const statusText = document.getElementById('importStatus');
        const resultsDiv = document.getElementById('importResults');

        progressDiv.style.display = 'block';
        resultsDiv.style.display = 'block';
        progressBar.style.width = '10%';
        statusText.innerHTML = 'Reading file...';

        try {
            // Parse file
            let studentsData = [];

            if (file.name.endsWith('.csv')) {
                studentsData = await parseCSV(file);
            } else {
                studentsData = await parseExcel(file);
            }

            progressBar.style.width = '30%';
            statusText.innerHTML = `Parsed ${studentsData.length} records. Validating...`;

            // Validate and prepare data
            const defaultCourseCode = document.getElementById('defaultCourseCode').value.trim();
            const defaultCourseName = document.getElementById('defaultCourseName').value.trim();

            const validStudents = [];
            const errors = [];
            const duplicates = [];

            for (let i = 0; i < studentsData.length; i++) {
                const row = studentsData[i];
                const studentId = (row['Student ID'] || row['student_id'] || row['Student ID'] || '').toString()
                    .trim();
                const fullName = (row['Full Name'] || row['full_name'] || row['Full Name'] || '').toString().trim();
                let level = (row['Level'] || row['level'] || '').toString().trim();
                let programme = (row['Programme'] || row['programme'] || '').toString().trim();
                let courseCode = (row['Course Code'] || row['course_code'] || row['Course Code'] || '').toString()
                    .trim();
                let courseName = (row['Course Name'] || row['course_name'] || row['Course Name'] || '').toString()
                    .trim();
                let status = (row['Status'] || row['status'] || 'Active').toString().trim();

                // Apply defaults if empty
                if (!courseCode && defaultCourseCode) courseCode = defaultCourseCode;
                if (!courseName && defaultCourseName) courseName = defaultCourseName;
                const courseCodes = courseCode.split(/[,;\n]+/).map(v => v.trim()).filter(Boolean);
                const courseNames = courseName.split(/[,;\n]+/).map(v => v.trim()).filter(Boolean);
                const courses = courseCodes.map((code, idx) => ({
                    code,
                    name: courseNames[idx] || courseNames[0] || ''
                }));

                // Validate required fields
                const rowErrors = [];
                if (!studentId) rowErrors.push('Student ID missing');
                if (!fullName) rowErrors.push('Full Name missing');
                if (!level) rowErrors.push('Level missing');
                if (!programme) rowErrors.push('Programme missing');
                if (courses.length === 0) rowErrors.push('Course Code missing');
                if (courses.some(course => !course.name)) rowErrors.push('Every Course Code needs a matching Course Name');

                // Validate level
                const validLevels = ['100', '200', '300', '400', '500'];
                if (level && !validLevels.includes(level.toString())) {
                    rowErrors.push(`Level must be ${validLevels.join(', ')}`);
                }

                // Validate status
                if (status && !['Active', 'Inactive'].includes(status)) {
                    rowErrors.push('Status must be Active or Inactive');
                }

                if (rowErrors.length > 0) {
                    errors.push(`Row ${i + 2}: ${rowErrors.join(', ')}`);
                } else {
                    // Check for duplicate within file
                    const existingInFile = validStudents.filter(s => s.student_id === studentId);
                    if (existingInFile.length > 0) {
                        duplicates.push(`Row ${i + 2}: Student ID ${studentId} already exists in this import file`);
                    } else {
                        validStudents.push({
                            student_id: studentId,
                            full_name: fullName,
                            level: level.toString(),
                            programme: programme,
                            course_code: courseCode,
                            course_name: courseName,
                            courses: courses,
                            status: status
                        });
                    }
                }
            }

            progressBar.style.width = '50%';
            statusText.innerHTML = `Validated ${validStudents.length} students. Importing...`;

            // Show validation errors
            if (errors.length > 0 || duplicates.length > 0) {
                let warningHtml =
                    '<div style="color: #f59e0b;"><i class="fas fa-exclamation-triangle"></i> Validation Warnings:</div><ul style="margin: 5px 0 0 20px;">';
                if (errors.length > 0) {
                    warningHtml +=
                        `<li><strong>Errors (${errors.length}):</strong><ul>${errors.slice(0, 10).map(e => `<li>${e}</li>`).join('')}${errors.length > 10 ? `<li>... and ${errors.length - 10} more</li>` : ''}</ul></li>`;
                }
                if (duplicates.length > 0) {
                    warningHtml +=
                        `<li><strong>Duplicates (${duplicates.length}):</strong><ul>${duplicates.slice(0, 10).map(d => `<li>${d}</li>`).join('')}${duplicates.length > 10 ? `<li>... and ${duplicates.length - 10} more</li>` : ''}</ul></li>`;
                }
                warningHtml += '</ul>';
                resultsDiv.innerHTML = warningHtml;
                resultsDiv.style.background = 'rgba(245, 158, 11, 0.1)';
                resultsDiv.style.border = '1px solid #f59e0b';
            }

            if (validStudents.length === 0) {
                statusText.innerHTML = 'No valid students to import';
                progressBar.style.width = '100%';
                toast('❌ No valid students found in file');
                return;
            }

            // Import students one by one
            let imported = 0;
            let failed = 0;
            const failedStudents = [];

            for (let i = 0; i < validStudents.length; i++) {
                const student = validStudents[i];
                progressBar.style.width = `${50 + (i / validStudents.length) * 40}%`;
                statusText.innerHTML = `Importing ${i + 1} of ${validStudents.length}: ${student.full_name}...`;

                try {
                    const formData = new FormData();
                    formData.append('action', 'add_student');
                    formData.append('student_id', student.student_id);
                    formData.append('full_name', student.full_name);
                    formData.append('level', student.level);
                    formData.append('programme', student.programme);
                    formData.append('status', student.status);
                    formData.append('course_code', student.course_code);
                    formData.append('course_name', student.course_name);
                    formData.append('courses', JSON.stringify(student.courses || []));

                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        imported++;
                    } else {
                        failed++;
                        failedStudents.push(
                            `${student.student_id} - ${student.full_name}: ${result.error || 'Unknown error'}`);
                    }
                } catch (error) {
                    failed++;
                    failedStudents.push(`${student.student_id} - ${student.full_name}: Network error`);
                }
            }

            progressBar.style.width = '100%';
            statusText.innerHTML = `Import completed! Imported: ${imported}, Failed: ${failed}`;

            // Show results
            let resultHtml = `<div style="margin-bottom: 10px;">
            <strong>✅ Successfully imported: ${imported} students</strong><br>
            <strong>❌ Failed: ${failed} students</strong>
        </div>`;

            if (failedStudents.length > 0) {
                resultHtml += `<div style="margin-top: 10px;">
                <strong>Failed students:</strong>
                <ul style="margin: 5px 0 0 20px; max-height: 100px; overflow-y: auto;">
                    ${failedStudents.slice(0, 20).map(f => `<li>${escapeHTML(f)}</li>`).join('')}
                    ${failedStudents.length > 20 ? `<li>... and ${failedStudents.length - 20} more</li>` : ''}
                </ul>
            </div>`;
            }

            resultsDiv.innerHTML = resultHtml;
            resultsDiv.style.background = imported > 0 ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)';
            resultsDiv.style.border = `1px solid ${imported > 0 ? '#10b981' : '#ef4444'}`;

            if (imported > 0) {
                toast(`✅ Successfully imported ${imported} students`);
                await loadStudents();
                loadDashboardStats();

                // Close modal after 3 seconds if no failures
                if (failed === 0) {
                    setTimeout(() => closeImportModal(), 3000);
                }
            } else {
                toast('❌ No students were imported. Check the errors above.');
            }

        } catch (error) {
            console.error('Import error:', error);
            statusText.innerHTML = 'Import failed';
            resultsDiv.innerHTML =
                `<div style="color: #ef4444;"><i class="fas fa-exclamation-circle"></i> Error: ${error.message}</div>`;
            resultsDiv.style.display = 'block';
            toast('❌ Import failed. Please check file format.');
        }
    }

    // Parse CSV file
    function parseCSV(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const text = e.target.result;
                    const lines = text.split('\n');
                    const headers = lines[0].split(',').map(h => h.trim().replace(/"/g, ''));

                    const data = [];
                    for (let i = 1; i < lines.length; i++) {
                        if (!lines[i].trim()) continue;

                        const values = parseCSVLine(lines[i]);
                        const row = {};
                        headers.forEach((header, idx) => {
                            row[header] = values[idx] ? values[idx].trim().replace(/"/g, '') : '';
                        });
                        data.push(row);
                    }
                    resolve(data);
                } catch (error) {
                    reject(error);
                }
            };
            reader.onerror = reject;
            reader.readAsText(file);
        });
    }

    // Parse CSV line (handles quoted fields)
    function parseCSVLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;

        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if (char === '"') {
                inQuotes = !inQuotes;
            } else if (char === ',' && !inQuotes) {
                result.push(current);
                current = '';
            } else {
                current += char;
            }
        }
        result.push(current);
        return result;
    }

    // Parse Excel file
    function parseExcel(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, {
                        type: 'array'
                    });
                    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                    const jsonData = XLSX.utils.sheet_to_json(firstSheet);
                    resolve(jsonData);
                } catch (error) {
                    reject(error);
                }
            };
            reader.onerror = reject;
            reader.readAsArrayBuffer(file);
        });
    }

    // Update renderStudentsTable to include Course Code and Course Name columns
    function renderStudentsTable() {
        const tbody = document.getElementById('studentsTableBody');
        if (!tbody) return;

        if (!filteredStudents || filteredStudents.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="9" style="text-align:center; padding:40px;">👥 No students found. Click "Add New Student" or "Import Students" to get started.</td></tr>';
            return;
        }

        tbody.innerHTML = filteredStudents.map((s, index) => {
            const statusClass = s.status === 'Active' ? 'status-active' : 'status-inactive';

            const courseCode = s.enrolled_courses && s.enrolled_courses !== '—'
                ? s.enrolled_courses
                : (s.course_code || '—');
            const courseName = s.enrolled_courses_names && s.enrolled_courses_names !== '—'
                ? s.enrolled_courses_names
                : (s.course_name || '—');

            return `
            <tr class="student-row">
                <td>${index + 1}</td>
                <td><span class="tag">${escapeHTML(s.student_id || '—')}</span></td>
                <td><b>${escapeHTML(s.full_name || '—')}</b></td>
                <td>${escapeHTML(s.level || '—')}</td>
                <td>${escapeHTML(s.programme || '—')}</td>
                <td><code style="background: var(--bg); padding: 2px 6px; border-radius: 4px; font-size: 12px;">${escapeHTML(courseCode)}</code></td>
                <td><small>${escapeHTML(courseName)}</small></td>
                <td><span class="tag ${statusClass}">${s.status || 'Active'}</span></td>
                <td class="action-buttons" style="white-space: nowrap;">
                    <button class="action-btn" onclick="viewStudentCourses(${s.id})" title="View Courses"><i class="fas fa-book"></i></button>
                    <button class="action-btn" onclick="editStudentById(${s.id})" title="Edit Student"><i class="fas fa-edit"></i></button>
                    <button class="action-btn" onclick="showMigrateLevelModal(${s.id}, '${escapeHTML(s.full_name)}', '${escapeHTML(s.level || '')}')" title="Migrate Level"><i class="fas fa-level-up-alt"></i></button>
                    <button class="action-btn" onclick="showEnrollModal(${s.id}, '${escapeHTML(s.full_name)}')" title="Enroll in Course"><i class="fas fa-plus-circle"></i></button>
                    <button class="action-btn" onclick="resetStudentPasswordById(${s.id})" title="Reset Password"><i class="fas fa-key"></i></button>
                    <button class="action-btn" onclick="deleteStudentById(${s.id})" title="Delete" style="color: #ef4444;"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
        }).join('');
    }



    // ============================================
    // 18. GRADING OPTION TOGGLES
    // ============================================

    function toggleAutoGrading(enabled) {
        updateExamField('auto_grading_enabled', enabled ? 1 : 0);
        setGradingMode(enabled ? 'auto' : 'manual', false);
        toast(enabled ? '✅ Automatic grading enabled' : '❌ Automatic grading disabled');
    }

    function togglePartialGrading(enabled) {
        updateExamField('partial_grading_enabled', enabled ? 1 : 0);
        if (enabled) setGradingMode('hybrid', false);
        toast(enabled ? '✅ Partial grading enabled' : '❌ Partial grading disabled');
    }

    function toggleShowCorrectAnswers(enabled) {
        updateExamField('show_correct_answers', enabled ? 1 : 0);
        toast(enabled ? '✅ Show correct answers after submission enabled' : '❌ Show correct answers disabled');
    }

    function toggleAllowReview(enabled) {
        updateExamField('allow_review', enabled ? 1 : 0);
        toast(enabled ? '✅ Allow review before submission enabled' : '❌ Review before submission disabled');
    }

    function setGradingMode(mode, showToast = true) {
        const cleanMode = ['auto', 'manual', 'hybrid'].includes(mode) ? mode : 'auto';
        updateExamField('gradingMode', cleanMode);
        updateExamField('auto_grading_enabled', cleanMode === 'manual' ? 0 : 1);
        updateExamField('partial_grading_enabled', cleanMode === 'hybrid' ? 1 : 0);

        const gradingModeSelect = document.getElementById('gradingMode');
        if (gradingModeSelect) gradingModeSelect.value = cleanMode;
        const autoGrading = document.getElementById('enableAutoGrading');
        if (autoGrading) autoGrading.checked = cleanMode !== 'manual';
        const partialGrading = document.getElementById('enablePartialGrading');
        if (partialGrading) partialGrading.checked = cleanMode === 'hybrid';

        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx].questions = (exams[idx].questions || []).map(q => ({
                ...q,
                gradingMode: cleanMode
            }));
            setExams(exams);
            renderQuestions();
        }

        if (showToast) toast(`Grading mode set to ${cleanMode}`);
    }

    function updateExamField(field, value) {
        if (!currentExamId) return;
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx >= 0) {
            exams[idx][field] = value;
            setExams(exams);
            backupExamDraft(exams[idx]);
            console.log(`Updated ${field} = ${value}`);
            if (['questionsToAnswer', 'marks', 'questions'].includes(field)) {
                refreshQuestionAnswerRuleHint();
            }
        }
    }

    function applySearch(route) {
        const searchInput = document.getElementById("examsSearch");
        const q = searchInput ? searchInput.value.trim() : '';
        go(route, q ? {
            q
        } : {});
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.style.display = 'none';
    }

    function previewSchoolLogo(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('schoolLogoPreview');
                const img = document.getElementById('schoolLogoImg');
                if (img) img.src = e.target.result;
                if (preview) preview.style.display = 'block';
                updateExamField('school_logo', e.target.result);
            };
            reader.readAsDataURL(file);
        }
    }

    function updateStudentCredentials() {
        const idInput = document.getElementById('studentId');
        const usernameInput = document.getElementById('studentUsername');
        const passwordInput = document.getElementById('studentPassword');

        if (idInput && usernameInput && passwordInput) {
            const id = idInput.value;
            usernameInput.value = id;
            passwordInput.value = id;
        }
    }

    function addMarkingScheme(type) {
        const scheme = {
            id: Date.now() + Math.random(),
            text: ""
        };

        if (type === 'essay') {
            essaySchemes.push(scheme);
            renderMarkingSchemes('essay');
        } else if (type === 'coding') {
            codingSchemes.push(scheme);
            renderMarkingSchemes('coding');
        } else if (type === 'short') {
            shortSchemes.push(scheme);
            renderMarkingSchemes('short');
        }
    }

    function removeMarkingScheme(type, id) {
        if (type === 'essay') {
            essaySchemes = essaySchemes.filter(s => s.id !== id);
            renderMarkingSchemes('essay');
        } else if (type === 'coding') {
            codingSchemes = codingSchemes.filter(s => s.id !== id);
            renderMarkingSchemes('coding');
        } else if (type === 'short') {
            shortSchemes = shortSchemes.filter(s => s.id !== id);
            renderMarkingSchemes('short');
        }
    }

    function updateMarkingScheme(type, id, value) {
        if (type === 'essay') {
            const scheme = essaySchemes.find(s => s.id === id);
            if (scheme) scheme.text = value;
        } else if (type === 'coding') {
            const scheme = codingSchemes.find(s => s.id === id);
            if (scheme) scheme.text = value;
        } else if (type === 'short') {
            const scheme = shortSchemes.find(s => s.id === id);
            if (scheme) scheme.text = value;
        }
    }

    function renderMarkingSchemes(type) {
        const container = document.getElementById(`${type}SchemesContainer`);
        if (!container) return;

        const schemes = type === 'essay' ? essaySchemes : type === 'coding' ? codingSchemes : shortSchemes;

        container.innerHTML = schemes.map(s =>
            `<div class="marking-scheme-item">
                <div class="marking-scheme-header">
                    <span>Scheme ${schemes.indexOf(s) + 1}</span>
                    <button class="btn danger small" onclick="removeMarkingScheme('${type}', ${s.id})">✖</button>
                </div>
                <textarea class="marking-scheme-text" oninput="updateMarkingScheme('${type}', ${s.id}, this.value)" rows="2" placeholder="Enter marking scheme...">${escapeHTML(s.text)}</textarea>
            </div>`
        ).join('');
    }

    // ============================================
    // 19. INITIALIZATION & EVENT LISTENERS
    // ============================================

    document.addEventListener('DOMContentLoaded', async function() {
        loadTheme();
        loadProfile();
        loadDashboardStats();

        await loadExamsList();

        loadStudents();
        loadSubmissions();
        initResize();
        setStaticHeaderText();
        startAutoSave();

        // Inside DOMContentLoaded, after loading submissions, add:
        await loadSubmissions();
        populateDashboardCourseSelector();
        initDashboard();

        // In the navigation handler, when going to results:
        if (route === "results") {
            initResultsPage();
        }

        const savedView = sessionStorage.getItem('currentView') || localStorage.getItem('currentView');
        const savedExamId = sessionStorage.getItem('currentExamId') || localStorage.getItem(K_LAST_BUILDER_EXAM);
        const savedParams = JSON.parse(sessionStorage.getItem('currentRouteParams') || localStorage.getItem('currentRouteParams') || '{}');

        console.log("Restoring view:", savedView, "Exam ID:", savedExamId);

        if (savedView === 'builder' && (savedExamId || savedParams.id)) {
            const examId = savedExamId || savedParams.id;
            setTimeout(async () => {
                console.log("Restoring builder with exam ID:", examId);
                await openBuilder(examId);
            }, 300);
        } else if (savedView && routes.includes(savedView)) {
            go(savedView, savedParams);
        } else {
            go('dashboard');
        }

        setInterval(loadDashboardStats, 30000);
        setInterval(() => {
            if (document.getElementById('view-exams')?.classList.contains('active')) {
                loadExamsList();
            }
            if (document.getElementById('view-students')?.classList.contains('active') ||
                document.getElementById('view-student-details')?.classList.contains('active')) {
                loadStudents();
            }
            if (document.getElementById('view-submissions')?.classList.contains('active')) {
                loadSubmissions();
            }
            if (document.getElementById('view-results')?.classList.contains('active')) {
                if (typeof initResultsPage === 'function') initResultsPage();
                else renderResults();
            }
            if (document.getElementById('view-proctoring')?.classList.contains('active') && currentProctoringExam) {
                fetchScreenUpdates();
            }
        }, 10000);

        window.addEventListener('beforeunload', function() {
            if (currentExamId) {
                saveAllFormDataToExam();
                backupExamDraft();
                saveExamToDatabase();
                sessionStorage.setItem('currentExamId', currentExamId);
                localStorage.setItem(K_LAST_BUILDER_EXAM, String(currentExamId));
            }
            sessionStorage.setItem('currentView', routeState.route);
            sessionStorage.setItem('currentRouteParams', JSON.stringify(routeState.params));
            localStorage.setItem('currentView', routeState.route);
            localStorage.setItem('currentRouteParams', JSON.stringify(routeState.params));
        });

        document.addEventListener('click', function(e) {
            const panel = document.getElementById('submenuPanel');
            if (!panel) return;
            const isClickInside = panel.contains(e.target);
            const isClickOnNavIcon = e.target.closest('.nav-icon');
            if (panel.classList.contains('open') && !isClickInside && !isClickOnNavIcon) {
                closeSubmenuPanel();
            }
        });

        // Initialize real-time form saving
        initRealTimeFormSaving();

        // Add a save button or auto-save on page unload
        window.addEventListener('beforeunload', function() {
            if (currentExamId && document.getElementById('view-builder')?.classList.contains(
                    'active')) {
                saveAllFormDataToExam();
                backupExamDraft();
                console.log("Auto-saved form data before page unload");
            }
        });

        // Close submenu when clicking outside
        document.addEventListener('click', function(e) {
            const panel = document.getElementById('submenuPanel');
            const sidebar = document.getElementById('sidebar');

            if (!panel) return;

            // Check if click is outside the submenu panel and outside the nav icons that have submenus
            const isClickInsidePanel = panel.contains(e.target);
            const isClickOnNavIcon = e.target.closest('.nav-icon');
            const isClickOnSubmenuItem = e.target.closest('.submenu-item');

            // If clicking on a submenu item, handle it in the item's onclick
            if (isClickOnSubmenuItem) {
                return;
            }

            // If clicking outside panel and not on a nav icon, close the panel
            if (panel.classList.contains('open') && !isClickInsidePanel && !isClickOnNavIcon) {
                closeSubmenuPanel();
            }
        });

        // Also close submenu when clicking on the main content area
        document.querySelector('.main')?.addEventListener('click', function() {
            closeSubmenuPanel();
        });

        // Close submenu on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSubmenuPanel();
            }
        });


        // Add Ctrl+S shortcut to save
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (currentExamId) {
                    saveAllFormDataToExam();
                    toast('✅ Exam data saved');
                }
            }
        });

        const studentSearch = document.getElementById('studentSearchInput');
        const levelFilter = document.getElementById('levelFilter');
        const programmeFilter = document.getElementById('programmeFilter');
        const statusFilter = document.getElementById('statusFilter');
        const studentDetailsSearch = document.getElementById('studentDetailsSearchInput');
        const studentDetailsLevelFilter = document.getElementById('studentDetailsLevelFilter');
        const studentDetailsProgrammeFilter = document.getElementById('studentDetailsProgrammeFilter');
        const studentDetailsStatusFilter = document.getElementById('studentDetailsStatusFilter');

        if (studentSearch) studentSearch.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
        if (levelFilter) levelFilter.addEventListener('change', applyFilters);
        if (programmeFilter) programmeFilter.addEventListener('change', applyFilters);
        if (statusFilter) statusFilter.addEventListener('change', applyFilters);

        if (studentDetailsSearch) studentDetailsSearch.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyStudentDetailsFilters();
        });
        if (studentDetailsLevelFilter) studentDetailsLevelFilter.addEventListener('change',
            applyStudentDetailsFilters);
        if (studentDetailsProgrammeFilter) studentDetailsProgrammeFilter.addEventListener('change',
            applyStudentDetailsFilters);
        if (studentDetailsStatusFilter) studentDetailsStatusFilter.addEventListener('change',
            applyStudentDetailsFilters);

        const studentIdInput = document.getElementById('studentId');
        if (studentIdInput) {
            studentIdInput.addEventListener('input', updateStudentCredentials);
        }

        // Focus on the first field when builder is opened
        function focusFirstField() {
            const titleField = document.getElementById('bTitle');
            if (titleField) {
                setTimeout(() => {
                    titleField.focus();
                    titleField.placeholder =
                        'e.g., Final Examination - Introduction to Programming';
                }, 100);
            }
        }

        // Call this when switching to builder view
        // Add this inside your go() function when route === 'builder'
        if (route === 'builder') {
            setTimeout(focusFirstField, 200);
        }
    });

    window.addEventListener("hashchange", parseHash);
    parseHash();

    // ========== ADD FORM FIELD EVENT LISTENERS ==========
    function initializeFormListeners() {
        console.log("Initializing form field listeners...");

        // Title field
        const titleField = document.getElementById('bTitle');
        if (titleField) {
            titleField.addEventListener('change', function() {
                updateExamField('title', this.value);
            });
            titleField.addEventListener('input', function() {
                updateExamField('title', this.value);
            });
        }

        // Course code field
        const courseField = document.getElementById('bCode');
        if (courseField) {
            courseField.addEventListener('change', function() {
                updateExamField('courseCode', this.value);
            });
            courseField.addEventListener('input', function() {
                updateExamField('courseCode', this.value);
            });
        }

        // Duration field
        const durationField = document.getElementById('bDuration');
        if (durationField) {
            durationField.addEventListener('change', function() {
                updateExamField('durationMins', parseInt(this.value) || 180);
                syncEndTimeFromDuration();
                syncCutoffFromGrace();
            });
            durationField.addEventListener('input', function() {
                syncEndTimeFromDuration();
                syncCutoffFromGrace();
            });
        }

        // Instructions field
        const instructionsField = document.getElementById('bInstructions');
        if (instructionsField) {
            instructionsField.addEventListener('change', function() {
                updateExamField('instructions', this.value);
            });
        }

        // School Name field
        const schoolNameField = document.getElementById('schoolName');
        if (schoolNameField) {
            schoolNameField.addEventListener('change', function() {
                updateExamField('school_name', this.value);
            });
            schoolNameField.addEventListener('input', function() {
                updateExamField('school_name', this.value);
            });
        }

        // Faculty Name field
        const facultyField = document.getElementById('facultyName');
        if (facultyField) {
            facultyField.addEventListener('change', function() {
                updateExamField('faculty_name', this.value);
            });
            facultyField.addEventListener('input', function() {
                updateExamField('faculty_name', this.value);
            });
        }

        // Department field
        const deptField = document.getElementById('department');
        if (deptField) {
            deptField.addEventListener('change', function() {
                updateExamField('department', this.value);
            });
            deptField.addEventListener('input', function() {
                updateExamField('department', this.value);
            });
        }

        // Semester dropdown
        const semesterField = document.getElementById('semester');
        if (semesterField) {
            semesterField.addEventListener('change', function() {
                updateExamField('semester', this.value);
            });
        }

        // Exam Type dropdown
        const examTypeField = document.getElementById('examType');
        if (examTypeField) {
            examTypeField.addEventListener('change', function() {
                updateExamField('exam_type', this.value);
            });
        }

        // School Type dropdown
        const schoolTypeField = document.getElementById('schoolType');
        if (schoolTypeField) {
            schoolTypeField.addEventListener('change', function() {
                updateExamField('school_type', this.value);
            });
        }

        // Level dropdown
        const levelField = document.getElementById('examLevel');
        if (levelField) {
            levelField.addEventListener('change', function() {
                updateExamField('level', this.value);
            });
        }

        // Start date time field
        const startField = document.getElementById('bStartAt');
        if (startField) {
            startField.addEventListener('change', function() {
                updateExamField('startAtISO', this.value);
                syncSchedulePartsFromStart();
                syncDurationFromStartEnd();
                syncCutoffFromGrace();
            });
        }

        const endField = document.getElementById('bEndAt');
        if (endField) {
            endField.addEventListener('change', function() {
                updateExamField('endAtISO', this.value);
                syncDurationFromStartEnd();
                syncCutoffFromGrace();
            });
        }

        // Exam password field
        const passwordField = document.getElementById('examPassword');
        if (passwordField) {
            passwordField.addEventListener('change', function() {
                updateExamField('exam_password', this.value);
            });
        }

        // Questions to answer field
        const qtaField = document.getElementById('bQuestionsToAnswer');
        if (qtaField) {
            qtaField.addEventListener('change', function() {
                updateExamField('questionsToAnswer', parseInt(this.value) || 0);
            });
            qtaField.addEventListener('input', function() {
                updateExamField('questionsToAnswer', parseInt(this.value) || 0);
            });
        }

        // Exam Code field
        const examCodeField = document.getElementById('examCode');
        if (examCodeField) {
            examCodeField.addEventListener('change', function() {
                updateExamField('exam_code', this.value);
            });
        }

        // Grading option checkboxes
        const autoGradingCheckbox = document.getElementById('enableAutoGrading');
        if (autoGradingCheckbox) {
            autoGradingCheckbox.addEventListener('change', function() {
                updateExamField('auto_grading_enabled', this.checked ? 1 : 0);
            });
        }

        const partialGradingCheckbox = document.getElementById('enablePartialGrading');
        if (partialGradingCheckbox) {
            partialGradingCheckbox.addEventListener('change', function() {
                updateExamField('partial_grading_enabled', this.checked ? 1 : 0);
            });
        }

        const showAnswersCheckbox = document.getElementById('showCorrectAnswers');
        if (showAnswersCheckbox) {
            showAnswersCheckbox.addEventListener('change', function() {
                updateExamField('show_correct_answers', this.checked ? 1 : 0);
            });
        }

        const allowReviewCheckbox = document.getElementById('allowReview');
        if (allowReviewCheckbox) {
            allowReviewCheckbox.addEventListener('change', function() {
                updateExamField('allow_review', this.checked ? 1 : 0);
            });
        }
    }

    // Call this after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeFormListeners);
    } else {
        initializeFormListeners();
    }

    function parseHash() {
        const h = (window.location.hash || "").replace("#", "");
        if (!h) {
            const savedRoute = sessionStorage.getItem("currentView") || localStorage.getItem("currentView") || "dashboard";
            let savedParams = {};
            try {
                savedParams = JSON.parse(sessionStorage.getItem("currentRouteParams") || localStorage.getItem("currentRouteParams") || "{}") || {};
            } catch (error) {
                savedParams = {};
            }
            return go(savedRoute, savedParams);
        }
        const [r, q] = h.split("?");
        const params = Object.fromEntries(new URLSearchParams(q || ""));
        go(r || "dashboard", params);
    }

    function initResize() {
        const handle = document.getElementById('resizeHandle');
        const sidebar = document.getElementById('sidebar');
        const layout = document.querySelector('.layout');

        if (!handle) return;

        handle.addEventListener('mousedown', (e) => {
            isResizing = true;
            document.body.style.cursor = 'col-resize';
        });

        document.addEventListener('mousemove', (e) => {
            if (!isResizing) return;
            const newWidth = e.clientX;
            if (newWidth >= 200 && newWidth <= 400) {
                sidebarWidth = newWidth;
                if (layout) layout.style.gridTemplateColumns = `${newWidth}px 1fr`;
                if (handle) handle.style.left = `${newWidth}px`;
            }
        });

        document.addEventListener('mouseup', () => {
            isResizing = false;
            document.body.style.cursor = 'default';
        });
    }

    function logout() {
        if (confirm('Are you sure you want to logout?')) {
            if (currentExamId) {
                saveExamToDatabase();
            }
            localStorage.clear();
            sessionStorage.clear();
            window.location.href = 'logout.php';
        }
    }

    function openMarkingModal(submissionId) {
        const subs = getSubs();
        const submission = subs.find(s => s.id === submissionId);
        if (!submission) return;

        const exam = findExam(submission.examId);
        if (!exam) return;

        const student = getStudents().find(s => s.id === submission.studentId);

        let contentHtml = `
            <div style="padding: 10px;">
                <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                    <h3 style="margin: 0 0 10px 0; color: var(--text);">
                        <i class="fas fa-user-graduate"></i> ${escapeHTML(student?.fullName || submission.studentId)}
                    </h3>
                    <p style="margin: 5px 0; color: var(--muted);">
                        <i class="fas fa-file"></i> ${escapeHTML(exam.title)} | 
                        <i class="fas fa-clock"></i> Submitted: ${new Date(submission.submittedAtISO).toLocaleString()}
                    </p>
                </div>
                
                <div id="gradingQuestions">
        `;

        exam.questions.forEach(question => {
            const answer = submission.items?.find(item => item.qId === question.id);
            const studentAnswer = answer?.answerText || '';

            if (question.type === 'code') {
                contentHtml += renderCodingGrading(question, studentAnswer, answer?.manualScore || 0);
            }
        });

        contentHtml += `
                </div>
                
                <div style="margin-top: 20px; padding-top: 15px; border-top: 2px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                    <button class="btn" onclick="closeMarkingModal()">Cancel</button>
                    <button class="btn primary" onclick="saveAllMarks('${submissionId}')">
                        <i class="fas fa-save"></i> Save Marks
                    </button>
                    <button class="btn ok" onclick="finalizeGrading('${submissionId}')">
                        <i class="fas fa-check-circle"></i> Finalize & Publish
                    </button>
                </div>
            </div>
        `;

        const markingContent = document.getElementById('markingContent');
        if (markingContent) markingContent.innerHTML = contentHtml;

        const modal = document.getElementById('markingModal');
        if (modal) modal.style.display = 'flex';
    }

    function renderCodingGrading(question, studentAnswer, currentMarks = 0) {
        let html = `
            <div style="padding: 20px; background: var(--panel); border-radius: 12px; margin-bottom: 20px;">
                <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h4 style="color: var(--text); margin: 0;">
                            <i class="fas fa-code" style="color: #10b981;"></i> 
                            Coding Question (${question.marks} marks)
                        </h4>
                        <span class="tag">ID: ${question.id}</span>
                    </div>
                    <p style="color: var(--text); font-size: 15px; line-height: 1.6;">${escapeHTML(question.text || '')}</p>
                    <div style="margin-top: 10px;">
                        <span class="tag" style="background: #10b981; color: white;">Language: ${escapeHTML(question.language || 'Python')}</span>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text);">
                        <i class="fas fa-user-graduate"></i> Student's Code:
                    </label>
                    <div style="padding: 16px; background: #1e1e1e; border-radius: 10px; border: 1px solid var(--border);">
                        <pre style="margin: 0; color: #d4d4d4; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.5; white-space: pre-wrap; overflow-x: auto;">${escapeHTML(studentAnswer) || '<em style="color: var(--muted);">No code provided</em>'}</pre>
                    </div>
                </div>`;

        if (question.expectedOutput) {
            html += `
                <div style="margin-bottom: 20px; padding: 14px; background: rgba(16, 185, 129, 0.08); border-radius: 8px; border-left: 4px solid #10b981;">
                    <div style="font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #10b981;">
                        <i class="fas fa-check-circle"></i> Expected Output:
                    </div>
                    <pre style="margin: 0; color: var(--text); font-family: monospace; white-space: pre-wrap;">${escapeHTML(question.expectedOutput)}</pre>
                </div>`;
        }

        if (question.testCases && question.testCases.length > 0) {
            html += `
                <div style="margin-bottom: 20px;">
                    <div style="font-size: 13px; font-weight: 600; margin-bottom: 12px; color: var(--text);">
                        <i class="fas fa-vial"></i> Test Cases:
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        ${question.testCases.map((tc, idx) => `
                            <div style="padding: 10px; background: var(--bg); border-radius: 8px;">
                                <div style="font-size: 12px; color: var(--muted);">Test Case ${idx + 1}</div>
                                <div><strong>Input:</strong> <code>${escapeHTML(tc.input)}</code></div>
                                <div><strong>Expected:</strong> <code>${escapeHTML(tc.expected)}</code></div>
                                <div><strong>Marks:</strong> ${tc.marks}</div>
                            </div>
                        `).join('')}
                    </div>
                </div>`;
        }

        html += `
                <div style="margin-top: 20px; padding: 16px; background: var(--bg); border-radius: 10px;">
                    <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <label style="font-size: 14px; font-weight: 600; color: var(--text);">
                                <i class="fas fa-star" style="color: #f59e0b;"></i> Marks Awarded:
                            </label>
                            <input type="number" id="codingMarks_${question.id}" 
                                value="${currentMarks}" min="0" max="${question.marks}" step="0.5"
                                style="width: 100px; padding: 10px; border-radius: 8px; border: 2px solid var(--border); 
                                background: var(--input-bg); color: var(--text); font-size: 14px; font-weight: 600; text-align: center;"
                                onchange="updateCodingMarks('${question.id}', this.value)">
                            <span style="color: var(--muted);">/ ${question.marks}</span>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text);">
                        <i class="fas fa-comment"></i> Feedback for Student:
                    </label>
                    <textarea id="codingFeedback_${question.id}" rows="3" 
                        style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid var(--border); 
                        background: var(--input-bg); color: var(--text); font-size: 13px; resize: vertical;"
                        placeholder="Provide feedback to the student..."></textarea>
                </div>
            </div>
        `;

        return html;
    }

    function updateCodingMarks(questionId, value) {
        const marks = parseFloat(value) || 0;
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (idx < 0) return;

        const q = exams[idx].questions.find(x => x.id === questionId);
        if (!q) return;

        if (marks > q.marks) {
            toast(`⚠️ Marks cannot exceed ${q.marks}`);
            document.getElementById(`codingMarks_${questionId}`).value = q.marks;
        }
    }

    async function saveAllMarks(submissionId) {
        const subs = getSubs();
        const submission = subs.find(s => s.id === submissionId);
        if (!submission) return;

        const exam = findExam(submission.examId);
        if (!exam) return;

        let totalScore = 0;
        let totalMarks = 0;

        exam.questions.forEach(question => {
            if (question.type === 'code') {
                const marksInput = document.getElementById(`codingMarks_${question.id}`);
                const feedbackInput = document.getElementById(`codingFeedback_${question.id}`);

                const marks = parseFloat(marksInput?.value) || 0;
                const feedback = feedbackInput?.value || '';

                const answerItem = submission.items?.find(item => item.qId === question.id);
                if (answerItem) {
                    answerItem.manualScore = marks;
                    answerItem.feedback = feedback;
                }

                totalScore += marks;
                totalMarks += question.marks;
            }
        });

        submission.scoreManual = totalScore;
        submission.status = totalScore > 0 ? 'MARKED' : 'PENDING';

        setSubs(subs);

        toast(`✅ Marks saved! Total: ${totalScore}/${totalMarks}`);
    }

    async function finalizeGrading(submissionId) {
        await saveAllMarks(submissionId);

        const subs = getSubs();
        const submission = subs.find(s => s.id === submissionId);
        if (submission) {
            submission.status = 'MARKED';
            submission.markedAt = new Date().toISOString();
            setSubs(subs);
        }

        toast('🚀 Grading finalized and published to student');
        closeMarkingModal();
        loadSubmissions();
    }

    function publishSubmission(submissionId) {
        toast(`Publishing submission ${submissionId}`);
    }

    function captureAllExamFormData() {
        console.log("===== CAPTURING ALL FORM DATA =====");

        const selectedGradingMode = document.getElementById('gradingMode')?.value || 'auto';
        const formData = {
            title: document.getElementById('bTitle')?.value || '',
            courseCode: document.getElementById('bCode')?.value || '',
            durationMins: parseInt(document.getElementById('bDuration')?.value) || 180,
            instructions: document.getElementById('bInstructions')?.value || '',
            startAtISO: document.getElementById('bStartAt')?.value || '',
            endAtISO: document.getElementById('bEndAt')?.value || '',
            gracePeriodMinutes: parseInt(document.getElementById('bGracePeriod')?.value) || 0,
            cutoffAtISO: document.getElementById('bCutoffAt')?.value || '',
            questionsToAnswer: parseInt(document.getElementById('bQuestionsToAnswer')?.value) || 0,
            exam_password: document.getElementById('examPassword')?.value || '',
            school_name: document.getElementById('schoolName')?.value || '',
            faculty_name: document.getElementById('facultyName')?.value || '',
            department: document.getElementById('department')?.value || '',
            school_type: document.getElementById('schoolType')?.value || '',
            semester: document.getElementById('semester')?.value || '',
            exam_type: document.getElementById('examType')?.value || '',
            level: document.getElementById('examLevel')?.value || '',
            exam_code: document.getElementById('examCode')?.value || '',
            gradingMode: selectedGradingMode,
            auto_grading_enabled: selectedGradingMode === 'manual' ? 0 : 1,
            partial_grading_enabled: selectedGradingMode === 'hybrid' ? 1 : 0,
            show_correct_answers: 0,
            allow_review: 1,
            questions: []
        };

        const exams = getExams();
        const exam = exams.find(e => parseInt(e.id) === parseInt(currentExamId));

        if (exam && exam.questions) {
            formData.questions = exam.questions;
        }

        console.log("Captured form data:", formData);
        return formData;
    }

    function initRealTimeFormSaving() {
        console.log("Initializing real-time form saving...");

        const fieldsToMonitor = [{
                id: 'bTitle',
                property: 'title'
            },
            {
                id: 'bCode',
                property: 'courseCode'
            },
            {
                id: 'bDuration',
                property: 'durationMins',
                parser: (v) => parseInt(v) || 180
            },
            {
                id: 'bInstructions',
                property: 'instructions'
            },
            {
                id: 'bStartAt',
                property: 'startAtISO'
            },
            {
                id: 'bEndAt',
                property: 'endAtISO'
            },
            {
                id: 'bGracePeriod',
                property: 'gracePeriodMinutes',
                parser: (v) => parseInt(v) || 0
            },
            {
                id: 'bCutoffAt',
                property: 'cutoffAtISO'
            },
            {
                id: 'bQuestionsToAnswer',
                property: 'questionsToAnswer',
                parser: (v) => parseInt(v) || 0
            },
            {
                id: 'examPassword',
                property: 'exam_password'
            },
            {
                id: 'schoolName',
                property: 'school_name'
            },
            {
                id: 'facultyName',
                property: 'faculty_name'
            },
            {
                id: 'department',
                property: 'department'
            },
            {
                id: 'semester',
                property: 'semester'
            },
            {
                id: 'examType',
                property: 'exam_type'
            },
            {
                id: 'schoolType',
                property: 'school_type'
            },
            {
                id: 'examLevel',
                property: 'level'
            },
            {
                id: 'examCode',
                property: 'exam_code'
            }
        ];

        fieldsToMonitor.forEach(field => {
            const element = document.getElementById(field.id);
            if (element) {
                element.addEventListener('change', function() {
                    let value = this.value;
                    if (field.parser) value = field.parser(value);
                    updateExamField(field.property, value);
                });
                if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                    element.addEventListener('input', function() {
                        let value = this.value;
                        if (field.parser) value = field.parser(value);
                        updateExamField(field.property, value);
                    });
                }
            } else {
                console.warn(`Field ${field.id} not found`);
            }
        });

        const checkboxesToMonitor = [{
                id: 'enableAutoGrading',
                property: 'auto_grading_enabled'
            },
            {
                id: 'enablePartialGrading',
                property: 'partial_grading_enabled'
            },
            {
                id: 'showCorrectAnswers',
                property: 'show_correct_answers'
            },
            {
                id: 'allowReview',
                property: 'allow_review'
            }
        ];

        checkboxesToMonitor.forEach(field => {
            const element = document.getElementById(field.id);
            if (element) {
                element.addEventListener('change', function() {
                    updateExamField(field.property, this.checked ? 1 : 0);
                });
            }
        });

        console.log("✅ Real-time form saving initialized");
    }

    function saveAllFormDataToExam() {
        if (!currentExamId) return false;

        const formData = captureAllExamFormData();
        const exams = getExams();
        const idx = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));

        if (idx < 0) return false;

        exams[idx].title = formData.title;
        exams[idx].courseCode = formData.courseCode;
        exams[idx].durationMins = formData.durationMins;
        exams[idx].instructions = formData.instructions;
        exams[idx].startAtISO = formData.startAtISO;
        exams[idx].endAtISO = formData.endAtISO;
        exams[idx].gracePeriodMinutes = formData.gracePeriodMinutes;
        exams[idx].cutoffAtISO = formData.cutoffAtISO;
        exams[idx].questionsToAnswer = formData.questionsToAnswer;
        exams[idx].exam_password = formData.exam_password;
        exams[idx].school_name = formData.school_name;
        exams[idx].faculty_name = formData.faculty_name;
        exams[idx].department = formData.department;
        exams[idx].school_type = formData.school_type;
        exams[idx].semester = formData.semester;
        exams[idx].exam_type = formData.exam_type;
        exams[idx].level = formData.level;
        exams[idx].exam_code = formData.exam_code;
        exams[idx].auto_grading_enabled = formData.auto_grading_enabled;
        exams[idx].partial_grading_enabled = formData.partial_grading_enabled;
        exams[idx].show_correct_answers = formData.show_correct_answers;
        exams[idx].allow_review = formData.allow_review;

        exams[idx].questions = formData.questions;

        setExams(exams);
        backupExamDraft(exams[idx]);
        console.log("✅ All form data saved to exam object");
        return true;
    }
    // ============================================
    // MODERN CODING QUESTION AUTHORING WORKFLOW
    // ============================================
    function richToPlain(html) {
        const div = document.createElement('div');
        div.innerHTML = html || '';
        return (div.textContent || div.innerText || '').trim();
    }

    function richValueToHtml(value) {
        const text = String(value || '');
        if (!text) return '';
        if (/<[a-z][\s\S]*>/i.test(text)) return text;
        return escapeHTML(text).replace(/\n/g, '<br>');
    }

    const qodaModelEditors = new Map();
    let qodaMonacoLoading = null;

    function qodaEditorTheme() {
        return 'vs-dark';
    }

    function loadQodaMonaco() {
        if (window.monaco?.editor) return Promise.resolve(window.monaco);
        if (qodaMonacoLoading) return qodaMonacoLoading;
        qodaMonacoLoading = new Promise((resolve, reject) => {
            const ensureLoader = () => {
                if (typeof window.require === 'function') {
                    waitForLoader();
                    return;
                }
                let script = document.querySelector('script[data-qoda-monaco-loader]');
                if (!script) {
                    script = document.createElement('script');
                    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs/loader.js';
                    script.async = true;
                    script.dataset.qodaMonacoLoader = '1';
                    document.head.appendChild(script);
                }
                script.addEventListener('load', waitForLoader, { once: true });
                script.addEventListener('error', () => reject(new Error('Monaco loader failed to load')), { once: true });
                setTimeout(() => {
                    if (typeof window.require === 'function') waitForLoader();
                }, 1200);
            };
            const waitForLoader = () => {
                if (window.monaco?.editor) {
                    resolve(window.monaco);
                    return;
                }
                if (typeof window.require !== 'function') {
                    reject(new Error('Monaco loader is unavailable'));
                    return;
                }
                window.require.config({
                    paths: {
                        vs: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.44.0/min/vs'
                    }
                });
                window.require(['vs/editor/editor.main'], () => resolve(window.monaco), reject);
            };
            ensureLoader();
        }).catch(error => {
            console.warn('Model solution editor fallback:', error);
            qodaMonacoLoading = null;
            throw error;
        });
        return qodaMonacoLoading;
    }

    async function initQodaModelSolutionEditors() {
        const containers = Array.from(document.querySelectorAll('.qoda-monaco-editor[data-question-id]'));
        const liveIds = new Set(containers.map(container => container.id));
        qodaModelEditors.forEach((editor, id) => {
            if (!liveIds.has(id)) {
                editor.dispose();
                qodaModelEditors.delete(id);
            }
        });
        if (!containers.length) return;

        try {
            const monacoApi = await loadQodaMonaco();
            containers.forEach(container => {
                const qId = container.dataset.questionId;
                const lang = container.dataset.language || 'plaintext';
                const field = container.dataset.field || 'modelSolution';
                const fallback = document.getElementById(`${field}_${qId}`);
                const shell = container.closest('.qoda-code-shell');
                let existing = qodaModelEditors.get(container.id);
                if (existing && existing.getDomNode()?.parentElement !== container) {
                    existing.dispose();
                    qodaModelEditors.delete(container.id);
                    existing = null;
                }
                if (existing) {
                    const model = existing.getModel();
                    if (model) monacoApi.editor.setModelLanguage(model, lang);
                    existing.updateOptions({ theme: qodaEditorTheme() });
                    shell?.classList.add('monaco-ready');
                    requestAnimationFrame(() => existing.layout());
                    return;
                }
                const editor = monacoApi.editor.create(container, {
                    value: fallback?.value || '',
                    language: lang,
                    theme: qodaEditorTheme(),
                    automaticLayout: true,
                    minimap: { enabled: false },
                    lineNumbers: 'on',
                    lineNumbersMinChars: 3,
                    glyphMargin: false,
                    folding: false,
                    renderLineHighlight: 'line',
                    tabSize: 4,
                    insertSpaces: true,
                    wordWrap: 'on',
                    scrollBeyondLastLine: false,
                    fontSize: 14,
                    lineHeight: 22
                });
                shell?.classList.add('monaco-ready');
                editor.onDidChangeModelContent(() => {
                    const value = editor.getValue();
                    if (fallback) fallback.value = value;
                    updateQuestion(qId, field, value);
                });
                qodaModelEditors.set(container.id, editor);
                requestAnimationFrame(() => editor.layout());
                setTimeout(() => editor.layout(), 150);
            });
        } catch (error) {
            document.querySelectorAll('.qoda-code-shell.monaco-ready').forEach(shell => shell.classList.remove('monaco-ready'));
        }
    }

    function refreshQodaModelEditorThemes() {
        if (!window.monaco?.editor) return;
        monaco.editor.setTheme(qodaEditorTheme());
        qodaModelEditors.forEach(editor => {
            editor.updateOptions({ theme: qodaEditorTheme() });
            editor.layout();
        });
    }

    function handleQodaCodeTextareaKeydown(event, qId, field) {
        const textarea = event.target;
        if (!textarea || !(textarea instanceof HTMLTextAreaElement)) return;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;

        if (event.key === 'Tab') {
            event.preventDefault();
            textarea.value = `${value.slice(0, start)}    ${value.slice(end)}`;
            textarea.selectionStart = textarea.selectionEnd = start + 4;
            updateQuestion(qId, field, textarea.value);
            return;
        }

        if (event.key === 'Enter') {
            const lineStart = value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
            const currentLine = value.slice(lineStart, start);
            const indent = (currentLine.match(/^\s*/) || [''])[0];
            const extra = /[:{[(]\s*$/.test(currentLine) ? '    ' : '';
            if (indent || extra) {
                event.preventDefault();
                const insert = `\n${indent}${extra}`;
                textarea.value = `${value.slice(0, start)}${insert}${value.slice(end)}`;
                textarea.selectionStart = textarea.selectionEnd = start + insert.length;
                updateQuestion(qId, field, textarea.value);
            }
        }
    }

    function normalizeCodingQuestion(q) {
        q.type = 'code';
        q.text = q.text || richToPlain(q.problemStatement || '');
        q.problemStatement = q.problemStatement || q.text || '';
        q.inputFormat = '';
        q.outputFormat = '';
        q.constraints = q.constraints || '';
        q.notes = q.notes || '';
        q.sampleCases = Array.isArray(q.sampleCases) ? q.sampleCases : [];
        q.testCases = Array.isArray(q.testCases) ? q.testCases : [];
        q.language = q.language || 'Python';
        q.languageMode = q.languageMode || 'single';
        q.starterCode = q.starterCode || '';
        q.modelSolution = q.modelSolution || '';
        q.difficulty = q.difficulty || 'Easy';
        q.comparisonMode = q.comparisonMode || 'exact';
        q.markingRubric = [];
        q.executionSettings = Object.assign({
            timeLimit: 2,
            memoryLimit: 128,
            cpuLimit: 2,
            comparisonMode: 'exact',
            ignoreWhitespace: true,
            ignoreTrailingSpaces: true,
            ignoreBlankLines: false,
            caseSensitive: true,
            customValidator: ''
        }, q.executionSettings || {});
        q.securitySettings = Object.assign({
            allowRunCode: true,
            allowCompile: true,
            allowCustomInput: true,
            showPassedTestCases: true,
            showHiddenTestCases: false,
            detectCopyPaste: true,
            detectTabSwitching: true,
            detectMultipleScreens: true,
            autoSave: true,
            autoSubmit: true
        }, q.securitySettings || {});
        q.questionBankTags = q.questionBankTags || '';
        q.topic = q.topic || '';
        q.subtopic = q.subtopic || '';
        q.tags = q.tags || '';
        q.marks = parseFloat(q.marks || 5);
        q.compulsory = !!q.compulsory;
        return q;
    }

    function createCodeQuestionObject() {
        return normalizeCodingQuestion({
            id: uid('Q'),
            title: '',
            type: 'code',
            text: '',
            marks: 5,
            compulsory: false,
            language: 'Python',
            difficulty: 'Easy',
            gradingMode: 'auto',
            sampleCases: [],
            testCases: [],
            markingRubric: [{
                    criterion: 'Input Handling',
                    marks: 1,
                    description: 'Reads the required input correctly.'
                },
                {
                    criterion: 'Logic',
                    marks: 2,
                    description: 'Solves the problem using correct logic.'
                },
                {
                    criterion: 'Output',
                    marks: 1,
                    description: 'Displays output in the required format.'
                },
                {
                    criterion: 'Code Quality',
                    marks: 1,
                    description: 'Uses clear, maintainable code.'
                }
            ]
        });
    }

    function getQuestionById(qId) {
        const exams = getExams();
        const examIndex = exams.findIndex(e => parseInt(e.id) === parseInt(currentExamId));
        if (examIndex < 0) return null;
        const qIndex = (exams[examIndex].questions || []).findIndex(q => q.id === qId);
        if (qIndex < 0) return null;
        return {
            exams,
            examIndex,
            qIndex,
            question: normalizeCodingQuestion(exams[examIndex].questions[qIndex])
        };
    }

    function persistQuestionMutation(ctx, rerender = false) {
        ctx.exams[ctx.examIndex].questions[ctx.qIndex] = ctx.question;
        setExams(ctx.exams);
        backupExamDraft(ctx.exams[ctx.examIndex]);
        refreshQuestionAnswerRuleHint();
        saveExamToDatabase();
        if (rerender) renderQuestions();
        updateBuilderValidationSummary();
    }

    function updateQuestion(qId, field, value) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question[field] = value;
        if (field === 'marks') {
            ctx.question.marks = parseFloat(value) || 0;
            if (Array.isArray(ctx.question.markingRubric) && ctx.question.markingRubric.length) {
                ctx.question.markingRubric = distributeMarks(ctx.question.marks, ctx.question.markingRubric.map(row => [
                    row.criterion || 'Criterion',
                    row.description || 'Award marks for this requirement.'
                ]));
            }
        }
        if (field === 'problemStatement') ctx.question.text = richToPlain(value);
        persistQuestionMutation(ctx, field === 'marks' || field === 'compulsory' || field === 'difficulty');
    }

    function updateQuestionTextField(qId, field, value) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question[field] = value;
        if (field === 'problemStatement') ctx.question.text = richToPlain(value);
        persistQuestionMutation(ctx, false);
        const preview = document.getElementById(`qPreview_${qId}`);
        if (preview) preview.innerHTML = renderQuestionPreview(ctx.question);
    }

    function updateNestedQuestion(qId, group, field, value) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question[group] = ctx.question[group] || {};
        ctx.question[group][field] = value;
        persistQuestionMutation(ctx, false);
    }

    function formatRichField(qId, field, command) {
        const editor = document.getElementById(`rich_${field}_${qId}`);
        if (!editor) return;
        editor.focus();
        if (command === 'code') {
            document.execCommand('insertHTML', false, '<pre><code>// code block</code></pre>');
        } else if (command === 'table') {
            document.execCommand('insertHTML', false,
                '<table data-qoda-numbered="1"><tr><th>#</th><th>Input</th><th>Output</th></tr><tr><td>1</td><td></td><td></td></tr><tr><td>2</td><td></td><td></td></tr></table>');
        } else if (command === 'image') {
            insertRichImageFromDrive(qId, field);
            return;
        } else if (command === 'math') {
            document.execCommand('insertHTML', false, '<span class="math-inline">\\( formula \\)</span>');
        } else {
            document.execCommand(command, false, null);
        }
        updateQuestionTextField(qId, field, editor.innerHTML);
    }

    function activeRichTable(editor) {
        const selection = window.getSelection();
        let node = selection?.anchorNode || null;
        while (node && node !== editor) {
            if (node.nodeType === 1 && node.tagName === 'TABLE') return node;
            node = node.parentNode;
        }
        return editor.querySelector('table:last-of-type');
    }

    function renumberRichTable(table) {
        if (!table || table.dataset.qodaNumbered !== '1') return;
        Array.from(table.rows).forEach((row, index) => {
            const first = row.cells[0] || row.insertCell(0);
            first.textContent = index === 0 ? '#' : String(index);
        });
    }

    function editRichTable(qId, field, action) {
        const editor = document.getElementById(`rich_${field}_${qId}`);
        if (!editor) return;
        editor.focus();
        let table = activeRichTable(editor);
        if (!table) {
            formatRichField(qId, field, 'table');
            table = activeRichTable(editor);
        }
        if (!table) return;
        if (action === 'row') {
            const source = table.rows[table.rows.length - 1] || table.insertRow();
            const row = table.insertRow(-1);
            const cells = Math.max(source.cells.length, 2);
            for (let i = 0; i < cells; i++) row.insertCell(-1).innerHTML = i === 0 && table.dataset.qodaNumbered === '1' ? '' : '&nbsp;';
        }
        if (action === 'column') {
            Array.from(table.rows).forEach((row, index) => {
                const cell = index === 0 ? document.createElement('th') : document.createElement('td');
                cell.innerHTML = index === 0 ? 'Column' : '&nbsp;';
                row.appendChild(cell);
            });
        }
        if (action === 'number') table.dataset.qodaNumbered = '1';
        renumberRichTable(table);
        updateQuestionTextField(qId, field, editor.innerHTML);
    }

    function insertRichImageFromDrive(qId, field) {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = () => {
            const file = input.files?.[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = () => {
                const img = new Image();
                img.onload = () => {
                    const maxWidth = 1100;
                    const scale = Math.min(1, maxWidth / img.width);
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.round(img.width * scale));
                    canvas.height = Math.max(1, Math.round(img.height * scale));
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    const dataUrl = canvas.toDataURL('image/jpeg', 0.78);
                    const editor = document.getElementById(`rich_${field}_${qId}`);
                    if (!editor) return;
                    editor.focus();
                    document.execCommand('insertHTML', false, `<img src="${dataUrl}" alt="Question image">`);
                    updateQuestionTextField(qId, field, editor.innerHTML);
                };
                img.src = String(reader.result || '');
            };
            reader.readAsDataURL(file);
        };
        input.click();
    }

    function renderRichEditor(q, field, label, placeholder) {
        return `
            <div class="qoda-field">
                <label>${label}</label>
                <div class="qoda-rich-wrap">
                    <div class="qoda-rich-toolbar" aria-label="${escapeHTML(label)} tools">
                        <button type="button" title="Bold" onclick="formatRichField('${q.id}','${field}','bold')"><b>B</b></button>
                        <button type="button" title="Italic" onclick="formatRichField('${q.id}','${field}','italic')"><i>I</i></button>
                        <button type="button" title="Bulleted list" onclick="formatRichField('${q.id}','${field}','insertUnorderedList')"><i class="fas fa-list-ul"></i></button>
                        <button type="button" title="Numbered list" onclick="formatRichField('${q.id}','${field}','insertOrderedList')"><i class="fas fa-list-ol"></i></button>
                        <button type="button" title="Code block" onclick="formatRichField('${q.id}','${field}','code')"><i class="fas fa-code"></i></button>
                        <button type="button" title="Table" onclick="formatRichField('${q.id}','${field}','table')"><i class="fas fa-table"></i></button>
                        <button type="button" title="Add table row" onclick="editRichTable('${q.id}','${field}','row')"><i class="fas fa-grip-lines"></i></button>
                        <button type="button" title="Add table column" onclick="editRichTable('${q.id}','${field}','column')"><i class="fas fa-columns"></i></button>
                        <button type="button" title="Number table rows" onclick="editRichTable('${q.id}','${field}','number')"><i class="fas fa-list-ol"></i></button>
                        <button type="button" title="Image" onclick="formatRichField('${q.id}','${field}','image')"><i class="fas fa-image"></i></button>
                        <button type="button" title="Formula" onclick="formatRichField('${q.id}','${field}','math')"><i class="fas fa-square-root-variable"></i></button>
                    </div>
                    <div id="rich_${field}_${q.id}" class="qoda-rich-editor" contenteditable="true"
                        data-placeholder="${escapeHTML(placeholder)}"
                        oninput="updateQuestionTextField('${q.id}','${field}',this.innerHTML)">${richValueToHtml(q[field])}</div>
                </div>
            </div>`;
    }

    function getQuestionValidation(q) {
        normalizeCodingQuestion(q);
        const checks = [{
                label: 'Question entered',
                ok: !!richToPlain(q.problemStatement || q.text)
            },
            {
                label: 'Marks assigned',
                ok: (parseFloat(q.marks) || 0) > 0
            },
            {
                label: 'Language selected',
                ok: q.languageMode === 'multi' || !!q.language
            },
            {
                label: 'Test cases created',
                ok: (q.testCases || []).length > 0
            },
            {
                label: 'Model solution provided',
                ok: !!String(q.modelSolution || '').trim()
            }
        ];
        checks.push({
            label: 'Question ready for publication',
            ok: checks.every(c => c.ok)
        });
        return checks;
    }

    function renderValidationChecklist(q) {
        return `<ul class="qoda-validation-list">${getQuestionValidation(q).map(item => `
            <li class="${item.ok ? 'ready' : 'missing'}">
                <i class="fas ${item.ok ? 'fa-check-circle' : 'fa-circle-exclamation'}"></i>
                <span>${escapeHTML(item.label)}</span>
            </li>`).join('')}</ul>`;
    }

    function renderValidationStatusPanel(q) {
        const checks = getQuestionValidation(q);
        const ready = checks.filter(item => item.ok).length;
        const warningLabels = ['test case', 'model solution'];
        const items = checks.map(item => {
            const lower = item.label.toLowerCase();
            const status = item.ok ? 'ready' : (warningLabels.some(label => lower.includes(label)) ? 'warning' : 'missing');
            const icon = item.ok ? 'fa-check-circle' : (status === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-exclamation');
            return `
                <div class="qoda-validation-item ${status}">
                    <i class="fas ${icon}"></i>
                    <span>${escapeHTML(item.label)}</span>
                </div>`;
        }).join('');
        return `
            <div class="qoda-validation-title">
                <span><i class="fas fa-clipboard-check"></i> Validation Status</span>
                <span class="qoda-validation-progress">Progress: ${ready} / ${checks.length} Requirements Complete</span>
            </div>
            <div class="qoda-validation-grid">${items}</div>
        `;
    }

    function formatValidationProgress(ready, total) {
        return `${ready} of ${total} validation checks complete`;
    }

    function renderQuestionPreview(q) {
        normalizeCodingQuestion(q);
        const samples = (q.sampleCases || []).map((s, i) => `
            <div class="qoda-case-card">
                <strong>Sample ${i + 1}</strong>
                <div><small>Input</small><pre>${escapeHTML(s.input || '')}</pre></div>
                <div><small>Output</small><pre>${escapeHTML(s.output || s.expectedOutput || '')}</pre></div>
                ${s.explanation ? `<div><small>Explanation</small><div>${escapeHTML(s.explanation)}</div></div>` : ''}
            </div>`).join('');
        return `
            <div class="qoda-preview-content">
                <div>
                    <div class="qoda-q-badges">
                        <span class="qoda-badge primary">${escapeHTML(q.languageMode === 'multi' ? 'Multi-language' : q.language)}</span>
                        <span class="qoda-badge warning">${parseFloat(q.marks || 0)} marks</span>
                    </div>
                </div>
                <section><h4>Problem Statement</h4><div>${richValueToHtml(q.problemStatement || q.text)}</div></section>
                <section><h4>Validation</h4>${renderValidationChecklist(q)}</section>
            </div>`;
    }

    function renderSampleCasesList(q) {
        if (!q.sampleCases.length) {
            return `<div class="empty-state" style="padding:18px;">No sample cases yet. Add examples students can see.</div>`;
        }
        return q.sampleCases.map((sample, index) => `
            <div class="qoda-case-card">
                <div class="qoda-case-head">
                    <strong>Sample Case #${index + 1}</strong>
                    <div class="qoda-case-actions">
                        <button class="btn small" onclick="duplicateSampleCase('${q.id}',${index})"><i class="fas fa-copy"></i> Duplicate</button>
                        <button class="btn danger small" onclick="removeSampleCase('${q.id}',${index})"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </div>
                <div class="qoda-field-grid">
                    <div class="qoda-field"><label>Sample Input</label><textarea onchange="updateSampleCase('${q.id}',${index},'input',this.value)">${escapeHTML(sample.input || '')}</textarea></div>
                    <div class="qoda-field"><label>Sample Output</label><textarea onchange="updateSampleCase('${q.id}',${index},'output',this.value)">${escapeHTML(sample.output || sample.expectedOutput || '')}</textarea></div>
                </div>
                <div class="qoda-field"><label>Explanation</label><textarea onchange="updateSampleCase('${q.id}',${index},'explanation',this.value)">${escapeHTML(sample.explanation || '')}</textarea></div>
            </div>`).join('');
    }

    function renderModernTestCasesList(q) {
        if (!q.testCases.length) {
            return `<div class="empty-state" style="padding:18px;">No grading test cases yet. Add visible and hidden tests for auto-grading.</div>`;
        }
        return q.testCases.map((tc, index) => `
            <div class="qoda-case-card">
                <div class="qoda-case-head">
                    <div class="qoda-q-badges">
                        <strong>Test Case #${index + 1}</strong>
                        <span class="qoda-badge ${tc.hidden ? 'warning' : 'success'}">${tc.hidden ? 'Hidden Test Case' : 'Visible Test Case'}</span>
                    </div>
                    <div class="qoda-case-actions">
                        <button class="btn small" onclick="duplicateTestCase('${q.id}',${index})"><i class="fas fa-copy"></i> Duplicate</button>
                        <button class="btn danger small" onclick="removeTestCase('${q.id}',${index})"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </div>
                    <div class="qoda-field-grid">
                    <div class="qoda-field"><label>Input</label><textarea placeholder="Example: 23 3, or press Enter for line-by-line input" onchange="updateTestCase('${q.id}',${index},'input',this.value)">${escapeHTML(tc.input || '')}</textarea></div>
                    <div class="qoda-field"><label>Expected Output</label><textarea placeholder="Example: 26, or multiple output lines separated by Enter" onchange="updateTestCase('${q.id}',${index},'expected',this.value)">${escapeHTML(tc.expected || tc.expectedOutput || '')}</textarea></div>
                    <div class="qoda-field">
                        <label>Comparison Mode</label>
                        <select onchange="updateTestCase('${q.id}',${index},'comparisonMode',this.value)">
                            ${['exact','contains','ignore_whitespace','ignore_trailing','ignore_blank_lines','case_insensitive','regex','numeric_tolerance','custom_validator'].map(mode =>
                                `<option value="${mode}" ${(tc.comparisonMode || 'exact') === mode ? 'selected' : ''}>${mode.replaceAll('_',' ')}</option>`).join('')}
                        </select>
                    </div>
                    <div class="qoda-field">
                        <label>Visibility</label>
                        <label class="qoda-toggle-row">
                            <input type="checkbox" ${tc.hidden ? 'checked' : ''} onchange="updateTestCase('${q.id}',${index},'hidden',this.checked)">
                            ${tc.hidden ? 'Hidden Test Case' : 'Visible Test Case'}
                        </label>
                    </div>
                </div>
            </div>`).join('');
    }

    function renderRubricRows(q) {
        if (!q.markingRubric.length) {
            return `<div class="empty-state" style="padding:18px;">No rubric criteria yet.</div>`;
        }
        return q.markingRubric.map((row, index) => `
            <div class="qoda-rubric-row">
                <div class="qoda-field-grid">
                    <div class="qoda-field"><label>Criteria</label><input value="${escapeHTML(row.criterion || '')}" onchange="updateRubricCriterion('${q.id}',${index},'criterion',this.value)"></div>
                    <div class="qoda-field"><label>Marks</label><input type="number" min="0" step="0.5" value="${parseFloat(row.marks || 0)}" onchange="updateRubricCriterion('${q.id}',${index},'marks',parseFloat(this.value)||0)"></div>
                    <div class="qoda-field"><label>Description</label><input value="${escapeHTML(row.description || '')}" onchange="updateRubricCriterion('${q.id}',${index},'description',this.value)"></div>
                </div>
                <div><button class="btn danger small" onclick="removeRubricCriterion('${q.id}',${index})"><i class="fas fa-trash"></i> Remove Criterion</button></div>
            </div>`).join('');
    }

    const qodaTemplateCategories = ['Coding Templates', 'Web Development Templates', 'Database Templates'];
    let qodaTemplateTargetQuestionId = null;

    const qodaQuestionTemplates = [{
            category: 'Coding Templates',
            title: 'Input / Output Problems',
            topic: 'Input and Output',
            subtopic: 'Standard input',
            difficulty: 'Easy',
            language: 'Python',
            semester: 'First Semester',
            tags: ['stdin', 'stdout', 'basics'],
            problemStatement: 'Write a program that reads the required values from standard input and displays the correct result.',
            inputFormat: 'Each input value is provided on a separate line.',
            outputFormat: 'Print the final answer on a single line.',
            sampleCases: [{ input: '12\n45\n23', output: '45', explanation: '45 is the largest value.' }],
            testCases: [{ input: '12\n45\n23', expected: '45', hidden: false }, { input: '7\n3\n9', expected: '9', hidden: true }],
            modelSolution: 'values = [int(input()) for _ in range(3)]\nprint(max(values))',
            markingRubric: [
                { criterion: 'Input Handling', marks: 1, description: 'Reads all required inputs correctly.' },
                { criterion: 'Logic', marks: 2, description: 'Uses the correct calculation or comparison.' },
                { criterion: 'Output', marks: 1, description: 'Prints the result in the expected format.' },
                { criterion: 'Code Quality', marks: 1, description: 'Code is readable and organized.' }
            ],
            executionSettings: { timeLimit: 2, memoryLimit: 128, cpuLimit: 2 },
            recommendedMarks: 5
        },
        {
            category: 'Coding Templates',
            title: 'Loops and Iteration',
            topic: 'Loops',
            subtopic: 'For and while loops',
            difficulty: 'Easy',
            language: 'Java',
            semester: 'First Semester',
            tags: ['loops', 'iteration'],
            problemStatement: 'Write a program that reads an integer n and prints the numbers from 1 to n, each on a new line.',
            inputFormat: 'A single integer n.',
            outputFormat: 'Numbers from 1 to n, each on a new line.',
            sampleCases: [{ input: '5', output: '1\n2\n3\n4\n5', explanation: 'The loop prints five values.' }],
            testCases: [{ input: '3', expected: '1\n2\n3', hidden: false }, { input: '1', expected: '1', hidden: true }],
            modelSolution: 'import java.util.Scanner;\n\npublic class Main {\n    public static void main(String[] args) {\n        Scanner input = new Scanner(System.in);\n        int n = input.nextInt();\n        for (int i = 1; i <= n; i++) {\n            System.out.println(i);\n        }\n    }\n}',
            markingRubric: [
                { criterion: 'Input Handling', marks: 1, description: 'Reads n correctly.' },
                { criterion: 'Loop Logic', marks: 2, description: 'Uses a valid loop with correct bounds.' },
                { criterion: 'Output', marks: 1, description: 'Prints each value correctly.' },
                { criterion: 'Code Quality', marks: 1, description: 'Uses clear names and structure.' }
            ],
            executionSettings: { timeLimit: 2, memoryLimit: 128, cpuLimit: 2 },
            recommendedMarks: 5
        },
        {
            category: 'Coding Templates',
            title: 'Conditional Statements',
            topic: 'Decision Making',
            subtopic: 'If / else',
            difficulty: 'Easy',
            language: 'C',
            semester: 'First Semester',
            tags: ['if', 'else', 'conditions'],
            problemStatement: 'Write a program that reads one integer and determines whether it is even or odd.',
            inputFormat: 'One integer.',
            outputFormat: 'Print Even if the number is even, otherwise print Odd.',
            sampleCases: [{ input: '8', output: 'Even', explanation: '8 is divisible by 2.' }],
            testCases: [{ input: '8', expected: 'Even', hidden: false }, { input: '7', expected: 'Odd', hidden: true }],
            modelSolution: '#include <stdio.h>\n\nint main() {\n    int number;\n    scanf("%d", &number);\n    if (number % 2 == 0) {\n        printf("Even");\n    } else {\n        printf("Odd");\n    }\n    return 0;\n}',
            markingRubric: [
                { criterion: 'Input Handling', marks: 1, description: 'Reads the integer correctly.' },
                { criterion: 'Condition', marks: 2, description: 'Uses modulus or valid even/odd logic.' },
                { criterion: 'Output', marks: 1, description: 'Displays the correct label.' },
                { criterion: 'Code Quality', marks: 1, description: 'Compiles and is readable.' }
            ],
            executionSettings: { timeLimit: 2, memoryLimit: 128, cpuLimit: 2 },
            recommendedMarks: 5
        },
        {
            category: 'Coding Templates',
            title: 'Functions and Methods',
            topic: 'Functions',
            subtopic: 'Reusable logic',
            difficulty: 'Medium',
            language: 'JavaScript',
            semester: 'First Semester',
            tags: ['functions', 'methods'],
            problemStatement: 'Write a function that accepts two numbers and returns their sum. Read two numbers and print the returned value.',
            inputFormat: 'Two numbers on separate lines.',
            outputFormat: 'The sum of both numbers.',
            sampleCases: [{ input: '4\n6', output: '10', explanation: '4 + 6 = 10.' }],
            testCases: [{ input: '4\n6', expected: '10', hidden: false }, { input: '12\n5', expected: '17', hidden: true }],
            modelSolution: 'const fs = require("fs");\nconst [a, b] = fs.readFileSync(0, "utf8").trim().split(/\\s+/).map(Number);\nfunction add(x, y) {\n  return x + y;\n}\nconsole.log(add(a, b));',
            markingRubric: [
                { criterion: 'Function', marks: 2, description: 'Creates and uses a function/method.' },
                { criterion: 'Input Handling', marks: 1, description: 'Reads both values correctly.' },
                { criterion: 'Output', marks: 1, description: 'Prints the correct sum.' },
                { criterion: 'Code Quality', marks: 1, description: 'Clear reusable code.' }
            ],
            executionSettings: { timeLimit: 2, memoryLimit: 128, cpuLimit: 2 },
            recommendedMarks: 5
        },
        {
            category: 'Coding Templates',
            title: 'Arrays and Lists',
            topic: 'Arrays',
            subtopic: 'Aggregation',
            difficulty: 'Medium',
            language: 'Python',
            semester: 'Second Semester',
            tags: ['arrays', 'lists', 'sum'],
            problemStatement: 'Read n followed by n integers and print the sum of all values.',
            inputFormat: 'The first line contains n. The next line contains n integers.',
            outputFormat: 'Print the sum.',
            sampleCases: [{ input: '5\n1 2 3 4 5', output: '15', explanation: 'The sum is 15.' }],
            testCases: [{ input: '5\n1 2 3 4 5', expected: '15', hidden: false }, { input: '3\n10 -2 7', expected: '15', hidden: true }],
            modelSolution: 'n = int(input())\nvalues = list(map(int, input().split()))\nprint(sum(values[:n]))',
            markingRubric: [
                { criterion: 'Input Handling', marks: 1, description: 'Reads n and the array values.' },
                { criterion: 'Array Logic', marks: 2, description: 'Processes the collection correctly.' },
                { criterion: 'Output', marks: 1, description: 'Prints the correct sum.' },
                { criterion: 'Code Quality', marks: 1, description: 'Readable and efficient for the task.' }
            ],
            executionSettings: { timeLimit: 2, memoryLimit: 128, cpuLimit: 2 },
            recommendedMarks: 5
        },
        {
            category: 'Coding Templates',
            title: 'Strings',
            topic: 'String Processing',
            subtopic: 'Reversal',
            difficulty: 'Easy',
            language: 'PHP',
            semester: 'Second Semester',
            tags: ['strings', 'reverse'],
            problemStatement: 'Read a string and print the string in reverse order.',
            inputFormat: 'One line containing a string.',
            outputFormat: 'The reversed string.',
            sampleCases: [{ input: 'qoda', output: 'adoq', explanation: 'The characters are reversed.' }],
            testCases: [{ input: 'qoda', expected: 'adoq', hidden: false }, { input: 'exam', expected: 'maxe', hidden: true }],
            modelSolution: '<' + '?php\n$input = trim(stream_get_contents(STDIN));\necho strrev($input);\n?' + '>',
            markingRubric: [
                { criterion: 'Input Handling', marks: 1, description: 'Reads the string.' },
                { criterion: 'String Logic', marks: 2, description: 'Reverses the string correctly.' },
                { criterion: 'Output', marks: 1, description: 'Displays only the reversed value.' },
                { criterion: 'Code Quality', marks: 1, description: 'Clean PHP syntax.' }
            ],
            executionSettings: { timeLimit: 2, memoryLimit: 128, cpuLimit: 2 },
            recommendedMarks: 5
        },
        {
            category: 'Web Development Templates',
            title: 'HTML Page Design',
            topic: 'HTML',
            subtopic: 'Semantic page structure',
            difficulty: 'Easy',
            language: 'HTML/CSS/JS',
            semester: 'First Semester',
            tags: ['html', 'semantic', 'layout'],
            problemStatement: 'Create a semantic HTML page for a student profile with a heading, image placeholder, course list, and contact section.',
            inputFormat: 'No standard input is required.',
            outputFormat: 'A valid HTML page displayed in the browser preview.',
            sampleCases: [{ input: '', output: 'Student profile page renders correctly.', explanation: 'Students should use semantic tags.' }],
            testCases: [{ input: '', expected: '<h1', hidden: false }, { input: '', expected: '<section', hidden: true }],
            modelSolution: '<!doctype html>\n<html>\n<head><title>Student Profile</title></head>\n<body>\n  <main>\n    <h1>Student Profile</h1>\n    <section><h2>Courses</h2><ul><li>Programming</li></ul></section>\n    <section><h2>Contact</h2><p>Email: student@example.com</p></section>\n  </main>\n</body>\n</html>',
            markingRubric: [
                { criterion: 'Structure', marks: 2, description: 'Uses semantic HTML correctly.' },
                { criterion: 'Required Content', marks: 2, description: 'Includes all requested sections.' },
                { criterion: 'Validity', marks: 1, description: 'HTML is valid and renders.' }
            ],
            executionSettings: { timeLimit: 1, memoryLimit: 64, cpuLimit: 1 },
            recommendedMarks: 5
        },
        {
            category: 'Web Development Templates',
            title: 'JavaScript DOM Manipulation',
            topic: 'JavaScript',
            subtopic: 'DOM events',
            difficulty: 'Medium',
            language: 'HTML/CSS/JS',
            semester: 'Second Semester',
            tags: ['dom', 'events', 'javascript'],
            problemStatement: 'Build a page with an input, a button, and an output area. When the button is clicked, display the typed message.',
            inputFormat: 'Browser input field.',
            outputFormat: 'The message appears on the page after clicking the button.',
            sampleCases: [{ input: 'Hello', output: 'Hello', explanation: 'The page displays the typed text.' }],
            testCases: [{ input: '', expected: 'addEventListener', hidden: false }, { input: '', expected: 'textContent', hidden: true }],
            modelSolution: '<input id="message"><button id="show">Show</button><p id="output"></p><script>document.getElementById("show").addEventListener("click",()=>{document.getElementById("output").textContent=document.getElementById("message").value;});<\/script>',
            markingRubric: [
                { criterion: 'HTML Controls', marks: 1, description: 'Creates the input, button, and output area.' },
                { criterion: 'DOM Logic', marks: 3, description: 'Uses JavaScript event handling correctly.' },
                { criterion: 'User Experience', marks: 1, description: 'Displays the result clearly.' }
            ],
            executionSettings: { timeLimit: 1, memoryLimit: 64, cpuLimit: 1 },
            recommendedMarks: 5
        },
        {
            category: 'Database Templates',
            title: 'SQL Queries',
            topic: 'SQL',
            subtopic: 'Selection and filtering',
            difficulty: 'Easy',
            language: 'SQL',
            semester: 'First Semester',
            tags: ['select', 'where', 'sql'],
            problemStatement: 'Write an SQL query to select all active students from a students table.',
            inputFormat: 'Table: students(id, full_name, status).',
            outputFormat: 'Rows where status is Active.',
            sampleCases: [{ input: 'students table', output: 'Only active students', explanation: 'Filter using WHERE.' }],
            testCases: [{ input: '', expected: 'SELECT', hidden: false }, { input: '', expected: 'WHERE', hidden: true }],
            modelSolution: "SELECT id, full_name, status\nFROM students\nWHERE status = 'Active';",
            markingRubric: [
                { criterion: 'SELECT Clause', marks: 1, description: 'Selects appropriate columns.' },
                { criterion: 'FROM Clause', marks: 1, description: 'Uses the correct table.' },
                { criterion: 'WHERE Clause', marks: 2, description: 'Filters active students correctly.' },
                { criterion: 'Syntax', marks: 1, description: 'Valid SQL syntax.' }
            ],
            executionSettings: { timeLimit: 2, memoryLimit: 64, cpuLimit: 1 },
            recommendedMarks: 5
        },
        {
            category: 'Database Templates',
            title: 'Joins',
            topic: 'SQL',
            subtopic: 'Inner joins',
            difficulty: 'Medium',
            language: 'SQL',
            semester: 'Second Semester',
            tags: ['join', 'foreign key'],
            problemStatement: 'Write an SQL query to list each student with the course names they are enrolled in.',
            inputFormat: 'Tables: students(id, full_name), course_enrollments(student_id, course_name).',
            outputFormat: 'Student full name and course name.',
            sampleCases: [{ input: 'students + course_enrollments', output: 'student-course pairs', explanation: 'Join using student id.' }],
            testCases: [{ input: '', expected: 'JOIN', hidden: false }, { input: '', expected: 'student_id', hidden: true }],
            modelSolution: 'SELECT s.full_name, ce.course_name\nFROM students s\nJOIN course_enrollments ce ON ce.student_id = s.id;',
            markingRubric: [
                { criterion: 'Join', marks: 2, description: 'Uses a valid join condition.' },
                { criterion: 'Columns', marks: 1, description: 'Returns the requested fields.' },
                { criterion: 'Aliases', marks: 1, description: 'Uses clear table references.' },
                { criterion: 'Syntax', marks: 1, description: 'Valid SQL syntax.' }
            ],
            executionSettings: { timeLimit: 2, memoryLimit: 64, cpuLimit: 1 },
            recommendedMarks: 5
        }
    ];

    [
        ['Coding Templates', 'File Handling', 'Files', 'Read/write files', 'Medium', 'Python', ['files', 'read', 'write']],
        ['Coding Templates', 'Object-Oriented Programming', 'OOP', 'Classes and objects', 'Medium', 'Java', ['class', 'object', 'method']],
        ['Coding Templates', 'Database Programming', 'Database Programming', 'CRUD logic', 'Hard', 'PHP', ['database', 'crud', 'server-side']],
        ['Coding Templates', 'Data Structures and Algorithms', 'Algorithms', 'Sorting/searching', 'Hard', 'C++', ['algorithm', 'array', 'sort']],
        ['Web Development Templates', 'CSS Styling', 'CSS', 'Visual styling', 'Easy', 'HTML/CSS/JS', ['css', 'layout', 'colors']],
        ['Web Development Templates', 'Responsive Design', 'Responsive Web', 'Media queries', 'Medium', 'HTML/CSS/JS', ['responsive', 'media-query', 'mobile']],
        ['Web Development Templates', 'Form Validation', 'Forms', 'Client validation', 'Medium', 'HTML/CSS/JS', ['forms', 'validation']],
        ['Web Development Templates', 'PHP Web Application', 'PHP', 'Server-side form handling', 'Medium', 'PHP', ['php', 'form', 'server']],
        ['Web Development Templates', 'Full-Stack Development Tasks', 'Full Stack', 'Frontend plus backend', 'Hard', 'PHP', ['full-stack', 'database', 'forms']],
        ['Database Templates', 'Aggregation Functions', 'SQL', 'GROUP BY and aggregate functions', 'Medium', 'SQL', ['count', 'sum', 'group-by']],
        ['Database Templates', 'Stored Procedures', 'SQL', 'Stored procedure logic', 'Hard', 'SQL', ['procedure', 'parameters']],
        ['Database Templates', 'Database Design', 'SQL', 'Tables, keys, and relationships', 'Hard', 'SQL', ['schema', 'primary-key', 'foreign-key']]
    ].forEach(([category, title, topic, subtopic, difficulty, language, tags]) => {
        qodaQuestionTemplates.push({
            category,
            title,
            topic,
            subtopic,
            difficulty,
            language,
            semester: 'First Semester',
            tags,
            problemStatement: `Create a ${title.toLowerCase()} solution that demonstrates ${subtopic.toLowerCase()} for the given assessment scenario.`,
            inputFormat: language === 'HTML/CSS/JS' ? 'Browser-based input or page interaction where required.' : 'Use standard input or the provided database structure where required.',
            outputFormat: language === 'HTML/CSS/JS' ? 'A working browser preview that satisfies the task.' : 'Display the required result clearly.',
            sampleCases: [{ input: '', output: 'Expected behavior is demonstrated.', explanation: 'Customize this example for the course.' }],
            testCases: [{ input: '', expected: title.split(' ')[0], hidden: false }],
            modelSolution: language === 'SQL' ? '-- Write the model SQL solution here\nSELECT 1;' : language === 'HTML/CSS/JS' ? '<!doctype html>\n<html><body><h1>Model Solution</h1></body></html>' : '// Write the model solution here',
            markingRubric: [
                { criterion: 'Requirements', marks: 2, description: 'Meets the stated problem requirements.' },
                { criterion: 'Correctness', marks: 2, description: 'Produces the expected behavior or output.' },
                { criterion: 'Code Quality', marks: 1, description: 'Solution is readable and maintainable.' }
            ],
            executionSettings: { timeLimit: 2, memoryLimit: 128, cpuLimit: 2 },
            recommendedMarks: 5
        });
    });

    const qodaTemplateEnhancements = {
        'File Handling': {
            problemStatement: 'Write a program that reads all lines from a file named input.txt and prints the number of non-empty lines.',
            inputFormat: 'A text file named input.txt is provided with zero or more lines.',
            outputFormat: 'Print one integer: the count of non-empty lines.',
            sampleCases: [{ input: 'apple\\n\\nbanana', output: '2', explanation: 'Two lines contain visible text.' }],
            testCases: [{ input: 'alpha\\n\\nbeta\\n', expected: '2', hidden: false }, { input: '\\none\\ntwo\\nthree', expected: '3', hidden: true }],
            modelSolution: 'with open("input.txt", "r", encoding="utf-8") as f:\\n    lines = f.readlines()\\nprint(sum(1 for line in lines if line.strip()))'
        },
        'Object-Oriented Programming': {
            problemStatement: 'Create a Student class with name and score fields, then print the name of the student with the highest score.',
            inputFormat: 'The first line contains n. Each of the next n lines contains a student name and score.',
            outputFormat: 'Print the name of the highest-scoring student.',
            sampleCases: [{ input: '3\\nAma 78\\nKojo 92\\nEfua 88', output: 'Kojo', explanation: 'Kojo has the highest score.' }],
            testCases: [{ input: '2\\nA 10\\nB 20', expected: 'B', hidden: false }, { input: '3\\nSam 40\\nAnn 41\\nJoe 39', expected: 'Ann', hidden: true }],
            modelSolution: 'import java.util.*;\\n\\nclass Student {\\n    String name;\\n    int score;\\n    Student(String name, int score) { this.name = name; this.score = score; }\\n}\\n\\npublic class Main {\\n    public static void main(String[] args) {\\n        Scanner sc = new Scanner(System.in);\\n        int n = sc.nextInt();\\n        Student best = null;\\n        for (int i = 0; i < n; i++) {\\n            Student s = new Student(sc.next(), sc.nextInt());\\n            if (best == null || s.score > best.score) best = s;\\n        }\\n        System.out.println(best.name);\\n    }\\n}'
        },
        'Database Programming': {
            problemStatement: 'Build a PHP script that inserts a new course record using prepared statements and displays a success message.',
            inputFormat: 'POST fields: course_code and course_name.',
            outputFormat: 'Display Saved when the insert succeeds.',
            testCases: [{ input: '', expected: 'prepare', hidden: false }, { input: '', expected: 'execute', hidden: true }],
            modelSolution: '<' + '?php\\n$pdo = new PDO($dsn, $user, $pass);\\n$stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name) VALUES (?, ?)");\\n$stmt->execute([$_POST["course_code"], $_POST["course_name"]]);\\necho "Saved";\\n?' + '>'
        },
        'Data Structures and Algorithms': {
            problemStatement: 'Read n integers, sort them in ascending order, and print the sorted list on one line.',
            inputFormat: 'The first line contains n. The second line contains n integers.',
            outputFormat: 'Print the sorted integers separated by spaces.',
            sampleCases: [{ input: '5\\n4 1 3 2 5', output: '1 2 3 4 5', explanation: 'The values are sorted ascending.' }],
            testCases: [{ input: '4\\n9 1 7 3', expected: '1 3 7 9', hidden: false }, { input: '3\\n-1 2 0', expected: '-1 0 2', hidden: true }],
            modelSolution: '#include <bits/stdc++.h>\\nusing namespace std;\\nint main(){ int n; cin >> n; vector<int> a(n); for(int &x:a) cin >> x; sort(a.begin(), a.end()); for(int i=0;i<n;i++){ if(i) cout << " "; cout << a[i]; } }'
        },
        'CSS Styling': {
            problemStatement: 'Style a profile card with a centered layout, rounded avatar, readable typography, and a hover effect.',
            inputFormat: 'A basic HTML profile-card structure is provided.',
            outputFormat: 'The profile card is visually styled and responsive.',
            testCases: [{ input: '', expected: 'border-radius', hidden: false }, { input: '', expected: ':hover', hidden: true }],
            modelSolution: '.profile-card { max-width: 360px; margin: 32px auto; padding: 24px; border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,.12); text-align: center; font-family: Arial, sans-serif; }\\n.profile-card img { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; }\\n.profile-card:hover { transform: translateY(-4px); }'
        },
        'Responsive Design': {
            problemStatement: 'Create a responsive two-column dashboard that becomes a single column on mobile screens.',
            inputFormat: 'Browser viewport width determines the layout.',
            outputFormat: 'Desktop shows two columns; mobile shows one stacked column.',
            testCases: [{ input: '', expected: '@media', hidden: false }, { input: '', expected: 'grid-template-columns', hidden: true }],
            modelSolution: '.dashboard { display: grid; grid-template-columns: 260px 1fr; gap: 20px; }\\n@media (max-width: 768px) { .dashboard { grid-template-columns: 1fr; } }'
        },
        'Form Validation': {
            problemStatement: 'Build a registration form that validates name and email before submission and displays useful messages.',
            inputFormat: 'Browser form fields: name and email.',
            outputFormat: 'Invalid entries show validation messages; valid entries submit successfully.',
            testCases: [{ input: '', expected: 'preventDefault', hidden: false }, { input: '', expected: 'email', hidden: true }],
            modelSolution: 'document.querySelector("form").addEventListener("submit", event => {\\n  const email = document.querySelector("#email").value.trim();\\n  if (!email.includes("@")) {\\n    event.preventDefault();\\n    document.querySelector("#message").textContent = "Enter a valid email";\\n  }\\n});'
        },
        'PHP Web Application': {
            problemStatement: 'Create a PHP page that accepts a student name from a form, sanitizes it, and displays a welcome message.',
            inputFormat: 'POST field: student_name.',
            outputFormat: 'Display Welcome, followed by the sanitized student name.',
            testCases: [{ input: '', expected: 'htmlspecialchars', hidden: false }, { input: '', expected: '$_POST', hidden: true }],
            modelSolution: '<' + '?php\\n$name = trim($_POST["student_name"] ?? "");\\necho "Welcome, " . htmlspecialchars($name, ENT_QUOTES, "UTF-8");\\n?' + '>'
        },
        'Full-Stack Development Tasks': {
            problemStatement: 'Create a small course feedback feature with an HTML form, PHP handler, and database insert using prepared statements.',
            inputFormat: 'Form fields: course_code, rating, comment.',
            outputFormat: 'The feedback is saved and a confirmation is shown.',
            testCases: [{ input: '', expected: '<form', hidden: false }, { input: '', expected: 'prepare', hidden: true }],
            modelSolution: '<form method="post"><input name="course_code"><input name="rating" type="number"><textarea name="comment"></textarea><button>Save</button></form>\\n<' + '?php\\nif ($_SERVER["REQUEST_METHOD"] === "POST") {\\n  $stmt = $pdo->prepare("INSERT INTO feedback(course_code, rating, comment) VALUES (?, ?, ?)");\\n  $stmt->execute([$_POST["course_code"], $_POST["rating"], $_POST["comment"]]);\\n  echo "Feedback saved";\\n}\\n?' + '>'
        },
        'Aggregation Functions': {
            problemStatement: 'Write an SQL query that shows the number of students enrolled in each course.',
            inputFormat: 'Table: course_enrollments(course_code, student_id).',
            outputFormat: 'Course code and enrollment count.',
            testCases: [{ input: '', expected: 'COUNT', hidden: false }, { input: '', expected: 'GROUP BY', hidden: true }],
            modelSolution: 'SELECT course_code, COUNT(student_id) AS enrolled_students\\nFROM course_enrollments\\nGROUP BY course_code;'
        },
        'Stored Procedures': {
            problemStatement: 'Write a stored procedure that returns all students enrolled in a given course code.',
            inputFormat: 'Procedure parameter: p_course_code.',
            outputFormat: 'Rows for matching enrolled students.',
            testCases: [{ input: '', expected: 'CREATE PROCEDURE', hidden: false }, { input: '', expected: 'p_course_code', hidden: true }],
            modelSolution: 'CREATE PROCEDURE GetCourseStudents(IN p_course_code VARCHAR(50))\\nBEGIN\\n  SELECT student_id, course_code\\n  FROM course_enrollments\\n  WHERE course_code = p_course_code;\\nEND;'
        },
        'Database Design': {
            problemStatement: 'Design tables for courses, students, and enrollments with primary keys and foreign keys.',
            inputFormat: 'Entities: students, courses, course_enrollments.',
            outputFormat: 'SQL CREATE TABLE statements with relationships.',
            testCases: [{ input: '', expected: 'PRIMARY KEY', hidden: false }, { input: '', expected: 'FOREIGN KEY', hidden: true }],
            modelSolution: 'CREATE TABLE students (id INT PRIMARY KEY, full_name VARCHAR(150) NOT NULL);\\nCREATE TABLE courses (id INT PRIMARY KEY, course_code VARCHAR(50) UNIQUE NOT NULL);\\nCREATE TABLE course_enrollments (student_id INT, course_id INT, PRIMARY KEY(student_id, course_id), FOREIGN KEY(student_id) REFERENCES students(id), FOREIGN KEY(course_id) REFERENCES courses(id));'
        }
    };

    qodaQuestionTemplates.forEach(template => {
        const enhanced = qodaTemplateEnhancements[template.title];
        if (enhanced) Object.assign(template, enhanced);
        template.course = template.course || template.topic || template.category;
        template.starterCode = template.starterCode || '';
        template.notes = '';
        template.constraints = '';
    });

    function templateMatchesFilters(template) {
        const search = (document.getElementById('templateSearch')?.value || '').toLowerCase();
        const course = (document.getElementById('templateCourse')?.value || '').toLowerCase();
        const topicFilter = (document.getElementById('templateTopic')?.value || '').toLowerCase();
        const semester = document.getElementById('templateSemester')?.value || '';
        const tags = (document.getElementById('templateTags')?.value || '').toLowerCase();
        const category = document.getElementById('templateCategory')?.value || '';
        const language = document.getElementById('templateLanguage')?.value || '';
        const difficulty = document.getElementById('templateDifficulty')?.value || '';
        const haystack = [template.title, template.category, template.topic, template.subtopic, template.problemStatement, template.language, template.difficulty, template.course || '', ...(template.tags || [])].join(' ').toLowerCase();
        return (!search || haystack.includes(search)) &&
            (!course || haystack.includes(course)) &&
            (!topicFilter || haystack.includes(topicFilter)) &&
            (!semester || template.semester === semester) &&
            (!tags || (template.tags || []).join(' ').toLowerCase().includes(tags)) &&
            (!category || template.category === category) &&
            (!language || template.language === language) &&
            (!difficulty || template.difficulty === difficulty);
    }

    function ensureTemplateLibraryModal() {
        let modal = document.getElementById('qodaTemplateLibraryModal');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'qodaTemplateLibraryModal';
        modal.className = 'modal';
        modal.style.display = 'none';
        modal.innerHTML = `
            <div class="modal-content" style="width:1180px;max-width:96%;max-height:90vh;overflow:auto;">
                <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
                    <div>
                        <h3 style="margin:0;"><i class="fas fa-layer-group"></i> Import Question Template</h3>
                        <small style="color:var(--muted);">Choose a ready-made university assessment pattern and customize it.</small>
                    </div>
                    <button class="btn" onclick="closeTemplateLibraryModal()"><i class="fas fa-times"></i> Close</button>
                </div>
                <div class="qoda-template-layout">
                    <aside class="qoda-template-filter">
                        <div class="qoda-field"><label>Search</label><input id="templateSearch" placeholder="Topic, tags, language..." oninput="renderQuestionTemplates()"></div>
                        <div class="qoda-field"><label>Course</label><input id="templateCourse" placeholder="Course name or code..." oninput="renderQuestionTemplates()"></div>
                        <div class="qoda-field"><label>Topic</label><input id="templateTopic" placeholder="SQL, arrays, forms..." oninput="renderQuestionTemplates()"></div>
                        <div class="qoda-field"><label>Category</label><select id="templateCategory" onchange="renderQuestionTemplates()"></select></div>
                        <div class="qoda-field"><label>Language</label><select id="templateLanguage" onchange="renderQuestionTemplates()"></select></div>
                        <div class="qoda-field"><label>Semester</label><select id="templateSemester" onchange="renderQuestionTemplates()">
                            <option value="">Any Semester</option><option>First Semester</option><option>Second Semester</option><option>Summer School</option>
                        </select></div>
                        <div class="qoda-field"><label>Difficulty</label><select id="templateDifficulty" onchange="renderQuestionTemplates()">
                            <option value="">Any Difficulty</option><option>Easy</option><option>Medium</option><option>Hard</option>
                        </select></div>
                        <div class="qoda-field"><label>Tags</label><input id="templateTags" placeholder="loops, arrays..." oninput="renderQuestionTemplates()"></div>
                        <div class="qoda-field"><label>Import Option</label><select id="templateImportMode">
                            <option value="full">Import Full Question</option>
                            <option value="question">Import Question Only</option>
                            <option value="tests">Import Test Cases Only</option>
                            <option value="solution">Import Model Solution Only</option>
                        </select></div>
                    </aside>
                    <section>
                        <div id="qodaTemplateResults" class="qoda-template-grid"></div>
                    </section>
                </div>
            </div>`;
        document.body.appendChild(modal);
        const categorySelect = modal.querySelector('#templateCategory');
        const languageSelect = modal.querySelector('#templateLanguage');
        categorySelect.innerHTML = '<option value="">All Categories</option>' + qodaTemplateCategories.map(c => `<option>${escapeHTML(c)}</option>`).join('');
        const languages = [...new Set(qodaQuestionTemplates.map(t => t.language))].sort();
        languageSelect.innerHTML = '<option value="">All Languages</option>' + languages.map(l => `<option>${escapeHTML(l)}</option>`).join('');
        return modal;
    }

    function openTemplateLibrary(qId) {
        qodaTemplateTargetQuestionId = qId;
        const modal = ensureTemplateLibraryModal();
        modal.style.display = 'flex';
        renderQuestionTemplates();
    }

    function closeTemplateLibraryModal() {
        const modal = document.getElementById('qodaTemplateLibraryModal');
        if (modal) modal.style.display = 'none';
    }

    function renderQuestionTemplates() {
        const results = document.getElementById('qodaTemplateResults');
        if (!results) return;
        const matches = qodaQuestionTemplates.filter(templateMatchesFilters);
        if (!matches.length) {
            results.innerHTML = '<div class="empty-state" style="grid-column:1/-1;">No templates match your filters.</div>';
            return;
        }
        results.innerHTML = matches.map((template, index) => `
            <article class="qoda-template-card" onclick="applyQuestionTemplate(${qodaQuestionTemplates.indexOf(template)})">
                <div class="qoda-template-tags">
                    <span class="qoda-template-tag">${escapeHTML(template.category.replace(' Templates',''))}</span>
                    <span class="qoda-template-tag">${escapeHTML(template.language)}</span>
                    <span class="qoda-template-tag">${escapeHTML(template.difficulty)}</span>
                </div>
                <h4 style="margin:0;">${escapeHTML(template.title)}</h4>
                <p style="margin:0;color:var(--muted);">${escapeHTML(template.topic)}${template.subtopic ? ' / ' + escapeHTML(template.subtopic) : ''}</p>
                <small style="color:var(--muted);">${escapeHTML((template.tags || []).join(', '))}</small>
            </article>`).join('');
    }

    function applyQuestionTemplate(templateIndex) {
        const template = qodaQuestionTemplates[templateIndex];
        const ctx = getQuestionById(qodaTemplateTargetQuestionId);
        const mode = document.getElementById('templateImportMode')?.value || 'full';
        if (!template || !ctx) return;

        const copy = value => JSON.parse(JSON.stringify(value));
        if (mode === 'full' || mode === 'question') {
            ctx.question.title = template.title;
            ctx.question.problemStatement = template.problemStatement;
            ctx.question.text = richToPlain(template.problemStatement);
            ctx.question.inputFormat = '';
            ctx.question.outputFormat = '';
            ctx.question.constraints = '';
            ctx.question.notes = '';
            ctx.question.language = template.language;
            ctx.question.difficulty = template.difficulty;
            ctx.question.topic = template.topic;
            ctx.question.subtopic = template.subtopic;
            ctx.question.tags = (template.tags || []).join(', ');
            ctx.question.questionBankTags = ctx.question.tags;
            ctx.question.marks = template.recommendedMarks || ctx.question.marks || 5;
            ctx.question.sampleCases = copy(template.sampleCases || []);
            ctx.question.starterCode = template.starterCode || ctx.question.starterCode || '';
            ctx.question.executionSettings = Object.assign({}, ctx.question.executionSettings || {}, template.executionSettings || {});
        }
        if (mode === 'full' || mode === 'tests') {
            ctx.question.testCases = copy(template.testCases || []);
        }
        if (mode === 'full' || mode === 'solution') {
            ctx.question.modelSolution = template.modelSolution || '';
            ctx.question.modelValidationStatus = '';
            ctx.question.modelValidationMessage = 'Model solution has not been validated yet.';
        }
        if (mode === 'full') {
            ctx.question.markingRubric = [];
        }

        persistQuestionMutation(ctx, true);
        closeTemplateLibraryModal();
        toast(`Template imported: ${template.title}`);
    }

    function renderCodingQuestionCard(q, idx, totalQuestions) {
        normalizeCodingQuestion(q);
        const validation = getQuestionValidation(q);
        const readyCount = validation.filter(v => v.ok).length;
        return `
        <article class="qoda-author-card">
            <header class="qoda-q-header">
                <div class="qoda-q-meta">
                    <div class="qoda-q-badges">
                        <span class="qoda-badge primary">Q${idx + 1}</span>
                        <span class="qoda-badge success">Coding</span>
                        <span class="qoda-badge">${escapeHTML(q.languageMode === 'multi' ? 'Multi-language' : q.language)}</span>
                        <span class="qoda-badge warning">${parseFloat(q.marks || 0)} marks</span>
                        ${q.compulsory ? '<span class="qoda-badge success">Compulsory</span>' : '<span class="qoda-badge">Optional</span>'}
                    </div>
                </div>
                <div class="qoda-icon-actions">
                    <button class="btn small" onclick="toggleCompulsory('${q.id}')"><i class="fas fa-star"></i> ${q.compulsory ? 'Make Optional' : 'Compulsory'}</button>
                    <button class="btn small" onclick="moveQ('${q.id}', -1)" ${idx === 0 ? 'disabled' : ''} title="Move Up"><i class="fas fa-arrow-up"></i></button>
                    <button class="btn small" onclick="moveQ('${q.id}', 1)" ${idx === totalQuestions - 1 ? 'disabled' : ''} title="Move Down"><i class="fas fa-arrow-down"></i></button>
                    <button class="btn small" onclick="duplicateQuestion('${q.id}')"><i class="fas fa-copy"></i> Duplicate</button>
                    <button class="btn danger small" onclick="removeQuestion('${q.id}')"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </header>
            <div class="qoda-validation-panel" id="validation_${q.id}">
                ${renderValidationStatusPanel(q)}
            </div>

            <div class="qoda-author-body">
                <div class="qoda-author-main">
                    <details class="qoda-author-section" open>
                        <summary><span><i class="fas fa-pen-nib"></i> Problem Description</span><span class="qoda-badge">${richToPlain(q.problemStatement || q.text) ? 'Ready' : 'Required'}</span></summary>
                        <div class="qoda-section-body">
                            ${renderRichEditor(q, 'problemStatement', 'Problem Statement', 'Describe the task students must solve.')}
                            <div class="qoda-field-grid">
                                <div class="qoda-field"><label>Marks</label><input type="number" min="0" step="0.5" value="${parseFloat(q.marks || 0)}" onchange="updateQuestion('${q.id}','marks',parseFloat(this.value)||0)"></div>
                            </div>
                        </div>
                    </details>

                    <details class="qoda-author-section" open>
                        <summary><span><i class="fas fa-vial"></i> Auto-Grading Test Cases</span><span class="qoda-badge">${q.testCases.length} grading tests</span></summary>
                        <div class="qoda-section-body">
                            <div class="qoda-toolbar-actions">
                                <button class="btn primary small" onclick="generateAssessmentFromQuestion('${q.id}')"><i class="fas fa-wand-magic-sparkles"></i> Generate From Question</button>
                                <button class="btn primary small" onclick="addTestCase('${q.id}')"><i class="fas fa-plus"></i> Add Test Case</button>
                                <button class="btn small" onclick="batchImportTestCases('${q.id}')"><i class="fas fa-file-import"></i> Batch Import</button>
                                <button class="btn small" onclick="bulkUploadTestCases('${q.id}')"><i class="fas fa-upload"></i> Bulk Upload</button>
                                <button class="btn small" onclick="copyTestCasesFromPrevious('${q.id}')"><i class="fas fa-clone"></i> Copy Previous</button>
                            </div>
                            <div id="gradingCases_${q.id}">${renderModernTestCasesList(q)}</div>
                        </div>
                    </details>

                    <details class="qoda-author-section">
                        <summary><span><i class="fas fa-code"></i> Programming Language Settings</span><span class="qoda-badge">${escapeHTML(q.languageMode === 'multi' ? 'Multi-language' : q.language)}</span></summary>
                        <div class="qoda-section-body">
                            <div class="qoda-field-grid">
                                <div class="qoda-field"><label>Language</label><select onchange="updateCodeLanguage('${q.id}',this.value)">
                                    ${codingLanguagesList.map(lang => `<option value="${escapeHTML(lang)}" ${q.language === lang ? 'selected' : ''}>${escapeHTML(lang)}</option>`).join('')}
                                </select></div>
                            </div>
                            <label class="qoda-toggle-row"><input type="checkbox" ${q.languageMode === 'multi' ? 'checked' : ''} onchange="updateQuestion('${q.id}','languageMode',this.checked?'multi':'single')"> Multi-language mode</label>
                        </div>
                    </details>

                    <details class="qoda-author-section">
                        <summary><span><i class="fas fa-file-code"></i> Starter Code</span><span class="qoda-badge">Student template</span></summary>
                        <div class="qoda-section-body">
                            <div class="qoda-field">
                                <label>Editable starter code template</label>
                                <div class="qoda-code-shell">
                                    <div class="qoda-code-head">
                                        <span class="qoda-code-tab"><i class="fas fa-code"></i> ${escapeHTML(qodaCodeFileName(q, 'starterCode'))}<span class="qoda-code-close">x</span></span>
                                        <span class="qoda-code-status">VS Code editor</span>
                                    </div>
                                    <textarea id="starterCode_${q.id}" class="qoda-code-editor" spellcheck="false" onkeydown="handleQodaCodeTextareaKeydown(event,'${q.id}','starterCode')" oninput="updateQuestion('${q.id}','starterCode',this.value)" placeholder="Optional code students receive when the exam opens.">${escapeHTML(q.starterCode || '')}</textarea>
                                    <div id="starterMonaco_${q.id}" class="qoda-monaco-editor" data-question-id="${q.id}" data-field="starterCode" data-language="${monacoLanguageFromQuestion(q.languageMode === 'multi' ? q.language : q.language)}"></div>
                                </div>
                            </div>
                        </div>
                    </details>

                    <details class="qoda-author-section">
                        <summary><span><i class="fas fa-lock"></i> Model Solution</span><span class="qoda-badge ${q.modelValidationStatus === 'passed' ? 'success' : q.modelValidationStatus === 'failed' ? 'warning' : ''}">${q.modelValidationStatus || 'Hidden'}</span></summary>
                        <div class="qoda-section-body">
                            <div class="qoda-field">
                                <label>Hidden model solution</label>
                                <div class="qoda-code-shell">
                                    <div class="qoda-code-head">
                                        <span class="qoda-code-tab"><i class="fas fa-code"></i> ${escapeHTML(qodaCodeFileName(q, 'modelSolution'))}<span class="qoda-code-close">x</span></span>
                                        <span class="qoda-code-status">VS Code editor</span>
                                    </div>
                                    <textarea id="modelSolution_${q.id}" class="qoda-code-editor" spellcheck="false" onkeydown="handleQodaCodeTextareaKeydown(event,'${q.id}','modelSolution')" oninput="updateQuestion('${q.id}','modelSolution',this.value)" placeholder="Students never see this. Use it to validate the question before publishing.">${escapeHTML(q.modelSolution || '')}</textarea>
                                    <div id="modelMonaco_${q.id}" class="qoda-monaco-editor" data-question-id="${q.id}" data-field="modelSolution" data-language="${monacoLanguageFromQuestion(q.languageMode === 'multi' ? q.language : q.language)}"></div>
                                </div>
                            </div>
                            <div class="qoda-toolbar-actions">
                                <button class="btn small" onclick="runModelSolution('${q.id}')"><i class="fas fa-play"></i> Run Model Solution</button>
                                <button class="btn primary small" onclick="validateModelSolutionAgainstTests('${q.id}')"><i class="fas fa-check-double"></i> Validate Against Test Cases</button>
                            </div>
                            <div id="modelValidation_${q.id}" class="qoda-autosave">${escapeHTML(q.modelValidationMessage || 'Model solution has not been validated yet.')}</div>
                        </div>
                    </details>

                    <details class="qoda-author-section">
                        <summary><span><i class="fas fa-database"></i> Question Bank</span><span class="qoda-badge">Reuse and tags</span></summary>
                        <div class="qoda-section-body">
                            <div class="qoda-field-grid">
                                <div class="qoda-field"><label>Topic</label><input value="${escapeHTML(q.topic || '')}" onchange="updateQuestion('${q.id}','topic',this.value)"></div>
                                <div class="qoda-field"><label>Subtopic</label><input value="${escapeHTML(q.subtopic || '')}" onchange="updateQuestion('${q.id}','subtopic',this.value)"></div>
                                <div class="qoda-field"><label>Tags</label><input value="${escapeHTML(q.tags || q.questionBankTags || '')}" placeholder="arrays, loops, functions" onchange="updateQuestion('${q.id}','tags',this.value);updateQuestion('${q.id}','questionBankTags',this.value)"></div>
                            </div>
                            <div class="qoda-toolbar-actions">
                                <button class="btn primary small" onclick="saveQuestionToBank('${q.id}')"><i class="fas fa-save"></i> Save To Question Bank</button>
                                <button class="btn small" onclick="openQuestionBankModal()"><i class="fas fa-search"></i> Search Existing Questions</button>
                                <button class="btn small" onclick="openQuestionBankModal()"><i class="fas fa-copy"></i> Clone Existing Question</button>
                                <button class="btn small" onclick="openTemplateLibrary('${q.id}')"><i class="fas fa-layer-group"></i> Import Existing Question</button>
                            </div>
                        </div>
                    </details>

                </div>

                <aside class="qoda-preview-panel">
                    <div class="qoda-preview-head">
                        <span><i class="fas fa-eye"></i> Live Student Preview</span>
                        <span class="qoda-badge">${readyCount}/${validation.length}</span>
                    </div>
                    <div id="qPreview_${q.id}">${renderQuestionPreview(q)}</div>
                </aside>
            </div>

            <div class="qoda-publish-toolbar">
                <div class="qoda-autosave">Auto-save every 30 seconds. Missing fields are marked in the checklist.</div>
                <div class="qoda-toolbar-actions">
                    <button class="btn" onclick="saveDraftExam()"><i class="fas fa-save"></i> Save Draft</button>
                    <button class="btn" onclick="previewCodeQuestion('${q.id}')"><i class="fas fa-eye"></i> Preview</button>
                    <button class="btn primary" onclick="validateQuestion('${q.id}')"><i class="fas fa-check-circle"></i> Validate Question</button>
                    <button class="btn success" onclick="publishExam()"><i class="fas fa-paper-plane"></i> Publish</button>
                </div>
            </div>
        </article>`;
    }

    function addSampleCase(qId) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question.sampleCases.push({
            input: '',
            output: '',
            explanation: ''
        });
        persistQuestionMutation(ctx, true);
    }

    function updateSampleCase(qId, index, field, value) {
        const ctx = getQuestionById(qId);
        if (!ctx || !ctx.question.sampleCases[index]) return;
        ctx.question.sampleCases[index][field] = value;
        persistQuestionMutation(ctx, false);
    }

    function removeSampleCase(qId, index) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question.sampleCases.splice(index, 1);
        persistQuestionMutation(ctx, true);
    }

    function duplicateSampleCase(qId, index) {
        const ctx = getQuestionById(qId);
        if (!ctx || !ctx.question.sampleCases[index]) return;
        ctx.question.sampleCases.splice(index + 1, 0, JSON.parse(JSON.stringify(ctx.question.sampleCases[index])));
        persistQuestionMutation(ctx, true);
    }

    function distributeMarks(total, labels) {
        const cleanTotal = Math.max(1, parseFloat(total) || 1);
        const base = Math.floor((cleanTotal / labels.length) * 100) / 100;
        let remaining = cleanTotal;
        return labels.map((label, index) => {
            const marks = index === labels.length - 1 ? remaining : base;
            remaining = Math.max(0, remaining - marks);
            return { criterion: label[0], marks: Math.round(marks * 100) / 100, description: label[1] };
        });
    }

    function generatedTestsForQuestion(q) {
        const text = richToPlain(q.problemStatement || q.text || '').toLowerCase();
        const language = String(q.language || '').toLowerCase();
        const isWeb = language.includes('html') || language.includes('css') || language.includes('javascript') || language.includes('js');
        const isSql = language.includes('sql');
        if (isWeb) {
            return [
                { input: '', expected: text.includes('form') ? '<form' : text.includes('responsive') ? '@media' : '<', hidden: false, comparisonMode: 'contains' },
                { input: '', expected: text.includes('button') ? 'button' : text.includes('style') ? 'style' : 'class', hidden: true, comparisonMode: 'contains' }
            ];
        }
        if (isSql) {
            return [
                { input: '', expected: text.includes('join') ? 'JOIN' : 'SELECT', hidden: false, comparisonMode: 'contains' },
                { input: '', expected: text.includes('count') || text.includes('aggregate') ? 'GROUP BY' : 'WHERE', hidden: true, comparisonMode: 'contains' }
            ];
        }
        if (text.includes('sum') || text.includes('add')) {
            return [{ input: '23 3', expected: '26', hidden: false }, { input: '10 15', expected: '25', hidden: true }];
        }
        if (text.includes('even') || text.includes('odd')) {
            return [{ input: '8', expected: 'Even', hidden: false }, { input: '7', expected: 'Odd', hidden: true }];
        }
        if (text.includes('largest') || text.includes('maximum') || text.includes('max')) {
            return [{ input: '12 45 23', expected: '45', hidden: false }, { input: '7 3 9', expected: '9', hidden: true }];
        }
        if (text.includes('reverse')) {
            return [{ input: 'qoda', expected: 'adoq', hidden: false }, { input: 'exam', expected: 'maxe', hidden: true }];
        }
        if (text.includes('sort')) {
            return [{ input: '4\n9 1 7 3', expected: '1 3 7 9', hidden: false }, { input: '3\n-1 2 0', expected: '-1 0 2', hidden: true }];
        }
        return [{ input: '2 3', expected: '5', hidden: false }, { input: '5 6', expected: '11', hidden: true }];
    }

    function generateAssessmentFromQuestion(qId) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        const marks = parseFloat(ctx.question.marks || 0) || 5;
        ctx.question.testCases = generatedTestsForQuestion(ctx.question).map((tc, index) => ({
            marks: Math.round((marks / 2) * 100) / 100,
            comparisonMode: tc.comparisonMode || 'ignore_trailing',
            ...tc,
            expectedOutput: tc.expected
        }));
        ctx.question.markingRubric = [];
        persistQuestionMutation(ctx, true);
        toast('Generated test cases from the question.');
    }

    function addTestCase(qId) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question.testCases.push({
            input: '',
            expected: '',
            comparisonMode: ctx.question.executionSettings?.comparisonMode || 'exact',
            hidden: false
        });
        persistQuestionMutation(ctx, true);
    }

    function updateTestCase(qId, index, field, value) {
        const ctx = getQuestionById(qId);
        if (!ctx || !ctx.question.testCases[index]) return;
        ctx.question.testCases[index][field] = value;
        if (field === 'expected') ctx.question.testCases[index].expectedOutput = value;
        persistQuestionMutation(ctx, false);
    }

    function removeTestCase(qId, index) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question.testCases.splice(index, 1);
        persistQuestionMutation(ctx, true);
    }

    function duplicateTestCase(qId, index) {
        const ctx = getQuestionById(qId);
        if (!ctx || !ctx.question.testCases[index]) return;
        ctx.question.testCases.splice(index + 1, 0, JSON.parse(JSON.stringify(ctx.question.testCases[index])));
        persistQuestionMutation(ctx, true);
    }

    function batchImportTestCases(qId) {
        const raw = prompt('Paste test cases as JSON array: [{"input":"1\\n2","expected":"3","hidden":true}]');
        if (!raw) return;
        try {
            const imported = JSON.parse(raw);
            if (!Array.isArray(imported)) throw new Error('Expected a JSON array');
            const ctx = getQuestionById(qId);
            if (!ctx) return;
            imported.forEach(tc => ctx.question.testCases.push({
                input: String(tc.input || ''),
                expected: String(tc.expected || tc.expectedOutput || ''),
                comparisonMode: tc.comparisonMode || 'exact',
                hidden: !!tc.hidden
            }));
            persistQuestionMutation(ctx, true);
        } catch (error) {
            toast('Invalid test case import: ' + error.message);
        }
    }

    function bulkUploadTestCases(qId) {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.json,application/json,text/plain';
        input.onchange = () => {
            const file = input.files?.[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = () => {
                try {
                    const imported = JSON.parse(String(reader.result || '[]'));
                    if (!Array.isArray(imported)) throw new Error('Expected a JSON array');
                    const ctx = getQuestionById(qId);
                    if (!ctx) return;
                    imported.forEach(tc => ctx.question.testCases.push({
                        input: String(tc.input || ''),
                        expected: String(tc.expected || tc.expectedOutput || ''),
                        comparisonMode: tc.comparisonMode || 'exact',
                        hidden: !!tc.hidden
                    }));
                    persistQuestionMutation(ctx, true);
                    toast(`Uploaded ${imported.length} test case(s).`);
                } catch (error) {
                    toast('Bulk upload failed: ' + error.message);
                }
            };
            reader.readAsText(file);
        };
        input.click();
    }

    function copyTestCasesFromPrevious(qId) {
        const exam = findExam(currentExamId);
        if (!exam) return;
        const currentIndex = (exam.questions || []).findIndex(q => q.id === qId);
        const previous = (exam.questions || []).slice(0, currentIndex).reverse().find(q => Array.isArray(q.testCases) && q.testCases.length);
        if (!previous) {
            toast('No previous question has test cases to copy.');
            return;
        }
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question.testCases = JSON.parse(JSON.stringify(previous.testCases || []));
        persistQuestionMutation(ctx, true);
    }

    function addRubricCriterion(qId) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question.markingRubric.push({
            criterion: '',
            marks: 1,
            description: ''
        });
        persistQuestionMutation(ctx, true);
    }

    function updateRubricCriterion(qId, index, field, value) {
        const ctx = getQuestionById(qId);
        if (!ctx || !ctx.question.markingRubric[index]) return;
        ctx.question.markingRubric[index][field] = value;
        persistQuestionMutation(ctx, false);
    }

    function removeRubricCriterion(qId, index) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        ctx.question.markingRubric.splice(index, 1);
        persistQuestionMutation(ctx, true);
    }

    async function runQuestionModelCode(question, withTests = false) {
        const payload = {
            code: question.modelSolution || '',
            language: question.language || 'Python',
            input: '',
            use_inferred_input: !withTests,
            question_text: richToPlain(question.problemStatement || question.text || ''),
            test_cases: withTests ? (question.testCases || []).map(tc => ({
                input: tc.input || '',
                expected: tc.expected || tc.expectedOutput || '',
                tolerance: tc.tolerance || null,
                comparisonMode: tc.comparisonMode || 'exact'
            })) : []
        };
        const response = await fetch('api/run_code.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        return await response.json();
    }

    async function runModelSolution(qId) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        if (!String(ctx.question.modelSolution || '').trim()) {
            toast('Add a model solution before running.');
            return;
        }
        const target = document.getElementById(`modelValidation_${qId}`);
        if (target) target.textContent = 'Running model solution...';
        try {
            const result = await runQuestionModelCode(ctx.question, false);
            const output = result.success ? (result.output || 'Model solution ran successfully.') : (result.error || 'Model solution failed.');
            ctx.question.modelValidationMessage = output.slice(0, 500);
            ctx.question.modelValidationStatus = result.success ? 'ran' : 'failed';
            persistQuestionMutation(ctx, true);
            toast(result.success ? 'Model solution ran successfully.' : 'Model solution failed.');
        } catch (error) {
            ctx.question.modelValidationStatus = 'failed';
            ctx.question.modelValidationMessage = error.message;
            persistQuestionMutation(ctx, true);
            toast('Model solution failed: ' + error.message);
        }
    }

    async function validateModelSolutionAgainstTests(qId) {
        const ctx = getQuestionById(qId);
        if (!ctx) return;
        const hasSolution = !!String(ctx.question.modelSolution || '').trim();
        const hasTests = (ctx.question.testCases || []).length > 0;
        if (!hasSolution || !hasTests) {
            ctx.question.modelValidationStatus = 'failed';
            ctx.question.modelValidationMessage = 'Failed: add both a model solution and at least one grading test case.';
            persistQuestionMutation(ctx, true);
            toast(ctx.question.modelValidationMessage);
            return;
        }
        const target = document.getElementById(`modelValidation_${qId}`);
        if (target) target.textContent = 'Validating model solution against test cases...';
        try {
            const result = await runQuestionModelCode(ctx.question, true);
            const caseResults = Array.isArray(result.results) ? result.results : [];
            const passed = caseResults.length > 0 && caseResults.every(tc => tc.passed);
            ctx.question.modelValidationStatus = passed ? 'passed' : 'failed';
            ctx.question.modelValidationMessage = passed ?
                `Passed ${caseResults.length}/${caseResults.length} test case(s).` :
                `Failed ${caseResults.filter(tc => tc.passed).length}/${caseResults.length || ctx.question.testCases.length} test case(s).`;
            persistQuestionMutation(ctx, true);
            toast(ctx.question.modelValidationMessage);
        } catch (error) {
            ctx.question.modelValidationStatus = 'failed';
            ctx.question.modelValidationMessage = error.message;
            persistQuestionMutation(ctx, true);
            toast('Validation failed: ' + error.message);
        }
    }

    function validateQuestion(qId) {
        const ctx = getQuestionById(qId);
        if (!ctx) return false;
        const missing = getQuestionValidation(ctx.question).filter(item => !item.ok).map(item => item.label);
        if (missing.length) {
            confirmPopup('Missing items:\n' + missing.join('\n'), 'Question validation', 'OK');
            return false;
        }
        toast('Question is ready for publication.');
        return true;
    }

    async function saveQuestionToBank(qId) {
        const ctx = getQuestionById(qId);
        const exam = findExam(currentExamId);
        if (!ctx || !exam) return;
        const response = await apiRequest('save_question_to_bank', {
            course_code: exam.courseCode || document.getElementById('bCode')?.value || '',
            course_name: exam.title || document.getElementById('bTitle')?.value || '',
            semester: exam.semester || document.getElementById('semester')?.value || '',
            academic_year: exam.academic_year || document.getElementById('academic_year')?.value || '',
            topic: ctx.question.topic || '',
            difficulty: ctx.question.difficulty || '',
            language: ctx.question.language || '',
            title: ctx.question.title || `Question ${ctx.qIndex + 1}`,
            prompt: richToPlain(ctx.question.problemStatement || ctx.question.text),
            question_json: JSON.stringify(ctx.question),
            test_cases: JSON.stringify(ctx.question.testCases || []),
            marks: ctx.question.marks || 0,
            source_exam_id: currentExamId
        });
        toast(response.success ? 'Question saved to question bank.' : (response.error || 'Could not save question to bank.'));
    }

    function updateBuilderValidationSummary() {
        const exam = findExam(currentExamId);
        if (!exam || !Array.isArray(exam.questions)) return;
        exam.questions.forEach(q => {
            const target = document.getElementById(`validation_${q.id}`);
            if (!target) return;
            target.innerHTML = renderValidationStatusPanel(q);
        });
    }

    function ensureActiveSessionsModal() {
        let modal = document.getElementById('activeStudentSessionsModal');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'activeStudentSessionsModal';
        modal.className = 'modal';
        modal.style.display = 'none';
        modal.innerHTML = `
            <div class="modal-content" style="width:1050px;max-width:96%;max-height:90vh;overflow:auto;">
                <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
                    <div>
                        <h3 style="margin:0;"><i class="fas fa-desktop"></i> Active Student Sessions</h3>
                        <small style="color:var(--muted);">One active login is allowed per student. Force logout is for device crashes or emergency recovery.</small>
                    </div>
                    <button class="btn" onclick="closeActiveStudentSessionsModal()"><i class="fas fa-times"></i> Close</button>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                    <button class="btn primary" onclick="loadActiveStudentSessions()"><i class="fas fa-sync"></i> Refresh</button>
                    <button class="btn" onclick="loadStudentSessionHistory(0)"><i class="fas fa-history"></i> View Recent History</button>
                </div>
                <div id="activeStudentSessionsList" style="display:grid;gap:12px;"></div>
                <div id="studentSessionHistoryList" style="margin-top:16px;display:grid;gap:10px;"></div>
            </div>`;
        document.body.appendChild(modal);
        return modal;
    }

    async function showActiveStudentSessionsModal() {
        const modal = ensureActiveSessionsModal();
        modal.style.display = 'flex';
        await loadActiveStudentSessions();
    }

    function closeActiveStudentSessionsModal() {
        const modal = document.getElementById('activeStudentSessionsModal');
        if (modal) modal.style.display = 'none';
    }

    async function loadActiveStudentSessions() {
        const list = document.getElementById('activeStudentSessionsList');
        if (list) list.innerHTML = '<div class="empty-state">Loading active sessions...</div>';
        const result = await apiRequest('get_active_student_sessions');
        if (!list) return;
        if (!result.success) {
            list.innerHTML = `<div class="empty-state">Could not load active sessions: ${escapeHTML(result.error || 'Unknown error')}</div>`;
            return;
        }
        const rows = result.data || [];
        if (!rows.length) {
            list.innerHTML = '<div class="empty-state">No active student sessions right now.</div>';
            return;
        }
        list.innerHTML = rows.map(row => `
            <div class="qoda-case-card">
                <div class="qoda-case-head">
                    <div>
                        <strong>${escapeHTML(row.full_name || row.student_identifier || 'Student')}</strong>
                        <div style="color:var(--muted);font-size:12px;">${escapeHTML(row.student_identifier || '')} | Level ${escapeHTML(row.level || '-')} | ${escapeHTML(row.programme || '-')}</div>
                    </div>
                    <div class="qoda-case-actions">
                        <button class="btn small" onclick="loadStudentSessionHistory(${parseInt(row.student_id, 10) || 0})"><i class="fas fa-history"></i> History</button>
                        <button class="btn danger small" onclick="forceLogoutStudentSession(${parseInt(row.student_id, 10) || 0}, ${parseInt(row.id, 10) || 0})"><i class="fas fa-right-from-bracket"></i> Force Logout</button>
                    </div>
                </div>
                <div class="qoda-field-grid">
                    <div><small>Device</small><div>${escapeHTML(row.operating_system || 'Unknown OS')}</div></div>
                    <div><small>IP Address</small><div>${escapeHTML(row.ip_address || '-')}</div></div>
                    <div><small>Login Time</small><div>${escapeHTML(row.login_at || '-')}</div></div>
                    <div><small>Last Seen</small><div>${escapeHTML(row.last_seen || '-')}</div></div>
                </div>
            </div>`).join('');
    }

    async function forceLogoutStudentSession(studentId, sessionRecordId) {
        const ok = await confirmPopup('Force logout this student session? The student can log in again on one device and continue from autosaved answers.', 'Force Logout', 'Force Logout');
        if (!ok) return;
        const result = await apiRequest('force_logout_student_session', {
            student_id: studentId,
            session_record_id: sessionRecordId
        });
        toast(result.success ? 'Student session released.' : (result.error || 'Could not release session.'));
        await loadActiveStudentSessions();
    }

    async function loadStudentSessionHistory(studentId = 0) {
        const target = document.getElementById('studentSessionHistoryList');
        if (!target) return;
        target.innerHTML = '<div class="empty-state">Loading session history...</div>';
        const result = await apiRequest('get_student_session_history', {
            student_id: studentId
        });
        if (!result.success) {
            target.innerHTML = `<div class="empty-state">Could not load history: ${escapeHTML(result.error || 'Unknown error')}</div>`;
            return;
        }
        const rows = result.data || [];
        if (!rows.length) {
            target.innerHTML = '<div class="empty-state">No session history found.</div>';
            return;
        }
        target.innerHTML = `
            <h4 style="margin:0;">Session and Device History</h4>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead><tr><th>Time</th><th>Event</th><th>OS</th><th>IP</th><th>Exam</th><th>Details</th></tr></thead>
                    <tbody>${rows.map(row => `
                        <tr>
                            <td>${escapeHTML(row.created_at || '')}</td>
                            <td>${escapeHTML(row.event_type || '')}</td>
                            <td>${escapeHTML(row.operating_system || '')}</td>
                            <td>${escapeHTML(row.ip_address || '')}</td>
                            <td>${escapeHTML(row.exam_id || '')}</td>
                            <td>${escapeHTML(row.details || '')}</td>
                        </tr>`).join('')}</tbody>
                </table>
            </div>`;
    }

    const qodaLegacyPublishExam = publishExam;
    publishExam = async function() {
        const exam = findExam(currentExamId);
        if (exam && Array.isArray(exam.questions)) {
            const problems = [];
            exam.questions.forEach((q, index) => {
                const missing = getQuestionValidation(q).filter(item => !item.ok && item.label !== 'Question ready for publication');
                if (missing.length) {
                    problems.push(`Q${index + 1}: ${missing.map(m => m.label).join(', ')}`);
                }
            });
            if (problems.length) {
                await confirmPopup('Fix these before publishing:\n' + problems.join('\n'), 'Publishing validation', 'OK');
                return;
            }
        }
        return qodaLegacyPublishExam();
    };

    const qodaLegacyRemoveQuestion = removeQuestion;
    removeQuestion = async function(qId) {
        const ok = await confirmPopup('Delete this question from the exam?', 'Delete question', 'Delete');
        if (ok) qodaLegacyRemoveQuestion(qId);
    };

    function enhanceSidebarTooltips() {
        const descriptions = {
            Profile: 'Lecturer profile and teaching courses',
            'Add Lecturer': 'Create another lecturer account',
            Dashboard: 'Home, statistics, and activity',
            'Exam Management': 'Create exams, submissions, and results',
            'Student Management': 'Students, enrollment, and levels',
            Monitoring: 'Proctoring and screen evidence',
            Account: 'Profile and account tools',
            'Switch Theme': 'Toggle light or dark mode',
            Logout: 'Sign out safely'
        };
        document.querySelectorAll('.tooltip-text').forEach(tip => {
            const label = tip.textContent.trim();
            if (!label || tip.dataset.enhanced === '1') return;
            tip.innerHTML = `<strong>${escapeHTML(label)}</strong><small>${escapeHTML(descriptions[label] || 'Open this dashboard section')}</small>`;
            tip.dataset.enhanced = '1';
        });
    }

    document.addEventListener('pointerdown', function(e) {
        const panel = document.getElementById('submenuPanel');
        if (!panel || !panel.classList.contains('open')) return;
        if (e.target.closest('#submenuPanel, .nav-icon.has-submenu, .submenu-item')) return;
        closeSubmenuPanel();
    }, true);

    if (window.qodaBuilderAutoSaveTimer) clearInterval(window.qodaBuilderAutoSaveTimer);
    window.qodaBuilderAutoSaveTimer = setInterval(() => {
        if (currentExamId && document.getElementById('view-builder')?.classList.contains('active')) {
            saveAllFormDataToExam();
            saveExamToDatabase();
            document.querySelectorAll('.qoda-autosave').forEach(el => {
                if (el.id && el.id.startsWith('autosave_')) return;
                el.textContent = `Auto-saved at ${new Date().toLocaleTimeString()}`;
            });
        }
    }, 30000);

    document.addEventListener('DOMContentLoaded', enhanceSidebarTooltips);
