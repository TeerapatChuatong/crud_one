<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED","delete_only",405);
}

$disease_id = trim($_GET['disease_id'] ?? '');
if ($disease_id === '') {
  json_err("VALIDATION_ERROR","invalid_disease_id",400);
}

try {
  $st = $dbh->prepare("DELETE FROM diseases WHERE disease_id = ?");
  $st->execute([$disease_id]);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
