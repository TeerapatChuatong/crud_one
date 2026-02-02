<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$disease_id = trim((string)($_GET['disease_id'] ?? ''));
$level_code = trim((string)($_GET['level_code'] ?? ''));
$min_score  = trim((string)($_GET['min_score'] ?? ''));
$days       = trim((string)($_GET['days'] ?? ''));
$times      = trim((string)($_GET['times'] ?? ''));
$sprays_per_product     = trim((string)($_GET['sprays_per_product'] ?? ''));
$max_products_per_group = trim((string)($_GET['max_products_per_group'] ?? ''));
/* ✅ เพิ่ม */
$max_sprays_per_group   = trim((string)($_GET['max_sprays_per_group'] ?? ''));

try {
  $sql = "
    SELECT
      risk_level_id AS id,
      risk_level_id,
      disease_id,
      level_code,
      min_score,
      days,
      times,
      sprays_per_product,
      max_products_per_group,
      max_sprays_per_group
    FROM disease_risk_levels
  ";
  $where  = [];
  $params = [];

  if ($disease_id !== '') {
    if (!ctype_digit($disease_id)) json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
    $where[]  = "disease_id = ?";
    $params[] = (int)$disease_id;
  }
  if ($level_code !== '') {
    $where[]  = "level_code = ?";
    $params[] = $level_code;
  }
  if ($min_score !== '') {
    if (!ctype_digit($min_score)) json_err("VALIDATION_ERROR", "invalid_min_score", 400);
    $where[]  = "min_score >= ?";
    $params[] = (int)$min_score;
  }
  if ($days !== '') {
    if (!ctype_digit($days)) json_err("VALIDATION_ERROR", "invalid_days", 400);
    $where[]  = "days = ?";
    $params[] = (int)$days;
  }
  if ($times !== '') {
    if (!ctype_digit($times)) json_err("VALIDATION_ERROR", "invalid_times", 400);
    $where[]  = "times = ?";
    $params[] = (int)$times;
  }
  if ($sprays_per_product !== '') {
    if (!ctype_digit($sprays_per_product)) json_err("VALIDATION_ERROR", "invalid_sprays_per_product", 400);
    $where[]  = "sprays_per_product = ?";
    $params[] = (int)$sprays_per_product;
  }
  if ($max_products_per_group !== '') {
    if (!ctype_digit($max_products_per_group)) json_err("VALIDATION_ERROR", "invalid_max_products_per_group", 400);
    $where[]  = "max_products_per_group = ?";
    $params[] = (int)$max_products_per_group;
  }
  /* ✅ เพิ่ม filter */
  if ($max_sprays_per_group !== '') {
    if (!ctype_digit($max_sprays_per_group)) json_err("VALIDATION_ERROR", "invalid_max_sprays_per_group", 400);
    $where[]  = "max_sprays_per_group = ?";
    $params[] = (int)$max_sprays_per_group;
  }

  if ($where) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY disease_id ASC, min_score ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
