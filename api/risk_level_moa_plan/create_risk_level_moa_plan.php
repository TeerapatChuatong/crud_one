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

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
      json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
    }

    $db = dbh();
    $data = read_json_body();

    try {
      $tmp_risk_level_id = require_int($data['risk_level_id'] ?? null, 'risk_level_id_invalid');
      $tmp_moa_group_id = require_int($data['moa_group_id'] ?? null, 'moa_group_id_invalid');
      $tmp_priority = opt_int($data['priority'] ?? null, 'priority_invalid');

      $stmt = $db->prepare("INSERT INTO risk_level_moa_plan (risk_level_id, moa_group_id, priority) VALUES (:risk_level_id, :moa_group_id, :priority)");
      $stmt->execute([
        'risk_level_id' => $tmp_risk_level_id,
        'moa_group_id' => $tmp_moa_group_id,
        'priority' => $tmp_priority
      ]);

      $newId = (int)$db->lastInsertId();
      $row = $db->prepare("SELECT * FROM risk_level_moa_plan WHERE plan_id = ?");
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
