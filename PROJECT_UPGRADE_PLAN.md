# QODA Project Upgrade Plan

This plan turns QODA from a working prototype-style PHP app into a safer, maintainable exam platform.

## Completed Foundation

- Added a migration runner: `scripts/migrate.php`
- Added first foundation migration: `database/migrations/202606020001_project_foundation.sql`
- Added shared security helpers: `backend-php/lib/security.php`
- Added production request router: `server_router.php`
- Blocked debug/repair/runtime paths from public serving in Docker deployment
- Added compiler input/code/file guardrails in `backend-php/lib/code_runner.php`
- Added reusable score sheet HTML template: `backend-php/lib/score_sheet_template.php`
- Added preflight checks: `scripts/preflight.php`
- Added grading smoke tests: `scripts/smoke_grade.php`
- Added score sheet sample renderer: `scripts/render_score_sheet_sample.php`

## Phase 1: Stabilize Active PHP App

- Move page-load schema changes into migrations.
- Keep `backend-php/database.sql` as fresh-install schema.
- Split `web-client/lecturer_dashboard.php` into:
  - `web-client/api/lecturer/exams.php`
  - `web-client/api/lecturer/students.php`
  - `web-client/api/lecturer/results.php`
  - `web-client/api/lecturer/proctoring.php`
  - `web-client/assets/js/lecturer/*.js`
  - `web-client/views/lecturer/*.php`
- Remove or archive old duplicate files:
  - `auth/`
  - `desktop-client/`
  - old `backend/` Node app if PHP remains the active backend
  - `web-client/code_excutor.php`
  - debug entrypoints already blocked by router

## Phase 2: Security

- Wire CSRF tokens into all state-changing POST requests.
- Add login rate limiting through `login_rate_limits`.
- Add central audit logging through `system_audit_events`.
- Add strict role guards for each API action.
- Add upload MIME/type/size checks.
- Rotate any shared API tokens that appeared in chat or logs.
- Add secure password reset flow with expiring tokens instead of direct password reset helper files.

## Phase 3: Compiler and Grading

- Run compiler jobs in a dedicated container or worker with no network egress.
- Add per-language memory limits.
- Add hidden test cases and visible sample tests.
- Add test-case groups and weights.
- Add plagiarism/similarity checks.
- Add compiler run audit rows through `compiler_run_audit`.
- Add optional AI feedback after deterministic test-case grading, not instead of it.

## Phase 4: Proctoring

- Replace database polling with WebRTC for live screen previews.
- Keep database screenshots only as evidence snapshots.
- Add violation timeline per student.
- Add lock/unlock audit entries.
- Add lecturer review workflow for screenshots and violations.
- Make submitted students leave the proctoring grid immediately.

## Phase 5: Results and Score Sheets

- Generate official score sheets server-side using `backend-php/lib/score_sheet_template.php`.
- Add PDF generation with a supported PHP library or a print worker.
- Store score sheet export events in `system_audit_events`.
- Add result appeals using `student_result_appeals`.
- Publish results per course/semester/exam group.

## Phase 6: Course, Question Bank, and Templates

- Build a course management page.
- Build a reusable question bank from `question_bank`.
- Build exam templates from `exam_templates`.
- Support bulk publish/unpublish.
- Support analytics by course, semester, level, and lecturer.

## Phase 7: Backups and Operations

- Build a backup/restore page using `backup_jobs`.
- Add scheduled database backup.
- Add production health endpoint.
- Add centralized logs for compiler errors, proctoring events, login failures, and grading changes.

## Phase 8: Tests and Deployment

- Run before deployment:
  - `php scripts/migrate.php`
  - `php scripts/preflight.php`
  - `php scripts/smoke_grade.php`
- Add browser tests for:
  - lecturer login
  - student login
  - exam creation
  - exam visibility
  - exam submission
  - grading
  - result publishing
  - score sheet print/export
- Replace PHP built-in server with Apache/Nginx/PHP-FPM for stronger production hosting.

