<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php'; // ✅ รองรับ Authorization: Bearer <token>

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$isAdmin = is_admin();
$session_user_id = (int)($_SESSION['user_id'] ?? 0);

$q = trim($_GET['q'] ?? '');
$user_id = $_GET['user_id'] ?? null; // admin เท่านั้น
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($limit <= 0) $limit = 50;
if ($limit > 200) $limit = 200;
if ($offset < 0) $offset = 0;

try {
  $where = [];
  $params = [];

  // จำกัดตามผู้ใช้
  if ($isAdmin && $user_id !== null && $user_id !== '') {
    if (!ctype_digit((string)$user_id)) json_err("VALIDATION_ERROR","invalid_user_id",400);
    $where[] = "dh.user_id = ?";
    $params[] = (int)$user_id;
  } else {
    $where[] = "dh.user_id = ?";
    $params[] = $session_user_id;
  }

  if ($q !== '') {
    $where[] = "(
      d.disease_th LIKE ? OR d.disease_en LIKE ? OR
      ot.tree_name LIKE ? OR
      COALESCE(rl.level_code,'') LIKE ? OR
      COALESCE(dh.image_url,'') LIKE ?
    )";
    $kw = "%{$q}%";
    $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
  }

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
