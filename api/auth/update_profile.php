<?php
require_once __DIR__ . '/require_auth.php';

// อ่าน body JSON
$body = json_decode(file_get_contents("php://input"), true) ?: [];

try {
  $userId = (int)($AUTH_USER_ID ?? ($_SESSION['user_id'] ?? 0));
  if ($userId <= 0) {
    json_err("AUTH_ERROR", "not_logged_in", 401);
  }

  // ดึงข้อมูล user เดิม
  $q = $dbh->prepare("
      SELECT id, username, email, password_hash, role
      FROM user
      WHERE id = ?
      LIMIT 1
  ");
  $q->execute([$userId]);
  $user = $q->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    json_err("NOT_FOUND", "user_not_found", 404);
  }

  // ---- username (อาจจะส่งมาหรือไม่ก็ได้) ----
  $hasUsername = array_key_exists('username', $body);
  $username = $hasUsername ? trim((string)$body['username']) : $user['username'];

  if ($hasUsername && $username === '') {
    json_err("VALIDATION_ERROR", "invalid_username", 400);
  }

  // ---- email (อาจจะส่งมาหรือไม่ก็ได้) ----
  $hasEmail = array_key_exists('email', $body);
  $email = $hasEmail ? trim((string)$body['email']) : $user['email'];

  if ($hasEmail) {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      json_err("VALIDATION_ERROR", "invalid_email", 400);
    }
  }

  // ---- password (เปลี่ยนได้ถ้าส่ง current + new มา) ----
  $currentPassword = (string)($body['current_password'] ?? '');
  $newPassword     = (string)($body['new_password']     ?? '');

  // ถ้าไม่มีอะไรจะเปลี่ยนเลย
  if (!$hasUsername && !$hasEmail && $newPassword === '') {
    $resp = [
      'id'       => (int)$user['id'],
      'username' => $user['username'],
      'email'    => $user['email'],
      'role'     => $user['role'],
      'message'  => 'no_change',
    ];
    $token = session_id();
    if ($token) {
      $resp['token'] = $token;
    }
    json_ok($resp);
  }

  // ===== เช็ค username / email ซ้ำกับ user อื่น =====
  $chk = $dbh->prepare("
    SELECT id, username, email
    FROM user
    WHERE (email = ? OR username = ?)
      AND id <> ?
  ");
  $chk->execute([$email, $username, $userId]);
  $dup = $chk->fetch(PDO::FETCH_ASSOC);

  if ($dup) {
    if (strcasecmp($dup['email'], $email) === 0) {
      json_err("DUPLICATE", "email_exists", 409);
    }
    if (strcasecmp($dup['username'], $username) === 0) {
      json_err("DUPLICATE", "username_exists", 409);
    }
    json_err("DUPLICATE", "username_or_email_exists", 409);
  }

  // ===== เตรียมฟิลด์ UPDATE =====
  $fields = [];
  $params = [];

  if ($username !== $user['username']) {
    $fields[] = "username = ?";
    $params[] = $username;
  }

  if ($email !== $user['email']) {
    $fields[] = "email = ?";
    $params[] = $email;
  }

  // ===== จัดการเปลี่ยนรหัสผ่าน =====
  if ($newPassword !== '') {
    if ($currentPassword === '') {
      json_err("VALIDATION_ERROR", "current_password_required", 400);
    }

    if (!password_verify($currentPassword, $user['password_hash'])) {
      json_err("VALIDATION_ERROR", "current_password_incorrect", 400);
    }

    $fields[] = "password_hash = ?";
    $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
  }

  if (empty($fields)) {
    $resp = [
      'id'       => (int)$user['id'],
      'username' => $user['username'],
      'email'    => $user['email'],
      'role'     => $user['role'],
      'message'  => 'no_change',
    ];
    $token = session_id();
    if ($token) {
      $resp['token'] = $token;
    }
    json_ok($resp);
  }

  // ===== UPDATE =====
  $params[] = $userId;
  $sql = "UPDATE user SET " . implode(', ', $fields) . " WHERE id = ?";
  $stmt = $dbh->prepare($sql);
  $stmt->execute($params);

  $resp = [
    'id'       => (int)$user['id'],
    'username' => $username,
    'email'    => $email,
    'role'     => $user['role'],
    'message'  => 'profile_updated',
  ];
  $token = session_id();
  if ($token) {
    $resp['token'] = $token;
  }

  json_ok($resp);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
