<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED","post_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$disease_id  = trim($body['disease_id'] ?? '');
$image_url   = trim($body['image_url'] ?? '');
$description = trim($body['description'] ?? '');
$is_default  = $body['is_default'] ?? 0;

if ($disease_id === '') json_err("VALIDATION_ERROR","disease_id_required",400);
if ($image_url  === '') json_err("VALIDATION_ERROR","image_url_required",400);

$is_default = $is_default ? 1 : 0;

try {
  $st = $dbh->prepare("
    INSERT INTO disease_images(disease_id,image_url,description,is_default)
    VALUES (?,?,?,?)
  ");
  $st->execute([
    $disease_id,
    $image_url,
    $description ?: null,
    $is_default,
  ]);

  json_ok(["image_id" => (int)$dbh->lastInsertId()]);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
