<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Transactional Emails Controller
 *
 * Property Hive Emails Class which handles the sending on transactional emails and email templates. This class loads in available emails.
 *
 * @class 		PH_Emails
 * @version		1.0.0
 * @package		PropertyHive/Classes/Emails
 * @category	Class
 * @author 		PropertyHive
 */
class PH_Emails {

	/** @var array Array of email notification classes */
	public $emails;

	/** @var PH_Emails The single instance of the class */
	protected static $_instance = null;

	/**
	 * Main PH_Emails Instance.
	 *
	 * Ensures only one instance of PH_Emails is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @static
	 * @return PH_Emails Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'propertyhive' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'propertyhive' ), '1.0.0' );
	}

	/**
	 * Constructor for the email class hooks in all emails that can be sent.
	 *
	 */
	public function __construct() {
		$this->init();

		// Email Header, Footer
		add_action( 'propertyhive_email_header', array( $this, 'email_header' ), 10, 1 );
		add_action( 'propertyhive_email_footer', array( $this, 'email_footer' ), 10, 1 );

		add_action( 'propertyhive_process_email_log', array( $this, 'ph_process_email_log' ) );
		add_action( 'propertyhive_auto_email_match', array( $this, 'ph_auto_email_match' ) );
	}

	/**
	 * Process ph_email_log table. Handle failed, hung and pending emails
	 */
	public function ph_process_email_log()
	{
		global $wpdb;

		$lock_id = uniqid( "", true );

		$wpdb->query("
		    UPDATE " . $wpdb->prefix . "ph_email_log
		    SET 
				status = 'fail2',
				lock_id = ''
		    WHERE 
		    	status = 'fail1'
		    AND
		    	lock_id <> '' 
		    AND
		    	locked_at <= '" . date("Y-m-d H:i:s", strtotime('24 hours ago')) . "'
		");

		$wpdb->query("
		    UPDATE " . $wpdb->prefix . "ph_email_log
		    SET 
				status = 'fail1',
				lock_id = ''
		    WHERE 
		    	status = ''
		    AND
		    	lock_id <> '' 
		    AND
		    	locked_at <= '" . date("Y-m-d H:i:s", strtotime('24 hours ago')) . "'
		");
		
		// Lock/reserve all emails in log that are status blank or 'fail1' and lock_id blank and send_at in the past
		// Only grab 5 at a time to prevent hanging/being seen as spamming
		$wpdb->query("
		    UPDATE " . $wpdb->prefix . "ph_email_log
		    SET 
				lock_id = '" . $lock_id . "',
				locked_at = '" . date("Y-m-d H:i:s") . "'
		    WHERE 
		    	(status = '' OR status = 'fail1')
		    AND
		    	lock_id = ''
		    AND
		    	send_at <= '" . date("Y-m-d H:i:s") . "'
		    LIMIT 5
		");

		// We now have up to 5 emails locked. Get this 5 and attempt to send
		$emails_to_send = $wpdb->get_results("
			SELECT *
			FROM " . $wpdb->prefix . "ph_email_log
			WHERE 
				lock_id = '" . $lock_id . "'
		");

		foreach ( $emails_to_send as $email_to_send ) 
		{
			$email_id = $email_to_send->email_id;

			$headers = array();
			$headers[] = 'From: ' . $email_to_send->from_name . ' <' . $email_to_send->from_email_address . '>';
			$headers[] = 'Content-Type: text/html; charset=UTF-8';

        	$body = apply_filters( 'propertyhive_mail_content', $this->style_inline( $this->wrap_message( $email_to_send->body, $email_to_send->contact_id ) ) );
			
			$sent = wp_mail( 
				$email_to_send->to_email_address, 
				$email_to_send->subject, 
				$body, 
				$headers/*,
				string|array $attachments = array() */
			);

			$new_status = '';
			if ( $sent )
			{
				// Sent successfully
				$new_status = 'sent';
			}
			else
			{
				// Failed to send
				if ($email_to_send->status == '')
				{
					$new_status = 'fail1';
				}
				else
				{
					$new_status = 'fail2';
				}
			}
			$wpdb->query("
			    UPDATE " . $wpdb->prefix . "ph_email_log
			    SET 
					status = '" . $new_status . "',
					lock_id = ''
			    WHERE 
			    	email_id = '" . $email_id . "'
			");
		}
	}

	/*
	 * Automatically send new properties to registered applicants
	 */
	public function ph_auto_email_match()
	{
		global $post;

		// Auto emails enabled in settings
		// Auto emails not disabled in applicant record
		// Property added more recently that setting enabled in settings
		// Property not already previously sent
		// Valid email address
		// 'Do not email' not selected

		$auto_property_match_enabled = get_option( 'propertyhive_auto_property_match', '' );

		if ( $auto_property_match_enabled == '' )
		{
			return false;
		}
		
		$auto_property_match_enabled_date = get_option( 'propertyhive_auto_property_match_enabled_date', '' );

		if ( $auto_property_match_enabled_date == '' )
		{
			return false;
		}

		include_once( dirname(__FILE__) . '/admin/class-ph-admin-matching-properties.php' );
		$ph_admin_matching_properties = new PH_Admin_Matching_Properties();

		// Get all contacts that have a type of applicant
		$args = array(
			'post_type' => 'contact',
			'nopaging' => true,
			'meta_query' => array(
				array(
					'key' => '_contact_types',
					'value' => 'applicant',
					'compare' => 'LIKE'
				)
			),
			'fields' => 'ids'
		);

		$contact_query = new WP_Query( $args );

		if ( $contact_query->have_posts() )
		{
			$default_subject = get_option( 'propertyhive_property_match_default_email_subject', '' );
            $default_body = get_option( 'propertyhive_property_match_default_email_body', '' );

			while ( $contact_query->have_posts() )
			{
				$contact_query->the_post();

				$contact_id = get_the_ID();

				// invalid email address
				if ( strpos( get_post_meta( $contact_id, '_email_address', TRUE ), '@' ) === FALSE )
				{
					continue;
				}

				// email in the list of forbidden contact methods
				$forbidden_contact_methods = get_post_meta( $contact_id, '_forbidden_contact_methods', TRUE );
				if ( is_array($forbidden_contact_methods) && in_array('email', $forbidden_contact_methods) )
				{
					continue;
				}

				$applicant_profiles = get_post_meta( $contact_id, '_applicant_profiles', TRUE );

				if ( $applicant_profiles != '' && $applicant_profiles > 0 )
				{
					$dismissed_properties = get_post_meta( $contact_id, '_dismissed_properties', TRUE );

					for ( $i = 0; $i < $applicant_profiles; ++$i )
					{
						$applicant_profile = get_post_meta( $contact_id, '_applicant_profile_' . $i, TRUE );

						if ( $applicant_profile == '' || !is_array($applicant_profile) || !isset($applicant_profile['department']) )
						{
							continue;
						}

						if ( isset($applicant_profile['send_matching_properties']) && $applicant_profile['send_matching_properties'] == '' )
						{
							continue;
						}

						if ( isset($applicant_profile['auto_match_disabled']) && $applicant_profile['auto_match_disabled'] == 'yes' )
						{
							continue;
						}

						$matching_properties = $ph_admin_matching_properties->get_matching_properties( $contact_id, $i, $auto_property_match_enabled_date );

						if ( !empty($matching_properties) )
						{
							$already_sent_properties = get_post_meta( $contact_id, '_applicant_profile_' . $i . '_match_history', TRUE );

							// Check properties haven't already been sent and not marked as 'not interested'
							$new_matching_properties = array();
							foreach ($matching_properties as $matching_property)
							{
								if ( !isset($already_sent_properties[$matching_property->id]) && !in_array($matching_property->id, $dismissed_properties) )
								{
									$new_matching_properties[] = $matching_property->id;
								}
							}

							if ( !empty($new_matching_properties) )
							{
								$subject = str_replace("[property_count]", count($new_matching_properties) . ' propert' . ( ( count($new_matching_properties) != 1 ) ? 'ies' : 'y' ), $default_subject);

						        $body = str_replace("[contact_name]", get_the_title($contact_id), $default_body);
						        $body = str_replace("[property_count]", count($new_matching_properties) . ' propert' . ( ( count($new_matching_properties) != 1 ) ? 'ies' : 'y' ), $body);

						        if ( strpos($body, '[properties]') !== FALSE )
						        {
						            ob_start();
						            if ( !empty($new_matching_properties) )
						            {
						                foreach ( $new_matching_properties as $email_property_id )
						                {
						                    $property = new PH_Property((int)$email_property_id);
						                    ph_get_template( 'emails/applicant-match-property.php', array( 'property' => $property ) );
						                }
						            }
						            $body = str_replace("[properties]", ob_get_clean(), $body);
						        }

								$ph_admin_matching_properties->send_emails(
									$contact_id,
									$i,
									$new_matching_properties,
									get_bloginfo('name'),
									get_option('admin_email'),
									$subject,
									$body
								);
							}
						}
					}
				}
			}
		}

		wp_reset_postdata();
	}

	/**
	 * Init email classes.
	 */
	public function init() {
		// include css inliner
		if ( ! class_exists( 'Emogrifier' ) && class_exists( 'DOMDocument' ) ) {
			include_once( dirname( __FILE__ ) . '/libraries/class-emogrifier.php' );
		}
	}

	/**
	 * Apply inline styles to dynamic content.
	 *
	 * @param string|null $content
	 * @return string
	 */
	public function style_inline( $content ) {
		// make sure we only inline CSS for html emails
		if ( class_exists( 'DOMDocument' ) ) {
			ob_start();
			ph_get_template( 'emails/email-styles.php' );
			$css = apply_filters( 'propertyhive_email_styles', ob_get_clean() );
			// apply CSS styles inline for picky email clients
			try {
				$emogrifier = new Emogrifier( $content, $css );
				$content    = $emogrifier->emogrify();
			} catch ( Exception $e ) {
				die("Error converting CSS styles to be inline. Error as follows: " . $e->getMessage());
			}
		}
		return $content;
	}
	
	/**
	 * Get the email header.
	 */
	public function email_header( $contact_id = '' ) {
		ph_get_template( 'emails/email-header.php' );
	}

	/**
	 * Get the email footer.
	 */
	public function email_footer( $contact_id = '' ) {
		$unsubscribe_link = '#';
		if ($contact_id != '')
		{
			$unsubscribe_link = site_url() .'?ph_unsubscribe=' . base64_encode($contact_id . '|' . md5( get_post_meta( $contact_id, '_email_address', TRUE ) ) );
		}

		ph_get_template( 'emails/email-footer.php', array( 'unsubscribe_link' => $unsubscribe_link ) );
	}

	/**
	 * Wraps a message in the Property Hive mail template.
	 *
	 * @param mixed $email_heading
	 * @param string $message
	 * @return string
	 */
	public function wrap_message( $message, $contact_id = '', $plain_text = false ) {
		// Buffer
		ob_start();

		do_action( 'propertyhive_email_header', $contact_id );

		echo wpautop( wptexturize( $message ) );

		do_action( 'propertyhive_email_footer', $contact_id );

		// Get contents
		$message = ob_get_clean();

		return $message;
	}
}