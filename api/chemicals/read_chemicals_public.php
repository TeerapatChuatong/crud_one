<?php
header('Content-Type: application/json; charset=utf-8');

// รองรับทั้งกรณี db.php อยู่ใน api/ หรืออยู่ที่ root CRUD/
$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

// กันกรณี db.php ไม่มี json_ok/json_err
if (!function_exists('json_ok')) {
  function json_ok($data = []) {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('json_err')) {
  function json_err($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

function dbh(): PDO {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  if (isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh'];
  json_err('DB_ERROR', 'db_not_initialized', 500);
}

function opt_int($v): ?int {
  if ($v === null || $v === '') return null;
  if (!ctype_digit((string)$v)) json_err('VALIDATION_ERROR', 'invalid_int', 400);
  return (int)$v;
}
function opt_enum($v, array $allowed): ?string {
  if ($v === null || $v === '') return null;
  $s = trim((string)$v);
  if (!in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', 'invalid_enum', 400);
  return $s;
}
function opt_bool01($v): ?int {
  if ($v === null || $v === '') return null;
  $s = strtolower(trim((string)$v));
  if ($s === '1' || $s === 'true') return 1;
  if ($s === '0' || $s === 'false') return 0;
  json_err('VALIDATION_ERROR', 'invalid_bool', 400);
  return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
}

$db = dbh();

$chemical_id = opt_int($_GET['chemical_id'] ?? null);
$moa_group_id = opt_int($_GET['moa_group_id'] ?? null);
$target_type = opt_enum($_GET['target_type'] ?? null, ['fungicide','bactericide','insecticide','other']);
$is_active = opt_bool01($_GET['is_active'] ?? null);

// ✅ public: ถ้าไม่ส่ง is_active มา ให้แสดงเฉพาะที่ active
if ($is_active === null) $is_active = 1;

try {
  if ($chemical_id !== null) {
    $st = $db->prepare("
      SELECT
        c.chemical_id,
        c.trade_name AS chemical_name,
        c.trade_name,
        c.active_ingredient,
        c.target_type,
        c.moa_group_id,
        mg.moa_code,
        mg.group_name AS moa_group_name,
        c.notes,
        c.is_active
      FROM chemicals c
      LEFT JOIN moa_groups mg ON mg.moa_group_id = c.moa_group_id
      WHERE c.chemical_id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $chemical_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err('NOT_FOUND', 'chemical_not_found', 404);
    json_ok($row);
  }

  $st = $db->prepare("
    SELECT
      c.chemical_id,
      c.trade_name AS chemical_name,
      c.trade_name,
      c.active_ingredient,
      c.target_type,
      c.moa_group_id,
      mg.moa_code,
      mg.group_name AS moa_group_name,
      c.notes,
      c.is_active
    FROM chemicals c
    LEFT JOIN moa_groups mg ON mg.moa_group_id = c.moa_group_id
    WHERE 1=1
      AND (:moa_group_id IS NULL OR c.moa_group_id = :moa_group_id)
      AND (:target_type IS NULL OR c.target_type = :target_type)
      AND (:is_active IS NULL OR c.is_active = :is_active)
    ORDER BY c.chemical_id DESC
  ");
  $st->execute([
    ':moa_group_id' => $moa_group_id,
    ':target_type' => $target_type,
    ':is_active' => $is_active,
  ]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  json_ok($rows);

} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
