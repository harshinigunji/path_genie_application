<?php
// chatbot_local.php - Retrieval-Based Chatbot (No LLM Required)
// v2.0 - Improved keyword matching with better priority handling
// Uses keyword matching against local MySQL knowledge base

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

include("config/db.php");

// 1. Receive Input
$input = json_decode(file_get_contents("php://input"), true);
$message = trim($input['message'] ?? '');

// 2. Validate Input
if (empty($message)) {
    echo json_encode(["status" => true, "reply" => "Please ask me something about education, careers, or exams."]);
    exit;
}

// 3. Extract keywords from user message
$keywords = extractKeywords($message);
$importantKeywords = extractImportantKeywords($message);

// 4. Search Knowledge Base
$reply = searchKnowledgeBase($conn, $keywords, $importantKeywords, $message);

// 5. Return Response
echo json_encode(["status" => true, "reply" => $reply]);

/**
 * Extract meaningful keywords from user message
 */
function extractKeywords($message) {
    // Convert to lowercase
    $message = strtolower($message);
    
    // Remove common stop words
    $stopWords = ['what', 'is', 'the', 'a', 'an', 'are', 'how', 'to', 'can', 'i', 'do', 
                  'you', 'tell', 'me', 'about', 'please', 'help', 'want', 'know', 'need',
                  'which', 'where', 'when', 'why', 'who', 'should', 'would', 'could',
                  'will', 'be', 'for', 'of', 'in', 'on', 'at', 'and', 'or', 'but', 'with',
                  'this', 'that', 'there', 'here', 'have', 'has', 'had', 'get', 'give',
                  'my', 'your', 'our', 'their', 'its', 'class', 'standard'];
    
    // Remove punctuation and split into words
    $message = preg_replace('/[^\w\s]/', '', $message);
    $words = explode(' ', $message);
    
    // Filter out stop words (but keep short important words like "10th", "12th")
    $keywords = array_filter($words, function($word) use ($stopWords) {
        // Keep numeric patterns like 10th, 12th, 10, 12
        if (preg_match('/^\d+/', $word)) {
            return true;
        }
        return strlen($word) > 2 && !in_array($word, $stopWords);
    });
    
    return array_values($keywords);
}

/**
 * Extract high-priority keywords that must be matched first
 * These are critical differentiators like "10th" vs "12th"
 */
function extractImportantKeywords($message) {
    $message = strtolower($message);
    $importantPatterns = [
        '10th' => ['10th', '10', 'tenth', 'sslc', 'class 10', '10th class'],
        '12th' => ['12th', '12', 'twelfth', 'class 12', '12th class', 'hsc', 'intermediate'],
        'btech' => ['btech', 'b.tech', 'b tech', 'engineering degree', 'be degree'],
        'mbbs' => ['mbbs', 'medical', 'doctor'],
        'bcom' => ['bcom', 'b.com', 'b com', 'commerce degree'],
        'ba' => ['ba degree', 'arts degree'],
        'science' => ['science', 'pcm', 'pcb', 'physics', 'chemistry'],
        'commerce' => ['commerce', 'accounts', 'accountancy'],
        'arts' => ['arts', 'humanities'],
        'jee' => ['jee', 'iit'],
        'neet' => ['neet'],
        'cat' => ['cat exam', 'cat mba'],
        'upsc' => ['upsc', 'ias', 'ips', 'civil services']
    ];
    
    $found = [];
    foreach ($importantPatterns as $key => $patterns) {
        foreach ($patterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                $found[] = $key;
                break;
            }
        }
    }
    
    return $found;
}

/**
 * Search knowledge base using keyword matching with priority for important keywords
 */
