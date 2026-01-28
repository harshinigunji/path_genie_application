<?php
// test_chatbot_local.php
// Test the retrieval-based chatbot without LLM
// Run this file in browser: http://<server>/education_stream_advisor_api/test_chatbot_local.php

header("Content-Type: text/html; charset=utf-8");

echo "<h1>🤖 Retrieval-Based Chatbot Test</h1>";
echo "<p>This chatbot works <strong>without any LLM or API keys</strong>.</p>";
echo "<hr>";

// Test questions
$testQuestions = [
    "Streams after 12th Science",
    "Jobs after BTech",
    "What is JEE Main?",
    "How to become a doctor?",
    "Hello",
    "Highest paying careers",
    "How to prepare for NEET?",
    "Random question that won't match"
];

foreach ($testQuestions as $question) {
    echo "<h3>📝 Question: \"$question\"</h3>";
    
    // Simulate the API call
    $ch = curl_init("http://localhost/education_stream_advisor_api/chatbot_local.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["message" => $question]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        $reply = $data['reply'] ?? 'No reply';
        // Convert newlines to <br> for HTML display
        $reply = nl2br(htmlspecialchars($reply));
        echo "<div style='background:#f0f0f0; padding:15px; border-radius:8px; margin:10px 0;'>";
        echo "<strong>✅ Status:</strong> " . ($data['status'] ? 'Success' : 'Failed') . "<br><br>";
        echo "<strong>💬 Reply:</strong><br>$reply";
        echo "</div>";
    } else {
        echo "<div style='background:#ffe0e0; padding:15px; border-radius:8px;'>";
        echo "❌ Error: HTTP $http_code";
        echo "</div>";
    }
    
    echo "<hr>";
}

echo "<h2>🔧 Setup Instructions</h2>";
echo "<ol>";
echo "<li><strong>Run the SQL script:</strong> Execute <code>chatbot_kb_schema.sql</code> in your MySQL database</li>";
echo "<li><strong>Deploy PHP files:</strong> Copy <code>chatbot_local.php</code> to your server's API folder</li>";
echo "<li><strong>Update Android app:</strong> Change the API endpoint from <code>chatbot.php</code> to <code>chatbot_local.php</code> in AiAssistantPage.java</li>";
echo "</ol>";

echo "<h2>📊 Knowledge Base Stats</h2>";
include("config/db.php");
$result = mysqli_query($conn, "SELECT category, COUNT(*) as count FROM chatbot_knowledge_base GROUP BY category");
if ($result) {
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
    echo "<tr><th>Category</th><th>Count</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr><td>{$row['category']}</td><td>{$row['count']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>⚠️ Knowledge base table not found. Run the SQL script first!</p>";
}
?>
