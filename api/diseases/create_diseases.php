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

// ฟิลด์ใหม่ (แก้ symptoms -> symptom ตาม DB)
$description = $body['description'] ?? null;
$causes      = $body['causes'] ?? null;
$symptom     = $body['symptom'] ?? $body['symptoms'] ?? null; // รองรับทั้ง 2 แบบ
$image_url   = $body['image_url'] ?? null;

$normNullableText = function ($v) {
  if ($v === null) return null;
  if (!is_string($v)) $v = (string)$v;
  $v = trim($v);
  return $v === '' ? null : $v;
};

$description = $normNullableText($description);
$causes      = $normNullableText($causes);
$symptom     = $normNullableText($symptom);
$image_url   = $normNullableText($image_url);

if ($disease_th === '') {
  json_err("VALIDATION_ERROR", "disease_th_required", 400);
}

try {
  // 1) ดึง disease_id ล่าสุด (เป็นตัวเลข) จากฐานข้อมูล
  $st = $dbh->query("SELECT MAX(CAST(disease_id AS UNSIGNED)) AS max_id FROM diseases");
  $maxId = $st->fetchColumn();

  // 2) ถ้ายังไม่มีข้อมูลเลย → เริ่มจาก 1, ถ้ามีแล้ว → +1
  $nextId = ($maxId === null || $maxId === false) ? 1 : ((int)$maxId + 1);

  // 3) เก็บเป็น string ("1","2","3",...)
  $disease_id = (string)$nextId;

  $final_en = ($disease_en !== '' ? $disease_en : $disease_th);

  // 4) INSERT ลงตาราง diseases (แก้ symptoms -> symptom)
  $st = $dbh->prepare("
    INSERT INTO diseases (disease_id, disease_th, disease_en, description, causes, symptom, image_url)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $st->execute([
    $disease_id,
    $disease_th,
    $final_en,
    $description,
    $causes,
    $symptom,
    $image_url,
  ]);

  json_ok([
    "disease_id"  => $disease_id,
    "disease_th"  => $disease_th,
    "disease_en"  => $final_en,
    "description" => $description,
    "causes"      => $causes,
    "symptom"     => $symptom,
    "image_url"   => $image_url,
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}