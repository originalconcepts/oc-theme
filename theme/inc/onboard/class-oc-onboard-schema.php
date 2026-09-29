<?php
/**
 * The questions: one declaration drives the form, the saving, the checks
 * and the writing.
 *
 * Every field says which step and screen it sits on, what kind of value
 * it takes, what it defaults to (read live from the site, so the base
 * site's settings are the defaults), when it shows, whether it is
 * required, and where the answer goes when the questionnaire is applied.
 *
 * Targets are small arrays the Apply engine understands:
 *   [ 'mod', 'oc_key' ]                     a theme mod
 *   [ 'option', 'oc_contact', 'company' ]   a key inside a serialised option
 *   [ 'woo', 'woocommerce_store_city' ]     a plain option
 *   [ 'call', 'method' ]                    a method on Apply, for anything else
 *   [ 'state', 'existing' ]                 the invitation record only
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

use OC\Theme\Contact;
use OC\Theme\Thankyou;

defined( 'ABSPATH' ) || exit;

/**
 * Steps, screens and fields.
 */
final class Schema {

	/**
	 * The fields, built once per request.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $fields = null;

	/**
	 * The steps in order, each with its screens. Only the steps that exist
	 * in this build are listed; the numbering follows the spec so a later
	 * build can slot 3–7 in between.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function steps(): array {
		return array(
			array(
				'n'       => 1,
				'title'   => __( 'About the business', 'oc-theme' ),
				'screens' => array(
					array(
						'id'     => '1a',
						'title'  => __( 'The business', 'oc-theme' ),
						'intro'  => __( 'These details appear on the site, in the emails customers receive and on the legal pages. Everything can be changed later.', 'oc-theme' ),
						'fields' => array( 'existing_has', 'existing_url', 'brand_name', 'legal_name', 'company_id', 'domain', 'phone', 'whatsapp', 'email_service', 'email_orders', 'has_store', 'address_street', 'address_city', 'hours', 'instagram', 'facebook', 'tiktok', 'youtube', 'wa_group', 'contact_name', 'contact_phone', 'contact_email' ),
					),
					array(
						'id'     => '1b',
						'title'  => __( 'Accessibility', 'oc-theme' ),
						'intro'  => __( 'The law requires every site to publish the accessibility arrangements of the business. Tick what you have; we write the statement.', 'oc-theme' ),
						'fields' => array( 'branches_mode', 'branches', 'a11y_access', 'a11y_name', 'a11y_phone', 'a11y_email' ),
					),
				),
			),
			array(
				'n'       => 2,
				'title'   => __( 'Content pages', 'oc-theme' ),
				'screens' => array(
					array(
						'id'     => '2a',
						'title'  => __( 'About us', 'oc-theme' ),
						'intro'  => __( 'A few lines about who you are. This becomes the About page and the short text on the home page.', 'oc-theme' ),
						'fields' => array( 'about_mode', 'about_text', 'about_points', 'about_image' ),
					),
					array(
						'id'     => '2b',
						'title'  => __( 'Legal pages', 'oc-theme' ),
						'intro'  => __( 'Terms of sale, privacy policy and accessibility statement. For each one: use our template, or upload your own.', 'oc-theme' ),
						'fields' => array( 'terms_mode', 'terms_kind', 'terms_custom', 'terms_bulky', 'terms_file', 'terms_consent', 'privacy_mode', 'privacy_file', 'privacy_consent', 'a11y_mode', 'a11y_file', 'a11y_consent' ),
					),
				),
			),
			array(
				'n'       => 8,
				'title'   => __( 'Thank-you page', 'oc-theme' ),
				'screens' => array(
					array(
						'id'     => '8a',
						'title'  => __( 'After the order', 'oc-theme' ),
						'intro'  => __( 'What the customer sees right after paying.', 'oc-theme' ),
						'fields' => array( 'ty_layout', 'ty_contact', 'ty_summary', 'ty_wa_group', 'ty_social', 'ty_survey', 'ty_survey_q', 'ty_referral', 'ty_ref_friend', 'ty_ref_reward' ),
					),
				),
			),
		);
	}

	/**
	 * Every field, keyed by id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		if ( null !== self::$fields ) {
			return self::$fields;
		}

		$contact = Contact::settings();
		$ty      = Thankyou::settings();
		$yesno   = array(
			'yes' => __( 'Yes', 'oc-theme' ),
			'no'  => __( 'No', 'oc-theme' ),
		);

		$f = array();

		/* ---- 1a: the business ---- */

