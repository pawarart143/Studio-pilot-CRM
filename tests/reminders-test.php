<?php
/**
 * End-to-end checks for v0.6.0 reminders, against a real WordPress + MariaDB
 * install with wp_mail going through PHPMailer SMTP to a local sink.
 * Run: php reminders-test.php
 */
$_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
require '/home/user/site/wp-load.php';

use PCRM\Db;
use PCRM\Reminders;
use PCRM\Tenant;

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  PASS  $label\n";
	} else {
		++$fail;
		echo "  FAIL  $label\n";
	}
}
function mails() {
	return glob( '/home/user/mail/*.eml' );
}
function mails_to( $email ) {
	$tag = str_replace( '@', '_at_', $email );
	return array_values( array_filter( mails(), function ( $f ) use ( $tag ) { return false !== strpos( $f, $tag ); } ) );
}
function as_user( $uid ) {
	wp_set_current_user( $uid );
	Tenant::reset();
	Reminders::flush_cache();
}
function rest( $method, $path, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, '/pcrm/v1' . $path );
	if ( $body !== null ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
}
function n_rows( $where ) {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . Reminders::table() . ' WHERE ' . $where . ' ORDER BY id' );
}
function local_in( $sid, $mins ) {
	return Reminders::local( $sid, time() + $mins * 60 );
}
function mk_user( $login ) {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		return $u->ID;
	}
	return wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'pass12345', 'user_email' => $login . '@studio.test', 'display_name' => ucfirst( $login ) ) );
}
function mk_member( $sid, $uid, $name, $role, $email = null ) {
	global $wpdb;
	$wpdb->insert( PCRM\Installer::table( 'members' ), array( 'studio_id' => $sid, 'user_id' => $uid, 'name' => $name, 'email' => $email, 'role' => $role, 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	return (int) $wpdb->insert_id;
}

// ---------------------------------------------------------------- setup
global $wpdb;
foreach ( array( 'notifications', 'tasks', 'followups', 'leads', 'members', 'studios', 'activities', 'calls' ) as $t ) {
	$wpdb->query( 'DELETE FROM ' . PCRM\Installer::table( $t ) );
}
array_map( 'unlink', mails() );
delete_option( Reminders::LOCK_OPTION );
update_option( 'pcrm_reminders_started_at', time() - 3600, false );
foreach ( array( 'owner1', 'sales1', 'photo1', 'owner2' ) as $l ) {
	if ( $u = get_user_by( 'login', $l ) ) {
		delete_user_meta( $u->ID, Reminders::PREFS_META );
	}
}

$owner = mk_user( 'owner1' );
$sales = mk_user( 'sales1' );
$photo = mk_user( 'photo1' );
$owner2 = mk_user( 'owner2' );
$sid  = PCRM\Studios::create( $owner, array( 'name' => 'Lens & Light Studio', 'email' => 'hello@lenslight.test' ) );
$sid2 = PCRM\Studios::create( $owner2, array( 'name' => 'Other Studio', 'email' => 'other@other.test' ) );
foreach ( array( $owner => $sid, $sales => $sid, $photo => $sid, $owner2 => $sid2 ) as $u => $s ) {
	update_user_meta( $u, 'pcrm_current_studio', $s );
}
$m_owner = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . PCRM\Installer::table( 'members' ) . ' WHERE studio_id=%d AND user_id=%d', $sid, $owner ) );
$m_sales = mk_member( $sid, $sales, 'Riya Sales', 'sales' );
$m_photo = mk_member( $sid, $photo, 'Arjun Photographer', 'photographer' );
$m_nolog = mk_member( $sid, null, 'Freelancer No Login', 'photographer', 'free@lancer.test' );
echo "Studio $sid (owner m$m_owner, sales m$m_sales, photographer m$m_photo, no-login m$m_nolog), studio2 $sid2\n";
echo 'WordPress timezone: ' . wp_timezone_string() . ' | studio now (IST): ' . Reminders::local( $sid ) . ' | UTC now: ' . gmdate( 'Y-m-d H:i:s' ) . "\n";

