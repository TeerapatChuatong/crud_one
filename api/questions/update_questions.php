<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id   = $body['question_id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR","invalid_question_id",400);
}

$question_text = array_key_exists('question_text',$body)
  ? trim((string)$body['question_text'])
  : null;
$question_type = array_key_exists('question_type',$body)
  ? trim((string)$body['question_type'])
  : null;
$sort_order = array_key_exists('sort_order',$body)
  ? (int)$body['sort_order']
  : null;

$fields = [];
$params = [];

if ($question_text !== null) {
  if ($question_text === '') {
    json_err("VALIDATION_ERROR","question_text_required",400);
  }
  $fields[] = "question_text=?";
  $params[] = $question_text;
}
if ($question_type !== null) {
  if ($question_type === '') {
    json_err("VALIDATION_ERROR","question_type_required",400);
  }
  $fields[] = "question_type=?";
  $params[] = $question_type;
}
if ($sort_order !== null) {
  $fields[] = "sort_order=?";
  $params[] = $sort_order;
}

if (!$fields) {
  json_err("VALIDATION_ERROR","nothing_to_update",400);
}

$params[] = (int)$id;

try {
  $sql = "UPDATE questions SET ".implode(',', $fields)." WHERE question_id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
