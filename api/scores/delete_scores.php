<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED","delete_only",405);
}

$score_id = $_GET['score_id'] ?? ($_GET['id'] ?? null);

try {
  if ($score_id === null || !ctype_digit((string)$score_id)) {
    json_err("VALIDATION_ERROR","invalid_score_id",400);
  }

  $st = $dbh->prepare("DELETE FROM scores WHERE score_id=?");
  $st->execute([(int)$score_id]);

  if ($st->rowCount() === 0) json_err("NOT_FOUND","score_not_found",404);
  json_ok(true);

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
