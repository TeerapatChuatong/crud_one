<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$session_id = trim($body['session_id'] ?? '');

if ($session_id === '') {
  json_err("VALIDATION_ERROR","session_id_required",400);
}

$fields = [];
$params = [];
$allowed_status = ['image_only','in_severity','completed'];

if (array_key_exists('user_id',$body)) {
  $user_id = trim($body['user_id'] ?? '');
  if ($user_id === '') json_err("VALIDATION_ERROR","user_id_required",400);
  $fields[] = "user_id=?";
  $params[] = $user_id;
}
if (array_key_exists('uploaded_image_url',$body)) {
  $url = trim($body['uploaded_image_url'] ?? '');
  if ($url === '') json_err("VALIDATION_ERROR","uploaded_image_url_required",400);
  $fields[] = "uploaded_image_url=?";
  $params[] = $url;
}
if (array_key_exists('predicted_disease_id',$body)) {
  $pd = trim($body['predicted_disease_id'] ?? '');
  if ($pd === '') json_err("VALIDATION_ERROR","predicted_disease_id_required",400);
  $fields[] = "predicted_disease_id=?";
  $params[] = $pd;
}
if (array_key_exists('predicted_confidence',$body)) {
  $pc = $body['predicted_confidence'];
  if ($pc === null || !is_numeric($pc)) {
    json_err("VALIDATION_ERROR","invalid_predicted_confidence",400);
  }
  $fields[] = "predicted_confidence=?";
  $params[] = $pc;
}
if (array_key_exists('final_disease_id',$body)) {
  $fd = trim($body['final_disease_id'] ?? '');
  $fields[] = "final_disease_id=?";
  $params[] = $fd ?: null;
}
if (array_key_exists('status',$body)) {
  $stt = trim($body['status'] ?? '');
  if (!in_array($stt,$allowed_status,true)) {
    json_err("VALIDATION_ERROR","invalid_status",400);
  }
  $fields[] = "status=?";
  $params[] = $stt;
}

if (!$fields) json_err("VALIDATION_ERROR","nothing_to_update",400);

$params[] = $session_id;

try {
  $sql = "UPDATE diagnosis_sessions SET ".implode(',', $fields)." WHERE session_id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
