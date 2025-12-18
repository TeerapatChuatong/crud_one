<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED","delete_only",405);
}

$id = $_GET['choice_id'] ?? null;
if ($id === null || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR","invalid_choice_id",400);
}

try {
  $st = $dbh->prepare("DELETE FROM choices WHERE choice_id=?");
  $st->execute([(int)$id]);

  if ($st->rowCount() === 0) json_err("NOT_FOUND", "choice_not_found", 404);

  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
