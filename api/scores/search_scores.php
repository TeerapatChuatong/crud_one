<?php
// api/scores/search_scores.php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$disease_id  = trim($_GET['disease_id']  ?? '');
$question_id = trim($_GET['question_id'] ?? '');
$choice_id   = trim($_GET['choice_id']   ?? '');
$min_score   = trim($_GET['min_score']   ?? '');
$max_score   = trim($_GET['max_score']   ?? '');

try {
  $sql = "
    SELECT s.*
    FROM scores s
  ";

  $where  = [];
  $params = [];

  if ($disease_id !== '') {
    if (!ctype_digit($disease_id)) {
      json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
    }
    $where[]  = "s.disease_id = ?";
    $params[] = (int)$disease_id;
  }

  if ($question_id !== '') {
    if (!ctype_digit($question_id)) {
      json_err("VALIDATION_ERROR", "invalid_question_id", 400);
    }
    $where[]  = "s.question_id = ?";
    $params[] = (int)$question_id;
  }

  if ($choice_id !== '') {
    if (!ctype_digit($choice_id)) {
      json_err("VALIDATION_ERROR", "invalid_choice_id", 400);
    }
    $where[]  = "s.choice_id = ?";
    $params[] = (int)$choice_id;
  }

  if ($min_score !== '') {
    if (!ctype_digit($min_score)) {
      json_err("VALIDATION_ERROR", "invalid_min_score", 400);
    }
    $where[]  = "s.risk_score >= ?";
    $params[] = (int)$min_score;
  }

  if ($max_score !== '') {
    if (!ctype_digit($max_score)) {
      json_err("VALIDATION_ERROR", "invalid_max_score", 400);
    }
    $where[]  = "s.risk_score <= ?";
    $params[] = (int)$max_score;
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY s.disease_id ASC, s.question_id ASC, s.choice_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
