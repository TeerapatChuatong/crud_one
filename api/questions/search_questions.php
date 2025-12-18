<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$q = trim($_GET['q'] ?? '');

try {
  $sql = "
    SELECT
      qn.question_id AS id,
      qn.question_id,
      qn.question_text,
      qn.question_type,
      qn.sort_order,
      dq.disease_question_id,
      dq.disease_id,
      d.disease_th AS disease_name
    FROM questions qn
    LEFT JOIN disease_questions dq ON dq.question_id = qn.question_id
    LEFT JOIN diseases d ON d.disease_id = dq.disease_id
  ";

  $params = [];
  if ($q !== '') {
    $sql .= " WHERE qn.question_text LIKE ? OR d.disease_th LIKE ? OR d.disease_en LIKE ?";
    $params = ["%$q%","%$q%","%$q%"];
  }

  $sql .= " ORDER BY qn.question_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
