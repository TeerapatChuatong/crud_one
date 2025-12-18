<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED", "delete_only", 405);
}

$disease_id = trim($_GET['disease_id'] ?? '');
if ($disease_id === '') {
  json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
}

try {
  // ตรวจสอบว่า disease_id มีอยู่จริง
  $check = $dbh->prepare("SELECT disease_id FROM diseases WHERE disease_id = ?");
  $check->execute([$disease_id]);
  if (!$check->fetch()) {
    json_err("NOT_FOUND", "disease_not_found", 404);
  }

  // ลบ (จะ CASCADE ไปยัง disease_questions, disease_risk_levels, treatments ด้วย)
  $st = $dbh->prepare("DELETE FROM diseases WHERE disease_id = ?");
  $st->execute([$disease_id]);
  
  json_ok([
    "disease_id" => $disease_id,
    "deleted" => true
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}