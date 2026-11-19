<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription plans and their limits. -1 means unlimited.
 *
 * The plan definitions live in the {prefix}pcrm_plans table (seeded by
 * Billing_Installer from self::defaults()) so the platform owner can change
 * prices and limits from Super Admin without a code change. If the table is
 * missing (for example before the migration has run) the defaults are used.
 *
 * Plans (v0.6.0):
 *   starter      15-day free trial, 1 team login, 50 new leads a month
 *   professional ₹599/month or ₹4,999/year, 2 logins, 250 leads, WhatsApp templates, Razorpay links
 *   business     ₹999/month or ₹7,999/year, unlimited logins and leads
 *
 * Older plan keys are still understood: free → starter, enterprise → business.
 */
class Plans {

	/** Days after a paid period ends before the studio is treated as lapsed (renewal webhooks can be late). */
	const GRACE_DAYS = 3;

	/** Features a plan can switch on. Everything not listed here is on every plan. */
	const FEATURES = array( 'whatsapp_templates', 'razorpay_links', 'whatsapp_api' );

	private static $cache = null;

	public static function defaults() {
		return array(
			'starter'      => array(
				'label'           => 'Starter',
				'tagline'         => 'Try everything you need to run a studio, free for 15 days.',
				'price'           => 0,
				'price_yearly'    => 0,
				'trial_days'      => 15,
				'members'         => 1,
				'leads_per_month' => 50,
				'features'        => array(),
				'sort'            => 1,
			),
			'professional' => array(
				'label'           => 'Professional',
				'tagline'         => 'For growing photography studios.',
				'price'           => 599,
				'price_yearly'    => 4999,
				'trial_days'      => 0,
				'members'         => 2,
				'leads_per_month' => 250,
				'features'        => array( 'whatsapp_templates', 'razorpay_links', 'whatsapp_api' ),
				'sort'            => 2,
			),
			'business'     => array(
				'label'           => 'Business',
				'tagline'         => 'Best for multi-team agencies.',
				'price'           => 999,
				'price_yearly'    => 7999,
				'trial_days'      => 0,
				'members'         => -1,
				'leads_per_month' => -1,
				'features'        => array( 'whatsapp_templates', 'razorpay_links', 'whatsapp_api' ),
				'sort'            => 3,
			),
		);
	}

	/** Old plan keys from v0.5.0 and earlier. */
	public static function aliases() {
		return array(
			'free'       => 'starter',
			'enterprise' => 'business',
		);
	}

	public static function normalize( $key ) {
		$key = sanitize_key( (string) $key );
		$a   = self::aliases();
		return isset( $a[ $key ] ) ? $a[ $key ] : $key;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * All plans, keyed by plan key, in display order. Each plan also carries
	 * the old flags (pdf, reports, whatsapp_api) so existing callers keep working.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$plans = self::defaults();
		global $wpdb;
		$t = Installer::table( 'plans' );
		if ( get_option( 'pcrm_billing_db_version' ) ) {
			$rows = $wpdb->get_results( "SELECT * FROM $t WHERE is_active=1 ORDER BY sort, id" ); // phpcs:ignore
			if ( $rows ) {
				$plans = array();
				foreach ( $rows as $r ) {
					$f                     = json_decode( (string) $r->features, true );
					$plans[ $r->plan_key ] = array(
						'label'           => $r->label,
						'tagline'         => (string) $r->tagline,
						'price'           => (int) $r->price_monthly,
						'price_yearly'    => (int) $r->price_yearly,
						'trial_days'      => (int) $r->trial_days,
						'members'         => (int) $r->members,
						'leads_per_month' => (int) $r->leads_per_month,
						'features'        => is_array( $f ) ? array_values( array_intersect( self::FEATURES, $f ) ) : array(),
						'sort'            => (int) $r->sort,
					);
				}
			}
		}
		foreach ( $plans as $k => $p ) {
			$plans[ $k ]['key']          = $k;
			$plans[ $k ]['pdf']          = true;
			$plans[ $k ]['reports']      = true;
			$plans[ $k ]['whatsapp_api'] = in_array( 'whatsapp_api', $p['features'], true );
			$plans[ $k ]['paid']         = $p['price'] > 0 || $p['price_yearly'] > 0;
		}
		self::$cache = apply_filters( 'pcrm_plans', $plans );
		return self::$cache;
	}

	public static function exists( $plan ) {
		$all = self::all();
		return isset( $all[ self::normalize( $plan ) ] );
	}

	public static function get( $plan ) {
		$all  = self::all();
		$plan = self::normalize( $plan );
		if ( isset( $all[ $plan ] ) ) {
			return $all[ $plan ];
		}
		return isset( $all['starter'] ) ? $all['starter'] : reset( $all );
	}

