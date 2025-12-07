<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED","post_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$disease_id  = $body['disease_id']  ?? null;
$question_id = $body['question_id'] ?? null;
$choice_id   = $body['choice_id']   ?? null;
$risk_score  = $body['risk_score']  ?? null;

if (!ctype_digit((string)$disease_id))  json_err("VALIDATION_ERROR","invalid_disease_id",400);
if (!ctype_digit((string)$question_id)) json_err("VALIDATION_ERROR","invalid_question_id",400);
if (!ctype_digit((string)$choice_id))   json_err("VALIDATION_ERROR","invalid_choice_id",400);
if (!ctype_digit((string)$risk_score))  json_err("VALIDATION_ERROR","invalid_risk_score",400);

try {
  $st = $dbh->prepare("
    INSERT INTO scores(disease_id,question_id,choice_id,risk_score)
    VALUES (?,?,?,?)
  ");
  $st->execute([
    (int)$disease_id,
    (int)$question_id,
    (int)$choice_id,
    (int)$risk_score,
  ]);

  json_ok(["score_id" => (int)$dbh->lastInsertId()]);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
