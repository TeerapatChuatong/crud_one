<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'patch_only', 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

// ---- Inputs ----
$treatment_id = $body['treatment_id'] ?? null;

// รับได้ทั้ง risk_level_id (เลข) หรือ disease_id + level_code
$risk_level_id_raw = $body['risk_level_id'] ?? null;
$disease_id_raw    = $body['disease_id'] ?? null;

$level_code = strtolower(trim((string)($body['level_code'] ?? '')));

// รองรับกรณี frontend เผลอส่ง "ปานกลาง" หรือส่ง level ไปอยู่ใน risk_level_id
$level_th_map = [
  'ต่ำ' => 'low', 'น้อย' => 'low',
  'ปานกลาง' => 'medium', 'กลาง' => 'medium',
  'มาก' => 'high', 'รุนแรง' => 'high', 'สูง' => 'high',
];
if ($level_code === '' && is_string($risk_level_id_raw)) {
  $tmp = strtolower(trim($risk_level_id_raw));
  if (isset($level_th_map[$tmp])) $level_code = $level_th_map[$tmp];
  if (in_array($tmp, ['low','medium','high'], true)) $level_code = $tmp;
}

$advice_text = trim((string)($body['advice_text'] ?? ''));

if ($treatment_id === null || !ctype_digit((string)$treatment_id)) {
  json_err('VALIDATION_ERROR', 'invalid_treatment_id', 400);
}
if ($advice_text === '') {
  json_err('VALIDATION_ERROR', 'advice_text_required', 400);
}

$min_raw = $body['min_score'] ?? null;
if ($min_raw === null || $min_raw === '' || !is_numeric($min_raw)) {
  json_err('VALIDATION_ERROR', 'invalid_min_score', 400);
}
$min_score = (int)$min_raw;

$days_raw = $body['days'] ?? 0;
$times_raw = $body['times'] ?? 0;

if (!is_numeric($days_raw)) json_err('VALIDATION_ERROR', 'invalid_days', 400);
if (!is_numeric($times_raw)) json_err('VALIDATION_ERROR', 'invalid_times', 400);

$days = (int)$days_raw;
$times = (int)$times_raw;

// helper: parse int from "12" only
$parsed_risk_level_id = null;
if ($risk_level_id_raw !== null) {
  if (is_int($risk_level_id_raw) || (is_string($risk_level_id_raw) && ctype_digit(trim($risk_level_id_raw)))) {
    $parsed_risk_level_id = (int)$risk_level_id_raw;
  } elseif (is_string($risk_level_id_raw)) {
    // ถ้าเป็น "id: 12" หรือ "12 (medium)" ให้ดึงเลขออก
    if (preg_match('/\b(\d+)\b/', $risk_level_id_raw, $m)) {
      $parsed_risk_level_id = (int)$m[1];
    }
  }
}

$allowedLevels = ['low','medium','high'];

