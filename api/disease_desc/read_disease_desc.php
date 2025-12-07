<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$info_id    = $_GET['info_id']    ?? null;
$disease_id = $_GET['disease_id'] ?? null;

try {
  if ($info_id !== null && $info_id !== '') {
    $st = $dbh->prepare("SELECT * FROM disease_desc WHERE info_id=?");
    $st->execute([$info_id]);
    $row = $st->fetch();
    if (!$row) json_err("NOT_FOUND","not_found",404);
    json_ok($row);
  }

  $where = [];
  $params = [];

  if ($disease_id !== null && $disease_id !== '') {
    $where[] = "disease_id=?";
    $params[] = $disease_id;
  }

  $sql = "SELECT * FROM disease_desc";
  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }
  $sql .= " ORDER BY disease_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll());
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