		$f['existing_has'] = array(
			'type'    => 'choice',
			'label'   => __( 'Do you have a website today?', 'oc-theme' ),
			'options' => array(
				'no'  => __( 'No, this is a new site', 'oc-theme' ),
				'yes' => __( 'Yes, we are upgrading it', 'oc-theme' ),
			),
			'default' => 'no',
			'target'  => array( 'state', 'existing_has' ),
		);

		$f['existing_url'] = array(
			'type'     => 'url',
			'label'    => __( 'The address of your current site', 'oc-theme' ),
			'help'     => __( 'We will bring the content over from there. The questionnaire does not wait for it — fill it in and we do the rest.', 'oc-theme' ),
			'when'     => array( 'existing_has', array( 'yes' ) ),
			'required' => true,
			'target'   => array( 'state', 'existing' ),
		);

		$f['brand_name'] = array(
			'type'     => 'text',
			'label'    => __( 'Brand name', 'oc-theme' ),
			'help'     => __( 'As it should appear on the site and in emails to customers.', 'oc-theme' ),
			'required' => true,
			'default'  => static function (): string {
				$name = (string) get_bloginfo( 'name' );

				return in_array( $name, array( 'OC Base', 'base' ), true ) ? '' : $name;
			},
			'target'   => array( 'call', 'brand_name' ),
		);

		$f['legal_name'] = array(
			'type'    => 'text',
			'label'   => __( 'Registered name, if different', 'oc-theme' ),
			'help'    => __( 'The company or business name as registered. Appears on the legal pages only.', 'oc-theme' ),
			'default' => (string) $contact['company'],
			'target'  => array( 'option', 'oc_contact', 'company' ),
		);

		$f['company_id'] = array(
			'type'     => 'text',
			'label'    => __( 'Company / business number', 'oc-theme' ),
			'required' => true,
			'dir'      => 'ltr',
			'default'  => (string) $contact['company_id'],
			'target'   => array( 'option', 'oc_contact', 'company_id' ),
		);

		$f['domain'] = array(
			'type'   => 'text',
			'label'  => __( 'The site address you want (domain)', 'oc-theme' ),
			'help'   => __( 'For example www.example.co.il. Leave empty if you have not decided yet.', 'oc-theme' ),
			'dir'    => 'ltr',
			'target' => array( 'state', 'domain' ),
		);

		$f['phone'] = array(
			'type'     => 'phone',
			'label'    => __( 'Phone number shown on the site', 'oc-theme' ),
			'required' => true,
			'default'  => (string) $contact['phone'],
			'target'   => array( 'option', 'oc_contact', 'phone' ),
		);

		$f['whatsapp'] = array(
			'type'    => 'phone',
			'label'   => __( 'Business WhatsApp number', 'oc-theme' ),
			'help'    => __( 'Customers can open a chat with you from the site. Leave empty if there is none.', 'oc-theme' ),
			'default' => (string) $contact['whatsapp'],
			'target'  => array( 'option', 'oc_contact', 'whatsapp' ),
		);

		$f['email_service'] = array(
			'type'     => 'email',
			'label'    => __( 'Customer service email', 'oc-theme' ),
			'help'     => __( 'Shown on the site, and the sender of the emails customers receive.', 'oc-theme' ),
			'required' => true,
			'default'  => (string) $contact['email'],
			'target'   => array( 'call', 'email_service' ),
		);

		$f['email_orders'] = array(
			'type'    => 'email',
			'label'   => __( 'Where should new-order notices go?', 'oc-theme' ),
			'help'    => __( 'Every order sends a notice here. Empty = the customer service email.', 'oc-theme' ),
			'target'  => array( 'call', 'email_orders' ),
		);

		$f['has_store'] = array(
			'type'    => 'choice',
			'label'   => __( 'Do you have a store or showroom open to the public?', 'oc-theme' ),
			'options' => $yesno,
			'default' => (int) $contact['a11y_physical'] ? 'yes' : 'no',
			'target'  => array( 'call', 'has_store' ),
		);

		$f['address_street'] = array(
			'type'     => 'text',
			'label'    => __( 'Street and number', 'oc-theme' ),
			'when'     => array( 'has_store', array( 'yes' ) ),
			'required' => true,
			'target'   => array( 'call', 'address' ),
		);

