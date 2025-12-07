<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id   = $body['id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR","invalid_id",400);
}

$fields = [];
$params = [];

$allowed_levels = ['low','medium','high'];

if (array_key_exists('disease_id',$body)) {
  $disease_id = $body['disease_id'] ?? null;
  if (!$disease_id || !ctype_digit((string)$disease_id)) {
    json_err("VALIDATION_ERROR","invalid_disease_id",400);
  }
  $fields[] = "disease_id=?";
  $params[] = (int)$disease_id;
}
if (array_key_exists('level_code',$body)) {
  $level_code = trim($body['level_code'] ?? '');
  if (!in_array($level_code, $allowed_levels, true)) {
    json_err("VALIDATION_ERROR","invalid_level_code",400);
  }
  $fields[] = "level_code=?";
  $params[] = $level_code;
}
if (array_key_exists('min_score',$body)) {
  $min_score = $body['min_score'] ?? null;
  if ($min_score === null || !ctype_digit((string)$min_score)) {
    json_err("VALIDATION_ERROR","invalid_min_score",400);
  }
  $fields[] = "min_score=?";
  $params[] = (int)$min_score;
}

if (!$fields) json_err("VALIDATION_ERROR","nothing_to_update",400);

$params[] = (int)$id;

try {
  $sql = "UPDATE disease_risk_levels SET ".implode(',', $fields)." WHERE id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
