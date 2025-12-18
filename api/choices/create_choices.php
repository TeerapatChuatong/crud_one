<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

function is_dup_error(Throwable $e): bool {
  if ($e instanceof PDOException) {
    $sqlState = $e->errorInfo[0] ?? '';
    $driver  = $e->errorInfo[1] ?? 0; // MySQL duplicate = 1062
    return $sqlState === '23000' || (int)$driver === 1062;
  }
  return false;
}

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) $body = $_POST ?? [];

$question_id  = $body['question_id'] ?? null;
$choice_label = trim((string)($body['choice_label'] ?? ''));
$choice_value = array_key_exists('choice_value', $body) ? trim((string)$body['choice_value']) : null;
$image_url    = array_key_exists('image_url', $body) ? trim((string)$body['image_url']) : null;
$sort_order   = array_key_exists('sort_order', $body) ? $body['sort_order'] : 0;

if ($question_id === null || !ctype_digit((string)$question_id)) {
  json_err("VALIDATION_ERROR", "invalid_question_id", 400);
}
if ($choice_label === '') {
  json_err("VALIDATION_ERROR", "choice_label_required", 400);
}
if (!is_numeric($sort_order)) {
  json_err("VALIDATION_ERROR", "invalid_sort_order", 400);
}

try {
  // กัน question_id หลอก (เพราะไม่มี FK)
  $chk = $dbh->prepare("SELECT question_id FROM questions WHERE question_id=? LIMIT 1");
  $chk->execute([(int)$question_id]);
  if (!$chk->fetch()) json_err("NOT_FOUND", "question_not_found", 404);

  $st = $dbh->prepare("
    INSERT INTO choices (question_id, choice_label, choice_value, image_url, sort_order)
    VALUES (?,?,?,?,?)
  ");
  $st->execute([
    (int)$question_id,
    $choice_label,
    ($choice_value === '' ? null : $choice_value),
    ($image_url === '' ? null : $image_url),
    (int)$sort_order,
  ]);

  json_ok(["choice_id" => (int)$dbh->lastInsertId()]);
} catch (Throwable $e) {
  if (is_dup_error($e)) {
    json_err("DUPLICATE", "choice_label_exists_in_question", 409);
  }
  json_err("DB_ERROR", "db_error", 500);
}
