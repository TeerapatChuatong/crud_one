<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

// รองรับทั้ง JSON และ form
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || empty($body)) {
  $body = $_POST ?? [];
}

$account  = strtolower(trim($body['account'] ?? $body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($account === '' || $password === '') {
  json_err("VALIDATION_ERROR", "invalid_credential", 422);
}

try {
  // มีคอลัมน์ username ไหม?
  $hasUsername = false;
  $col = $dbh->query("SHOW COLUMNS FROM `user` LIKE 'username'")->fetch();
  if ($col) $hasUsername = true;

  // เลือกฟิลด์
  $fields = "id, email, password_hash, role";
  if ($hasUsername) {
    $fields .= ", username";
  }

  $where = $hasUsername
        ? "(username = :acc OR email = :acc)"
        : "email = :acc";

  $sql = "SELECT $fields FROM `user` WHERE $where LIMIT 1";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':acc' => $account]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u || !password_verify($password, $u['password_hash'])) {
    json_err("BAD_CREDENTIALS", "invalid_credential", 401);
  }

  // สร้าง session
  $_SESSION['user_id'] = (int)$u['id'];
  $_SESSION['role']    = $u['role'] ?? 'user';

  // ไม่ส่ง password_hash กลับ
  unset($u['password_hash']);

  // ใช้ session_id() เป็น token (ให้ Flutter ใช้)
  $token = session_id();
  if ($token) {
    $u['token'] = $token;
  }

  // { ok: true, data: { ... } }
  json_ok($u);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
