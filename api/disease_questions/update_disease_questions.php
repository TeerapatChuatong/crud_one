<?php
require_once __DIR__ . '/../db.php';

try {
  require_admin();

  $body = json_decode(file_get_contents("php://input"), true) ?: [];

  $disease_question_id = $body['disease_question_id'] ?? null;
  $disease_id = $body['disease_id'] ?? null;
  $question_id = $body['question_id'] ?? null;
  $sort_order  = $body['sort_order'] ?? 0;

  if (!ctype_digit((string)$disease_question_id) ||
      !ctype_digit((string)$disease_id) ||
      !ctype_digit((string)$question_id)) {
    json_err('VALIDATION_ERROR', 'invalid_input', 400);
  }

  $stmt = $pdo->prepare("
    UPDATE disease_questions
    SET disease_id=?, question_id=?, sort_order=?
    WHERE disease_question_id=?
  ");
  $stmt->execute([(int)$disease_id, (int)$question_id, (int)$sort_order, (int)$disease_question_id]);

  json_ok(["updated" => true]);
} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
