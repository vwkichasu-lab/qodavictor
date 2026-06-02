<?php

require_once __DIR__ . '/../backend-php/lib/code_grader.php';

$cases = [
    [
        'name' => 'Python addition',
        'payload' => [
            'code' => "a=int(input())\nb=int(input())\nprint(a+b)",
            'language' => 'python',
            'max_marks' => 10,
            'test_cases' => [
                ['input' => "2\n3\n", 'expected' => '5', 'marks' => 10],
            ],
        ],
        'expected_score' => 10,
    ],
    [
        'name' => 'Wrong output gets zero',
        'payload' => [
            'code' => 'print("wrong")',
            'language' => 'python',
            'max_marks' => 10,
            'test_cases' => [
                ['input' => '', 'expected' => 'right', 'marks' => 10],
            ],
        ],
        'expected_score' => 0,
    ],
];

$failed = 0;
foreach ($cases as $case) {
    $result = gradeQodaCode($case['payload']);
    $ok = abs((float)$result['score'] - (float)$case['expected_score']) < 0.001;
    echo '[' . ($ok ? 'PASS' : 'FAIL') . '] ' . $case['name'] . ' score=' . $result['score'] . PHP_EOL;
    if (!$ok) {
        $failed++;
        echo $result['feedback'] . PHP_EOL;
    }
}

exit($failed > 0 ? 1 : 0);
