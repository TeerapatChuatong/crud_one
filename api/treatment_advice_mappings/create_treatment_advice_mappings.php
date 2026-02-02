<?php
header("Content-Type: application/json; charset=utf-8");

$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

require_admin(); // ใช้ helper ใน db.php :contentReference[oaicite:4]{index=4}

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

function opt_int0($v, string $code, int $default = 0): int {
  if ($v === null || $v === '') return $default;
  if (!ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}
function require_int($v, string $code): int {
  if ($v === null || $v === '' || !ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}
function require_enum($v, array $allowed, string $code): string {
  $s = trim((string)$v);
  if ($s === '' || !in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}
function require_text($v, string $code): string {
  $s = trim((string)$v);
  if ($s === '') json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}
function opt_bool01($v, string $code, int $default = 1): int {
  if ($v === null || $v === '') return $default;
  if ($v === true) return 1;
  if ($v === false) return 0;
  $s = strtolower(trim((string)$v));
  if ($s === '1' || $s === 'true') return 1;
  if ($s === '0' || $s === 'false') return 0;
  json_err('VALIDATION_ERROR', $code, 400);
  return $default;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
}

$db = dbh();
$body = read_json_body();

$disease_id = opt_int0($body['disease_id'] ?? 0, 'disease_id_invalid', 0); // 0 = default
$slot = require_enum($body['slot'] ?? null, ['water','canopy','debris'], 'slot_invalid');
$choice_id = require_int($body['choice_id'] ?? null, 'choice_id_invalid');
$advice_text = require_text($body['advice_text'] ?? null, 'advice_text_required');
$is_active = opt_bool01($body['is_active'] ?? 1, 'is_active_invalid', 1);

try {
  $sql = "
    INSERT INTO treatment_advice_mappings (disease_id, slot, choice_id, advice_text, is_active)
    VALUES (:disease_id, :slot, :choice_id, :advice_text, :is_active)
    ON DUPLICATE KEY UPDATE
      advice_text = VALUES(advice_text),
      is_active = VALUES(is_active),
      updated_at = CURRENT_TIMESTAMP
  ";
  $st = $db->prepare($sql);
  $st->execute([
    ':disease_id' => $disease_id,
    ':slot' => $slot,
    ':choice_id' => $choice_id,
    ':advice_text' => $advice_text,
    ':is_active' => $is_active,
  ]);

  $st2 = $db->prepare("
    SELECT * FROM treatment_advice_mappings
    WHERE disease_id=? AND slot=? AND choice_id=?
    LIMIT 1
  ");
  $st2->execute([$disease_id, $slot, $choice_id]);
  json_ok($st2->fetch(PDO::FETCH_ASSOC) ?: true);

} catch (PDOException $e) {
  if ($e->getCode() === '23000') json_err('DUPLICATE_OR_FK', 'duplicate_or_fk_error', 409);
  json_err('DB_ERROR', 'db_error', 500);
} catch (Throwable $e) {
  json_err('SERVER_ERROR', 'server_error', 500);
}
