<?php
require_once __DIR__ . '/../db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$log_id = $body['log_id'] ?? null;

if (!$log_id || !ctype_digit((string)$log_id)) {
  json_err("VALIDATION_ERROR","invalid_log_id",400);
}

$fields = [];
$params = [];
$allowed_types = ['fertilizer','pesticide','watering','pruning','other'];

if (array_key_exists('user_id',$body)) {
  $user_id = trim($body['user_id'] ?? '');
  if ($user_id === '') json_err("VALIDATION_ERROR","user_id_required",400);
  $fields[] = "user_id=?";
  $params[] = $user_id;
}
if (array_key_exists('tree_id',$body)) {
  $tree_id = trim($body['tree_id'] ?? '');
  if ($tree_id === '') json_err("VALIDATION_ERROR","tree_id_required",400);
  $fields[] = "tree_id=?";
  $params[] = $tree_id;
}
if (array_key_exists('care_type',$body)) {
  $care_type = trim($body['care_type'] ?? '');
  if (!in_array($care_type,$allowed_types,true)) {
    json_err("VALIDATION_ERROR","invalid_care_type",400);
  }
  $fields[] = "care_type=?";
  $params[] = $care_type;
}
if (array_key_exists('care_date',$body)) {
  $care_date = trim($body['care_date'] ?? '');
  if ($care_date === '') json_err("VALIDATION_ERROR","care_date_required",400);
  $fields[] = "care_date=?";
  $params[] = $care_date;
}
if (array_key_exists('product_name',$body)) {
  $fields[] = "product_name=?";
  $params[] = trim($body['product_name'] ?? '') ?: null;
}
if (array_key_exists('amount',$body)) {
  $amount = $body['amount'];
  if ($amount !== null && $amount !== '' && !is_numeric($amount)) {
    json_err("VALIDATION_ERROR","invalid_amount",400);
  }
  $fields[] = "amount=?";
  $params[] = ($amount === '' ? null : $amount);
}
if (array_key_exists('unit',$body)) {
  $fields[] = "unit=?";
  $params[] = trim($body['unit'] ?? '') ?: null;
}
if (array_key_exists('area',$body)) {
  $fields[] = "area=?";
  $params[] = trim($body['area'] ?? '') ?: null;
}
if (array_key_exists('note',$body)) {
  $fields[] = "note=?";
  $params[] = trim($body['note'] ?? '') ?: null;
}

if (!$fields) json_err("VALIDATION_ERROR","nothing_to_update",400);

$params[] = (int)$log_id;

try {
  $sql = "UPDATE care_logs SET ".implode(',', $fields)." WHERE log_id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
