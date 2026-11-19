<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp: templates, click-to-chat links and (optional) Cloud API sending.
 *
 * Click-to-chat (wa.me) needs no account and works everywhere.
 * Cloud API sending only runs when the studio has saved a Phone Number ID and
 * access token in Settings. Note Meta's rule: free-form text can only be sent
 * within 24 hours of the customer's last message; outside that window Meta
 * requires a pre-approved template message.
 */
class Whatsapp {

	public static function default_templates() {
		return array(
			'first_contact'        => array( 'Thank you for enquiry', "Hi {{client_name}}! 👋\nThank you for contacting {{studio_name}} for your {{event_type}}{{event_date_text}}.\nWe'd love to capture your special day. When is a good time to call you and discuss your requirements?" ),
			'followup'             => array( 'Follow-up', "Hi {{client_name}},\nThis is {{studio_name}} following up on your {{event_type}} enquiry{{event_date_text}}. Do you have a few minutes to discuss the packages? We are happy to customise one for you." ),
			'quotation'            => array( 'Send quotation', "Hi {{client_name}},\nPlease find your quotation {{quotation_number}} from {{studio_name}} for {{quotation_amount}}.\nView it here: {{quotation_link}}\nThis quotation is valid until {{valid_until}}. Let us know if you have any questions!" ),
			'booking_confirmation' => array( 'Booking confirmation', "Hi {{client_name}}, 🎉\nYour booking {{booking_number}} with {{studio_name}} is confirmed!\nEvent dates: {{event_dates}}\nPackage amount: {{total_amount}}\nAdvance received: {{paid_amount}}\nThank you for choosing us. We can't wait to be part of your celebrations!" ),
			'payment_reminder'     => array( 'Payment reminder', "Hi {{client_name}},\nThis is a gentle reminder from {{studio_name}} that {{due_amount}} ({{due_label}}) for booking {{booking_number}} is due on {{due_date}}.\nTotal pending: {{pending_amount}}\n{{upi_text}}Thank you!" ),
			'event_reminder'       => array( 'Event reminder', "Hi {{client_name}},\nA quick reminder that our team will cover your {{event_name}} on {{event_date}}{{event_time_text}} at {{venue}}.\nPlease share the contact number of a family member who will coordinate with our team on the day. See you soon! 📸" ),
			'delivery'             => array( 'Delivery update', "Hi {{client_name}},\nGreat news! Your photos and videos for {{booking_number}} are ready. Our team will share the delivery link with you shortly.\nThank you for choosing {{studio_name}}! We'd love a review if you enjoyed working with us. ❤️" ),
			'portal_share'         => array( 'Client portal link + PIN', "Hi {{client_name}},\nYour {{event_type}} project is now available on our client portal.\nYou can check your photography and video delivery progress using the link below:\n{{portal_link}}\nYour secure PIN is: {{portal_pin}}\nPlease keep this PIN private.\nThank you for choosing {{studio_name}}!" ),
			'portal_link'          => array( 'Client portal link', "Hi {{client_name}},\nYou can track the delivery of your photos and videos for {{booking_number}} here:\n{{portal_link}}\nUse the PIN we shared with you earlier. If you need it again, just reply here.\n– {{studio_name}}" ),
			'delivery_progress'    => array( 'Delivery progress update', "Hi {{client_name}},\nHere is an update on your {{event_type}} deliverables from {{studio_name}}:\n{{deliverables_summary}}\nOverall progress: {{overall_progress}}\nSee live status anytime: {{portal_link}}" ),
			'deliverable_ready'    => array( 'Deliverable completed', "Hi {{client_name}}, 🎉\nYour {{deliverable_name}} is {{deliverable_status}}!\n{{delivery_link_text}}You can see all your deliverables on your portal: {{portal_link}}\nThank you for choosing {{studio_name}}! ❤️" ),
		);
	}

