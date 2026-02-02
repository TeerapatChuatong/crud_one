<?php
header("Content-Type: application/json; charset=utf-8");

$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

require_admin();

function dbh(): PDO {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  if (isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh'];
  json_err('DB_ERROR', 'db_not_initialized', 500);
}
function require_int_qs($v, string $code): int {
  if ($v === null || $v === '' || !ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
  json_err('METHOD_NOT_ALLOWED', 'delete_only', 405);
}

$db = dbh();
$mapping_id = require_int_qs($_GET['mapping_id'] ?? null, 'mapping_id_required');
$soft = (($_GET['soft'] ?? '') === '1');

try {
  if ($soft) {
    $st = $db->prepare("UPDATE treatment_advice_mappings SET is_active=0 WHERE mapping_id=?");
    $st->execute([$mapping_id]);
  } else {
    $st = $db->prepare("DELETE FROM treatment_advice_mappings WHERE mapping_id=?");
    $st->execute([$mapping_id]);
  }
  if ($st->rowCount() === 0) json_err('NOT_FOUND', 'mapping_not_found', 404);
  json_ok(true);
} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
