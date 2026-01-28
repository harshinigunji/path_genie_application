<?php
/**
 * Delete Account API
 * Permanently deletes user account and all associated data
 * 
 * Method: POST
 * Input: user_id (required)
 * Output: JSON with status and message
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include("config/db.php");
require_once("response.php");

// Get input data
$data = json_decode(file_get_contents("php://input"), true);
$user_id = isset($data['user_id']) ? intval($data['user_id']) : 0;

if ($user_id <= 0) {
    error("Valid user_id is required");
}

// Check if user exists
$checkUser = mysqli_query($conn, "SELECT user_id, profile_image FROM users WHERE user_id = $user_id");
if (mysqli_num_rows($checkUser) === 0) {
    error("User not found");
}

$user = mysqli_fetch_assoc($checkUser);

// Start transaction
mysqli_begin_transaction($conn);

try {
    // Delete user's forum replies first (child of answers)
    mysqli_query($conn, "DELETE FROM forum_replies WHERE user_id = $user_id");
    
    // Delete user's answer likes
    mysqli_query($conn, "DELETE FROM forum_answer_likes WHERE user_id = $user_id");
    
    // Delete user's question likes
    mysqli_query($conn, "DELETE FROM forum_question_likes WHERE user_id = $user_id");
    
    // Delete user's forum answers
    mysqli_query($conn, "DELETE FROM forum_answers WHERE user_id = $user_id");
    
    // Delete user's forum questions (and cascade to related answers/replies by other users)
    $userQuestions = mysqli_query($conn, "SELECT question_id FROM forum_questions WHERE user_id = $user_id");
    while ($q = mysqli_fetch_assoc($userQuestions)) {
        $qid = $q['question_id'];
        // Delete replies to answers on this question
        mysqli_query($conn, "DELETE fr FROM forum_replies fr 
                            INNER JOIN forum_answers fa ON fr.answer_id = fa.answer_id 
                            WHERE fa.question_id = $qid");
        // Delete answer likes on this question
        mysqli_query($conn, "DELETE fal FROM forum_answer_likes fal 
                            INNER JOIN forum_answers fa ON fal.answer_id = fa.answer_id 
                            WHERE fa.question_id = $qid");
        // Delete answers on this question
        mysqli_query($conn, "DELETE FROM forum_answers WHERE question_id = $qid");
        // Delete question likes
        mysqli_query($conn, "DELETE FROM forum_question_likes WHERE question_id = $qid");
    }
    mysqli_query($conn, "DELETE FROM forum_questions WHERE user_id = $user_id");
    
    // Delete user's notifications
    mysqli_query($conn, "DELETE FROM notifications WHERE user_id = $user_id");
    
    // Delete user's saved exams
    mysqli_query($conn, "DELETE FROM saved_exams WHERE user_id = $user_id");
    
    // Delete user's saved jobs
    mysqli_query($conn, "DELETE FROM saved_jobs WHERE user_id = $user_id");
    
    // Delete user's roadmap steps
    mysqli_query($conn, "DELETE urs FROM user_roadmap_steps urs 
                        INNER JOIN user_roadmaps ur ON urs.user_roadmap_id = ur.user_roadmap_id 
                        WHERE ur.user_id = $user_id");
    
    // Delete user's roadmaps
    mysqli_query($conn, "DELETE FROM user_roadmaps WHERE user_id = $user_id");
    
    // Delete saved system roadmaps
    mysqli_query($conn, "DELETE FROM saved_roadmaps WHERE user_id = $user_id");
    
    // Delete profile image file if exists
    if (!empty($user['profile_image'])) {
        $imagePath = $user['profile_image'];
        // Handle relative path
        if (strpos($imagePath, 'uploads/') === 0) {
            $fullPath = __DIR__ . '/' . $imagePath;
        } else {
            $fullPath = $imagePath;
        }
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
    
    // Finally, delete the user
    $deleteUser = mysqli_query($conn, "DELETE FROM users WHERE user_id = $user_id");
    
    if (!$deleteUser) {
        throw new Exception("Failed to delete user: " . mysqli_error($conn));
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    success([
        "message" => "Account deleted successfully"
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    mysqli_rollback($conn);
    error("Failed to delete account: " . $e->getMessage());
}
?>
