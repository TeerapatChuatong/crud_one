<?php
require_once __DIR__ . '/../db.php';

try {
  require_admin();

  $body = json_decode(file_get_contents("php://input"), true) ?: [];

  $disease_id = $body['disease_id'] ?? null;
  $question_id = $body['question_id'] ?? null;
  $sort_order  = $body['sort_order'] ?? 0;

  if (!ctype_digit((string)$disease_id) || !ctype_digit((string)$question_id)) {
    json_err('VALIDATION_ERROR', 'invalid_input', 400);
  }

  // กันซ้ำ (disease_id, question_id) ถ้ามีอยู่แล้วให้คืนตัวเดิม
  $chk = $pdo->prepare("SELECT disease_question_id FROM disease_questions WHERE disease_id=? AND question_id=? LIMIT 1");
  $chk->execute([(int)$disease_id, (int)$question_id]);
  $found = $chk->fetch();

  if ($found) {
    json_ok([
      "disease_question_id" => (int)$found['disease_question_id'],
      "disease_id" => (int)$disease_id,
      "question_id" => (int)$question_id,
      "sort_order" => (int)$sort_order
    ]);
  }

  $stmt = $pdo->prepare("INSERT INTO disease_questions (disease_id, question_id, sort_order) VALUES (?,?,?)");
  $stmt->execute([(int)$disease_id, (int)$question_id, (int)$sort_order]);

  json_ok([
    "disease_question_id" => (int)$pdo->lastInsertId(),
    "disease_id" => (int)$disease_id,
    "question_id" => (int)$question_id,
    "sort_order" => (int)$sort_order
  ]);
} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
