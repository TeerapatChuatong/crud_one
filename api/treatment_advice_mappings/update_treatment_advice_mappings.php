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
function opt_int0($v, string $code): ?int {
  if ($v === null || $v === '') return null;
  if (!ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}
function opt_enum($v, array $allowed, string $code): ?string {
  if ($v === null || $v === '') return null;
  $s = trim((string)$v);
  if (!in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}
function opt_text($v): ?string {
  if ($v === null) return null;
  $s = trim((string)$v);
  return ($s === '') ? null : $s;
}
function opt_bool01($v, string $code): ?int {
  if ($v === null || $v === '') return null;
  if ($v === true) return 1;
  if ($v === false) return 0;
  $s = strtolower(trim((string)$v));
  if ($s === '1' || $s === 'true') return 1;
  if ($s === '0' || $s === 'false') return 0;
  json_err('VALIDATION_ERROR', $code, 400);
  return null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['POST','PATCH'], true)) {
  json_err('METHOD_NOT_ALLOWED', 'post_or_patch_only', 405);
}

$db = dbh();
$body = read_json_body();

$mapping_id = require_int($body['mapping_id'] ?? null, 'mapping_id_required');

$disease_id = opt_int0($body['disease_id'] ?? null, 'disease_id_invalid'); // 0 allowed
$slot       = opt_enum($body['slot'] ?? null, ['water','canopy','debris'], 'slot_invalid');
$choice_id  = opt_int0($body['choice_id'] ?? null, 'choice_id_invalid');
$advice_text= opt_text($body['advice_text'] ?? null);
$is_active  = opt_bool01($body['is_active'] ?? null, 'is_active_invalid');

$set = [];
$params = [];

if ($disease_id !== null) { $set[] = "disease_id=?"; $params[] = $disease_id; }
if ($slot !== null)       { $set[] = "slot=?";      $params[] = $slot; }
if ($choice_id !== null)  { $set[] = "choice_id=?"; $params[] = $choice_id; }
if ($advice_text !== null){ $set[] = "advice_text=?"; $params[] = $advice_text; }
if ($is_active !== null)  { $set[] = "is_active=?"; $params[] = $is_active; }

if (!$set) json_err('VALIDATION_ERROR', 'no_fields_to_update', 400);

try {
  $sql = "UPDATE treatment_advice_mappings SET " . implode(", ", $set) . " WHERE mapping_id=?";
  $params[] = $mapping_id;

  $st = $db->prepare($sql);
  $st->execute($params);

  if ($st->rowCount() === 0) {
    $chk = $db->prepare("SELECT mapping_id FROM treatment_advice_mappings WHERE mapping_id=? LIMIT 1");
    $chk->execute([$mapping_id]);
    if (!$chk->fetch()) json_err('NOT_FOUND', 'mapping_not_found', 404);
  }

  $st2 = $db->prepare("SELECT * FROM treatment_advice_mappings WHERE mapping_id=? LIMIT 1");
  $st2->execute([$mapping_id]);
  json_ok($st2->fetch(PDO::FETCH_ASSOC) ?: true);

} catch (PDOException $e) {
  if ($e->getCode() === '23000') json_err('DUPLICATE_OR_FK', 'duplicate_or_fk_error', 409);
  json_err('DB_ERROR', 'db_error', 500);
} catch (Throwable $e) {
  json_err('SERVER_ERROR', 'server_error', 500);
}
