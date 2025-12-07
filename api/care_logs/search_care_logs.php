<?php
// api/care_logs/search_care_logs.php
require_once __DIR__ . '/../db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$current  = (string)($_SESSION['user_id'] ?? '');
$is_admin = is_admin();

$q        = trim($_GET['q'] ?? '');
$tree_id  = trim($_GET['tree_id'] ?? '');
$user_id  = trim($_GET['user_id'] ?? '');
$care_type = trim($_GET['care_type'] ?? '');

try {
  $sql    = "SELECT * FROM care_logs";
  $where  = [];
  $params = [];

  if ($is_admin && $user_id !== '') {
    $where[]  = "user_id = ?";
    $params[] = $user_id;
  } else {
    $where[]  = "user_id = ?";
    $params[] = $current;
  }

  if ($tree_id !== '') {
    $where[]  = "tree_id = ?";
    $params[] = $tree_id;
  }

  if ($care_type !== '') {
    $where[]  = "care_type = ?";
    $params[] = $care_type;
  }

  if ($q !== '') {
    $like    = "%{$q}%";
    $where[] = "(product_name LIKE ? OR area LIKE ? OR note LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY care_date DESC, log_id DESC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