function searchKnowledgeBase($conn, $keywords, $importantKeywords, $originalMessage) {
    if (empty($keywords)) {
        $keywords = explode(' ', strtolower($originalMessage));
    }
    
    // Build search conditions
    $searchConditions = [];
    $scoreCalculation = [];
    
    // Regular keywords get score of 1
    foreach ($keywords as $keyword) {
        $keyword = mysqli_real_escape_string($conn, $keyword);
        if (strlen($keyword) > 1) {
            $searchConditions[] = "keywords LIKE '%$keyword%'";
            $scoreCalculation[] = "(CASE WHEN keywords LIKE '%$keyword%' THEN 1 ELSE 0 END)";
        }
    }
    
    // Important keywords get score of 5 (higher weight)
    foreach ($importantKeywords as $impKeyword) {
        $impKeyword = mysqli_real_escape_string($conn, $impKeyword);
        $scoreCalculation[] = "(CASE WHEN keywords LIKE '%$impKeyword%' THEN 5 ELSE 0 END)";
        $searchConditions[] = "keywords LIKE '%$impKeyword%'";
    }
    
    if (empty($searchConditions)) {
        return getFallbackResponse($conn, $originalMessage, $keywords);
    }
    
    $originalEscaped = mysqli_real_escape_string($conn, strtolower($originalMessage));
    
    // Build the query with weighted scoring
    $scoreExpr = implode(" + ", $scoreCalculation);
    if (empty($scoreExpr)) {
        $scoreExpr = "0";
    }
    
    $query = "
        SELECT 
            kb_id,
            category,
            keywords,
            question,
            answer,
            priority,
            ($scoreExpr) as match_score
        FROM chatbot_knowledge_base
        WHERE (" . implode(" OR ", $searchConditions) . ")
           OR question LIKE '%$originalEscaped%'
        ORDER BY match_score DESC, priority DESC
        LIMIT 1
    ";
    
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['answer'];
    }
    
    // Fallback
    return getFallbackResponse($conn, $originalMessage, $keywords);
}

/**
 * Fallback response when no exact match found
 */
function getFallbackResponse($conn, $message, $keywords) {
    $message = strtolower($message);
    
    // Check for specific patterns first
    if (strpos($message, '10th') !== false || strpos($message, 'tenth') !== false || strpos($message, '10') !== false) {
        // Look specifically for 10th content
        $query = "SELECT answer FROM chatbot_knowledge_base 
                  WHERE keywords LIKE '%10th%' OR keywords LIKE '%tenth%'
                  ORDER BY priority DESC LIMIT 1";
        $result = mysqli_query($conn, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return $row['answer'];
        }
    }
    
    // Topic mappings for other fallbacks
    $topicMappings = [
        'stream' => 'streams',
        'career' => 'careers', 
        'job' => 'jobs',
        'exam' => 'exams',
        'entrance' => 'exams',
        'become' => 'careers',
        'engineer' => 'careers',
        'doctor' => 'careers',
        'salary' => 'jobs',
        'jee' => 'exams',
        'neet' => 'exams',
        'cat' => 'exams',
        'upsc' => 'exams',
        'btech' => 'jobs',
        'mbbs' => 'careers',
        'lawyer' => 'careers',
        'pilot' => 'careers',
        'ca' => 'careers',
        'scholarship' => 'general',
        'abroad' => 'general',
        'hello' => 'general',
        'hi' => 'general',
        'confused' => 'general'
    ];
    
    $detectedCategory = null;
    foreach ($topicMappings as $keyword => $category) {
        if (strpos($message, $keyword) !== false) {
            $detectedCategory = $category;
            break;
        }
    }
    
    if ($detectedCategory) {
        $query = "SELECT answer FROM chatbot_knowledge_base 
                  WHERE category = '$detectedCategory' 
                  ORDER BY priority DESC 
                  LIMIT 1";
        $result = mysqli_query($conn, $query);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return $row['answer'];
        }
    }
    
    // Generic fallback
    return "I'm not sure about that specific topic. I can help you with:\n\n" .
           "• **Streams** - Options after 10th, 12th (Science/Commerce/Arts)\n" .
           "• **Exams** - JEE, NEET, CAT, UPSC and other entrance exams\n" .
           "• **Jobs** - Career opportunities after various degrees\n" .
           "• **Career Guidance** - How to become a doctor, engineer, lawyer, etc.\n\n" .
           "Try asking something like:\n" .
           "- \"Streams after 10th\"\n" .
           "- \"Options after 12th Science\"\n" .
           "- \"How to become a doctor\"\n" .
           "- \"Jobs after B.Tech\"";
}
?>
