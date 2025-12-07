<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
  http_response_code(200); 
  exit(); 
}

/* รองรับทั้ง $pdo และ $dbh (กันกรณี db.php ใช้ $pdo) */
if (!isset($dbh) && isset($pdo)) { 
  $dbh = $pdo; 
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$username = trim($body['username'] ?? '');
$email    = trim($body['email'] ?? '');
$password = trim($body['password'] ?? '');
// Allow admin to set role, default to user
$role     = $body['role'] ?? 'user';

/* ตรวจข้อมูล */
if ($username === '' || $email === '' || $password === '') {
  json_err("VALIDATION_ERROR", "missing_fields", 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_err("VALIDATION_ERROR", "invalid_email", 400);
}
if (strlen($password) < 8) {
  json_err("VALIDATION_ERROR", "weak_password_min_8", 400);
}

try {
  // Admin only
  require_admin();

  if (!in_array($role, ['user', 'admin', 'super_admin'])) {
      json_err("VALIDATION_ERROR", "invalid_role", 400);
  }

  /* ===== เช็ค username / email ซ้ำ ===== */
  $chk = $dbh->prepare("
    SELECT id, username, email 
    FROM user 
    WHERE email = ? OR username = ?
  ");
  $chk->execute([$email, $username]);
  $row = $chk->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    // email ซ้ำ
    if (strcasecmp($row['email'], $email) === 0) {
      json_err("DUPLICATE", "email_exists", 409);
    }
    // username ซ้ำ
    if (strcasecmp($row['username'], $username) === 0) {
      json_err("DUPLICATE", "username_exists", 409);
    }
    // กันเผื่อเคสอื่น ๆ
    json_err("DUPLICATE", "username_or_email_exists", 409);
  }

  /* ===== สร้าง user ใหม่ ===== */
  $password_hash = password_hash($password, PASSWORD_BCRYPT);

  $stmt = $dbh->prepare("
    INSERT INTO user (username, email, password_hash, role)
    VALUES (?, ?, ?, ?)
  ");
  $ok = $stmt->execute([$username, $email, $password_hash, $role]);

  if ($ok) {
    http_response_code(201);
    json_ok([
        'id'       => (int)$dbh->lastInsertId(),
        'username' => $username,
        'email'    => $email,
        'role'     => $role,
    ]);
  } else {
    json_err("DB_ERROR", "insert_failed", 500);
  }
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}