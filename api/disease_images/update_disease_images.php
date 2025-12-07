<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$image_id = $body['image_id'] ?? null;

if (!$image_id || !ctype_digit((string)$image_id)) {
  json_err("VALIDATION_ERROR","invalid_image_id",400);
}

$fields = [];
$params = [];

if (array_key_exists('disease_id',$body)) {
  $disease_id = trim($body['disease_id'] ?? '');
  if ($disease_id === '') json_err("VALIDATION_ERROR","disease_id_required",400);
  $fields[] = "disease_id=?";
  $params[] = $disease_id;
}
if (array_key_exists('image_url',$body)) {
  $image_url = trim($body['image_url'] ?? '');
  if ($image_url === '') json_err("VALIDATION_ERROR","image_url_required",400);
  $fields[] = "image_url=?";
  $params[] = $image_url;
}
if (array_key_exists('description',$body)) {
  $fields[] = "description=?";
  $params[] = trim($body['description'] ?? '') ?: null;
}
if (array_key_exists('is_default',$body)) {
  $is_default = $body['is_default'] ? 1 : 0;
  $fields[] = "is_default=?";
  $params[] = $is_default;
}

if (!$fields) {
  json_err("VALIDATION_ERROR","nothing_to_update",400);
}

$params[] = (int)$image_id;

try {
  $sql = "UPDATE disease_images SET ".implode(',', $fields)." WHERE image_id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
