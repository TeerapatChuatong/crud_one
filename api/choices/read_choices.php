<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$choice_id   = $_GET['choice_id']   ?? null;
$question_id = $_GET['question_id'] ?? null;

try {
  if ($choice_id !== null && $choice_id !== '') {
    if (!ctype_digit((string)$choice_id)) {
      json_err("VALIDATION_ERROR","invalid_choice_id",400);
    }
    $st = $dbh->prepare("SELECT * FROM choices WHERE choice_id=?");
    $st->execute([(int)$choice_id]);
    $row = $st->fetch();
    if (!$row) json_err("NOT_FOUND","not_found",404);
    json_ok($row);

  } elseif ($question_id !== null && $question_id !== '') {
    if (!ctype_digit((string)$question_id)) {
      json_err("VALIDATION_ERROR","invalid_question_id",400);
    }
    $st = $dbh->prepare("
      SELECT * FROM choices
      WHERE question_id=?
      ORDER BY sort_order ASC, choice_id ASC
    ");
    $st->execute([(int)$question_id]);
    json_ok($st->fetchAll());

  } else {
    $st = $dbh->query("
      SELECT * FROM choices
      ORDER BY question_id ASC, sort_order ASC, choice_id ASC
    ");
    json_ok($st->fetchAll());
  }
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
