<?php
require_once "config/db.php";

header("Content-Type: application/json");

/* ================= CONSTANTS ================= */

define("OPT_STREAM", 1);
define("OPT_EXAM", 2);
define("OPT_JOB", 3);

define("JOB_GOVT", 0);
define("JOB_PRIVATE", 1);

/* ================= INTEREST AREA MAPPING ================= */

/**
 * Maps interest areas to related stream keywords for matching
 */
function getInterestKeywords($interestArea) {
    switch ($interestArea) {
        case "Technology":
            return ["engineering", "software", "technology", "computer", "bca", "b.tech", "it", "data", "science (pcm)"];
        case "Science":
            return ["science", "research", "medical", "mbbs", "pharmacy", "b.sc", "biology", "chemistry", "physics", "pcm", "pcb"];
        case "Commerce":
            return ["commerce", "business", "finance", "accounting", "b.com", "bba", "ca", "cma", "mba", "banking"];
        case "Law":
            return ["law", "legal", "llb", "judiciary", "advocate"];
        case "Arts":
            return ["arts", "humanities", "literature", "history", "sociology", "communication", "media", "bfa", "design"];
        default:
            return [];
    }
}

/**
 * Check if stream matches user's interest area
 */
function matchesInterest($streamData, $interestArea) {
    $keywords = getInterestKeywords($interestArea);
    if (empty($keywords)) return 50; // Neutral if no preference
    
    $matchScore = 0;
    $searchText = strtolower(
        ($streamData['stream_name'] ?? '') . ' ' .
        ($streamData['who_should_choose'] ?? '') . ' ' .
        ($streamData['career_scope'] ?? '') . ' ' .
        ($streamData['description'] ?? '')
    );
    
    foreach ($keywords as $keyword) {
        if (strpos($searchText, strtolower($keyword)) !== false) {
            $matchScore += 15;
        }
    }
    
    return min($matchScore, 35); // Cap at 35 points for interest match
}

/* ================= DIFFICULTY MATCHING ================= */

function mapDifficultyLevel($level) {
    $level = strtolower(trim($level ?? 'medium'));
    return match ($level) {
        "easy" => 1,
        "medium" => 2,
        "hard" => 3,
        default => 2
    };
}

/**
 * Score based on difficulty tolerance vs stream difficulty
 */
function scoreDifficultyMatch($streamDifficulty, $userTolerance) {
    $streamLevel = mapDifficultyLevel($streamDifficulty);
    
    // Perfect match
    if ($streamLevel == $userTolerance) {
        return 15;
    }
    
    // Close match (one level difference)
    if (abs($streamLevel - $userTolerance) == 1) {
        return 8;
    }
    
    // Mismatch (two levels difference)
    return 0;
}

/* ================= CAREER PREFERENCE MATCHING ================= */

/**
 * Check job type distribution for a stream from stream_jobs table
 */
