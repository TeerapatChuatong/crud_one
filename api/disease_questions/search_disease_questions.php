<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$disease_id  = trim($_GET['disease_id']  ?? '');
$question_id = trim($_GET['question_id'] ?? '');
$q           = trim($_GET['q']           ?? '');

try {
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
      qn.sort_order AS question_sort_order
    FROM disease_questions dq
    LEFT JOIN diseases d ON d.disease_id = dq.disease_id
    INNER JOIN questions qn ON qn.question_id = dq.question_id
  ";

  $where  = [];
  $params = [];

  if ($disease_id !== '') {
    if (!ctype_digit($disease_id)) json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
    $where[]  = "dq.disease_id = ?";
    $params[] = (int)$disease_id;
  }

  if ($question_id !== '') {
    if (!ctype_digit($question_id)) json_err("VALIDATION_ERROR", "invalid_question_id", 400);
    $where[]  = "dq.question_id = ?";
    $params[] = (int)$question_id;
  }

  if ($q !== '') {
    $where[]  = "(qn.question_text LIKE ? OR d.disease_th LIKE ? OR d.disease_en LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
  }

  if ($where) $sql .= " WHERE " . implode(" AND ", $where);

  $sql .= " ORDER BY dq.disease_id ASC, dq.sort_order ASC, qn.sort_order ASC, dq.disease_question_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
