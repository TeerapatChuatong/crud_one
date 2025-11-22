<?php
// CORS + JSON
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

$method = $_SERVER['REQUEST_METHOD'];

// Preflight
if ($method === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once __DIR__ . '/../db.php';

$uid = null;

// 1) ถ้ามีส่ง id มาจาก body (เหมาะกับ Flutter / Postman)
if ($method === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true) ?: [];
  if (isset($body['id']) && is_numeric($body['id'])) {
    $uid = (int)$body['id'];
  }
}

// 2) ถ้าไม่ส่ง id มาก็ลองใช้ค่าใน session (เหมาะกับเว็บ PHP เดิม)
if (!$uid && !empty($_SESSION['user_id'])) {
  $uid = (int)$_SESSION['user_id'];
}

// ถ้ายังไม่มี uid เลย = ยังไม่ล็อกอิน
if (!$uid) {
  json_err("UNAUTHENTICATED", "unauthenticated", 401);
}

try {
  $stmt = $dbh->prepare(
    "SELECT id, username, email, role, created_at
     FROM `user`
     WHERE id = ? LIMIT 1"
  );
  $stmt->execute([$uid]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u) {
    json_err("USER_NOT_FOUND", "user_not_found", 404);
  }

  json_ok($u);

} catch (Throwable $e) {
  // ถ้าต้องการ debug ชั่วคราว:
  // json_err("DB_ERROR", $e->getMessage(), 500);
  json_err("DB_ERROR", "db_error", 500);
}
