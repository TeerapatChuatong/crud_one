<?php
require_once __DIR__ . '/../db.php';

/* Preflight */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit();
}

/* Method guard */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

/* ต้อง login ก่อน */
require_login();

/* role normalize */
$role_raw  = $_SESSION['role'] ?? 'user';
$role_norm = str_replace(' ', '_', strtolower($role_raw));
$is_admin  = in_array($role_norm, ['admin', 'super_admin'], true);

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

$q = trim($_GET['q'] ?? '');

try {
  if ($is_admin) {
    // ===== admin: search all users =====
    if ($q !== '') {
      $like = "%{$q}%";
      $stmt = $dbh->prepare("
        SELECT id, username, email, role
        FROM user
        WHERE username LIKE ? OR email LIKE ?
        ORDER BY id DESC
        LIMIT 200
      ");
      $stmt->execute([$like, $like]);
    } else {
      $stmt = $dbh->prepare("
        SELECT id, username, email, role
        FROM user
        ORDER BY id DESC
        LIMIT 200
      ");
      $stmt->execute();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = array_map(fn($r) => [
      "id"       => (int)$r["id"],
      "username" => $r["username"],
      "email"    => $r["email"],
      "role"     => $r["role"],
    ], $rows);

    json_ok($data);

  } else {
    // ===== user: return only self =====
    $stmt = $dbh->prepare("
      SELECT id, username, email, role
      FROM user
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->execute([$current_user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      json_ok([]); // ไม่ควรเกิด แต่กันไว้
    }

    json_ok([[
      "id"       => (int)$row["id"],
      "username" => $row["username"],
      "email"    => $row["email"],
      "role"     => $row["role"],
    ]]);
  }

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
