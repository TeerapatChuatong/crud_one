<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/../db.php';

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$text = trim($body['question_text'] ?? '');
$type = trim($body['question_type'] ?? 'choice'); // choice, text, boolean
$order = isset($body['order_index']) ? (int)$body['order_index'] : 0;
$user_id = isset($body['user_id']) ? (int)$body['user_id'] : null; // Admin ID

if ($text === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_text']);
    exit;
}

try {
    $stmt = $dbh->prepare("
        INSERT INTO symptom_questions (question_text, question_type, order_index, user_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$text, $type, $order, $user_id]);

    echo json_encode(['status' => 'ok', 'id' => $dbh->lastInsertId()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>