<?php
require __DIR__.'/lib.php';
global $wpdb;
$owner=uid('owner');
as_user($owner);
$roles=array('admin','sales','production_manager','photographer','editor');
foreach($roles as $r){
  if(uid("m_$r")) continue;
  list($st,$d)=rest('POST','/team',array('name'=>"Member $r",'role'=>$r,'email'=>"m_$r@studio.test",'phone'=>'9876543210','create_login'=>1,'password'=>'Team#12345'));
  echo "create $r: $st ".(is_array($d)&&isset($d['message'])?$d['message']:'')."\n";
}
// fix logins to predictable usernames
foreach($roles as $r){ $u=get_user_by('email',"m_$r@studio.test"); if($u && $u->user_login!=="m_$r"){ $wpdb->update($wpdb->users,array('user_login'=>"m_$r"),array('ID'=>$u->ID)); clean_user_cache($u->ID);} }
$snap=array('counts'=>array(),'dashboard'=>array(),'perms'=>array());
foreach($wpdb->get_col("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$wpdb->prefix}pcrm_%'") as $t){ $snap['counts'][$t]=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t"); }
foreach(array_merge(array('owner'),array_map(function($r){return "m_$r";},$roles)) as $l){
  as_user(uid($l));
  list($st,$d)=rest('GET','/dashboard'); $snap['dashboard'][$l]=array($st,$d);
  list($st,$me)=rest('GET','/me'); $snap['perms'][$l]=isset($me['permissions'])?$me['permissions']:(isset($me['member']['permissions'])?$me['member']['permissions']:$me);
}
$snap['options']=array('db'=>get_option('pcrm_db_version'));
file_put_contents($argv[1] ?? __DIR__.'/before.json', wp_json_encode($snap));
echo "tables=".count($snap['counts'])." leads=".$snap['counts'][$wpdb->prefix.'pcrm_leads']."\n";
