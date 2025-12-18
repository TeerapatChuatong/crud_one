<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED","post_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$score_value = $body['score_value'] ?? ($body['risk_score'] ?? null);
if ($score_value === null || !is_numeric($score_value)) json_err("VALIDATION_ERROR","invalid_score_value",400);
$score_value = (int)$score_value;

$choice_id = $body['choice_id'] ?? null;
if (!$choice_id || !ctype_digit((string)$choice_id)) json_err("VALIDATION_ERROR","invalid_choice_id",400);

$dq_id = $body['disease_question_id'] ?? null;
$disease_id  = $body['disease_id'] ?? null;
$question_id = $body['question_id'] ?? null;

try {
  if ($dq_id === null || $dq_id === '') {
    if (!$disease_id || !ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
    if (!$question_id || !ctype_digit((string)$question_id)) json_err("VALIDATION_ERROR","invalid_question_id",400);

    $q = $dbh->prepare("SELECT disease_question_id FROM disease_questions WHERE disease_id=? AND question_id=? LIMIT 1");
    $q->execute([(int)$disease_id, (int)$question_id]);
    $dq = $q->fetch(PDO::FETCH_ASSOC);
    if (!$dq) json_err("NOT_FOUND","disease_question_not_found",404);

    $dq_id = (int)$dq['disease_question_id'];
  } else {
    if (!ctype_digit((string)$dq_id)) json_err("VALIDATION_ERROR","invalid_disease_question_id",400);
    $dq_id = (int)$dq_id;
  }

  // ใช้ UPSERT เหมือนกัน กันซ้ำ
  $st = $dbh->prepare("
    INSERT INTO scores (disease_question_id, choice_id, score_value)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE score_value = VALUES(score_value)
  ");
  $st->execute([$dq_id, (int)$choice_id, $score_value]);

  json_ok(true);

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
