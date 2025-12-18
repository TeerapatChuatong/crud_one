<?php
require_once __DIR__ . '/../db.php';

try {
  require_admin();

  $dq = $_GET['disease_question_id'] ?? null;

  // backward compatible: disease_id + question_id -> หา disease_question_id
  if (($dq === null || $dq === '') && isset($_GET['disease_id'], $_GET['question_id'])) {
    $disease_id = $_GET['disease_id'];
    $question_id = $_GET['question_id'];
    if (!ctype_digit((string)$disease_id) || !ctype_digit((string)$question_id)) {
      json_err('VALIDATION_ERROR', 'invalid_input', 400);
    }
    $q = $pdo->prepare("SELECT disease_question_id FROM disease_questions WHERE disease_id=? AND question_id=? LIMIT 1");
    $q->execute([(int)$disease_id, (int)$question_id]);
    $row = $q->fetch();
    $dq = $row ? (int)$row['disease_question_id'] : 0;
  }

  if (!ctype_digit((string)$dq)) {
    json_err('VALIDATION_ERROR', 'invalid_disease_question_id', 400);
  }

  $stmt = $pdo->prepare("
    SELECT score_id, disease_question_id, choice_id, score_value
    FROM scores
    WHERE disease_question_id=?
  ");
  $stmt->execute([(int)$dq]);

  json_ok($stmt->fetchAll());
} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
