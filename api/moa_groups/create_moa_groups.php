<?php
// create_moa_groups.php
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
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
  );
  $st->execute([$table, $col]);
  return ((int)$st->fetchColumn()) > 0;
}

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (is_array($data)) return $data;
  if (!empty($_POST) && is_array($_POST)) return $_POST;
  return [];
}

function require_str($v, string $code, int $maxLen = 255): string {
  $s = trim((string)$v);
  if ($s === '') json_err('VALIDATION_ERROR', $code, 400);
  if (mb_strlen($s) > $maxLen) json_err('VALIDATION_ERROR', $code . '_too_long', 400);
  return $s;
}

function opt_str($v, int $maxLen = 65535): ?string {
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === '') return null;
  if (mb_strlen($s) > $maxLen) json_err('VALIDATION_ERROR', 'value_too_long', 400);
  return $s;
}

function require_enum($v, array $allowed, string $code): string {
  $s = trim((string)$v);
  if ($s === '' || !in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
}

$db = dbh();
$data = read_json_body();

try {
  // รองรับ schema ทั้งแบบ moa_system หรือ system
  $sysCol = null;
  if (has_column($db, 'moa_groups', 'moa_system')) $sysCol = 'moa_system';
  else if (has_column($db, 'moa_groups', 'system')) $sysCol = 'system';

  $allowedSystems = ['FRAC', 'IRAC', 'HRAC', 'OTHER'];

  $tmp_moa_code = require_str($data['moa_code'] ?? null, 'moa_code_required');
  $tmp_group_name = opt_str($data['group_name'] ?? null);
  $tmp_description = opt_str($data['description'] ?? null);

  $tmp_moa_system = null;
  if ($sysCol !== null) {
    $tmp_moa_system = require_enum($data['moa_system'] ?? ($data['system'] ?? null), $allowedSystems, 'invalid_moa_system');
  }

  if ($sysCol !== null) {
    $stmt = $db->prepare(
      "INSERT INTO moa_groups ($sysCol, moa_code, group_name, description)
       VALUES (:moa_system, :moa_code, :group_name, :description)"
    );
    $stmt->execute([
      ':moa_system' => $tmp_moa_system,
      ':moa_code' => $tmp_moa_code,
      ':group_name' => $tmp_group_name,
      ':description' => $tmp_description,
    ]);
  } else {
    // schema เก่ายังไม่มีคอลัมน์ system
    $stmt = $db->prepare(
      "INSERT INTO moa_groups (moa_code, group_name, description)
       VALUES (:moa_code, :group_name, :description)"
    );
    $stmt->execute([
      ':moa_code' => $tmp_moa_code,
      ':group_name' => $tmp_group_name,
      ':description' => $tmp_description,
    ]);
  }

  $newId = (int)$db->lastInsertId();

  // return row (alias moa_system ให้ frontend เสมอ)
  $systemExpr = "CASE
    WHEN mg.group_name LIKE 'FRAC%' THEN 'FRAC'
    WHEN mg.group_name LIKE 'IRAC%' THEN 'IRAC'
    WHEN mg.group_name LIKE 'HRAC%' THEN 'HRAC'
    ELSE 'OTHER'
  END";
  $selectSystem = ($sysCol !== null)
    ? "COALESCE(NULLIF(mg.$sysCol,''), ($systemExpr))"
    : "($systemExpr)";

  $row = $db->prepare(
    "SELECT
        mg.moa_group_id,
        ($selectSystem) AS moa_system,
        mg.moa_code,
        mg.group_name,
        mg.description
     FROM moa_groups mg
     WHERE mg.moa_group_id = ?"
  );
  $row->execute([$newId]);

  json_ok($row->fetch(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
  if ($e->getCode() === '23000') {
    json_err('DUPLICATE', 'duplicate_or_fk_error', 409);
  }
  json_err('DB_ERROR', 'db_error', 500);
} catch (Throwable $e) {
  json_err('SERVER_ERROR', 'server_error', 500);
}
