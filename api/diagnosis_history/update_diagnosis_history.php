<?php
// update_diagnosis_history.php
require_once __DIR__ . '/../db.php';

// ให้เฉพาะ admin แก้ไข
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

$body       = json_decode(file_get_contents('php://input'), true) ?: [];
$history_id = $body['history_id'] ?? null;

if (!$history_id || !ctype_digit((string)$history_id)) {
  json_err("VALIDATION_ERROR", "invalid_history_id", 400);
}

$allowed_levels = ['low', 'medium', 'high'];

$fields = [];
$params = [];

// อัปเดตเท่าที่ส่งมาใน body

if (array_key_exists('session_id', $body)) {
  $v = trim($body['session_id'] ?? '');
  if ($v === '') json_err("VALIDATION_ERROR", "session_id_required", 400);
  $fields[] = "session_id = ?";
  $params[] = $v;
}

if (array_key_exists('user_id', $body)) {
  $v = trim($body['user_id'] ?? '');
  if ($v === '') json_err("VALIDATION_ERROR", "user_id_required", 400);
  $fields[] = "user_id = ?";
  $params[] = $v;
}

if (array_key_exists('tree_id', $body)) {
  $v = trim($body['tree_id'] ?? '');
  $fields[] = "tree_id = ?";
  $params[] = ($v === '' ? null : $v);
}

if (array_key_exists('image_url', $body)) {
  $v = trim($body['image_url'] ?? '');
  if ($v === '') json_err("VALIDATION_ERROR", "image_url_required", 400);
  $fields[] = "image_url = ?";
  $params[] = $v;
}

if (array_key_exists('predicted_disease_id', $body)) {
  $v = trim($body['predicted_disease_id'] ?? '');
  if ($v === '') json_err("VALIDATION_ERROR", "predicted_disease_id_required", 400);
  $fields[] = "predicted_disease_id = ?";
  $params[] = $v;
}

if (array_key_exists('predicted_confidence', $body)) {
  $v = $body['predicted_confidence'];
  if (!is_numeric($v)) json_err("VALIDATION_ERROR", "invalid_predicted_confidence", 400);
  $fields[] = "predicted_confidence = ?";
  $params[] = (float)$v;
}

if (array_key_exists('final_disease_id', $body)) {
  $v = trim($body['final_disease_id'] ?? '');
  if ($v === '') json_err("VALIDATION_ERROR", "final_disease_id_required", 400);
  $fields[] = "final_disease_id = ?";
  $params[] = $v;
}

if (array_key_exists('risk_level_code', $body)) {
  $v = trim($body['risk_level_code'] ?? '');
  if (!in_array($v, $allowed_levels, true)) {
    json_err("VALIDATION_ERROR", "invalid_risk_level_code", 400);
  }
  $fields[] = "risk_level_code = ?";
  $params[] = $v;
}

if (array_key_exists('total_score', $body)) {
  $v = $body['total_score'];
  if (!is_numeric($v)) json_err("VALIDATION_ERROR", "invalid_total_score", 400);
  $fields[] = "total_score = ?";
  $params[] = (int)$v;
}

if (array_key_exists('treatment_id', $body)) {
  $v = $body['treatment_id'];
  if ($v !== null && $v !== '' && !ctype_digit((string)$v)) {
    json_err("VALIDATION_ERROR", "invalid_treatment_id", 400);
  }
  $fields[] = "treatment_id = ?";
  $params[] = ($v === '' ? null : $v);
}

if (array_key_exists('advice_text', $body)) {
  $v = trim($body['advice_text'] ?? '');
  if ($v === '') json_err("VALIDATION_ERROR", "advice_text_required", 400);
  $fields[] = "advice_text = ?";
  $params[] = $v;
}

if (!$fields) {
  json_err("VALIDATION_ERROR", "nothing_to_update", 400);
}

$params[] = (int)$history_id;

try {
  $sql = "UPDATE diagnosis_history
          SET " . implode(', ', $fields) . "
          WHERE history_id = ?";
  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
