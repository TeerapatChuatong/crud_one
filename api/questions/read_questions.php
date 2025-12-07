<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$id = $_GET['question_id'] ?? null;

try {
  if ($id !== null && $id !== '') {
    // ----- อ่านคำถามเดียวตาม question_id -----
    if (!ctype_digit((string)$id)) {
      json_err("VALIDATION_ERROR", "invalid_question_id", 400);
    }

    $sql = "
      SELECT
        q.*,
        (
          SELECT GROUP_CONCAT(dq.disease_id ORDER BY dq.disease_id SEPARATOR ',')
          FROM disease_questions dq
          WHERE dq.question_id = q.question_id
        ) AS disease_ids
      FROM questions q
      WHERE q.question_id = ?
      LIMIT 1
    ";

    $st = $dbh->prepare($sql);
    $st->execute([(int)$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      json_err("NOT_FOUND", "not_found", 404);
    }
    json_ok($row);

  } else {
    // ----- อ่านคำถามทั้งหมด -----
    $sql = "
      SELECT
        q.*,
        (
          SELECT GROUP_CONCAT(dq.disease_id ORDER BY dq.disease_id SEPARATOR ',')
          FROM disease_questions dq
          WHERE dq.question_id = q.question_id
        ) AS disease_ids
      FROM questions q
      ORDER BY q.sort_order ASC, q.question_id ASC
    ";

    $st = $dbh->prepare($sql);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    json_ok($rows);
  }

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
