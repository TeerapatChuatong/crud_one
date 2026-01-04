<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

// ✅ บังคับล็อกอิน (เหมือน endpoint อื่นในโปรเจกต์คุณ)
$session_uid = (int)($_SESSION["user_id"] ?? 0);
if ($session_uid <= 0) json_err("UNAUTHORIZED", "Please login", 401);

$choice_id           = $_GET['choice_id'] ?? null;
$question_id         = $_GET['question_id'] ?? null;
$disease_question_id = $_GET['disease_question_id'] ?? null;

try {

  // ✅ ถ้าเรียกด้วย disease_question_id -> คืน choices + score_value (จาก scores)
  if ($disease_question_id !== null && $disease_question_id !== '') {
    if (!ctype_digit((string)$disease_question_id)) {
      json_err("VALIDATION_ERROR","invalid_disease_question_id",400);
    }

    $st = $dbh->prepare("
      SELECT
        dq.disease_question_id,
        dq.question_id,
        c.*,
        COALESCE(s.score_value, 0) AS score_value
      FROM disease_questions dq
      INNER JOIN choices c
        ON c.question_id = dq.question_id
      LEFT JOIN scores s
        ON s.disease_question_id = dq.disease_question_id
       AND s.choice_id = c.choice_id
      WHERE dq.disease_question_id = ?
      ORDER BY c.sort_order ASC, c.choice_id ASC
    ");
    $st->execute([(int)$disease_question_id]);
    json_ok($st->fetchAll(PDO::FETCH_ASSOC));
  }

  // อ่าน choice เดี่ยว
  if ($choice_id !== null && $choice_id !== '') {
    if (!ctype_digit((string)$choice_id)) {
      json_err("VALIDATION_ERROR","invalid_choice_id",400);
    }
    $st = $dbh->prepare("SELECT * FROM choices WHERE choice_id=?");
    $st->execute([(int)$choice_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err("NOT_FOUND","not_found",404);
    json_ok($row);

  // อ่าน choices ตาม question_id (ไม่มีคะแนน)
  } elseif ($question_id !== null && $question_id !== '') {
    if (!ctype_digit((string)$question_id)) {
      json_err("VALIDATION_ERROR","invalid_question_id",400);
    }
    $st = $dbh->prepare("
      SELECT * FROM choices
      WHERE question_id=?
      ORDER BY sort_order ASC, choice_id ASC
    ");
    $st->execute([(int)$question_id]);
    json_ok($st->fetchAll(PDO::FETCH_ASSOC));

  } else {
    $st = $dbh->query("
      SELECT * FROM choices
      ORDER BY question_id ASC, sort_order ASC, choice_id ASC
    ");
    json_ok($st->fetchAll(PDO::FETCH_ASSOC));
  }

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
