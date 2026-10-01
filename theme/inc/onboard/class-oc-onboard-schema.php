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
	 * The two halves of the questionnaire. The first is everything the site
	 * needs in order to speak in the customer's name; the second is how it
	 * looks. Between them the customer gets a word of encouragement, which is
	 * what these lines are for.
	 *
	 * A part with no 'text' hands over to nothing: the questionnaire then
	 * goes straight from its last question to the review.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function parts(): array {
		return array(
			1 => array(
				'title' => __( 'Your details', 'oc-theme' ),
				'done'  => __( 'Part one is done', 'oc-theme' ),
				'text'  => __( 'Everything the site needs in order to speak in your name is already with us: the business, the ways customers reach you, the legal pages and the thank-you page.', 'oc-theme' ),
				'link'  => __( 'Look over what you answered', 'oc-theme' ),
				'ahead' => __( 'Now comes part two, the last one, and it is the nice part: how the site looks. The home page, the catalogue, the product page and the way an order is placed. Mostly pictures — you pick what you like.', 'oc-theme' ),
				'next'  => __( 'Continue to part 2, the last part of the questionnaire', 'oc-theme' ),
			),
			2 => array(
				'title' => __( 'The look of the site', 'oc-theme' ),
			),
		);
	}

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
				'part'    => 1,
				'title'   => __( 'About the business', 'oc-theme' ),
				'screens' => array(
					array(
						'id'     => '1a',
						'title'  => __( 'The business', 'oc-theme' ),
						'intro'  => __( 'These details appear on the site, in the emails customers receive and on the legal pages. Everything can be changed later.', 'oc-theme' ),
						'fields' => array( 'existing_has', 'existing_url', 'brand_name', 'legal_name', 'company_id', 'domain', 'phone', 'whatsapp', 'email_service', 'email_orders', 'has_store', 'branches_mode', 'address_street', 'address_city', 'hours', 'branches', 'instagram', 'facebook', 'tiktok', 'youtube' ),
					),
					array(
						'id'     => '1b',
						'title'  => __( 'Accessibility', 'oc-theme' ),
						'intro'  => __( 'The law requires every site to publish the accessibility arrangements of the business. Tick what you have; we write the statement.', 'oc-theme' ),
						'fields' => array( 'a11y_access', 'branch_access', 'a11y_name', 'a11y_phone', 'a11y_email' ),
					),
				),
			),
			array(
				'n'       => 2,
				'part'    => 1,
				'title'   => __( 'Content pages', 'oc-theme' ),
				'screens' => array(
					array(
						'id'     => '2a',
						'title'  => __( 'About us', 'oc-theme' ),
						'intro'  => __( 'A few lines about who you are. This becomes the About page and the short text on the home page.', 'oc-theme' ),
						'fields' => array( 'about_mode', 'about_url', 'about_text', 'about_points', 'about_image' ),
					),
					array(
						'id'     => '2b',
						'title'  => __( 'Legal pages', 'oc-theme' ),
						'intro'  => __( 'Terms of sale, privacy policy and accessibility statement. For each one: upload your own file, take it from the site you have today, or use the wording we prepared.', 'oc-theme' ),
						'fields' => array( 'terms_mode', 'terms_url', 'terms_file', 'terms_kind', 'terms_custom', 'terms_bulky', 'terms_consent', 'privacy_mode', 'privacy_url', 'privacy_file', 'privacy_consent', 'a11y_mode', 'a11y_url', 'a11y_file', 'a11y_consent' ),
					),
				),
			),
			array(
				'n'       => 8,
				'part'    => 1,
				'title'   => __( 'Thank-you page', 'oc-theme' ),
				'screens' => array(
					array(
						'id'     => '8a',
						'title'  => __( 'The thank-you page', 'oc-theme' ),
						'intro'  => __( 'The page a customer lands on the moment the order goes through. It already shows the order and what happens next; here you choose what else it carries.', 'oc-theme' ),
						'fields' => array( 'ty_contact', 'ty_wa_group', 'wa_group', 'ty_wa_title', 'ty_social', 'ty_survey', 'ty_referral', 'ty_ref_friend', 'ty_ref_reward' ),
					),
				),
			),
			array(
				'n'       => 3,
				'part'    => 2,
				'title'   => __( 'The home page', 'oc-theme' ),
				'screens' => array(
					array(
						'id'      => '3a',
						'title'   => __( 'What stands on the home page', 'oc-theme' ),
						'intro'   => __( 'This is the page, from top to bottom. Move a part, hide one you do not need, or add another of the same kind. The drawing beside you is the page as you are arranging it.', 'oc-theme' ),
						'preview' => 'home',
						'fields'  => array( 'home_layout' ),
					),
					array(
						'id'      => '3b',
						'title'   => __( 'The main banner', 'oc-theme' ),
						'intro'   => __( 'The big picture at the top of the home page. This is where an offer goes, or a launch, or a new collection. Everything you type here appears on the drawing beside you.', 'oc-theme' ),
						'preview' => 'banner',
						'fields'  => array( 'banner_media', 'home_banner', 'banner_video', 'banner_title', 'banner_cta', 'banner_link', 'banner_url' ),
					),
					array(
						'id'     => '3c',
						'title'  => __( 'The menu and the banner', 'oc-theme' ),
						'intro'  => __( 'Two ways the menu can meet the picture under it. Which one do you like more?', 'oc-theme' ),
						'fields' => array( 'home_header' ),
					),
				),
			),
			array(
				'n'       => 4,
				'part'    => 2,
				'title'   => __( 'The catalogue', 'oc-theme' ),
				'screens' => array(
					array(
						'id'      => '4a',
						'title'   => __( 'The category page', 'oc-theme' ),
						'intro'   => __( 'The page a customer lands on from the menu. Every answer here changes the drawing beside you.', 'oc-theme' ),
						'preview' => 'category',
						'fields'  => array( 'cat_hero', 'cat_cols', 'cat_per_page', 'cat_filters', 'card_sale', 'card_new', 'card_new_days', 'card_excerpt', 'cat_paging' ),
					),
				),
			),
			array(
				'n'       => 5,
				'part'    => 2,
				'title'   => __( 'The product page', 'oc-theme' ),
				'screens' => array(
					array(
						'id'     => '5a',
						'title'  => __( 'The product page', 'oc-theme' ),
						'intro'  => __( 'Where the decision is made. The pictures on one side, everything the buyer needs on the other.', 'oc-theme' ),
						'fields' => array( 'prod_side', 'prod_gallery', 'prod_qty', 'prod_sku', 'prod_ship_tab' ),
					),
				),
			),
			array(
				'n'       => 6,
				'part'    => 2,
				'title'   => __( 'Brands', 'oc-theme' ),
				'screens' => array(
					array(
						'id'     => '6a',
						'title'  => __( 'Brands', 'oc-theme' ),
						'intro'  => __( 'Only worth filling in if you sell goods of brands with a name of their own.', 'oc-theme' ),
						'fields' => array( 'brands_has', 'brand_logo', 'brand_card', 'brand_product' ),
					),
				),
			),
		);
	}

	/**
	 * The parts a home page can be built from, and what each one lets the
	 * customer set from the list itself. Anything deeper is asked later, on
	 * the screens that follow the arranging.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function home_blocks(): array {
		return array(
			'banner'     => array(
				'label' => __( 'Main banner', 'oc-theme' ),
				'once'  => true,
				'note'  => __( 'The big picture at the top. Its contents come on the next screen.', 'oc-theme' ),
			),
			'content'    => array(
				'label'    => __( 'A content area', 'oc-theme' ),
				'variants' => array(
					'words'  => __( 'A heading and words', 'oc-theme' ),
					'video'  => __( 'Words, a picture, and a film over it', 'oc-theme' ),
					'two'    => __( 'Words and two pictures', 'oc-theme' ),
					'sticky' => __( 'Words that stay while the pictures move', 'oc-theme' ),
				),
			),
			'products'   => array(
				'label' => __( 'A row of products', 'oc-theme' ),
				'title' => true,
			),
			'marquee'    => array(
				'label' => __( 'A line of messages running across', 'oc-theme' ),
				'text'  => true,
			),
			'categories' => array(
				'label' => __( 'The categories', 'oc-theme' ),
				'title' => true,
			),
			'look'       => array(
				'label' => __( 'Shop the look', 'oc-theme' ),
				'title' => true,
				'note'  => __( 'A styled photograph with the products marked on it.', 'oc-theme' ),
			),
			'posts'      => array(
				'label' => __( 'From the magazine', 'oc-theme' ),
				'title' => true,
			),
			'icons'      => array(
				'label' => __( 'Reasons to buy', 'oc-theme' ),
				'note'  => __( 'Delivery, returns, a secure purchase. You fill these in later.', 'oc-theme' ),
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
			'group'   => __( 'Do you have a website today?', 'oc-theme' ),
			'label'   => '',
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
			'group'    => __( 'Business details', 'oc-theme' ),
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
			'help'    => __( 'The name of the company or business as registered with the tax authority and, for a company, the companies registrar.', 'oc-theme' ),
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
			'when'   => array( 'existing_has', array( 'no' ) ),
			'target' => array( 'state', 'domain' ),
		);

		$f['phone'] = array(
			'type'     => 'phone',
			'group'    => __( 'How customers reach you', 'oc-theme' ),
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
			'type'   => 'email',
			'label'  => __( 'Where should new-order notices go?', 'oc-theme' ),
			'help'   => __( 'Every order sends a notice here. Empty = the customer service email.', 'oc-theme' ),
			'target' => array( 'call', 'email_orders' ),
		);

		$f['has_store'] = array(
			'type'    => 'choice',
			'group'   => __( 'Store and branches', 'oc-theme' ),
			'label'   => __( 'Do you have a store or showroom open to the public?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'call', 'has_store' ),
		);

		$f['address_street'] = array(
			'type'     => 'text',
			'label'    => __( 'Street and number', 'oc-theme' ),
			'when'     => array(
				array( 'has_store', array( 'yes' ) ),
				array( 'branches_mode', array( 'one' ) ),
			),
			'required' => true,
			'target'   => array( 'call', 'address' ),
		);

		$f['address_city'] = array(
			'type'     => 'text',
			'label'    => __( 'City', 'oc-theme' ),
			'when'     => array(
				array( 'has_store', array( 'yes' ) ),
				array( 'branches_mode', array( 'one' ) ),
			),
			'required' => true,
			'target'   => array( 'call', 'address' ),
		);

		$f['hours'] = array(
			'type'   => 'hours',
			'label'  => __( 'Opening hours', 'oc-theme' ),
			'help'   => __( 'Pick the days, then the hours. Add a line for days with different hours.', 'oc-theme' ),
			'when'   => array(
				array( 'has_store', array( 'yes' ) ),
				array( 'branches_mode', array( 'one' ) ),
			),
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
			'type'     => 'url',
			'label'    => __( 'The group invite link', 'oc-theme' ),
			'help'     => __( 'In WhatsApp: the group → Invite via link → Copy link.', 'oc-theme' ),
			'dir'      => 'ltr',
			'default'  => (string) $contact['wa_group'],
			'when'     => array( 'ty_wa_group', array( 'yes' ) ),
			'required' => true,
			'target'   => array( 'option', 'oc_contact', 'wa_group' ),
		);

		/* ---- 1b: accessibility ---- */

		$f['branches_mode'] = array(
			'type'    => 'choice',
			'label'   => __( 'How many branches?', 'oc-theme' ),
			'help'    => __( 'With more than one we build a branches page: every branch gets its own page with its address, hours and a map, and customers can pick one for collection at the checkout.', 'oc-theme' ),
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
			'help'     => __( 'One card per branch. A picture is not required now, but a branch with one looks far better on the site.', 'oc-theme' ),
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
					'type'     => 'text',
					'label'    => __( 'Street and number', 'oc-theme' ),
					'required' => true,
				),
				'city'    => array(
					'type'     => 'text',
					'label'    => __( 'City', 'oc-theme' ),
					'required' => true,
				),
				'phone'   => array(
					'type'  => 'phone',
					'label' => __( 'Phone', 'oc-theme' ),
				),
				'hours'   => array(
					'type'        => 'textarea',
					'label'       => __( 'Opening hours', 'oc-theme' ),
					'placeholder' => __( 'Sunday–Thursday 9:00–19:00', 'oc-theme' ),
				),
				'about'   => array(
					'type'  => 'text',
					'label' => __( 'A line about this branch', 'oc-theme' ),
					'help'  => __( 'Optional. For example: parking in the building, or the branch with the workshop.', 'oc-theme' ),
				),
				'image'   => array(
					'type'   => 'file',
					'accept' => 'image',
					'label'  => __( 'A picture of the branch', 'oc-theme' ),
				),
			),
			'target'   => array( 'call', 'branches' ),
		);

		$f['a11y_access'] = array(
			'type'    => 'checks',
			'group'   => __( 'The premises', 'oc-theme' ),
			'label'   => __( 'Accessibility arrangements in the store', 'oc-theme' ),
			'help'    => __( 'Tick everything you have. Nothing ticked is fine too; the statement says so honestly.', 'oc-theme' ),
			'options' => Contact::access_items(),
			'when'    => array( 'branches_mode', array( 'one' ) ),
			'default' => array_keys( array_filter( is_array( $contact['a11y_access'] ) ? $contact['a11y_access'] : array() ) ),
			'target'  => array( 'call', 'a11y_access' ),
		);

		$f['branch_access'] = array(
			'type'    => 'branch_access',
			'label'   => __( 'Accessibility arrangements at each branch', 'oc-theme' ),
			'help'    => __( 'Tick per branch. Each one shows its own row in the statement.', 'oc-theme' ),
			'options' => Contact::access_items(),
			'of'      => 'branches',
			'when'    => array( 'branches_mode', array( 'many' ) ),
			'target'  => array( 'call', 'branches' ),
		);

		$f['a11y_name'] = array(
			'type'     => 'text',
			'group'    => __( 'The accessibility officer', 'oc-theme' ),
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
			'type'         => 'choice',
			'label'        => __( 'The About text', 'oc-theme' ),
			'options'      => array(
				'paste' => __( 'I have a text, I will paste it', 'oc-theme' ),
				'link'  => __( 'It is on my current site — take it from there', 'oc-theme' ),
				'write' => __( 'Write it for me from a few points', 'oc-theme' ),
			),
			'options_when' => array( 'link' => array( 'existing_has', array( 'yes' ) ) ),
			'default'      => 'paste',
			'target'       => array( 'call', 'about' ),
		);

		$f['about_url'] = array(
			'type'     => 'url',
			'label'    => __( 'The address of the page on your site', 'oc-theme' ),
			'help'     => __( 'Paste the address of the page and we take the text from it. Nothing to download and nothing to upload.', 'oc-theme' ),
			'dir'      => 'ltr',
			'when'     => array( 'about_mode', array( 'link' ) ),
			'required' => true,
			'target'   => array( 'call', 'about' ),
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
			'upload'   => __( 'I have my own — I will upload the file', 'oc-theme' ),
			'link'     => __( 'It is on my current site — take it from there', 'oc-theme' ),
			'template' => __( 'Use the ready-made wording you prepared', 'oc-theme' ),
		);

		$f['terms_mode'] = array(
			'type'         => 'choice',
			'group'        => __( 'Terms of sale', 'oc-theme' ),
			'label'        => __( 'Where should the terms come from?', 'oc-theme' ),
			'options'      => $legal_mode,
			'options_when' => array( 'link' => array( 'existing_has', array( 'yes' ) ) ),
			'default'      => 'upload',
			'target'       => array( 'call', 'legal_terms' ),
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
			'when'    => array(
				array( 'terms_mode', array( 'template' ) ),
				array( 'terms_kind', array( 'general' ) ),
			),
			'target'  => array( 'call', 'legal_terms' ),
		);

		$f['terms_bulky'] = array(
			'type'    => 'choice',
			'label'   => __( 'Do you deliver large items?', 'oc-theme' ),
			'help'    => __( 'Furniture, appliances: the terms then cover access, stairs, a crane and assembly.', 'oc-theme' ),
			'options' => $yesno,
			'default' => (int) $contact['terms_bulky'] ? 'yes' : 'no',
			'when'    => array(
				array( 'terms_mode', array( 'template' ) ),
				array( 'terms_kind', array( 'general' ) ),
			),
			'target'  => array( 'call', 'legal_terms' ),
		);

		$f['terms_url'] = array(
			'type'     => 'url',
			'label'    => __( 'The address of the page on your site', 'oc-theme' ),
			'help'     => __( 'Paste the address of the page and we take the text from it. Nothing to download and nothing to upload.', 'oc-theme' ),
			'dir'      => 'ltr',
			'when'     => array( 'terms_mode', array( 'link' ) ),
			'required' => true,
			'target'   => array( 'call', 'legal_terms' ),
		);

		$f['terms_file'] = array(
			'type'     => 'file',
			'accept'   => 'doc',
			'label'    => __( 'Your terms (Word or PDF)', 'oc-theme' ),
			'help'     => __( 'Do not have one? Pick the ready-made wording above and we write the page for you.', 'oc-theme' ),
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
			'type'         => 'choice',
			'group'        => __( 'Privacy policy', 'oc-theme' ),
			'label'        => __( 'Where should the privacy policy come from?', 'oc-theme' ),
			'options'      => $legal_mode,
			'options_when' => array( 'link' => array( 'existing_has', array( 'yes' ) ) ),
			'default'      => 'upload',
			'target'       => array( 'call', 'legal_privacy' ),
		);

		$f['privacy_url'] = array(
			'type'     => 'url',
			'label'    => __( 'The address of the page on your site', 'oc-theme' ),
			'help'     => __( 'Paste the address of the page and we take the text from it. Nothing to download and nothing to upload.', 'oc-theme' ),
			'dir'      => 'ltr',
			'when'     => array( 'privacy_mode', array( 'link' ) ),
			'required' => true,
			'target'   => array( 'call', 'legal_privacy' ),
		);

		$f['privacy_file'] = array(
			'type'     => 'file',
			'accept'   => 'doc',
			'label'    => __( 'Your privacy policy (Word or PDF)', 'oc-theme' ),
			'help'     => __( 'Do not have one? Pick the ready-made wording above and we write the page for you.', 'oc-theme' ),
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
			'type'         => 'choice',
			'group'        => __( 'Accessibility statement', 'oc-theme' ),
			'label'        => __( 'Where should the accessibility statement come from?', 'oc-theme' ),
			'options'      => $legal_mode,
			'options_when' => array( 'link' => array( 'existing_has', array( 'yes' ) ) ),
			'default'      => 'upload',
			'target'       => array( 'call', 'legal_a11y' ),
		);

		$f['a11y_url'] = array(
			'type'     => 'url',
			'label'    => __( 'The address of the page on your site', 'oc-theme' ),
			'help'     => __( 'Paste the address of the page and we take the text from it. Nothing to download and nothing to upload.', 'oc-theme' ),
			'dir'      => 'ltr',
			'when'     => array( 'a11y_mode', array( 'link' ) ),
			'required' => true,
			'target'   => array( 'call', 'legal_a11y' ),
		);

		$f['a11y_file'] = array(
			'type'     => 'file',
			'accept'   => 'doc',
			'label'    => __( 'Your accessibility statement (Word or PDF)', 'oc-theme' ),
			'help'     => __( 'Do not have one? Pick the ready-made wording above and we write the page for you.', 'oc-theme' ),
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

		$f['ty_contact'] = array(
			'type'    => 'choice',
			'group'   => __( 'Getting in touch', 'oc-theme' ),
			'label'   => __( 'Show your phone, email and WhatsApp on it?', 'oc-theme' ),
			'help'    => __( 'Under the thank-you message, so a customer with a question does not have to look for you.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'option', 'oc_thankyou', 'contact' ),
		);

		$f['ty_wa_group'] = array(
			'type'    => 'choice',
			'label'   => __( 'Invite the buyer to your WhatsApp group?', 'oc-theme' ),
			'help'    => __( 'On the thank-you page we encourage customers to join your WhatsApp group — the moment they are happiest with you.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'option', 'oc_thankyou', 'wa_group' ),
		);

		$f['ty_wa_title'] = array(
			'type'        => 'text',
			'label'       => __( 'The wording on the invitation', 'oc-theme' ),
			'placeholder' => __( 'Join our WhatsApp group', 'oc-theme' ),
			'help'        => __( 'Leave it empty to use the wording shown here.', 'oc-theme' ),
			'when'        => array( 'ty_wa_group', array( 'yes' ) ),
			'target'      => array( 'option', 'oc_thankyou', 'wa_title' ),
		);

		$f['ty_social'] = array(
			'type'    => 'choice',
			'group'   => __( 'What else the page offers', 'oc-theme' ),
			'label'   => __( '"Follow us" buttons?', 'oc-theme' ),
			'help'    => __( 'We show the links to your social profiles and invite the buyer to follow you.', 'oc-theme' ),
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
			'default' => 'yes',
			'target'  => array( 'option', 'oc_thankyou', 'survey' ),
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

		/* ---- 3a: the home page ---- */

		$f['home_layout'] = array(
			'type'    => 'layout',
			'label'   => '',
			'blocks'  => self::home_blocks(),
			'default' => array(
				array(
					'type' => 'banner',
					'on'   => 1,
				),
				array(
					'type'    => 'content',
					'on'      => 1,
					'variant' => 'words',
				),
				array(
					'type'  => 'products',
					'on'    => 1,
					'title' => __( 'New in', 'oc-theme' ),
				),
				array(
					'type' => 'marquee',
					'on'   => 1,
					'text' => __( 'Free delivery over 400 ILS | 12 payments, no interest | Never tested on animals', 'oc-theme' ),
				),
				array(
					'type'  => 'categories',
					'on'    => 1,
					'title' => __( 'Our categories', 'oc-theme' ),
				),
				array(
					'type'  => 'look',
					'on'    => 1,
					'title' => __( 'Get the look', 'oc-theme' ),
				),
				array(
					'type'  => 'products',
					'on'    => 1,
					'title' => __( 'Best sellers', 'oc-theme' ),
				),
				array(
					'type'  => 'posts',
					'on'    => 1,
					'title' => __( 'From the magazine', 'oc-theme' ),
				),
				array(
					'type' => 'icons',
					'on'   => 1,
				),
			),
			'target'  => array( 'call', 'home' ),
		);

		/* ---- 3b: the banner ---- */

		$f['banner_media'] = array(
			'type'    => 'choice',
			'label'   => __( 'What stands in the banner?', 'oc-theme' ),
			'options' => array(
				'image' => __( 'A picture', 'oc-theme' ),
				'video' => __( 'A short film', 'oc-theme' ),
			),
			'default' => 'image',
			'target'  => array( 'call', 'home' ),
		);

		$f['home_banner'] = array(
			'type'   => 'file',
			'accept' => 'image',
			'label'  => __( 'The picture', 'oc-theme' ),
			'help'   => __( 'The widest, best one you have — 1920 pixels across or more. It can be changed later.', 'oc-theme' ),
			'when'   => array( 'banner_media', array( 'image' ) ),
			'target' => array( 'call', 'home' ),
		);

		$f['banner_video'] = array(
			'type'        => 'url',
			'label'       => __( 'The address of the film', 'oc-theme' ),
			'help'        => __( 'An mp4 file. A film plays without sound and repeats itself, so keep it short.', 'oc-theme' ),
			'placeholder' => 'https://',
			'dir'         => 'ltr',
			'when'        => array( 'banner_media', array( 'video' ) ),
			'target'      => array( 'call', 'home' ),
		);

		$f['banner_title'] = array(
			'type'        => 'text',
			'label'       => __( 'The headline on the picture', 'oc-theme' ),
			'placeholder' => 'NEW COLLECTION',
			'target'      => array( 'call', 'home' ),
		);

		$f['banner_cta'] = array(
			'type'        => 'text',
			'label'       => __( 'What the button says', 'oc-theme' ),
			'placeholder' => 'SHOP NOW',
			'target'      => array( 'call', 'home' ),
		);

		$f['banner_link'] = array(
			'type'    => 'choice',
			'label'   => __( 'Where does the button lead?', 'oc-theme' ),
			'options' => array(
				'shop' => __( 'To the shop, all the products', 'oc-theme' ),
				'url'  => __( 'To another page — I will give the address', 'oc-theme' ),
			),
			'default' => 'shop',
			'target'  => array( 'call', 'home' ),
		);

		$f['banner_url'] = array(
			'type'        => 'url',
			'label'       => __( 'The address', 'oc-theme' ),
			'placeholder' => 'https://',
			'dir'         => 'ltr',
			'when'        => array( 'banner_link', array( 'url' ) ),
			'target'      => array( 'call', 'home' ),
		);

		/* ---- 3c: the menu over the banner ---- */

		$f['home_header'] = array(
			'type'    => 'gallery',
			'label'   => '',
			'show'    => 'header',
			'options' => array(
				'home' => __( 'The menu sits on the picture', 'oc-theme' ),
				'none' => __( 'The menu sits above the picture', 'oc-theme' ),
			),
			'default' => 'home',
			'target'  => array( 'mod', 'oc_header_transparent' ),
		);

		/* ---- 4a: the category page ---- */

		$f['cat_hero'] = array(
			'type'    => 'choice',
			'group'   => __( 'The top of the page', 'oc-theme' ),
			'label'   => __( 'What stands above the products?', 'oc-theme' ),
			'options' => array(
				'none'  => __( 'Nothing — straight to the products', 'oc-theme' ),
				'full'  => __( 'A picture across the whole width', 'oc-theme' ),
				'split' => __( 'Half picture, half words', 'oc-theme' ),
			),
			'default' => 'none',
			'target'  => array( 'mod', 'oc_chero_layout' ),
		);

		$f['cat_cols'] = array(
			'type'    => 'stepper',
			'group'   => __( 'The shelf of products', 'oc-theme' ),
			'label'   => __( 'How many products in a row?', 'oc-theme' ),
			'help'    => __( 'On a phone it is always two. This is the computer.', 'oc-theme' ),
			'min'     => 2,
			'max'     => 5,
			'default' => '3',
			'target'  => array( 'mod', 'oc_catalog_cols' ),
		);

		$f['cat_per_page'] = array(
			'type'        => 'number',
			'label'       => __( 'How many on a page?', 'oc-theme' ),
			'placeholder' => '40',
			'suffix'      => __( 'products', 'oc-theme' ),
			'min'         => 6,
			'max'         => 200,
			'default'     => 40,
			'target'      => array( 'mod', 'oc_catalog_per_page' ),
		);

		$f['cat_filters'] = array(
			'type'    => 'choice',
			'label'   => __( 'Where does the filter sit?', 'oc-theme' ),
			'options' => array(
				'sidebar' => __( 'Down the side', 'oc-theme' ),
				'topbar'  => __( 'Above the products', 'oc-theme' ),
				'off'     => __( 'No filter', 'oc-theme' ),
			),
			'default' => 'sidebar',
			'target'  => array( 'call', 'filters' ),
		);

		$f['cat_paging'] = array(
			'type'    => 'choice',
			'label'   => __( 'How does a customer reach the next page?', 'oc-theme' ),
			'options' => array(
				'infinite' => __( 'It opens by itself as they scroll', 'oc-theme' ),
				'numbers'  => __( 'They see page numbers and press one', 'oc-theme' ),
			),
			'default' => 'infinite',
			'target'  => array( 'mod', 'oc_catalog_paging' ),
		);

		$f['card_sale'] = array(
			'type'    => 'choice',
			'group'   => __( 'What a product card carries', 'oc-theme' ),
			'label'   => __( 'How is a discount marked?', 'oc-theme' ),
			'options' => array(
				'percent' => __( 'How many percent off', 'oc-theme' ),
				'text'    => __( 'The word Sale', 'oc-theme' ),
				'none'    => __( 'Not marked', 'oc-theme' ),
			),
			'default' => 'percent',
			'target'  => array( 'mod', 'oc_card_sale_badge' ),
		);

		$f['card_new'] = array(
			'type'    => 'choice',
			'label'   => __( 'A "new" label on a product that just arrived?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'mod', 'oc_label_new' ),
		);

		$f['card_new_days'] = array(
			'type'        => 'number',
			'label'       => __( 'For how long does it count as new?', 'oc-theme' ),
			'placeholder' => '30',
			'suffix'      => __( 'days from the day it went up', 'oc-theme' ),
			'min'         => 1,
			'max'         => 365,
			'default'     => 30,
			'when'        => array( 'card_new', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_label_new_days' ),
		);

		$f['card_excerpt'] = array(
			'type'    => 'choice',
			'label'   => __( 'A line of description under the name?', 'oc-theme' ),
			'help'    => __( 'Helpful when the name alone does not say what it is.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'mod', 'oc_card_excerpt' ),
		);

		/* ---- 5a: the product page ---- */

		$f['prod_side'] = array(
			'type'    => 'pick',
			'label'   => __( 'Which side are the pictures on?', 'oc-theme' ),
			'options' => array(
				'gallery-start' => __( 'The side the page starts from', 'oc-theme' ),
				'gallery-end'   => __( 'The other side', 'oc-theme' ),
			),
			'art'     => array(
				'gallery-start' => 'prod_right',
				'gallery-end'   => 'prod_left',
			),
			'default' => 'gallery-start',
			'target'  => array( 'mod', 'oc_product_layout_side' ),
		);

		$f['prod_gallery'] = array(
			'type'    => 'pick',
			'label'   => __( 'Where do the small pictures sit?', 'oc-theme' ),
			'options' => array(
				'thumbs-side'  => __( 'Beside the big one', 'oc-theme' ),
				'thumbs-under' => __( 'Under it', 'oc-theme' ),
				'grid'         => __( 'All of them, one under the other', 'oc-theme' ),
			),
			'art'     => array(
				'thumbs-side'  => 'gal_side',
				'thumbs-under' => 'gal_under',
				'grid'         => 'gal_grid',
			),
			'default' => 'thumbs-side',
			'target'  => array( 'mod', 'oc_gallery_preset' ),
		);

		$f['prod_qty'] = array(
			'type'    => 'choice',
			'label'   => __( 'A quantity box beside the buy button?', 'oc-theme' ),
			'help'    => __( 'Worth it when people buy several of the same thing. A buyer can change the quantity in the cart either way.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'mod', 'oc_atc_qty' ),
		);

		$f['prod_sku'] = array(
			'type'    => 'choice',
			'label'   => __( 'Show the product code?', 'oc-theme' ),
			'help'    => __( 'The number you use in the warehouse. Shoppers rarely need it; a trade customer does.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'mod', 'oc_product_sku' ),
		);

		$f['prod_ship_tab'] = array(
			'type'    => 'choice',
			'label'   => __( 'A "delivery and returns" tab on every product?', 'oc-theme' ),
			'help'    => __( 'We open it with a short text drawn from your terms. You can rewrite it whenever you like.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'call', 'ship_tab' ),
		);

		/* ---- 6a: brands ---- */

		$f['brands_has'] = array(
			'type'    => 'choice',
			'label'   => __( 'Do you sell goods of brands with a name of their own?', 'oc-theme' ),
			'help'    => __( 'Not your own name — the makers whose products you stock.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'state', 'brands' ),
		);

		$f['brand_logo'] = array(
			'type'    => 'pick',
			'label'   => __( 'The brand\'s page, at the top', 'oc-theme' ),
			'options' => array(
				'above'  => __( 'Logo above the words', 'oc-theme' ),
				'beside' => __( 'Logo beside them', 'oc-theme' ),
				'hidden' => __( 'No logo', 'oc-theme' ),
			),
			'art'     => array(
				'above'  => 'brand_above',
				'beside' => 'brand_beside',
				'hidden' => 'brand_none',
			),
			'default' => 'above',
			'when'    => array( 'brands_has', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_brand_logo_pos' ),
		);

		$f['brand_card'] = array(
			'type'    => 'choice',
			'label'   => __( 'Show the brand name on a product card?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'when'    => array( 'brands_has', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_card_brand' ),
		);

		$f['brand_product'] = array(
			'type'    => 'choice',
			'label'   => __( 'And on the product page itself?', 'oc-theme' ),
			'options' => array(
				'text'  => __( 'The name', 'oc-theme' ),
				'image' => __( 'The logo', 'oc-theme' ),
				'none'  => __( 'Neither', 'oc-theme' ),
			),
			'default' => 'text',
			'when'    => array( 'brands_has', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_product_brand' ),
		);

		// Every field carries every key, so readers never test for presence.
		foreach ( $f as $id => $def ) {
			$f[ $id ] = wp_parse_args(
				$def,
				array(
					'type'         => 'text',
					'label'        => '',
					'help'         => '',
					'placeholder'  => '',
					'group'        => '',
					'options'      => array(),
					'options_when' => array(),
					'art'          => array(),
					'notes'        => array(),
					'show'         => '',
					'suffix'       => '',
					'blocks'       => array(),
					'default'      => null,
					'when'         => null,
					'required'     => false,
					'target'       => null,
				)
			);
		}

		self::$fields = $f;

		return $f;
	}

	/**
	 * Every field that actually stands on a screen. A field the steps do
	 * not name is not asked, so it never counts towards the progress and
	 * never holds the submit back.
	 *
	 * @return array<string,bool>
	 */
	public static function asked(): array {
		static $asked = null;

		if ( null !== $asked ) {
			return $asked;
		}

		$asked = array();

		foreach ( self::steps() as $step ) {
			foreach ( $step['screens'] as $screen ) {
				foreach ( $screen['fields'] as $id ) {
					$asked[ (string) $id ] = true;
				}
			}
		}

		return $asked;
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
			case 'layout':
			case 'branch_access':
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

		// A list of rules: every one of them must hold.
		if ( isset( $f['when'][0] ) && is_array( $f['when'][0] ) ) {
			foreach ( $f['when'] as $rule ) {
				if ( ! self::rule_holds( $rule, $values ) ) {
					return false;
				}
			}

			return true;
		}

		return self::rule_holds( $f['when'], $values );
	}

	/**
	 * One condition: [ field, values ] — the field's answer is one of them;
	 * or [ 'a|b|c', 'filled' ] — any of those is not empty.
	 *
	 * @param array<int,mixed>    $rule   The condition.
	 * @param array<string,mixed> $values id => value.
	 */
	private static function rule_holds( array $rule, array $values ): bool {
		list( $dep, $want ) = $rule;

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

		$asked = self::asked();

		foreach ( self::fields() as $id => $f ) {
			if ( ! $f['required'] || empty( $asked[ $id ] ) || ! self::shown( $id, $values ) ) {
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
			case 'pick':
			case 'gallery':
				$v = (string) ( is_scalar( $raw ) ? $raw : '' );

				return isset( $f['options'][ $v ] ) ? $v : '';

			case 'layout':
				$blocks = self::home_blocks();
				$rows   = array();

				foreach ( (array) ( is_array( $raw ) ? $raw : array() ) as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$type = (string) ( $row['type'] ?? '' );

					if ( ! isset( $blocks[ $type ] ) ) {
						continue;
					}

					$keep = array(
						'type' => $type,
						'on'   => empty( $row['on'] ) ? 0 : 1,
					);

					if ( ! empty( $blocks[ $type ]['title'] ) ) {
						$keep['title'] = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
					}

					if ( ! empty( $blocks[ $type ]['text'] ) ) {
						$keep['text'] = sanitize_text_field( (string) ( $row['text'] ?? '' ) );
					}

					if ( ! empty( $blocks[ $type ]['variants'] ) ) {
						$v               = (string) ( $row['variant'] ?? '' );
						$keep['variant'] = isset( $blocks[ $type ]['variants'][ $v ] ) ? $v : (string) array_key_first( $blocks[ $type ]['variants'] );
					}

					$rows[] = $keep;

					if ( count( $rows ) >= 24 ) {
						break;
					}
				}

				return $rows;

			case 'stepper':
				$n   = (int) ( is_scalar( $raw ) ? $raw : 0 );
				$min = isset( $f['min'] ) ? (int) $f['min'] : 1;
				$max = isset( $f['max'] ) ? (int) $f['max'] : 10;

				return (string) max( $min, min( $max, $n ) );

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

			case 'branch_access':
				$out = array();

				foreach ( (array) $raw as $row => $ticked ) {
					$keys = array();

					foreach ( (array) $ticked as $v ) {
						$v = (string) ( is_scalar( $v ) ? $v : '' );

						if ( isset( $f['options'][ $v ] ) ) {
							$keys[] = $v;
						}
					}

					$out[ (int) $row ] = array_values( array_unique( $keys ) );
				}

				ksort( $out );

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
	 * @return array{steps:array<int,array<string,mixed>>,parts:array<int,array<string,string>>,fields:array<string,array<string,mixed>>}
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
			'parts'  => self::parts(),
			'fields' => $fields,
		);
	}
}
