<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php'; // ✅ รองรับ Bearer token (auth_token)

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$isAdmin = is_admin();
$session_user_id = (int)($_SESSION['user_id'] ?? 0);

$diagnosis_history_id = $_GET['diagnosis_history_id'] ?? $_GET['id'] ?? null;
$user_id    = $_GET['user_id'] ?? null; // admin เท่านั้น
$tree_id    = $_GET['tree_id'] ?? null;
$disease_id = $_GET['disease_id'] ?? null;
$risk_level_id = $_GET['risk_level_id'] ?? null;

$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($limit <= 0) $limit = 50;
if ($limit > 200) $limit = 200;
if ($offset < 0) $offset = 0;

try {
  // ✅ read one
  if ($diagnosis_history_id !== null && $diagnosis_history_id !== '') {
    if (!ctype_digit((string)$diagnosis_history_id)) json_err("VALIDATION_ERROR","invalid_id",400);

    $sql = "
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
    ";
    $st = $dbh->prepare($sql);
    $st->execute([(int)$diagnosis_history_id]);
    $row = $st->fetch();
    if (!$row) json_err("NOT_FOUND","not_found",404);

    if (!$isAdmin && (int)$row['user_id'] !== $session_user_id) {
      json_err("FORBIDDEN","not_owner",403);
    }

    json_ok($row);
  }

  // ✅ read list
  $where = [];
  $params = [];

  // user_id: ถ้าไม่ใช่ admin ให้ล็อกไว้ที่ session
  if ($isAdmin && $user_id !== null && $user_id !== '') {
    if (!ctype_digit((string)$user_id)) json_err("VALIDATION_ERROR","invalid_user_id",400);
    $where[] = "dh.user_id = ?";
    $params[] = (int)$user_id;
  } else {
    $where[] = "dh.user_id = ?";
    $params[] = $session_user_id;
  }

  if ($tree_id !== null && $tree_id !== '') {
    if (!ctype_digit((string)$tree_id)) json_err("VALIDATION_ERROR","invalid_tree_id",400);
    $where[] = "dh.tree_id = ?";
    $params[] = (int)$tree_id;
  }
  if ($disease_id !== null && $disease_id !== '') {
    if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
    $where[] = "dh.disease_id = ?";
    $params[] = (int)$disease_id;
  }
  if ($risk_level_id !== null && $risk_level_id !== '') {
    if (!ctype_digit((string)$risk_level_id)) json_err("VALIDATION_ERROR","invalid_risk_level_id",400);
    $where[] = "dh.risk_level_id = ?";
    $params[] = (int)$risk_level_id;
  }
  if ($from !== '') { $where[] = "dh.diagnosed_at >= ?"; $params[] = $from; }
  if ($to !== '')   { $where[] = "dh.diagnosed_at <= ?"; $params[] = $to; }

  $sql = "
    SELECT
      dh.*,
      d.disease_th, d.disease_en,
      ot.tree_name,
      rl.level_code, rl.min_score, rl.days, rl.times
    FROM diagnosis_history dh
    JOIN diseases d ON d.disease_id = dh.disease_id
    JOIN orange_trees ot ON ot.tree_id = dh.tree_id
    LEFT JOIN disease_risk_levels rl ON rl.risk_level_id = dh.risk_level_id
  ";
  if ($where) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY dh.diagnosed_at DESC, dh.diagnosis_history_id DESC LIMIT {$limit} OFFSET {$offset}";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
