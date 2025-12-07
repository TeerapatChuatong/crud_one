<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED","post_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$question_id  = $body['question_id'] ?? null;
$choice_label = trim($body['choice_label'] ?? '');
$image_url    = trim($body['image_url'] ?? '');

if (!$question_id || !ctype_digit((string)$question_id)) {
  json_err("VALIDATION_ERROR","invalid_question_id",400);
}
if ($choice_label === '') {
  json_err("VALIDATION_ERROR","choice_label_required",400);
}

try {
  $st = $dbh->prepare("
    INSERT INTO choices(question_id,choice_label,image_url)
    VALUES (?,?,?)
  ");
  $st->execute([
    (int)$question_id,
    $choice_label,
    $image_url ?: null,
  ]);

  json_ok(["choice_id" => (int)$dbh->lastInsertId()]);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
