<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Default catalogue for every new studio, plus optional realistic sample data
 * that an owner can load from Settings to try the CRM.
 */
class Seeder {

	public static function default_services() {
		return array(
			array( 'photography', 'Traditional Photography (per day)', 'One traditional photographer, all rituals and family portraits', 25000, 'day' ),
			array( 'photography', 'Candid Photography (per day)', 'Lead candid photographer for natural moments', 40000, 'day' ),
			array( 'cinematography', 'Cinematic Wedding Film (per day)', 'Cinematographer with gimbal, highlight film and teaser', 50000, 'day' ),
			array( 'cinematography', 'Traditional Videography (per day)', 'Full-length traditional video coverage', 22000, 'day' ),
			array( 'pre_wedding', 'Pre-Wedding Shoot – Local', 'Half-day shoot at 2 locations in the city, 40 edited photos', 35000, 'shoot' ),
			array( 'pre_wedding', 'Pre-Wedding Shoot – Destination', 'Full-day destination shoot with cinematic teaser', 85000, 'shoot' ),
			array( 'album', 'Premium Album (40 sheets)', '12x36 layflat album with designer cover', 18000, 'album' ),
			array( 'album', 'Parents Album (20 sheets)', '8x24 album for parents', 8000, 'album' ),
			array( 'drone', 'Drone Coverage (per day)', 'Licensed drone pilot with aerial photos and video', 15000, 'day' ),
			array( 'reels', 'Instagram Reels (set of 5)', 'Vertical reels edited for Instagram', 12000, 'set' ),
			array( 'additional', 'Same-Day Edit Video', 'Highlights played at the reception', 30000, 'event' ),
			array( 'additional', 'LED Wall Live Streaming', 'Live mixing on LED wall and YouTube stream', 20000, 'day' ),
		);
	}

	public static function defaults( $studio_id ) {
		foreach ( self::default_services() as $i => $s ) {
			Db::insert(
				'services',
				array(
					'category'    => $s[0],
					'name'        => $s[1],
					'description' => $s[2],
					'price'       => $s[3],
					'unit'        => $s[4],
					'is_active'   => 1,
					'sort'        => $i,
				),
				$studio_id
			);
		}
		foreach ( Whatsapp::default_templates() as $slug => $t ) {
			Db::insert(
				'wa_templates',
				array(
					'slug'      => $slug,
					'name'      => $t[0],
					'body'      => $t[1],
					'is_active' => 1,
				),
				$studio_id
			);
		}
		self::defaults_v3( $studio_id );
		self::default_quote_packages( $studio_id );
	}

