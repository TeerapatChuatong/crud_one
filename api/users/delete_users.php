<?php
require_once __DIR__ . '/../db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit(); }
if (!in_array($method, ['POST','DELETE'], true)) json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id   = $body['user_id'] ?? ($body['id'] ?? null);

if ($id === null || !is_numeric($id)) json_err("VALIDATION_ERROR", "invalid_or_missing_id", 400);

try {
  require_admin();

  $target_id = (int)$id;

  $check = $dbh->prepare("SELECT role FROM `user` WHERE user_id = ? LIMIT 1");
  $check->execute([$target_id]);
  $row = $check->fetch(PDO::FETCH_ASSOC);

  if (!$row) json_err("NOT_FOUND", "not_found", 404);

  if ($row['role'] === 'super admin') {
    json_err("FORBIDDEN", "cannot_delete_super_admin", 403);
  }

  $stmt = $dbh->prepare("DELETE FROM `user` WHERE user_id = ?");
  $stmt->execute([$target_id]);

  if ($stmt->rowCount() > 0) json_ok(["status" => "ok"]);
  json_err("NOT_FOUND", "not_found", 404);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
