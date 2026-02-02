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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'patch_or_post_only', 405);
}

$db = dbh();
$data = read_json_body();

$id = $data['chemical_id'] ?? ($_GET['chemical_id'] ?? null);
$id = require_int($id, 'invalid_chemical_id');

try {
  $v_trade_name = opt_str($data['trade_name'] ?? null, 150);
  $v_active_ingredient = opt_str($data['active_ingredient'] ?? null, 200);
  $v_target_type = opt_enum($data['target_type'] ?? null, ["fungicide", "bactericide", "insecticide", "other"], 'target_type_invalid');
  $v_notes = opt_str($data['notes'] ?? null);
  $v_is_active = opt_bool01($data['is_active'] ?? null, 'is_active_invalid');

  $sets = [];
  $params = [':id' => $id];

  if ($v_trade_name !== null) { $sets[] = "trade_name = :trade_name"; $params[':trade_name'] = $v_trade_name; }
  if ($v_active_ingredient !== null) { $sets[] = "active_ingredient = :active_ingredient"; $params[':active_ingredient'] = $v_active_ingredient; }
  if ($v_target_type !== null) { $sets[] = "target_type = :target_type"; $params[':target_type'] = $v_target_type; }
  if ($v_notes !== null) { $sets[] = "notes = :notes"; $params[':notes'] = $v_notes; }
  if ($v_is_active !== null) { $sets[] = "is_active = :is_active"; $params[':is_active'] = $v_is_active; }

  // ✅ รองรับอัปเดต moa_group_id และตรวจ FK
  if (array_key_exists('moa_group_id', $data)) {
    $rawMoa = $data['moa_group_id'];
    if ($rawMoa === null || $rawMoa === '') {
      $sets[] = "moa_group_id = NULL";
    } else {
      $v_moa_group_id = opt_int($rawMoa, 'moa_group_id_invalid');
      if ($v_moa_group_id !== null && !moa_group_exists($db, $v_moa_group_id)) {
        json_err('VALIDATION_ERROR', 'moa_group_not_found', 400);
      }
      $sets[] = "moa_group_id = :moa_group_id";
      $params[':moa_group_id'] = $v_moa_group_id;
    }
  }

  if (count($sets) === 0) {
    json_err('VALIDATION_ERROR', 'no_fields_to_update', 400);
  }

  $sql = "UPDATE chemicals SET " . implode(', ', $sets) . " WHERE chemical_id = :id";
  $st = $db->prepare($sql);
  $st->execute($params);

  if ($st->rowCount() === 0) {
    $check = $db->prepare("SELECT chemical_id FROM chemicals WHERE chemical_id=?");
    $check->execute([$id]);
    if (!$check->fetch()) json_err('NOT_FOUND', 'chemicals_not_found', 404);
  }

  $row = $db->prepare("SELECT * FROM chemicals WHERE chemical_id=?");
  $row->execute([$id]);
  json_ok($row->fetch(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
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
