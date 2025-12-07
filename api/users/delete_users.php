<?php
require_once __DIR__ . '/../db.php';

$method = $_SERVER['REQUEST_METHOD'];

// Preflight
if ($method === 'OPTIONS') {
  http_response_code(200);
  exit();
}

// อนุญาตลบผ่าน POST หรือ DELETE
if (!in_array($method, ['POST', 'DELETE'], true)) {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

// รับ id จาก JSON body
$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id   = $body['id'] ?? null;

// Validate id
if ($id === null || !is_numeric($id)) {
  json_err("VALIDATION_ERROR", "invalid_or_missing_id", 400);
}

try {
  // Admin only
  require_admin();

  // 1) เช็กว่ามี user นี้ไหม + ดู role ก่อน
  $check = $dbh->prepare("SELECT role FROM user WHERE id = ?");
  $check->execute([(int)$id]);
  $row = $check->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_err("NOT_FOUND", "not_found", 404);
  }

  // 2) ถ้าเป็น super_admin → ไม่ให้ลบ
  if ($row['role'] === 'super_admin') {
    json_err("FORBIDDEN", "cannot_delete_super_admin", 403);
  }

  // 3) ลบจริง (admin / user ลบได้)
  $stmt = $dbh->prepare("DELETE FROM user WHERE id = ?");
  $ok   = $stmt->execute([(int)$id]);

  if ($ok && $stmt->rowCount() > 0) {
    json_ok(['status' => 'ok']);
  } else {
    json_err("NOT_FOUND", "not_found", 404);
  }

  $dbh = null;

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}