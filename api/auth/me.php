<?php
require_once __DIR__ . '/require_auth.php';

try {
  // require_auth.php ใส่ $AUTH_USER_ID ไว้แล้ว
  $userId = $AUTH_USER_ID ?? ($_SESSION['user_id'] ?? null);
  if (!$userId) {
    json_err("AUTH_ERROR", "not_logged_in", 401);
  }

  $stmt = $dbh->prepare("
    SELECT
      id,
      username,
      email,
      role,
      created_at
    FROM `user`
    WHERE id = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $userId]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u) {
    json_err("NOT_FOUND", "user_not_found", 404);
  }

  // ส่ง token กลับ (session_id) เผื่อ Flutter ใช้ต่อ
  $token = session_id();
  if ($token) {
    $u['token'] = $token;
  }

  json_ok($u);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
