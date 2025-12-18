<?php
// crud/api/auth/require_auth.php

function require_auth() {
  // 1) Authorization: Bearer <token>
  $rawAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? '');

  if (!$rawAuth && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) $rawAuth = $headers['Authorization'];
  }

  $token = null;
  if ($rawAuth && preg_match('/Bearer\s+(\S+)/i', $rawAuth, $m)) {
    $token = $m[1];
  }

  // 2) token -> session_id
  if ($token) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $token = substr($token, 0, 128);
    session_id($token);
  }

  if (session_status() === PHP_SESSION_NONE) session_start();

  if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => false,
      'error' => 'UNAUTHORIZED',
      'message' => 'กรุณาเข้าสู่ระบบก่อน'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  return [
    'id'       => (int)($_SESSION['user_id'] ?? 0),
    'role'     => (string)($_SESSION['role'] ?? 'user'),
    'username' => (string)($_SESSION['username'] ?? ''),
    'email'    => (string)($_SESSION['email'] ?? ''),
  ];
}

// ✅ ตั้งตัวแปร global ให้ไฟล์อื่นใช้ได้ด้วย
$AUTH_USER = require_auth();
$AUTH_USER_ID = $AUTH_USER['id'];

return $AUTH_USER;
