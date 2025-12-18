<?php
require_once __DIR__ . '/../db.php';

try {
  require_admin();

  $disease_question_id = $_GET['disease_question_id'] ?? null;
  if (!ctype_digit((string)$disease_question_id)) {
    json_err('VALIDATION_ERROR', 'invalid_disease_question_id', 400);
  }

  $stmt = $pdo->prepare("DELETE FROM disease_questions WHERE disease_question_id=?");
  $stmt->execute([(int)$disease_question_id]);

  json_ok(["deleted" => true]);
} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
