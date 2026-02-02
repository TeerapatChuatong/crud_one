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
$event_id=opt_int($_GET['event_id']??null,'event_id_invalid');
$episode_id=opt_int($_GET['episode_id']??null,'episode_id_invalid');
try{
  if($event_id!==null){
    $sql="SELECT ev.* FROM treatment_episode_events ev INNER JOIN treatment_episodes e ON e.episode_id=ev.episode_id WHERE ev.event_id=:id".($isAdmin?"":" AND e.user_id=:uid");
    $st=$db->prepare($sql); $params=[':id'=>$event_id]; if(!$isAdmin) $params[':uid']=$uid; $st->execute($params);
    $row=$st->fetch(PDO::FETCH_ASSOC); if(!$row) json_err('NOT_FOUND','event_not_found',404); json_ok($row);
  }
  if($episode_id===null) json_err('VALIDATION_ERROR','episode_id_required',400);
  if(!$isAdmin){ $chk=$db->prepare("SELECT episode_id FROM treatment_episodes WHERE episode_id=? AND user_id=?"); $chk->execute([$episode_id,$uid]); if(!$chk->fetch()) json_err('FORBIDDEN','episode_not_owned',403); }
  $st=$db->prepare("SELECT * FROM treatment_episode_events WHERE episode_id=:episode_id ORDER BY event_id ASC");
  $st->execute([':episode_id'=>$episode_id]);
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
}catch(Throwable $e){ json_err('DB_ERROR',$e->getMessage(),500); }
