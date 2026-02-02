<?php
header("Content-Type: application/json; charset=utf-8");
$dbPath = __DIR__ . '/../db.php'; if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php'; require_once $dbPath;
$authPath = __DIR__ . '/../auth/require_auth.php'; if (!file_exists($authPath)) $authPath = __DIR__ . '/../../auth/require_auth.php'; if (file_exists($authPath)) require_once $authPath;
if (!isset($_SESSION)) @session_start();
function dbh(): PDO { if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; if (isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh']; json_err('DB_ERROR','db_not_initialized',500); }
function is_admin_safe(): bool { return function_exists("is_admin") ? (bool)is_admin() : false; }
function session_uid(): int { $uid=(int)($_SESSION["user_id"]??0); if($uid<=0) json_err("UNAUTHORIZED","Please login",401); return $uid; }
function opt_int($v,string $code):?int{ if($v===null||$v==='') return null; if(!ctype_digit((string)$v)) json_err('VALIDATION_ERROR',$code,400); return (int)$v; }
function opt_enum($v,array $allowed,string $code):?string{ if($v===null||$v==='') return null; $s=trim((string)$v); if(!in_array($s,$allowed,true)) json_err('VALIDATION_ERROR',$code,400); return $s; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_err('METHOD_NOT_ALLOWED','get_only',405);
$db=dbh(); $session_uid=session_uid(); $isAdmin=is_admin_safe();
try{
  $q=trim((string)($_GET['q']??'')); if($q==='') json_ok([]);
  $user_id=$session_uid; if($isAdmin) $user_id=opt_int($_GET['user_id']??null,'user_id_invalid') ?? $session_uid;
  $tree_id=opt_int($_GET['tree_id']??null,'tree_id_invalid');
  $status=opt_enum($_GET['status']??null,['active','completed','stopped'],'status_invalid');
  $st=$db->prepare(
    "SELECT e.*, t.tree_name, d.disease_th, d.disease_en
     FROM treatment_episodes e
     INNER JOIN orange_trees t ON t.tree_id=e.tree_id
     INNER JOIN diseases d ON d.disease_id=e.disease_id
     WHERE e.user_id=:uid
       AND (:tree_id IS NULL OR e.tree_id=:tree_id)
       AND (:status IS NULL OR e.status=:status)
       AND (d.disease_th LIKE :q OR d.disease_en LIKE :q OR t.tree_name LIKE :q)
     ORDER BY e.episode_id DESC"
  );
  $st->execute([':uid'=>$user_id,':tree_id'=>$tree_id,':status'=>$status,':q'=>('%'.$q.'%')]);
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
}catch(Throwable $e){ json_err('DB_ERROR',$e->getMessage(),500); }
