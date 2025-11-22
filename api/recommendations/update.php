<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PATCH, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/../db.php';

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id = $body['reco_id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_id']);
    exit;
}

try {
    // แก้ไขข้อความแนะนำ
    $reco_text = trim($body['reco_text'] ?? '');
    
    if ($reco_text === '') {
         // ถ้าไม่ได้ส่งข้อความใหม่มา ก็ไม่ทำอะไร
         echo json_encode(['status' => 'ok', 'message' => 'no_change']);
         exit;
    }

    $stmt = $dbh->prepare("UPDATE recommendations SET reco_text = ? WHERE reco_id = ?");
    $stmt->execute([$reco_text, (int)$id]);

    echo json_encode(['status' => 'ok', 'message' => 'updated']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>