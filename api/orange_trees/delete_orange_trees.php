<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED","delete_only",405);
}

$tree_id = $_GET['tree_id'] ?? '';
if ($tree_id === '') {
  json_err("VALIDATION_ERROR","tree_id_required",400);
}

try {
  $st = $dbh->prepare("DELETE FROM orange_trees WHERE tree_id=?");
  $st->execute([$tree_id]);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
