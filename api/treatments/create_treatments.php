<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$disease_id  = $body['disease_id'] ?? null;
$level_code  = strtolower(trim((string)($body['level_code'] ?? '')));
$advice_text = trim((string)($body['advice_text'] ?? ''));

$allowedLevels = ['low','medium','high'];

if ($disease_id === null || !ctype_digit((string)$disease_id)) json_err('VALIDATION_ERROR', 'invalid_disease_id', 400);
if (!in_array($level_code, $allowedLevels, true)) json_err('VALIDATION_ERROR', 'invalid_level_code', 400);
if ($advice_text === '') json_err('VALIDATION_ERROR', 'advice_text_required', 400);

// ต้องมี min_score เสมอในหน้าสร้าง
$min_raw = $body['min_score'] ?? null;
if ($min_raw === null || $min_raw === '' || !is_numeric($min_raw)) json_err('VALIDATION_ERROR', 'invalid_min_score', 400);
$min_score = (int)$min_raw;

$days = null;
if (array_key_exists('days', $body)) {
  $days_raw = $body['days'];
  if ($days_raw === '' || $days_raw === null) $days = null;
  else {
    if (!is_numeric($days_raw)) json_err('VALIDATION_ERROR', 'invalid_days', 400);
    $days = (int)$days_raw;
  }
}

$times = null;
if (array_key_exists('times', $body)) {
  $times_raw = $body['times'];
  if ($times_raw === '' || $times_raw === null) $times = null;
  else {
    if (!is_numeric($times_raw)) json_err('VALIDATION_ERROR', 'invalid_times', 400);
    $times = (int)$times_raw;
  }
}

try {
  $dbh->beginTransaction();

  // หา/สร้าง risk level ของโรค+ระดับ
  $stFind = $dbh->prepare('SELECT risk_level_id FROM disease_risk_levels WHERE disease_id=? AND level_code=? LIMIT 1');
  $stFind->execute([(int)$disease_id, $level_code]);
  $existing = $stFind->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $risk_level_id = (int)$existing['risk_level_id'];

    // ✅ ห้ามเพิ่มซ้ำ: 1 risk level มีได้แค่ 1 treatment
    $stDup = $dbh->prepare('SELECT treatment_id FROM treatments WHERE risk_level_id=? LIMIT 1');
    $stDup->execute([$risk_level_id]);
    $dup = $stDup->fetch(PDO::FETCH_ASSOC);
    if ($dup) {
      json_err('DUPLICATE', 'โรค+ระดับนี้มีคำแนะนำอยู่แล้ว (ไม่สามารถเพิ่มซ้ำได้)', 409);
    }

    // อัปเดตเกณฑ์ระดับตามที่กรอก
    $stUp = $dbh->prepare('UPDATE disease_risk_levels SET min_score=?, days=?, times=? WHERE risk_level_id=?');
    $stUp->execute([$min_score, $days, $times, $risk_level_id]);

  } else {
    // สร้าง risk level ใหม่
    $stIns = $dbh->prepare('INSERT INTO disease_risk_levels (disease_id, level_code, min_score, days, times) VALUES (?, ?, ?, ?, ?)');
    $stIns->execute([(int)$disease_id, $level_code, $min_score, $days, $times]);
    $risk_level_id = (int)$dbh->lastInsertId();
  }

  // เพิ่ม treatment ใหม่
  $st2 = $dbh->prepare('INSERT INTO treatments (risk_level_id, advice_text) VALUES (?, ?)');
  $st2->execute([$risk_level_id, $advice_text]);
  $treatment_id = (int)$dbh->lastInsertId();

  $st3 = $dbh->prepare("
    SELECT
      t.treatment_id, t.risk_level_id, t.advice_text, t.created_at,
      rl.disease_id, rl.level_code, rl.min_score, rl.days, rl.times,
      d.disease_th, d.disease_en
    FROM treatments t
    JOIN disease_risk_levels rl ON rl.risk_level_id = t.risk_level_id
    JOIN diseases d ON d.disease_id = rl.disease_id
    WHERE t.treatment_id = ?
    LIMIT 1
  ");
  $st3->execute([$treatment_id]);
  $row = $st3->fetch(PDO::FETCH_ASSOC);

  $dbh->commit();
  json_ok($row ?: true);

} catch (Throwable $e) {
  if ($dbh->inTransaction()) $dbh->rollBack();
  json_err('DB_ERROR', 'db_error', 500);
}
