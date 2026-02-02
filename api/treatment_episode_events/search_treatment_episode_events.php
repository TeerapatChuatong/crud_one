<?php
header("Content-Type: application/json; charset=utf-8");
$dbPath=__DIR__.'/../db.php'; if(!file_exists($dbPath)) $dbPath=__DIR__.'/../../db.php'; require_once $dbPath;
$authPath=__DIR__.'/../auth/require_auth.php'; if(!file_exists($authPath)) $authPath=__DIR__.'/../../auth/require_auth.php'; if(file_exists($authPath)) require_once $authPath;
if(!isset($_SESSION)) @session_start();
function dbh():PDO{ if(isset($GLOBALS['pdo'])&&$GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; if(isset($GLOBALS['dbh'])&&$GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh']; json_err('DB_ERROR','db_not_initialized',500); }
function is_admin_safe():bool{ return function_exists("is_admin") ? (bool)is_admin() : false; }
function session_uid():int{ $uid=(int)($_SESSION["user_id"]??0); if($uid<=0) json_err("UNAUTHORIZED","Please login",401); return $uid; }
function opt_int($v,string $code):?int{ if($v===null||$v==='') return null; if(!ctype_digit((string)$v)) json_err('VALIDATION_ERROR',$code,400); return (int)$v; }
if(($_SERVER['REQUEST_METHOD']??'')!=='GET') json_err('METHOD_NOT_ALLOWED','get_only',405);
$db=dbh(); $uid=session_uid(); $isAdmin=is_admin_safe();
try{
  $q=trim((string)($_GET['q']??'')); if($q==='') json_ok([]);
  $episode_id=opt_int($_GET['episode_id']??null,'episode_id_invalid');
  $sql="SELECT ev.* FROM treatment_episode_events ev INNER JOIN treatment_episodes e ON e.episode_id=ev.episode_id WHERE (ev.note LIKE :q OR ev.event_type LIKE :q) AND (:episode_id IS NULL OR ev.episode_id=:episode_id)".($isAdmin?"":" AND e.user_id=:uid")." ORDER BY ev.event_id ASC";
  $params=[':q'=>('%'.$q.'%'),':episode_id'=>$episode_id]; if(!$isAdmin) $params[':uid']=$uid;
  $st=$db->prepare($sql); $st->execute($params);
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
}catch(Throwable $e){ json_err('DB_ERROR',$e->getMessage(),500); }
