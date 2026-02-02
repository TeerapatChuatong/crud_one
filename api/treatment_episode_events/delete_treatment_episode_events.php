<?php
header("Content-Type: application/json; charset=utf-8");
$dbPath=__DIR__.'/../db.php'; if(!file_exists($dbPath)) $dbPath=__DIR__.'/../../db.php'; require_once $dbPath;
$authPath=__DIR__.'/../auth/require_auth.php'; if(!file_exists($authPath)) $authPath=__DIR__.'/../../auth/require_auth.php'; if(file_exists($authPath)) require_once $authPath;
if(!isset($_SESSION)) @session_start();
function dbh():PDO{ if(isset($GLOBALS['pdo'])&&$GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; if(isset($GLOBALS['dbh'])&&$GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh']; json_err('DB_ERROR','db_not_initialized',500); }
function is_admin_safe():bool{ return function_exists("is_admin") ? (bool)is_admin() : false; }
function session_uid():int{ $uid=(int)($_SESSION["user_id"]??0); if($uid<=0) json_err("UNAUTHORIZED","Please login",401); return $uid; }
function require_int($v,string $code):int{ if($v===null||$v===''||!ctype_digit((string)$v)) json_err('VALIDATION_ERROR',$code,400); return (int)$v; }
if(($_SERVER['REQUEST_METHOD']??'')!=='DELETE') json_err('METHOD_NOT_ALLOWED','delete_only',405);
$db=dbh(); $uid=session_uid(); $isAdmin=is_admin_safe();
$event_id=require_int($_GET['event_id'] ?? null,'event_id_invalid');
try{
  if(!$isAdmin){ $chk=$db->prepare("SELECT ev.event_id FROM treatment_episode_events ev INNER JOIN treatment_episodes e ON e.episode_id=ev.episode_id WHERE ev.event_id=? AND e.user_id=?"); $chk->execute([$event_id,$uid]); if(!$chk->fetch()) json_err('FORBIDDEN','event_not_owned',403); }
  $st=$db->prepare("DELETE FROM treatment_episode_events WHERE event_id=?"); $st->execute([$event_id]);
  if($st->rowCount()===0) json_err('NOT_FOUND','event_not_found',404);
  json_ok(['event_id'=>$event_id,'deleted'=>true]);
}catch(Throwable $e){ json_err('DB_ERROR',$e->getMessage(),500); }
