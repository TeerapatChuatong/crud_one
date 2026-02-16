<?php
header("Content-Type: application/json; charset=utf-8");
$dbPath=__DIR__.'/../db.php'; if(!file_exists($dbPath)) $dbPath=__DIR__.'/../../db.php'; require_once $dbPath;
$authPath=__DIR__.'/../auth/require_auth.php'; if(!file_exists($authPath)) $authPath=__DIR__.'/../../auth/require_auth.php'; if(file_exists($authPath)) require_once $authPath;
if(!isset($_SESSION)) @session_start();
function dbh():PDO{ if(isset($GLOBALS['pdo'])&&$GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; if(isset($GLOBALS['dbh'])&&$GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh']; json_err('DB_ERROR','db_not_initialized',500); }
function read_json_body():array{ $raw=file_get_contents('php://input'); $d=json_decode($raw,true); if(is_array($d)) return $d; if(!empty($_POST)&&is_array($_POST)) return $_POST; return []; }
function is_admin_safe():bool{ return function_exists("is_admin") ? (bool)is_admin() : false; }
function session_uid():int{ $uid=(int)($_SESSION["user_id"]??0); if($uid<=0) json_err("UNAUTHORIZED","Please login",401); return $uid; }
function require_int($v,string $code):int{ if($v===null||$v===''||!ctype_digit((string)$v)) json_err('VALIDATION_ERROR',$code,400); return (int)$v; }
function opt_int($v,string $code):?int{ if($v===null||$v==='') return null; if(!ctype_digit((string)$v)) json_err('VALIDATION_ERROR',$code,400); return (int)$v; }
function opt_enum($v,array $allowed,string $code):?string{ if($v===null||$v==='') return null; $s=trim((string)$v); if(!in_array($s,$allowed,true)) json_err('VALIDATION_ERROR',$code,400); return $s; }
function opt_str($v,int $maxLen=65535):?string{ if($v===null) return null; $s=trim((string)$v); if($s==='') return null; if(mb_strlen($s)>$maxLen) json_err('VALIDATION_ERROR','value_too_long',400); return $s; }
if(($_SERVER['REQUEST_METHOD']??'')!=='PATCH' && ($_SERVER['REQUEST_METHOD']??'')!=='POST') json_err('METHOD_NOT_ALLOWED','patch_or_post_only',405);
$db=dbh(); $data=read_json_body(); $uid=session_uid(); $isAdmin=is_admin_safe();
$event_id=require_int($data['event_id'] ?? ($_GET['event_id'] ?? null),'event_id_invalid');
try{
  if(!$isAdmin){ $chk=$db->prepare("SELECT ev.event_id FROM treatment_episode_events ev INNER JOIN treatment_episodes e ON e.episode_id=ev.episode_id WHERE ev.event_id=? AND e.user_id=?"); $chk->execute([$event_id,$uid]); if(!$chk->fetch()) json_err('FORBIDDEN','event_not_owned',403); }
  $v_moa_group_id=opt_int($data['moa_group_id']??null,'moa_group_id_invalid');
  $v_chemical_id=opt_int($data['chemical_id']??null,'chemical_id_invalid');
  $v_spray_round_no=opt_int($data['spray_round_no']??null,'spray_round_no_invalid');
  $v_evaluation_result=opt_enum($data['evaluation_result']??null,['improved','stable','not_improved'],'evaluation_result_invalid');
  $v_note=opt_str($data['note']??null);

  $has_planned_start_date = array_key_exists('planned_start_date', $data);
  $v_planned_start_date = null;
  if ($has_planned_start_date) {
    $vv = $data['planned_start_date'];
    if ($vv === null || $vv === '') {
      $v_planned_start_date = null;
    } else {
      $s = trim((string)$vv);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) json_err('VALIDATION_ERROR','invalid_planned_start_date',400);
      $v_planned_start_date = $s;
    }
  }

  $sets=[]; $params=[':id'=>$event_id];
  if($v_moa_group_id!==null){$sets[]="moa_group_id=:moa_group_id";$params[':moa_group_id']=$v_moa_group_id;}
  if($v_chemical_id!==null){$sets[]="chemical_id=:chemical_id";$params[':chemical_id']=$v_chemical_id;}
  if($v_spray_round_no!==null){$sets[]="spray_round_no=:spray_round_no";$params[':spray_round_no']=$v_spray_round_no;}
  if($v_evaluation_result!==null){$sets[]="evaluation_result=:evaluation_result";$params[':evaluation_result']=$v_evaluation_result;}
  if($v_note!==null){$sets[]="note=:note";$params[':note']=$v_note;}
  if($has_planned_start_date){$sets[]="planned_start_date=:planned_start_date";$params[':planned_start_date']=$v_planned_start_date;}
  if(count($sets)===0) json_err('VALIDATION_ERROR','no_fields_to_update',400);
  $st=$db->prepare("UPDATE treatment_episode_events SET ".implode(', ',$sets)." WHERE event_id=:id");
  $st->execute($params);
  $row=$db->prepare("SELECT * FROM treatment_episode_events WHERE event_id=?"); $row->execute([$event_id]); $r=$row->fetch(PDO::FETCH_ASSOC);
  if(!$r) json_err('NOT_FOUND','event_not_found',404);
  json_ok($r);
}catch(Throwable $e){ json_err('DB_ERROR',$e->getMessage(),500); }
