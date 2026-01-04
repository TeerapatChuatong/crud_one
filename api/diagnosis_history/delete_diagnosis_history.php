<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php'; // ✅ รองรับ Authorization: Bearer <token>


$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['DELETE', 'POST', 'GET'], true)) {
  json_err("METHOD_NOT_ALLOWED", "delete_post_get_only", 405);
}

$isAdmin = is_admin();
$session_user_id = (int)($_SESSION['user_id'] ?? 0);

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) $body = [];

$id = $_GET['diagnosis_history_id'] ?? $_GET['id'] ?? $body['diagnosis_history_id'] ?? $body['id'] ?? null;
if ($id === null || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR", "invalid_diagnosis_history_id", 400);
}

try {
  // ตรวจสอบสิทธิ์ก่อนลบ
  $st = $dbh->prepare("SELECT diagnosis_history_id, user_id FROM diagnosis_history WHERE diagnosis_history_id=? LIMIT 1");
  $st->execute([(int)$id]);
  $row = $st->fetch();
  if (!$row) json_err("NOT_FOUND", "not_found", 404);

  if (!$isAdmin && (int)$row['user_id'] !== $session_user_id) {
    json_err("FORBIDDEN", "not_owner", 403);
  }

  $del = $dbh->prepare("DELETE FROM diagnosis_history WHERE diagnosis_history_id=?");
  $del->execute([(int)$id]);

  json_ok(["deleted" => true, "diagnosis_history_id" => (int)$id]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