function getStreamJobTypes($conn, $streamId) {
    $q = $conn->prepare("
        SELECT j.job_type, sj.eligibility_strength
        FROM stream_jobs sj
        JOIN jobs j ON j.job_id = sj.job_id
        WHERE sj.stream_id = ?
    ");
    $q->bind_param("i", $streamId);
    $q->execute();
    $r = $q->get_result();
    
    $govtCount = 0;
    $privateCount = 0;
    
    while ($row = $r->fetch_assoc()) {
        $weight = ($row['eligibility_strength'] == 'PRIMARY') ? 2 : 1;
        if (strtolower($row['job_type']) == 'government') {
            $govtCount += $weight;
        } else {
            $privateCount += $weight;
        }
    }
    
    return ['govt' => $govtCount, 'private' => $privateCount];
}

/**
 * Score based on career preference (govt/private) matching
 */
function scoreCareerPreference($jobTypeCounts, $careerPrefPrivate) {
    $total = $jobTypeCounts['govt'] + $jobTypeCounts['private'];
    if ($total == 0) return 10; // Neutral score if no data
    
    if ($careerPrefPrivate == JOB_PRIVATE) {
        // User prefers private
        $ratio = $jobTypeCounts['private'] / $total;
    } else {
        // User prefers government
        $ratio = $jobTypeCounts['govt'] / $total;
    }
    
    return round($ratio * 20); // Up to 20 points for preference match
}

/* ================= MAIN SCORING FUNCTIONS ================= */

/**
 * Calculate stream score using database data
 */
function calculateStreamScore($conn, $streamId, $streamData, $interestArea, $difficultyTol, $careerPref) {
    // Base score
    $score = 50;
    
    // Interest area matching (up to +35)
    $score += matchesInterest($streamData, $interestArea);
    
    // Difficulty matching (up to +15)
    $score += scoreDifficultyMatch($streamData['difficulty_level'] ?? 'Medium', $difficultyTol);
    
    // Career preference matching (up to +20)
    $jobTypes = getStreamJobTypes($conn, $streamId);
    $score += scoreCareerPreference($jobTypes, $careerPref);
    
    // Add some randomness for variety (±3)
    $score += rand(-3, 3);
    
    return $score;
}

/**
 * Calculate exam score based on education level relevance and exam role
 */
function calculateExamScore($conn, $examId, $educationLevel, $interestArea, $difficultyTol) {
    // Base score
    $score = 55;
    
    // Check exam role in stream_exams
    $q = $conn->prepare("
        SELECT se.exam_role, s.stream_name, s.who_should_choose, s.career_scope
        FROM stream_exams se
        JOIN streams s ON s.stream_id = se.stream_id
        WHERE se.exam_id = ?
        LIMIT 5
    ");
    $q->bind_param("i", $examId);
    $q->execute();
    $r = $q->get_result();
    
    $roleBonus = 0;
    $interestBonus = 0;
    
    while ($row = $r->fetch_assoc()) {
        // Exam role bonus
        switch ($row['exam_role']) {
            case 'MANDATORY':
                $roleBonus = max($roleBonus, 20);
                break;
            case 'OPTIONAL':
                $roleBonus = max($roleBonus, 12);
                break;
            case 'SCHOLARSHIP':
                $roleBonus = max($roleBonus, 8);
                break;
        }
        
        // Interest area match from related stream
        $streamMatch = matchesInterest($row, $interestArea);
        $interestBonus = max($interestBonus, $streamMatch);
    }
    
    $score += $roleBonus;
    $score += min($interestBonus, 20); // Cap interest bonus at 20 for exams
    
    // Add some randomness
    $score += rand(-2, 2);
    
    return $score;
}

/**
 * Calculate job score based on eligibility and user preferences
 */
function calculateJobScore($conn, $jobId, $jobType, $educationLevel, $interestArea, $careerPref, $difficultyTol) {
    // Base score
    $score = 50;
    
    // Career preference matching (up to +25)
    $isGovt = (strtolower($jobType) == 'government');
    if (($careerPref == JOB_GOVT && $isGovt) || ($careerPref == JOB_PRIVATE && !$isGovt)) {
        $score += 25;
    } else {
        $score += 10; // Still some score for opposite preference
    }
    
    // Check eligibility strength from stream_jobs
    $q = $conn->prepare("
        SELECT sj.eligibility_strength, s.stream_name, s.who_should_choose, s.career_scope
        FROM stream_jobs sj
        JOIN streams s ON s.stream_id = sj.stream_id
        WHERE sj.job_id = ?
        LIMIT 5
    ");
    $q->bind_param("i", $jobId);
    $q->execute();
    $r = $q->get_result();
    
    $strengthBonus = 0;
    $interestBonus = 0;
    
    while ($row = $r->fetch_assoc()) {
        // Eligibility strength bonus
        switch ($row['eligibility_strength']) {
            case 'PRIMARY':
                $strengthBonus = max($strengthBonus, 15);
                break;
            case 'SECONDARY':
                $strengthBonus = max($strengthBonus, 8);
                break;
        }
        
        // Interest area match from related stream
        $streamMatch = matchesInterest($row, $interestArea);
        $interestBonus = max($interestBonus, $streamMatch);
    }
    
    $score += $strengthBonus;
    $score += min($interestBonus, 15); // Cap at 15 for jobs
    
    // Add some randomness
    $score += rand(-2, 3);
    
    return $score;
}

/* ================= SCORE ADJUSTMENT FUNCTIONS ================= */

function varyDifficulty($base, $index) {
    return max(1, min(3, $base + ($index % 2)));
}

function diversifyScore($score, $index, $educationLevel) {
    $spread = ($educationLevel <= 1) ? 3 : 2;
    return $score - ($index * $spread) + rand(-1, 1);
}

function calibrateScore($score, $educationLevel, $rank) {
    if ($educationLevel <= 1)      $boost = 10;
    elseif ($educationLevel == 2)  $boost = 8;
    else                           $boost = 6;

    return $score + $boost - ($rank * 3);
}

function scaleScore($score) {
    $scaled = ($score - 40) * 1.25;
    return max(55, min(95, round($scaled)));
}

function applySoftFloor($score, $rank) {
    $minRanges = [
        0 => 82, // top recommendation
        1 => 74, // second
        2 => 66  // third
    ];

    $min = $minRanges[$rank] ?? 60;

    if ($score < $min) {
        $score = $min + rand(0, 4);
    }

    return min(95, $score);
}

function getThresholds($educationLevel) {
    if ($educationLevel <= 1)
        return ["stream" => 50, "exam" => 50, "job" => 50];
    elseif ($educationLevel == 2)
        return ["stream" => 65, "exam" => 60, "job" => 60];
    else
        return ["stream" => 70, "exam" => 65, "job" => 65];
}

/* ================= READ INPUT ================= */

$input = json_decode(file_get_contents("php://input"), true);

$educationLevel = (int)($input["education_level"] ?? 0);
$careerPref     = (int)($input["career_preference_private"] ?? 0);
$difficultyTol  = (int)($input["difficulty_tolerance"] ?? 2);
$riskTol        = (int)($input["risk_tolerance"] ?? 2);
$interestArea   = $input["interest_area"] ?? "";

$streams = [];
$exams   = [];
$jobs    = [];

/* ================= STREAMS ================= */

$q = $conn->prepare("
    SELECT stream_id, stream_name, description, who_should_choose, career_scope, difficulty_level 
    FROM streams 
    WHERE education_level_id = ?
");
$q->bind_param("i", $educationLevel);
$q->execute();
$r = $q->get_result();

$index = 0;
while ($row = $r->fetch_assoc()) {
    $streamId = (int)$row["stream_id"];
    
    // Calculate score using database-driven algorithm
    $raw = calculateStreamScore($conn, $streamId, $row, $interestArea, $difficultyTol, $careerPref);
    
    // Apply same adjustments as before
    $div     = diversifyScore($raw, $index, $educationLevel);
    $cal     = calibrateScore($div, $educationLevel, $index);
    $scaled  = scaleScore($cal);
    $final   = applySoftFloor($scaled, $index);

    $streams[] = ["stream_id" => $streamId, "score" => $final];
    $index++;
}

/* ================= EXAMS ================= */

$q = $conn->prepare("
    SELECT e.exam_id
    FROM entrance_exams e
    JOIN education_level_exams ele ON ele.exam_id = e.exam_id
    WHERE ele.education_level_id = ?
");
$q->bind_param("i", $educationLevel);
$q->execute();
$r = $q->get_result();

$index = 0;
while ($row = $r->fetch_assoc()) {
    $examId = (int)$row["exam_id"];
    
    // Calculate score using database-driven algorithm
    $raw = calculateExamScore($conn, $examId, $educationLevel, $interestArea, $difficultyTol);
    
    $div     = diversifyScore($raw, $index, $educationLevel);
    $cal     = calibrateScore($div, $educationLevel, $index);
    $scaled  = scaleScore($cal);
    $final   = applySoftFloor($scaled, $index);

    $exams[] = ["exam_id" => $examId, "score" => $final];
    $index++;
}

/* ================= JOBS ================= */

$q = $conn->prepare("
    SELECT j.job_id, j.job_type
    FROM jobs j
    JOIN education_level_jobs elj ON elj.job_id = j.job_id
    WHERE elj.education_level_id = ?
");
$q->bind_param("i", $educationLevel);
$q->execute();
$r = $q->get_result();

$index = 0;
while ($row = $r->fetch_assoc()) {
    $jobId = (int)$row["job_id"];
    $jobType = $row["job_type"];
    
    // Calculate score using database-driven algorithm
    $raw = calculateJobScore($conn, $jobId, $jobType, $educationLevel, $interestArea, $careerPref, $difficultyTol);
    
    $div     = diversifyScore($raw, $index, $educationLevel);
    $cal     = calibrateScore($div, $educationLevel, $index);
    $scaled  = scaleScore($cal);
    $final   = applySoftFloor($scaled, $index);

    $jobs[] = ["job_id" => $jobId, "score" => $final];
    $index++;
}

/* ================= NON-EMPTY GUARANTEE ================= */

$allStreams = $streams;
$allExams   = $exams;
$allJobs    = $jobs;

$thresholds = getThresholds($educationLevel);

$streams = array_filter($streams, fn($s) => $s["score"] >= $thresholds["stream"]);
$exams   = array_filter($exams,   fn($e) => $e["score"] >= $thresholds["exam"]);
$jobs    = array_filter($jobs,    fn($j) => $j["score"] >= $thresholds["job"]);

usort($allStreams, fn($a,$b) => $b["score"] <=> $a["score"]);
usort($allExams,   fn($a,$b) => $b["score"] <=> $a["score"]);
usort($allJobs,    fn($a,$b) => $b["score"] <=> $a["score"]);

// Re-index filtered arrays
$streams = array_values($streams);
$exams   = array_values($exams);
$jobs    = array_values($jobs);

// Sort filtered arrays by score
usort($streams, fn($a,$b) => $b["score"] <=> $a["score"]);
usort($exams,   fn($a,$b) => $b["score"] <=> $a["score"]);
usort($jobs,    fn($a,$b) => $b["score"] <=> $a["score"]);

if (empty($streams)) $streams = array_slice($allStreams, 0, 3);
if (empty($exams))   $exams   = array_slice($allExams, 0, 3);
if (empty($jobs))    $jobs    = array_slice($allJobs, 0, 3);

/* ================= FINAL RESPONSE ================= */

echo json_encode([
    "status" => true,
    "recommended_streams" => array_slice($streams, 0, 3),
    "recommended_exams" => array_slice($exams, 0, 3),
    "recommended_jobs" => array_slice($jobs, 0, 3)
]);