		$f['address_city'] = array(
			'type'     => 'text',
			'label'    => __( 'City', 'oc-theme' ),
			'when'     => array( 'has_store', array( 'yes' ) ),
			'required' => true,
			'target'   => array( 'call', 'address' ),
		);

		$f['hours'] = array(
			'type'   => 'hours',
			'label'  => __( 'Opening hours', 'oc-theme' ),
			'help'   => __( 'Pick the days, then the hours. Add a line for days with different hours.', 'oc-theme' ),
			'when'   => array( 'has_store', array( 'yes' ) ),
			'target' => array( 'call', 'hours' ),
		);

		foreach ( Contact::networks() as $key => $label ) {
			$f[ $key ] = array(
				'type'    => 'url',
				'label'   => $label,
				'group'   => __( 'Social profiles', 'oc-theme' ),
				'dir'     => 'ltr',
				'default' => (string) $contact[ $key ],
				'target'  => array( 'option', 'oc_contact', $key ),
			);
		}

		$f['wa_group'] = array(
			'type'    => 'url',
			'label'   => __( 'WhatsApp group invite link', 'oc-theme' ),
			'help'    => __( 'If you run a customers\' group, the thank-you page can invite buyers to join.', 'oc-theme' ),
			'group'   => __( 'Social profiles', 'oc-theme' ),
			'dir'     => 'ltr',
			'default' => (string) $contact['wa_group'],
			'target'  => array( 'option', 'oc_contact', 'wa_group' ),
		);

		$f['contact_name'] = array(
			'type'     => 'text',
			'label'    => __( 'Your name', 'oc-theme' ),
			'group'    => __( 'Who are we talking to', 'oc-theme' ),
			'required' => true,
			'default'  => static fn(): string => (string) ( Onboard::state()['client']['name'] ?? '' ),
			'target'   => array( 'call', 'client' ),
		);

		$f['contact_phone'] = array(
			'type'     => 'phone',
			'label'    => __( 'Your mobile', 'oc-theme' ),
			'group'    => __( 'Who are we talking to', 'oc-theme' ),
			'required' => true,
			'default'  => static fn(): string => (string) ( Onboard::state()['client']['phone'] ?? '' ),
			'target'   => array( 'call', 'client' ),
		);

		$f['contact_email'] = array(
			'type'     => 'email',
			'label'    => __( 'Your email', 'oc-theme' ),
			'group'    => __( 'Who are we talking to', 'oc-theme' ),
			'required' => true,
			'default'  => static fn(): string => (string) ( Onboard::state()['client']['email'] ?? '' ),
			'target'   => array( 'call', 'client' ),
		);

		/* ---- 1b: accessibility ---- */

		$f['branches_mode'] = array(
			'type'    => 'choice',
			'label'   => __( 'How many branches?', 'oc-theme' ),
			'options' => array(
				'one'  => __( 'One store', 'oc-theme' ),
				'many' => __( 'More than one', 'oc-theme' ),
			),
			'default' => 'one',
			'when'    => array( 'has_store', array( 'yes' ) ),
			'target'  => array( 'call', 'branches' ),
		);

		$f['branches'] = array(
			'type'     => 'repeater',
			'label'    => __( 'The branches', 'oc-theme' ),
			'help'     => __( 'Each branch gets its own page, and customers can choose it for collection at the checkout.', 'oc-theme' ),
			'when'     => array( 'branches_mode', array( 'many' ) ),
			'required' => true,
			'row'      => __( 'Branch', 'oc-theme' ),
			'add'      => __( 'Add a branch', 'oc-theme' ),
			'max'      => 20,
			'fields'   => array(
				'name'    => array(
					'type'     => 'text',
					'label'    => __( 'Branch name', 'oc-theme' ),
					'required' => true,
				),
				'address' => array(
					'type'  => 'text',
					'label' => __( 'Street and number', 'oc-theme' ),
				),
				'city'    => array(
					'type'  => 'text',
					'label' => __( 'City', 'oc-theme' ),
				),
				'phone'   => array(
					'type'  => 'phone',
					'label' => __( 'Phone', 'oc-theme' ),
				),
				'hours'   => array(
					'type'  => 'textarea',
					'label' => __( 'Opening hours', 'oc-theme' ),
				),
				'access'  => array(
					'type'    => 'checks',
					'label'   => __( 'Accessibility arrangements at this branch', 'oc-theme' ),
					'options' => Contact::access_items(),
				),
			),
			'target'   => array( 'call', 'branches' ),
		);

