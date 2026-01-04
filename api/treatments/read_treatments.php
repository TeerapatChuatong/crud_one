<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err("METHOD_NOT_ALLOWED", "get_only", 405);

$risk_level_id = trim($_GET['risk_level_id'] ?? '');
$disease_id    = trim($_GET['disease_id'] ?? '');
$level_code    = strtolower(trim($_GET['level_code'] ?? ''));
$q             = trim($_GET['q'] ?? '');

$allowedLevels = ['low','medium','high'];
$where = [];
$params = [];

if ($risk_level_id !== '') {
  if (!ctype_digit((string)$risk_level_id)) json_err("VALIDATION_ERROR", "invalid_risk_level_id", 400);
  $where[] = "t.risk_level_id = ?";
  $params[] = (int)$risk_level_id;
}
if ($disease_id !== '') {
  if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
  $where[] = "rl.disease_id = ?";
  $params[] = (int)$disease_id;
}
if ($level_code !== '') {
  if (!in_array($level_code, $allowedLevels, true)) json_err("VALIDATION_ERROR", "invalid_level_code", 400);
  $where[] = "rl.level_code = ?";
  $params[] = $level_code;
}
if ($q !== '') {
  $where[] = "t.advice_text LIKE ?";
  $params[] = "%{$q}%";
}

$sql = "
  SELECT
    t.treatment_id, t.risk_level_id, t.advice_text, t.created_at,
    rl.disease_id, rl.level_code, rl.min_score, rl.days, rl.times,
    d.disease_th, d.disease_en
  FROM treatments t
  JOIN disease_risk_levels rl ON rl.risk_level_id = t.risk_level_id
  JOIN diseases d ON d.disease_id = rl.disease_id
";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);

$sql .= "
  ORDER BY
    rl.disease_id ASC,
    FIELD(rl.level_code,'low','medium','high') ASC,
    t.treatment_id DESC
";

try {
  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
