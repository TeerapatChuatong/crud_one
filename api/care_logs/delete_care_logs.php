<?php
require_once __DIR__ . '/../db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED","delete_only",405);
}

$log_id = $_GET['log_id'] ?? null;
if (!$log_id || !ctype_digit((string)$log_id)) {
  json_err("VALIDATION_ERROR","invalid_log_id",400);
}

try {
  $st = $dbh->prepare("DELETE FROM care_logs WHERE log_id=?");
  $st->execute([(int)$log_id]);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
