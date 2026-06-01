<?php
// backend-php/helpers/grading.php
// Auto-grading and marking functions

function autoGradeSubmission($attemptId, $pdo) {
    // Get all answers for this attempt
    $stmt = $pdo->prepare("
        SELECT ea.*, qg.marks_allocated, qg.grading_rules, qg.auto_gradable, qg.keywords
        FROM exam_answers ea
        JOIN question_grading_criteria qg ON ea.question_id = qg.question_id AND qg.exam_id = ea.exam_id
        WHERE ea.attempt_id = ?
    ");
    $stmt->execute([$attemptId]);
    $answers = $stmt->fetchAll();
    
    $totalScore = 0;
    $totalMarks = 0;
    
    foreach ($answers as $answer) {
        $score = 0;
        $totalMarks += $answer['marks_allocated'];
        
        if ($answer['auto_gradable']) {
            $rules = json_decode($answer['grading_rules'], true);
            
            switch ($rules['type']) {
                case 'mcq':
                    $score = ($answer['answer_options'] == $rules['correct_answer']) ? $answer['marks_allocated'] : 0;
                    break;
                    
                case 'short_answer':
                    $keywords = explode(',', strtolower($answer['keywords']));
                    $userAnswer = strtolower($answer['answer_text']);
                    $matches = 0;
                    foreach ($keywords as $keyword) {
                        if (strpos($userAnswer, trim($keyword)) !== false) {
                            $matches++;
                        }
                    }
                    $score = ($matches / count($keywords)) * $answer['marks_allocated'];
                    break;
                    
                case 'coding':
                    $testCases = $rules['test_cases'];
                    $passedTests = 0;
                    // Execute code and check against expected output
                    if (!empty($testCases)) {
                        foreach ($testCases as $test) {
                            // Run code against test case
                            $output = executeCode($answer['code_answer'], $test['input'], $rules['language']);
                            if (trim($output) == trim($test['expected'])) {
                                $passedTests++;
                            }
                        }
                        $score = ($passedTests / count($testCases)) * $answer['marks_allocated'];
                    }
                    break;
                    
                case 'essay':
                    $score = 0; // Manual grading required
                    break;
            }
            
            // Update auto score
            $stmt2 = $pdo->prepare("UPDATE exam_answers SET auto_score = ? WHERE id = ?");
            $stmt2->execute([$score, $answer['id']]);
        }
        
        $totalScore += $score;
    }
    
    // Calculate percentage
    $percentage = $totalMarks > 0 ? ($totalScore / $totalMarks) * 100 : 0;
    
    // Update submission total
    $stmt = $pdo->prepare("
        UPDATE submissions 
        SET auto_score = ?, total_score = ?, percentage = ?, status = 'AUTO_GRADED' 
        WHERE attempt_id = ?
    ");
    $stmt->execute([$totalScore, $totalScore, $percentage, $attemptId]);
    
    return ['score' => $totalScore, 'total' => $totalMarks, 'percentage' => $percentage];
}

// Helper function to execute code safely
function executeCode($code, $input, $language) {
    // This is a simplified version - in production, use Docker or sandbox
    $tempFile = tempnam(sys_get_temp_dir(), 'code_');
    
    switch ($language) {
        case 'python':
            file_put_contents($tempFile . '.py', $code);
            $output = shell_exec("python " . $tempFile . ".py 2>&1 <<< '$input'");
            break;
        case 'javascript':
            file_put_contents($tempFile . '.js', $code);
            $output = shell_exec("node " . $tempFile . ".js 2>&1 <<< '$input'");
            break;
        default:
            $output = '';
    }
    
    unlink($tempFile . '.' . ($language == 'python' ? 'py' : 'js'));
    return $output;
}

// Calculate PU Grade based on score
function calculatePUGrade($percentage) {
    if ($percentage >= 80) return ['grade' => 'A', 'grade_point' => 4.0, 'remark' => 'Excellent'];
    if ($percentage >= 75) return ['grade' => 'B+', 'grade_point' => 3.5, 'remark' => 'Very Good'];
    if ($percentage >= 70) return ['grade' => 'B', 'grade_point' => 3.0, 'remark' => 'Good'];
    if ($percentage >= 65) return ['grade' => 'C+', 'grade_point' => 2.5, 'remark' => 'Average'];
    if ($percentage >= 60) return ['grade' => 'C', 'grade_point' => 2.0, 'remark' => 'Fair'];
    if ($percentage >= 55) return ['grade' => 'D+', 'grade_point' => 1.5, 'remark' => 'Barely Satisfactory'];
    if ($percentage >= 50) return ['grade' => 'D', 'grade_point' => 1.0, 'remark' => 'Weak Pass'];
    return ['grade' => 'E', 'grade_point' => 0.0, 'remark' => 'Fail'];
}
?>