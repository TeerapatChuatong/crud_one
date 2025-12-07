<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$score_id   = $_GET['score_id']   ?? null;
$disease_id = $_GET['disease_id'] ?? null;
$question_id = $_GET['question_id'] ?? null;

try {
  if ($score_id !== null && $score_id !== '') {
    if (!ctype_digit((string)$score_id)) {
      json_err("VALIDATION_ERROR","invalid_score_id",400);
    }
    $st = $dbh->prepare("SELECT * FROM scores WHERE score_id=?");
    $st->execute([(int)$score_id]);
    $row = $st->fetch();
    if (!$row) json_err("NOT_FOUND","not_found",404);
    json_ok($row);
  } else {
    $where = [];
    $params = [];
    if ($disease_id !== null && $disease_id !== '') {
      if (!ctype_digit((string)$disease_id)) {
        json_err("VALIDATION_ERROR","invalid_disease_id",400);
      }
      $where[] = "disease_id=?";
      $params[] = (int)$disease_id;
    }
    if ($question_id !== null && $question_id !== '') {
      if (!ctype_digit((string)$question_id)) {
        json_err("VALIDATION_ERROR","invalid_question_id",400);
      }
      $where[] = "question_id=?";
      $params[] = (int)$question_id;
    }

    $sql = "SELECT * FROM scores";
    if ($where) {
      $sql .= " WHERE ".implode(" AND ",$where);
    }
    $sql .= " ORDER BY score_id ASC";

    $st = $dbh->prepare($sql);
    $st->execute($params);
    json_ok($st->fetchAll());
  }
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
