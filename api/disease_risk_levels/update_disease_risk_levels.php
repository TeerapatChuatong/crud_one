<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id = $body['risk_level_id'] ?? ($body['id'] ?? null);

if (!$id || !ctype_digit((string)$id)) json_err("VALIDATION_ERROR","invalid_id",400);

$fields = [];
$params = [];

if (array_key_exists('disease_id', $body)) {
  if (!$body['disease_id'] || !ctype_digit((string)$body['disease_id'])) json_err("VALIDATION_ERROR","invalid_disease_id",400);
  $fields[] = "disease_id=?";
  $params[] = (int)$body['disease_id'];
}

if (array_key_exists('level_code', $body)) {
  $lc = trim((string)$body['level_code']);
  if ($lc === '') json_err("VALIDATION_ERROR","invalid_level_code",400);
  $fields[] = "level_code=?";
  $params[] = $lc;
}

if (array_key_exists('min_score', $body)) {
  if (!is_numeric($body['min_score'])) json_err("VALIDATION_ERROR","invalid_min_score",400);
  $fields[] = "min_score=?";
  $params[] = (int)$body['min_score'];
}

if (array_key_exists('days', $body)) {
  $v = $body['days'];
  if ($v === null || $v === '') $v = 0;
  if (!is_numeric($v) || (int)$v < 0 || (int)$v > 365) json_err("VALIDATION_ERROR","invalid_days",400);
  $fields[] = "days=?";
  $params[] = (int)$v;
}

if (array_key_exists('times', $body)) {
  $v = $body['times'];
  if ($v === null || $v === '') $v = 0;
  if (!is_numeric($v) || (int)$v < 0 || (int)$v > 99) json_err("VALIDATION_ERROR","invalid_times",400);
  $fields[] = "times=?";
  $params[] = (int)$v;
}

if (array_key_exists('sprays_per_product', $body)) {
  $v = $body['sprays_per_product'];
  if ($v === null || $v === '') $v = 2;
  if (!is_numeric($v) || (int)$v < 1 || (int)$v > 20) json_err("VALIDATION_ERROR","invalid_sprays_per_product",400);
  $fields[] = "sprays_per_product=?";
  $params[] = (int)$v;
}

if (array_key_exists('max_products_per_group', $body)) {
  $v = $body['max_products_per_group'];
  if ($v === null || $v === '') $v = 2;
  if (!is_numeric($v) || (int)$v < 1 || (int)$v > 20) json_err("VALIDATION_ERROR","invalid_max_products_per_group",400);
  $fields[] = "max_products_per_group=?";
  $params[] = (int)$v;
}

/* ✅ เพิ่มส่วนนี้ */
if (array_key_exists('max_sprays_per_group', $body)) {
  $v = $body['max_sprays_per_group'];
  if ($v === null || $v === '') $v = 2;
  if (!is_numeric($v) || (int)$v < 1 || (int)$v > 20) json_err("VALIDATION_ERROR","invalid_max_sprays_per_group",400);
  $fields[] = "max_sprays_per_group=?";
  $params[] = (int)$v;
}

if (!$fields) json_err("VALIDATION_ERROR","nothing_to_update",400);

$params[] = (int)$id;

try {
  $sql = "UPDATE disease_risk_levels SET ".implode(", ", $fields)." WHERE risk_level_id=?";
  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
