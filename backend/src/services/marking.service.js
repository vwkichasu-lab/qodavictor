import prisma from '../utils/db.js';

export class MarkingService {
  /**
   * Auto-grade a single answer based on question type
   */
  static async gradeAnswer(answerId) {
    const answer = await prisma.answer.findUnique({
      where: { id: answerId },
      include: {
        question: true,
        submission: true,
      },
    });

    if (!answer) {
      throw new Error('Answer not found');
    }

    const { question } = answer;
    let result = {
      score: 0,
      maxScore: question.marks,
      autoGraded: true,
      needsManualReview: false,
      feedback: '',
    };

    switch (question.type) {
      case 'MCQ':
        result = this.gradeMCQ(answer, question);
        break;
      case 'TRUE_FALSE':
        result = this.gradeTrueFalse(answer, question);
        break;
      case 'SHORT_ANSWER':
        result = this.gradeShortAnswer(answer, question);
        break;
      case 'FILL_BLANK':
        result = this.gradeFillBlank(answer, question);
        break;
      case 'MATCHING':
        result = this.gradeMatching(answer, question);
        break;
      case 'CODING':
        result = this.gradeCoding(answer, question);
        break;
      case 'ESSAY':
        result = this.gradeEssay(answer, question);
        break;
      default:
        result.needsManualReview = true;
        result.feedback = 'Unknown question type - requires manual grading';
    }

    // Update the answer with the grade
    await prisma.answer.update({
      where: { id: answerId },
      data: {
        score: result.score,
        maxScore: result.maxScore,
        autoGraded: result.autoGraded,
        needsManualReview: result.needsManualReview,
        feedback: result.feedback,
      },
    });

    return result;
  }

  /**
   * Grade Multiple Choice Question
   */
  static gradeMCQ(answer, question) {
    const studentAnswer = answer.answerData?.selectedOption;
    const options = question.options || [];
    
    // Find the correct option
    const correctOption = options.find(opt => opt.isCorrect);
    const isCorrect = studentAnswer === correctOption?.id || 
                      studentAnswer === correctOption?.letter ||
                      options.findIndex(opt => opt.isCorrect) === parseInt(studentAnswer);

    return {
      score: isCorrect ? question.marks : 0,
      maxScore: question.marks,
      autoGraded: true,
      needsManualReview: false,
      feedback: isCorrect ? 'Correct answer' : 'Incorrect answer',
    };
  }

  /**
   * Grade True/False Question
   */
  static gradeTrueFalse(answer, question) {
    const studentAnswer = answer.answerData?.answer?.toLowerCase();
    const correctAnswer = question.correctAnswer?.value?.toLowerCase();
    
    const isCorrect = studentAnswer === correctAnswer ||
                      (studentAnswer === 'true' && correctAnswer === true) ||
                      (studentAnswer === 'false' && correctAnswer === false);

    return {
      score: isCorrect ? question.marks : 0,
      maxScore: question.marks,
      autoGraded: true,
      needsManualReview: false,
      feedback: isCorrect ? 'Correct' : 'Incorrect',
    };
  }

  /**
   * Grade Short Answer with keyword matching
   */
  static gradeShortAnswer(answer, question) {
    const studentAnswer = (answer.answerText || '').toLowerCase().trim();
    const correctAnswer = (question.correctAnswer?.text || '').toLowerCase().trim();
    const keywords = (question.keywords || '').split(',').map(k => k.trim().toLowerCase()).filter(Boolean);
    
    let score = 0;
    let feedback = '';

    // Exact match gets full marks
    if (studentAnswer === correctAnswer) {
      score = question.marks;
      feedback = 'Perfect match';
    } else if (keywords.length > 0) {
      // Check for keyword matches
      const matchedKeywords = keywords.filter(keyword => studentAnswer.includes(keyword));
      const matchRatio = matchedKeywords.length / keywords.length;
      
      // Award partial credit based on keyword matches
      score = Math.round(matchRatio * question.marks);
      
      if (matchRatio >= 0.8) {
        feedback = 'Excellent answer with most key points';
      } else if (matchRatio >= 0.5) {
        feedback = 'Good answer with some key points';
      } else if (matchRatio > 0) {
        feedback = 'Partial answer with few key points';
      } else {
        feedback = 'Answer does not match expected keywords';
      }
    } else {
      // No keywords defined - needs manual review
      return {
        score: 0,
        maxScore: question.marks,
        autoGraded: false,
        needsManualReview: true,
        feedback: 'No auto-grading criteria defined - requires manual review',
      };
    }

    return {
      score,
      maxScore: question.marks,
      autoGraded: true,
      needsManualReview: score < question.marks * 0.5, // Review if less than 50%
      feedback,
    };
  }

