<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

/* รองรับทั้ง $pdo และ $dbh (กันกรณี db.php ใช้ $pdo) */
if (!isset($dbh) && isset($pdo)) {
  $dbh = $pdo;
}

// ถ้ามีระบบเช็ค admin อยู่แล้ว ก็สามารถเปิดบรรทัดนี้ได้
// require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'method_not_allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
  json_err('BAD_REQUEST', 'invalid_json', 400);
}

$diseaseId      = isset($body['disease_id']) ? (int)$body['disease_id'] : 0;
/* รองรับทั้งชื่อ key level_code และ risk_level_code จากฝั่ง frontend */
$levelCodeInput = $body['level_code'] ?? $body['risk_level_code'] ?? null;
$minScore       = isset($body['min_score']) ? (int)$body['min_score'] : 0;
$adviceText     = trim($body['advice_text'] ?? '');

/* ====== VALIDATION ====== */

if ($diseaseId <= 0) {
  json_err('VALIDATION_ERROR', 'disease_id_required', 400);
}

if (!$levelCodeInput) {
  json_err('VALIDATION_ERROR', 'level_code_required', 400);
}

$levelCode = strtolower($levelCodeInput);
$allowedLevels = ['low', 'medium', 'high'];
if (!in_array($levelCode, $allowedLevels, true)) {
  json_err('VALIDATION_ERROR', 'invalid_level_code', 400);
}

if ($adviceText === '') {
  json_err('VALIDATION_ERROR', 'advice_text_required', 400);
}

/* ====== MAIN LOGIC ====== */
try {
  $dbh->beginTransaction();

  // 1) สร้างแถวใหม่ใน disease_risk_levels → ได้ risk_level_id ถัดจากเลขล่าสุด
  $sqlRisk = "
    INSERT INTO disease_risk_levels (disease_id, level_code, min_score)
    VALUES (:disease_id, :level_code, :min_score)
  ";
  $stmtRisk = $dbh->prepare($sqlRisk);
  $okRisk = $stmtRisk->execute([
    ':disease_id' => $diseaseId,
    ':level_code' => $levelCode,
    ':min_score'  => $minScore,
  ]);

  if (!$okRisk) {
    $dbh->rollBack();
    json_err('DB_ERROR', 'insert_risk_failed', 500);
  }

  // id ใหม่ในตาราง disease_risk_levels เช่น 23
  $riskLevelId = (int)$dbh->lastInsertId();

  // 2) ใช้ risk_level_id ที่เพิ่งได้ ไปสร้างแถวใหม่ใน treatments
  $sqlTreat = "
    INSERT INTO treatments (disease_id, risk_level_id, advice_text)
    VALUES (:disease_id, :risk_level_id, :advice_text)
  ";
  $stmtTreat = $dbh->prepare($sqlTreat);
  $okTreat = $stmtTreat->execute([
    ':disease_id'    => $diseaseId,
    ':risk_level_id' => $riskLevelId,
    ':advice_text'   => $adviceText,
  ]);

  if (!$okTreat) {
    $dbh->rollBack();
    json_err('DB_ERROR', 'insert_treatment_failed', 500);
  }

  $treatmentId = (int)$dbh->lastInsertId();
  $now = date('Y-m-d H:i:s');

  $dbh->commit();

  http_response_code(201);
  json_ok([
    'data' => [
      'treatment_id'  => $treatmentId,
      'disease_id'    => $diseaseId,
      'risk_level_id' => $riskLevelId,  // ← จะเป็นเลขต่อจากอันล่าสุดใน disease_risk_levels
      'advice_text'   => $adviceText,
      'updated_at'    => $now,
    ],
  ]);
} catch (Throwable $e) {
  if ($dbh->inTransaction()) {
    $dbh->rollBack();
  }
  json_err('DB_ERROR', 'db_error', 500);
}