as_user( $owner );
list( $st, $lead ) = rest( 'POST', '/leads', array( 'name' => 'Priya Sharma', 'phone' => '9876543210', 'event_type' => 'wedding', 'assigned_to' => $m_sales ) );
$lead_id = $lead['id'] ?? 0;
ok( 201 === $st && $lead_id, 'lead created' );

// ---------------------------------------------------------------- 1. task assigned + reminder timing
echo "\n1. Task: scheduled email, reminder at the right time, no duplicates\n";
list( $st, $t1 ) = rest( 'POST', '/tasks', array( 'title' => 'Send album proofs to Priya', 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 120 ), 'priority' => 'high', 'category' => 'album' ) );
ok( 201 === $st, 'task created via API' );
$rows = n_rows( "kind='task' AND item_id={$t1['id']}" );
ok( 1 === count( $rows ) && 'assigned' === $rows[0]->event && 'pending' === $rows[0]->email_status && (int) $rows[0]->member_id === $m_sales, '"assigned" notification queued for the assignee' );
ok( 0 === Reminders::generate( $sid ), 'no reminder yet (due in 2 h, lead time 30 min)' );
$r = Reminders::run();
ok( 1 === count( mails_to( 'sales1@studio.test' ) ), 'cron sent exactly 1 email (scheduled) to the assignee' );
$eml = file_get_contents( mails_to( 'sales1@studio.test' )[0] );
ok( false !== strpos( $eml, 'Content-Type: text/html' ) || false !== stripos( $eml, 'text/html' ), 'email is HTML' );
ok( false !== strpos( $eml, 'Lens & Light Studio' ) || false !== strpos( $eml, 'Lens &amp; Light Studio' ), 'email carries the studio name' );
ok( false !== strpos( quoted_printable_decode( $eml ), '/crm/tasks?task=' . $t1['id'] ), 'email links straight to the task' );
ok( (bool) preg_match( '/\d{2}-\d{2}-\d{4}, \d{2}:\d{2} (AM|PM)/', quoted_printable_decode( $eml ) ), 'date shown as DD-MM-YYYY, hh:mm AM/PM' );
ok( false !== strpos( $eml, 'Reply-To: Lens & Light Studio <hello@lenslight.test>' ) || false !== strpos( $eml, 'hello@lenslight.test' ), 'Reply-To is the studio email' );
Reminders::run( true );
Reminders::run( true );
ok( 1 === count( mails_to( 'sales1@studio.test' ) ), 'running the scheduler twice more sends nothing new' );

// Move it to 20 min from now: reschedule = new "scheduled" email; reminder in-window.
array_map( 'unlink', mails() );
list( $st ) = rest( 'PUT', '/tasks/' . $t1['id'], array( 'due_at' => local_in( $sid, 20 ) ) );
$rows = n_rows( "kind='task' AND item_id={$t1['id']} AND event='assigned'" );
ok( 2 === count( $rows ), 'rescheduling queues a new "scheduled" notification' );
Reminders::run( true );
$rem = n_rows( "kind='task' AND item_id={$t1['id']} AND event='reminder'" );
ok( 1 === count( $rem ), 'reminder created when inside the 30 min window' );
ok( 'none' === $rem[0]->email_status, 'reminder email skipped because the reschedule email just went out (no double email)' );
ok( 1 === count( mails_to( 'sales1@studio.test' ) ), 'one email total for the reschedule' );

