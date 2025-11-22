<?php
// CORS + JSON
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

$method = $_SERVER["REQUEST_METHOD"];

if ($method === 'OPTIONS') {
  http_response_code(200);
  exit();
}

if (!in_array($method, ['PATCH','POST'], true)) {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'method_not_allowed']);
  exit;
}

/* ต่อ DB */
require_once __DIR__ . '/../db.php';
if (!isset($dbh) && isset($pdo)) {
  $dbh = $pdo;
}

/* ===== อ่าน body ===== */
$body = json_decode(file_get_contents("php://input"), true) ?: [];

/*
 * ===== หา user id =====
 * 1) ถ้ามีส่ง id มาจาก body -> ใช้อันนี้ (เหมาะกับ Flutter / Postman)
 * 2) ถ้าไม่มีก็ลองใช้ $_SESSION['user_id'] (เหมาะกับเว็บ PHP เดิม)
 */
$id = null;

if (isset($body['id']) && is_numeric($body['id'])) {
  $id = (int)$body['id'];
} elseif (!empty($_SESSION['user_id'])) {
  $id = (int)$_SESSION['user_id'];
}

if (!$id) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'invalid_or_missing_id']);
  exit;
}

try {
  // ===== ดึงข้อมูล user เดิม =====
  $q = $dbh->prepare("
      SELECT id, username, email, password_hash, role
      FROM user
      WHERE id = ?
      LIMIT 1
  ");
  $q->execute([$id]);
  $user = $q->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'user_not_found']);
    exit;
  }

  // ---- username (อาจจะส่งมาหรือไม่ก็ได้) ----
  $hasUsername = array_key_exists('username', $body);
  $username = $hasUsername ? trim($body['username']) : $user['username'];

  if ($hasUsername && $username === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'invalid_username']);
    exit;
  }

  // ---- email (อาจจะส่งมาหรือไม่ก็ได้) ----
  $hasEmail = array_key_exists('email', $body);
  $email = $hasEmail ? trim($body['email']) : $user['email'];

  if ($hasEmail) {
    if ($email === '') {
      http_response_code(400);
      echo json_encode(['status' => 'error', 'message' => 'invalid_email']);
      exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      http_response_code(400);
      echo json_encode(['status' => 'error', 'message' => 'invalid_email']);
      exit;
    }
  }

  // ---- password (เปลี่ยนได้ถ้าส่ง current + new มา) ----
  $currentPassword = $body['current_password'] ?? '';
  $newPassword     = $body['new_password']     ?? '';

  // ถ้าไม่มีอะไรจะเปลี่ยนเลย
  if (!$hasUsername && !$hasEmail && $newPassword === '') {
    echo json_encode([
      'status'  => 'ok',
      'message' => 'no_change'
    ]);
    exit;
  }

  // ===== เช็ค username / email ซ้ำกับ user อื่น =====
  $chk = $dbh->prepare("
    SELECT id, username, email
    FROM user
    WHERE (email = ? OR username = ?)
      AND id <> ?
  ");
  $chk->execute([$email, $username, $id]);
  $dup = $chk->fetch(PDO::FETCH_ASSOC);

  if ($dup) {
    if (strcasecmp($dup['email'], $email) === 0) {
      http_response_code(409);
      echo json_encode(['status' => 'error', 'message' => 'email_exists']);
      exit;
    }
    if (strcasecmp($dup['username'], $username) === 0) {
      http_response_code(409);
      echo json_encode(['status' => 'error', 'message' => 'username_exists']);
      exit;
    }
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'username_or_email_exists']);
    exit;
  }

  // ===== เตรียมฟิลด์ UPDATE =====
  $fields = [];
  $params = [];

  if ($username !== $user['username']) {
    $fields[]  = "username = ?";
    $params[]  = $username;
  }

  if ($email !== $user['email']) {
    $fields[]  = "email = ?";
    $params[]  = $email;
  }

  // ===== จัดการเปลี่ยนรหัสผ่าน =====
  if ($newPassword !== '') {
    if ($currentPassword === '') {
      http_response_code(400);
      echo json_encode([
        'status'  => 'error',
        'message' => 'current_password_required'
      ]);
      exit;
    }

    if (!password_verify($currentPassword, $user['password_hash'])) {
      http_response_code(400);
      echo json_encode([
        'status'  => 'error',
        'message' => 'current_password_incorrect'
      ]);
      exit;
    }

    $fields[] = "password_hash = ?";
    $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
  }

  if (empty($fields)) {
    echo json_encode([
      'status'  => 'ok',
      'message' => 'no_change'
    ]);
    exit;
  }

  // ===== UPDATE =====
  $params[] = $id;
  $sql = "UPDATE user SET " . implode(', ', $fields) . " WHERE id = ?";
  $stmt = $dbh->prepare($sql);
  $stmt->execute($params);

  echo json_encode([
    'status'  => 'ok',
    'message' => 'profile_updated',
    'data'    => [
      'id'       => (int)$user['id'],
      'username' => $username,
      'email'    => $email,
      'role'     => $user['role'],   // ไม่ให้แก้ role แต่ส่งกลับให้ดูได้
    ]
  ], JSON_UNESCAPED_UNICODE);

  $dbh = null;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'status'  => 'error',
    'message' => 'db_error'
  ]);
}
