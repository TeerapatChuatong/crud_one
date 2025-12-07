<?php
// api/diagnosis_history/search_diagnosis_history.php
require_once __DIR__ . '/../db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$current  = (string)($_SESSION['user_id'] ?? '');
$is_admin = is_admin();

$disease_id = trim($_GET['disease_id'] ?? '');
$risk_level = trim($_GET['risk_level_code'] ?? '');
$user_id    = trim($_GET['user_id'] ?? '');

try {
  $sql    = "SELECT * FROM diagnosis_history";
  $where  = [];
  $params = [];

  if ($is_admin && $user_id !== '') {
    $where[]  = "user_id = ?";
    $params[] = $user_id;
  } else {
    $where[]  = "user_id = ?";
    $params[] = $current;
  }

  if ($disease_id !== '') {
    $where[]  = "final_disease_id = ?";
    $params[] = $disease_id;
  }

  if ($risk_level !== '') {
    $where[]  = "risk_level_code = ?";
    $params[] = $risk_level;
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY created_at DESC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