	public static function link( $phone, $text ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( strlen( $digits ) === 10 ) {
			$digits = '91' . $digits;
		}
		return 'https://wa.me/' . $digits . '?text=' . rawurlencode( $text );
	}

	public static function fill( $body, array $vars ) {
		return trim(
			preg_replace_callback(
				'/\{\{\s*([a-z_]+)\s*\}\}/',
				function ( $m ) use ( $vars ) {
					return isset( $vars[ $m[1] ] ) ? (string) $vars[ $m[1] ] : '';
				},
				$body
			)
		);
	}

	public static function template( $slug ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'wa_templates' ) . ' WHERE studio_id=%d AND slug=%s', Tenant::studio_id(), $slug ) );
		if ( $row ) {
			return $row->body;
		}
		$d = self::default_templates();
		return isset( $d[ $slug ] ) ? $d[ $slug ][1] : '';
	}

	/**
	 * Placeholder values for a lead / client / booking / quotation / event.
	 */
	public static function vars( array $ctx ) {
		$studio = Tenant::studio();
		$types  = Config::event_types();
		$m      = function ( $n ) {
			return '₹' . Format::inr( $n );
		};
		$v      = array(
			'studio_name'  => $studio ? $studio->name : '',
			'studio_phone' => $studio ? $studio->phone : '',
			'upi_id'       => $studio ? $studio->settings['upi_id'] : '',
			'upi_text'     => $studio && $studio->settings['upi_id'] ? 'UPI: ' . $studio->settings['upi_id'] . "\n" : '',
		);
		$phone  = null;
		if ( ! empty( $ctx['lead'] ) ) {
			$l                    = $ctx['lead'];
			$v['client_name']     = $l->name;
			$v['event_type']      = $l->event_type ? $types[ $l->event_type ] : 'event';
			$v['event_date']      = Format::date( $l->event_date );
			$v['event_date_text'] = $l->event_date ? ' on ' . Format::date( $l->event_date ) : '';
			$v['venue']           = $l->venue;
			$phone                = $l->whatsapp ? $l->whatsapp : $l->phone;
		}
		if ( ! empty( $ctx['client'] ) ) {
			$c                = $ctx['client'];
			$v['client_name'] = $c->name;
			$phone            = $c->whatsapp ? $c->whatsapp : $c->phone;
		}
		if ( ! empty( $ctx['quotation'] ) ) {
			$q                     = $ctx['quotation'];
			$v['client_name']      = isset( $v['client_name'] ) ? $v['client_name'] : $q->customer_name;
			$v['quotation_number'] = $q->number;
			$v['quotation_amount'] = $m( $q->total );
			$v['quotation_link']   = home_url( '/crm/q/' . $q->public_token );
			$v['valid_until']      = Format::date( $q->valid_until );
			$v['event_type']       = $q->event_type ? $types[ $q->event_type ] : ( isset( $v['event_type'] ) ? $v['event_type'] : 'event' );
			$phone                 = $phone ? $phone : $q->customer_phone;
		}
		if ( ! empty( $ctx['booking'] ) ) {
			$b                     = $ctx['booking'];
			$v['booking_number']   = $b->number;
			$v['total_amount']     = $m( $b->total_amount );
			$sum                   = isset( $b->payment ) ? $b->payment : \PCRM\Rest\Bookings::payment_summary( $b );
			$v['paid_amount']      = $m( $sum['paid'] );
			$v['pending_amount']   = $m( $sum['pending'] );
			$v['due_amount']       = $sum['next_due'] ? $m( $sum['next_due']['amount'] ) : $m( $sum['pending'] );
			$v['due_label']        = $sum['next_due'] ? $sum['next_due']['label'] : 'balance';
			$v['due_date']         = $sum['next_due'] && $sum['next_due']['due_date'] ? Format::date( $sum['next_due']['due_date'] ) : 'at the earliest';
			$v['event_type']       = $b->event_type ? $types[ $b->event_type ] : 'event';
			if ( ! empty( $b->events ) ) {
				$v['event_dates'] = implode(
					', ',
					array_map(
						function ( $e ) {
							return $e->name . ' (' . Format::date( $e->event_date ) . ')';
						},
						$b->events
					)
				);
			}
		}
		if ( ! empty( $ctx['event'] ) ) {
			$e                    = $ctx['event'];
			$v['event_name']      = $e->name;
			$v['event_date']      = Format::date( $e->event_date );
			$v['event_time_text'] = $e->start_time ? ' from ' . Format::time( $e->start_time ) : '';
			$v['venue']           = trim( $e->venue . ( $e->city ? ', ' . $e->city : '' ), ', ' );
		}
		if ( ! empty( $ctx['booking'] ) ) {
			$b                 = $ctx['booking'];
			$v['portal_link']  = Portals::url_for_booking( (int) $b->id );
			$dels              = isset( $b->deliverables ) ? $b->deliverables : Rest\Deliverables::for_booking( (int) $b->id );
			$st                = Config::deliverable_statuses();
			$v['deliverables_summary'] = implode(
				"\n",
				array_map(
					function ( $d ) use ( $st ) {
						return '• ' . $d->name . ': ' . $st[ $d->status ] . ' (' . (int) $d->progress . '%)';
					},
					$dels
				)
			);
			$v['overall_progress'] = Rest\Deliverables::overall( $dels ) . '%';
		}
		if ( ! empty( $ctx['deliverable'] ) ) {
			$d                        = $ctx['deliverable'];
			$st                       = Config::deliverable_statuses();
			$v['deliverable_name']    = $d->name;
			$v['deliverable_status']  = 'delivered' === $d->status ? 'delivered' : strtolower( $st[ $d->status ] );
			$v['delivery_link']       = (string) $d->delivery_link;
			$v['delivery_link_text']  = $d->delivery_link ? 'Download / view here: ' . $d->delivery_link . "\n" : '';
		}
		$v['portal_pin'] = ! empty( $ctx['portal_pin'] ) ? $ctx['portal_pin'] : '(the PIN we shared with you)';
		$v['_phone']     = $phone;
		return $v;
	}

	public static function render_for_quotation( $q ) {
		$ctx = array( 'quotation' => $q );
		if ( $q->lead_id ) {
			$ctx['lead'] = Db::find( 'leads', $q->lead_id );
		}
		$vars = self::vars( $ctx );
		$text = self::fill( self::template( 'quotation' ), $vars );
		$ph   = $q->customer_phone ? $q->customer_phone : $vars['_phone'];
		return array(
			'text' => $text,
			'link' => $ph ? self::link( $ph, $text ) : null,
		);
	}

	public static function api_configured() {
		$s = Tenant::studio();
		return $s && ! empty( $s->settings['wa_phone_id'] ) && ! empty( $s->settings['wa_token'] );
	}

	/**
	 * Sends a text message through the WhatsApp Cloud API.
	 */
	public static function send_api( $phone, $text ) {
		$s = Tenant::studio();
		if ( ! self::api_configured() ) {
			return new \WP_Error( 'not_configured', 'WhatsApp Business API is not configured. Add your Phone Number ID and access token in Settings → WhatsApp.', array( 'status' => 422 ) );
		}
		$limit = Plans::check( $s, 'whatsapp_api' );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$to  = preg_replace( '/\D+/', '', (string) $phone );
		$res = wp_remote_post(
			'https://graph.facebook.com/v21.0/' . rawurlencode( $s->settings['wa_phone_id'] ) . '/messages',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $s->settings['wa_token'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'to'                => $to,
						'type'              => 'text',
						'text'              => array(
							'preview_url' => true,
							'body'        => $text,
						),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'wa_failed', 'Could not reach WhatsApp: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 300 ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			return new \WP_Error( 'wa_failed', 'WhatsApp rejected the message: ' . $msg, array( 'status' => 502 ) );
		}
		return isset( $body['messages'][0]['id'] ) ? $body['messages'][0]['id'] : true;
	}
}
