<?php
header("Content-Type: application/json; charset=utf-8");

$dbPath=__DIR__.'/../db.php';
if(!file_exists($dbPath)) $dbPath=__DIR__.'/../../db.php';
require_once $dbPath;

$authPath=__DIR__.'/../auth/require_auth.php';
if(!file_exists($authPath)) $authPath=__DIR__.'/../../auth/require_auth.php';
if(file_exists($authPath)) require_once $authPath;

if(!isset($_SESSION)) @session_start();

function dbh():PDO{
  if(isset($GLOBALS['pdo'])&&$GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  if(isset($GLOBALS['dbh'])&&$GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh'];
  json_err('DB_ERROR','db_not_initialized',500);
}
function read_json_body():array{
  $raw=file_get_contents('php://input');
  $d=json_decode($raw,true);
  if(is_array($d)) return $d;
  if(!empty($_POST)&&is_array($_POST)) return $_POST;
  return [];
}
function is_admin_safe():bool{
  return function_exists("is_admin") ? (bool)is_admin() : false;
}
function session_uid():int{
  $uid=(int)($_SESSION["user_id"]??0);
  if($uid<=0) json_err("UNAUTHORIZED","Please login",401);
  return $uid;
}
function require_int($v,string $code):int{
  if($v===null||$v===''||!ctype_digit((string)$v)) json_err('VALIDATION_ERROR',$code,400);
  return (int)$v;
}
function opt_int($v,string $code):?int{
  if($v===null||$v==='') return null;
  if(!ctype_digit((string)$v)) json_err('VALIDATION_ERROR',$code,400);
  return (int)$v;
}
function opt_enum($v,array $allowed,string $code):?string{
  if($v===null||$v==='') return null;
  $s=trim((string)$v);
  if(!in_array($s,$allowed,true)) json_err('VALIDATION_ERROR',$code,400);
  return $s;
}
function opt_str($v,int $maxLen=65535):?string{
  if($v===null) return null;
  $s=trim((string)$v);
  if($s==='') return null;
  if(mb_strlen($s)>$maxLen) json_err('VALIDATION_ERROR','value_too_long',400);
  return $s;
}

if(($_SERVER['REQUEST_METHOD']??'')!=='POST') json_err('METHOD_NOT_ALLOWED','post_only',405);

$db=dbh();
$data=read_json_body();
$uid=session_uid();
$isAdmin=is_admin_safe();

try{
  $episode_id=require_int($data['episode_id']??null,'episode_id_invalid');

  if(!$isAdmin){
    $chk=$db->prepare("SELECT episode_id FROM treatment_episodes WHERE episode_id=? AND user_id=?");
    $chk->execute([$episode_id,$uid]);
    if(!$chk->fetch()) json_err('FORBIDDEN','episode_not_owned',403);
  }

  $event_type=opt_enum($data['event_type']??null,['spray','evaluate','switch_product','switch_group','note'],'event_type_invalid');
  if($event_type===null) json_err('VALIDATION_ERROR','event_type_required',400);

  $moa_group_id=opt_int($data['moa_group_id']??null,'moa_group_id_invalid');
  $chemical_id=opt_int($data['chemical_id']??null,'chemical_id_invalid');

  // ✅ Auto-fill moa_group_id when spray + chemical_id provided
  if($event_type==='spray' && $chemical_id!==null && ($moa_group_id===null || $moa_group_id===0)){
    $q=$db->prepare("SELECT moa_group_id FROM chemicals WHERE chemical_id=? LIMIT 1");
    $q->execute([$chemical_id]);
    $mg=$q->fetchColumn();
    if($mg!==false && $mg!==null && $mg!=='') $moa_group_id=(int)$mg;
  }

  $spray_round_no=opt_int($data['spray_round_no']??null,'spray_round_no_invalid');
  $evaluation_result=opt_enum($data['evaluation_result']??null,['improved','stable','not_improved'],'evaluation_result_invalid');
  $note=opt_str($data['note']??null);

  $st=$db->prepare("
    INSERT INTO treatment_episode_events
      (episode_id,event_type,moa_group_id,chemical_id,spray_round_no,evaluation_result,note)
    VALUES
      (:episode_id,:event_type,:moa_group_id,:chemical_id,:spray_round_no,:evaluation_result,:note)
  ");
  $st->execute([
    ':episode_id'=>$episode_id,
    ':event_type'=>$event_type,
    ':moa_group_id'=>$moa_group_id,
    ':chemical_id'=>$chemical_id,
    ':spray_round_no'=>$spray_round_no,
    ':evaluation_result'=>$evaluation_result,
    ':note'=>$note
  ]);

  $newId=(int)$db->lastInsertId();
  $row=$db->prepare("SELECT * FROM treatment_episode_events WHERE event_id=?");
  $row->execute([$newId]);
  json_ok($row->fetch(PDO::FETCH_ASSOC));
}catch(Throwable $e){
  json_err('DB_ERROR',$e->getMessage(),500);
}
