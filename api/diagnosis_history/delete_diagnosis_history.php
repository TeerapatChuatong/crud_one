<?php
// delete_diagnosis_history.php
require_once __DIR__ . '/../db.php';

// ลบได้เฉพาะ admin
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

$history_id = $_GET['history_id'] ?? null;

if (!$history_id || !ctype_digit((string)$history_id)) {
  json_err("VALIDATION_ERROR", "invalid_history_id", 400);
}

try {
  $st = $dbh->prepare("DELETE FROM diagnosis_history WHERE history_id = ?");
  $st->execute([(int)$history_id]);

  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
