<?php
require_once __DIR__ . '/../db.php';
$auth = require_once __DIR__ . '/require_auth.php';

try {
  $userId = (int)($auth['id'] ?? 0);
  if ($userId <= 0) json_err("AUTH_ERROR", "not_logged_in", 401);

  $stmt = $dbh->prepare("
    SELECT user_id, username, email, role, created_at
    FROM `user`
    WHERE user_id = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $userId]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u) json_err("NOT_FOUND", "user_not_found", 404);

  $u['id'] = (int)$u['user_id'];
  unset($u['user_id']);

  $token = session_id();
  if ($token) $u['token'] = $token;

  json_ok($u);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
