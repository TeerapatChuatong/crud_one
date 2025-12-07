<?php
require_once __DIR__ . '/../db.php';

/* Preflight */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit();
}

/* Method guard */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

/* ต้อง login ก่อน */
require_login();

/* role normalize (กันเคสใน DB เป็น 'super admin') */
$role_raw  = $_SESSION['role'] ?? 'user';
$role_norm = str_replace(' ', '_', strtolower($role_raw));
$is_admin  = in_array($role_norm, ['admin', 'super_admin'], true);

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

/* ถ้าไม่ส่ง id มา ให้ fallback เป็น id ตัวเอง */
$id_param = $_GET['id'] ?? $current_user_id;

if ($id_param === null || !ctype_digit((string)$id_param)) {
  json_err("VALIDATION_ERROR", "invalid_or_missing_id", 400);
}

$target_id = (int)$id_param;

/* user ทั่วไปดูได้เฉพาะตัวเอง */
if (!$is_admin && $target_id !== $current_user_id) {
  json_err("FORBIDDEN", "can_only_view_self", 403);
}

try {
  $stmt = $dbh->prepare("SELECT id, username, email, role FROM user WHERE id = ?");
  $stmt->execute([$target_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_err("NOT_FOUND", "not_found", 404);
  }

  json_ok([
    "id"       => (int)$row["id"],
    "username" => $row["username"],
    "email"    => $row["email"],
    "role"     => $row["role"],
  ]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
