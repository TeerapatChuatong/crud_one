<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . '/../db.php';

$disease_id = isset($_GET['disease_id']) ? (int)$_GET['disease_id'] : null;

if (!$disease_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_disease_id']);
    exit;
}

try {
    $stmt = $dbh->prepare("SELECT * FROM recommendations WHERE disease_id = ?");
    $stmt->execute([$disease_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['status' => 'ok', 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>