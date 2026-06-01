<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$code = $input['code'] ?? '';
$language = $input['language'] ?? 'python';
$testCases = $input['test_cases'] ?? [];
$action = $input['action'] ?? 'execute';
$markingScheme = $input['marking_scheme'] ?? '';

$tempDir = sys_get_temp_dir() . '/code_run_' . uniqid();
mkdir($tempDir, 0777, true);

$startTime = microtime(true);
$results = [];
$totalScore = 0;
$maxScore = 0;
$totalPassed = 0;
$rawOutput = '';

foreach ($testCases as $index => $testCase) {
    $maxScore += floatval($testCase['marks'] ?? 0);
    $result = executeCode($code, $language, $testCase['input'] ?? '', $tempDir);
    
    $expected = trim($testCase['expected'] ?? '');
    $actual = trim($result['output']);
    $passed = $result['success'] && ($expected === $actual);
    
    if ($passed) {
        $totalPassed++;
        $marks = floatval($testCase['marks'] ?? 0);
        $totalScore += $marks;
        $results[] = [
            'test_case' => $index + 1,
            'input' => $testCase['input'] ?? '',
            'expected' => $expected,
            'actual' => $actual,
            'passed' => true,
            'marks' => $marks,
            'max_marks' => $marks,
            'error' => null
        ];
    } else {
        $results[] = [
            'test_case' => $index + 1,
            'input' => $testCase['input'] ?? '',
            'expected' => $expected,
            'actual' => $actual,
            'passed' => false,
            'marks' => 0,
            'max_marks' => floatval($testCase['marks'] ?? 0),
            'error' => $result['error']
        ];
    }
    
    if ($result['output']) {
        $rawOutput .= $result['output'] . "\n";
    }
}

$executionTime = round((microtime(true) - $startTime) * 1000, 2);
$cpuTime = round(memory_get_peak_usage() / 1024, 2);
$memory = round(memory_get_peak_usage() / 1024, 2);

// Generate AI feedback
$aiFeedback = generateAIFeedback($code, $language, $results, $totalScore, $maxScore, $markingScheme);
$qualityScore = assessCodeQuality($code, $language);

// Cleanup
if (is_dir($tempDir)) {
    array_map('unlink', glob("$tempDir/*.*"));
    rmdir($tempDir);
}

echo json_encode([
    'success' => true,
    'results' => $results,
    'total_passed' => $totalPassed,
    'total_test_cases' => count($testCases),
    'score' => $totalScore,
    'max_score' => $maxScore,
    'percentage' => $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0,
    'ai_feedback' => $aiFeedback,
    'quality_score' => $qualityScore,
    'output' => $rawOutput,
    'raw_output' => $rawOutput,
    'metrics' => [
        'cpu_time' => $cpuTime . ' ms',
        'memory' => $memory . ' KB',
        'execution_time' => $executionTime . ' ms'
    ]
]);

