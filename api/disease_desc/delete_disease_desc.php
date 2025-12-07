<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED","delete_only",405);
}

$info_id = $_GET['info_id'] ?? '';
if ($info_id === '') {
  json_err("VALIDATION_ERROR","info_id_required",400);
}

try {
  $st = $dbh->prepare("DELETE FROM disease_desc WHERE info_id=?");
  $st->execute([$info_id]);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
