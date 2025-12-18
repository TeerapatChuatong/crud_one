<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

try {
  require_admin(); // ✅ ถ้าเป็น endpoint สำหรับแอดมิน

  $sql = "
    SELECT
      q.question_id AS id,
      q.question_id,
      q.question_text,
      q.question_type,
      q.sort_order,
      dq.disease_question_id,
      dq.disease_id,
      d.disease_th AS disease_name
    FROM questions q
    LEFT JOIN (
      SELECT dq1.*
      FROM disease_questions dq1
      JOIN (
        SELECT question_id, MIN(disease_question_id) AS min_id
        FROM disease_questions
        GROUP BY question_id
      ) m ON m.min_id = dq1.disease_question_id
    ) dq ON dq.question_id = q.question_id
    LEFT JOIN diseases d ON d.disease_id = dq.disease_id
    ORDER BY q.question_id ASC
  ";

  $st = $pdo->prepare($sql); // ✅ ใช้ $pdo ถ้า db.php ของคุณเป็น PDO ชื่อนี้
  $st->execute();
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
