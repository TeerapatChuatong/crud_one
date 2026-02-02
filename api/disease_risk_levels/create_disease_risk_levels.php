<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED","post_only",405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$disease_id = $body['disease_id'] ?? null;
$level_code = trim((string)($body['level_code'] ?? ''));
$min_score  = $body['min_score'] ?? null;

// existing columns
$days  = array_key_exists('days',  $body) ? $body['days']  : 0;
$times = array_key_exists('times', $body) ? $body['times'] : 0;

// rule columns
$sprays_per_product     = array_key_exists('sprays_per_product', $body) ? $body['sprays_per_product'] : 2;
$max_products_per_group = array_key_exists('max_products_per_group', $body) ? $body['max_products_per_group'] : 2;
/* ✅ เพิ่ม */
$max_sprays_per_group   = array_key_exists('max_sprays_per_group', $body) ? $body['max_sprays_per_group'] : 2;

if (!$disease_id || !ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
if ($level_code === '') json_err("VALIDATION_ERROR","invalid_level_code",400);
if ($min_score === null || !is_numeric($min_score)) json_err("VALIDATION_ERROR","invalid_min_score",400);

// normalize + validate days
if ($days === null || $days === '') $days = 0;
if (!is_numeric($days) || (int)$days < 0 || (int)$days > 365) json_err("VALIDATION_ERROR","invalid_days",400);

// normalize + validate times
if ($times === null || $times === '') $times = 0;
if (!is_numeric($times) || (int)$times < 0 || (int)$times > 99) json_err("VALIDATION_ERROR","invalid_times",400);

// validate sprays_per_product
if ($sprays_per_product === null || $sprays_per_product === '') $sprays_per_product = 2;
if (!is_numeric($sprays_per_product) || (int)$sprays_per_product < 1 || (int)$sprays_per_product > 20) {
  json_err("VALIDATION_ERROR","invalid_sprays_per_product",400);
}

// validate max_products_per_group
if ($max_products_per_group === null || $max_products_per_group === '') $max_products_per_group = 2;
if (!is_numeric($max_products_per_group) || (int)$max_products_per_group < 1 || (int)$max_products_per_group > 20) {
  json_err("VALIDATION_ERROR","invalid_max_products_per_group",400);
}

/* ✅ validate max_sprays_per_group */
if ($max_sprays_per_group === null || $max_sprays_per_group === '') $max_sprays_per_group = 2;
if (!is_numeric($max_sprays_per_group) || (int)$max_sprays_per_group < 1 || (int)$max_sprays_per_group > 20) {
  json_err("VALIDATION_ERROR","invalid_max_sprays_per_group",400);
}

try {
  $st = $dbh->prepare("
    INSERT INTO disease_risk_levels(
      disease_id, level_code, min_score, days, times,
      sprays_per_product, max_products_per_group, max_sprays_per_group
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      min_score=VALUES(min_score),
      days=VALUES(days),
      times=VALUES(times),
      sprays_per_product=VALUES(sprays_per_product),
      max_products_per_group=VALUES(max_products_per_group),
      max_sprays_per_group=VALUES(max_sprays_per_group)
  ");
  $st->execute([
    (int)$disease_id,
    $level_code,
    (int)$min_score,
    (int)$days,
    (int)$times,
    (int)$sprays_per_product,
    (int)$max_products_per_group,
    (int)$max_sprays_per_group
  ]);

  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
