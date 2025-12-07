<?php
require_once __DIR__ . '/../db.php';

// ให้แน่ใจว่ามี session (เผื่อ db.php ยังไม่ได้เรียก)
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/**
 * อ่าน body ให้รองรับทั้ง JSON และ form-urlencoded / form-data
 */
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

// ถ้า decode JSON ไม่ได้ หรือ body ว่าง -> ลองใช้ $_POST (กรณีส่งแบบ form-data / x-www-form-urlencoded)
if (!is_array($body) || empty($body)) {
  $body = $_POST ?? [];
}

// ถ้า body เป็นรูปแบบ { "data": { ... } } หรือ { "user": { ... } } ให้ดึงชั้นในออกมา
if (is_array($body) && count($body) === 1 && is_array(reset($body))) {
  $body = reset($body);
}

/**
 * helper ดึงค่าจากหลายชื่อ key
 */
function pick_value(array $src, array $keys): string {
  foreach ($keys as $k) {
    if (isset($src[$k]) && $src[$k] !== null && $src[$k] !== '') {
      return (string)$src[$k];
    }
  }
  return '';
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

  // auto login ฝั่ง session (ถ้าเว็บใช้)
  $_SESSION['user_id'] = $uid;
  $_SESSION['role']    = 'user';

  // ✅ สร้าง token ให้เหมือน login.php (ใช้ random string)
  $token = bin2hex(random_bytes(32));

  // ส่งผลลัพธ์กลับ (มี token ด้วย เพื่อให้ Flutter ใช้งานได้)
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
