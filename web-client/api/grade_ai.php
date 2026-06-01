<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../../backend-php/config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$submission_id = $input['submission_id'] ?? 0;
$question_index = $input['question_index'] ?? 0;
$code = $input['code'] ?? '';
$language = $input['language'] ?? 'java';
$test_cases = $input['test_cases'] ?? [];
$marking_scheme = $input['marking_scheme'] ?? '';
$max_marks = floatval($input['max_marks'] ?? 20);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS ai_grading_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consistency_hash CHAR(32) NOT NULL UNIQUE,
        score DECIMAL(10,2) NOT NULL DEFAULT 0,
        feedback TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_consistency_hash (consistency_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Generate a consistency hash based on code content
$consistency_hash = md5($code . $language . json_encode($test_cases) . $marking_scheme);

// Check if we already graded this exact code before
$stmt = $pdo->prepare("SELECT score, feedback FROM ai_grading_cache WHERE consistency_hash = ?");
$stmt->execute([$consistency_hash]);
$cached = $stmt->fetch(PDO::FETCH_ASSOC);

if ($cached) {
    echo json_encode([
        'success' => true,
        'score' => floatval($cached['score']),
        'feedback' => $cached['feedback'],
        'consistency_hash' => $consistency_hash,
        'results' => [],
        'test_results' => []
    ]);
    exit;
}

// Calculate score based on test cases
$test_results = [];
$test_score = 0;
$total_test_weight = 0;

foreach ($test_cases as $idx => $tc) {
    $input_val = $tc['input'] ?? '';
    $expected = trim($tc['expected'] ?? '');
    $weight = floatval($tc['marks'] ?? 0);
    $total_test_weight += $weight;
    
    // Simulate code execution (use actual execution in production)
    $actual = simulateCodeExecution($code, $language, $input_val);
    $passed = (trim($actual) === $expected);
    
    if ($passed) {
        $test_score += $weight;
    }
    
    $test_results[] = [
        'test_case' => $idx + 1,
        'passed' => $passed,
        'input' => $input_val,
        'expected' => $expected,
        'actual' => $actual
    ];
}

// Parse marking scheme for additional scoring
$scheme_score = 0;
$scheme_max = 0;
$marking_criteria = parseMarkingScheme($marking_scheme);

foreach ($marking_criteria as $criterion) {
    $scheme_max += $criterion['marks'];
    if (evaluateCriterion($code, $criterion['text'])) {
        $scheme_score += $criterion['marks'];
    }
}

// Calculate final score. When a marking scheme exists, give it equal weight with tests.
$final_score = 0;
if ($total_test_weight > 0 && $scheme_max > 0) {
    $final_score += ($test_score / $total_test_weight) * ($max_marks * 0.5);
    $final_score += ($scheme_score / $scheme_max) * ($max_marks * 0.5);
} elseif ($total_test_weight > 0) {
    $final_score = ($test_score / $total_test_weight) * $max_marks;
} elseif ($scheme_max > 0) {
    $final_score = ($scheme_score / $scheme_max) * $max_marks;
}
$final_score = round($final_score, 1);

// Generate consistent AI feedback
$feedback = generateConsistentFeedback($code, $language, $test_results, $final_score, $max_marks, $test_score, $total_test_weight, $scheme_score, $scheme_max);

// Cache the result for consistency
$stmt = $pdo->prepare("INSERT INTO ai_grading_cache (consistency_hash, score, feedback, created_at) VALUES (?, ?, ?, NOW())");
$stmt->execute([$consistency_hash, $final_score, $feedback]);

echo json_encode([
    'success' => true,
    'score' => $final_score,
    'feedback' => $feedback,
    'consistency_hash' => $consistency_hash,
    'results' => $test_results,
    'test_results' => $test_results,
    'test_score' => $test_score,
    'scheme_score' => $scheme_score
]);

function simulateCodeExecution($code, $language, $input) {
    // For demo - in production, use actual execution
    if (strpos($code, 'add') !== false || strpos($code, 'sum') !== false || strpos($code, '+') !== false) {
        $numbers = preg_match_all('/-?\d+/', $input, $matches);
        if (count($matches[0]) >= 2) {
            return (string)(array_sum($matches[0]));
        }
    }
    if (strpos($code, 'Hello') !== false || strpos($code, 'World') !== false) {
        return 'Hello World';
    }
    return $input ?: '0';
}

function parseMarkingScheme($scheme) {
    $criteria = [];
    $lines = explode("\n", $scheme);
    foreach ($lines as $line) {
        if (preg_match('/(\d+)\s*marks?/i', $line, $marks_match)) {
            $marks = intval($marks_match[1]);
            $text = preg_replace('/\s*\d+\s*marks?/i', '', $line);
            $criteria[] = ['text' => trim($text), 'marks' => $marks];
        }
    }
    if (empty($criteria)) {
        $criteria = [
            ['text' => 'Correct output', 'marks' => floor(50)],
            ['text' => 'Logic and implementation', 'marks' => floor(30)],
            ['text' => 'Code efficiency', 'marks' => floor(20)]
        ];
    }
    return $criteria;
}

function evaluateCriterion($code, $criterion) {
    $criterion_lower = strtolower($criterion);
    if (strpos($criterion_lower, 'output') !== false || strpos($criterion_lower, 'correct') !== false) {
        return (strlen($code) > 50);
    }
    if (strpos($criterion_lower, 'logic') !== false || strpos($criterion_lower, 'implementation') !== false) {
        return (strpos($code, 'function') !== false || strpos($code, 'def ') !== false || strpos($code, 'public') !== false);
    }
    if (strpos($criterion_lower, 'efficiency') !== false || strpos($criterion_lower, 'best') !== false) {
        return (strlen($code) < 500 && strpos($code, '//') !== false);
    }
    return true;
}

function generateConsistentFeedback($code, $language, $test_results, $score, $max, $test_score, $test_total, $scheme_score, $scheme_total) {
    $passed = count(array_filter($test_results, function($r) { return $r['passed']; }));
    $total = count($test_results);
    $percentage = ($score / $max) * 100;
    
    $feedback = "=== AI GRADING REPORT ===\n\n";
    $feedback .= "📊 Score: $score / $max marks (" . round($percentage, 1) . "%)\n\n";
    
    $feedback .= "📋 DETAILED BREAKDOWN:\n";
    $feedback .= "• Test Cases: $test_score/$test_total marks (" . round(($test_score/max($test_total,1))*100, 1) . "%)\n";
    $feedback .= "• Marking Scheme: $scheme_score/$scheme_total marks (" . round(($scheme_score/max($scheme_total,1))*100, 1) . "%)\n\n";
    
    $feedback .= "🧪 TEST RESULTS:\n";
    foreach ($test_results as $r) {
        $feedback .= $r['passed'] ? "  ✓" : "  ✗";
        $feedback .= " Test {$r['test_case']}: ";
        if ($r['passed']) {
            $feedback .= "PASSED\n";
        } else {
            $feedback .= "FAILED\n";
            $feedback .= "     Expected: {$r['expected']}\n";
            $feedback .= "     Got: {$r['actual']}\n";
        }
    }
    
    $feedback .= "\n💡 CODE QUALITY ANALYSIS:\n";
    if (strlen($code) < 100) {
        $feedback .= "• Code is concise. Consider adding more comprehensive logic.\n";
    } else {
        $feedback .= "• Code length is appropriate for the problem.\n";
    }
    
    if (strpos($code, '//') !== false || strpos($code, '#') !== false) {
        $feedback .= "• Good use of comments for documentation.\n";
    } else {
        $feedback .= "• Add comments to explain your logic.\n";
    }
    
    if ($language === 'java' && strpos($code, 'public class') === false) {
        $feedback .= "• Ensure your code is wrapped in a proper class definition.\n";
    }
    
    if ($language === 'python' && strpos($code, 'def ') === false) {
        $feedback .= "• Consider using functions to organize your code.\n";
    }
    
    $feedback .= "\n🎯 FINAL VERDICT:\n";
    if ($percentage >= 80) {
        $feedback .= "Excellent work! Your solution is correct and well-structured. Keep up the great work!";
    } elseif ($percentage >= 60) {
        $feedback .= "Good effort! You're on the right track. Review the failed test cases to improve.";
    } elseif ($percentage >= 40) {
        $feedback .= "Needs improvement. Carefully review the problem statement and test cases.";
    } else {
        $feedback .= "Significant improvement needed. Start with a simple approach and build up.";
    }
    
    return $feedback;
}
?>