	/** The plan a studio starts on (the trial plan). */
	public static function trial_plan() {
		foreach ( self::all() as $k => $p ) {
			if ( $p['trial_days'] > 0 ) {
				return $k;
			}
		}
		return 'starter';
	}

	/**
	 * What happens when a trial or a paid period ends:
	 * 'read_only'      the studio can sign in, view and export, but not add or change anything (default)
	 * 'starter_limits' the studio keeps working on Starter limits
	 */
	public static function after_expiry_mode() {
		$m = get_option( 'pcrm_billing_after_expiry', 'read_only' );
		return in_array( $m, array( 'read_only', 'starter_limits' ), true ) ? $m : 'read_only';
	}

	/**
	 * The studio's billing state, used by the limits, the billing page and the app banner.
	 *
	 * state: trial | trial_ended | active | past_due | lapsed | manual
	 */
	public static function status( $studio ) {
		$key     = self::normalize( $studio->plan );
		$plan    = self::get( $key );
		$today   = Db::date( 'Y-m-d' );
		$trial   = isset( $studio->trial_ends_at ) ? $studio->trial_ends_at : null;
		$expires = $studio->plan_expires_at ? $studio->plan_expires_at : null;
		$state   = 'manual';
		$days    = null;
		$ends    = null;

		if ( ! $plan['paid'] ) {
			// Trial plan. No end date means the super admin gave it without a limit.
			$ends = $trial ? $trial : $expires;
			if ( $ends ) {
				$days  = self::days_between( $today, $ends );
				$state = $days >= 0 ? 'trial' : 'trial_ended';
			}
		} elseif ( $expires ) {
			$ends  = $expires;
			$days  = self::days_between( $today, $expires );
			$grace = self::days_between( $today, gmdate( 'Y-m-d', strtotime( $expires . ' +' . self::GRACE_DAYS . ' days' ) ) );
			if ( $days >= 0 ) {
				$state = 'active';
			} elseif ( $grace >= 0 && ! empty( $studio->billing_status ) && in_array( $studio->billing_status, array( 'active', 'pending' ), true ) ) {
				$state = 'past_due';
			} else {
				$state = 'lapsed';
			}
		}

		$ended     = in_array( $state, array( 'trial_ended', 'lapsed' ), true );
		$read_only = $ended && 'read_only' === self::after_expiry_mode();
		$limits    = $ended ? self::get( self::trial_plan() ) : $plan;

		return array(
			'plan'           => $key,
			'label'          => $plan['label'],
			'state'          => $state,
			'ends_at'        => $ends,
			'days_left'      => null === $days ? null : max( 0, $days + 1 ), // Includes today.
			'read_only'      => $read_only,
			'after_expiry'   => self::after_expiry_mode(),
			'limits_plan'    => $limits['key'],
			'members'        => $limits['members'],
			'leads_per_month' => $limits['leads_per_month'],
			'features'       => $ended ? array() : $plan['features'],
			'billing_cycle'  => isset( $studio->billing_cycle ) ? $studio->billing_cycle : null,
		);
	}

