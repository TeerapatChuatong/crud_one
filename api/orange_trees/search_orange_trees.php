<?php
// api/orange_trees/search_orange_trees.php
require_once __DIR__ . '/../db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$q        = trim($_GET['q'] ?? '');
$user_id  = trim($_GET['user_id'] ?? '');
$is_admin = is_admin();
$current  = (string)($_SESSION['user_id'] ?? '');

try {
  $sql    = "SELECT * FROM orange_trees";
  $where  = [];
  $params = [];

  if ($is_admin && $user_id !== '') {
    $where[]  = "user_id = ?";
    $params[] = $user_id;
  } else {
    $where[]  = "user_id = ?";
    $params[] = $current;
  }

  if ($q !== '') {
    $where[]  = "(tree_name LIKE ? OR location_in_farm LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY CAST(tree_id AS UNSIGNED) ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
