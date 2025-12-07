<?php
// api/diagnosis_sessions/create_diagnosis_sessions.php
require_once __DIR__ . '/../db.php';

require_login(); // ต้องล็อกอินก่อนถึงจะสร้าง session ได้

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

// อ่าน JSON body
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!is_array($data)) {
  json_err("INVALID_JSON", "invalid_json", 400);
}

// ----------------- ดึงค่า & validate ง่าย ๆ -----------------
$uploaded_image_url   = trim($data['uploaded_image_url']   ?? '');
$predicted_disease_id = trim($data['predicted_disease_id'] ?? '');
$predicted_confidence = $data['predicted_confidence']      ?? null;
$final_disease_id     = trim($data['final_disease_id']     ?? '');
$status               = trim($data['status']               ?? 'image_only');

if ($uploaded_image_url === '') {
  json_err("VALIDATION_ERROR", "uploaded_image_url_required", 400);
}
if ($predicted_disease_id === '') {
  json_err("VALIDATION_ERROR", "predicted_disease_id_required", 400);
}
if (!is_null($predicted_confidence) && !is_numeric($predicted_confidence)) {
  json_err("VALIDATION_ERROR", "predicted_confidence_must_be_number", 400);
}
if ($final_disease_id === '') {
  // ถ้าช่วงแรกยังไม่ได้ตัดสินโรคสุดท้าย จะอนุญาตว่างก็ได้
  // json_err("VALIDATION_ERROR", "final_disease_id_required", 400);
}

$allowed_status = ['image_only', 'in_severity', 'completed'];
if (!in_array($status, $allowed_status, true)) {
  json_err("VALIDATION_ERROR", "invalid_status", 400);
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
  json_err("AUTH_ERROR", "no_user_in_session", 401);
}

// ----------------- บันทึกลงฐานข้อมูล -----------------
try {
  $sql = "
    INSERT INTO diagnosis_sessions (
      user_id,
      uploaded_image_url,
      predicted_disease_id,
      predicted_confidence,
      final_disease_id,
      status
    )
    VALUES (?, ?, ?, ?, ?, ?)
  ";

  $st = $dbh->prepare($sql);
  $st->execute([
    $user_id,
    $uploaded_image_url,
    $predicted_disease_id,
    $predicted_confidence,
    $final_disease_id,
    $status,
  ]);

  // ให้ DB สร้าง session_id แล้วดึง id ล่าสุดออกมา
  $session_id = (int)$dbh->lastInsertId();

  // ดึงข้อมูลแถวที่เพิ่งสร้างส่งกลับ
  $st2 = $dbh->prepare("SELECT * FROM diagnosis_sessions WHERE session_id = ?");
  $st2->execute([$session_id]);
  $row = $st2->fetch(PDO::FETCH_ASSOC);

  json_ok($row);

} catch (Throwable $e) {
  // ถ้าต้องการ debug ชั่วคราว:
  // json_err("DB_ERROR", $e->getMessage(), 500);
  json_err("DB_ERROR", "db_error", 500);
}
