<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$body = json_decode(file_get_contents("php://input"), true) ?: [];

// ตรวจสอบค่าที่จำเป็น
$name_th = trim($body['name_th'] ?? '');
if ($name_th === '') {
    json_err("VALIDATION_ERROR", "missing_name_th", 400);
}

try {
    // Admin only
    require_admin();

    $stmt = $dbh->prepare("
        INSERT INTO diseases (name_th, name_en, description, cause, pathogen, image)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $name_th,
        $body['name_en'] ?? '',
        $body['description'] ?? '',
        $body['cause'] ?? '',
        $body['pathogen'] ?? '',
        $body['image'] ?? '' // รับเป็น URL หรือ Path ของรูป
    ]);

    json_ok(['status' => 'ok', 'id' => $dbh->lastInsertId()]);
} catch (Throwable $e) {
    json_err("DB_ERROR", "db_error", 500);
}
?>