// Timezone: an item 40 min out (IST) is NOT reminded; 25 min out is.
list( , $t40 ) = rest( 'POST', '/tasks', array( 'title' => 'TZ check 40', 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 40 ) ) );
list( , $t25 ) = rest( 'POST', '/tasks', array( 'title' => 'TZ check 25', 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 25 ) ) );
$wpdb->query( 'UPDATE ' . Reminders::table() . " SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR), email_status=IF(email_status='pending','sent',email_status) WHERE event='assigned'" ); // pretend "scheduled" went out long ago
Reminders::generate( $sid );
ok( 0 === count( n_rows( "kind='task' AND item_id={$t40['id']} AND event='reminder'" ) ), 'Asia/Kolkata time: due in 40 min → no reminder yet (WordPress itself is on UTC)' );
$r25 = n_rows( "kind='task' AND item_id={$t25['id']} AND event='reminder'" );
ok( 1 === count( $r25 ) && 'pending' === $r25[0]->email_status, 'due in 25 min → reminder with email queued' );

// Lead time change in Settings
list( $st ) = rest( 'PUT', '/settings', array( 'settings' => array( 'reminder_lead_minutes' => 60 ) ) );
ok( 200 === $st, 'owner sets lead time to 60 min in Settings' );
list( $st ) = rest( 'PUT', '/settings', array( 'settings' => array( 'reminder_lead_minutes' => 2 ) ) );
ok( 422 === $st, 'lead time below 5 min is rejected' );
Reminders::flush_cache();
Reminders::generate( $sid );
ok( 1 === count( n_rows( "kind='task' AND item_id={$t40['id']} AND event='reminder'" ) ), 'with 60 min lead time, the 40 min task now gets its reminder' );
array_map( 'unlink', mails() );
Reminders::run( true );
$m = mails_to( 'sales1@studio.test' );
ok( 2 === count( $m ), 'cron sent the 2 reminder emails' );
$subj = implode( ' ', array_map( function ( $f ) { return mb_decode_mimeheader( trim( preg_match( '/^Subject: (.*(?:\r?\n[ \t].*)*)/m', file_get_contents( $f ), $mm ) ? preg_replace( '/\r?\n[ \t]+/', ' ', $mm[1] ) : '' ) ); }, $m ) );
echo "    subjects: $subj\n"; ok( false !== strpos( $subj, "Reminder:" ), 'reminder subject starts with "Reminder:"' );
Reminders::run( true );
ok( 2 === count( mails_to( 'sales1@studio.test' ) ), 'no duplicates on the next run' );

// ---------------------------------------------------------------- 2. completed items
echo "\n2. Completed items are never emailed\n";
array_map( 'unlink', mails() );
list( , $t3 ) = rest( 'POST', '/tasks', array( 'title' => 'Will be done before cron', 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 300 ) ) );
rest( 'PUT', '/tasks/' . $t3['id'], array( 'status' => 'done' ) );
Reminders::run( true );
$row = n_rows( "kind='task' AND item_id={$t3['id']}" );
ok( 'skipped' === $row[0]->email_status && 0 === count( mails() ), 'task completed before the scheduler ran → email skipped' );
list( , $t4 ) = rest( 'POST', '/tasks', array( 'title' => 'Done task near due', 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 10 ), 'status' => 'done' ) );
ok( 0 === count( n_rows( "kind='task' AND item_id={$t4['id']}" ) ), 'task created as done → no notification at all' );
Reminders::generate( $sid );
ok( 0 === count( n_rows( "kind='task' AND item_id={$t4['id']}" ) ), 'done task never gets a reminder' );

