<?php
// update_moa_groups.php
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

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (is_array($data)) return $data;
  if (!empty($_POST) && is_array($_POST)) return $_POST;
  return [];
}

function require_int($v, string $code): int {
  if ($v === null || $v === '' || !ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'patch_or_post_only', 405);
}

$db = dbh();
$data = read_json_body();

$id = $data['moa_group_id'] ?? ($_GET['moa_group_id'] ?? null);
$id = require_int($id, 'invalid_moa_group_id');

try {
  // รองรับ schema ทั้งแบบ moa_system หรือ system
  $sysCol = null;
  if (has_column($db, 'moa_groups', 'moa_system')) $sysCol = 'moa_system';
  else if (has_column($db, 'moa_groups', 'system')) $sysCol = 'system';

  $allowedSystems = ['FRAC', 'IRAC', 'HRAC', 'OTHER'];

  $sets = [];
  $params = [':id' => $id];

  // อนุญาต update เฉพาะ field ที่ส่งมา (รองรับการล้างค่าเป็น NULL ด้วย)
  if ($sysCol !== null && (array_key_exists('moa_system', $data) || array_key_exists('system', $data))) {
    $v = $data['moa_system'] ?? $data['system'] ?? null;
    $v = require_enum($v, $allowedSystems, 'invalid_moa_system');
    $sets[] = "$sysCol = :moa_system";
    $params[':moa_system'] = $v;
  }

  if (array_key_exists('moa_code', $data)) {
    $v = require_str($data['moa_code'], 'moa_code_required');
    $sets[] = "moa_code = :moa_code";
    $params[':moa_code'] = $v;
  }

  if (array_key_exists('group_name', $data)) {
    $v = opt_str($data['group_name']);
    $sets[] = "group_name = :group_name";
    $params[':group_name'] = $v;
  }

  if (array_key_exists('description', $data)) {
    $v = opt_str($data['description']);
    $sets[] = "description = :description";
    $params[':description'] = $v;
  }

  if (count($sets) === 0) {
    json_err('VALIDATION_ERROR', 'no_fields_to_update', 400);
  }

  $sql = "UPDATE moa_groups SET " . implode(', ', $sets) . " WHERE moa_group_id = :id";
  $st = $db->prepare($sql);
  $st->execute($params);

  if ($st->rowCount() === 0) {
    $check = $db->prepare("SELECT moa_group_id FROM moa_groups WHERE moa_group_id=?");
    $check->execute([$id]);
    if (!$check->fetch()) json_err('NOT_FOUND', 'moa_groups_not_found', 404);
  }

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
     WHERE mg.moa_group_id=?"
  );
  $row->execute([$id]);
  json_ok($row->fetch(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
  if ($e->getCode() === '23000') {
    json_err('DUPLICATE', 'duplicate_or_fk_error', 409);
  }
  json_err('DB_ERROR', 'db_error', 500);
} catch (Throwable $e) {
  json_err('SERVER_ERROR', 'server_error', 500);
}
