<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Business vocabulary for the photography industry. Keys are stored in the DB,
 * labels are shown in the UI (and can be translated later).
 */
class Config {

	public static function stages() {
		return array(
			'new'                => 'New Lead',
			'first_call_pending' => 'First Call Pending',
			'contacted'          => 'Contacted',
			'interested'         => 'Interested',
			'followup_required'  => 'Follow-up Required',
			'quotation_sent'     => 'Quotation Sent',
			'negotiation'        => 'Negotiation',
			'booked'             => 'Booking Confirmed',
			'lost'               => 'Lost',
		);
	}

	public static function sources() {
		return array(
			'instagram'  => 'Instagram',
			'facebook'   => 'Facebook Ads',
			'google'     => 'Google Ads',
			'website'    => 'Website',
			'enquiry_form' => 'Website / Enquiry Form',
			'whatsapp'   => 'WhatsApp',
			'referral'   => 'Referral',
			'walkin'     => 'Walk-in',
			'other'      => 'Other',
		);
	}

	public static function event_types() {
		return array(
			'wedding'      => 'Wedding',
			'pre_wedding'  => 'Pre-Wedding',
			'engagement'   => 'Engagement',
			'haldi_mehndi' => 'Haldi / Mehndi',
			'reception'    => 'Reception',
			'birthday'     => 'Birthday',
			'baby_shoot'   => 'Baby / Maternity',
			'corporate'    => 'Corporate Event',
			'commercial'   => 'Commercial',
			'fashion'      => 'Fashion',
			'product'      => 'Product',
			'other'        => 'Other',
		);
	}

	public static function call_statuses() {
		return array(
			'not_called'     => 'Not Called',
			'attempted'      => 'Call Attempted',
			'connected'      => 'Connected',
			'not_answered'   => 'Not Answered',
			'call_back'      => 'Call Back Requested',
			'followup'       => 'Follow-up Required',
			'interested'     => 'Interested',
			'not_interested' => 'Not Interested',
			'switched_off'   => 'Number Switched Off',
			'wrong_number'   => 'Wrong Number',
		);
	}

	public static function priorities() {
		return array(
			'low'    => 'Low',
			'medium' => 'Medium',
			'high'   => 'High',
			'hot'    => 'Hot',
		);
	}

	public static function quotation_statuses() {
		return array(
			'draft'    => 'Draft',
			'sent'     => 'Sent',
			'viewed'   => 'Viewed',
			'accepted' => 'Accepted',
			'rejected' => 'Rejected',
			'expired'  => 'Expired',
		);
	}

	public static function service_categories() {
		return array(
			'photography'    => 'Photography',
			'cinematography' => 'Cinematography',
			'pre_wedding'    => 'Pre-Wedding Package',
			'album'          => 'Albums',
			'drone'          => 'Drone Coverage',
			'reels'          => 'Reels & Social Media',
			'additional'     => 'Additional Services',
		);
	}

	public static function booking_statuses() {
		return array(
			'confirmed'    => 'Booking Confirmed',
			'shoot_done'   => 'Event Executed',
			'editing'      => 'Photo/Video Editing',
			'album_design' => 'Album Design',
			'delivered'    => 'Final Delivery',
			'completed'    => 'Completed',
			'cancelled'    => 'Cancelled',
		);
	}

	/** Studio settings that are never sent back to the browser in full. */
	public static function secret_settings() {
		return array( 'wa_token', 'rzp_key_secret', 'rzp_webhook_secret' );
	}

	public static function payment_methods() {
		return array(
			'upi'           => 'UPI',
			'cash'          => 'Cash',
			'bank_transfer' => 'Bank Transfer (NEFT/IMPS/RTGS)',
			'cheque'        => 'Cheque',
			'card'          => 'Card',
			'razorpay'      => 'Razorpay (online)',
			'other'         => 'Other',
		);
	}

	public static function payment_types() {
		return array(
			'advance'     => 'Advance',
			'installment' => 'Installment',
			'final'       => 'Final Payment',
		);
	}

	/** Only "received" payments count toward what the client has paid. */
	public static function payment_statuses() {
		return array(
			'received' => 'Received',
			'pending'  => 'Pending clearance',
			'failed'   => 'Failed / bounced',
			'refunded' => 'Refunded',
		);
	}

	/** Creative roles for the team directory (separate from CRM login access). */
	public static function team_roles() {
		return array(
			'lead_photographer'      => 'Lead Photographer',
			'candid_photographer'    => 'Candid Photographer',
			'traditional_photographer' => 'Traditional Photographer',
			'cinematographer'        => 'Cinematographer',
			'traditional_videographer' => 'Traditional Videographer',
			'drone_operator'         => 'Drone Operator',
			'photo_editor'           => 'Photo Editor',
			'video_editor'           => 'Video Editor',
			'album_designer'         => 'Album Designer',
			'assistant_photographer' => 'Assistant Photographer',
			'production_manager'     => 'Production Manager',
		);
	}

