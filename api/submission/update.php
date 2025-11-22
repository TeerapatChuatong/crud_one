<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PATCH, OPTIONS");
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
    // สร้าง SQL แบบ Dynamic (แก้เฉพาะค่าที่ส่งมา)
    $fields = [];
    $params = [];

    if (isset($body['name_th'])) { $fields[] = "name_th = ?"; $params[] = $body['name_th']; }
    if (isset($body['name_en'])) { $fields[] = "name_en = ?"; $params[] = $body['name_en']; }
    if (isset($body['description'])) { $fields[] = "description = ?"; $params[] = $body['description']; }
    if (isset($body['cause'])) { $fields[] = "cause = ?"; $params[] = $body['cause']; }
    if (isset($body['pathogen'])) { $fields[] = "pathogen = ?"; $params[] = $body['pathogen']; }
    if (isset($body['image'])) { $fields[] = "image = ?"; $params[] = $body['image']; }

    if (empty($fields)) {
        echo json_encode(['status' => 'ok', 'message' => 'no_change']);
        exit;
    }

    $params[] = $id; // id ตัวสุดท้ายสำหรับ WHERE
    $sql = "UPDATE diseases SET " . implode(", ", $fields) . " WHERE disease_id = ?";

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['status' => 'ok', 'message' => 'updated']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>