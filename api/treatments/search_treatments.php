<?php
// api/treatments/search_treatments.php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$disease_id = trim($_GET['disease_id'] ?? '');
$risk_level = trim($_GET['risk_level_code'] ?? '');
$q          = trim($_GET['q'] ?? '');

try {
  $sql    = "SELECT * FROM treatments";
  $where  = [];
  $params = [];

  if ($disease_id !== '') {
    $where[]  = "disease_id = ?";
    $params[] = $disease_id;
  }

  if ($risk_level !== '') {
    $where[]  = "risk_level_code = ?";
    $params[] = $risk_level;
  }

  if ($q !== '') {
    $like    = "%{$q}%";
    $where[] = "advice_text LIKE ?";
    $params[] = $like;
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY disease_id ASC, risk_level_code ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
