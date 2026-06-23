            <!-- EXAMS LIST -->
            <section id="view-exams" class="view">
                <div class="panel">
                    <div class="panel-title">📋 Exams <small>Published</small></div>
                    <div class="crumb">Home / Exams</div>
                    <div class="toolbar">
                        <div class="search">
                            <input id="examsSearch" placeholder="🔍 Search by title/code..." />
                            <button class="btn primary" onclick="applySearch('exams')">🔍 Search</button>
                        </div>
                        <button class="btn primary animate-pulse-slow" onclick="newExam()">✨ + Create New Exam</button>
                    </div>
                    <div id="examsTable" class="exam-created-grid"></div>
                </div>
            </section>

