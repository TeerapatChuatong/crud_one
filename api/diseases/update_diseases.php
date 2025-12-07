<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED","patch_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$disease_id = trim($body['disease_id'] ?? '');
if ($disease_id === '') {
  json_err("VALIDATION_ERROR","invalid_disease_id",400);
}

// รองรับทั้ง name_th/name_en และ disease_th/disease_en
$th_input = $body['name_th']     ?? $body['disease_th'] ?? null;
$en_input = $body['name_en']     ?? $body['disease_en'] ?? null;

$fields = [];
$params = [];

if ($th_input !== null) {
  $disease_th = trim($th_input);
  if ($disease_th === '') {
    json_err("VALIDATION_ERROR","disease_th_required",400);
  }
  $fields[] = "disease_th = ?";
  $params[] = $disease_th;
}

if ($en_input !== null) {
  $disease_en = trim($en_input);
  if ($disease_en === '') {
    // ถ้าอยากให้ว่างได้ก็ไม่ต้องเช็ค
    json_err("VALIDATION_ERROR","disease_en_required",400);
  }
  $fields[] = "disease_en = ?";
  $params[] = $disease_en;
}

if (!$fields) {
  json_err("VALIDATION_ERROR","nothing_to_update",400);
}

$params[] = $disease_id;

try {
  $sql = "UPDATE diseases SET " . implode(", ", $fields) . " WHERE disease_id = ?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);

  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
