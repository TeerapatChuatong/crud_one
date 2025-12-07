<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$image_id   = $_GET['image_id']   ?? null;
$disease_id = $_GET['disease_id'] ?? null;
$only_default = $_GET['only_default'] ?? null;

try {
  if ($image_id !== null && $image_id !== '') {
    if (!ctype_digit((string)$image_id)) {
      json_err("VALIDATION_ERROR","invalid_image_id",400);
    }
    $st = $dbh->prepare("SELECT * FROM disease_images WHERE image_id=?");
    $st->execute([(int)$image_id]);
    $row = $st->fetch();
    if (!$row) json_err("NOT_FOUND","not_found",404);
    json_ok($row);
  }

  $where = [];
  $params = [];

  if ($disease_id !== null && $disease_id !== '') {
    $where[]  = "disease_id=?";
    $params[] = $disease_id;
  }

  if ($only_default === '1') {
    $where[] = "is_default=1";
  }

  $sql = "SELECT * FROM disease_images";
  if ($where) {
    $sql .= " WHERE ".implode(" AND ",$where);
  }
  $sql .= " ORDER BY created_at DESC, image_id DESC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll());
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