		$f['a11y_access'] = array(
			'type'    => 'checks',
			'label'   => __( 'Accessibility arrangements in the store', 'oc-theme' ),
			'help'    => __( 'Tick everything you have. Nothing ticked is fine too; the statement says so honestly.', 'oc-theme' ),
			'options' => Contact::access_items(),
			'when'    => array( 'branches_mode', array( 'one' ) ),
			'default' => array_keys( array_filter( is_array( $contact['a11y_access'] ) ? $contact['a11y_access'] : array() ) ),
			'target'  => array( 'call', 'a11y_access' ),
		);

		$f['a11y_name'] = array(
			'type'     => 'text',
			'label'    => __( 'Accessibility coordinator — full name', 'oc-theme' ),
			'help'     => __( 'The person customers can turn to about accessibility. It can be the owner.', 'oc-theme' ),
			'required' => true,
			'default'  => (string) $contact['a11y_name'],
			'target'   => array( 'option', 'oc_contact', 'a11y_name' ),
		);

		$f['a11y_phone'] = array(
			'type'    => 'phone',
			'label'   => __( 'Coordinator phone', 'oc-theme' ),
			'help'    => __( 'Empty = the store phone.', 'oc-theme' ),
			'default' => (string) $contact['a11y_phone'],
			'target'  => array( 'option', 'oc_contact', 'a11y_phone' ),
		);

		$f['a11y_email'] = array(
			'type'    => 'email',
			'label'   => __( 'Coordinator email', 'oc-theme' ),
			'help'    => __( 'Empty = the customer service email.', 'oc-theme' ),
			'default' => (string) $contact['a11y_email'],
			'target'  => array( 'option', 'oc_contact', 'a11y_email' ),
		);

		/* ---- 2a: about ---- */

		$f['about_mode'] = array(
			'type'    => 'choice',
			'label'   => __( 'The About text', 'oc-theme' ),
			'options' => array(
				'paste' => __( 'I have a text, I will paste it', 'oc-theme' ),
				'write' => __( 'Write it for me from a few points', 'oc-theme' ),
				'later' => __( 'Later', 'oc-theme' ),
			),
			'default' => 'paste',
			'target'  => array( 'call', 'about' ),
		);

		$f['about_text'] = array(
			'type'     => 'textarea',
			'label'    => __( 'About us', 'oc-theme' ),
			'rows'     => 10,
			'when'     => array( 'about_mode', array( 'paste' ) ),
			'required' => true,
			'target'   => array( 'call', 'about' ),
		);

		$f['about_points'] = array(
			'type'     => 'textarea',
			'label'    => __( 'A few points about the business', 'oc-theme' ),
			'help'     => __( 'Since when, what you sell, who it is for, what makes you different, a word about the team. One point per line.', 'oc-theme' ),
			'rows'     => 8,
			'when'     => array( 'about_mode', array( 'write' ) ),
			'required' => true,
			'target'   => array( 'call', 'about' ),
		);

		$f['about_image'] = array(
			'type'   => 'file',
			'accept' => 'image',
			'label'  => __( 'A picture for the About page', 'oc-theme' ),
			'help'   => __( 'The store, the team, the workshop. Optional.', 'oc-theme' ),
			'when'   => array( 'about_mode', array( 'paste', 'write' ) ),
			'target' => array( 'call', 'about' ),
		);

		/* ---- 2b: legal ---- */

		$legal_mode = array(
			'template' => __( 'Use the template', 'oc-theme' ),
			'upload'   => __( 'Upload my own', 'oc-theme' ),
		);

		$f['terms_mode'] = array(
			'type'    => 'choice',
			'label'   => __( 'Terms of sale', 'oc-theme' ),
			'options' => $legal_mode,
			'default' => 'template',
			'target'  => array( 'call', 'legal_terms' ),
		);

