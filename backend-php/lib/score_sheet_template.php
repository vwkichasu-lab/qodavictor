<?php

if (!function_exists('qodaScoreSheetGradeRemark')) {
    function qodaScoreSheetGradeRemark(string $grade): string
    {
        return [
            'A' => 'Excellent',
            'B+' => 'Very Good',
            'B' => 'Good',
            'C+' => 'Average',
            'C' => 'Fair',
            'D+' => 'Barely Satisfactory',
            'D' => 'Weak Pass',
            'E' => 'Fail',
            'X' => 'Absent',
        ][$grade] ?? '';
    }

    function qodaScoreSheetGrade(array $row): string
    {
        $class = (float)($row['class_score'] ?? 0);
        $exam = (float)($row['exam_score'] ?? 0);
        $total = (float)($row['total_score'] ?? 0);
        if ($class == 0.0 && $exam == 0.0 && $total == 0.0) {
            return 'X';
        }
        if (!empty($row['grade'])) {
            return (string)$row['grade'];
        }
        if ($total >= 80) return 'A';
        if ($total >= 75) return 'B+';
        if ($total >= 70) return 'B';
        if ($total >= 65) return 'C+';
        if ($total >= 60) return 'C';
        if ($total >= 55) return 'D+';
        if ($total >= 50) return 'D';
        return 'E';
    }

    function qodaScoreSheetAnalysis(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $grade = qodaScoreSheetGrade($row);
            $counts[$grade] = ($counts[$grade] ?? 0) + 1;
        }

        $order = ['D+', 'B', 'A', 'B+', 'X', 'C+', 'C', 'D', 'E'];
        $total = max(count($rows), 1);
        $analysis = [];
        foreach ($order as $grade) {
            if (empty($counts[$grade])) continue;
            $analysis[] = [
                'grade' => $grade,
                'count' => $counts[$grade],
                'percentage' => number_format(($counts[$grade] / $total) * 100, 1),
                'remark' => qodaScoreSheetGradeRemark($grade),
            ];
        }
        return $analysis;
    }

    function qodaRenderScoreSheetHtml(array $meta, array $rows): string
    {
        $school = htmlspecialchars(strtoupper($meta['school_name'] ?? 'PENTECOST UNIVERSITY'), ENT_QUOTES, 'UTF-8');
        $programme = htmlspecialchars($meta['programme'] ?? 'BIT', ENT_QUOTES, 'UTF-8');
        $schoolType = htmlspecialchars($meta['school_type'] ?? 'Weekend', ENT_QUOTES, 'UTF-8');
        $level = htmlspecialchars($meta['level'] ?? '', ENT_QUOTES, 'UTF-8');
        $semester = htmlspecialchars($meta['semester'] ?? '', ENT_QUOTES, 'UTF-8');
        $academicYear = htmlspecialchars($meta['academic_year'] ?? '', ENT_QUOTES, 'UTF-8');
        $intake = htmlspecialchars($meta['intake'] ?? '0', ENT_QUOTES, 'UTF-8');
        $courseName = htmlspecialchars($meta['course_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $courseCode = htmlspecialchars($meta['course_code'] ?? '', ENT_QUOTES, 'UTF-8');

        $studentRows = '';
        foreach ($rows as $index => $row) {
            $studentRows .= '<tr>';
            $studentRows .= '<td>' . ($index + 1) . '</td>';
            $studentRows .= '<td>' . htmlspecialchars($row['student_id'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $studentRows .= '<td class="name">' . htmlspecialchars($row['student_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $studentRows .= '<td>' . htmlspecialchars((string)($row['class_score'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>';
            $studentRows .= '<td>' . htmlspecialchars((string)($row['exam_score'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>';
            $studentRows .= '<td>' . htmlspecialchars((string)($row['total_score'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>';
            $studentRows .= '<td>' . htmlspecialchars(qodaScoreSheetGrade($row), ENT_QUOTES, 'UTF-8') . '</td>';
            $studentRows .= '</tr>';
        }

        $analysisRows = '';
        foreach (qodaScoreSheetAnalysis($rows) as $item) {
            $analysisRows .= '<tr>';
            $analysisRows .= '<td>' . htmlspecialchars($item['grade'], ENT_QUOTES, 'UTF-8') . '</td>';
            $analysisRows .= '<td>' . (int)$item['count'] . '</td>';
            $analysisRows .= '<td>' . htmlspecialchars($item['percentage'], ENT_QUOTES, 'UTF-8') . '</td>';
            $analysisRows .= '<td class="name">' . htmlspecialchars($item['remark'], ENT_QUOTES, 'UTF-8') . '</td>';
            $analysisRows .= '</tr>';
        }

        return <<<HTML
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{$courseCode} Score Sheet</title>
    <link rel="icon" type="image/png" href="/assets/qoda-logo.png">
    <style>
        @page { size: A4 landscape; margin: 9mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; margin: 0; }
        .sheet { padding: 0; }
        .title { text-align: center; font-weight: 700; line-height: 1.25; }
        .title h1 { font-size: 18px; margin: 0 0 4px; }
        .title h2 { font-size: 16px; margin: 0 0 6px; }
        .meta { text-align: center; font-size: 12px; line-height: 1.35; margin-bottom: 8px; }
        table { border-collapse: collapse; width: 100%; font-size: 10.5px; }
        th, td { border: 1px solid #111; padding: 3px 4px; text-align: center; vertical-align: middle; }
        th { font-weight: 700; background: #fff; }
        .name { text-align: left; }
        .analysis-title { font-size: 12px; font-weight: 700; margin: 12px 0 4px; }
        .analysis { width: 430px; max-width: 100%; }
    </style>
</head>
<body>
    <section class="sheet">
        <div class="title">
            <h1>{$school}</h1>
            <h2>SCORE SHEET</h2>
        </div>
        <div class="meta">
            <div><strong>{$programme} - {$schoolType}</strong></div>
            <div>Level {$level}&nbsp;&nbsp; Semester {$semester}&nbsp;&nbsp; {$academicYear}&nbsp;&nbsp; Intake: {$intake}</div>
            <div><strong>{$courseName} - {$courseCode}</strong></div>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:34px;">No</th>
                    <th style="width:118px;">Student No</th>
                    <th>Student Name</th>
                    <th style="width:78px;">Class<br>Score<br>40</th>
                    <th style="width:78px;">Exam<br>Score<br>60</th>
                    <th style="width:78px;">Total<br>Score<br>100</th>
                    <th style="width:78px;">Grade</th>
                </tr>
            </thead>
            <tbody>{$studentRows}</tbody>
        </table>
        <div class="analysis-title">GRADE ANALYSIS</div>
        <table class="analysis">
            <thead>
                <tr><th>Grade</th><th>No. of Students</th><th>Percentage(%)</th><th>Remark</th></tr>
            </thead>
            <tbody>{$analysisRows}</tbody>
        </table>
    </section>
</body>
</html>
HTML;
    }
}
