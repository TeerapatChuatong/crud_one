<?php
$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

require_admin();

function dbh(): PDO {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  if (isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh'];
  json_err('DB_ERROR', 'db_not_initialized', 500);
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
}

$db = dbh();
$id = $_GET['plan_id'] ?? null;

try {
  if ($id !== null && $id !== '') {
    $id = require_int($id, 'invalid_plan_id');
    $st = $db->prepare(
      "SELECT p.plan_id, p.risk_level_id, p.moa_group_id,
              mg.moa_system, mg.moa_code,
              mg.group_name AS group_name, mg.group_name AS moa_group_name,
              p.priority
       FROM risk_level_moa_plan p
       INNER JOIN moa_groups mg ON mg.moa_group_id = p.moa_group_id
       WHERE p.plan_id = :id"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err('NOT_FOUND', 'risk_level_moa_plan_not_found', 404);
    echo json_encode($row, JSON_UNESCAPED_UNICODE);
    exit;
  }

  $risk_level_id = opt_int($_GET['risk_level_id'] ?? null, 'risk_level_id_invalid');
  $moa_group_id  = opt_int($_GET['moa_group_id'] ?? null,  'moa_group_id_invalid');

  $sql = "SELECT p.plan_id, p.risk_level_id, p.moa_group_id,
                 mg.moa_system, mg.moa_code,
                 mg.group_name AS group_name, mg.group_name AS moa_group_name,
                 p.priority
          FROM risk_level_moa_plan p
          INNER JOIN moa_groups mg ON mg.moa_group_id = p.moa_group_id
          WHERE 1=1
            AND (:risk_level_id IS NULL OR p.risk_level_id = :risk_level_id)
            AND (:moa_group_id IS NULL OR p.moa_group_id = :moa_group_id)
          ORDER BY p.risk_level_id ASC, p.priority ASC, p.plan_id ASC";

  $st = $db->prepare($sql);
  $st->execute([
    ':risk_level_id' => $risk_level_id,
    ':moa_group_id'  => $moa_group_id
  ]);
  echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
