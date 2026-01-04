<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php'; // ✅ app ส่ง Bearer token ได้

try {
  // ✅ แค่อยู่ในระบบก็อ่านได้ ไม่ต้องเป็นแอดมิน
  // $AUTH_USER = require_auth(); (ถูกเรียกใน require_auth.php แล้ว)

  $disease_id = $_GET['disease_id'] ?? null;

  $sql = "
    SELECT
      dq.disease_question_id AS id,
      dq.disease_question_id,
      dq.disease_id,
      dq.question_id,
      dq.sort_order,
      d.disease_th,
      d.disease_en,
      qn.question_text,
      qn.question_type,
      qn.max_score,
      qn.sort_order AS question_sort_order
    FROM disease_questions dq
    LEFT JOIN diseases d ON d.disease_id = dq.disease_id
    INNER JOIN questions qn ON qn.question_id = dq.question_id
  ";

  $where  = [];
  $params = [];

  if ($disease_id !== null && $disease_id !== '' && $disease_id !== 'all') {
    if (!ctype_digit((string)$disease_id)) {
      json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
    }
    $where[]  = "dq.disease_id = ?";
    $params[] = (int)$disease_id;
  }

  if ($where) $sql .= " WHERE " . implode(" AND ", $where);

  $sql .= " ORDER BY dq.sort_order ASC, qn.sort_order ASC, dq.disease_question_id ASC";

  $st = $pdo->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
