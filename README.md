# QODA — Online Coding Examination System

A focused PHP/MySQL system where lecturers create **coding exams** and students write, run, and submit code in the browser. **No MCQ, short answer, or essay — coding only.**

## Stack
- PHP 7.4+ / 8+
- MySQL (XAMPP / phpMyAdmin)
- HTML / CSS / vanilla JS (in-browser JavaScript runner)

## Install (XAMPP)

1. Copy the `qoda` folder into `xampp/htdocs/`.
2. Start **Apache** and **MySQL** in XAMPP.
3. If your MySQL port is **3306** (not 3307), edit both:
   - `config/db.php`
   - `config/install.php`
   change `$DB_PORT = '3307'` → `'3306'`.
4. Visit **http://localhost/qoda/config/install.php** once — this creates the database, tables, demo accounts, and a sample JavaScript exam.
5. Go to **http://localhost/qoda/**.

## Database Changes

- Keep the full project schema updated in `backend-php/database.sql`.
- When a feature needs a database change, update the SQL file in VS Code and also provide the exact SQL snippet that can be pasted into phpMyAdmin.
- Use phpMyAdmin for live database updates, then keep `backend-php/database.sql` as the source of truth for fresh installs or resets.

## Demo accounts (password: `password123`)
- Lecturer: `lecturer@qoda.test`
- Student: `student@qoda.test`

## Flow

**Lecturer:** Login → Create Exam → add coding questions (prompt + language + expected output + points) → Save.

**Student:** Login → Available Exams → Start → write code (timer running) → click **Run** for JavaScript questions (output captured automatically) → Submit.

## Grading
- **JavaScript** questions are auto-graded by exact stdout match (`console.log` output trimmed and compared to the expected output).
- **Other languages** (Python, PHP, Java, C++, Other) are stored as code text and graded manually by the lecturer in the **Grade** screen.

## Files
```
qoda/
  config/         db.php, install.php
  auth/           login.php, register.php, logout.php
  lecturer/       dashboard.php, create_exam.php, save_exam.php,
                  view_exams.php, submissions.php, grade.php
  student/        dashboard.php, exams.php, take_exam.php,
                  submit_exam.php, result.php, results.php
  includes/       auth.php, header.php, footer.php
  assets/css/     style.css
  index.php
```