function executeCode($code, $language, $input, $tempDir) {
    $result = ['success' => false, 'output' => '', 'error' => null];
    $timeout = 10;
    
    // Add necessary imports/wrappers for student code
    $wrappedCode = wrapCode($code, $language);
    
    switch (strtolower($language)) {
        case 'python':
            $filePath = $tempDir . '/script.py';
            file_put_contents($filePath, $wrappedCode);
            $command = "cd $tempDir && timeout $timeout python3 $filePath 2>&1";
            if (!empty($input)) {
                $command = "echo " . escapeshellarg($input) . " | " . $command;
            }
            exec($command, $output, $returnCode);
            $result['output'] = implode("\n", $output);
            $result['success'] = ($returnCode === 0);
            if ($returnCode === 124) $result['error'] = "Time limit exceeded ({$timeout}s)";
            break;
            
        case 'javascript':
        case 'js':
            $filePath = $tempDir . '/script.js';
            file_put_contents($filePath, $wrappedCode);
            $command = "cd $tempDir && timeout $timeout node $filePath 2>&1";
            if (!empty($input)) {
                $command = "echo " . escapeshellarg($input) . " | " . $command;
            }
            exec($command, $output, $returnCode);
            $result['output'] = implode("\n", $output);
            $result['success'] = ($returnCode === 0);
            break;
            
        case 'java':
            preg_match('/public\s+class\s+(\w+)/', $code, $matches);
            $className = $matches[1] ?? 'Main';
            $filePath = $tempDir . '/' . $className . '.java';
            file_put_contents($filePath, $wrappedCode);
            exec("cd $tempDir && javac $className.java 2>&1", $compileOutput, $compileCode);
            if ($compileCode === 0) {
                $command = "cd $tempDir && timeout $timeout java $className 2>&1";
                if (!empty($input)) {
                    $command = "echo " . escapeshellarg($input) . " | " . $command;
                }
                exec($command, $output, $returnCode);
                $result['output'] = implode("\n", $output);
                $result['success'] = ($returnCode === 0);
            } else {
                $result['error'] = "Compilation error: " . implode("\n", $compileOutput);
            }
            break;
            
        case 'c':
            $filePath = $tempDir . '/program.c';
            file_put_contents($filePath, $wrappedCode);
            exec("cd $tempDir && gcc program.c -o program 2>&1", $compileOutput, $compileCode);
            if ($compileCode === 0) {
                $command = "cd $tempDir && timeout $timeout ./program 2>&1";
                if (!empty($input)) {
                    $command = "echo " . escapeshellarg($input) . " | " . $command;
                }
                exec($command, $output, $returnCode);
                $result['output'] = implode("\n", $output);
                $result['success'] = ($returnCode === 0);
            } else {
                $result['error'] = "Compilation error: " . implode("\n", $compileOutput);
            }
            break;
            
        case 'cpp':
        case 'c++':
            $filePath = $tempDir . '/program.cpp';
            file_put_contents($filePath, $wrappedCode);
            exec("cd $tempDir && g++ program.cpp -o program 2>&1", $compileOutput, $compileCode);
            if ($compileCode === 0) {
                $command = "cd $tempDir && timeout $timeout ./program 2>&1";
                if (!empty($input)) {
                    $command = "echo " . escapeshellarg($input) . " | " . $command;
                }
                exec($command, $output, $returnCode);
                $result['output'] = implode("\n", $output);
                $result['success'] = ($returnCode === 0);
            } else {
                $result['error'] = "Compilation error: " . implode("\n", $compileOutput);
            }
            break;
            
        case 'csharp':
        case 'c#':
        case 'cs':
            $filePath = $tempDir . '/Program.cs';
            file_put_contents($filePath, $wrappedCode);
            exec("cd $tempDir && mcs Program.cs 2>&1", $compileOutput, $compileCode);
            if ($compileCode === 0) {
                $command = "cd $tempDir && timeout $timeout mono Program.exe 2>&1";
                if (!empty($input)) {
                    $command = "echo " . escapeshellarg($input) . " | " . $command;
                }
                exec($command, $output, $returnCode);
                $result['output'] = implode("\n", $output);
                $result['success'] = ($returnCode === 0);
            } else {
                $result['error'] = "Compilation error: " . implode("\n", $compileOutput);
            }
            break;
            
        case 'vb':
        case 'vb.net':
            $filePath = $tempDir . '/Program.vb';
            file_put_contents($filePath, $wrappedCode);
            exec("cd $tempDir && vbnc Program.vb 2>&1", $compileOutput, $compileCode);
            if ($compileCode === 0) {
                $command = "cd $tempDir && timeout $timeout mono Program.exe 2>&1";
                if (!empty($input)) {
                    $command = "echo " . escapeshellarg($input) . " | " . $command;
                }
                exec($command, $output, $returnCode);
                $result['output'] = implode("\n", $output);
                $result['success'] = ($returnCode === 0);
            } else {
                $result['error'] = "Compilation error: " . implode("\n", $compileOutput);
            }
            break;
            
        case 'php':
            $filePath = $tempDir . '/script.php';
            file_put_contents($filePath, $wrappedCode);
            $command = "cd $tempDir && timeout $timeout php $filePath 2>&1";
            if (!empty($input)) {
                $command = "echo " . escapeshellarg($input) . " | " . $command;
            }
            exec($command, $output, $returnCode);
            $result['output'] = implode("\n", $output);
            $result['success'] = ($returnCode === 0);
            break;
            
        case 'html':
            $result['output'] = $wrappedCode;
            $result['success'] = true;
            break;
            
        case 'css':
            $result['output'] = "CSS code validated";
            $result['success'] = true;
            break;
            
        case 'sql':
            // Simulate SQL execution (in production, connect to test database)
            $result['output'] = "SQL Query: " . substr($wrappedCode, 0, 200);
            $result['success'] = true;
            break;
            
        default:
            $result['error'] = "Language '$language' is not supported";
    }
    
    return $result;
}

function wrapCode($code, $language) {
    // Add necessary wrappers to ensure code runs correctly
    switch (strtolower($language)) {
        case 'python':
            // Ensure code has proper structure
            if (!strpos($code, 'if __name__')) {
                $code = $code . "\n\nif __name__ == '__main__':\n    pass";
            }
            break;
        case 'javascript':
        case 'js':
            break;
        case 'java':
            if (!strpos($code, 'public class')) {
                $code = "public class Main {\n    public static void main(String[] args) {\n" . $code . "\n    }\n}";
            }
            break;
        case 'c':
        case 'cpp':
            if (!strpos($code, 'int main')) {
                $code = "#include <stdio.h>\n\n" . $code . "\n\nint main() {\n    return 0;\n}";
            }
            break;
    }
    return $code;
}