	public static function availability_statuses() {
		return array(
			'available'   => 'Available',
			'busy'        => 'Busy',
			'on_leave'    => 'On Leave',
			'unavailable' => 'Unavailable',
		);
	}

	public static function deliverable_statuses() {
		return array(
			'not_started'        => 'Not Started',
			'in_progress'        => 'In Progress',
			'editing'            => 'Editing',
			'awaiting_selection' => 'Awaiting Client Selection',
			'under_review'       => 'Under Review',
			'ready'              => 'Ready for Delivery',
			'delivered'          => 'Delivered',
			'revision_requested' => 'Revision Requested',
		);
	}

	/** Suggested progress when a deliverable moves to a status (admin can change it). */
	public static function deliverable_progress() {
		return array(
			'not_started'        => 0,
			'in_progress'        => 25,
			'editing'            => 50,
			'awaiting_selection' => 60,
			'under_review'       => 80,
			'ready'              => 95,
			'delivered'          => 100,
			'revision_requested' => 70,
		);
	}

	/** Common deliverables offered as quick picks. */
	public static function deliverable_presets() {
		return array(
			'Traditional Photography',
			'Candid Photography',
			'Cinematic Wedding Film',
			'Traditional Video',
			'Teaser Film',
			'Instagram Reels',
			'Edited Photographs',
			'Wedding Album',
			'Pre-Wedding Photos',
			'Pre-Wedding Film',
			'Drone Footage',
		);
	}

	public static function expense_categories() {
		return array(
			'travel'      => 'Travel',
			'freelancer'  => 'Freelancer Payment',
			'equipment'   => 'Equipment',
			'album_print' => 'Album Printing',
			'food'        => 'Food & Stay',
			'marketing'   => 'Marketing',
			'software'    => 'Software',
			'rent'        => 'Rent',
			'other'       => 'Other',
		);
	}

	/* ---------- Crew scheduling (v0.5.0) ---------- */
	public static function crew_categories() {
		return array(
			'candid_photographer'      => 'Candid Photographer',
			'traditional_photographer' => 'Traditional Photographer',
			'cinematographer'          => 'Cinematographer',
			'traditional_videographer' => 'Traditional Videographer',
			'drone_operator'           => 'Drone Operator',
			'assistant_photographer'   => 'Assistant Photographer',
			'lighting_assistant'       => 'Lighting Assistant',
			'production_assistant'     => 'Production Assistant',
			'photo_editor'             => 'Photo Editor',
			'video_editor'             => 'Video Editor',
			'other'                    => 'Other',
		);
	}

	public static function crew_availability() {
		return array(
			'available'   => 'Available',
			'limited'     => 'Limited availability',
			'unavailable' => 'Not available',
			'inactive'    => 'Inactive',
		);
	}

	public static function crew_assignment_statuses() {
		return array(
			'scheduled'            => 'Scheduled',
			'confirmation_pending' => 'Confirmation Pending',
			'confirmed'            => 'Confirmed',
			'declined'             => 'Declined',
			'reassigned'           => 'Reassigned',
			'completed'            => 'Completed',
			'cancelled'            => 'Cancelled',
		);
	}

	/** Assignment statuses that free the crew member (no clash check). */
	public static function crew_inactive_statuses() {
		return array( 'declined', 'reassigned', 'cancelled' );
	}

	public static function crew_payment_statuses() {
		return array(
			'pending' => 'Pending',
			'partial' => 'Partially Paid',
			'paid'    => 'Paid',
		);
	}

	/* ---------- Public enquiry form (v0.5.0) ---------- */
	public static function enquiry_services() {
		return array(
			'candid'      => 'Candid Photography',
			'traditional' => 'Traditional Photography',
			'cinematic'   => 'Cinematography',
			'video'       => 'Traditional Videography',
			'pre_wedding' => 'Pre-Wedding Shoot',
			'drone'       => 'Drone Coverage',
			'album'       => 'Wedding Album',
			'reels'       => 'Reels & Social Media Videos',
			'other'       => 'Other Services',
		);
	}

	/** Services that only make sense for wedding-type events. */
	public static function enquiry_wedding_services() {
		return array( 'pre_wedding', 'album' );
	}

	public static function wedding_event_types() {
		return array( 'wedding', 'pre_wedding', 'engagement', 'haldi_mehndi', 'reception' );
	}

	/** Budget ranges: [label, value saved as the lead's estimated budget]. */
	public static function enquiry_budgets() {
		return array(
			'under_50k' => array( 'Under ₹50,000', 40000 ),
			'50k_1l'    => array( '₹50,000 – ₹1 Lakh', 75000 ),
			'1l_2l'     => array( '₹1 – 2 Lakh', 150000 ),
			'2l_3l'     => array( '₹2 – 3 Lakh', 250000 ),
			'3l_5l'     => array( '₹3 – 5 Lakh', 400000 ),
			'5l_10l'    => array( '₹5 – 10 Lakh', 750000 ),
			'10l_plus'  => array( '₹10 Lakh+', 1000000 ),
			'custom'    => array( 'Enter my own budget', null ),
		);
	}

