<?php
// read_diagnosis_history.php
require_once __DIR__ . '/../db.php';

// ต้องล็อกอินก่อน
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

$is_admin    = is_admin();
$current_uid = (string)($_SESSION['user_id'] ?? '');

$history_id       = $_GET['history_id']       ?? null;
$user_id_param    = $_GET['user_id']          ?? null; // ใช้เฉพาะ admin
$session_id       = $_GET['session_id']       ?? null;
$final_disease_id = $_GET['final_disease_id'] ?? null;
$risk_level_code  = $_GET['risk_level_code']  ?? null;

try {
  // ---------- กรณีอ่านแถวเดียวด้วย history_id ----------
  if ($history_id !== null && $history_id !== '') {
    if (!ctype_digit((string)$history_id)) {
      json_err("VALIDATION_ERROR", "invalid_history_id", 400);
    }

    $st = $dbh->prepare("SELECT * FROM diagnosis_history WHERE history_id = ?");
    $st->execute([(int)$history_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      json_err("NOT_FOUND", "not_found", 404);
    }

    // user ธรรมดาดูได้เฉพาะของตัวเอง
    if (!$is_admin && (string)$row['user_id'] !== $current_uid) {
      json_err("FORBIDDEN", "can_only_view_own_history", 403);
    }

    json_ok($row);
  }

  // ---------- กรณีอ่านหลายแถว (list) ----------
  $where  = [];
  $params = [];

  // ถ้าไม่ใช่ admin → บังคับ user_id = ตัวเอง
  if (!$is_admin) {
    $where[]  = "user_id = ?";
    $params[] = $current_uid;
  } else {
    if ($user_id_param !== null && $user_id_param !== '') {
      $where[]  = "user_id = ?";
      $params[] = $user_id_param;
    }
  }

  if ($session_id !== null && $session_id !== '') {
    $where[]  = "session_id = ?";
    $params[] = $session_id;
  }

  if ($final_disease_id !== null && $final_disease_id !== '') {
    $where[]  = "final_disease_id = ?";
    $params[] = $final_disease_id;
  }

  if ($risk_level_code !== null && $risk_level_code !== '') {
    $where[]  = "risk_level_code = ?";
    $params[] = $risk_level_code;
  }

  $sql = "SELECT * FROM diagnosis_history";
  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }
  $sql .= " ORDER BY created_at DESC, history_id DESC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  json_ok($rows);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
