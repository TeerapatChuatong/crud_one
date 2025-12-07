<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$reminder_id = $_GET['reminder_id'] ?? null;
$user_id     = $_GET['user_id'] ?? null;
$tree_id     = $_GET['tree_id'] ?? null;
$is_done     = $_GET['is_done'] ?? null;

try {
  if ($reminder_id !== null && $reminder_id !== '') {
    if (!ctype_digit((string)$reminder_id)) {
      json_err("VALIDATION_ERROR","invalid_reminder_id",400);
    }
    $st = $dbh->prepare("SELECT * FROM care_reminders WHERE reminder_id=?");
    $st->execute([(int)$reminder_id]);
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
  if ($is_done !== null && $is_done !== '') {
    $where[]  = "is_done=?";
    $params[] = ((int)$is_done ? 1 : 0);
  }

  $sql = "SELECT * FROM care_reminders";
  if ($where) {
    $sql .= " WHERE ".implode(" AND ",$where);
  }
  $sql .= " ORDER BY remind_date ASC, reminder_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll());
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
