<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED", "delete_only", 405);
}

$id = $_GET['question_id'] ?? null;
if (!$id || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR", "invalid_question_id", 400);
}

try {
  // ตรวจสอบว่า question_id มีอยู่จริง
  $check = $dbh->prepare("SELECT question_id FROM questions WHERE question_id = ?");
  $check->execute([(int)$id]);
  if (!$check->fetch()) {
    json_err("NOT_FOUND", "question_not_found", 404);
  }

  // ลบ (จะ CASCADE ไปยัง disease_questions, choices, scores ด้วย)
  $st = $dbh->prepare("DELETE FROM questions WHERE question_id = ?");
  $st->execute([(int)$id]);
  
  json_ok([
    "question_id" => (int)$id,
    "deleted" => true
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}