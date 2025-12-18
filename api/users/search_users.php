<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405); }

require_login();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role_norm = role_norm($_SESSION['role'] ?? 'user');
$is_admin  = in_array($role_norm, ['admin','super_admin'], true);

$q = trim($_GET['q'] ?? '');

try {
  if ($is_admin) {
    if ($q !== '') {
      $like = "%{$q}%";
      $stmt = $dbh->prepare("
        SELECT user_id, username, email, role, created_at
        FROM `user`
        WHERE username LIKE ? OR email LIKE ?
        ORDER BY user_id DESC
        LIMIT 200
      ");
      $stmt->execute([$like, $like]);
    } else {
      $stmt = $dbh->prepare("
        SELECT user_id, username, email, role, created_at
        FROM `user`
        ORDER BY user_id DESC
        LIMIT 200
      ");
      $stmt->execute();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = array_map(fn($r) => [
      "user_id"   => (int)$r["user_id"],
      "id"        => (int)$r["user_id"],
      "username"  => $r["username"],
      "email"     => $r["email"],
      "role"      => $r["role"],
      "created_at"=> $r["created_at"] ?? null,
    ], $rows);

    json_ok($data);
  } else {
    $stmt = $dbh->prepare("SELECT user_id, username, email, role, created_at FROM `user` WHERE user_id = ? LIMIT 1");
    $stmt->execute([$current_user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) json_ok([]);

    json_ok([[
      "user_id"   => (int)$row["user_id"],
      "id"        => (int)$row["user_id"],
      "username"  => $row["username"],
      "email"     => $row["email"],
      "role"      => $row["role"],
      "created_at"=> $row["created_at"] ?? null,
    ]]);
  }
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
