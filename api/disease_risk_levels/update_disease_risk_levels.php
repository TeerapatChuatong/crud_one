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
  $fields[] = "days=?";
  $params[] = ($body['days'] === null || $body['days'] === '') ? null : (int)$body['days'];
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
