<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$q = trim($_GET['q'] ?? '');
$question_id = trim($_GET['question_id'] ?? '');

try {
  $sql = "
    SELECT
      c.choice_id,
      c.question_id,
      c.choice_label,
      c.choice_value,
      c.image_url,
      c.sort_order,
      q.question_text
    FROM choices c
    LEFT JOIN questions q ON q.question_id = c.question_id
  ";

  $where  = [];
  $params = [];

  if ($question_id !== '') {
    if (!ctype_digit($question_id)) json_err("VALIDATION_ERROR","invalid_question_id",400);
    $where[] = "c.question_id = ?";
    $params[] = (int)$question_id;
  }

  if ($q !== '') {
    $where[] = "c.choice_label LIKE ?";
    $params[] = "%{$q}%";
  }

  if ($where) $sql .= " WHERE " . implode(" AND ", $where);

  $sql .= " ORDER BY c.question_id ASC, c.sort_order ASC, c.choice_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