// ---------------------------------------------------------------- 3. follow-ups
echo "\n3. Call follow-ups\n";
array_map( 'unlink', mails() );
list( $st, $f1 ) = rest( 'POST', '/followups', array( 'lead_id' => $lead_id, 'due_at' => local_in( $sid, 45 ), 'note' => 'Discuss candid + drone package', 'assigned_to' => $m_sales ) );
ok( 201 === $st, 'follow-up scheduled via API' );
ok( 1 === count( n_rows( "kind='followup' AND item_id={$f1['id']} AND event='assigned'" ) ), 'follow-up "scheduled" notification created' );
Reminders::run( true );
$m = mails_to( 'sales1@studio.test' );
ok( 1 === count( $m ), 'follow-up scheduled email sent' );
$body = quoted_printable_decode( file_get_contents( $m[0] ) );
ok( false !== strpos( $body, '/crm/leads/' . $lead_id . '?followup=' . $f1['id'] ) && false !== strpos( $body, 'Priya Sharma' ), 'follow-up email names the lead and links to it' );
// Log a call with a next call date → reschedules the pending follow-up → new scheduled email.
list( $st ) = rest( 'POST', '/leads/' . $lead_id . '/calls', array( 'status' => 'call_back', 'notes' => 'Asked to call later', 'next_call_at' => local_in( $sid, 240 ) ) );
ok( 201 === $st, 'call logged with next call date' );
$fups = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'followups' ) . " WHERE studio_id=%d AND lead_id=%d AND status='pending'", $sid, $lead_id ) );
$next = end( $fups );
ok( $next && count( n_rows( "kind='followup' AND item_id={$next->id} AND event='assigned'" ) ) >= 1, 'follow-up from "Log call → next call" gets a scheduled notification' );
// Follow-up becomes due now: in-app "due" alert.
$wpdb->update( Db::t( 'followups' ), array( 'due_at' => local_in( $sid, -1 ) ), array( 'id' => $next->id ) );
$wpdb->query( 'UPDATE ' . Reminders::table() . " SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR) WHERE kind='followup'" );
as_user( $sales );
list( $st, $feed ) = rest( 'GET', '/notifications' );
$due = array_values( array_filter( $feed['items'], function ( $n ) use ( $next ) { return 'followup' === $n['kind'] && (int) $n['item_id'] === (int) $next->id && 'due' === $n['event']; } ) );
ok( 200 === $st && 1 === count( $due ), 'GET /notifications creates the "due now" alert for the follow-up immediately (no cron wait)' );
ok( in_array( 'followup', array_column( $feed['due'], 'kind' ), true ), 'follow-up shows in the Due list' );
list( , $c1 ) = rest( 'POST', '/notifications/' . $due[0]['id'] . '/alert' );
list( , $c2 ) = rest( 'POST', '/notifications/' . $due[0]['id'] . '/alert' );
ok( true === $c1['claimed'] && false === $c2['claimed'], 'alert claimed once: a second tab/device does not ring again' );
list( , $feed2 ) = rest( 'GET', '/notifications' );
ok( count( $feed2['items'] ) === count( $feed['items'] ), 'polling again creates no duplicate alerts' );
// Complete the follow-up: removed from Due list, any pending email skipped
rest( 'POST', '/followups/' . $next->id . '/complete', array( 'note' => 'done' ) );
list( , $feed3 ) = rest( 'GET', '/notifications' );
ok( ! in_array( (int) $next->id, array_map( 'intval', array_column( array_filter( $feed3['due'], function ( $d ) { return 'followup' === $d['kind']; } ), 'id' ) ), true ), 'completed follow-up leaves the Due list' );
as_user( $owner );
Reminders::run( true );
$dr = n_rows( "kind='followup' AND item_id={$next->id} AND event='due'" );
ok( in_array( $dr[0]->email_status, array( 'skipped', 'none' ), true ), 'completed follow-up: its due email is not sent (' . $dr[0]->email_status . ')' );

