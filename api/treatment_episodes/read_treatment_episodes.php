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
function is_admin_safe(): bool {
  return function_exists("is_admin") ? (bool)is_admin() : false;
}
function session_uid(): int {
  $uid = (int)($_SESSION["user_id"] ?? 0);
  if ($uid <= 0) json_err("UNAUTHORIZED", "Please login", 401);
  return $uid;
}
function opt_int($v, string $code) : ?int {
  if ($v === null || $v === '') return null;
  if (!ctype_digit((string)$v)) json_err('VALIDATION_ERROR', $code, 400);
  return (int)$v;
}
function opt_enum($v, array $allowed, string $code) : ?string {
  if ($v === null || $v === '') return null;
  $s = trim((string)$v);
  if (!in_array($s, $allowed, true)) json_err('VALIDATION_ERROR', $code, 400);
  return $s;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
}

$db = dbh();
$session_uid = session_uid();
$isAdmin = is_admin_safe();

$episode_id = opt_int($_GET['episode_id'] ?? null, 'episode_id_invalid');
// ✅ รองรับการอ่าน episode ด้วย diagnosis_history_id (ใช้กับ flow “ติดตามผลหลังครบแผน”)
$diagnosis_history_id = opt_int($_GET['diagnosis_history_id'] ?? null, 'diagnosis_history_id_invalid');

try {
  if ($episode_id !== null) {
    if ($isAdmin) {
      $st = $db->prepare(
        "SELECT e.*,
                t.tree_name,
                d.disease_th, d.disease_en
         FROM treatment_episodes e
         INNER JOIN orange_trees t ON t.tree_id=e.tree_id
         INNER JOIN diseases d ON d.disease_id=e.disease_id
         WHERE e.episode_id=:id"
      );
      $st->execute([':id' => $episode_id]);
    } else {
      $st = $db->prepare(
        "SELECT e.*,
                t.tree_name,
                d.disease_th, d.disease_en
         FROM treatment_episodes e
         INNER JOIN orange_trees t ON t.tree_id=e.tree_id
         INNER JOIN diseases d ON d.disease_id=e.disease_id
         WHERE e.episode_id=:id AND e.user_id=:uid"
      );
      $st->execute([':id' => $episode_id, ':uid' => $session_uid]);
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err('NOT_FOUND', 'episode_not_found', 404);
    json_ok($row);
  }

  // ✅ อ่าน episode ของ diagnosis_history_id (ถ้ามี) — จะคืนเป็น list
  //    1) พยายามหาโดย e.diagnosis_history_id ก่อน
  //    2) ถ้าไม่พบ (กรณีข้อมูลเก่ายังไม่ผูก) -> fallback หา episode ล่าสุดของ tree+disease จาก diagnosis_history
  if ($diagnosis_history_id !== null) {
    $user_id = $session_uid;
    if ($isAdmin) $user_id = opt_int($_GET['user_id'] ?? null, 'user_id_invalid') ?? $session_uid;

    $st = $db->prepare(
      "SELECT e.*,
              t.tree_name,
              d.disease_th, d.disease_en
       FROM treatment_episodes e
       INNER JOIN orange_trees t ON t.tree_id=e.tree_id
       INNER JOIN diseases d ON d.disease_id=e.disease_id
       WHERE e.user_id=:uid AND e.diagnosis_history_id=:dh_id
       ORDER BY e.episode_id DESC"
    );
    $st->execute([':uid' => $user_id, ':dh_id' => $diagnosis_history_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows || count($rows) === 0) {
      if ($isAdmin) {
        $stDh = $db->prepare(
          "SELECT user_id, tree_id, disease_id
           FROM diagnosis_history
           WHERE diagnosis_history_id=:dh_id
           LIMIT 1"
        );
        $stDh->execute([':dh_id' => $diagnosis_history_id]);
      } else {
        $stDh = $db->prepare(
          "SELECT user_id, tree_id, disease_id
           FROM diagnosis_history
           WHERE diagnosis_history_id=:dh_id AND user_id=:uid
           LIMIT 1"
        );
        $stDh->execute([':dh_id' => $diagnosis_history_id, ':uid' => $user_id]);
      }

      $dh = $stDh->fetch(PDO::FETCH_ASSOC);
      if ($dh) {
        $st2 = $db->prepare(
          "SELECT e.*,
                  t.tree_name,
                  d.disease_th, d.disease_en
           FROM treatment_episodes e
           INNER JOIN orange_trees t ON t.tree_id=e.tree_id
           INNER JOIN diseases d ON d.disease_id=e.disease_id
           WHERE e.user_id=:uid AND e.tree_id=:tree_id AND e.disease_id=:disease_id
           ORDER BY e.episode_id DESC
           LIMIT 5"
        );
        $st2->execute([
          ':uid' => (int)$dh['user_id'],
          ':tree_id' => (int)$dh['tree_id'],
          ':disease_id' => (int)$dh['disease_id'],
        ]);
        $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
      }
    }

    json_ok($rows ?? []);
  }

  $user_id = $session_uid;
  if ($isAdmin) $user_id = opt_int($_GET['user_id'] ?? null, 'user_id_invalid') ?? $session_uid;

  $tree_id = opt_int($_GET['tree_id'] ?? null, 'tree_id_invalid');
  $status  = opt_enum($_GET['status'] ?? null, ['active','completed','stopped'], 'status_invalid');

  $st = $db->prepare(
    "SELECT e.*,
            t.tree_name,
            d.disease_th, d.disease_en
     FROM treatment_episodes e
     INNER JOIN orange_trees t ON t.tree_id=e.tree_id
     INNER JOIN diseases d ON d.disease_id=e.disease_id
     WHERE e.user_id=:uid
       AND (:tree_id IS NULL OR e.tree_id=:tree_id)
       AND (:status IS NULL OR e.status=:status)
     ORDER BY e.episode_id DESC"
  );
  $st->execute([':uid'=>$user_id, ':tree_id'=>$tree_id, ':status'=>$status]);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err('DB_ERROR', $e->getMessage(), 500);
}