		$f['terms_kind'] = array(
			'type'    => 'choice',
			'label'   => __( 'What kind of store?', 'oc-theme' ),
			'options' => array(
				'general' => __( 'General — clothing, furniture, gifts, anything shipped', 'oc-theme' ),
				'food'    => __( 'Food — perishables, cold chain, weight', 'oc-theme' ),
			),
			'default' => (string) $contact['terms_kind'],
			'when'    => array( 'terms_mode', array( 'template' ) ),
			'target'  => array( 'call', 'legal_terms' ),
		);

		$f['terms_custom'] = array(
			'type'    => 'choice',
			'label'   => __( 'Do you sell made-to-order products?', 'oc-theme' ),
			'help'    => __( 'Custom furniture, engraving, sizes made for the customer. The terms then say there is no cancellation once production began.', 'oc-theme' ),
			'options' => $yesno,
			'default' => (int) $contact['terms_custom'] ? 'yes' : 'no',
			'when'    => array( 'terms_mode', array( 'template' ) ),
			'target'  => array( 'call', 'legal_terms' ),
		);

		$f['terms_bulky'] = array(
			'type'    => 'choice',
			'label'   => __( 'Do you deliver large items?', 'oc-theme' ),
			'help'    => __( 'Furniture, appliances: the terms then cover access, stairs, a crane and assembly.', 'oc-theme' ),
			'options' => $yesno,
			'default' => (int) $contact['terms_bulky'] ? 'yes' : 'no',
			'when'    => array( 'terms_mode', array( 'template' ) ),
			'target'  => array( 'call', 'legal_terms' ),
		);

		$f['terms_file'] = array(
			'type'     => 'file',
			'accept'   => 'doc',
			'label'    => __( 'Your terms (Word or PDF)', 'oc-theme' ),
			'when'     => array( 'terms_mode', array( 'upload' ) ),
			'required' => true,
			'target'   => array( 'call', 'legal_terms' ),
		);

		$f['terms_consent'] = array(
			'type'     => 'consent',
			'label'    => __( 'I have read and understood: this is a template, not legal advice, and using it is my responsibility.', 'oc-theme' ),
			'when'     => array( 'terms_mode', array( 'template' ) ),
			'required' => true,
			'target'   => array( 'call', 'legal_terms' ),
		);

		$f['privacy_mode'] = array(
			'type'    => 'choice',
			'label'   => __( 'Privacy policy', 'oc-theme' ),
			'options' => $legal_mode,
			'default' => 'template',
			'target'  => array( 'call', 'legal_privacy' ),
		);

		$f['privacy_file'] = array(
			'type'     => 'file',
			'accept'   => 'doc',
			'label'    => __( 'Your privacy policy (Word or PDF)', 'oc-theme' ),
			'when'     => array( 'privacy_mode', array( 'upload' ) ),
			'required' => true,
			'target'   => array( 'call', 'legal_privacy' ),
		);

		$f['privacy_consent'] = array(
			'type'     => 'consent',
			'label'    => __( 'I have read and understood: this is a template, not legal advice, and using it is my responsibility.', 'oc-theme' ),
			'when'     => array( 'privacy_mode', array( 'template' ) ),
			'required' => true,
			'target'   => array( 'call', 'legal_privacy' ),
		);

		$f['a11y_mode'] = array(
			'type'    => 'choice',
			'label'   => __( 'Accessibility statement', 'oc-theme' ),
			'options' => $legal_mode,
			'default' => 'template',
			'target'  => array( 'call', 'legal_a11y' ),
		);

		$f['a11y_file'] = array(
			'type'     => 'file',
			'accept'   => 'doc',
			'label'    => __( 'Your accessibility statement (Word or PDF)', 'oc-theme' ),
			'when'     => array( 'a11y_mode', array( 'upload' ) ),
			'required' => true,
			'target'   => array( 'call', 'legal_a11y' ),
		);

		$f['a11y_consent'] = array(
			'type'     => 'consent',
			'label'    => __( 'I have read and understood: this is a template, not legal advice, and using it is my responsibility.', 'oc-theme' ),
			'when'     => array( 'a11y_mode', array( 'template' ) ),
			'required' => true,
			'target'   => array( 'call', 'legal_a11y' ),
		);

		/* ---- 8a: thank-you ---- */

		$f['ty_layout'] = array(
			'type'    => 'choice',
			'label'   => __( 'Layout', 'oc-theme' ),
			'options' => array(
				'stack' => __( 'One column — everything under the greeting', 'oc-theme' ),
				'split' => __( 'Two columns — greeting and summary on one side', 'oc-theme' ),
			),
			'default' => (string) $ty['layout'],
			'target'  => array( 'option', 'oc_thankyou', 'layout' ),
		);

