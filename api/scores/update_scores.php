<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];
$id   = $body['score_id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR","invalid_score_id",400);
}

$disease_id  = array_key_exists('disease_id',$body)  ? $body['disease_id']  : null;
$question_id = array_key_exists('question_id',$body) ? $body['question_id'] : null;
$choice_id   = array_key_exists('choice_id',$body)   ? $body['choice_id']   : null;
$risk_score  = array_key_exists('risk_score',$body)  ? $body['risk_score']  : null;

$fields = [];
$params = [];

if ($disease_id !== null) {
  if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
  $fields[] = "disease_id=?";
  $params[] = (int)$disease_id;
}
if ($question_id !== null) {
  if (!ctype_digit((string)$question_id)) json_err("VALIDATION_ERROR","invalid_question_id",400);
  $fields[] = "question_id=?";
  $params[] = (int)$question_id;
}
if ($choice_id !== null) {
  if (!ctype_digit((string)$choice_id)) json_err("VALIDATION_ERROR","invalid_choice_id",400);
  $fields[] = "choice_id=?";
  $params[] = (int)$choice_id;
}
if ($risk_score !== null) {
  if (!ctype_digit((string)$risk_score)) json_err("VALIDATION_ERROR","invalid_risk_score",400);
  $fields[] = "risk_score=?";
  $params[] = (int)$risk_score;
}

if (!$fields) json_err("VALIDATION_ERROR","nothing_to_update",400);

$params[] = (int)$id;

try {
  $sql = "UPDATE scores SET ".implode(',', $fields)." WHERE score_id=?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);
  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
