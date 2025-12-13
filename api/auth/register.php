<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

/**
 * อ่าน body ให้รองรับทั้ง JSON และ form-urlencoded / form-data
 */
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body) || empty($body)) {
  $body = $_POST ?? [];
}

// ถ้า body เป็น { "data": {...} } หรือ { "user": {...} } → ดึงชั้นในมาใช้
if (is_array($body) && count($body) === 1 && is_array(reset($body))) {
  $body = reset($body);
}

/**
 * helper ดึงค่าจากหลาย key
 */
if (!function_exists('pick_value')) {
  function pick_value(array $src, array $keys): string {
    foreach ($keys as $k) {
      if (isset($src[$k]) && $src[$k] !== null && $src[$k] !== '') {
        return (string)$src[$k];
      }
    }
    return '';
  }
}

// รองรับได้ทั้ง username / name / user
$username = trim(pick_value($body, ['username', 'name', 'user']));
// รองรับ email / mail / email_address
$email    = strtolower(trim(pick_value($body, ['email', 'mail', 'email_address'])));
$password = (string)pick_value($body, ['password', 'pass', 'passwd', 'password1']);

// ==== ตรวจข้อมูล และบอกให้รู้ว่าขาดอะไร ====
$missing = [];
if ($username === '') $missing[] = 'username';
if ($email === '')    $missing[] = 'email';
if ($password === '') $missing[] = 'password';

if (!empty($missing)) {
  json_err(
    "VALIDATION_ERROR",
    "missing_fields: " . implode(',', $missing),
    422
  );
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_err("VALIDATION_ERROR", "invalid_email", 422);
}

try {
  // เช็คซ้ำ username / email
  $stmt = $dbh->prepare("
    SELECT id
    FROM `user`
    WHERE email = :email OR username = :username
    LIMIT 1
  ");
  $stmt->execute([
    ':email'    => $email,
    ':username' => $username,
  ]);
  $exists = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($exists) {
    json_err("DUPLICATE", "user_exists", 409);
  }

  // สร้าง user ใหม่
  $hash = password_hash($password, PASSWORD_DEFAULT);

  $stmt = $dbh->prepare("
    INSERT INTO `user` (username, email, password_hash, role)
    VALUES (:username, :email, :password_hash, 'user')
  ");
  $stmt->execute([
    ':username'      => $username,
    ':email'         => $email,
    ':password_hash' => $hash,
  ]);

  $uid = (int)$dbh->lastInsertId();

  // auto login ฝั่ง session
  $_SESSION['user_id'] = $uid;
  $_SESSION['role']    = 'user';

  // ใช้ session_id() เป็น token
  $token = session_id();

  json_ok([
    "id"       => $uid,
    "username" => $username,
    "email"    => $email,
    "role"     => "user",
    "token"    => $token,
  ]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