try {
  $dbh->beginTransaction();

  // 1) load current treatment + current risk level
  $stCur = $dbh->prepare("
    SELECT
      t.treatment_id,
      t.risk_level_id AS current_risk_level_id,
      rl.disease_id  AS current_disease_id,
      rl.level_code  AS current_level_code
    FROM treatments t
    JOIN disease_risk_levels rl ON rl.risk_level_id = t.risk_level_id
    WHERE t.treatment_id = ?
    LIMIT 1
  ");
  $stCur->execute([(int)$treatment_id]);
  $cur = $stCur->fetch(PDO::FETCH_ASSOC);

  if (!$cur) {
    $dbh->rollBack();
    json_err('NOT_FOUND', 'treatment_not_found', 404);
  }

  $target_risk_level_id = (int)$cur['current_risk_level_id'];

  // 2) determine target risk_level_id
  if ($parsed_risk_level_id !== null) {
    // verify exists
    $stV = $dbh->prepare("SELECT risk_level_id FROM disease_risk_levels WHERE risk_level_id=? LIMIT 1");
    $stV->execute([$parsed_risk_level_id]);
    $v = $stV->fetch(PDO::FETCH_ASSOC);
    if (!$v) {
      $dbh->rollBack();
      json_err('VALIDATION_ERROR', 'invalid_risk_level_id', 400);
    }
    $target_risk_level_id = (int)$parsed_risk_level_id;

  } else {
    // ถ้ามี disease_id + level_code ให้หา risk_level_id ตามคู่นี้
    $disease_id = null;
    if ($disease_id_raw !== null && ctype_digit((string)$disease_id_raw)) {
      $disease_id = (int)$disease_id_raw;
    }

    if ($disease_id !== null && $level_code !== '') {
      if (!in_array($level_code, $allowedLevels, true)) {
        $dbh->rollBack();
        json_err('VALIDATION_ERROR', 'invalid_level_code', 400);
      }

      $stFind = $dbh->prepare("SELECT risk_level_id FROM disease_risk_levels WHERE disease_id=? AND level_code=? LIMIT 1");
      $stFind->execute([$disease_id, $level_code]);
      $found = $stFind->fetch(PDO::FETCH_ASSOC);

      if ($found) {
        $target_risk_level_id = (int)$found['risk_level_id'];
      } else {
        // ถ้าไม่มี risk level นี้ในตาราง -> สร้างใหม่ (ให้สอดคล้องกับ create_treatments.php)
        $stIns = $dbh->prepare("
          INSERT INTO disease_risk_levels (disease_id, level_code, min_score, days, times)
          VALUES (?, ?, ?, ?, ?)
        ");
        $stIns->execute([$disease_id, $level_code, $min_score, $days, $times]);
        $target_risk_level_id = (int)$dbh->lastInsertId();
      }
    }
    // ถ้าไม่ได้ส่งอะไรมาเลย -> ใช้ current_risk_level_id (ปล่อยผ่านให้แก้ advice/min/days/times ได้)
  }

  // 3) ถ้าเปลี่ยน risk_level_id ต้องกันชน UNIQUE (1 risk level มีได้ 1 treatment)
  if ($target_risk_level_id !== (int)$cur['current_risk_level_id']) {
    $stDup = $dbh->prepare("SELECT treatment_id FROM treatments WHERE risk_level_id=? AND treatment_id<>? LIMIT 1");
    $stDup->execute([$target_risk_level_id, (int)$treatment_id]);
    if ($stDup->fetch(PDO::FETCH_ASSOC)) {
      $dbh->rollBack();
      json_err('DUPLICATE', 'โรค+ระดับนี้มีคำแนะนำอยู่แล้ว (ไม่สามารถแก้ไปซ้ำได้)', 409);
    }

    $stMove = $dbh->prepare("UPDATE treatments SET risk_level_id=? WHERE treatment_id=?");
    $stMove->execute([$target_risk_level_id, (int)$treatment_id]);
  }

  // 4) update risk level threshold + schedule
  $st1 = $dbh->prepare('UPDATE disease_risk_levels SET min_score=?, days=?, times=? WHERE risk_level_id=?');
  $st1->execute([$min_score, $days, $times, $target_risk_level_id]);

  // 5) update advice
  $st2 = $dbh->prepare('UPDATE treatments SET advice_text=? WHERE treatment_id=?');
  $st2->execute([$advice_text, (int)$treatment_id]);

  // 6) return updated row
  $st3 = $dbh->prepare("
    SELECT
      t.treatment_id, t.risk_level_id, t.advice_text, t.created_at,
      rl.disease_id, rl.level_code, rl.min_score, rl.days, rl.times,
      d.disease_th, d.disease_en
    FROM treatments t
    JOIN disease_risk_levels rl ON rl.risk_level_id = t.risk_level_id
    JOIN diseases d ON d.disease_id = rl.disease_id
    WHERE t.treatment_id=?
    LIMIT 1
  ");
  $st3->execute([(int)$treatment_id]);
  $row = $st3->fetch(PDO::FETCH_ASSOC);

  $dbh->commit();
  json_ok($row ?: true);

} catch (Throwable $e) {
  if ($dbh->inTransaction()) $dbh->rollBack();
  json_err('DB_ERROR', 'db_error', 500);
}
