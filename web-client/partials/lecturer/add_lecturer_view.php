            <!-- ADD LECTURER PAGE -->
            <section id="view-add-lecturer" class="view">
                <div class="panel">
                    <div class="panel-title"><i class="fas fa-user-plus"></i> Add Lecturer <small>Create another lecturer account</small></div>
                    <div class="crumb">Home / Add Lecturer</div>

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

                </div>
            </section>