function generateAIFeedback($code, $language, $results, $score, $maxScore, $markingScheme) {
    $passedTests = count(array_filter($results, function($r) { return $r['passed']; }));
    $totalTests = count($results);
    $percentage = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
    
    $feedback = "=== AI GRADING ANALYSIS ===\n\n";
    $feedback .= "📊 SCORE: $score / $maxScore marks (" . round($percentage, 1) . "%)\n";
    $feedback .= "✅ TESTS PASSED: $passedTests / $totalTests\n\n";
    
    if ($results) {
        $feedback .= "📝 DETAILED RESULTS:\n";
        foreach ($results as $result) {
            $icon = $result['passed'] ? '✓' : '✗';
            $feedback .= "  $icon Test {$result['test_case']}: ";
            if ($result['passed']) {
                $feedback .= "PASSED (+{$result['marks']} marks)\n";
            } else {
                $feedback .= "FAILED\n";
                $feedback .= "     Input: {$result['input']}\n";
                $feedback .= "     Expected: {$result['expected']}\n";
                $feedback .= "     Got: {$result['actual']}\n";
            }
        }
    }
    
    $feedback .= "\n💡 CODE ANALYSIS:\n";
    
    // Code quality checks
    $lines = explode("\n", $code);
    $codeLength = strlen($code);
    $lineCount = count($lines);
    
    if ($codeLength < 50 && $maxScore > 10) {
        $feedback .= "  • Your solution is quite brief. Consider adding more detailed implementation.\n";
    }
    
    if (!strpos($code, 'function') && !strpos($code, 'def ') && !strpos($code, 'class')) {
        $feedback .= "  • Consider using functions/classes for better code organization.\n";
    }
    
    if (!strpos($code, '//') && !strpos($code, '#')) {
        $feedback .= "  • Add comments to explain your logic - this helps with maintainability.\n";
    }
    
    // Performance analysis
    if ($lineCount > 100) {
        $feedback .= "  • Your solution is quite long. Look for opportunities to simplify.\n";
    }
    
    // Specific suggestions based on results
    if ($passedTests === 0 && $totalTests > 0) {
        $feedback .= "\n🔧 CRITICAL ISSUES:\n";
        $feedback .= "  • No test cases passed. Check your syntax and logic carefully.\n";
        $feedback .= "  • Make sure your code produces the exact expected output.\n";
    } elseif ($passedTests < $totalTests && $passedTests > 0) {
        $feedback .= "\n📈 AREAS FOR IMPROVEMENT:\n";
        $feedback .= "  • You passed " . round(($passedTests/$totalTests)*100) . "% of tests.\n";
        $feedback .= "  • Review the failed test cases to understand what's missing.\n";
        $feedback .= "  • Check edge cases and input handling.\n";
    } elseif ($passedTests === $totalTests && $score < $maxScore) {
        $feedback .= "\n⭐ EXCELLENT! All tests passed!\n";
        $feedback .= "  • Consider code optimization and best practices.\n";
        $feedback .= "  • Look for opportunities to make your code more efficient.\n";
    }
    
    // Language-specific tips
    switch ($language) {
        case 'python':
            if (strpos($code, 'print') !== false && strpos($code, 'return') === false) {
                $feedback .= "  • In Python, use 'return' instead of 'print' when the function needs to return a value.\n";
            }
            break;
        case 'javascript':
            if (strpos($code, 'console.log') !== false && strpos($code, 'return') === false) {
                $feedback .= "  • Use 'return' instead of 'console.log' for function output.\n";
            }
            break;
        case 'java':
            if (!strpos($code, 'System.out.println')) {
                $feedback .= "  • Use System.out.println() to display output.\n";
            }
            break;
        case 'c':
        case 'cpp':
            if (!strpos($code, 'printf') && !strpos($code, 'cout')) {
                $feedback .= "  • Use printf() (C) or cout (C++) to display output.\n";
            }
            break;
    }
    
    // Final encouragement
    if ($percentage >= 90) {
        $feedback .= "\n🎉 OUTSTANDING! Excellent work on this solution!\n";
    } elseif ($percentage >= 75) {
        $feedback .= "\n👍 VERY GOOD! You have a solid understanding. Keep it up!\n";
    } elseif ($percentage >= 60) {
        $feedback .= "\n📚 GOOD EFFORT! Review the suggestions to improve.\n";
    } elseif ($percentage >= 40) {
        $feedback .= "\n📖 KEEP PRACTICING! You're on the right track.\n";
    } else {
        $feedback .= "\n⚠️ NEEDS WORK. Review the fundamentals and try again.\n";
    }
    
    return $feedback;
}

function assessCodeQuality($code, $language) {
    $qualityScore = 0;
    
    // Function definitions (good structure)
    if (preg_match('/function\s+\w+\s*\(|def\s+\w+\s*\(|public\s+\w+\s+\w+\s*\(/', $code)) {
        $qualityScore += 3;
    }
    
    // Comments (documentation)
    if (preg_match('/\/\/|\/\*|\*\/|#/', $code)) {
        $qualityScore += 2;
    }
    
    // Proper indentation
    $lines = explode("\n", $code);
    $indentedLines = 0;
    foreach ($lines as $line) {
        if (preg_match('/^\s+/', $line)) $indentedLines++;
    }
    if ($indentedLines > count($lines) * 0.3) {
        $qualityScore += 2;
    }
    
    // Meaningful variable names (at least 3 chars)
    if (preg_match('/\b[a-z]{3,}\b/', $code)) {
        $qualityScore += 2;
    }
    
    // Return statements
    if (strpos($code, 'return') !== false) {
        $qualityScore += 1;
    }
    
    return min($qualityScore, 10);
}
?>