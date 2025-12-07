<?php
// create_diagnosis_history_answers.php
require_once __DIR__ . '/../db.php';

// ต้องล็อกอินก่อน
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

$is_admin    = is_admin();
$current_uid = (string)($_SESSION['user_id'] ?? '');

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$history_id     = $body['history_id']     ?? null;
$question_id    = $body['question_id']    ?? null;
$choice_id      = $body['choice_id']      ?? null;
$score_id       = $body['score_id']       ?? null;
$score_value    = $body['score_value']    ?? null;
$answer_bool    = $body['answer_bool']    ?? null;
$answer_numeric = $body['answer_numeric'] ?? null;
$answer_text    = $body['answer_text']    ?? null;

if (!$history_id || !ctype_digit((string)$history_id)) {
  json_err("VALIDATION_ERROR", "invalid_history_id", 400);
}
if (!$question_id || !ctype_digit((string)$question_id)) {
  json_err("VALIDATION_ERROR", "invalid_question_id", 400);
}

if ($choice_id !== null && $choice_id !== '' && !ctype_digit((string)$choice_id)) {
  json_err("VALIDATION_ERROR", "invalid_choice_id", 400);
}
if ($score_id !== null && $score_id !== '' && !ctype_digit((string)$score_id)) {
  json_err("VALIDATION_ERROR", "invalid_score_id", 400);
}
if ($score_value !== null && $score_value !== '' && !is_numeric($score_value)) {
  json_err("VALIDATION_ERROR", "invalid_score_value", 400);
}
if ($answer_numeric !== null && $answer_numeric !== '' && !is_numeric($answer_numeric)) {
  json_err("VALIDATION_ERROR", "invalid_answer_numeric", 400);
}

// ตรวจว่า history นี้เป็นของ user คนนี้จริงหรือไม่ (ถ้าไม่ใช่ admin)
try {
  $st = $dbh->prepare("SELECT user_id FROM diagnosis_history WHERE history_id = ?");
  $st->execute([(int)$history_id]);
  $h = $st->fetch(PDO::FETCH_ASSOC);

  if (!$h) {
    json_err("NOT_FOUND", "history_not_found", 404);
  }

  if (!$is_admin && (string)$h['user_id'] !== $current_uid) {
    json_err("FORBIDDEN", "cannot_modify_other_history", 403);
  }
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}

// แปลงค่าให้เหมาะกับคอลัมน์
$choice_id      = ($choice_id === '' ? null : $choice_id);
$score_id       = ($score_id === '' ? null : $score_id);
$score_value    = ($score_value === '' ? null : ($score_value === null ? null : (int)$score_value));
$answer_numeric = ($answer_numeric === '' ? null : $answer_numeric);
$answer_text    = ($answer_text === '' ? null : $answer_text);

if ($answer_bool !== null) {
  $answer_bool = $answer_bool ? 1 : 0;
}

try {
  $sql = "INSERT INTO diagnosis_history_answers (
            history_id,
            question_id,
            choice_id,
            score_id,
            score_value,
            answer_bool,
            answer_numeric,
            answer_text
          )
          VALUES (?,?,?,?,?,?,?,?)";

  $st = $dbh->prepare($sql);
  $st->execute([
    (int)$history_id,
    (int)$question_id,
    $choice_id,
    $score_id,
    $score_value,
    $answer_bool,
    $answer_numeric,
    $answer_text,
  ]);

  json_ok([
    "history_answer_id" => (int)$dbh->lastInsertId(),
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
