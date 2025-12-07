<?php
// update_diagnosis_history_answers.php
require_once __DIR__ . '/../db.php';

// แก้ได้เฉพาะ admin
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$history_answer_id = $body['history_answer_id'] ?? null;
if (!$history_answer_id || !ctype_digit((string)$history_answer_id)) {
  json_err("VALIDATION_ERROR", "invalid_history_answer_id", 400);
}

$fields = [];
$params = [];

if (array_key_exists('history_id', $body)) {
  $v = $body['history_id'];
  if (!$v || !ctype_digit((string)$v)) {
    json_err("VALIDATION_ERROR", "invalid_history_id", 400);
  }
  $fields[] = "history_id = ?";
  $params[] = (int)$v;
}

if (array_key_exists('question_id', $body)) {
  $v = $body['question_id'];
  if (!$v || !ctype_digit((string)$v)) {
    json_err("VALIDATION_ERROR", "invalid_question_id", 400);
  }
  $fields[] = "question_id = ?";
  $params[] = (int)$v;
}

if (array_key_exists('choice_id', $body)) {
  $v = $body['choice_id'];
  if ($v !== null && $v !== '' && !ctype_digit((string)$v)) {
    json_err("VALIDATION_ERROR", "invalid_choice_id", 400);
  }
  $fields[] = "choice_id = ?";
  $params[] = ($v === '' ? null : $v);
}

if (array_key_exists('score_id', $body)) {
  $v = $body['score_id'];
  if ($v !== null && $v !== '' && !ctype_digit((string)$v)) {
    json_err("VALIDATION_ERROR", "invalid_score_id", 400);
  }
  $fields[] = "score_id = ?";
  $params[] = ($v === '' ? null : $v);
}

if (array_key_exists('score_value', $body)) {
  $v = $body['score_value'];
  if ($v !== null && $v !== '' && !is_numeric($v)) {
    json_err("VALIDATION_ERROR", "invalid_score_value", 400);
  }
  $fields[] = "score_value = ?";
  $params[] = ($v === '' ? null : (int)$v);
}

if (array_key_exists('answer_bool', $body)) {
  $v = $body['answer_bool'];
  $fields[] = "answer_bool = ?";
  $params[] = ($v === null ? null : ($v ? 1 : 0));
}

if (array_key_exists('answer_numeric', $body)) {
  $v = $body['answer_numeric'];
  if ($v !== null && $v !== '' && !is_numeric($v)) {
    json_err("VALIDATION_ERROR", "invalid_answer_numeric", 400);
  }
  $fields[] = "answer_numeric = ?";
  $params[] = ($v === '' ? null : $v);
}

if (array_key_exists('answer_text', $body)) {
  $v = $body['answer_text'];
  $fields[] = "answer_text = ?";
  $params[] = ($v === '' ? null : $v);
}

if (!$fields) {
  json_err("VALIDATION_ERROR", "nothing_to_update", 400);
}

$params[] = (int)$history_answer_id;

try {
  $sql = "UPDATE diagnosis_history_answers
          SET " . implode(', ', $fields) . "
          WHERE history_answer_id = ?";
  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
