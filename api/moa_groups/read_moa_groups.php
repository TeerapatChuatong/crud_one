<?php
// read_moa_groups.php
// รองรับทั้งกรณี db.php อยู่ใน api/ หรืออยู่ที่ root CRUD/
$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

require_admin();

function dbh(): PDO {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  if (isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh'];
  json_err('DB_ERROR', 'db_not_initialized', 500);
}

function has_column(PDO $db, string $table, string $col): bool {
  $st = $db->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS\n     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
  );
  $st->execute([$table, $col]);
  return ((int)$st->fetchColumn()) > 0;
}

function require_int($v, string $code): int {
  if ($v === null || $v === '' || !ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}

function require_enum($v, array $allowed, string $code): string {
  $s = trim((string)$v);
  if ($s === '' || !in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
}

$db = dbh();

try {
  $allowedSystems = ['FRAC', 'IRAC', 'HRAC', 'OTHER'];

  // รองรับ schema ทั้งแบบ moa_system หรือ system
  $sysCol = null;
  if (has_column($db, 'moa_groups', 'moa_system')) $sysCol = 'moa_system';
  else if (has_column($db, 'moa_groups', 'system')) $sysCol = 'system';

  $id = $_GET['moa_group_id'] ?? null;
  $filterSystem = trim((string)($_GET['moa_system'] ?? ''));
  if ($filterSystem === '') $filterSystem = null;
  if ($filterSystem !== null) {
    $filterSystem = require_enum($filterSystem, $allowedSystems, 'invalid_moa_system');
  }

  // fallback derive จาก group_name (เช่น FRAC 1A)
  $systemExpr = "CASE
    WHEN mg.group_name LIKE 'FRAC%' THEN 'FRAC'
    WHEN mg.group_name LIKE 'IRAC%' THEN 'IRAC'
    WHEN mg.group_name LIKE 'HRAC%' THEN 'HRAC'
    ELSE 'OTHER'
  END";

  // ถ้ามีคอลัมน์ system แต่เป็น NULL/'' ให้ fallback ไป derive
  $selectSystem = ($sysCol !== null)
    ? "COALESCE(NULLIF(mg.$sysCol,''), ($systemExpr))"
    : "($systemExpr)";

  $baseSelect = "SELECT
      mg.moa_group_id,
      ($selectSystem) AS moa_system,
      mg.moa_code,
      mg.group_name,
      mg.description
    FROM moa_groups mg";

  // read one
  if ($id !== null && $id !== '') {
    $id = require_int($id, 'invalid_moa_group_id');
    $st = $db->prepare($baseSelect . ' WHERE mg.moa_group_id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err('NOT_FOUND', 'moa_groups_not_found', 404);
    echo json_encode($row, JSON_UNESCAPED_UNICODE);
    exit;
  }

  // list
  $where = ' WHERE 1=1';
  $params = [];

  if ($filterSystem !== null) {
    $where .= " AND (($selectSystem) = :sys)";
    $params[':sys'] = $filterSystem;
  }

  $order = ' ORDER BY moa_system ASC, mg.moa_code ASC';

  $st = $db->prepare($baseSelect . $where . $order);
  $st->execute($params);
  echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