// ---------------------------------------------------------------- 4. preferences
echo "\n4. Per-user preferences\n";
as_user( $sales );
list( $st, $p ) = rest( 'PUT', '/notifications/prefs', array( 'email_reminder' => false, 'sound' => false ) );
ok( 200 === $st && false === $p['email_reminder'] && false === $p['sound'] && true === $p['email_assigned'], 'prefs saved (reminder email off, sound off)' );
list( , $p2 ) = rest( 'GET', '/notifications/prefs' );
ok( false === $p2['sound'], 'sound choice is remembered on the server' );
as_user( $owner );
array_map( 'unlink', mails() );
list( , $t5 ) = rest( 'POST', '/tasks', array( 'title' => 'Prefs check', 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 30 ) ) );
$wpdb->query( 'UPDATE ' . Reminders::table() . " SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR) WHERE item_id={$t5['id']} AND kind='task'" );
Reminders::run( true );
$m = mails_to( 'sales1@studio.test' );
ok( 1 === count( $m ), 'scheduled email still sent (that pref is on)' );
$rr = n_rows( "kind='task' AND item_id={$t5['id']} AND event='reminder'" );
ok( 1 === count( $rr ) && 'skipped' === $rr[0]->email_status, 'reminder email skipped because the user turned reminder emails off' );
as_user( $sales );
rest( 'PUT', '/notifications/prefs', array( 'email_assigned' => false ) );
as_user( $owner );
array_map( 'unlink', mails() );
list( , $t6 ) = rest( 'POST', '/tasks', array( 'title' => 'Prefs check 2', 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 600 ) ) );
Reminders::run( true );
ok( 0 === count( mails_to( 'sales1@studio.test' ) ), 'scheduled emails off → nothing sent' );
ok( 1 === count( n_rows( "kind='task' AND item_id={$t6['id']}" ) ), '…but the in-app notification is still in the centre' );
as_user( $sales );
rest( 'PUT', '/notifications/prefs', array( 'email_assigned' => true, 'email_reminder' => true, 'sound' => true ) );

// Studio-wide switch
as_user( $owner );
rest( 'PUT', '/settings', array( 'settings' => array( 'reminder_emails' => false ) ) );
Reminders::flush_cache();
array_map( 'unlink', mails() );
list( , $t7 ) = rest( 'POST', '/tasks', array( 'title' => 'Studio emails off', 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 600 ) ) );
Reminders::run( true );
ok( 0 === count( mails() ), 'studio turned reminder emails off → nothing sent' );
rest( 'PUT', '/settings', array( 'settings' => array( 'reminder_emails' => true, 'reminder_lead_minutes' => 30 ) ) );
Reminders::flush_cache();

// ---------------------------------------------------------------- 5. roles and isolation
echo "\n5. Roles and studio isolation\n";
as_user( $owner );
list( , $tp ) = rest( 'POST', '/tasks', array( 'title' => 'Edit haldi photos', 'assigned_to' => $m_photo, 'due_at' => local_in( $sid, 15 ) ) );
ok( 1 === count( n_rows( "kind='task' AND item_id={$tp['id']} AND member_id=$m_photo" ) ), 'photographer gets reminders for their own task' );
$fid = Db::insert( 'followups', array( 'lead_id' => $lead_id, 'assigned_to' => $m_photo, 'due_at' => local_in( $sid, 15 ), 'status' => 'pending' ) );
ok( 0 === count( n_rows( "kind='followup' AND item_id=$fid" ) ), 'follow-up assigned to a photographer (no lead access) → no reminder' );
Reminders::generate( $sid );
ok( 0 === count( n_rows( "kind='followup' AND item_id=$fid" ) ), '…not from the scheduler either' );
list( , $tn ) = rest( 'POST', '/tasks', array( 'title' => 'No-login freelancer task', 'assigned_to' => $m_nolog, 'due_at' => local_in( $sid, 15 ) ) );
Reminders::generate( $sid );
ok( 0 === count( n_rows( "kind='task' AND item_id={$tn['id']}" ) ), 'team member without a login gets no reminders' );
as_user( $photo );
list( $st, $pf ) = rest( 'GET', '/notifications' );
$others = array_filter( $pf['items'], function ( $n ) use ( $tp ) { return (int) $n['item_id'] !== (int) $tp['id']; } );
ok( 200 === $st && ! $others, 'photographer only sees their own notifications' );
ok( ! in_array( 'followup', array_column( $pf['due'], 'kind' ), true ), 'photographer Due list has no follow-ups' );
$sales_n = n_rows( "member_id=$m_sales" )[0];
list( , $cl ) = rest( 'POST', '/notifications/' . $sales_n->id . '/alert' );
ok( false === $cl['claimed'], 'cannot claim someone else\'s alert' );
list( $st ) = rest( 'GET', '/notifications/status' );
ok( 403 === $st, 'photographer cannot open scheduler status (admin only)' );
as_user( $owner2 );
list( $st, $o2 ) = rest( 'GET', '/notifications' );
ok( 200 === $st && 0 === count( $o2['items'] ), 'other studio sees none of these notifications' );
list( $st ) = rest( 'POST', '/notifications/' . $sales_n->id . '/alert' );
list( , $o2b ) = rest( 'POST', '/notifications/read', array( 'ids' => array( $sales_n->id ) ) );
ok( null === $wpdb->get_var( 'SELECT read_at FROM ' . Reminders::table() . ' WHERE id=' . (int) $sales_n->id ) || $sales_n->read_at, 'other studio cannot mark it read' );
// Reassign: old assignee's pending email is skipped, new one gets theirs.
as_user( $owner );
array_map( 'unlink', mails() );
list( , $t8 ) = rest( 'POST', '/tasks', array( 'title' => 'Reassign me', 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 500 ) ) );
rest( 'PUT', '/tasks/' . $t8['id'], array( 'assigned_to' => $m_owner ) );
Reminders::run( true );
ok( 0 === count( mails_to( 'sales1@studio.test' ) ) && 1 === count( mails_to( 'owner1@studio.test' ) ), 'reassigned before cron → only the new assignee is emailed' );

