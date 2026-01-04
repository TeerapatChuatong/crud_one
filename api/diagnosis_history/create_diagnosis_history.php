<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php'; // ✅ รองรับ Bearer token

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) $body = $_POST ?? [];

$isAdmin = is_admin();
$session_user_id = (int)($_SESSION['user_id'] ?? 0);

// user_id: ถ้าไม่ใช่ admin ให้ยึดจาก session เท่านั้น
$user_id = $body['user_id'] ?? $session_user_id;
if (!$isAdmin) $user_id = $session_user_id;

$tree_id    = $body['tree_id'] ?? null;
$disease_id = $body['disease_id'] ?? null;

$risk_level_id = array_key_exists('risk_level_id', $body) ? $body['risk_level_id'] : null;
$total_score   = array_key_exists('total_score', $body) ? $body['total_score'] : 0;
$image_url     = array_key_exists('image_url', $body) ? trim((string)$body['image_url']) : null;
$diagnosed_at  = array_key_exists('diagnosed_at', $body) ? trim((string)$body['diagnosed_at']) : null;

if (!ctype_digit((string)$user_id) || (int)$user_id <= 0) json_err("VALIDATION_ERROR", "invalid_user_id", 400);
if (!ctype_digit((string)$tree_id) || (int)$tree_id <= 0) json_err("VALIDATION_ERROR", "invalid_tree_id", 400);
if (!ctype_digit((string)$disease_id) || (int)$disease_id <= 0) json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
if (!is_numeric($total_score)) json_err("VALIDATION_ERROR", "invalid_total_score", 400);

try {
  // ตรวจสอบต้นส้ม (และต้องเป็นของ user ถ้าไม่ใช่ admin)
  if ($isAdmin) {
    $chkTree = $dbh->prepare("SELECT tree_id FROM orange_trees WHERE tree_id=? LIMIT 1");
    $chkTree->execute([(int)$tree_id]);
  } else {
    $chkTree = $dbh->prepare("SELECT tree_id FROM orange_trees WHERE tree_id=? AND user_id=? LIMIT 1");
    $chkTree->execute([(int)$tree_id, (int)$user_id]);
  }
  if (!$chkTree->fetch()) json_err("NOT_FOUND", "tree_not_found_or_not_owner", 404);

  // ตรวจสอบโรค
  $chkD = $dbh->prepare("SELECT disease_id FROM diseases WHERE disease_id=? LIMIT 1");
  $chkD->execute([(int)$disease_id]);
  if (!$chkD->fetch()) json_err("NOT_FOUND", "disease_not_found", 404);

  // ตรวจสอบ risk_level_id (ถ้าส่งมา)
  $risk_val = null;
  if ($risk_level_id !== null && $risk_level_id !== '') {
    if (!ctype_digit((string)$risk_level_id)) json_err("VALIDATION_ERROR", "invalid_risk_level_id", 400);

    $chkR = $dbh->prepare("SELECT risk_level_id FROM disease_risk_levels WHERE risk_level_id=? AND disease_id=? LIMIT 1");
    $chkR->execute([(int)$risk_level_id, (int)$disease_id]);
    if (!$chkR->fetch()) json_err("NOT_FOUND", "risk_level_not_found_for_disease", 404);

    $risk_val = (int)$risk_level_id;
  }

  $img = ($image_url === '') ? null : $image_url;
  $diag = ($diagnosed_at === '') ? null : $diagnosed_at;

  if ($diag === null) {
    $st = $dbh->prepare("
      INSERT INTO diagnosis_history (user_id, tree_id, disease_id, risk_level_id, total_score, image_url)
      VALUES (?,?,?,?,?,?)
    ");
    $st->execute([(int)$user_id, (int)$tree_id, (int)$disease_id, $risk_val, (int)$total_score, $img]);
  } else {
    $st = $dbh->prepare("
      INSERT INTO diagnosis_history (user_id, tree_id, disease_id, risk_level_id, total_score, image_url, diagnosed_at)
      VALUES (?,?,?,?,?,?,?)
    ");
    $st->execute([(int)$user_id, (int)$tree_id, (int)$disease_id, $risk_val, (int)$total_score, $img, $diag]);
  }

  $newId = (int)$dbh->lastInsertId();

  $q = $dbh->prepare("
    SELECT
      dh.*,
      d.disease_th, d.disease_en,
      ot.tree_name,
      rl.level_code, rl.min_score, rl.days, rl.times
    FROM diagnosis_history dh
    JOIN diseases d ON d.disease_id = dh.disease_id
    JOIN orange_trees ot ON ot.tree_id = dh.tree_id
    LEFT JOIN disease_risk_levels rl ON rl.risk_level_id = dh.risk_level_id
    WHERE dh.diagnosis_history_id = ?
    LIMIT 1
  ");
  $q->execute([$newId]);

  json_ok($q->fetch() ?: ["diagnosis_history_id" => $newId]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