  /**
   * Grade Fill in the Blank
   */
  static gradeFillBlank(answer, question) {
    const blanks = answer.answerData?.blanks || [];
    const correctAnswers = question.correctAnswer?.blanks || [];
    
    let totalScore = 0;
    const blankResults = [];

    blanks.forEach((blank, index) => {
      const correct = correctAnswers[index];
      const studentValue = (blank.value || '').toLowerCase().trim();
      const correctValue = (correct?.value || '').toLowerCase().trim();
      
      // Allow tolerance for numeric answers
      const isNumeric = !isNaN(studentValue) && !isNaN(correctValue);
      const tolerance = correct?.tolerance || 0;
      
      let isCorrect = false;
      if (isNumeric && tolerance > 0) {
        const diff = Math.abs(parseFloat(studentValue) - parseFloat(correctValue));
        isCorrect = diff <= tolerance;
      } else {
        isCorrect = studentValue === correctValue;
      }

      const blankScore = isCorrect ? (correct?.marks || question.marks / blanks.length) : 0;
      totalScore += blankScore;
      
      blankResults.push({
        blankIndex: index,
        isCorrect,
        score: blankScore,
      });
    });

    return {
      score: Math.round(totalScore),
      maxScore: question.marks,
      autoGraded: true,
      needsManualReview: false,
      feedback: `Correct blanks: ${blankResults.filter(r => r.isCorrect).length}/${blanks.length}`,
    };
  }

  /**
   * Grade Matching Question
   */
  static gradeMatching(answer, question) {
    const pairs = answer.answerData?.pairs || [];
    const correctPairs = question.correctAnswer?.pairs || [];
    
    let correctCount = 0;
    
    pairs.forEach(pair => {
      const correct = correctPairs.find(cp => cp.left === pair.left);
      if (correct && correct.right === pair.right) {
        correctCount++;
      }
    });

    const score = Math.round((correctCount / correctPairs.length) * question.marks);

    return {
      score,
      maxScore: question.marks,
      autoGraded: true,
      needsManualReview: false,
      feedback: `Correct matches: ${correctCount}/${correctPairs.length}`,
    };
  }

  /**
   * Grade Coding Question (basic syntax check + effort points)
   */
  static gradeCoding(answer, question) {
    const code = answer.answerData?.code || '';
    const files = answer.answerData?.files || {};
    
    // Basic checks for coding effort
    const hasCode = code.length > 50;
    const hasMultipleFiles = Object.keys(files).length > 0;
    const hasComments = code.includes('//') || code.includes('/*') || code.includes('#');
    const hasFunctions = /function|def|void|int|public|class/i.test(code);
    
    // Award partial credit for effort
    let effortScore = 0;
    if (hasCode) effortScore += question.marks * 0.3;
    if (hasMultipleFiles) effortScore += question.marks * 0.1;
    if (hasComments) effortScore += question.marks * 0.1;
    if (hasFunctions) effortScore += question.marks * 0.1;

    return {
      score: Math.round(effortScore),
      maxScore: question.marks,
      autoGraded: true,
      needsManualReview: true, // Coding always needs manual review for accuracy
      feedback: 'Auto-graded for effort. Manual review required for accuracy.',
    };
  }

  /**
   * Grade Essay (always requires manual review)
   */
  static gradeEssay(answer, question) {
    const wordCount = (answer.answerText || '').split(/\s+/).length;
    
    return {
      score: 0,
      maxScore: question.marks,
      autoGraded: false,
      needsManualReview: true,
      feedback: `Essay submitted (${wordCount} words). Requires manual grading using rubric.`,
    };
  }

  /**
   * Auto-grade all answers in a submission
   */
  static async gradeSubmission(submissionId) {
    const answers = await prisma.answer.findMany({
      where: { submissionId },
      include: { question: true },
    });

    const results = [];
    for (const answer of answers) {
      const result = await this.gradeAnswer(answer.id);
      results.push({
        answerId: answer.id,
        questionId: answer.questionId,
        ...result,
      });
    }

    // Calculate total score
    const totalScore = results.reduce((sum, r) => sum + r.score, 0);
    const maxScore = results.reduce((sum, r) => sum + r.maxScore, 0);
    const needsManualReview = results.some(r => r.needsManualReview);

    // Update submission status
    await prisma.submission.update({
      where: { id: submissionId },
      data: {
        status: needsManualReview ? 'GRADING' : 'GRADED',
      },
    });

    return {
      submissionId,
      totalScore,
      maxScore,
      percentage: maxScore > 0 ? (totalScore / maxScore) * 100 : 0,
      needsManualReview,
      results,
    };
  }

  /**
   * Create or update marking scheme for a question
   */
  static async createMarkingScheme(questionId, rules) {
    const question = await prisma.question.findUnique({
      where: { id: questionId },
    });

    if (!question) {
      throw new Error('Question not found');
    }

    const scheme = await prisma.markingScheme.upsert({
      where: {
        questionId: questionId,
      },
      update: {
        rules,
      },
      create: {
        examId: question.examId,
        questionId: questionId,
        rules,
      },
    });

    return scheme;
  }
}

export default MarkingService;
