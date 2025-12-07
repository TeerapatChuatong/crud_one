<?php
// api/diagnosis_history/create_diagnosis_history.php
require_once __DIR__ . '/../db.php';

// ให้เฉพาะคนที่ล็อกอินเรียกได้
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

// ================= อ่าน & ตรวจสอบ JSON =================
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  json_err("INVALID_JSON", "invalid_json", 400);
}

// ดึงค่าจาก body
$session_id       = $data['session_id']       ?? null;   // ต้องส่ง
$tree_id          = trim($data['tree_id']     ?? '');    // optional
$final_disease_id = trim($data['final_disease_id'] ?? '');
$risk_level_code  = trim($data['risk_level_code']  ?? '');
$total_score      = $data['total_score']      ?? null;   // int
$treatment_id     = $data['treatment_id']     ?? null;   // optional int
$advice_text      = trim($data['advice_text'] ?? '');    // optional text

// ---------- validate เบื้องต้น ----------
if ($session_id === null || !ctype_digit((string)$session_id)) {
  json_err("VALIDATION_ERROR", "session_id_required", 400);
}
$session_id = (int)$session_id;

if ($final_disease_id === '') {
  json_err("VALIDATION_ERROR", "final_disease_id_required", 400);
}

if ($risk_level_code === '') {
  json_err("VALIDATION_ERROR", "risk_level_code_required", 400);
}

if ($total_score === null || filter_var($total_score, FILTER_VALIDATE_INT) === false) {
  json_err("VALIDATION_ERROR", "total_score_must_be_int", 400);
}
$total_score = (int)$total_score;

// treatment_id ถ้ามีก็ต้องเป็นตัวเลข
if ($treatment_id !== null && $treatment_id !== '' && !ctype_digit((string)$treatment_id)) {
  json_err("VALIDATION_ERROR", "invalid_treatment_id", 400);
}
$treatment_id = ($treatment_id === '' ? null : $treatment_id);

$currentUserId = (string)($_SESSION['user_id'] ?? '');

// ================== ดึง session เพื่อตรวจสอบ ==================
try {
  // 1) ดึง session ที่เกี่ยวข้อง
  $st = $dbh->prepare("
    SELECT *
    FROM diagnosis_sessions
    WHERE session_id = ?
  ");
  $st->execute([$session_id]);
  $sess = $st->fetch(PDO::FETCH_ASSOC);

  if (!$sess) {
    json_err("VALIDATION_ERROR", "session_not_found", 400);
  }

  // ตรวจสิทธิ์: user ปกติใช้ได้เฉพาะ session ของตัวเอง
  // (diagnosis_sessions.user_id เก็บอะไรไว้ให้ดูให้ตรงกับ $_SESSION['user_id'])
  $sessionUserId = (string)($sess['user_id'] ?? '');
  $is_admin = is_admin();
  if (!$is_admin && $sessionUserId !== $currentUserId) {
    json_err("FORBIDDEN", "cannot_use_other_user_session", 403);
  }

  // เตรียมข้อมูลจาก diagnosis_sessions
  $image_url             = $sess['uploaded_image_url']      ?? null;
  $predicted_disease_id  = $sess['predicted_disease_id']    ?? null;
  $predicted_confidence  = $sess['predicted_confidence']    ?? null;

  // ถ้าไม่ได้ส่ง final_disease_id มา อนุญาตให้ใช้ของ session (ถ้ามี)
  if ($final_disease_id === '' && !empty($sess['final_disease_id'])) {
    $final_disease_id = $sess['final_disease_id'];
  }

  if ($final_disease_id === '') {
    json_err("VALIDATION_ERROR", "final_disease_id_required", 400);
  }

  // ================== INSERT ลง diagnosis_history ==================
  $sql = "
    INSERT INTO diagnosis_history (
      user_id,
      session_id,
      tree_id,
      image_url,
      predicted_disease_id,
      predicted_confidence,
      final_disease_id,
      risk_level_code,
      total_score,
      treatment_id,
      advice_text
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ";

  $stIns = $dbh->prepare($sql);
  $stIns->execute([
    // user_id เก็บจาก session (หรือจะใช้ $sessionUserId ก็ได้ถ้าแน่ใจว่าตรงกัน)
    $currentUserId,
    $session_id,
    $tree_id !== '' ? $tree_id : null,
    $image_url,
    $predicted_disease_id,
    $predicted_confidence,
    $final_disease_id,
    $risk_level_code,
    $total_score,
    $treatment_id,
    $advice_text !== '' ? $advice_text : null,
  ]);

  $history_id = (int)$dbh->lastInsertId();

  // ดึงข้อมูลแถวที่เพิ่งสร้าง ส่งกลับ
  $st2 = $dbh->prepare("SELECT * FROM diagnosis_history WHERE history_id = ?");
  $st2->execute([$history_id]);
  $row = $st2->fetch(PDO::FETCH_ASSOC);

  json_ok($row);

} catch (Throwable $e) {
  // ถ้าต้อง debug ให้เปิดบรรทัดนี้แทนด้านล่างชั่วคราว:
  // json_err("DB_ERROR", $e->getMessage(), 500);
  json_err("DB_ERROR", "db_error", 500);
}
