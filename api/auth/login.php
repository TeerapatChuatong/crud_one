<?php
require_once __DIR__ . '/../db.php';

$body     = json_decode(file_get_contents('php://input'), true) ?: [];
$account  = strtolower(trim($body['account'] ?? $body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($account === '' || $password === '') {
  json_err("VALIDATION_ERROR","invalid_credential", 422);
}

try {
  // มี username ไหม?
  $hasUsername = false;
  $col = $dbh->query("SHOW COLUMNS FROM `user` LIKE 'username'")->fetch();
  if ($col) $hasUsername = true;

  // ดึงคอลัมน์ที่ต้องใช้
  $fields = "id, email, password_hash, role";
  if ($hasUsername) {
    $fields .= ", username";
  }

  $where  = $hasUsername
          ? "(username = :acc OR email = :acc)"
          : "email = :acc";

  $sql = "SELECT $fields FROM `user` WHERE $where LIMIT 1";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':acc' => $account]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u || !password_verify($password, $u['password_hash'])) {
    json_err("BAD_CREDENTIALS","invalid_credential", 401);
  }

  // ===== session ฝั่ง PHP (ไว้ใช้กับเว็บเดิม) =====
  $_SESSION['user_id'] = (int)$u['id'];
  $_SESSION['role']    = $u['role'] ?? 'user';

  // ไม่ส่ง password_hash กลับ
  unset($u['password_hash']);

  // ส่งข้อมูล user กลับ (ไม่มี token แล้ว)
  // json_ok จะส่ง { "ok": true, "data": { ... } }
  json_ok($u);

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error", 500);
}
