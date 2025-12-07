<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$session_id = $_GET['session_id'] ?? null;
$user_id    = $_GET['user_id'] ?? null;
$status     = $_GET['status'] ?? null;

try {
  if ($session_id !== null && $session_id !== '') {
    $st = $dbh->prepare("SELECT * FROM diagnosis_sessions WHERE session_id=?");
    $st->execute([$session_id]);
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
  if ($status !== null && $status !== '') {
    $where[]  = "status=?";
    $params[] = $status;
  }

  $sql = "SELECT * FROM diagnosis_sessions";
  if ($where) {
    $sql .= " WHERE ".implode(" AND ",$where);
  }
  $sql .= " ORDER BY created_at DESC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll());
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
