<?php
require __DIR__.'/lib.php';
global $wpdb;
$b=json_decode(file_get_contents(__DIR__.'/before.json'),true);
echo "== Upgrade keeps data\n";
foreach($b['counts'] as $t=>$n){ $now=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t"); ok($now>=$n,"$t rows $n -> $now"); }
$tables=$wpdb->get_col("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$wpdb->prefix}pcrm_%'");
foreach(array('notifications','quotation_events','quote_packages','quote_package_items','plans','subscriptions','gcal_links','gcal_queue') as $t){ $hit=array_filter($tables,function($x)use($t){return $x===$GLOBALS['wpdb']->prefix.'pcrm_'.$t;}); ok((bool)$hit,"new table $t exists"); }
echo "tables now: ".implode(',',array_map(function($x){return str_replace('wp_pcrm_','',$x);},array_diff($tables,array_keys($b['counts']))))."\n";
echo "== Dashboard per role\n";
$roles=array('owner','m_admin','m_sales','m_production_manager','m_photographer','m_editor');
$money=array('revenue_month','pending_payments','revenue_chart','overdue_bookings','revenue_total','total_revenue');
foreach($roles as $l){
  as_user(uid($l));
  list($st,$d)=rest('GET','/dashboard');
  ok($st===200,"$l /dashboard 200 (got $st)");
  $old=$b['dashboard'][$l][1];
  $biz=in_array($l,array('owner','m_admin'));
  if($biz){ ok($d==$old,"$l /dashboard identical to v0.5.0"); if($d!=$old){ foreach($old as $k=>$v) if(!isset($d[$k])||$d[$k]!=$v) echo "    diff key $k\n"; } }
  else {
    foreach($money as $k) ok(!array_key_exists($k,$d),"$l dashboard has no $k");
    $same=true; foreach($d as $k=>$v){ if(!array_key_exists($k,$old)||$old[$k]!=$v){$same=false; echo "    $l changed key $k\n";} }
    ok($same,"$l non-money dashboard keys unchanged vs v0.5.0");
  }
  // widgets endpoint (customizable dashboard)
  list($st,$w)=rest('GET','/dashboard/widgets');
  if($st===404){ list($st,$w)=rest('GET','/dashboard/layout'); }
  ok($st===200,"$l dashboard widgets/layout 200 (got $st)");
  $ids=wp_json_encode($w);
  if(!$biz){ foreach(array('stat_revenue','stat_pending_payments','chart_revenue','stat_collected','stat_expenses','stat_profit','chart_cashflow','list_outstanding') as $wid) ok(false===strpos($ids,'"'.$wid.'"'),"$l cannot see widget $wid"); }
  else ok(false!==strpos($ids,'"stat_revenue"'),"$l sees revenue widget");
}
echo "== Business area closed to team roles (server side)\n";
$biz_routes=array(array('GET','/payments'),array('GET','/expenses'),array('GET','/reports'),array('GET','/team/performance'),array('GET','/billing'),array('GET','/gcal'),array('PUT','/settings'),array('POST','/quote-packages'),array('GET','/notifications/status'));
foreach(array('m_sales','m_production_manager','m_photographer','m_editor') as $l){
  as_user(uid($l));
  foreach($biz_routes as $r){ list($st,)=rest($r[0],$r[1],$r[0]==='GET'?null:array()); ok(in_array($st,array(401,403),true),"$l {$r[0]} {$r[1]} blocked (got $st)"); }
  list($st,)=rest('GET','/notifications'); ok($st===200,"$l can read own notifications");
  list($st,$me)=rest('GET','/me'); $p=wp_json_encode($me);
  foreach(array('payments.view','reports.view','team.view','settings.edit') as $k) ok(false===strpos($p,'"'.$k.'"'),"$l /me has no $k");
}
foreach(array('owner','m_admin') as $l){ as_user(uid($l)); foreach(array('/payments','/expenses','/reports','/billing','/gcal','/quote-packages','/notifications/status') as $r){ list($st,)=rest('GET',$r); ok($st===200,"$l GET $r 200 (got $st)"); } }
echo "== Plans are the single source of truth\n";
$P=\PCRM\Plans::all();
ok(isset($P['starter'],$P['professional'],$P['business']),"plans starter/professional/business exist");
ok((int)$P['starter']['members']===1 && (int)$P['starter']['leads_per_month']===50,"Starter = 1 login, 50 leads");
ok((int)$P['professional']['members']===2 && (int)$P['professional']['leads_per_month']===250,"Professional = 2 logins, 250 leads");
ok((int)$P['business']['members']===-1 && (int)$P['business']['leads_per_month']===-1,"Business unlimited");
$s1=$wpdb->get_row("select * from wp_pcrm_studios where id=1"); $s2=$wpdb->get_row("select * from wp_pcrm_studios where id=2");
ok($s1->plan==='business',"v0.5.0 enterprise studio -> business");
ok($s2->plan==='starter' && $s2->trial_ends_at,"v0.5.0 free studio -> starter 15-day trial");
// starter: second login refused
as_user(uid('owner2'));
list($st,$d)=rest('POST','/team',array('name'=>'Extra','role'=>'sales','email'=>'extra2@studio.test','create_login'=>1,'password'=>'Team#12345'));
ok($st===402,"Starter studio cannot add a 2nd login (got $st)");
// leads cap: 64 seeded leads; check() for leads
$c=\PCRM\Plans::check($s2,'leads'); echo "    starter leads check: ".(is_wp_error($c)?$c->get_error_message():'ok')."\n";
// tenant isolation
list($st,$l2)=rest('GET','/leads',null,array('per_page'=>200));
$ids=array_map(function($x){return (int)$x['id'];},isset($l2['items'])?$l2['items']:(isset($l2['data'])?$l2['data']:$l2));
$foreign=(int)$wpdb->get_var("select count(*) from wp_pcrm_leads where studio_id=1 and id in (".implode(',',$ids?:array(0)).")");
ok($st===200 && $ids && $foreign===0,"studio 2 sees only its own leads (".count($ids).")");
$lid1=(int)$wpdb->get_var("select id from wp_pcrm_leads where studio_id=1 limit 1");
list($st,)=rest('GET','/leads/'.$lid1); ok(in_array($st,array(403,404),true),"studio 2 cannot open studio 1 lead (got $st)");
echo "== Read-only after trial\n";
$wpdb->update('wp_pcrm_studios',array('trial_ends_at'=>'2026-01-01'),array('id'=>2)); \PCRM\Plans::flush(); as_user(uid('owner2'));
list($st,)=rest('GET','/leads'); ok($st===200,"expired trial: reading leads works");
list($st,$d)=rest('POST','/leads',array('name'=>'New after trial','phone'=>'9876501234','event_type'=>'wedding'));
ok($st===402,"expired trial: adding a lead answers 402 (got $st)");
list($st,)=rest('GET','/notifications'); ok($st===200,"expired trial: notifications still readable");
list($st,)=rest('POST','/notifications/read',array('all'=>true)); ok($st!==402,"expired trial: marking notifications read not blocked (got $st)");
$wpdb->update('wp_pcrm_studios',array('trial_ends_at'=>gmdate('Y-m-d',time()+15*86400)),array('id'=>2));
done();
foreach($GLOBALS['fails'] as $f) echo "FAILED: $f\n";