	/**
	 * Starter quotation packages (v0.6.0), built only from the studio's own
	 * service catalogue so prices are the studio's, never invented. Skipped if
	 * the studio already has packages.
	 */
	public static function default_quote_packages( $studio_id ) {
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'quote_packages' ) . ' WHERE studio_id=%d', $studio_id ) ) ) {
			return;
		}
		$services = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'services' ) . ' WHERE studio_id=%d AND is_active=1 ORDER BY sort, id', $studio_id ) );
		if ( ! $services ) {
			return;
		}
		$find = function ( $needle ) use ( $services ) {
			foreach ( $services as $s ) {
				if ( false !== stripos( $s->name, $needle ) ) {
					return $s;
				}
			}
			return null;
		};
		$roles = array_keys( Config::quote_coverage_roles() );
		$specs = array(
			array(
				'Wedding – Essentials',
				'wedding',
				'Traditional photo and video coverage with an album. Good for 1 to 2 day weddings.',
				array( array( 'Traditional Photography', 2 ), array( 'Traditional Videography', 2 ), array( 'Premium Album', 1 ) ),
				array( 'Edited high-resolution photographs in an online gallery', 'Full-length traditional video of all rituals', 'Premium lay-flat photo album', 'All raw photographs and video footage on a drive' ),
				array( 'traditional_photographer' => 1, 'traditional_videographer' => 1 ),
			),
			array(
				'Wedding – Signature',
				'wedding',
				'Candid and cinematic team with traditional coverage, drone, reels and albums.',
				array( array( 'Traditional Photography', 2 ), array( 'Candid Photography', 2 ), array( 'Traditional Videography', 2 ), array( 'Cinematic Wedding Film', 2 ), array( 'Drone Coverage', 1 ), array( 'Instagram Reels', 1 ), array( 'Premium Album', 1 ), array( 'Parents Album', 1 ) ),
				array( 'Edited high-resolution photographs in an online gallery', 'Cinematic highlight film (4 to 6 minutes)', 'Teaser film (about 1 minute)', 'Full-length traditional video of all rituals', 'Instagram reels, ready to post', 'Premium lay-flat photo album', 'Photo-book for parents', 'All raw photographs and video footage on a drive' ),
				array( 'traditional_photographer' => 1, 'candid_photographer' => 1, 'traditional_videographer' => 1, 'cinematographer' => 1 ),
			),
			array(
				'Pre-Wedding Shoot',
				'pre_wedding',
				'Local pre-wedding shoot with reels.',
				array( array( 'Pre-Wedding Shoot – Local', 1 ), array( 'Instagram Reels', 1 ) ),
				array( 'Edited high-resolution photographs in an online gallery', 'Pre-wedding film with music', 'Instagram reels, ready to post' ),
				array( 'candid_photographer' => 1, 'cinematographer' => 1 ),
			),
		);
		foreach ( $specs as $i => $spec ) {
			$lines = array();
			foreach ( $spec[3] as $l ) {
				$svc = $find( $l[0] );
				if ( $svc ) {
					$lines[] = array( $svc, $l[1] );
				}
			}
			if ( ! $lines ) {
				continue;
			}
			$pid = Db::insert(
				'quote_packages',
				array(
					'name'         => $spec[0],
					'event_type'   => $spec[1],
					'description'  => $spec[2],
					'deliverables' => wp_json_encode( $spec[4] ),
					'coverage'     => wp_json_encode( array_intersect_key( $spec[5], array_flip( $roles ) ) ),
					'is_active'    => 1,
					'sort'         => $i,
				),
				$studio_id
			);
			if ( is_wp_error( $pid ) ) {
				continue;
			}
			foreach ( $lines as $j => $l ) {
				Db::insert(
					'quote_package_items',
					array(
						'package_id'  => $pid,
						'service_id'  => (int) $l[0]->id,
						'category'    => $l[0]->category,
						'name'        => $l[0]->name,
						'description' => $l[0]->description,
						'qty'         => $l[1],
						'rate'        => $l[0]->price,
						'unit'        => $l[0]->unit,
						'sort'        => $j,
					),
					$studio_id
				);
			}
		}
	}

	public static function default_deliverable_templates() {
		return array(
			'wedding'     => array(
				'Wedding Package',
				'wedding',
				array(
					array( 'Edited Wedding Photos', 'Colour-corrected and retouched photos from all functions', 30 ),
					array( 'Wedding Teaser', '60–90 second teaser film', 15 ),
					array( 'Cinematic Wedding Film', 'Highlight film with music', 45 ),
					array( 'Traditional Wedding Video', 'Full-length video of all rituals', 45 ),
					array( 'Wedding Album', 'Designed after the client selects photos', 60 ),
				),
			),
			'pre_wedding' => array(
				'Pre-Wedding',
				'pre_wedding',
				array(
					array( 'Edited Pre-Wedding Photos', 'Retouched photos from the shoot', 15 ),
					array( 'Pre-Wedding Cinematic Film', 'Cinematic film with music', 21 ),
					array( 'Social Media Reels', 'Vertical reels for Instagram', 10 ),
				),
			),
		);
	}

	/**
	 * Defaults added in v0.4.0: deliverable templates and portal WhatsApp
	 * templates. Safe to run more than once; it only adds what is missing.
	 */
	public static function defaults_v3( $studio_id ) {
		global $wpdb;
		foreach ( self::default_deliverable_templates() as $slug => $t ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'deliverable_templates' ) . ' WHERE studio_id=%d AND slug=%s', $studio_id, $slug ) );
			if ( $exists ) {
				continue;
			}
			$tid = Db::insert( 'deliverable_templates', array( 'slug' => $slug, 'name' => $t[0], 'event_type' => $t[1], 'is_active' => 1 ), $studio_id );
			if ( is_wp_error( $tid ) ) {
				continue;
			}
			foreach ( $t[2] as $i => $item ) {
				Db::insert( 'deliverable_template_items', array( 'template_id' => $tid, 'name' => $item[0], 'description' => $item[1], 'days_after_event' => $item[2], 'sort' => $i ), $studio_id );
			}
		}
		foreach ( Whatsapp::default_templates() as $slug => $t ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'wa_templates' ) . ' WHERE studio_id=%d AND slug=%s', $studio_id, $slug ) );
			if ( ! $exists ) {
				Db::insert( 'wa_templates', array( 'slug' => $slug, 'name' => $t[0], 'body' => $t[1], 'is_active' => 1 ), $studio_id );
			}
		}
	}

	private static function pick( array $a ) {
		return $a[ mt_rand( 0, count( $a ) - 1 ) ];
	}

	private static function weighted( array $w ) {
		$r = mt_rand( 1, array_sum( $w ) );
		foreach ( $w as $k => $n ) {
			$r -= $n;
			if ( $r <= 0 ) {
				return $k;
			}
		}
		return key( $w );
	}

	private static function phone() {
		return '+91' . self::pick( array( '98', '97', '99', '96', '93', '88', '70', '81' ) ) . str_pad( (string) mt_rand( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
	}

	public static function sample( $sid ) {
		global $wpdb;
		$has = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'leads' ) . ' WHERE studio_id=%d', $sid ) );
		if ( $has > 0 ) {
			return new \WP_Error( 'has_data', 'Sample data can only be loaded into an empty studio. Reset the studio data first.', array( 'status' => 409 ) );
		}
		mt_srand( 20261117 );
		$now_ts = Db::ts();
		$d      = function ( $days, $fmt = 'Y-m-d' ) use ( $now_ts ) {
			return date( $fmt, $now_ts + (int) round( $days * DAY_IN_SECONDS ) );
		};

		// Team.
		$team_def = array(
			array( 'Rohit Sharma', 'sales', '#2563eb' ),
			array( 'Priya Nair', 'sales', '#db2777' ),
			array( 'Amit Joshi', 'production_manager', '#d97706' ),
			array( 'Arjun Mehta', 'photographer', '#059669' ),
			array( 'Karan Singh', 'photographer', '#0891b2' ),
			array( 'Vikram Rao', 'cinematographer', '#7c3aed' ),
			array( 'Sahil Khan', 'cinematographer', '#dc2626' ),
			array( 'Sneha Patil', 'editor', '#65a30d' ),
			array( 'Neha Kulkarni', 'album_designer', '#9333ea' ),
		);
		$team = array();
		foreach ( $team_def as $t ) {
			$id                 = Db::insert( 'members', array( 'name' => $t[0], 'phone' => self::phone(), 'email' => strtolower( str_replace( ' ', '.', $t[0] ) ) . '@example.com', 'role' => $t[1], 'status' => 'active', 'color' => $t[2] ), $sid );
			$team[ $t[1] ][]    = $id;
		}
		$sales   = $team['sales'];
		$photogs = $team['photographer'];
		$cinemas = $team['cinematographer'];

		$services = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'services' ) . ' WHERE studio_id=%d', $sid ), OBJECT_K );
		$svc_by   = array();
		foreach ( $services as $s ) {
			$svc_by[ $s->category ][] = $s;
		}

		$male   = array( 'Aarav', 'Vivaan', 'Aditya', 'Ishaan', 'Rahul', 'Siddharth', 'Kunal', 'Rohan', 'Nikhil', 'Varun', 'Harsh', 'Manish', 'Gaurav', 'Akash', 'Farhan', 'Deepak', 'Kabir', 'Arjun', 'Dev' );
		$female = array( 'Ananya', 'Diya', 'Isha', 'Kavya', 'Meera', 'Pooja', 'Riya', 'Sakshi', 'Tanvi', 'Shreya', 'Nisha', 'Simran', 'Jasleen', 'Ayesha', 'Tara', 'Aditi' );
		$first  = array_merge( $male, $female );
		$last  = array( 'Sharma', 'Verma', 'Gupta', 'Agarwal', 'Patel', 'Shah', 'Iyer', 'Reddy', 'Nair', 'Kapoor', 'Malhotra', 'Chopra', 'Jain', 'Desai', 'Kulkarni', 'Bhatia', 'Sethi', 'Menon', 'Rathore', 'Chauhan' );
		$cities = array(
			'Mumbai'    => array( 'Taj Lands End', 'ITC Maratha', 'Sahara Star', 'JW Marriott Juhu' ),
			'Pune'      => array( 'Conrad Pune', 'JW Marriott Pune', 'The Westin Koregaon Park', 'Hyatt Regency' ),
			'Delhi'     => array( 'The Leela Palace', 'Taj Palace', 'ITC Maurya', 'Hyatt Regency Delhi' ),
			'Jaipur'    => array( 'Rambagh Palace', 'Fairmont Jaipur', 'ITC Rajputana', 'Jai Mahal Palace' ),
			'Udaipur'   => array( 'The Oberoi Udaivilas', 'Taj Lake Palace', 'The Leela Udaipur', 'Radisson Blu Udaipur' ),
			'Goa'       => array( 'Taj Exotica', 'W Goa', 'Grand Hyatt Goa', 'The Leela Goa' ),
			'Bengaluru' => array( 'The Leela Bengaluru', 'Taj West End', 'ITC Gardenia' ),
			'Ahmedabad' => array( 'Hyatt Regency Ahmedabad', 'ITC Narmada', 'The House of MG' ),
			'Indore'    => array( 'Sayaji Hotel', 'Radisson Blu Indore', 'Marriott Indore' ),
			'Lucknow'   => array( 'Taj Mahal Lucknow', 'Hyatt Regency Lucknow', 'Renaissance Lucknow' ),
		);
		$sources = array( 'instagram' => 30, 'referral' => 18, 'google' => 14, 'facebook' => 12, 'website' => 10, 'whatsapp' => 9, 'walkin' => 4, 'other' => 3 );
		$etypes  = array( 'wedding' => 45, 'pre_wedding' => 15, 'engagement' => 10, 'birthday' => 8, 'reception' => 5, 'baby_shoot' => 5, 'corporate' => 5, 'commercial' => 4, 'fashion' => 3 );
		$lost_reasons = array( 'Budget too high', 'Booked another photographer', 'Date not available', 'Event cancelled', 'No response after follow-ups', 'Went with a family friend' );
		$budgets = array(
			'wedding'     => array( 150000, 650000 ),
			'pre_wedding' => array( 30000, 120000 ),
			'engagement'  => array( 40000, 150000 ),
			'birthday'    => array( 15000, 60000 ),
			'reception'   => array( 50000, 200000 ),
			'baby_shoot'  => array( 10000, 35000 ),
			'corporate'   => array( 25000, 150000 ),
			'commercial'  => array( 40000, 250000 ),
			'fashion'     => array( 30000, 150000 ),
		);
		$call_notes = array(
			'connected'      => array( 'Discussed requirements, wants candid + cinematic.', 'Spoke to the bride, family deciding by weekend.', 'Shared portfolio link, client liked the films.' ),
			'interested'     => array( 'Very interested, asked for detailed quotation.', 'Wants to meet at studio on Saturday.', 'Interested in destination pre-wedding as well.' ),
			'not_answered'   => array( 'No answer, will try in the evening.', 'Phone ringing, no response.' ),
			'call_back'      => array( 'Asked to call back after 7 PM.', 'Busy in office, call back tomorrow.' ),
			'switched_off'   => array( 'Number switched off.' ),
			'not_interested' => array( 'Already finalised another studio.', 'Budget does not match.' ),
		);

		$plan = array(
			'new' => 7, 'first_call_pending' => 5, 'contacted' => 6, 'interested' => 6, 'followup_required' => 6,
			'quotation_sent' => 6, 'negotiation' => 4, 'booked' => 16, 'lost' => 8,
		);
		$stage_list = array();
		foreach ( $plan as $st => $n ) {
			for ( $i = 0; $i < $n; $i++ ) {
				$stage_list[] = $st;
			}
		}
		shuffle( $stage_list );

		$created = array( 'leads' => 0, 'quotations' => 0, 'bookings' => 0, 'payments' => 0 );
		$busy    = array(); // member_id => [dates].
		$tnum    = array( 'qt' => 0, 'bk' => 0, 'rc' => 0 );
		$year    = $d( 0, 'Y' );
		$cats    = Config::event_types();

		foreach ( $stage_list as $idx => $stage ) {
			$fn    = self::pick( $first );
			$ln    = self::pick( $last );
			$etype = self::weighted( $etypes );
			$city  = array_rand( $cities );
			$venue = self::pick( $cities[ $city ] );
			$src   = self::weighted( $sources );
			$name  = $fn . ' ' . $ln;
			if ( in_array( $etype, array( 'wedding', 'engagement', 'pre_wedding', 'reception' ), true ) ) {
				$fn   = self::pick( $female );
				$name = $fn . ' & ' . self::pick( $male ) . ' ' . $ln;
			} elseif ( in_array( $etype, array( 'corporate', 'commercial', 'fashion' ), true ) ) {
				$name = self::pick( array( 'Aurum Jewellers', 'Zest Foods Pvt Ltd', 'Kalaa Couture', 'Nimbus Tech', 'Bloom Cafe', 'Saffron Ethnic Wear', 'Orbit Realty', 'Veda Ayurveda' ) ) . ( mt_rand( 0, 1 ) ? '' : ' – ' . $fn . ' ' . $ln );
			}
			// Booked/lost leads skew older; early stages are recent.
			$age = in_array( $stage, array( 'booked', 'lost' ), true ) ? mt_rand( 20, 330 ) : ( in_array( $stage, array( 'new', 'first_call_pending' ), true ) ? mt_rand( 0, 6 ) : mt_rand( 4, 75 ) );
			$created_at = $d( -$age - mt_rand( 0, 9 ) / 24, 'Y-m-d H:i:s' );
			$created_at = substr( $created_at, 0, 14 ) . str_pad( (string) mt_rand( 0, 59 ), 2, '0', STR_PAD_LEFT ) . ':00';
			if ( 'booked' === $stage ) {
				$event_off = mt_rand( -150, 210 ) - (int) ( $age / 4 );
				$event_off = max( $event_off, -$age + 25 );
			} else {
				$event_off = mt_rand( 15, 260 ) - ( 'lost' === $stage ? $age : 0 );
			}
			$event_date = $d( $event_off );
			$multi      = 'wedding' === $etype ? mt_rand( 1, 3 ) : 0;
			$end_date   = $multi ? $d( $event_off + $multi ) : null;
			$b          = $budgets[ $etype ];
			$budget     = round( mt_rand( $b[0], $b[1] ) / 5000 ) * 5000;
			$priority   = self::weighted( array( 'medium' => 50, 'high' => 25, 'low' => 15, 'hot' => 10 ) );
			$assigned   = self::pick( $sales );
			$phone      = self::phone();

			$call_status = 'not_called';
			if ( 'first_call_pending' === $stage ) {
				$call_status = self::pick( array( 'not_answered', 'switched_off', 'attempted' ) );
			} elseif ( in_array( $stage, array( 'contacted' ), true ) ) {
				$call_status = self::pick( array( 'connected', 'call_back' ) );
			} elseif ( in_array( $stage, array( 'interested', 'quotation_sent', 'negotiation', 'booked' ), true ) ) {
				$call_status = 'interested';
			} elseif ( 'followup_required' === $stage ) {
				$call_status = 'followup';
			} elseif ( 'lost' === $stage ) {
				$call_status = 'not_interested';
			}

			$lead = array(
				'name'           => $name,
				'phone'          => $phone,
				'whatsapp'       => $phone,
				'email'          => mt_rand( 0, 3 ) ? strtolower( $fn . '.' . $ln . mt_rand( 1, 99 ) ) . '@gmail.com' : null,
				'event_type'     => $etype,
				'event_date'     => $event_date,
				'event_end_date' => $end_date,
				'event_duration' => $multi ? ( $multi + 1 ) . ' days' : self::pick( array( 'Full day', 'Half day', '6 hours', '4 hours' ) ),
				'venue'          => $venue,
				'city'           => $city,
				'source'         => $src,
				'assigned_to'    => $assigned,
				'budget'         => $budget,
				'stage'          => $stage,
				'priority'       => $priority,
				'call_status'    => $call_status,
				'lost_reason'    => 'lost' === $stage ? self::pick( $lost_reasons ) : null,
				'notes'          => self::pick( array( 'Looking for candid + cinematic coverage.', 'Wants a destination pre-wedding as well.', 'Found us through our Instagram reels.', 'Referred by a past client.', 'Prefers natural, documentary style.', '', 'Asked about album options and delivery timeline.' ) ),
				'position'       => $idx,
				'created_at'     => $created_at,
			);
			$lid = Db::insert( 'leads', $lead, $sid );
			$created['leads']++;
			$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::t( 'activities' ) . ' (studio_id, lead_id, type, message, created_at) VALUES (%d,%d,%s,%s,%s)', $sid, $lid, 'created', 'Lead created from ' . Config::sources()[ $src ], $created_at ) );

			// Calls.
			$ncalls = array( 'new' => 0, 'first_call_pending' => 1, 'contacted' => 1, 'interested' => 2, 'followup_required' => 2, 'quotation_sent' => 2, 'negotiation' => 3, 'booked' => 3, 'lost' => 2 )[ $stage ];
			$last_call = null;
			$span      = max( 1, min( $age, 40 ) );
			for ( $c = 0; $c < $ncalls; $c++ ) {
				$at        = date( 'Y-m-d H:i:s', strtotime( $created_at ) + (int) ( ( $c + 1 ) * $span / ( $ncalls + 1 ) * DAY_IN_SECONDS ) + mt_rand( 1, 5 ) * HOUR_IN_SECONDS );
				$at        = min( $at, $d( 0, 'Y-m-d H:i:s' ) );
				$cs        = $c === $ncalls - 1 ? ( 'not_called' === $call_status ? 'attempted' : $call_status ) : self::pick( array( 'connected', 'not_answered', 'call_back' ) );
				$cs        = 'followup' === $cs ? 'connected' : $cs;
				$notes     = isset( $call_notes[ $cs ] ) ? self::pick( $call_notes[ $cs ] ) : 'Call attempted.';
				Db::insert( 'calls', array( 'lead_id' => $lid, 'member_id' => $assigned, 'status' => $cs, 'notes' => $notes, 'called_at' => $at, 'created_at' => $at ), $sid );
				$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::t( 'activities' ) . ' (studio_id, lead_id, type, message, created_at) VALUES (%d,%d,%s,%s,%s)', $sid, $lid, 'call', 'Call logged: ' . Config::call_statuses()[ $cs ] . ' — ' . $notes, $at ) );
				$last_call = $at;
			}
			$upd = array( 'last_call_at' => $last_call );

			// Follow-ups for open leads: some today, some overdue, rest upcoming.
			if ( ! in_array( $stage, array( 'booked', 'lost' ), true ) ) {
				$r   = mt_rand( 1, 10 );
				$due = $r <= 3 ? $d( 0, 'Y-m-d' ) . ' ' . self::pick( array( '10:30', '11:00', '12:30', '15:00', '16:30', '18:00' ) ) . ':00' : ( $r <= 5 ? $d( -mt_rand( 1, 5 ), 'Y-m-d' ) . ' 11:00:00' : $d( mt_rand( 1, 10 ), 'Y-m-d' ) . ' 12:00:00' );
				if ( 'new' === $stage && $r > 6 ) {
					$due = null;
				}
				if ( $due ) {
					Db::insert( 'followups', array( 'lead_id' => $lid, 'assigned_to' => $assigned, 'due_at' => $due, 'note' => self::pick( array( 'Call to discuss package', 'Check if family has decided', 'Share sample album', 'Confirm date availability', 'Follow up on quotation' ) ), 'status' => 'pending', 'created_at' => $created_at ), $sid );
					$upd['next_followup_at'] = $due;
				}
			}
			Db::update( 'leads', $lid, $upd, $sid );

			// Quotations.
			if ( in_array( $stage, array( 'quotation_sent', 'negotiation', 'booked', 'lost' ), true ) && ( 'lost' !== $stage || mt_rand( 0, 1 ) ) ) {
				$items = array();
				if ( in_array( $etype, array( 'wedding', 'reception', 'engagement' ), true ) ) {
					$days    = max( 1, $multi + 1 );
					$items[] = array( $svc_by['photography'][0], $days );
					$items[] = array( $svc_by['photography'][1], $days );
					$items[] = array( $svc_by['cinematography'][0], max( 1, $days - 1 ) );
					if ( mt_rand( 0, 1 ) ) {
						$items[] = array( $svc_by['drone'][0], 1 );
					}
					$items[] = array( $svc_by['album'][0], 1 );
					if ( mt_rand( 0, 1 ) ) {
						$items[] = array( $svc_by['album'][1], 2 );
					}
					if ( mt_rand( 0, 2 ) === 0 ) {
						$items[] = array( $svc_by['pre_wedding'][ mt_rand( 0, 1 ) ], 1 );
					}
				} elseif ( 'pre_wedding' === $etype ) {
					$items[] = array( $svc_by['pre_wedding'][ mt_rand( 0, 1 ) ], 1 );
					$items[] = array( $svc_by['reels'][0], 1 );
				} else {
					$items[] = array( $svc_by['photography'][ mt_rand( 0, 1 ) ], 1 );
					if ( mt_rand( 0, 1 ) ) {
						$items[] = array( $svc_by['cinematography'][1], 1 );
					}
					$items[] = array( $svc_by['reels'][0], 1 );
				}
				$lines = array();
				foreach ( $items as $i => $it ) {
					$lines[] = array( 'service_id' => (int) $it[0]->id, 'category' => $it[0]->category, 'name' => $it[0]->name, 'description' => $it[0]->description, 'qty' => $it[1], 'rate' => (float) $it[0]->price, 'amount' => $it[1] * $it[0]->price, 'sort' => $i );
				}
				$disc   = mt_rand( 0, 2 ) ? round( mt_rand( 5, 25 ) / 5 ) * 5000 : 0;
				$tot    = Rest\Quotations::totals( $lines, 'flat', $disc, 18 );
				$q_at   = date( 'Y-m-d H:i:s', strtotime( $created_at ) + (int) ( min( $age, 30 ) * 0.6 * DAY_IN_SECONDS ) );
				$qstat  = array( 'quotation_sent' => self::pick( array( 'sent', 'viewed' ) ), 'negotiation' => self::pick( array( 'viewed', 'accepted' ) ), 'booked' => 'accepted', 'lost' => self::pick( array( 'rejected', 'expired' ) ) )[ $stage ];
				$tnum['qt']++;
				$qid    = Db::insert(
					'quotations',
					array_merge(
						array(
							'number'         => sprintf( 'QT-%s-%04d', $year, $tnum['qt'] ),
							'lead_id'        => $lid,
							'title'          => ( $cats[ $etype ] ) . ' Coverage – ' . $name,
							'customer_name'  => $name,
							'customer_phone' => $phone,
							'customer_email' => $lead['email'],
							'event_type'     => $etype,
							'event_date'     => $event_date,
							'venue'          => $venue . ', ' . $city,
							'status'         => $qstat,
							'valid_until'    => date( 'Y-m-d', strtotime( $q_at ) + 15 * DAY_IN_SECONDS ),
							'discount_type'  => 'flat',
							'discount_value' => $disc,
							'gst_rate'       => 18,
							'terms'          => Config::default_settings()['quote_terms'],
							'public_token'   => wp_generate_password( 32, false, false ),
							'sent_at'        => $q_at,
							'viewed_at'      => in_array( $qstat, array( 'viewed', 'accepted', 'rejected' ), true ) ? date( 'Y-m-d H:i:s', strtotime( $q_at ) + 7200 ) : null,
							'created_at'     => $q_at,
						),
						$tot
					),
					$sid
				);
				foreach ( $lines as $ln_ ) {
					$ln_['quotation_id'] = $qid;
					Db::insert( 'quotation_items', $ln_, $sid );
				}
				$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::t( 'activities' ) . ' (studio_id, lead_id, type, message, created_at) VALUES (%d,%d,%s,%s,%s)', $sid, $lid, 'quotation', 'Quotation ' . sprintf( 'QT-%s-%04d', $year, $tnum['qt'] ) . ' sent for ₹' . Format::inr( $tot['total'] ), $q_at ) );
				$created['quotations']++;

				// Bookings.
				if ( 'booked' === $stage ) {
					$cid = Db::insert( 'clients', array( 'name' => $name, 'phone' => $phone, 'whatsapp' => $phone, 'email' => $lead['email'], 'city' => $city, 'created_at' => $q_at ), $sid );
					Db::update( 'leads', $lid, array( 'client_id' => $cid ), $sid );
					Db::update( 'quotations', $qid, array( 'client_id' => $cid ), $sid );
					$conf  = date( 'Y-m-d', strtotime( $q_at ) + mt_rand( 2, 9 ) * DAY_IN_SECONDS );
					$conf  = min( $conf, $d( 0 ) );
					$total = $tot['total'];
					$evoff = $event_off;
					$past  = $evoff + max( 0, $multi ) < 0;
					$since = -( $evoff + max( 0, $multi ) );
					$status = 'confirmed';
					if ( $past ) {
						$status = $since > 75 ? 'completed' : ( $since > 50 ? 'delivered' : ( $since > 25 ? 'album_design' : ( $since > 3 ? 'editing' : 'shoot_done' ) ) );
					}
					$tnum['bk']++;
					$bid = Db::insert(
						'bookings',
						array(
							'number'               => sprintf( 'BK-%s-%04d', $year, $tnum['bk'] ),
							'client_id'            => $cid,
							'lead_id'              => $lid,
							'quotation_id'         => $qid,
							'title'                => $name . ' – ' . $cats[ $etype ],
							'event_type'           => $etype,
							'package_details'      => implode( "\n", array_map( function ( $l ) {
								return $l['name'] . ( 1 != $l['qty'] ? ' × ' . $l['qty'] : '' ); // phpcs:ignore
							}, $lines ) ),
							'total_amount'         => $total,
							'status'               => $status,
							'confirmed_on'         => $conf,
							'delivery_due'         => date( 'Y-m-d', strtotime( $event_date ) + ( max( 0, $multi ) + 45 ) * DAY_IN_SECONDS ),
							'special_instructions' => self::pick( array( 'Bride wants extra focus on family portraits.', 'Groom entry on horse – need drone at 7 PM.', 'Mehndi is outdoors, plan for sunset light.', 'Share teaser within 48 hours for Instagram.', '' ) ),
							'created_at'           => $conf . ' 12:00:00',
						),
						$sid
					);
					$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::t( 'activities' ) . ' (studio_id, lead_id, client_id, booking_id, type, message, created_at) VALUES (%d,%d,%d,%d,%s,%s,%s)', $sid, $lid, $cid, $bid, 'booking', 'Booking ' . sprintf( 'BK-%s-%04d', $year, $tnum['bk'] ) . ' confirmed for ₹' . Format::inr( $total ), $conf . ' 12:00:00' ) );
					$created['bookings']++;

					// Events.
					$names = 'wedding' === $etype ? array_slice( array( 'Haldi & Mehndi', 'Sangeet', 'Wedding Ceremony', 'Reception' ), 3 - $multi ) : array( $cats[ $etype ] );
					if ( 'wedding' === $etype && 1 === $multi ) {
						$names = array( 'Sangeet', 'Wedding Ceremony' );
					}
					foreach ( $names as $ei => $ename ) {
						$edate = date( 'Y-m-d', strtotime( $event_date ) + $ei * DAY_IN_SECONDS );
						$times = 'Sangeet' === $ename || 'Reception' === $ename ? array( '18:00:00', '23:30:00' ) : ( 'Haldi & Mehndi' === $ename ? array( '10:00:00', '17:00:00' ) : array( '09:00:00', '21:00:00' ) );
						$eid   = Db::insert( 'booking_events', array( 'booking_id' => $bid, 'name' => $ename, 'event_type' => 'wedding' === $etype && 'Wedding Ceremony' !== $ename ? ( 'Reception' === $ename ? 'reception' : 'haldi_mehndi' ) : $etype, 'event_date' => $edate, 'start_time' => $times[0], 'end_time' => $times[1], 'venue' => $venue, 'city' => $city ), $sid );
						if ( 'Sangeet' === $ename ) {
							Db::update( 'booking_events', $eid, array( 'event_type' => 'other' ), $sid );
						}
						// Team without double booking.
						$want = array( array( $photogs, 'photographer' ), array( $cinemas, 'cinematographer' ) );
						foreach ( $want as $w ) {
							foreach ( $w[0] as $mid ) {
								if ( empty( $busy[ $mid ][ $edate ] ) ) {
									Db::insert( 'event_assignments', array( 'booking_event_id' => $eid, 'member_id' => $mid, 'role' => $w[1] ), $sid );
									$busy[ $mid ][ $edate ] = true;
									break;
								}
							}
						}
					}

					// Installments and payments.
					$adv   = round( $total * 0.3 / 1000 ) * 1000;
					$sec   = round( $total * 0.4 / 1000 ) * 1000;
					$fin   = $total - $adv - $sec;
					$inst  = array(
						array( 'Advance', $adv, $conf ),
						array( 'Second Installment', $sec, date( 'Y-m-d', strtotime( $event_date ) - 7 * DAY_IN_SECONDS ) ),
						array( 'Final Payment', $fin, date( 'Y-m-d', strtotime( $event_date ) + ( max( 0, $multi ) + 30 ) * DAY_IN_SECONDS ) ),
					);
					foreach ( $inst as $ii => $in ) {
						$iid = Db::insert( 'installments', array( 'booking_id' => $bid, 'label' => $in[0], 'amount' => $in[1], 'due_date' => $in[2], 'sort' => $ii ), $sid );
						$pay = false;
						if ( 0 === $ii ) {
							$pay = true;
						} elseif ( $in[2] <= $d( 0 ) ) {
							$pay = mt_rand( 1, 10 ) <= 7; // Some overdue.
						}
						if ( $pay ) {
							$amt = $in[1];
							if ( 2 === $ii && mt_rand( 0, 3 ) === 0 ) {
								$amt = round( $in[1] / 2 );
							}
							$pdate = min( $d( 0 ), date( 'Y-m-d', strtotime( $in[2] ) + mt_rand( 0, 4 ) * DAY_IN_SECONDS ) );
							$m     = self::weighted( array( 'upi' => 50, 'bank_transfer' => 30, 'cash' => 12, 'cheque' => 8 ) );
							$tnum['rc']++;
							Db::insert( 'payments', array( 'booking_id' => $bid, 'installment_id' => $iid, 'receipt_no' => sprintf( 'RC-%s-%04d', $year, $tnum['rc'] ), 'amount' => $amt, 'paid_on' => $pdate, 'method' => $m, 'transaction_ref' => 'cash' === $m ? null : strtoupper( substr( md5( $bid . $ii ), 0, 12 ) ), 'created_at' => $pdate . ' 13:00:00' ), $sid );
							$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Db::t( 'activities' ) . ' (studio_id, client_id, booking_id, type, message, created_at) VALUES (%d,%d,%d,%s,%s,%s)', $sid, $cid, $bid, 'payment', 'Payment of ₹' . Format::inr( $amt ) . ' received via ' . Config::payment_methods()[ $m ] . ' (' . $in[0] . ')', $pdate . ' 13:00:00' ) );
							$created['payments']++;
						}
					}

					// Expenses for past events.
					if ( $past ) {
						Db::insert( 'expenses', array( 'booking_id' => $bid, 'category' => 'travel', 'description' => 'Team travel to ' . $city, 'amount' => mt_rand( 3, 25 ) * 1000, 'spent_on' => $event_date, 'method' => 'upi' ), $sid );
						if ( mt_rand( 0, 1 ) ) {
							Db::insert( 'expenses', array( 'booking_id' => $bid, 'category' => 'freelancer', 'description' => 'Second shooter payment', 'amount' => mt_rand( 8, 20 ) * 1000, 'spent_on' => $event_date, 'method' => 'bank_transfer' ), $sid );
						}
						if ( in_array( $status, array( 'delivered', 'completed' ), true ) ) {
							Db::insert( 'expenses', array( 'booking_id' => $bid, 'category' => 'album_print', 'description' => 'Album printing', 'amount' => mt_rand( 6, 14 ) * 1000, 'spent_on' => date( 'Y-m-d', strtotime( $event_date ) + 40 * DAY_IN_SECONDS ), 'method' => 'upi' ), $sid );
						}
					}

					// Production tasks.
					$pm = $team['production_manager'][0];
					if ( 'editing' === $status ) {
						Db::insert( 'tasks', array( 'title' => 'Complete photo editing – ' . $name, 'category' => 'editing', 'booking_id' => $bid, 'assigned_to' => $team['editor'][0], 'priority' => 'high', 'due_at' => $d( mt_rand( -2, 6 ), 'Y-m-d' ) . ' 18:00:00', 'status' => 'in_progress' ), $sid );
					}
					if ( 'album_design' === $status ) {
						Db::insert( 'tasks', array( 'title' => 'Album selection call – ' . $name, 'category' => 'album', 'booking_id' => $bid, 'assigned_to' => $team['album_designer'][0], 'priority' => 'medium', 'due_at' => $d( mt_rand( 0, 5 ), 'Y-m-d' ) . ' 16:00:00', 'status' => 'todo' ), $sid );
					}
					if ( 'confirmed' === $status && $evoff < 30 ) {
						Db::insert( 'tasks', array( 'title' => 'Confirm venue & timings – ' . $name, 'category' => 'venue', 'booking_id' => $bid, 'assigned_to' => $pm, 'priority' => 'high', 'due_at' => $d( max( 0, $evoff - 3 ), 'Y-m-d' ) . ' 12:00:00', 'status' => 'todo' ), $sid );
					}
				}
			}

			// Sales tasks.
			if ( in_array( $stage, array( 'interested', 'negotiation' ), true ) && mt_rand( 0, 1 ) ) {
				Db::insert( 'tasks', array( 'title' => ( 'interested' === $stage ? 'Send quotation to ' : 'Close negotiation with ' ) . $name, 'category' => 'interested' === $stage ? 'quotation' : 'followup', 'lead_id' => $lid, 'assigned_to' => $assigned, 'priority' => 'high', 'due_at' => $d( mt_rand( -1, 3 ), 'Y-m-d' ) . ' 17:00:00', 'status' => 'todo' ), $sid );
			}
			if ( 'new' === $stage ) {
				Db::insert( 'tasks', array( 'title' => 'Call new lead ' . $name, 'category' => 'call', 'lead_id' => $lid, 'assigned_to' => $assigned, 'priority' => 'hot' === $priority ? 'hot' : 'medium', 'due_at' => $d( 0, 'Y-m-d' ) . ' 13:00:00', 'status' => 'todo' ), $sid );
			}
		}

		// General studio expenses over the year.
		for ( $m = 0; $m < 12; $m++ ) {
			Db::insert( 'expenses', array( 'category' => 'rent', 'description' => 'Studio rent', 'amount' => 35000, 'spent_on' => date( 'Y-m-05', strtotime( "-$m months", $now_ts ) ), 'method' => 'bank_transfer' ), $sid );
			if ( $m % 2 === 0 ) {
				Db::insert( 'expenses', array( 'category' => 'marketing', 'description' => 'Instagram & Facebook ads', 'amount' => mt_rand( 8, 20 ) * 1000, 'spent_on' => date( 'Y-m-12', strtotime( "-$m months", $now_ts ) ), 'method' => 'card' ), $sid );
			}
		}
		Db::insert( 'expenses', array( 'category' => 'equipment', 'description' => 'Sony 85mm f/1.4 GM lens', 'amount' => 145000, 'spent_on' => $d( -120 ), 'method' => 'card' ), $sid );
		Db::insert( 'expenses', array( 'category' => 'software', 'description' => 'Adobe Creative Cloud (annual)', 'amount' => 54000, 'spent_on' => $d( -200 ), 'method' => 'card' ), $sid );

		mt_srand();
		return $created;
	}
}
