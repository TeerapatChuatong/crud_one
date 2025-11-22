<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/../db.php';

$body = json_decode(file_get_contents("php://input"), true) ?: [];

// ต้องระบุว่าคำแนะนำนี้ของโรคอะไร
$disease_id = isset($body['disease_id']) ? (int)$body['disease_id'] : null;
$reco_text  = trim($body['reco_text'] ?? '');
$created_by = isset($body['created_by']) ? (int)$body['created_by'] : null; // User ID ของ Admin

if (!$disease_id || $reco_text === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_fields']);
    exit;
}

try {
    $stmt = $dbh->prepare("
        INSERT INTO recommendations (reco_text, disease_id, created_by)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$reco_text, $disease_id, $created_by]);

    echo json_encode(['status' => 'ok', 'id' => $dbh->lastInsertId()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>