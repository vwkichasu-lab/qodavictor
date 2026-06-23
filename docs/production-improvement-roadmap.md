# QODA Production Improvement Roadmap

This roadmap separates the current stabilization work from future feature expansion. The goal is to keep QODA reliable, secure, and easy to demonstrate.

## Completed Stabilization Items

- Production database errors are now logged privately and hidden from users.
- JWT secrets are no longer hardcoded for production; Railway should provide `JWT_SECRET`.
- Main exam and lecturer pages now silence development `console.log` output unless `?debug=1` is used.
- A safe migration pattern was added for performance indexes.
- Performance indexes were added for login, exams, submissions, score sheets, and proctoring tables.

## Highest Priority Next Work

1. Split `web-client/lecturer_dashboard.php` into smaller modules.
   - API actions
   - Exam builder view
   - Submissions view
   - Results and score sheet view
   - Proctoring view
   - Profile/account view

2. Split `web-client/exam_interface.php` into smaller modules.
   - Timer and exam controls
   - Monaco editor setup
   - Submission/autosave
   - Proctoring and screen sharing
   - Question rendering

3. Consolidate old/reference code.
   - Move `desktop-client` and backup files outside the production app.
   - Keep one official backend path for production behavior.

4. Strengthen security.
   - Add login rate limiting.
   - Add audit logs for password resets and grade changes.
   - Require stronger default password reset flow.
   - Configure `JWT_SECRET`, `QODA_APP_SECRET`, and `QODA_SOCKET_SECRET` in Railway.

5. Improve grading transparency.
   - Show per-test-case pass/fail results to lecturers.
   - Show AI grading reasons separately from compiler/test results.
   - Preserve lecturer override history.

6. Improve reliability.
   - Move to a larger managed MySQL database when usage grows.
   - Enable automated database backups.
   - Store large evidence files in object storage instead of database columns.

7. Add repeatable tests.
   - Login smoke test.
   - Exam creation/publish smoke test.
   - Student submit and auto-grade smoke test.
   - Lecturer score sheet smoke test.
   - Proctoring connection smoke test.

## Defense Talking Point

QODA is already feature complete for the academic demonstration. The most important professional improvement is controlled modularization: reducing large files into maintainable services and views without changing the user experience.
