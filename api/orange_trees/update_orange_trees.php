<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body    = json_decode(file_get_contents("php://input"), true) ?: [];
$tree_id = trim($body['tree_id'] ?? '');

if ($tree_id === '') {
  json_err("VALIDATION_ERROR","tree_id_required",400);
}

$fields = [];
$params = [];

if (array_key_exists('user_id',$body)) {
  $user_id = trim($body['user_id'] ?? '');
  if ($user_id === '') json_err("VALIDATION_ERROR","user_id_required",400);
  $fields[] = "user_id=?";
  $params[] = $user_id;
}
if (array_key_exists('tree_name',$body)) {
  $tree_name = trim($body['tree_name'] ?? '');
  if ($tree_name === '') json_err("VALIDATION_ERROR","tree_name_required",400);
  $fields[] = "tree_name=?";
  $params[] = $tree_name;
}
if (array_key_exists('location_in_farm',$body)) {
  $fields[] = "location_in_farm=?";
  $params[] = trim($body['location_in_farm'] ?? '') ?: null;
}

if (!$fields) json_err("VALIDATION_ERROR","nothing_to_update",400);

$params[] = $tree_id;

try {
  $sql = "UPDATE orange_trees SET ".implode(',', $fields)." WHERE tree_id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
