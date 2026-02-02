<?php
header("Content-Type: application/json; charset=utf-8");

$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;


function dbh(): PDO {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  if (isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh'];
  json_err('DB_ERROR', 'db_not_initialized', 500);
}
function opt_int($v, string $code): ?int {
  if ($v === null || $v === '') return null;
  if (!ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}
function opt_enum($v, array $allowed, string $code): ?string {
  if ($v === null || $v === '') return null;
  $s = trim((string)$v);
  if (!in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}
function opt_bool01($v, string $code): ?int {
  if ($v === null || $v === '') return null;
  if ($v === true) return 1;
  if ($v === false) return 0;
  $s = strtolower(trim((string)$v));
  if ($s === '1' || $s === 'true') return 1;
  if ($s === '0' || $s === 'false') return 0;
  json_err('VALIDATION_ERROR', $code, 400);
  return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
}

$db = dbh();

$mapping_id = opt_int($_GET['mapping_id'] ?? null, 'mapping_id_invalid');
$disease_id = opt_int($_GET['disease_id'] ?? null, 'disease_id_invalid');
$choice_id  = opt_int($_GET['choice_id'] ?? null, 'choice_id_invalid');
$slot       = opt_enum($_GET['slot'] ?? null, ['water','canopy','debris'], 'slot_invalid');
$is_active  = opt_bool01($_GET['is_active'] ?? null, 'is_active_invalid');
$q          = trim((string)($_GET['q'] ?? ''));

try {
  if ($mapping_id !== null) {
    $st = $db->prepare("SELECT * FROM treatment_advice_mappings WHERE mapping_id=? LIMIT 1");
    $st->execute([$mapping_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err('NOT_FOUND', 'mapping_not_found', 404);
    json_ok($row);
  }

  $where = [];
  $params = [];

  if ($disease_id !== null) { $where[] = "disease_id = ?"; $params[] = $disease_id; }
  if ($choice_id !== null)  { $where[] = "choice_id = ?";  $params[] = $choice_id; }
  if ($slot !== null)       { $where[] = "slot = ?";       $params[] = $slot; }
  if ($is_active !== null)  { $where[] = "is_active = ?";  $params[] = $is_active; }
  if ($q !== '')            { $where[] = "advice_text LIKE ?"; $params[] = "%{$q}%"; }

  $sql = "SELECT * FROM treatment_advice_mappings";
  if ($where) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY disease_id DESC, slot ASC, choice_id ASC, mapping_id ASC";

  $st = $db->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
