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

if (array_key_exists('disease_id',$body)) {
  $disease_id = trim($body['disease_id'] ?? '');
  if ($disease_id === '') json_err("VALIDATION_ERROR","disease_id_required",400);
  $fields[]  = "disease_id=?";
  $params[]  = $disease_id;
}
if (array_key_exists('question_id',$body)) {
  $question_id = $body['question_id'] ?? null;
  if (!$question_id || !ctype_digit((string)$question_id)) {
    json_err("VALIDATION_ERROR","invalid_question_id",400);
  }
  $fields[] = "question_id=?";
  $params[] = (int)$question_id;
}

if (!$fields) {
  json_err("VALIDATION_ERROR","nothing_to_update",400);
}

$params[] = (int)$id;

try {
  $sql = "UPDATE disease_questions SET ".implode(',', $fields)." WHERE id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
