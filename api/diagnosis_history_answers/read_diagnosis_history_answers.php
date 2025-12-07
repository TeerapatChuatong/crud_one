<?php
// read_diagnosis_history_answers.php
require_once __DIR__ . '/../db.php';

// ต้องล็อกอินก่อน
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

$is_admin    = is_admin();
$current_uid = (string)($_SESSION['user_id'] ?? '');

$history_answer_id = $_GET['history_answer_id'] ?? null;
$history_id        = $_GET['history_id']        ?? null;
$question_id       = $_GET['question_id']       ?? null;

try {
  // ----- กรณีอ่านหนึ่งแถวด้วย history_answer_id -----
  if ($history_answer_id !== null && $history_answer_id !== '') {
    if (!ctype_digit((string)$history_answer_id)) {
      json_err("VALIDATION_ERROR", "invalid_history_answer_id", 400);
    }

    $sql = "SELECT a.*, h.user_id AS history_user_id
            FROM diagnosis_history_answers a
            JOIN diagnosis_history h ON a.history_id = h.history_id
            WHERE a.history_answer_id = ?";
    $st = $dbh->prepare($sql);
    $st->execute([(int)$history_answer_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      json_err("NOT_FOUND", "not_found", 404);
    }

    if (!$is_admin && (string)$row['history_user_id'] !== $current_uid) {
      json_err("FORBIDDEN", "can_only_view_own_history_answers", 403);
    }

    // ลบฟิลด์ชั่วคราวออกก่อนส่ง
    unset($row['history_user_id']);

    json_ok($row);
  }

  // ----- กรณีอ่านหลายแถว -----

  if ($is_admin) {
    // admin: ดึงจากตาราง answers ตรง ๆ
    $where  = [];
    $params = [];
    $sql    = "SELECT * FROM diagnosis_history_answers";

    if ($history_id !== null && $history_id !== '') {
      if (!ctype_digit((string)$history_id)) {
        json_err("VALIDATION_ERROR", "invalid_history_id", 400);
      }
      $where[]  = "history_id = ?";
      $params[] = (int)$history_id;
    }

    if ($question_id !== null && $question_id !== '') {
      if (!ctype_digit((string)$question_id)) {
        json_err("VALIDATION_ERROR", "invalid_question_id", 400);
      }
      $where[]  = "question_id = ?";
      $params[] = (int)$question_id;
    }

    if ($where) {
      $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY history_id ASC, history_answer_id ASC";

    $st = $dbh->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    json_ok($rows);
  } else {
    // user ทั่วไป: join กับ diagnosis_history เพื่อเช็คว่าเป็นของตัวเอง
    $params = [$current_uid];
    $sql = "SELECT a.*
            FROM diagnosis_history_answers a
            JOIN diagnosis_history h ON a.history_id = h.history_id
            WHERE h.user_id = ?";

    if ($history_id !== null && $history_id !== '') {
      if (!ctype_digit((string)$history_id)) {
        json_err("VALIDATION_ERROR", "invalid_history_id", 400);
      }
      $sql     .= " AND a.history_id = ?";
      $params[] = (int)$history_id;
    }

    if ($question_id !== null && $question_id !== '') {
      if (!ctype_digit((string)$question_id)) {
        json_err("VALIDATION_ERROR", "invalid_question_id", 400);
      }
      $sql     .= " AND a.question_id = ?";
      $params[] = (int)$question_id;
    }

    $sql .= " ORDER BY a.history_id ASC, a.history_answer_id ASC";

    $st = $dbh->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    json_ok($rows);
  }
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
