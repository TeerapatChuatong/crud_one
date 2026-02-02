<?php
header("Content-Type: application/json; charset=utf-8");

$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

$authPath = __DIR__ . '/../auth/require_auth.php';
if (!file_exists($authPath)) $authPath = __DIR__ . '/../../auth/require_auth.php';
if (file_exists($authPath)) require_once $authPath;

if (!isset($_SESSION)) @session_start();

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
function is_admin_safe(): bool {
  return function_exists("is_admin") ? (bool)is_admin() : false;
}
function session_uid(): int {
  $uid = (int)($_SESSION["user_id"] ?? 0);
  if ($uid <= 0) json_err("UNAUTHORIZED", "Please login", 401);
  return $uid;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
}

$db = dbh();
$data = read_json_body();

try {
  $session_uid = session_uid();
  $isAdmin = is_admin_safe();

  $user_id = $session_uid;
  if ($isAdmin && isset($data['user_id'])) {
    $user_id = require_int($data['user_id'], 'user_id_invalid');
  }

  $tree_id = require_int($data['tree_id'] ?? null, 'tree_id_invalid');
  $disease_id = require_int($data['disease_id'] ?? null, 'disease_id_invalid');
  $risk_level_id = require_int($data['risk_level_id'] ?? null, 'risk_level_id_invalid');

  if (!$isAdmin) {
    $chk = $db->prepare("SELECT tree_id FROM orange_trees WHERE tree_id=? AND user_id=?");
    $chk->execute([$tree_id, $user_id]);
    if (!$chk->fetch()) json_err('FORBIDDEN', 'tree_not_owned', 403);
  }

  $status = opt_enum($data['status'] ?? null, ['active','completed','stopped'], 'status_invalid') ?? 'active';
  $current_moa_group_id = opt_int($data['current_moa_group_id'] ?? null, 'current_moa_group_id_invalid');
  $current_chemical_id  = opt_int($data['current_chemical_id'] ?? null, 'current_chemical_id_invalid');

  $group_attempt_no   = opt_int($data['group_attempt_no'] ?? null, 'group_attempt_no_invalid') ?? 1;
  $product_attempt_no = opt_int($data['product_attempt_no'] ?? null, 'product_attempt_no_invalid') ?? 1;
  $spray_round_no     = opt_int($data['spray_round_no'] ?? null, 'spray_round_no_invalid') ?? 0;

  $last_spray_date = opt_str($data['last_spray_date'] ?? null, 20);
  $next_spray_date = opt_str($data['next_spray_date'] ?? null, 20);

  $last_evaluation = opt_enum($data['last_evaluation'] ?? null, ['improved','stable','not_improved'], 'last_evaluation_invalid');

  $st = $db->prepare(
    "INSERT INTO treatment_episodes
      (user_id, tree_id, disease_id, risk_level_id, status,
       current_moa_group_id, current_chemical_id,
       group_attempt_no, product_attempt_no, spray_round_no,
       last_spray_date, next_spray_date, last_evaluation)
     VALUES
      (:user_id, :tree_id, :disease_id, :risk_level_id, :status,
       :current_moa_group_id, :current_chemical_id,
       :group_attempt_no, :product_attempt_no, :spray_round_no,
       :last_spray_date, :next_spray_date, :last_evaluation)"
  );

  $st->execute([
    ':user_id' => $user_id,
    ':tree_id' => $tree_id,
    ':disease_id' => $disease_id,
    ':risk_level_id' => $risk_level_id,
    ':status' => $status,
    ':current_moa_group_id' => $current_moa_group_id,
    ':current_chemical_id' => $current_chemical_id,
    ':group_attempt_no' => $group_attempt_no,
    ':product_attempt_no' => $product_attempt_no,
    ':spray_round_no' => $spray_round_no,
    ':last_spray_date' => $last_spray_date,
    ':next_spray_date' => $next_spray_date,
    ':last_evaluation' => $last_evaluation,
  ]);

  $newId = (int)$db->lastInsertId();

  $row = $db->prepare(
    "SELECT e.*,
            t.tree_name,
            d.disease_th, d.disease_en
     FROM treatment_episodes e
     INNER JOIN orange_trees t ON t.tree_id=e.tree_id
     INNER JOIN diseases d ON d.disease_id=e.disease_id
     WHERE e.episode_id=?"
  );
  $row->execute([$newId]);

  json_ok($row->fetch(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
  if ($e->getCode() === '23000') json_err('DUPLICATE', 'duplicate_or_fk_error', 409);
  json_err('DB_ERROR', $e->getMessage(), 500);
} catch (Throwable $e) {
  json_err('SERVER_ERROR', $e->getMessage(), 500);
}
