<?php
require_once __DIR__ . '/../db.php';
require_admin();

// รองรับ PATCH เป็นหลัก แต่กันพลาดให้ POST ใช้ได้ด้วย
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['PATCH','POST'], true)) {
  json_err('METHOD_NOT_ALLOWED', 'patch_or_post_only', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$treatment_id = $body['treatment_id'] ?? null;
if ($treatment_id === null || !ctype_digit((string)$treatment_id)) {
  json_err('VALIDATION_ERROR', 'invalid_treatment_id', 400);
}
$treatment_id = (int)$treatment_id;

// ฟิลด์ที่อนุญาตให้อัปเดต
$hasAdvice = array_key_exists('advice_text', $body);
$hasMin   = array_key_exists('min_score', $body);
$hasDays  = array_key_exists('days', $body);
$hasTimes = array_key_exists('times', $body);

if (!$hasAdvice && !$hasMin && !$hasDays && !$hasTimes) {
  json_err('VALIDATION_ERROR', 'no_fields_to_update', 400);
}

$advice_text = null;
if ($hasAdvice) {
  $advice_text = trim((string)($body['advice_text'] ?? ''));
  if ($advice_text === '') json_err('VALIDATION_ERROR', 'advice_text_required', 400);
}

$min_score = null;
if ($hasMin) {
  $min_raw = $body['min_score'];
  if ($min_raw === '' || $min_raw === null || !is_numeric($min_raw)) {
    json_err('VALIDATION_ERROR', 'invalid_min_score', 400);
  }
  $min_score = (int)$min_raw;
}

$days = null;
if ($hasDays) {
  $days_raw = $body['days'];
  if ($days_raw === '' || $days_raw === null) $days = null;
  else {
    if (!is_numeric($days_raw)) json_err('VALIDATION_ERROR', 'invalid_days', 400);
    $days = (int)$days_raw;
  }
}

$times = null;
if ($hasTimes) {
  $times_raw = $body['times'];
  if ($times_raw === '' || $times_raw === null) $times = null;
  else {
    if (!is_numeric($times_raw)) json_err('VALIDATION_ERROR', 'invalid_times', 400);
    $times = (int)$times_raw;
  }
}

try {
  $dbh->beginTransaction();

  // หา risk_level_id ของ treatment ที่จะแก้
  $st = $dbh->prepare('SELECT risk_level_id FROM treatments WHERE treatment_id=? LIMIT 1');
  $st->execute([$treatment_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_err('NOT_FOUND', 'treatment_not_found', 404);
  $risk_level_id = (int)$row['risk_level_id'];

  // อัปเดตคำแนะนำ
  if ($hasAdvice) {
    $st2 = $dbh->prepare('UPDATE treatments SET advice_text=? WHERE treatment_id=?');
    $st2->execute([$advice_text, $treatment_id]);
  }

  // ✅ อัปเดตเกณฑ์ระดับเสมอ (ไม่ต้องมีติ๊กแล้ว)
  $set = [];
  $params = [];

  if ($hasMin) {
    $set[] = 'min_score=?';
    $params[] = $min_score;
  }
  if ($hasDays) {
    $set[] = 'days=?';
    $params[] = $days;
  }
  if ($hasTimes) {
    $set[] = 'times=?';
    $params[] = $times;
  }

  if ($set) {
    $params[] = $risk_level_id;
    $sql = 'UPDATE disease_risk_levels SET ' . implode(', ', $set) . ' WHERE risk_level_id=?';
    $st3 = $dbh->prepare($sql);
    $st3->execute($params);
  }

  // ส่งข้อมูลล่าสุดกลับ
  $st4 = $dbh->prepare("
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
  $st4->execute([$treatment_id]);
  $out = $st4->fetch(PDO::FETCH_ASSOC);

  $dbh->commit();
  json_ok($out ?: true);

} catch (Throwable $e) {
  if ($dbh->inTransaction()) $dbh->rollBack();
  json_err('DB_ERROR', 'db_error', 500);
}
