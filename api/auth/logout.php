<?php
// ดึง Authorization header (ถ้ามี) เพื่อใช้ token เป็น session_id
$rawAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? '');

if (!$rawAuth && function_exists('apache_request_headers')) {
  $headers = apache_request_headers();
  if (isset($headers['Authorization'])) $rawAuth = $headers['Authorization'];
}

$token = null;
if ($rawAuth && preg_match('/Bearer\s+(\S+)/i', $rawAuth, $m)) {
  $token = $m[1];
}

if ($token && session_status() === PHP_SESSION_NONE) {
  $token = substr($token, 0, 128);
  session_id($token);
}

require_once __DIR__ . '/../db.php';

try {
  if (session_status() === PHP_SESSION_NONE) session_start();

  $_SESSION = [];

  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params["path"], $params["domain"], $params["secure"], $params["httponly"]
    );
  }

  session_destroy();
  json_ok(["message" => "logged_out"]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
