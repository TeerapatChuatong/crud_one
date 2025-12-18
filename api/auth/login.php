<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || empty($body)) $body = $_POST ?? [];

$account  = strtolower(trim($body['account'] ?? $body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($account === '' || $password === '') {
  json_err("VALIDATION_ERROR", "invalid_credential", 422);
}

try {
  $stmt = $dbh->prepare("
    SELECT user_id, username, email, password_hash, role
    FROM `user`
    WHERE (LOWER(username) = :acc OR LOWER(email) = :acc)
    LIMIT 1
  ");
  $stmt->execute([':acc' => $account]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u || !password_verify($password, $u['password_hash'])) {
    json_err("BAD_CREDENTIALS", "invalid_credential", 401);
  }

  $_SESSION['user_id']  = (int)$u['user_id'];
  $_SESSION['role']     = (string)$u['role'];
  $_SESSION['username'] = (string)$u['username'];
  $_SESSION['email']    = (string)$u['email'];

  unset($u['password_hash']);

  $u['id'] = (int)$u['user_id']; // ✅ เผื่อ frontend ใช้ key id
  unset($u['user_id']);

  $token = session_id();
  if ($token) $u['token'] = $token;

  json_ok($u);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
