<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED","post_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$question_text = trim($body['question_text'] ?? '');
$question_type = trim($body['question_type'] ?? '');
$sort_order    = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;

if ($question_text === '') {
  json_err("VALIDATION_ERROR","question_text_required",400);
}
if ($question_type === '') {
  json_err("VALIDATION_ERROR","question_type_required",400);
}

try {
  $st = $dbh->prepare("
    INSERT INTO questions(question_text,question_type,sort_order)
    VALUES (?,?,?)
  ");
  $st->execute([
    $question_text,
    $question_type,
    $sort_order,
  ]);

  json_ok(["question_id" => (int)$dbh->lastInsertId()]);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
