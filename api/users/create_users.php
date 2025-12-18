<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER["REQUEST_METHOD"] !== "POST") { json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405); }

function role_to_db($role) {
  $r = strtolower(trim((string)$role));
  if ($r === 'super_admin') return 'super admin';
  if ($r === 'super admin') return 'super admin';
  if ($r === 'admin') return 'admin';
  return 'user';
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$username = trim($body['username'] ?? '');
$email    = trim($body['email'] ?? '');
$password = trim($body['password'] ?? '');
$role_in  = $body['role'] ?? 'user';
$role_db  = role_to_db($role_in);

if ($username === '' || $email === '' || $password === '') json_err("VALIDATION_ERROR", "missing_fields", 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err("VALIDATION_ERROR", "invalid_email", 400);
if (strlen($password) < 8) json_err("VALIDATION_ERROR", "weak_password_min_8", 400);

try {
  require_admin();

  if (!in_array($role_db, ['user','admin','super admin'], true)) {
    json_err("VALIDATION_ERROR", "invalid_role", 400);
  }

  $chk = $dbh->prepare("SELECT user_id, username, email FROM `user` WHERE email = ? OR username = ? LIMIT 1");
  $chk->execute([$email, $username]);
  $row = $chk->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    if (strcasecmp($row['email'], $email) === 0) json_err("DUPLICATE", "email_exists", 409);
    if (strcasecmp($row['username'], $username) === 0) json_err("DUPLICATE", "username_exists", 409);
    json_err("DUPLICATE", "username_or_email_exists", 409);
  }

  $password_hash = password_hash($password, PASSWORD_BCRYPT);

  $stmt = $dbh->prepare("INSERT INTO `user` (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
  $stmt->execute([$username, $email, $password_hash, $role_db]);

  http_response_code(201);
  json_ok([
    "user_id"  => (int)$dbh->lastInsertId(),
    "id"       => (int)$dbh->lastInsertId(),
    "username" => $username,
    "email"    => $email,
    "role"     => $role_db,
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
