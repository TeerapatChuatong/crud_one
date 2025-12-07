<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$id          = $_GET['id'] ?? null;
$disease_id  = $_GET['disease_id'] ?? null;
$question_id = $_GET['question_id'] ?? null;

try {
  if ($id !== null && $id !== '') {
    if (!ctype_digit((string)$id)) {
      json_err("VALIDATION_ERROR","invalid_id",400);
    }
    $st = $dbh->prepare("SELECT * FROM disease_questions WHERE id=?");
    $st->execute([(int)$id]);
    $row = $st->fetch();
    if (!$row) json_err("NOT_FOUND","not_found",404);
    json_ok($row);
  }

  $where = [];
  $params = [];

  if ($disease_id !== null && $disease_id !== '') {
    $where[] = "disease_id=?";
    $params[] = $disease_id;
  }
  if ($question_id !== null && $question_id !== '') {
    if (!ctype_digit((string)$question_id)) {
      json_err("VALIDATION_ERROR","invalid_question_id",400);
    }
    $where[] = "question_id=?";
    $params[] = (int)$question_id;
  }

  $sql = "SELECT * FROM disease_questions";
  if ($where) {
    $sql .= " WHERE ".implode(" AND ",$where);
  }
  $sql .= " ORDER BY disease_id ASC, question_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll());
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
