<?php
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../config/app.php';require_once __DIR__.'/../../utils/csrf_sf.php';require_once __DIR__.'/../../utils/acl.php';require_once __DIR__.'/../../utils/mileage.php';require_once __DIR__.'/../../utils/api_response.php';
header('Content-Type: application/json');
function mt_json(array $data,int $status=200):never{
 if($status<400)api_json_success($data,$status);
 $codes=[400=>'invalid_tracking_request',401=>'authentication_required',403=>'permission_denied',404=>'not_found',409=>'tracking_conflict'];
 api_json_failure($status,$codes[$status]??'tracking_error',(string)($data['message']??$data['error']??'Mileage tracking failed.'));
}
$actor=new SessionMileageActorAdapter((int)($_SESSION['user']['id']??0),request_client_org_id()?:null);$userId=$actor->userId();$orgId=$actor->organizationId()??0;
if($userId<=0)mt_json(['error'=>'Authentication required.'],401);if(!user_can($pdo,$userId,'financial.manage',0))mt_json(['error'=>'Permission denied.'],403);if(empty($appConfig['mileage_tracking_enabled']))mt_json(['error'=>'GPS mileage tracking is not enabled.'],403);
$action=(string)($_GET['action']??'status');$body=json_decode((string)file_get_contents('php://input'),true);if(!is_array($body))$body=$_POST;
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if(!mileage_tracking_method_allowed($method,$action)){header('Allow: GET, POST');mt_json(['error'=>'Tracking changes require POST.'],405);}
if($method==='POST'){$token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??($body['_token']??''));if(!csrf_sf_is_valid('mileage_tracking',$token))mt_json(['error'=>'Invalid request token.'],400);}
function mt_session(PDO $pdo,int $id,int $userId,bool $lock=false):array{$s=$pdo->prepare('SELECT * FROM mileage_tracking_sessions WHERE id=? AND user_id=?'.($lock?' FOR UPDATE':''));$s->execute([$id,$userId]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Tracking session not found.');return $row;}
try{
 if($action==='status'){$s=$pdo->prepare('SELECT * FROM mileage_tracking_sessions WHERE user_id=? AND status IN ("active","draft_review") ORDER BY id DESC LIMIT 1');$s->execute([$userId]);mt_json(['session'=>$s->fetch(PDO::FETCH_ASSOC)?:null]);}
 if($action==='start'){
  $pdo->beginTransaction();$s=$pdo->prepare('SELECT * FROM mileage_tracking_sessions WHERE user_id=? AND status IN ("active","draft_review") ORDER BY id DESC LIMIT 1 FOR UPDATE');$s->execute([$userId]);$existing=$s->fetch(PDO::FETCH_ASSOC);if($existing){$pdo->commit();mt_json(['session'=>$existing,'resumed'=>true]);}
  $pdo->prepare('INSERT INTO mileage_tracking_sessions (organization_id,user_id,status,started_at) VALUES (?,?,"active",NOW(3))')->execute([$orgId?:null,$userId]);$id=(int)$pdo->lastInsertId();$row=mt_session($pdo,$id,$userId);$pdo->commit();mt_json(['session'=>$row,'resumed'=>false],201);
 }
 $id=max(0,(int)($body['session_id']??$_GET['session_id']??0));
 if($action==='points'){
  $session=mt_session($pdo,$id,$userId);if($session['status']!=='active')throw new RuntimeException('This tracking session is not active.');$points=(array)($body['points']??[]);if(count($points)>200)throw new InvalidArgumentException('Upload no more than 200 points at once.');
  $last=$pdo->prepare('SELECT latitude,longitude,captured_at FROM mileage_tracking_points WHERE session_id=? AND accepted=1 ORDER BY sequence_no DESC LIMIT 1');$last->execute([$id]);$previous=$last->fetch(PDO::FETCH_ASSOC)?:null;
  $insert=$pdo->prepare('INSERT IGNORE INTO mileage_tracking_points (session_id,sequence_no,captured_at,latitude,longitude,accuracy_m,speed_mps,accepted,rejection_reason) VALUES (?,?,?,?,?,?,?,?,?)');
  foreach($points as $point){$seq=(int)($point['sequence']??-1);$lat=(float)($point['latitude']??999);$lon=(float)($point['longitude']??999);$accuracy=isset($point['accuracy'])?(float)$point['accuracy']:null;$speed=isset($point['speed'])?(float)$point['speed']:null;$captured=(string)($point['captured_at']??'');if($seq<0||abs($lat)>90||abs($lon)>180||strtotime($captured)===false)continue;$accepted=1;$reason=null;if($accuracy!==null&&$accuracy>100){$accepted=0;$reason='poor_accuracy';}elseif($speed!==null&&$speed>80){$accepted=0;$reason='implausible_speed';}elseif($previous){$seconds=max(0.001,(strtotime($captured)-strtotime((string)$previous['captured_at'])));$segment=mileage_haversine_miles((float)$previous['latitude'],(float)$previous['longitude'],$lat,$lon);if(($segment*1609.344)/$seconds>80){$accepted=0;$reason='implausible_jump';}}$insert->execute([$id,$seq,date('Y-m-d H:i:s.v',strtotime($captured)),$lat,$lon,$accuracy,$speed,$accepted,$reason]);if($accepted)$previous=['latitude'=>$lat,'longitude'=>$lon,'captured_at'=>$captured];}
  mt_json(['session'=>array_merge($session,mileage_recalculate_tracking_session($pdo,$id))]);
 }
 if($action==='stop'){$pdo->beginTransaction();$session=mt_session($pdo,$id,$userId,true);if($session['status']==='active')$pdo->prepare('UPDATE mileage_tracking_sessions SET status="draft_review",stopped_at=NOW(3) WHERE id=?')->execute([$id]);$summary=mileage_recalculate_tracking_session($pdo,$id);$pdo->commit();mt_json(['session'=>array_merge(mt_session($pdo,$id,$userId),$summary),'review_url'=>'/?page=financial/mileage-create&tracking_session_id='.$id]);}
 if($action==='discard'){$pdo->beginTransaction();mt_session($pdo,$id,$userId,true);$pdo->prepare('DELETE FROM mileage_tracking_points WHERE session_id=?')->execute([$id]);$pdo->prepare('UPDATE mileage_tracking_sessions SET status="discarded",stopped_at=COALESCE(stopped_at,NOW(3)) WHERE id=?')->execute([$id]);$pdo->commit();mt_json(['discarded'=>true]);}
 mt_json(['error'=>'Unknown tracking action.'],404);
}catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[MileageTracking]['.api_request_id().'] '.$e->getMessage());api_json_failure(503,'schema_out_of_date','Mileage tracking is unavailable until the latest database migration is applied.');
}catch(InvalidArgumentException $e){if($pdo->inTransaction())$pdo->rollBack();api_json_failure(422,'invalid_tracking_request',$e->getMessage());
}catch(RuntimeException $e){if($pdo->inTransaction())$pdo->rollBack();api_json_failure(409,'tracking_conflict',$e->getMessage());
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[MileageTracking]['.api_request_id().'] '.$e->getMessage());api_json_failure(500,'internal_error','Mileage tracking failed.');}
