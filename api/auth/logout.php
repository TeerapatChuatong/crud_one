<?php
require_once __DIR__ . '/../db.php'; 
// db.php ยังจำเป็น เพราะเราต้องใช้ json_ok / json_err และ CORS header ต่าง ๆ

try {
  // ให้แน่ใจว่าเริ่ม session แล้วค่อยไปล้าง
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

  // ล้างตัวแปรใน session
  $_SESSION = [];

  // ถ้ามี cookie session ให้ลบทิ้งด้วย (กันเหนียว)
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params["path"],
      $params["domain"],
      $params["secure"],
      $params["httponly"]
    );
  }

  // ทำลาย session
  session_destroy();

  // ✅ ตอบกลับว่า logout แล้ว (โครงสร้างเหมือน endpoint อื่น: { ok: true, data: {...} })
  json_ok([
    "message" => "logged_out"
  ]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
