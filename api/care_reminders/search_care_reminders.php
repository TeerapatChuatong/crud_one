<?php
// api/care_reminders/search_care_reminders.php
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
$is_done  = trim($_GET['is_done'] ?? ''); // "", "0", "1"

try {
  $sql    = "SELECT * FROM care_reminders";
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

  if ($is_done !== '' && ($is_done === '0' || $is_done === '1')) {
    $where[]  = "is_done = ?";
    $params[] = (int)$is_done;
  }

  if ($q !== '') {
    $like    = "%{$q}%";
    $where[] = "(title LIKE ? OR description LIKE ?)";
    $params[] = $like;
    $params[] = $like;
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY remind_date ASC, reminder_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
