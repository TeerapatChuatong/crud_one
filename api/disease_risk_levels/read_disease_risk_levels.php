<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$id = $_GET['risk_level_id'] ?? ($_GET['id'] ?? '');

try {
  if ($id !== '') {
    if (!ctype_digit((string)$id)) json_err("VALIDATION_ERROR","invalid_id",400);
    $st = $dbh->prepare("SELECT risk_level_id AS id, risk_level_id, disease_id, level_code, min_score, days FROM disease_risk_levels WHERE risk_level_id=? LIMIT 1");
    $st->execute([(int)$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err("NOT_FOUND","not_found",404);
    json_ok($row);
  } else {
    $st = $dbh->query("SELECT risk_level_id AS id, risk_level_id, disease_id, level_code, min_score, days FROM disease_risk_levels ORDER BY disease_id ASC, min_score ASC");
    json_ok($st->fetchAll(PDO::FETCH_ASSOC));
  }
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
