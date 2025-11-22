<?php
require_once __DIR__ . '/../db.php';

// ไม่ใช้ token / auth_sessions อีกต่อไป
// ทำแค่เคลียร์ session ให้ผู้ใช้ล็อกเอาต์

try {
  // ล้างตัวแปรใน session
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

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

  // ตอบกลับว่า logout แล้ว
  json_ok(["message" => "logged_out"]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
