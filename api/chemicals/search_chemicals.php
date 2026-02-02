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

    try {
      $q = trim((string)($_GET['q'] ?? ''));
      if ($q === '') {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
      }

      $moa_group_id = opt_int($_GET['moa_group_id'] ?? null, 'moa_group_id_invalid');
      $target_type = opt_enum($_GET['target_type'] ?? null, ['fungicide','bactericide','insecticide','other'], 'target_type_invalid');
      $is_active = opt_bool01($_GET['is_active'] ?? null, 'is_active_invalid');

      $sql = "SELECT c.chemical_id, c.trade_name, c.active_ingredient, c.target_type, c.moa_group_id, mg.moa_code, mg.group_name AS moa_group_name, c.notes, c.is_active FROM chemicals c LEFT JOIN moa_groups mg ON mg.moa_group_id = c.moa_group_id WHERE (c.trade_name LIKE :q OR c.active_ingredient LIKE :q OR mg.moa_code LIKE :q OR mg.group_name LIKE :q) AND (:moa_group_id IS NULL OR c.moa_group_id = :moa_group_id) AND (:target_type IS NULL OR c.target_type = :target_type) AND (:is_active IS NULL OR c.is_active = :is_active) ORDER BY c.chemical_id DESC";
      $st = $db->prepare($sql);

      $params = [':q' => ('%' . $q . '%')];
      $params = array_merge($params, [':moa_group_id' => $moa_group_id,
        ':target_type' => $target_type,
        ':is_active' => $is_active]);

      $st->execute($params);
      echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
      json_err('DB_ERROR', 'db_error', 500);
    }
