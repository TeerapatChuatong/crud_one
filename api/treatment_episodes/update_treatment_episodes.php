<?php
header("Content-Type: application/json; charset=utf-8");
$dbPath = __DIR__ . '/../db.php'; if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php'; require_once $dbPath;
$authPath = __DIR__ . '/../auth/require_auth.php'; if (!file_exists($authPath)) $authPath = __DIR__ . '/../../auth/require_auth.php'; if (file_exists($authPath)) require_once $authPath;
if (!isset($_SESSION)) @session_start();
function dbh(): PDO { if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; if (isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh']; json_err('DB_ERROR','db_not_initialized',500); }
function read_json_body(): array { $raw=file_get_contents('php://input'); $d=json_decode($raw,true); if(is_array($d)) return $d; if(!empty($_POST)&&is_array($_POST)) return $_POST; return []; }
function is_admin_safe(): bool { return function_exists("is_admin") ? (bool)is_admin() : false; }
function session_uid(): int { $uid=(int)($_SESSION["user_id"]??0); if($uid<=0) json_err("UNAUTHORIZED","Please login",401); return $uid; }
function require_int($v,string $code):int{ if($v===null||$v===''||!ctype_digit((string)$v)) json_err('VALIDATION_ERROR',$code,400); return (int)$v; }
function opt_int($v,string $code):?int{ if($v===null||$v==='') return null; if(!ctype_digit((string)$v)) json_err('VALIDATION_ERROR',$code,400); return (int)$v; }
function opt_str($v,int $maxLen=65535):?string{ if($v===null) return null; $s=trim((string)$v); if($s==='') return null; if(mb_strlen($s)>$maxLen) json_err('VALIDATION_ERROR','value_too_long',400); return $s; }
function opt_enum($v,array $allowed,string $code):?string{ if($v===null||$v==='') return null; $s=trim((string)$v); if(!in_array($s,$allowed,true)) json_err('VALIDATION_ERROR',$code,400); return $s; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('METHOD_NOT_ALLOWED','patch_or_post_only',405);
$db=dbh(); $data=read_json_body(); $session_uid=session_uid(); $isAdmin=is_admin_safe();
$episode_id=require_int($data['episode_id'] ?? ($_GET['episode_id'] ?? null),'episode_id_invalid');
try{
  if(!$isAdmin){ $chk=$db->prepare("SELECT episode_id FROM treatment_episodes WHERE episode_id=? AND user_id=?"); $chk->execute([$episode_id,$session_uid]); if(!$chk->fetch()) json_err('FORBIDDEN','episode_not_owned',403); }
  $v_status=opt_enum($data['status']??null,['active','completed','stopped'],'status_invalid');
  $v_current_moa_group_id=opt_int($data['current_moa_group_id']??null,'current_moa_group_id_invalid');
  $v_current_chemical_id=opt_int($data['current_chemical_id']??null,'current_chemical_id_invalid');
  $v_diagnosis_history_id=opt_int($data['diagnosis_history_id']??null,'diagnosis_history_id_invalid');
  $v_group_attempt_no=opt_int($data['group_attempt_no']??null,'group_attempt_no_invalid');
  $v_product_attempt_no=opt_int($data['product_attempt_no']??null,'product_attempt_no_invalid');
  $v_spray_round_no=opt_int($data['spray_round_no']??null,'spray_round_no_invalid');
  $v_start_spray_date=opt_str($data['start_spray_date']??null,20);
  $v_last_spray_date=opt_str($data['last_spray_date']??null,20);
  $v_next_spray_date=opt_str($data['next_spray_date']??null,20);
  $v_last_evaluation=opt_enum($data['last_evaluation']??null,['improved','stable','not_improved'],'last_evaluation_invalid');
  $sets=[]; $params=[':id'=>$episode_id];
  if($v_status!==null){$sets[]="status=:status";$params[':status']=$v_status;}
  if($v_current_moa_group_id!==null){$sets[]="current_moa_group_id=:current_moa_group_id";$params[':current_moa_group_id']=$v_current_moa_group_id;}
  if($v_current_chemical_id!==null){$sets[]="current_chemical_id=:current_chemical_id";$params[':current_chemical_id']=$v_current_chemical_id;}
  if($v_diagnosis_history_id!==null){$sets[]="diagnosis_history_id=:diagnosis_history_id";$params[':diagnosis_history_id']=$v_diagnosis_history_id;}
  if($v_group_attempt_no!==null){$sets[]="group_attempt_no=:group_attempt_no";$params[':group_attempt_no']=$v_group_attempt_no;}
  if($v_product_attempt_no!==null){$sets[]="product_attempt_no=:product_attempt_no";$params[':product_attempt_no']=$v_product_attempt_no;}
  if($v_spray_round_no!==null){$sets[]="spray_round_no=:spray_round_no";$params[':spray_round_no']=$v_spray_round_no;}
  if($v_start_spray_date!==null){$sets[]="start_spray_date=:start_spray_date";$params[':start_spray_date']=$v_start_spray_date;}
  if($v_last_spray_date!==null){$sets[]="last_spray_date=:last_spray_date";$params[':last_spray_date']=$v_last_spray_date;}
  if($v_next_spray_date!==null){$sets[]="next_spray_date=:next_spray_date";$params[':next_spray_date']=$v_next_spray_date;}
  if($v_last_evaluation!==null){$sets[]="last_evaluation=:last_evaluation";$params[':last_evaluation']=$v_last_evaluation;}
  if(count($sets)===0) json_err('VALIDATION_ERROR','no_fields_to_update',400);
  $st=$db->prepare("UPDATE treatment_episodes SET ".implode(', ',$sets)." WHERE episode_id=:id");
  $st->execute($params);
  $row=$db->prepare("SELECT * FROM treatment_episodes WHERE episode_id=?"); $row->execute([$episode_id]); $r=$row->fetch(PDO::FETCH_ASSOC);
  if(!$r) json_err('NOT_FOUND','episode_not_found',404);
  json_ok($r);
}catch(PDOException $e){ if($e->getCode()==='23000') json_err('DUPLICATE','duplicate_or_fk_error',409); json_err('DB_ERROR',$e->getMessage(),500); }
catch(Throwable $e){ json_err('SERVER_ERROR',$e->getMessage(),500); }