// ---------------------------------------------------------------- 6. concurrency
echo "\n6. Scheduler running twice at the same time\n";
array_map( 'unlink', mails() );
$ids = array();
for ( $i = 0; $i < 12; $i++ ) {
	list( , $tx ) = rest( 'POST', '/tasks', array( 'title' => "Bulk $i", 'assigned_to' => $m_sales, 'due_at' => local_in( $sid, 700 + $i ) ) );
	$ids[] = $tx['id'];
}
$cmd = 'php -r ' . escapeshellarg( '$_SERVER["HTTP_HOST"]="127.0.0.1:8080"; require "/home/user/site/wp-load.php"; PCRM\\Reminders::run(true);' );
$procs = array();
for ( $i = 0; $i < 4; $i++ ) {
	$procs[] = proc_open( $cmd, array(), $pipes );
}
foreach ( $procs as $p ) {
	proc_close( $p );
}
ok( 12 === count( mails_to( 'sales1@studio.test' ) ), '4 scheduler processes at once → each of 12 emails sent exactly once (' . count( mails_to( 'sales1@studio.test' ) ) . ')' );
$sent = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Reminders::table() . " WHERE email_status='sent' AND kind='task' AND item_id IN (" . implode( ',', $ids ) . ')' );
ok( 12 === $sent, 'all 12 rows marked sent' );

// ---------------------------------------------------------------- 7. sample data and real cron
echo "\n7. Sample data and wp-cron\n";
as_user( $owner2 );
array_map( 'unlink', mails() );
list( $st ) = rest( 'POST', '/settings/sample-data' );
$n2 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Reminders::table() . " WHERE studio_id=%d AND event='assigned'", $sid2 ) );
ok( 200 === $st && 0 === $n2, 'loading sample data creates no "scheduled" emails' );
Reminders::$mute = false;
ok( (bool) wp_next_scheduled( Reminders::CRON_HOOK ), 'reminder job is scheduled in WP-Cron (every 5 minutes)' );
$schedules = wp_get_schedules();
ok( isset( $schedules['pcrm_five_minutes'] ) && 300 === $schedules['pcrm_five_minutes']['interval'], '5-minute schedule registered' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
