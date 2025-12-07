<?php
// api/disease_questions/search_disease_questions.php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$disease_id  = trim($_GET['disease_id']  ?? '');
$question_id = trim($_GET['question_id'] ?? '');
$q           = trim($_GET['q']           ?? ''); // ค้นในข้อความคำถาม

try {
  $sql = "
    SELECT dq.id,
           dq.disease_id,
           dq.question_id,
           q.question_text,
           q.question_type,
           q.sort_order
    FROM disease_questions dq
    INNER JOIN questions q
      ON q.question_id = dq.question_id
  ";

  $where  = [];
  $params = [];

  if ($disease_id !== '') {
    $where[]  = "dq.disease_id = ?";
    $params[] = $disease_id;
  }

  if ($question_id !== '') {
    if (!ctype_digit($question_id)) {
      json_err("VALIDATION_ERROR", "invalid_question_id", 400);
    }
    $where[]  = "dq.question_id = ?";
    $params[] = (int)$question_id;
  }

  if ($q !== '') {
    $where[]  = "q.question_text LIKE ?";
    $params[] = "%{$q}%";
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY dq.disease_id ASC, q.sort_order ASC, dq.id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
