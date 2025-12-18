<?php
require_once __DIR__ . '/../db.php';

$method = $_SERVER["REQUEST_METHOD"];
if ($method === 'OPTIONS') { http_response_code(204); exit(); }
if (!in_array($method, ['PATCH','POST'], true)) json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);

function role_to_db($role) {
  $r = strtolower(trim((string)$role));
  if ($r === 'super_admin') return 'super admin';
  if ($r === 'super admin') return 'super admin';
  if ($r === 'admin') return 'admin';
  return 'user';
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

// รับได้ทั้ง id หรือ user_id
$id = $body['user_id'] ?? ($body['id'] ?? null);

$username = trim($body['username'] ?? '');
$email    = trim($body['email'] ?? '');

if ($id === null || !is_numeric($id)) json_err("VALIDATION_ERROR", "invalid_or_missing_id", 400);
if ($username === '' || $email === '') json_err("VALIDATION_ERROR", "missing_fields", 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err("VALIDATION_ERROR", "invalid_email", 400);

try {
  require_login();

  $target_id = (int)$id;

  $q = $dbh->prepare("SELECT user_id, role FROM `user` WHERE user_id = ? LIMIT 1");
  $q->execute([$target_id]);
  $old = $q->fetch(PDO::FETCH_ASSOC);
  if (!$old) json_err("NOT_FOUND", "user_not_found", 404);

  $current_user_id = (int)($_SESSION['user_id'] ?? 0);
  $current_role_n  = role_norm($_SESSION['role'] ?? 'user');
  $is_admin        = in_array($current_role_n, ['admin','super_admin'], true);

  if (!$is_admin && $target_id !== $current_user_id) {
    json_err("FORBIDDEN", "can_only_update_self", 403);
  }

  // role
  $role_db = $old['role'];
  if (array_key_exists('role', $body)) {
    $role_db = role_to_db($body['role']);

    if (!in_array($role_db, ['user','admin','super admin'], true)) {
      json_err("VALIDATION_ERROR", "invalid_role", 400);
    }
    if (!$is_admin && $role_db !== $old['role']) {
      json_err("FORBIDDEN", "cannot_change_role", 403);
    }
  }

  // dup check
  $chk = $dbh->prepare("
    SELECT user_id, username, email
    FROM `user`
    WHERE (email = ? OR username = ?)
      AND user_id <> ?
    LIMIT 1
  ");
  $chk->execute([$email, $username, $target_id]);
  $dup = $chk->fetch(PDO::FETCH_ASSOC);
  if ($dup) {
    if (strcasecmp($dup['email'], $email) === 0) json_err("DUPLICATE", "email_exists", 409);
    if (strcasecmp($dup['username'], $username) === 0) json_err("DUPLICATE", "username_exists", 409);
    json_err("DUPLICATE", "username_or_email_exists", 409);
  }

  $stmt = $dbh->prepare("UPDATE `user` SET username = ?, email = ?, role = ? WHERE user_id = ?");
  $stmt->execute([$username, $email, $role_db, $target_id]);

  json_ok(["status" => "ok"]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
