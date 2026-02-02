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

function require_enum($v, array $allowed, string $code) : string {
  $s = trim((string)$v);
  if ($s === '' || !in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}

function opt_enum($v, array $allowed, string $code) : ?string {
  if ($v === null || $v === '') return null;
  $s = trim((string)$v);
  if (!in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
      json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
    }

    $db = dbh();

    $id = $_GET['id'] ?? null;

    try {
      if ($id !== null && $id !== '') {
        $id = require_int($id, 'invalid_id');
        $st = $db->prepare("SELECT r.id, r.risk_level_id, r.moa_group_id, mg.moa_code, r.chemical_id, c.trade_name, c.active_ingredient, c.target_type, r.priority FROM risk_level_moa_chemicals r INNER JOIN moa_groups mg ON mg.moa_group_id=r.moa_group_id INNER JOIN chemicals c ON c.chemical_id=r.chemical_id WHERE risk_level_moa_chemicals.id = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_err('NOT_FOUND', 'risk_level_moa_chemicals_not_found', 404);
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
        exit;
      }

      $risk_level_id = opt_int($_GET['risk_level_id'] ?? null, 'risk_level_id_invalid');
      $moa_group_id = opt_int($_GET['moa_group_id'] ?? null, 'moa_group_id_invalid');
      $chemical_id = opt_int($_GET['chemical_id'] ?? null, 'chemical_id_invalid');
      $sql = "SELECT r.id, r.risk_level_id, r.moa_group_id, mg.moa_code, r.chemical_id, c.trade_name, c.active_ingredient, c.target_type, r.priority FROM risk_level_moa_chemicals r INNER JOIN moa_groups mg ON mg.moa_group_id=r.moa_group_id INNER JOIN chemicals c ON c.chemical_id=r.chemical_id WHERE 1=1 AND (:risk_level_id IS NULL OR r.risk_level_id = :risk_level_id) AND (:moa_group_id IS NULL OR r.moa_group_id = :moa_group_id) AND (:chemical_id IS NULL OR r.chemical_id = :chemical_id) ORDER BY r.risk_level_id ASC, r.moa_group_id ASC, r.priority ASC, r.id ASC";
      $st = $db->prepare($sql);
      $st->execute([':risk_level_id' => $risk_level_id,
        ':moa_group_id' => $moa_group_id,
        ':chemical_id' => $chemical_id]);
      echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
      json_err('DB_ERROR', 'db_error', 500);
    }
