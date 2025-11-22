<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, DELETE, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/../db.php';

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id = $body['disease_id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_id']);
    exit;
}

try {
    // ลบโรค (เนื่องจากเราตั้ง Cascade ไว้ใน SQL ข้อมูลที่เกี่ยวข้องจะหายไปด้วย หรือ set null ตามที่ตั้ง)
    $stmt = $dbh->prepare("DELETE FROM diseases WHERE disease_id = ?");
    $stmt->execute([(int)$id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'not_found']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>