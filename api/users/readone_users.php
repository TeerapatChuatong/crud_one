<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405); }

require_login();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role_norm = role_norm($_SESSION['role'] ?? 'user');
$is_admin  = in_array($role_norm, ['admin','super_admin'], true);

// รับได้ทั้ง id หรือ user_id
$id_param = $_GET['user_id'] ?? ($_GET['id'] ?? $current_user_id);
if ($id_param === null || !ctype_digit((string)$id_param)) {
  json_err("VALIDATION_ERROR", "invalid_or_missing_id", 400);
}
$target_id = (int)$id_param;

if (!$is_admin && $target_id !== $current_user_id) {
  json_err("FORBIDDEN", "can_only_view_self", 403);
}

try {
  $stmt = $dbh->prepare("SELECT user_id, username, email, role, created_at FROM `user` WHERE user_id = ? LIMIT 1");
  $stmt->execute([$target_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) json_err("NOT_FOUND", "not_found", 404);

  json_ok([
    "user_id"   => (int)$row["user_id"],
    "id"        => (int)$row["user_id"],
    "username"  => $row["username"],
    "email"     => $row["email"],
    "role"      => $row["role"],
    "created_at"=> $row["created_at"] ?? null,
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
