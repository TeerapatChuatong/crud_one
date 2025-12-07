<?php
require_once __DIR__ . '/../db.php';

$method = $_SERVER["REQUEST_METHOD"];

if ($method === 'OPTIONS') {
  http_response_code(200);
  exit();
}

if (!in_array($method, ['PATCH','POST'], true)) {
  json_err("METHOD_NOT_ALLOWED", "Method not allowed", 405);
}

/* รับข้อมูล */
$body = json_decode(file_get_contents("php://input"), true) ?: [];

$id       = $body['id']       ?? null;
$username = trim($body['username'] ?? '');
$email    = trim($body['email']    ?? '');
// $password = trim($body['password'] ?? ''); 

/* ตรวจข้อมูลพื้นฐาน */
if ($id === null || !is_numeric($id)) {
  json_err("VALIDATION_ERROR", "invalid_or_missing_id", 400);
}
if ($username === '' || $email === '') {
  json_err("VALIDATION_ERROR", "missing_fields", 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_err("VALIDATION_ERROR", "invalid_email", 400);
}

try {
  // ต้อง login ก่อน
  require_login();

  // ===== ดึงข้อมูลเก่า =====
  $q = $dbh->prepare("SELECT role FROM user WHERE id = ?");
  $q->execute([(int)$id]);
  $old = $q->fetch(PDO::FETCH_ASSOC);
  if (!$old) {
    json_err("NOT_FOUND", "user_not_found", 404);
  }

  // Check permission:
  // 1. Admin can update anyone.
  // 2. User can only update themselves.
  $current_user_id = $_SESSION['user_id'];
  $current_role    = $_SESSION['role'] ?? 'user';

  if ($current_role !== 'admin' && $current_role !== 'super_admin') {
      if ((int)$id !== (int)$current_user_id) {
          json_err("FORBIDDEN", "You can only update your own profile", 403);
      }
  }

  // ถ้าส่ง role มาให้ใช้ที่ส่งมา, ถ้าไม่ส่งให้ใช้ค่าเดิม
  // แต่ user ธรรมดาห้ามเปลี่ยน role ตัวเอง
  if (array_key_exists('role', $body)) {
    $role = trim($body['role']);
    if (!in_array($role, ['super_admin','admin','user'], true)) {
      json_err("VALIDATION_ERROR", "invalid_role", 400);
    }
    
    // Only admin can change role
    if ($current_role !== 'admin' && $current_role !== 'super_admin') {
        if ($role !== $old['role']) {
             json_err("FORBIDDEN", "You cannot change your own role", 403);
        }
    }
  } else {
    $role = $old['role']; // ใช้ role เดิม
  }

  // ===== เช็ค username / email ซ้ำกับ user อื่น =====
  $chk = $dbh->prepare("
    SELECT id, username, email
    FROM user
    WHERE (email = ? OR username = ?)
      AND id <> ?
  ");
  $chk->execute([$email, $username, (int)$id]);
  $dup = $chk->fetch(PDO::FETCH_ASSOC);

  if ($dup) {
    if (strcasecmp($dup['email'], $email) === 0) {
      json_err("DUPLICATE", "email_exists", 409);
    }
    if (strcasecmp($dup['username'], $username) === 0) {
      json_err("DUPLICATE", "username_exists", 409);
    }
    json_err("DUPLICATE", "username_or_email_exists", 409);
  }

  // ===== UPDATE =====
  $stmt = $dbh->prepare("
    UPDATE user
    SET username = ?, email = ?, role = ?
    WHERE id = ?
  ");
  $ok = $stmt->execute([
    $username,
    $email,
    $role,
    (int)$id
  ]);

  json_ok(['status' => 'ok']);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