	public static function enquiry_heard_from() {
		return array(
			'instagram' => 'Instagram',
			'facebook'  => 'Facebook',
			'google'    => 'Google Search',
			'youtube'   => 'YouTube',
			'referral'  => 'Friend / Family',
			'wedding_site' => 'Wedding website (WedMeGood, ShaadiSaga…)',
			'past_client' => 'I am a past client',
			'other'     => 'Other',
		);
	}

	public static function enquiry_statuses() {
		return array(
			'lead_created' => 'Lead created',
			'duplicate'    => 'Possible duplicate',
			'merged'       => 'Added to existing lead',
			'separate'     => 'Created as new lead',
			'on_hold'      => 'On hold (plan lead limit)',
			'dismissed'    => 'Dismissed',
		);
	}

	public static function task_categories() {
		return array(
			'call'      => 'Call',
			'quotation' => 'Quotation',
			'followup'  => 'Follow-up',
			'payment'   => 'Payment',
			'venue'     => 'Venue',
			'team'      => 'Team Assignment',
			'editing'   => 'Editing',
			'album'     => 'Album',
			'delivery'  => 'Delivery',
			'other'     => 'Other',
		);
	}

	public static function task_statuses() {
		return array(
			'todo'        => 'To Do',
			'in_progress' => 'In Progress',
			'done'        => 'Done',
		);
	}

	public static function roles() {
		return array(
			'owner'              => 'Owner',
			'admin'              => 'Admin',
			'sales'              => 'Sales Executive',
			'photographer'       => 'Photographer',
			'cinematographer'    => 'Cinematographer',
			'editor'             => 'Editor',
			'album_designer'     => 'Album Designer',
			'production_manager' => 'Production Manager',
		);
	}

	public static function event_roles() {
		return array(
			'photographer'       => 'Photographer',
			'candid'             => 'Candid Photographer',
			'cinematographer'    => 'Cinematographer',
			'videographer'       => 'Traditional Videographer',
			'drone'              => 'Drone Operator',
			'assistant'          => 'Assistant',
		);
	}

	public static function default_settings() {
		return array(
			'currency'        => 'INR',
			'timezone'        => 'Asia/Kolkata',
			'date_format'     => 'DD-MM-YYYY',
			'phone_code'      => '+91',
			'gst_rate'        => 18,
			'gst_enabled'     => true,
			'quote_prefix'    => 'QT',
			'booking_prefix'  => 'BK',
			'receipt_prefix'  => 'RC',
			'quote_validity'  => 15,
			'quote_terms'     => "50% advance to confirm the booking.\n30% before the event, balance on delivery.\nEdited photos delivered within 30 days, album within 60 days of selection.\nTravel and stay outside the city are charged extra.",
			'bank_details'    => '',
			'upi_id'          => '',
			'wa_phone_id'     => '',
			'wa_token'        => '',
			'rzp_key_id'      => '',
			'rzp_key_secret'  => '',
			'rzp_webhook_secret' => '',
		);
	}

	public static function all() {
		return array(
			'stages'             => self::stages(),
			'sources'            => self::sources(),
			'event_types'        => self::event_types(),
			'call_statuses'      => self::call_statuses(),
			'priorities'         => self::priorities(),
			'quotation_statuses' => self::quotation_statuses(),
			'service_categories' => self::service_categories(),
			'booking_statuses'   => self::booking_statuses(),
			'payment_methods'    => self::payment_methods(),
			'expense_categories' => self::expense_categories(),
			'task_categories'    => self::task_categories(),
			'task_statuses'      => self::task_statuses(),
			'roles'              => self::roles(),
			'event_roles'        => self::event_roles(),
			'payment_types'      => self::payment_types(),
			'payment_statuses'   => self::payment_statuses(),
			'team_roles'         => self::team_roles(),
			'availability'       => self::availability_statuses(),
			'deliverable_statuses' => self::deliverable_statuses(),
			'deliverable_progress' => self::deliverable_progress(),
			'deliverable_presets'  => self::deliverable_presets(),
			'crew_categories'      => self::crew_categories(),
			'crew_availability'    => self::crew_availability(),
			'crew_assignment_statuses' => self::crew_assignment_statuses(),
			'crew_payment_statuses' => self::crew_payment_statuses(),
			'enquiry_services'     => self::enquiry_services(),
			'enquiry_budgets'      => array_map( function ( $b ) { return $b[0]; }, self::enquiry_budgets() ),
			'enquiry_heard_from'   => self::enquiry_heard_from(),
			'enquiry_statuses'     => self::enquiry_statuses(),
			'plans'              => Plans::all(),
		);
	}
}
