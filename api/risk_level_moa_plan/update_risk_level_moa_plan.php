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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'patch_or_post_only', 405);
}

$db = dbh();
$data = read_json_body();

// -----------------------------
// Bulk save mode (ใช้กับหน้า Admin):
// payload: { risk_level_id, items: [{ moa_group_id, priority }, ...] }
// แนวทาง: ลบแผนเดิมของ risk_level_id แล้ว insert ใหม่ทั้งชุด (กันชน unique priority)
// -----------------------------
if (isset($data['items'])) {
  $rlId = require_int($data['risk_level_id'] ?? null, 'risk_level_id_invalid');
  $items = $data['items'];
  if (!is_array($items)) {
    json_err('VALIDATION_ERROR', 'items_must_be_array', 400);
  }

  // validate + กันซ้ำ
  $seenMoa = [];
  $seenPri = [];
  $clean = [];
  foreach ($items as $it) {
    if (!is_array($it)) json_err('VALIDATION_ERROR', 'item_invalid', 400);
    $moaId = require_int($it['moa_group_id'] ?? null, 'moa_group_id_invalid');
    $pri = require_int($it['priority'] ?? null, 'priority_invalid');
    if ($pri <= 0) json_err('VALIDATION_ERROR', 'priority_must_be_positive', 400);
    if (isset($seenMoa[$moaId])) json_err('VALIDATION_ERROR', 'duplicate_moa_group_id', 400);
    if (isset($seenPri[$pri])) json_err('VALIDATION_ERROR', 'duplicate_priority', 400);
    $seenMoa[$moaId] = 1;
    $seenPri[$pri] = 1;
    $clean[] = ['moa_group_id' => $moaId, 'priority' => $pri];
  }

  try {
    $db->beginTransaction();

    $del = $db->prepare('DELETE FROM risk_level_moa_plan WHERE risk_level_id = ?');
    $del->execute([$rlId]);

    if (count($clean) > 0) {
      $ins = $db->prepare('INSERT INTO risk_level_moa_plan (risk_level_id, moa_group_id, priority) VALUES (?,?,?)');
      foreach ($clean as $r) {
        $ins->execute([$rlId, $r['moa_group_id'], $r['priority']]);
      }
    }

    $db->commit();

    $st = $db->prepare(
      "SELECT p.plan_id, p.risk_level_id, p.moa_group_id, mg.moa_system, mg.moa_code,
              mg.group_name AS group_name, mg.group_name AS moa_group_name, p.priority
       FROM risk_level_moa_plan p
       INNER JOIN moa_groups mg ON mg.moa_group_id = p.moa_group_id
       WHERE p.risk_level_id = ?
       ORDER BY p.priority ASC, p.plan_id ASC"
    );
    $st->execute([$rlId]);
    json_ok($st->fetchAll(PDO::FETCH_ASSOC));
  } catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($e->getCode() === '23000') json_err('DUPLICATE', 'duplicate_or_fk_error', 409);
    json_err('DB_ERROR', 'db_error', 500);
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    json_err('SERVER_ERROR', 'server_error', 500);
  }
}

// -----------------------------
// Single row update mode (เดิม): plan_id + fields
// -----------------------------
$id = $data['plan_id'] ?? ($_GET['plan_id'] ?? null);
$id = require_int($id, 'invalid_plan_id');

try {
  $v_risk_level_id = opt_int($data['risk_level_id'] ?? null, 'risk_level_id_invalid');
  $v_moa_group_id  = opt_int($data['moa_group_id'] ?? null, 'moa_group_id_invalid');
  $v_priority      = opt_int($data['priority'] ?? null, 'priority_invalid');

  $sets = [];
  $params = [':id' => $id];

  if ($v_risk_level_id !== null) { $sets[] = "risk_level_id = :risk_level_id"; $params[':risk_level_id'] = $v_risk_level_id; }
  if ($v_moa_group_id !== null)  { $sets[] = "moa_group_id = :moa_group_id";   $params[':moa_group_id']  = $v_moa_group_id; }
  if ($v_priority !== null)      { $sets[] = "priority = :priority";          $params[':priority']       = $v_priority; }

  if (count($sets) === 0) json_err('VALIDATION_ERROR', 'no_fields_to_update', 400);

  $sql = "UPDATE risk_level_moa_plan SET " . implode(', ', $sets) . " WHERE plan_id = :id";
  $st = $db->prepare($sql);
  $st->execute($params);

  if ($st->rowCount() === 0) {
    $check = $db->prepare("SELECT plan_id FROM risk_level_moa_plan WHERE plan_id=?");
    $check->execute([$id]);
    if (!$check->fetch()) json_err('NOT_FOUND', 'risk_level_moa_plan_not_found', 404);
  }

  $row = $db->prepare("SELECT * FROM risk_level_moa_plan WHERE plan_id=?");
  $row->execute([$id]);
  json_ok($row->fetch(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
  if ($e->getCode() === '23000') json_err('DUPLICATE', 'duplicate_or_fk_error', 409);
  json_err('DB_ERROR', 'db_error', 500);
} catch (Throwable $e) {
  json_err('SERVER_ERROR', 'server_error', 500);
}
