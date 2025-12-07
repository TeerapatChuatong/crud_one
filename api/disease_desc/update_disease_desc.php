<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body   = json_decode(file_get_contents("php://input"), true) ?: [];
$info_id = trim($body['info_id'] ?? '');

if ($info_id === '') {
  json_err("VALIDATION_ERROR","info_id_required",400);
}

$fields = [];
$params = [];

if (array_key_exists('disease_id',$body)) {
  $disease_id = trim($body['disease_id'] ?? '');
  if ($disease_id === '') json_err("VALIDATION_ERROR","disease_id_required",400);
  $fields[] = "disease_id=?";
  $params[] = $disease_id;
}
if (array_key_exists('description',$body)) {
  $fields[] = "description=?";
  $params[] = trim($body['description'] ?? '') ?: null;
}
if (array_key_exists('causes',$body)) {
  $fields[] = "causes=?";
  $params[] = trim($body['causes'] ?? '') ?: null;
}
if (array_key_exists('symptoms',$body)) {
  $fields[] = "symptoms=?";
  $params[] = trim($body['symptoms'] ?? '') ?: null;
}

if (!$fields) {
  json_err("VALIDATION_ERROR","nothing_to_update",400);
}

$params[] = $info_id;

try {
  $sql = "UPDATE disease_desc SET ".implode(',', $fields)." WHERE info_id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
