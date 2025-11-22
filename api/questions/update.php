<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PATCH, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/../db.php';

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id = $body['question_id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_id']);
    exit;
}

try {
    // สร้าง Dynamic Update
    $fields = [];
    $params = [];

    if (isset($body['question_text'])) { 
        $fields[] = "question_text = ?"; 
        $params[] = $body['question_text']; 
    }
    if (isset($body['question_type'])) { 
        $fields[] = "question_type = ?"; 
        $params[] = $body['question_type']; 
    }
    if (isset($body['order_index'])) { 
        $fields[] = "order_index = ?"; 
        $params[] = $body['order_index']; 
    }

    if (empty($fields)) {
        echo json_encode(['status' => 'ok', 'message' => 'no_change']);
        exit;
    }

    $params[] = (int)$id;
    $sql = "UPDATE symptom_questions SET " . implode(", ", $fields) . " WHERE question_id = ?";

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['status' => 'ok', 'message' => 'updated']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>