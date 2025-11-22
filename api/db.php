<?php
// --- always JSON ---
header("Content-Type: application/json; charset=utf-8");

/* ================= CORS (จำเป็นเท่าที่ใช้) =================
   - อนุญาตเฉพาะต้นทางจาก Vite dev
   - สะท้อน Origin + เปิด Credentials (ใช้คุกกี้)
==============================================================*/
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [
  'http://localhost:5173',
  'http://127.0.0.1:5173',
];

if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
  header("Access-Control-Allow-Credentials: true");
} else if ($origin) {
  // origin แปลก → บล็อกชัดเจน (กันสับสนจาก * + credentials)
  header("Access-Control-Allow-Origin: null");
  header("Vary: Origin");
  http_response_code(403);
  echo json_encode(["ok"=>false,"error"=>"CORS_FORBIDDEN","message"=>"Origin not allowed"]);
  exit;
}

header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Max-Age: 86400");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ================= DB (PDO) — ค่าดีฟอลต์โลคอล ================= */
$DB_HOST = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: '127.0.0.1';
$DB_PORT = getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'mydbtest2';
$DB_USER = getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: getenv('MYSQLPASSWORD') ?: '';

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"error"=>"DB_CONNECT_FAIL"]);
  exit;
}

/* ================= Session (จำเป็นถ้าใช้คุกกี้) =============== */
$is_https = (
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);
session_set_cookie_params([
  'lifetime' => 60*60*24*7,
  'path'     => '/',
  'secure'   => $is_https,           // true เมื่อผ่าน HTTPS (เช่น ngrok)
  'httponly' => true,
  'samesite' => $is_https ? 'None' : 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) session_start();

/* ================= Helpers ================= */
if (!function_exists('json_ok')) {
    function json_ok($d = []) {
      echo json_encode(["ok"=>true, "data"=>$d], JSON_UNESCAPED_UNICODE);
      exit;
    }
}

if (!function_exists('json_err')) {
    function json_err($code, $msg='', $status=400) {
      http_response_code($status);
      echo json_encode(["ok"=>false, "error"=>$code, "message"=>$msg], JSON_UNESCAPED_UNICODE);
      exit;
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        if (empty($_SESSION['user_id'])) {
            json_err("UNAUTHORIZED", "Please login first", 401);
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin() {
        require_login();
        if (($_SESSION['role'] ?? '') !== 'admin' && ($_SESSION['role'] ?? '') !== 'super_admin') {
            json_err("FORBIDDEN", "Admin access required", 403);
        }
    }
}

/* alias ให้โค้ดเดิมที่อ้าง $dbh */
if (!isset($dbh)) $dbh = $pdo;
