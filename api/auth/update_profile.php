<?php
require_once __DIR__ . '/../db.php';

// ให้แน่ใจว่ามี session (กันเหนียว)
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$method = $_SERVER["REQUEST_METHOD"];

if ($method === 'OPTIONS') {
  http_response_code(200);
  exit();
}

if (!in_array($method, ['PATCH','POST'], true)) {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

/* ===== อ่าน body (JSON) ===== */
$body = json_decode(file_get_contents("php://input"), true) ?: [];

/*
 * ===== หา user id =====
 * 1) ถ้ามีส่ง id มาจาก body -> ใช้อันนี้ (เหมาะกับ Flutter / Postman)
 * 2) ถ้าไม่มีก็ลองใช้ $_SESSION['user_id'] (เหมาะกับเว็บ PHP เดิม)
 */
$id = null;

if (isset($body['id']) && is_numeric($body['id'])) {
  $id = (int)$body['id'];
} elseif (!empty($_SESSION['user_id'])) {
  $id = (int)$_SESSION['user_id'];
}

if (!$id) {
  json_err("VALIDATION_ERROR", "invalid_or_missing_id", 400);
}

try {
  // ===== ดึงข้อมูล user เดิม =====
  $q = $dbh->prepare("
      SELECT id, username, email, password_hash, role
      FROM user
      WHERE id = ?
      LIMIT 1
  ");
  $q->execute([$id]);
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
    if ($email === '') {
      json_err("VALIDATION_ERROR", "invalid_email", 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      json_err("VALIDATION_ERROR", "invalid_email", 400);
    }
  }

  // ---- password (เปลี่ยนได้ถ้าส่ง current + new มา) ----
  $currentPassword = (string)($body['current_password'] ?? '');
  $newPassword     = (string)($body['new_password']     ?? '');

  // ถ้าไม่มีอะไรจะเปลี่ยนเลย
  if (!$hasUsername && !$hasEmail && $newPassword === '') {
    // ส่งข้อมูลเดิมกลับ พร้อม message
    $resp = [
      'id'       => (int)$user['id'],
      'username' => $user['username'],
      'email'    => $user['email'],
      'role'     => $user['role'],
      'message'  => 'no_change',
    ];
    // เผื่อฝั่ง Flutter อยากใช้ token จาก session_id
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
  $chk->execute([$email, $username, $id]);
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
    $fields[]  = "username = ?";
    $params[]  = $username;
  }

  if ($email !== $user['email']) {
    $fields[]  = "email = ?";
    $params[]  = $email;
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
    // ถึงตรงนี้ แปลว่า username/email เหมือนเดิม และไม่ได้เปลี่ยน password จริง ๆ
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
  $params[] = $id;
  $sql = "UPDATE user SET " . implode(', ', $fields) . " WHERE id = ?";
  $stmt = $dbh->prepare($sql);
  $stmt->execute($params);

  $resp = [
    'id'       => (int)$user['id'],
    'username' => $username,
    'email'    => $email,
    'role'     => $user['role'],   // ไม่ให้แก้ role แต่ส่งกลับให้ดูได้
    'message'  => 'profile_updated',
  ];

  // เพิ่ม token จาก session_id เผื่อฝั่ง Flutter จะใช้
  $token = session_id();
  if ($token) {
    $resp['token'] = $token;
  }

  json_ok($resp);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
