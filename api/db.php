<?php
// --- always JSON ---
// กัน warning/notice หลุดไปปน response
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header("Content-Type: application/json; charset=utf-8");

/* ================= CORS (จำเป็นเท่าที่ใช้) =================
   - อนุญาตเฉพาะต้นทางจาก Vite dev
   - สะท้อน Origin + เปิด Credentials (ใช้คุกกี้)
==============================================================*/
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [
  'http://localhost:5173',
  'http://127.0.0.1:5173',
  'http://localhost:5174',
];

if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
  header("Access-Control-Allow-Credentials: true");
} else if ($origin) {
  header("Access-Control-Allow-Origin: null");
  header("Vary: Origin");
  http_response_code(403);
  echo json_encode(["ok"=>false,"error"=>"CORS_FORBIDDEN","message"=>"Origin not allowed"], JSON_UNESCAPED_UNICODE);
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

// ✅ เพิ่ม mysqli ($conn) เพื่อไม่ให้หน้า/ไฟล์เดิมพัง (บางไฟล์ยังใช้ $conn)
if (!isset($conn)) {
  $conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, (int)$DB_PORT);
  if ($conn->connect_errno) {
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"DB_CONNECT_FAIL","message"=>"mysqli connect failed"], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $conn->set_charset('utf8mb4');
}

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
  echo json_encode(["ok"=>false,"error"=>"DB_CONNECT_FAIL"], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ================= Session (จำเป็นถ้าใช้คุกกี้) =============== */
$is_https = (
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

// ✅ FIX: เรียก session_set_cookie_params เฉพาะตอน session ยังไม่ active
if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params([
    'lifetime' => 60*60*24*7,
    'path'     => '/',
    'secure'   => $is_https,
    'httponly' => true,
    'samesite' => $is_https ? 'None' : 'Lax',
  ]);
}

// ✅ NEW: รองรับ Authorization: Bearer <token> ให้ใช้เป็น session_id ได้
function _bearer_token() {
  $raw = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? '');
  if (!$raw && function_exists('apache_request_headers')) {
    $h = apache_request_headers();
    if (isset($h['Authorization'])) $raw = $h['Authorization'];
  }
  if ($raw && preg_match('/Bearer\s+(\S+)/i', $raw, $m)) return $m[1];
  return null;
}

$token = _bearer_token();
if ($token) {
  $token = substr($token, 0, 128);
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  session_id($token);
}

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
    // ✅ ป้องกัน Fatal: บางที่อาจส่ง array มาเป็น $msg หรือ $status
    $extra = [];
    if (is_array($msg)) {
      $extra = $msg;
      $msg = '';
    }
    if (is_array($status)) {
      $extra = array_merge($extra, $status);
      $status = 400;
    }
    if (!is_int($status)) {
      $status = is_numeric($status) ? (int)$status : 400;
    }

    http_response_code($status);
    echo json_encode(["ok"=>false, "error"=>$code, "message"=>$msg] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if (!function_exists('role_norm')) {
  function role_norm($role_raw) {
    return str_replace(' ', '_', strtolower($role_raw ?? 'user'));
  }
}

if (!function_exists('require_login')) {
  function require_login() {
    if (empty($_SESSION['user_id'])) {
      json_err("UNAUTHORIZED", "Please login first", 401);
    }
  }
}

if (!function_exists('is_admin')) {
  function is_admin(): bool {
    $r = role_norm($_SESSION['role'] ?? 'user');
    return in_array($r, ['admin', 'super_admin'], true);
  }
}

if (!function_exists('require_admin')) {
  function require_admin() {
    require_login();
    if (!is_admin()) {
      json_err("FORBIDDEN", "Admin access required", 403);
    }
  }
}

if (!isset($dbh)) $dbh = $pdo;
