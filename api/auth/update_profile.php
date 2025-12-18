<?php
require_once __DIR__ . '/../db.php';
$auth = require_once __DIR__ . '/require_auth.php';

$body = json_decode(file_get_contents("php://input"), true) ?: [];

try {
  $userId = (int)($auth['id'] ?? 0);
  if ($userId <= 0) json_err("AUTH_ERROR", "not_logged_in", 401);

  $q = $dbh->prepare("
    SELECT user_id, username, email, password_hash, role
    FROM `user`
    WHERE user_id = ?
    LIMIT 1
  ");
  $q->execute([$userId]);
  $user = $q->fetch(PDO::FETCH_ASSOC);
  if (!$user) json_err("NOT_FOUND", "user_not_found", 404);

  $hasUsername = array_key_exists('username', $body);
  $hasEmail    = array_key_exists('email', $body);

  $username = $hasUsername ? trim((string)$body['username']) : $user['username'];
  $email    = $hasEmail ? strtolower(trim((string)$body['email'])) : $user['email'];

  if ($hasUsername && $username === '') json_err("VALIDATION_ERROR", "invalid_username", 400);
  if ($hasEmail && (!filter_var($email, FILTER_VALIDATE_EMAIL))) json_err("VALIDATION_ERROR", "invalid_email", 400);

  $currentPassword = (string)($body['current_password'] ?? '');
  $newPassword     = (string)($body['new_password'] ?? '');

  // เช็คซ้ำกับ user อื่น
  if ($hasUsername || $hasEmail) {
    $chk = $dbh->prepare("
      SELECT user_id, username, email
      FROM `user`
      WHERE (email = ? OR username = ?)
        AND user_id <> ?
      LIMIT 1
    ");
    $chk->execute([$email, $username, $userId]);
    $dup = $chk->fetch(PDO::FETCH_ASSOC);

    if ($dup) {
      if (strcasecmp($dup['email'], $email) === 0) json_err("DUPLICATE", "email_exists", 409);
      if (strcasecmp($dup['username'], $username) === 0) json_err("DUPLICATE", "username_exists", 409);
      json_err("DUPLICATE", "username_or_email_exists", 409);
    }
  }

  $fields = [];
  $params = [];

  if ($username !== $user['username']) { $fields[] = "username = ?"; $params[] = $username; }
  if ($email !== $user['email'])       { $fields[] = "email = ?";    $params[] = $email; }

  if ($newPassword !== '') {
    if ($currentPassword === '') json_err("VALIDATION_ERROR", "current_password_required", 400);
    if (!password_verify($currentPassword, $user['password_hash'])) {
      json_err("VALIDATION_ERROR", "current_password_incorrect", 400);
    }
    $fields[] = "password_hash = ?";
    $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
  }

  if (!$fields) {
    json_ok([
      'id' => (int)$user['user_id'],
      'username' => $user['username'],
      'email' => $user['email'],
      'role' => $user['role'],
      'message' => 'no_change',
      'token' => session_id(),
    ]);
  }

  $params[] = $userId;
  $sql = "UPDATE `user` SET " . implode(', ', $fields) . " WHERE user_id = ?";
  $stmt = $dbh->prepare($sql);
  $stmt->execute($params);

  // sync session
  $_SESSION['username'] = $username;
  $_SESSION['email']    = $email;

  json_ok([
    'id' => $userId,
    'username' => $username,
    'email' => $email,
    'role' => $user['role'],
    'message' => 'profile_updated',
    'token' => session_id(),
  ]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
