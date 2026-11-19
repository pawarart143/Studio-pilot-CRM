<?php
use PCRM\Tenant;
$GLOBALS['pass']=0;$GLOBALS['fail']=0;$GLOBALS['fails']=array();
function ok($c,$l){ if($c){$GLOBALS['pass']++;} else {$GLOBALS['fail']++;$GLOBALS['fails'][]=$l; echo "  FAIL  $l\n";} }
function as_user($uid){ wp_set_current_user($uid); if(method_exists('PCRM\\Tenant','reset')) Tenant::reset(); }
function rest($m,$p,$body=null,$params=array()){
  $r=new WP_REST_Request($m,'/pcrm/v1'.$p);
  if($body!==null){$r->set_header('content-type','application/json');$r->set_body(wp_json_encode($body));}
  foreach($params as $k=>$v)$r->set_param($k,$v);
  $res=rest_do_request($r); return array($res->get_status(), json_decode(wp_json_encode($res->get_data()),true));
}
function uid($login){ $u=get_user_by('login',$login); return $u?$u->ID:0; }
function done(){ echo "RESULT pass={$GLOBALS['pass']} fail={$GLOBALS['fail']}\n"; }
