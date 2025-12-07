<?php
require_once __DIR__ . '/../db.php';
require_admin(); // เพิ่มโรคได้เฉพาะ admin

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

// รองรับทั้ง name_th / disease_th
$disease_th = trim($body['name_th'] ?? $body['disease_th'] ?? '');
$disease_en = trim($body['name_en'] ?? $body['disease_en'] ?? '');

if ($disease_th === '') {
  json_err("VALIDATION_ERROR", "disease_th_required", 400);
}

try {
  // 1) ดึง disease_id ล่าสุด (เป็นตัวเลข) จากฐานข้อมูล
  $st = $dbh->query("SELECT MAX(CAST(disease_id AS UNSIGNED)) AS max_id FROM diseases");
  $maxId = $st->fetchColumn();

  // 2) ถ้ายังไม่มีข้อมูลเลย → เริ่มจาก 1, ถ้ามีแล้ว → +1
  if ($maxId === null || $maxId === false) {
    $nextId = 1;
  } else {
    $nextId = (int)$maxId + 1;
  }

  // 3) แปลงเป็น string เพื่อเก็บใน disease_id (จะได้ "1", "2", "3", ...)
  $disease_id = (string)$nextId;

  // 4) INSERT ลงตาราง
  $st = $dbh->prepare("
    INSERT INTO diseases (disease_id, disease_th, disease_en)
    VALUES (?, ?, ?)
  ");
  $st->execute([
    $disease_id,
    $disease_th,
    $disease_en !== '' ? $disease_en : $disease_th,
  ]);

  json_ok([
    "disease_id"  => $disease_id,
    "disease_th"  => $disease_th,
    "disease_en"  => $disease_en !== '' ? $disease_en : $disease_th,
  ]);
} catch (Throwable $e) {
  // ถ้าอยากเห็น error detail ตอน dev ปรับเป็น $e->getMessage() ได้
  json_err("DB_ERROR", "db_error", 500);
}
