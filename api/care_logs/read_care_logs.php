<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$log_id   = $_GET['log_id'] ?? null;
$user_id  = $_GET['user_id'] ?? null;
$tree_id  = $_GET['tree_id'] ?? null;
$care_type = $_GET['care_type'] ?? null;

try {
  if ($log_id !== null && $log_id !== '') {
    if (!ctype_digit((string)$log_id)) {
      json_err("VALIDATION_ERROR","invalid_log_id",400);
    }
    $st = $dbh->prepare("SELECT * FROM care_logs WHERE log_id=?");
    $st->execute([(int)$log_id]);
    $row = $st->fetch();
    if (!$row) json_err("NOT_FOUND","not_found",404);
    json_ok($row);
  }

  $where = [];
  $params = [];

  if ($user_id !== null && $user_id !== '') {
    $where[]  = "user_id=?";
    $params[] = $user_id;
  }
  if ($tree_id !== null && $tree_id !== '') {
    $where[]  = "tree_id=?";
    $params[] = $tree_id;
  }
  if ($care_type !== null && $care_type !== '') {
    $where[]  = "care_type=?";
    $params[] = $care_type;
  }

  $sql = "SELECT * FROM care_logs";
  if ($where) {
    $sql .= " WHERE ".implode(" AND ",$where);
  }
  $sql .= " ORDER BY care_date DESC, log_id DESC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll());
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
