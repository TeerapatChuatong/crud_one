<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED","delete_only",405);
}

$session_id = $_GET['session_id'] ?? '';
if ($session_id === '') {
  json_err("VALIDATION_ERROR","session_id_required",400);
}

try {
  $st = $dbh->prepare("DELETE FROM diagnosis_sessions WHERE session_id=?");
  $st->execute([$session_id]);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
