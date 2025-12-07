<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id   = $body['treatment_id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR","invalid_treatment_id",400);
}

$disease_id    = array_key_exists('disease_id',$body)    ? $body['disease_id']    : null;
$risk_level_id = array_key_exists('risk_level_id',$body) ? $body['risk_level_id'] : null;
$advice_text   = array_key_exists('advice_text',$body)   ? trim((string)$body['advice_text']) : null;

$fields = [];
$params = [];

if ($disease_id !== null) {
  if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
  $fields[] = "disease_id=?";
  $params[] = (int)$disease_id;
}
if ($risk_level_id !== null) {
  if (!ctype_digit((string)$risk_level_id)) json_err("VALIDATION_ERROR","invalid_risk_level_id",400);
  $fields[] = "risk_level_id=?";
  $params[] = (int)$risk_level_id;
}
if ($advice_text !== null) {
  if ($advice_text === '') json_err("VALIDATION_ERROR","advice_text_required",400);
  $fields[] = "advice_text=?";
  $params[] = $advice_text;
}

if (!$fields) json_err("VALIDATION_ERROR","nothing_to_update",400);

$params[] = (int)$id;

try {
  $sql = "UPDATE treatments SET ".implode(',', $fields)." WHERE treatment_id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
