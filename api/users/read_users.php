<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405); }

try {
  require_admin();

  $stmt = $dbh->prepare("SELECT user_id, username, email, role, created_at FROM `user` ORDER BY user_id ASC");
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $users = array_map(function($r){
    return [
      "user_id"   => (int)$r["user_id"],
      "id"        => (int)$r["user_id"], // เผื่อ frontend ยังใช้ id
      "username"  => $r["username"],
      "email"     => $r["email"] ?? null,
      "role"      => $r["role"] ?? null,
      "created_at"=> $r["created_at"] ?? null,
    ];
  }, $rows);

  json_ok($users);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
