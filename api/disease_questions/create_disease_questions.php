<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED","post_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$disease_id  = trim($body['disease_id'] ?? '');
$question_id = $body['question_id'] ?? null;

if ($disease_id === '') json_err("VALIDATION_ERROR","disease_id_required",400);
if (!$question_id || !ctype_digit((string)$question_id)) {
  json_err("VALIDATION_ERROR","invalid_question_id",400);
}

try {
  $st = $dbh->prepare("
    INSERT INTO disease_questions(disease_id,question_id)
    VALUES (?,?)
  ");
  $st->execute([
    $disease_id,
    (int)$question_id,
  ]);

  json_ok(["id" => (int)$dbh->lastInsertId()]);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
