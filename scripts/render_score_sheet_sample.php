<?php

require_once __DIR__ . '/../backend-php/lib/score_sheet_template.php';

$html = qodaRenderScoreSheetHtml([
    'school_name' => 'PENTECOST UNIVERSITY',
    'programme' => 'BIT',
    'school_type' => 'Weekend',
    'level' => '400',
    'semester' => '1',
    'academic_year' => '2025/2026',
    'intake' => '0',
    'course_name' => 'Artificial Intelligence and Expert Systems',
    'course_code' => 'PBIT403',
], [
    ['student_id' => 'PU/210419', 'student_name' => 'ANGMOR, NICHOLAS', 'class_score' => 30, 'exam_score' => 26, 'total_score' => 56, 'grade' => 'D+'],
    ['student_id' => 'PUIT/22210001', 'student_name' => 'DZAH, GABRIEL BISMARK', 'class_score' => 37, 'exam_score' => 43, 'total_score' => 80, 'grade' => 'A'],
    ['student_id' => 'PU/211396', 'student_name' => 'OWUSU, JOSEPH KWESI AKUFFO', 'class_score' => 0, 'exam_score' => 0, 'total_score' => 0, 'grade' => 'X'],
]);

$target = __DIR__ . '/../runtime/score-sheet-sample.html';
if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0775, true);
}
file_put_contents($target, $html);
echo $target . PHP_EOL;
