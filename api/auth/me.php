<?php
require_once __DIR__ . '/../db.php';

// ให้แน่ใจว่ามี session
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ต้องล็อกอินก่อนถึงจะเรียก /me ได้
// ถ้าไม่ได้ล็อกอิน ให้ฟังก์ชันนี้ตอบ 401 ออกไปเอง
if (!function_exists('require_login')) {
  // กันกรณี db.php รุ่นเก่าไม่มีฟังก์ชันนี้
  if (empty($_SESSION['user_id'])) {
    json_err("AUTH_ERROR", "not_logged_in", 401);
  }
} else {
  require_login();
}

try {
  // ดึง user_id จาก session
  $userId = $_SESSION['user_id'] ?? null;
  if (!$userId) {
    json_err("AUTH_ERROR", "not_logged_in", 401);
  }

  // ดึงข้อมูลผู้ใช้จากตาราง user
  // เลือกเฉพาะคอลัมน์ที่ปลอดภัย ไม่เอา password_hash
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

  // เพิ่ม token ให้เหมือน login/register (ใช้ session_id เป็น token ง่าย ๆ)
  $token = session_id();
  if ($token) {
    $u['token'] = $token;
  }

  // ส่งข้อมูลกลับในรูปแบบเดียวกับ endpoint อื่น
  // { "ok": true, "data": { ... } }
  json_ok($u);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
