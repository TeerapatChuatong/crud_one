<?php
// delete_moa_groups.php
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

function require_int($v, string $code): int {
  if ($v === null || $v === '' || !ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
if ($method !== 'DELETE' && $method !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'delete_or_post_only', 405);
}

$db = dbh();
$data = ($method === 'POST') ? read_json_body() : [];

$id = $data['moa_group_id'] ?? ($_GET['moa_group_id'] ?? null);
$id = require_int($id, 'invalid_moa_group_id');

try {
  $st = $db->prepare("DELETE FROM moa_groups WHERE moa_group_id = ?");
  $st->execute([$id]);

  if ($st->rowCount() === 0) {
    json_err('NOT_FOUND', 'moa_groups_not_found', 404);
  }

  json_ok(['moa_group_id' => $id, 'deleted' => true]);
} catch (PDOException $e) {
  if ($e->getCode() === '23000') {
    json_err('FK_RESTRICT', 'cannot_delete_referenced_row', 409);
  }
  json_err('DB_ERROR', 'db_error', 500);
} catch (Throwable $e) {
  json_err('SERVER_ERROR', 'server_error', 500);
}
