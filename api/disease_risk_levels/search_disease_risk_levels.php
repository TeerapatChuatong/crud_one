<?php
// api/disease_risk_levels/search_disease_risk_levels.php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$disease_id = trim($_GET['disease_id'] ?? '');
$level_code = trim($_GET['level_code'] ?? ''); // low/medium/high
$min_score  = trim($_GET['min_score'] ?? '');  // filter >=

try {
  $sql    = "SELECT * FROM disease_risk_levels";
  $where  = [];
  $params = [];

  if ($disease_id !== '') {
    if (!ctype_digit($disease_id)) {
      json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
    }
    $where[]  = "disease_id = ?";
    $params[] = (int)$disease_id;
  }

  if ($level_code !== '') {
    $where[]  = "level_code = ?";
    $params[] = $level_code;
  }

  if ($min_score !== '') {
    if (!ctype_digit($min_score)) {
      json_err("VALIDATION_ERROR", "invalid_min_score", 400);
    }
    $where[]  = "min_score >= ?";
    $params[] = (int)$min_score;
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY disease_id ASC, min_score ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
