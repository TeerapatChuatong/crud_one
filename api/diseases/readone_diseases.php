<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$disease_id = trim($_GET['disease_id'] ?? '');
if ($disease_id === '') {
  json_err("VALIDATION_ERROR","invalid_disease_id",400);
}

try {
  $st = $dbh->prepare("SELECT * FROM diseases WHERE disease_id = ?");
  $st->execute([$disease_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_err("NOT_FOUND","not_found",404);
  }

  json_ok($row);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
