<?php
// api/diagnosis_history_answers/search_diagnosis_history_answers.php
require_once __DIR__ . '/../db.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$is_admin      = is_admin();
$currentUserId = (string)($_SESSION['user_id'] ?? '');

$history_id  = trim($_GET['history_id']  ?? '');
$question_id = trim($_GET['question_id'] ?? '');
$user_id_q   = trim($_GET['user_id']     ?? '');
$disease_id  = trim($_GET['disease_id']  ?? ''); // filter จาก final_disease_id

try {
  $sql = "
    SELECT a.*
    FROM diagnosis_history_answers a
    INNER JOIN diagnosis_history h
      ON h.history_id = a.history_id
  ";

  $where  = [];
  $params = [];

  // จำกัดสิทธิ์ตาม user
  if ($is_admin && $user_id_q !== '') {
    $where[]  = "h.user_id = ?";
    $params[] = $user_id_q;
  } else {
    $where[]  = "h.user_id = ?";
    $params[] = $currentUserId;
  }

  if ($history_id !== '') {
    if (!ctype_digit($history_id)) {
      json_err("VALIDATION_ERROR", "invalid_history_id", 400);
    }
    $where[]  = "a.history_id = ?";
    $params[] = (int)$history_id;
  }

  if ($question_id !== '') {
    if (!ctype_digit($question_id)) {
      json_err("VALIDATION_ERROR", "invalid_question_id", 400);
    }
    $where[]  = "a.question_id = ?";
    $params[] = (int)$question_id;
  }

  if ($disease_id !== '') {
    $where[]  = "h.final_disease_id = ?";
    $params[] = $disease_id;
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY a.history_id ASC, a.history_answer_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
