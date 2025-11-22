<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . '/../db.php';

try {
    // ดึงคำถามเรียงตาม order_index
    $stmt = $dbh->prepare("SELECT * FROM symptom_questions ORDER BY order_index ASC");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['status' => 'ok', 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>