		$f['ty_contact'] = array(
			'type'    => 'choice',
			'label'   => __( 'Show phone, email and WhatsApp under the greeting?', 'oc-theme' ),
			'options' => $yesno,
			'default' => (int) $ty['contact'] ? 'yes' : 'no',
			'target'  => array( 'option', 'oc_thankyou', 'contact' ),
		);

		$f['ty_summary'] = array(
			'type'    => 'choice',
			'label'   => __( 'Show the order summary with pictures?', 'oc-theme' ),
			'options' => $yesno,
			'default' => (int) $ty['summary'] ? 'yes' : 'no',
			'target'  => array( 'option', 'oc_thankyou', 'summary' ),
		);

		$f['ty_wa_group'] = array(
			'type'    => 'choice',
			'label'   => __( 'Invite the buyer to your WhatsApp group?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'when'    => array( 'wa_group', 'filled' ),
			'target'  => array( 'option', 'oc_thankyou', 'wa_group' ),
		);

		$f['ty_social'] = array(
			'type'    => 'choice',
			'label'   => __( '"Follow us" buttons?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'when'    => array( 'instagram|facebook|tiktok|youtube', 'filled' ),
			'target'  => array( 'option', 'oc_thankyou', 'social' ),
		);

		$f['ty_survey'] = array(
			'type'    => 'choice',
			'label'   => __( 'A one-question satisfaction survey?', 'oc-theme' ),
			'help'    => __( 'Stars from 1 to 5 and a free line. The answers show on your dashboard.', 'oc-theme' ),
			'options' => $yesno,
			'default' => (int) $ty['survey'] ? 'yes' : 'no',
			'target'  => array( 'option', 'oc_thankyou', 'survey' ),
		);

		$f['ty_survey_q'] = array(
			'type'    => 'text',
			'label'   => __( 'The question', 'oc-theme' ),
			'default' => (string) $ty['survey_q'],
			'when'    => array( 'ty_survey', array( 'yes' ) ),
			'target'  => array( 'option', 'oc_thankyou', 'survey_q' ),
		);

		$f['ty_referral'] = array(
			'type'    => 'choice',
			'label'   => __( 'Refer a friend?', 'oc-theme' ),
			'help'    => __( 'The buyer gets a coupon to share; the friend gets a discount and the buyer a reward.', 'oc-theme' ),
			'options' => $yesno,
			'default' => (int) $ty['referral'] ? 'yes' : 'no',
			'target'  => array( 'option', 'oc_thankyou', 'referral' ),
		);

		$f['ty_ref_friend'] = array(
			'type'    => 'number',
			'label'   => __( 'Friend\'s discount (%)', 'oc-theme' ),
			'default' => (int) $ty['ref_friend_pct'],
			'min'     => 1,
			'max'     => 50,
			'when'    => array( 'ty_referral', array( 'yes' ) ),
			'target'  => array( 'option', 'oc_thankyou', 'ref_friend_pct' ),
		);

		$f['ty_ref_reward'] = array(
			'type'    => 'number',
			'label'   => __( 'Buyer\'s reward (%)', 'oc-theme' ),
			'default' => (int) $ty['ref_reward_pct'],
			'min'     => 1,
			'max'     => 50,
			'when'    => array( 'ty_referral', array( 'yes' ) ),
			'target'  => array( 'option', 'oc_thankyou', 'ref_reward_pct' ),
		);

		// Every field carries every key, so readers never test for presence.
		foreach ( $f as $id => $def ) {
			$f[ $id ] = wp_parse_args(
				$def,
				array(
					'type'     => 'text',
					'label'    => '',
					'help'     => '',
					'group'    => '',
					'options'  => array(),
					'default'  => null,
					'when'     => null,
					'required' => false,
					'target'   => null,
				)
			);
		}

		self::$fields = $f;

		return $f;
	}

	/**
	 * Is there such a field?
	 *
	 * @param string $id Field id.
	 */
	public static function has( string $id ): bool {
		return isset( self::fields()[ $id ] );
	}

	/**
	 * One field's declaration.
	 *
	 * @param string $id Field id.
	 * @return array<string,mixed>
	 */
	public static function field( string $id ): array {
		return self::fields()[ $id ] ?? array();
	}

	/**
	 * The default, evaluated when it is a closure.
	 *
	 * @param string $id Field id.
	 * @return mixed
	 */
	public static function default_of( string $id ) {
		$f = self::field( $id );

		if ( ! $f ) {
			return null;
		}

		$d = $f['default'];

		if ( $d instanceof \Closure ) {
			$d = $d();
		}

		if ( null === $d ) {
			return self::empty_for( (string) $f['type'] );
		}

		return $d;
	}

	/**
	 * What "nothing" looks like for a type.
	 *
	 * @param string $type Field type.
	 * @return mixed
	 */
	private static function empty_for( string $type ) {
		switch ( $type ) {
			case 'checks':
			case 'hours':
			case 'repeater':
				return array();
			case 'file':
				return null;
			case 'consent':
				return false;
			case 'number':
				return 0;
			default:
				return '';
		}
	}

	/**
	 * Is a value "nothing"?
	 *
	 * @param mixed $v The value.
	 */
	public static function empty_value( $v ): bool {
		if ( null === $v || false === $v ) {
			return true;
		}

		if ( is_array( $v ) ) {
			return empty( $v );
		}

		return '' === trim( (string) $v );
	}

	/**
	 * Does the field show, given the other answers?
	 *
	 * `when` is [ field, values ] — shown when the field's answer is one of
	 * the values; or [ 'a|b|c', 'filled' ] — shown when any of those is
	 * not empty. A hidden field's own condition chain is followed too.
	 *
	 * @param string              $id     Field id.
	 * @param array<string,mixed> $values id => value (defaults fill the gaps).
	 */
	public static function shown( string $id, array $values ): bool {
		$f = self::field( $id );

		if ( ! $f || null === $f['when'] ) {
			return true;
		}

		list( $dep, $want ) = $f['when'];

		foreach ( explode( '|', (string) $dep ) as $d ) {
			$has = array_key_exists( $d, $values ) ? $values[ $d ] : self::default_of( $d );

			if ( ! self::shown( $d, $values ) ) {
				continue;
			}

			if ( 'filled' === $want ) {
				if ( ! self::empty_value( $has ) ) {
					return true;
				}
				continue;
			}

			if ( in_array( (string) $has, array_map( 'strval', (array) $want ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The required fields that are shown and still empty.
	 *
	 * @param array<string,mixed> $values id => value.
	 * @return array<int,string> Field ids.
	 */
	public static function missing( array $values ): array {
		$out = array();

		foreach ( self::fields() as $id => $f ) {
			if ( ! $f['required'] || ! self::shown( $id, $values ) ) {
				continue;
			}

			$v = array_key_exists( $id, $values ) ? $values[ $id ] : self::default_of( $id );

			if ( 'consent' === $f['type'] ) {
				if ( true !== $v ) {
					$out[] = $id;
				}
				continue;
			}

			if ( 'repeater' === $f['type'] ) {
				if ( self::empty_value( $v ) ) {
					$out[] = $id;
					continue;
				}

				foreach ( (array) $v as $row ) {
					foreach ( $f['fields'] as $sub_id => $sub ) {
						if ( ! empty( $sub['required'] ) && self::empty_value( $row[ $sub_id ] ?? null ) ) {
							$out[] = $id;
							continue 3;
						}
					}
				}
				continue;
			}

			if ( self::empty_value( $v ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * A raw value made safe for its field.
	 *
	 * @param string $id  Field id.
	 * @param mixed  $raw From the request.
	 * @return mixed
	 */
	public static function sanitize( string $id, $raw ) {
		$f = self::field( $id );

		return $f ? self::sanitize_typed( $f, $raw ) : null;
	}

	/**
	 * Sanitise by declaration (fields and repeater sub-fields alike).
	 *
	 * @param array<string,mixed> $f   Declaration.
	 * @param mixed               $raw Value.
	 * @return mixed
	 */
	private static function sanitize_typed( array $f, $raw ) {
		$type = (string) ( $f['type'] ?? 'text' );

		switch ( $type ) {
			case 'text':
				return mb_substr( sanitize_text_field( (string) ( is_scalar( $raw ) ? $raw : '' ) ), 0, 200 );

			case 'textarea':
				return mb_substr( sanitize_textarea_field( (string) ( is_scalar( $raw ) ? $raw : '' ) ), 0, 8000 );

			case 'phone':
				$v = preg_replace( '/[^0-9+\-\s()]/', '', (string) ( is_scalar( $raw ) ? $raw : '' ) );

				return mb_substr( trim( (string) $v ), 0, 30 );

			case 'email':
				return sanitize_email( (string) ( is_scalar( $raw ) ? $raw : '' ) );

			case 'url':
				$v = trim( (string) ( is_scalar( $raw ) ? $raw : '' ) );

				if ( '' !== $v && ! preg_match( '~^https?://~i', $v ) ) {
					$v = 'https://' . $v;
				}

				return esc_url_raw( $v );

			case 'number':
				$n = (int) ( is_scalar( $raw ) ? $raw : 0 );

				if ( isset( $f['min'] ) ) {
					$n = max( (int) $f['min'], $n );
				}
				if ( isset( $f['max'] ) ) {
					$n = min( (int) $f['max'], $n );
				}

				return $n;

			case 'choice':
				$v = (string) ( is_scalar( $raw ) ? $raw : '' );

				return isset( $f['options'][ $v ] ) ? $v : '';

			case 'checks':
				$out = array();

				foreach ( (array) $raw as $v ) {
					$v = (string) ( is_scalar( $v ) ? $v : '' );

					if ( isset( $f['options'][ $v ] ) ) {
						$out[] = $v;
					}
				}

				return array_values( array_unique( $out ) );

			case 'consent':
				return true === $raw || '1' === (string) ( is_scalar( $raw ) ? $raw : '' ) || 'true' === $raw;

			case 'file':
				if ( ! is_array( $raw ) || empty( $raw['id'] ) ) {
					return null;
				}

				$id = absint( $raw['id'] );

				if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
					return null;
				}

				return array(
					'id'   => $id,
					'url'  => (string) wp_get_attachment_url( $id ),
					'name' => sanitize_text_field( (string) ( $raw['name'] ?? basename( (string) get_attached_file( $id ) ) ) ),
					'type' => (string) get_post_mime_type( $id ),
				);

			case 'hours':
				$out = array();

				foreach ( array_slice( (array) $raw, 0, 7 ) as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$days = array();

					foreach ( (array) ( $row['days'] ?? array() ) as $d ) {
						$d = (int) $d;

						if ( $d >= 0 && $d <= 6 ) {
							$days[] = $d;
						}
					}

					$from = preg_match( '/^\d{2}:\d{2}$/', (string) ( $row['from'] ?? '' ) ) ? (string) $row['from'] : '';
					$to   = preg_match( '/^\d{2}:\d{2}$/', (string) ( $row['to'] ?? '' ) ) ? (string) $row['to'] : '';

					if ( $days && $from && $to ) {
						sort( $days );
						$out[] = array(
							'days' => array_values( array_unique( $days ) ),
							'from' => $from,
							'to'   => $to,
						);
					}
				}

				return $out;

			case 'repeater':
				$out = array();
				$max = (int) ( $f['max'] ?? 20 );

				foreach ( array_slice( (array) $raw, 0, $max ) as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$clean = array();

					foreach ( (array) $f['fields'] as $sub_id => $sub ) {
						$clean[ $sub_id ] = self::sanitize_typed( $sub, $row[ $sub_id ] ?? null );
					}

					$out[] = $clean;
				}

				return $out;

			default:
				return null;
		}
	}

	/**
	 * The schema as the browser needs it: labels resolved, defaults
	 * evaluated, targets left out.
	 *
	 * @return array{steps:array<int,array<string,mixed>>,fields:array<string,array<string,mixed>>}
	 */
	public static function for_js(): array {
		$fields = array();

		foreach ( self::fields() as $id => $f ) {
			$row = $f;

			unset( $row['target'] );

			$row['default'] = self::default_of( $id );

			if ( 'repeater' === $f['type'] ) {
				$subs = array();

				foreach ( (array) $f['fields'] as $sub_id => $sub ) {
					$subs[ $sub_id ] = wp_parse_args(
						$sub,
						array(
							'type'     => 'text',
							'label'    => '',
							'help'     => '',
							'options'  => array(),
							'required' => false,
						)
					);
				}

				$row['fields'] = $subs;
			}

			$fields[ $id ] = $row;
		}

		return array(
			'steps'  => self::steps(),
			'fields' => $fields,
		);
	}
}
