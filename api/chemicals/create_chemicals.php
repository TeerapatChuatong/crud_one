<?php
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

function require_int($v, string $code) : int {
  if ($v === null || $v === '' || !ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}

function opt_int($v, string $code) : ?int {
  if ($v === null || $v === '') return null;
  if (!ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}

function opt_bool01($v, string $code) : ?int {
  if ($v === null || $v === '') return null;
  if ($v === true) return 1;
  if ($v === false) return 0;
  $s = strtolower(trim((string)$v));
  if ($s === '1' || $s === 'true') return 1;
  if ($s === '0' || $s === 'false') return 0;
  json_err('VALIDATION_ERROR', $code, 400);
  return null;
}

function require_str($v, string $code, int $maxLen = 255) : string {
  $s = trim((string)$v);
  if ($s === '') json_err('VALIDATION_ERROR', $code, 400);
  if (mb_strlen($s) > $maxLen) json_err('VALIDATION_ERROR', $code . '_too_long', 400);
  return $s;
}

function opt_str($v, int $maxLen = 65535) : ?string {
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === '') return null;
  if (mb_strlen($s) > $maxLen) json_err('VALIDATION_ERROR', 'value_too_long', 400);
  return $s;
}

function opt_enum($v, array $allowed, string $code) : ?string {
  if ($v === null || $v === '') return null;
  $s = trim((string)$v);
  if (!in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}

function moa_group_exists(PDO $db, int $id): bool {
  $st = $db->prepare("SELECT 1 FROM moa_groups WHERE moa_group_id = ? LIMIT 1");
  $st->execute([$id]);
  return (bool)$st->fetchColumn();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
}

$db = dbh();
$data = read_json_body();


$hasUsageRate = has_column($db, 'chemicals', 'usage_rate');
try {
  $tmp_trade_name = require_str($data['trade_name'] ?? null, 'trade_name_required', 150);
  $tmp_active_ingredient = opt_str($data['active_ingredient'] ?? null, 200);

  $tmp_usage_rate = opt_str($data['usage_rate'] ?? null, 150);

  // ✅ กัน null -> ใช้ default ตาม schema
  $tmp_target_type = opt_enum($data['target_type'] ?? null, ["fungicide", "bactericide", "insecticide", "other"], 'target_type_invalid');
  if ($tmp_target_type === null) $tmp_target_type = "other";

  $tmp_moa_group_id = opt_int($data['moa_group_id'] ?? null, 'moa_group_id_invalid');
  $tmp_notes = opt_str($data['notes'] ?? null);

  // ✅ กัน null -> default 1
  $tmp_is_active = opt_bool01($data['is_active'] ?? null, 'is_active_invalid');
  if ($tmp_is_active === null) $tmp_is_active = 1;

  // ✅ ถ้าส่ง moa_group_id มา ต้องมีอยู่จริง (กัน FK error)
  if ($tmp_moa_group_id !== null && !moa_group_exists($db, $tmp_moa_group_id)) {
    json_err('VALIDATION_ERROR', 'moa_group_not_found', 400);
  }

  
  if ($hasUsageRate) {
    $stmt = $db->prepare(
      "INSERT INTO chemicals (trade_name, active_ingredient, usage_rate, target_type, moa_group_id, notes, is_active)
       VALUES (:trade_name, :active_ingredient, :usage_rate, :target_type, :moa_group_id, :notes, :is_active)"
    );
    $stmt->execute([
      'trade_name' => $tmp_trade_name,
      'active_ingredient' => $tmp_active_ingredient,
      'usage_rate' => $tmp_usage_rate,
      'target_type' => $tmp_target_type,
      'moa_group_id' => $tmp_moa_group_id,
      'notes' => $tmp_notes,
      'is_active' => $tmp_is_active
    ]);
  } else {
    $stmt = $db->prepare(
      "INSERT INTO chemicals (trade_name, active_ingredient, target_type, moa_group_id, notes, is_active)
       VALUES (:trade_name, :active_ingredient, :target_type, :moa_group_id, :notes, :is_active)"
    );
    $stmt->execute([
      'trade_name' => $tmp_trade_name,
      'active_ingredient' => $tmp_active_ingredient,
      'target_type' => $tmp_target_type,
      'moa_group_id' => $tmp_moa_group_id,
      'notes' => $tmp_notes,
      'is_active' => $tmp_is_active
    ]);
  }

  $newId = (int)$db->lastInsertId();
  $row = $db->prepare("SELECT * FROM chemicals WHERE chemical_id = ?");
  $row->execute([$newId]);

  json_ok($row->fetch(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
  // ✅ แยกสาเหตุให้ชัด (MySQL/MariaDB)
  $info = $e->errorInfo ?? [];
  $driverErr = (int)($info[1] ?? 0);

  if (($e->getCode() ?? '') === '23000') {
    if ($driverErr === 1452) json_err('FK_ERROR', 'foreign_key_violation', 409);
    if ($driverErr === 1062) json_err('DUPLICATE', 'duplicate_entry', 409);
    if ($driverErr === 1048) json_err('VALIDATION_ERROR', 'missing_required_field', 400);
    json_err('CONSTRAINT_ERROR', 'constraint_violation', 409);
  }

  json_err('DB_ERROR', 'db_error', 500);
} catch (Throwable $e) {
  json_err('SERVER_ERROR', 'server_error', 500);
}
