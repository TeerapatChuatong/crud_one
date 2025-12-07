<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id   = $body['choice_id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR","invalid_choice_id",400);
}

$question_id  = array_key_exists('question_id',$body) ? $body['question_id'] : null;
$choice_label = array_key_exists('choice_label',$body) ? trim((string)$body['choice_label']) : null;
$image_url    = array_key_exists('image_url',$body) ? trim((string)$body['image_url']) : null;

$fields = [];
$params = [];

if ($question_id !== null) {
  if (!ctype_digit((string)$question_id)) {
    json_err("VALIDATION_ERROR","invalid_question_id",400);
  }
  $fields[] = "question_id=?";
  $params[] = (int)$question_id;
}
if ($choice_label !== null) {
  if ($choice_label === '') {
    json_err("VALIDATION_ERROR","choice_label_required",400);
  }
  $fields[] = "choice_label=?";
  $params[] = $choice_label;
}
if ($image_url !== null) {
  $fields[] = "image_url=?";
  $params[] = $image_url ?: null;
}

if (!$fields) {
  json_err("VALIDATION_ERROR","nothing_to_update",400);
}

$params[] = (int)$id;

try {
  $sql = "UPDATE choices SET ".implode(',', $fields)." WHERE choice_id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