	private static function days_between( $from, $to ) {
		return (int) floor( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
	}

	public static function has_feature( $studio, $feature ) {
		$s = self::status( $studio );
		return in_array( $feature, $s['features'], true );
	}

	/** Team logins in use: members with a WordPress login, not deactivated, not platform support staff. */
	public static function logins_used( $studio_id ) {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . Installer::table( 'members' ) . " WHERE studio_id=%d AND status<>'inactive' AND user_id IS NOT NULL";
		$ids = Tenant::super_admin_ids();
		if ( $ids ) {
			$sql .= ' AND user_id NOT IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $studio_id ) ); // phpcs:ignore
	}

	/** New leads created this calendar month (studio time zone), from any source. */
	public static function leads_this_month( $studio_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'leads' ) . ' WHERE studio_id=%d AND created_at >= %s', $studio_id, Db::date( 'Y-m-01 00:00:00' ) ) );
	}

	public static function usage( $studio ) {
		$s = self::status( $studio );
		return array(
			'logins' => array( 'used' => self::logins_used( $studio->id ), 'limit' => $s['members'] ),
			'leads'  => array( 'used' => self::leads_this_month( $studio->id ), 'limit' => $s['leads_per_month'], 'resets_on' => gmdate( 'Y-m-d', strtotime( Db::date( 'Y-m-01' ) . ' 00:00:00 UTC +1 month' ) ) ),
		);
	}

	private static function upgrade_error( $message, $code = 'plan_limit', $extra = array() ) {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => 402, 'upgrade' => true ), $extra ) );
	}

	/**
	 * Error for a studio whose trial or plan has ended in read-only mode, else null.
	 */
	public static function read_only_error( $studio ) {
		$s = self::status( $studio );
		if ( ! $s['read_only'] ) {
			return null;
		}
		$when = $s['ends_at'] ? Format::date( $s['ends_at'] ) : '';
		$msg  = 'trial_ended' === $s['state']
			? sprintf( 'Your 15-day free trial ended on %s. Your data is safe and you can still view it. Choose Professional or Business in Billing to keep adding and editing.', $when )
			: sprintf( 'Your %s plan ended on %s. Your data is safe and you can still view it. Renew in Billing to keep adding and editing.', $s['label'], $when );
		return self::upgrade_error( $msg, 'plan_expired' );
	}

	/**
	 * "Upgrade to Professional (2 logins) or Business (unlimited logins) in Billing."
	 * Lists only plans that give more than the limits in force now ($s from status()),
	 * so a studio is never told to upgrade to the plan it is already on.
	 * $what: members | leads_per_month | a feature key.
	 */
	public static function upgrade_hint( array $s, $what ) {
		$cur     = self::get( $s['limits_plan'] );
		$current = in_array( $s['state'], array( 'trial_ended', 'lapsed' ), true ) ? null : $s['plan'];
		$opts    = array();
		foreach ( self::all() as $k => $p ) {
			if ( ! $p['paid'] || $k === $current || $p['sort'] < $cur['sort'] ) {
				continue;
			}
			if ( 'members' === $what || 'leads_per_month' === $what ) {
				$have = $s[ $what ];
				$get  = $p[ $what ];
				if ( $have < 0 || ( $get >= 0 && $get <= $have ) ) {
					continue;
				}
				if ( 'members' === $what ) {
					$more = $get < 0 ? 'unlimited logins' : ( 1 === $get ? '1 login' : $get . ' logins' );
				} else {
					$more = $get < 0 ? 'unlimited leads' : $get . ' leads a month';
				}
				$opts[] = $p['label'] . ' (' . $more . ')';
			} elseif ( in_array( $what, $p['features'], true ) ) {
				$opts[] = $p['label'];
			}
		}
		if ( ! $opts ) {
			return 'Contact ShootPilot support for a higher limit.';
		}
		$last = array_pop( $opts );
		return 'Upgrade to ' . ( $opts ? implode( ', ', $opts ) . ' or ' : '' ) . $last . ' in Billing.';
	}

	/**
	 * Returns WP_Error when a studio has hit a plan limit, else true.
	 * $what: members | leads | reports | whatsapp_api | whatsapp_templates | razorpay_links
	 */
	public static function check( $studio, $what ) {
		if ( ! $studio ) {
			return true;
		}
		$ro = self::read_only_error( $studio );
		if ( $ro && in_array( $what, array( 'members', 'leads', 'whatsapp_api', 'whatsapp_templates', 'razorpay_links' ), true ) ) {
			return $ro;
		}
		$s    = self::status( $studio );
		$name = self::get( $s['limits_plan'] )['label'];
		if ( 'members' === $what && $s['members'] >= 0 ) {
			$n = self::logins_used( $studio->id );
			if ( $n >= $s['members'] ) {
				return self::upgrade_error(
					sprintf(
						'Your %s plan includes %s. You are using %d. %s Crew without a login can always be added.',
						$name,
						1 === $s['members'] ? '1 team login' : $s['members'] . ' team logins',
						$n,
						self::upgrade_hint( $s, 'members' )
					),
					'plan_limit',
					array( 'limit' => 'members', 'used' => $n, 'allowed' => $s['members'] )
				);
			}
		}
		if ( 'leads' === $what && $s['leads_per_month'] >= 0 ) {
			$n = self::leads_this_month( $studio->id );
			if ( $n >= $s['leads_per_month'] ) {
				return self::upgrade_error(
					sprintf( 'Your %s plan includes %d new leads a month and you have added %d this month. %s The count resets on the 1st.', $name, $s['leads_per_month'], $n, self::upgrade_hint( $s, 'leads_per_month' ) ),
					'plan_limit',
					array( 'limit' => 'leads', 'used' => $n, 'allowed' => $s['leads_per_month'] )
				);
			}
		}
		$labels = array(
			'whatsapp_templates' => 'WhatsApp share with ready templates',
			'razorpay_links'     => 'Razorpay payment links',
			'whatsapp_api'       => 'WhatsApp Cloud API sending',
		);
		if ( isset( $labels[ $what ] ) && ! in_array( $what, $s['features'], true ) ) {
			return self::upgrade_error(
				in_array( $s['state'], array( 'trial_ended', 'lapsed' ), true ) && in_array( $what, self::get( $s['plan'] )['features'], true )
					? sprintf( '%s is part of your %s plan, which has ended. Renew it in Billing to use it again.', $labels[ $what ], $s['label'] )
					: sprintf( '%s is not included in your %s plan. %s', $labels[ $what ], $s['label'], self::upgrade_hint( $s, $what ) ),
				'plan_feature',
				array( 'feature' => $what )
			);
		}
		return true;
	}
}
