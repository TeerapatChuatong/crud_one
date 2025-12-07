<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED","post_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$disease_id = $body['disease_id'] ?? null;
$level_code = trim($body['level_code'] ?? '');
$min_score  = $body['min_score'] ?? null;

$allowed_levels = ['low','medium','high'];

if (!$disease_id || !ctype_digit((string)$disease_id)) {
  json_err("VALIDATION_ERROR","invalid_disease_id",400);
}
if (!in_array($level_code, $allowed_levels, true)) {
  json_err("VALIDATION_ERROR","invalid_level_code",400);
}
if ($min_score === null || !ctype_digit((string)$min_score)) {
  json_err("VALIDATION_ERROR","invalid_min_score",400);
}

try {
  $st = $dbh->prepare("
    INSERT INTO disease_risk_levels(disease_id,level_code,min_score)
    VALUES (?,?,?)
  ");
  $st->execute([
    (int)$disease_id,
    $level_code,
    (int)$min_score,
  ]);

  json_ok(["id" => (int)$dbh->lastInsertId()]);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
