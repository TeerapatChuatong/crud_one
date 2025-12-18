<?php
require_once __DIR__ . '/../db.php';

try {
  require_admin();

  $body = json_decode(file_get_contents("php://input"), true) ?: [];

  $question_text = trim((string)($body['question_text'] ?? ''));
  $question_type = (string)($body['question_type'] ?? 'yes_no');
  $is_active = isset($body['is_active']) ? (int)!!$body['is_active'] : 1;

  $disease_id = $body['disease_id'] ?? null;     // สำคัญ
  $sort_order = $body['sort_order'] ?? 0;

  if ($question_text === '') {
    json_err('VALIDATION_ERROR', 'question_text_required', 400);
  }
  if (!in_array($question_type, ['yes_no','numeric','multi'], true)) {
    json_err('VALIDATION_ERROR', 'invalid_question_type', 400);
  }
  if (!ctype_digit((string)$disease_id)) {
    json_err('VALIDATION_ERROR', 'disease_id_required', 400);
  }

  $pdo->beginTransaction();

  $stmt = $pdo->prepare("INSERT INTO questions (question_text, question_type, is_active) VALUES (?,?,?)");
  $stmt->execute([$question_text, $question_type, $is_active]);
  $question_id = (int)$pdo->lastInsertId();

  // create pivot
  $stmt2 = $pdo->prepare("INSERT INTO disease_questions (disease_id, question_id, sort_order) VALUES (?,?,?)");
  $stmt2->execute([(int)$disease_id, $question_id, (int)$sort_order]);
  $disease_question_id = (int)$pdo->lastInsertId();

  $pdo->commit();

  json_ok([
    "question_id" => $question_id,
    "disease_question_id" => $disease_question_id,
    "disease_id" => (int)$disease_id,
    "question_text" => $question_text,
    "question_type" => $question_type,
    "is_active" => $is_active,
    "sort_order" => (int)$sort_order
  ]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  json_err('DB_ERROR', 'db_error', 500);
}
