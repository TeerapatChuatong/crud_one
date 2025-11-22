<?php
require_once __DIR__ . '/../db.php';

// Preflight handled in db.php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

/* Method guard: อนุญาตเฉพาะ GET */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "Method not allowed", 405);
}

try {
  // ต้องเป็น admin ถึงจะดู user ทั้งหมดได้
  require_admin();

  $users = [];

  // ดึงข้อมูลจากตาราง user
  foreach ($dbh->query('SELECT * FROM user') as $row) {
    $users[] = [
      'id'        => (int)$row['id'],
      'username'  => $row['username'],
      'email'     => $row['email'] ?? null,
      'role'      => $row['role'] ?? null,
    ];
  }

  json_ok($users);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
