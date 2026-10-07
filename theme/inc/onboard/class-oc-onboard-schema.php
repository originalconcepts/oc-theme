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
						'fields' => array( 'existing_has', 'existing_url', 'brand_name', 'legal_name', 'company_id', 'domain', 'phone', 'whatsapp', 'email_service', 'email_orders', 'has_store', 'branches_mode', 'address_street', 'address_city', 'address_zip', 'hours', 'branches', 'instagram', 'facebook', 'tiktok', 'youtube' ),
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
						'fields' => array( 'about_mode', 'about_url', 'about_text', 'about_points', 'about_written', 'about_image' ),
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
				'n'       => 3,
				'part'    => 2,
				'title'   => __( 'The home page', 'oc-theme' ),
				'screens' => array(
					array(
						'id'      => '3a',
						'title'   => __( 'What stands on the home page', 'oc-theme' ),
						'intro'   => __( 'Here you decide what your home page offers a visitor. On this side stands everything the page is made of: give a part its heading, move it up or down, throw one away, add another. Beside it the page draws itself as you go. Walk down the list, give every part its heading or its words, and carry on.', 'oc-theme' ),
						'intro_m' => __( 'On a phone the two do not fit side by side, so the two buttons above switch between the list and the drawing.', 'oc-theme' ),
						'preview' => 'home',
						'fields'  => array( 'home_layout' ),
					),
					array(
						'id'      => '3b',
						'title'   => __( 'The header and the menu', 'oc-theme' ),
						'intro'   => __( 'The strip every page of the shop wears. Whatever you write here, the sketch beside you puts in its place.', 'oc-theme' ),
						'intro_m' => __( 'On a phone the two do not fit side by side, so the two buttons above switch between the questions and the drawing.', 'oc-theme' ),
						'preview' => 'top',
						'fields'  => array( 'top_bar', 'top_bar_1', 'top_bar_2', 'top_bar_3', 'site_logo', 'header_look', 'site_menu' ),
					),
					array(
						'id'      => '3c',
						'title'   => __( 'The main banner', 'oc-theme' ),
						'intro'   => __( 'The big picture at the top of the home page: an offer, a launch, a new collection. First, where the menu stands over it.', 'oc-theme' ),
						'preview' => 'banner',
						'fields'  => array( 'home_header', 'logo_light', 'banner_media', 'home_banner', 'banner_video', 'banner_title', 'banner_sub', 'banner_cta', 'banner_link', 'banner_cat' ),
					),
					array(
						'id'      => '3d',
						'title'   => __( 'The categories on the home page', 'oc-theme' ),
						'intro'   => __( 'Out of the menu you just built, which aisles are worth a place of their own on the front page.', 'oc-theme' ),
						'preview' => 'band:categories',
						'fields'  => array( 'home_cats' ),
					),
					array(
						'id'      => '3e',
						'title'   => __( 'Shop the Look', 'oc-theme' ),
						'intro'   => __( 'A photograph of the real thing, where several of your products stand together. A visitor presses what they like on it and reaches the product.', 'oc-theme' ),
						'preview' => 'band:look',
						'fields'  => array( 'look_shot' ),
					),
					array(
						'id'      => '3f',
						'title'   => __( 'The content areas', 'oc-theme' ),
						'intro'   => __( 'A content area is a few words and a picture, standing between the shelves: your story, a promise, a collection. One block of questions for each one you kept.', 'oc-theme' ),
						'preview' => 'band:content',
						'fields'  => array( 'home_content' ),
					),
					array(
						'id'      => '3g',
						'title'   => __( 'Reasons to buy from you', 'oc-theme' ),
						'intro'   => __( 'The little row of promises near the bottom: a drawing, two or three words, and a line explaining. Four at the most — fewer and stronger reads better.', 'oc-theme' ),
						'preview' => 'band:icons',
						'fields'  => array( 'home_icons' ),
					),
					array(
						'id'      => '3h',
						'title'   => __( 'Questions and answers', 'oc-theme' ),
						'intro'   => __( 'What people ask you before they buy. Leave an answer empty and the question waits for you in the editor.', 'oc-theme' ),
						'preview' => 'band:faq',
						'fields'  => array( 'home_faq' ),
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
						'intro'   => __( 'The page a customer lands on from the menu. Every answer here changes the sketch.', 'oc-theme' ),
						'preview' => 'category',
						'fields'  => array( 'cat_hero', 'cat_cols', 'cat_oos_last', 'cat_filters', 'card_atc', 'card_sale', 'card_new', 'card_new_days', 'card_excerpt', 'cat_paging' ),
					),
				),
			),
			array(
				'n'       => 5,
				'part'    => 2,
				'title'   => __( 'Brands', 'oc-theme' ),
				'screens' => array(
					array(
						'id'      => '5a',
						'title'   => __( 'Brands', 'oc-theme' ),
						'intro'   => __( 'Do you carry goods of makers with a name of their own? Say no and there is nothing more to answer here.', 'oc-theme' ),
						'preview' => 'band:brands',
						'fields'  => array( 'brands_has', 'brand_list', 'home_brands', 'brand_logo' ),
					),
				),
			),
			array(
				'n'       => 6,
				'part'    => 2,
				'title'   => __( 'The product page', 'oc-theme' ),
				'screens' => array(
					array(
						'id'      => '6a',
						'title'   => __( 'The product page', 'oc-theme' ),
						'intro'   => __( 'Where the decision is made. The pictures on one side, everything the buyer needs on the other. Every answer here changes the drawing beside you.', 'oc-theme' ),
						'intro_m' => __( 'On a phone the two do not fit side by side, so the two buttons above switch between the questions and the drawing.', 'oc-theme' ),
						'preview' => 'product',
						'fields'  => array( 'prod_side', 'prod_gallery', 'prod_sku', 'prod_short', 'prod_stock', 'prod_ship_days', 'prod_lead_min', 'prod_lead_max', 'prod_qty', 'prod_price_btn', 'prod_icons_lay', 'prod_icons', 'prod_tabs_style', 'prod_tabs_pos', 'prod_ship_tab', 'prod_ship_title', 'prod_ship_text', 'prod_tabs', 'prod_contact', 'contact_place', 'contact_name', 'contact_role', 'contact_photo', 'contact_phone', 'contact_from', 'contact_to' ),
					),
					array(
						'id'      => '6b',
						'title'   => __( 'Choices and details', 'oc-theme' ),
						'intro'   => __( 'Two different things, and the drawing beside you shows both. A choice is something the buyer picks before buying — a colour, a size. A detail is something the product simply has, and it sits in a table further down.', 'oc-theme' ),
						'intro_m' => __( 'On a phone the two do not fit side by side, so the two buttons above switch between the questions and the drawing.', 'oc-theme' ),
						'preview' => 'attrs',
						'fields'  => array( 'attr_vary', 'attr_list', 'attr_spec', 'attr_specs' ),
					),
					array(
						'id'      => '6c',
						'title'   => __( 'More products on the product page', 'oc-theme' ),
						'intro'   => __( 'What else the page offers after the product itself. Four things, each with its own job — the drawing shows where each lands.', 'oc-theme' ),
						'intro_m' => __( 'On a phone the two do not fit side by side, so the two buttons above switch between the questions and the drawing.', 'oc-theme' ),
						'preview' => 'linked',
						'fields'  => array( 'xs_on', 'xs_title', 'xs_place', 'xs_style_cart', 'xs_style_sum', 'bt_on', 'bt_title', 'ups_on', 'ups_title', 'rel_title' ),
					),
				),
			),
			array(
				'n'       => 7,
				'part'    => 2,
				'title'   => __( 'Cart and checkout', 'oc-theme' ),
				'screens' => array(
					array(
						'id'      => '7a',
						'title'   => __( 'The cart panel', 'oc-theme' ),
						'intro'   => __( 'The panel that slides out when something is added. Beside you it stands open, with whatever you put in it.', 'oc-theme' ),
						'intro_m' => __( 'On a phone the two do not fit side by side, so the two buttons above switch between the questions and the drawing.', 'oc-theme' ),
						'preview' => 'cart',
						'fields'  => array( 'cart_open', 'cart_ship_bar', 'cart_up', 'cart_up_style', 'cart_up_title', 'cart_up_source', 'cart_up_cat', 'cart_up_max', 'cart_continue', 'cart_coupon', 'cart_btn_total' ),
					),
					array(
						'id'      => '7b',
						'title'   => __( 'The checkout', 'oc-theme' ),
						'intro'   => __( 'The last page before the money. Everything here is already set to what works for most shops — change what is not true of yours.', 'oc-theme' ),
						'intro_m' => __( 'On a phone the two do not fit side by side, so the two buttons above switch between the questions and the drawing.', 'oc-theme' ),
						'preview' => 'checkout',
						'fields'  => array( 'ck_multi', 'ck_other', 'ck_phone2', 'ck_req', 'ck_summary', 'ck_fold', 'ck_coupon', 'ck_btn_total' ),
					),
					array(
						'id'     => '7c',
						'title'  => __( 'Delivery, collection and payment', 'oc-theme' ),
						'intro'  => __( 'What it costs to send, whether people can come and collect, and who takes the money. No drawing here — none of it shows on a page.', 'oc-theme' ),
						'fields' => array( 'ship_price', 'ship_title', 'ship_free_over', 'pickup_on', 'pickup_title', 'pickup_branches', 'pay_gw', 'pay_none', 'pay_when', 'cc_terminal', 'cc_user', 'cc_pass', 'pp_api', 'pp_secret', 'pp_page', 'pay_other', 'pay_split', 'pay_max', 'pay_charge', 'pay_more', 'pay_cash_pickup' ),
					),
				),
			),
			array(
				'n'       => 8,
				'part'    => 2,
				'title'   => __( 'Thank-you page', 'oc-theme' ),
				'screens' => array(
					array(
						'id'      => '8a',
						'title'   => __( 'The thank-you page', 'oc-theme' ),
						'intro'   => __( 'The page a customer lands on the moment the order goes through. It already shows the order and what happens next; here you choose what else it carries.', 'oc-theme' ),
						'intro_m' => __( 'On a phone the two do not fit side by side, so the two buttons above switch between the questions and the drawing.', 'oc-theme' ),
						'preview' => 'thanks',
						'fields'  => array( 'ty_contact', 'ty_wa_group', 'wa_group', 'ty_wa_title', 'ty_social', 'ty_survey', 'ty_referral', 'ty_ref_friend', 'ty_ref_reward' ),
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
				'label' => __( 'A banner', 'oc-theme' ),
				'note'  => __( 'A wide picture with a heading and a button. The first one on the page is the main banner, and the next screen asks what goes in it; another one is yours to fill in later.', 'oc-theme' ),
			),
			'content'    => array(
				'label'    => __( 'A content area', 'oc-theme' ),
				'variants' => array(
					'words'   => __( 'A heading and words', 'oc-theme' ),
					'single'  => __( 'One picture or film', 'oc-theme' ),
					'overlap' => __( 'Picture with a little film', 'oc-theme' ),
					'duo'     => __( 'Two pictures, staggered', 'oc-theme' ),
					'canvas'  => __( 'Wide picture, small guest', 'oc-theme' ),
				),
			),
			'products'   => array(
				'label'    => __( 'A row of products', 'oc-theme' ),
				'title'    => true,
				// Nothing is chosen for them: which shelf this is, is a
				// decision about the shop, not a default we can guess.
				'blank'    => true,
				'variants' => array(
					'new'    => __( 'The newest', 'oc-theme' ),
					'sale'   => __( 'Whatever is on offer', 'oc-theme' ),
					'sales'  => __( 'The best sellers', 'oc-theme' ),
					'manual' => __( 'I will choose them myself', 'oc-theme' ),
				),
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
			'brands'     => array(
				'label' => __( 'A row of brands', 'oc-theme' ),
				'title' => true,
			),
			'faq'        => array(
				'label' => __( 'Questions and answers', 'oc-theme' ),
				'title' => true,
			),
			'scrolly'    => array(
				'label' => __( 'A story that unrolls as you scroll', 'oc-theme' ),
				'title' => true,
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

		$f['address_zip'] = array(
			'type'        => 'text',
			'label'       => __( 'Postcode', 'oc-theme' ),
			'help'        => __( 'Not shown anywhere. The delivery companies work it out from the address, and a postcode makes them get it right.', 'oc-theme' ),
			'placeholder' => '7570000',
			'dir'         => 'ltr',
			'when'        => array(
				array( 'has_store', array( 'yes' ) ),
				array( 'branches_mode', array( 'one' ) ),
			),
			'target'      => array( 'call', 'address' ),
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
			// "More than one" means two to begin with; nobody should have to
			// press a button to get the second card.
			'start'    => 2,
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
					'type'  => 'hours',
					'label' => __( 'Opening hours', 'oc-theme' ),
					'help'  => __( 'Pick the days, then the hours. Add a line for days with different hours.', 'oc-theme' ),
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
			// The points stay the answer; the writing happens on the About
			// screen, into the field below, where they can read it.
		);

		$f['about_written'] = array(
			'type'   => 'textarea',
			'label'  => __( 'And this is how it reads', 'oc-theme' ),
			'help'   => __( 'Press the button and we turn your points into the text for the page. Read it over — change a word, or ask for it again differently. Nothing is kept until you are happy with it.', 'oc-theme' ),
			'rows'   => 10,
			'when'   => array( 'about_mode', array( 'write' ) ),
			'target' => array( 'call', 'about' ),
			'ai'     => array(
				'what'  => __( 'the "about us" page', 'oc-theme' ),
				'from'  => array( 'brand_name', 'about_points', 'site_menu', 'top_bar_1' ),
				'words' => 130,
			),
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
			'target'  => array( 'call', 'thanks' ),
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

		/* ---- 3b: the header, the menu and the brands ---- */

		$f['top_bar'] = array(
			'type'    => 'choice',
			'group'   => __( 'The strip above the header', 'oc-theme' ),
			'label'   => __( 'A thin strip at the very top, with a line of news?', 'oc-theme' ),
			'help'    => __( 'This is where free delivery, payments without interest or an opening offer goes.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'mod', 'oc_topbar' ),
		);

		$f['top_bar_1'] = array(
			'type'        => 'text',
			'group'       => __( 'The strip above the header', 'oc-theme' ),
			'label'       => __( 'What it says', 'oc-theme' ),
			'placeholder' => __( 'Free delivery on orders over 400 ILS', 'oc-theme' ),
			'required'    => true,
			'when'        => array( 'top_bar', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_topbar_msg1' ),
		);

		$f['top_bar_2'] = array(
			'type'        => 'text',
			'group'       => __( 'The strip above the header', 'oc-theme' ),
			'label'       => __( 'And a second line, if you have one', 'oc-theme' ),
			'help'        => __( 'More than one line and they take turns.', 'oc-theme' ),
			'placeholder' => __( 'Up to 12 payments, no interest', 'oc-theme' ),
			'when'        => array( 'top_bar', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_topbar_msg2' ),
		);

		$f['top_bar_3'] = array(
			'type'        => 'text',
			'group'       => __( 'The strip above the header', 'oc-theme' ),
			'label'       => __( 'And a third', 'oc-theme' ),
			'placeholder' => __( 'Returns within 14 days', 'oc-theme' ),
			'when'        => array( 'top_bar', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_topbar_msg3' ),
		);

		$f['site_logo'] = array(
			'type'   => 'file',
			'accept' => 'image',
			'group'  => __( 'The header itself', 'oc-theme' ),
			'label'  => __( 'Your logo', 'oc-theme' ),
			'help'   => __( 'The file itself, as large and as sharp as you have it — PNG with a see-through background, or SVG. Not a photograph of a sign, and not one with white corners around it. Not to hand? Carry on without it and send it to us later.', 'oc-theme' ),
			'target' => array( 'mod', 'custom_logo' ),
			'as'     => 'id',
		);

		$f['header_look'] = array(
			'type'    => 'pick',
			'group'   => __( 'The header itself', 'oc-theme' ),
			'label'   => __( 'Where the logo and the menu stand', 'oc-theme' ),
			'options' => array(
				'classic'     => __( 'Logo on the side, menu beside it', 'oc-theme' ),
				'menu-center' => __( 'Logo on the side, menu in the middle', 'oc-theme' ),
				'centred'     => __( 'Logo in the middle, menu under it', 'oc-theme' ),
				'split'       => __( 'Menu, logo in the middle, icons', 'oc-theme' ),
				'burger'      => __( 'A button opens the menu, logo in the middle', 'oc-theme' ),
			),
			'art'     => array(
				'classic'     => 'head_classic',
				'menu-center' => 'head_mcenter',
				'centred'     => 'head_centred',
				'split'       => 'head_split',
				'burger'      => 'head_burger',
			),
			'default' => 'classic',
			'target'  => array( 'mod', 'oc_header_preset' ),
		);

		$f['site_menu'] = array(
			'type'     => 'menu',
			'group'    => __( 'The menu of the shop', 'oc-theme' ),
			'label'    => '',
			'help'     => __( 'The aisles of your shop, in the order you want them read. Each one can hold a few of its own. We open the categories and the menu for you; the products come later.', 'oc-theme' ),
			'required' => true,
			'target'   => array( 'call', 'menu' ),
		);

		$f['brands_has'] = array(
			'type'    => 'choice',
			'label'   => __( 'Do you sell goods of brands with a name of their own?', 'oc-theme' ),
			'help'    => __( 'Not your own name — the makers whose products you stock.', 'oc-theme' ),
			'options' => array(
				'no'  => __( 'No, not a brand shop', 'oc-theme' ),
				'yes' => __( 'Yes', 'oc-theme' ),
			),
			'default' => 'no',
			'quiet'   => true,
			'target'  => array( 'state', 'brands' ),
		);

		$f['brand_list'] = array(
			'type'        => 'textarea',
			'label'       => __( 'The brands you carry', 'oc-theme' ),
			'help'        => __( 'One name per line. We open a page for each of them; the logos can be added later.', 'oc-theme' ),
			'placeholder' => "IKEA\nHAY\nMuuto",
			'rows'        => 6,
			'required'    => true,
			'when'        => array( 'brands_has', array( 'yes' ) ),
			'target'      => array( 'call', 'menu' ),
		);

		/* ---- 3a: the home page ---- */

		$f['home_layout'] = array(
			'type'     => 'layout',
			'label'    => '',
			'blocks'   => self::home_blocks(),
			'required' => true,
			'default'  => array(
				array(
					'type' => 'banner',
					'on'   => 1,
				),
				array(
					'type'  => 'brands',
					'on'    => 1,
					'title' => '',
				),
				array(
					'type'  => 'categories',
					'on'    => 1,
					'title' => '',
				),
				array(
					'type' => 'marquee',
					'on'   => 1,
					'text' => '',
				),
				array(
					'type'    => 'products',
					'on'      => 1,
					'title'   => '',
					'variant' => '',
				),
				array(
					'type'    => 'products',
					'on'      => 1,
					'title'   => '',
					'variant' => '',
				),
				array(
					'type'  => 'look',
					'on'    => 1,
					'title' => '',
				),
				array(
					'type'  => 'posts',
					'on'    => 1,
					'title' => '',
				),
				array(
					'type'    => 'content',
					'on'      => 1,
					'variant' => 'duo',
				),
				array(
					'type' => 'icons',
					'on'   => 1,
				),
			),
			'target'   => array( 'call', 'home' ),
		);

		/* ---- 3b: the banner ---- */

		$f['banner_media'] = array(
			'type'    => 'choice',
			'label'   => __( 'What will be in the main banner?', 'oc-theme' ),
			'options' => array(
				'image' => __( 'A picture', 'oc-theme' ),
				'video' => __( 'A short film', 'oc-theme' ),
			),
			'default' => 'image',
			'target'  => array( 'call', 'home' ),
		);

		$f['home_banner'] = array(
			'type'     => 'file',
			'accept'   => 'image',
			'label'    => __( 'The picture', 'oc-theme' ),
			'help'     => __( 'Upload the best one you have today — the widest, 1920 pixels across or more. You can always change it later.', 'oc-theme' ),
			'required' => true,
			'when'     => array( 'banner_media', array( 'image' ) ),
			'target'   => array( 'call', 'home' ),
		);

		$f['banner_video'] = array(
			'type'     => 'file',
			'accept'   => 'video',
			'label'    => __( 'The film', 'oc-theme' ),
			'help'     => __( 'An mp4 file, up to 32MB. It plays without sound and repeats itself, so keep it short. No film ready yet? Put a picture here instead — a film can take its place at any time.', 'oc-theme' ),
			'required' => true,
			'when'     => array( 'banner_media', array( 'video' ) ),
			'target'   => array( 'call', 'home' ),
		);

		$f['banner_title'] = array(
			'type'        => 'text',
			'label'       => __( 'The headline on the picture', 'oc-theme' ),
			'help'        => __( 'Write something now — it is on the page from the first day, and it can be changed at any time.', 'oc-theme' ),
			'placeholder' => 'NEW COLLECTION',
			'required'    => true,
			'target'      => array( 'call', 'home' ),
		);

		$f['banner_sub'] = array(
			'type'        => 'text',
			'label'       => __( 'A line under the headline', 'oc-theme' ),
			'placeholder' => __( 'The new season, in the shop now', 'oc-theme' ),
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
				'cat'  => __( 'To one of the departments', 'oc-theme' ),
			),
			'default' => 'shop',
			'target'  => array( 'call', 'home' ),
		);

		$f['banner_cat'] = array(
			'type'     => 'from_menu',
			'label'    => __( 'Which one', 'oc-theme' ),
			'one'      => true,
			'required' => true,
			'when'     => array( 'banner_link', array( 'cat' ) ),
			'target'   => array( 'call', 'home' ),
		);

		/* ---- 3c: the menu over the banner ---- */

		$f['home_header'] = array(
			'type'    => 'choice',
			'label'   => __( 'Where does the menu stand over the banner?', 'oc-theme' ),
			'options' => array(
				'home' => __( 'On the picture', 'oc-theme' ),
				'none' => __( 'Above the picture', 'oc-theme' ),
			),
			'default' => 'home',
			'target'  => array( 'mod', 'oc_header_transparent' ),
		);

		// Standing on a photograph, the menu writes itself in white. A logo
		// is a file and cannot: a dark mark on a dark picture disappears.
		// Not required -- plenty of shops have only the one version, and
		// that is a job for us rather than a wall in front of the customer.
		$f['logo_light'] = array(
			'type'   => 'file',
			'accept' => 'image',
			'label'  => __( 'A light version of the logo', 'oc-theme' ),
			'help'   => __( 'Because the menu is standing on the picture, the logo stands on it too, and a dark logo on a dark photograph cannot be seen. Usually that is the same logo in white. Have not got one? Leave it — we make it for you, and the regular logo stands there until we do.', 'oc-theme' ),
			'when'   => array( 'home_header', array( 'home' ) ),
			'target' => array( 'mod', 'oc_logo_transparent' ),
			'as'     => 'url',
		);

		/* ---- 3d-3g: what the parts of the page say ---- */

		$f['look_shot'] = array(
			'type'     => 'file',
			'accept'   => 'image',
			'label'    => __( 'The photograph', 'oc-theme' ),
			'help'     => __( 'One picture with the whole scene in it. In a clothes shop that is a model wearing a complete look; in a furniture shop, a living room where the sofa, the table, the armchair and the lamp all stand together. Marking which product is which comes later, with us.', 'oc-theme' ),
			'required' => true,
			'when'     => array( 'home_layout', 'has:look' ),
			'target'   => array( 'call', 'home' ),
		);

		$f['home_cats'] = array(
			'type'     => 'from_menu',
			'label'    => __( 'Which of them stand on the home page', 'oc-theme' ),
			'help'     => __( 'Departments and what is inside them — tick the ones worth the front row.', 'oc-theme' ),
			'deep'     => true,
			'min'      => 4,
			'required' => true,
			'when'     => array( 'home_layout', 'has:categories' ),
			'target'   => array( 'call', 'home' ),
		);

		$f['home_brands'] = array(
			'type'     => 'from_brands',
			'label'    => __( 'And which brands', 'oc-theme' ),
			'help'     => __( 'The ones whose logos stand on the home page.', 'oc-theme' ),
			'min'      => 6,
			'required' => true,
			'when'     => array(
				array( 'home_layout', 'has:brands' ),
				array( 'brands_has', array( 'yes' ) ),
			),
			'target'   => array( 'call', 'home' ),
		);

		$f['home_content'] = array(
			'type'     => 'repeater',
			'label'    => '',
			'row'      => __( 'Content area', 'oc-theme' ),
			'max'      => 6,
			'fixed'    => true,
			'fold'     => true,
			'fields'   => array(
				'eyebrow' => array(
					'type'        => 'text',
					'label'       => __( 'A small line above', 'oc-theme' ),
					'placeholder' => __( 'OUR STORY', 'oc-theme' ),
					'required'    => true,
				),
				'heading' => array(
					'type'        => 'text',
					'label'       => __( 'The heading', 'oc-theme' ),
					'placeholder' => __( 'Furniture that lasts', 'oc-theme' ),
					'required'    => true,
				),
				'text'    => array(
					'type'     => 'textarea',
					'label'    => __( 'A few lines', 'oc-theme' ),
					'rows'     => 4,
					'required' => true,
				),
				'media'   => array(
					'type'     => 'file',
					'accept'   => 'image',
					'label'    => __( 'The picture', 'oc-theme' ),
					'required' => true,
				),
				'media2'  => array(
					'type'     => 'file',
					'accept'   => 'image',
					'label'    => __( 'The second picture', 'oc-theme' ),
					'required' => true,
					'twin'     => true,
				),
			),
			'grow'     => 'content',
			'required' => true,
			'when'     => array( 'home_layout', 'has:content' ),
			'target'   => array( 'call', 'home' ),
		);

		$f['home_icons'] = array(
			'type'     => 'repeater',
			'label'    => '',
			'row'      => __( 'Buying reason', 'oc-theme' ),
			'add'      => __( 'Another buying reason', 'oc-theme' ),
			'max'      => 4,
			'fold'     => true,
			'required' => true,
			'fields'   => array(
				'icon'    => array(
					'type'     => 'iconpick',
					'label'    => __( 'The drawing', 'oc-theme' ),
					'required' => true,
				),
				'img'     => array(
					'type'   => 'file',
					'accept' => 'image',
					'label'  => __( 'Or a drawing of your own', 'oc-theme' ),
					'help'   => __( 'A small square picture, better with a see-through background. It stands in place of the one above.', 'oc-theme' ),
				),
				'heading' => array(
					'type'        => 'text',
					'label'       => __( 'In a few words', 'oc-theme' ),
					'placeholder' => __( 'Delivery across the country', 'oc-theme' ),
					'required'    => true,
				),
				'text'    => array(
					'type'        => 'text',
					'label'       => __( 'And a line explaining', 'oc-theme' ),
					'placeholder' => __( 'Up to 7 working days, free over 400 ILS', 'oc-theme' ),
					'required'    => true,
				),
			),
			'default'  => array(
				array(
					'icon'    => 'truck',
					'heading' => '',
					'text'    => '',
				),
				array(
					'icon'    => 'returns',
					'heading' => '',
					'text'    => '',
				),
				array(
					'icon'    => 'shield',
					'heading' => '',
					'text'    => '',
				),
			),
			'when'     => array( 'home_layout', 'has:icons' ),
			'target'   => array( 'call', 'home' ),
		);

		$f['home_faq'] = array(
			'type'    => 'repeater',
			'label'   => '',
			'row'     => __( 'Question', 'oc-theme' ),
			'add'     => __( 'Another question', 'oc-theme' ),
			'max'     => 10,
			'fold'    => true,
			'fields'  => array(
				'q' => array(
					'type'  => 'text',
					'label' => __( 'The question', 'oc-theme' ),
				),
				'a' => array(
					'type'  => 'textarea',
					'label' => __( 'The answer', 'oc-theme' ),
					'rows'  => 3,
				),
			),
			'default' => array(
				array(
					'q' => __( 'How long does delivery take?', 'oc-theme' ),
					'a' => '',
				),
				array(
					'q' => __( 'Can I return something?', 'oc-theme' ),
					'a' => '',
				),
				array(
					'q' => __( 'Do you have a shop I can visit?', 'oc-theme' ),
					'a' => '',
				),
			),
			'when'    => array( 'home_layout', 'has:faq' ),
			'target'  => array( 'call', 'home' ),
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

		// How many stand on a page is not a question: a number that does not
		// divide by the width of the row leaves a hole at the bottom of it.
		// Twelve rows of whatever they chose always comes out even.
		$f['cat_per_page'] = array(
			'type'    => 'call',
			'label'   => '',
			'default' => '',
			'target'  => array( 'call', 'per_page' ),
		);

		$f['card_atc'] = array(
			'type'    => 'choice',
			'group'   => __( 'The product card', 'oc-theme' ),
			'label'   => __( 'An add-to-cart button on the card?', 'oc-theme' ),
			'help'    => __( 'A button on the card itself saves a step for a shop whose products have no sizes or colours to choose.', 'oc-theme' ),
			'options' => array(
				'always' => __( 'Yes, always showing', 'oc-theme' ),
				'hover'  => __( 'Only when the mouse is on the card', 'oc-theme' ),
				'none'   => __( 'No button — the card leads to the product', 'oc-theme' ),
			),
			'default' => 'hover',
			'target'  => array( 'mod', 'oc_card_atc' ),
		);

		$f['cat_oos_last'] = array(
			'type'    => 'choice',
			'group'   => __( 'The shelf of products', 'oc-theme' ),
			'label'   => __( 'Send what is out of stock to the end?', 'oc-theme' ),
			'help'    => __( 'What can be bought stands first, and what cannot waits at the back instead of taking the good places.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'mod', 'oc_catalog_oos_last' ),
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
			'target'  => array( 'call', 'catalog_filters' ),
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

		/* ---- 7a: the cart and the checkout ---- */

		/* ---- 6b: choices and details ---- */

		$f['attr_vary'] = array(
			'type'    => 'choice',
			'group'   => __( 'Choices on the product page', 'oc-theme' ),
			'label'   => __( 'Does a buyer choose something before buying?', 'oc-theme' ),
			'help'    => __( 'A colour, a size, a length. One product, several versions of it — each with its own stock and, if you like, its own price.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'call', 'attrs' ),
		);

		$f['attr_list'] = array(
			'type'   => 'attrs',
			'group'  => __( 'Choices on the product page', 'oc-theme' ),
			'label'  => '',
			'help'   => __( 'Press one of the usual ones, or write your own. Beside each, how the buyer picks it — the drawing changes as you go.', 'oc-theme' ),
			'chips'  => array(
				_x( 'Colour', 'product attribute', 'oc-theme' ),
				_x( 'Size', 'product attribute', 'oc-theme' ),
				_x( 'Length', 'product attribute', 'oc-theme' ),
				_x( 'Width', 'product attribute', 'oc-theme' ),
				_x( 'Flavour', 'product attribute', 'oc-theme' ),
				_x( 'Material', 'product attribute', 'oc-theme' ),
			),
			'shows'  => array(
				'swatch' => __( 'A circle of colour', 'oc-theme' ),
				'button' => __( 'A button', 'oc-theme' ),
				'select' => __( 'A list to open', 'oc-theme' ),
			),
			'add'    => __( 'Another choice', 'oc-theme' ),
			'max'    => 6,
			'when'   => array( 'attr_vary', array( 'yes' ) ),
			'target' => array( 'call', 'attrs' ),
		);

		$f['attr_spec'] = array(
			'type'    => 'choice',
			'group'   => __( 'Details of the product', 'oc-theme' ),
			'label'   => __( 'Does your product have details worth listing?', 'oc-theme' ),
			'help'    => __( 'Not something to choose — something the product has. A television has a screen size and a resolution; a sofa has a fabric and a depth. They stand in a table under the product, in the "More information" tab.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'call', 'attrs' ),
		);

		$f['attr_specs'] = array(
			'type'   => 'attrs',
			'group'  => __( 'Details of the product', 'oc-theme' ),
			'label'  => '',
			'help'   => __( 'The names only. What each product says for them you fill in on the product itself.', 'oc-theme' ),
			'chips'  => array(
				_x( 'Material', 'product attribute', 'oc-theme' ),
				_x( 'Measurements', 'product attribute', 'oc-theme' ),
				_x( 'Weight', 'product attribute', 'oc-theme' ),
				_x( 'Country of origin', 'product attribute', 'oc-theme' ),
				_x( 'Warranty', 'product attribute', 'oc-theme' ),
				_x( 'Care', 'product attribute', 'oc-theme' ),
			),
			'add'    => __( 'Another detail', 'oc-theme' ),
			'max'    => 10,
			'when'   => array( 'attr_spec', array( 'yes' ) ),
			'target' => array( 'call', 'attrs' ),
		);

		/* ---- 6c: the other products on the product page ---- */

		$f['xs_on'] = array(
			'type'    => 'choice',
			'group'   => __( 'Goes with it', 'oc-theme' ),
			'label'   => __( 'Offer products that go with this one?', 'oc-theme' ),
			'help'    => __( 'The cushion with the sofa. You pick them per product, under Linked products → Cross-sells.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'mod', 'oc_xsell_on' ),
		);

		$f['xs_title'] = array(
			'type'        => 'text',
			'group'       => __( 'Goes with it', 'oc-theme' ),
			'label'       => __( 'The heading above them', 'oc-theme' ),
			'placeholder' => __( 'Goes well with', 'oc-theme' ),
			'when'        => array( 'xs_on', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_xsell_title' ),
		);

		$f['xs_place'] = array(
			'type'    => 'choice',
			'group'   => __( 'Goes with it', 'oc-theme' ),
			'label'   => __( 'Where do they stand?', 'oc-theme' ),
			'options' => array(
				'cart'    => __( 'Before the buy button — ticked, they go into the cart with it', 'oc-theme' ),
				'tabs'    => __( 'After the tabs, each with a button of its own', 'oc-theme' ),
				'summary' => __( 'Lower down the page, under the product itself', 'oc-theme' ),
			),
			'default' => 'cart',
			'quiet'   => true,
			'when'    => array( 'xs_on', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_xsell_place' ),
		);

		$f['xs_style_cart'] = array(
			'type'    => 'choice',
			'group'   => __( 'Goes with it', 'oc-theme' ),
			'label'   => __( 'In what shape?', 'oc-theme' ),
			'options' => array(
				'rows' => __( 'Rows with a tick box', 'oc-theme' ),
				'grid' => __( 'Squares you can tick', 'oc-theme' ),
			),
			'default' => 'rows',
			'quiet'   => true,
			'when'    => array( array( 'xs_on', array( 'yes' ) ), array( 'xs_place', array( 'cart' ) ) ),
			'target'  => array( 'mod', 'oc_xsell_style_cart' ),
		);

		// After the tabs there is nothing to choose: that row is the width of
		// the page, so it is a grid of products like the similar ones.
		$f['xs_style_sum'] = array(
			'type'    => 'choice',
			'group'   => __( 'Goes with it', 'oc-theme' ),
			'label'   => __( 'In what shape?', 'oc-theme' ),
			'options' => array(
				'grid'   => __( 'A grid', 'oc-theme' ),
				'slider' => __( 'A slider', 'oc-theme' ),
			),
			'default' => 'grid',
			'quiet'   => true,
			'when'    => array( array( 'xs_on', array( 'yes' ) ), array( 'xs_place', array( 'summary' ) ) ),
			'target'  => array( 'mod', 'oc_xsell_style_sum' ),
		);

		$f['bt_on'] = array(
			'type'    => 'choice',
			'group'   => __( 'Bought together', 'oc-theme' ),
			'label'   => __( 'Offer a bundle of this one with others?', 'oc-theme' ),
			'help'    => __( 'This product and whatever you tie to it, as one add to the cart, with a discount if you want one. It only shows on a product you have built a bundle for.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'mod', 'oc_bt_on' ),
		);

		$f['bt_title'] = array(
			'type'        => 'text',
			'group'       => __( 'Bought together', 'oc-theme' ),
			'label'       => __( 'The heading above it', 'oc-theme' ),
			'placeholder' => __( 'Bought together', 'oc-theme' ),
			'when'        => array( 'bt_on', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_bt_title' ),
		);

		$f['ups_on'] = array(
			'type'    => 'choice',
			'group'   => __( 'Upgrades', 'oc-theme' ),
			'label'   => __( 'Show upgrades?', 'oc-theme' ),
			'help'    => __( 'The better version of the thing being looked at. You pick them per product, in the shop, under Linked products → Upsells. With none picked the row simply does not appear.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'mod', 'oc_product_upsells' ),
		);

		$f['ups_title'] = array(
			'type'        => 'text',
			'group'       => __( 'Upgrades', 'oc-theme' ),
			'label'       => __( 'The heading above them', 'oc-theme' ),
			'placeholder' => __( 'You may also like', 'oc-theme' ),
			'when'        => array( 'ups_on', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_upsells_title' ),
		);

		// Nothing to decide here: the shop picks them itself, and it picks
		// them the way that works — the heading is the only thing that is
		// yours.
		$f['rel_title'] = array(
			'type'        => 'text',
			'group'       => __( 'Similar products', 'oc-theme' ),
			'label'       => __( 'The heading above them', 'oc-theme' ),
			'help'        => __( 'They stand at the foot of the page, and the shop picks them itself — by what they share with this one: the category, the name, the price, the tags.', 'oc-theme' ),
			'placeholder' => __( 'Similar products', 'oc-theme' ),
			'target'      => array( 'mod', 'oc_related_title' ),
		);

		/* ---- 7a: the cart panel ---- */

		// Which side it slides out from is not asked: left is the theme's
		// own default and the one nearly every shop keeps.
		$f['cart_open'] = array(
			'type'    => 'choice',
			'group'   => __( 'The panel', 'oc-theme' ),
			'label'   => __( 'Does it open the moment something is added?', 'oc-theme' ),
			'help'    => __( 'It opens, shows what went in, and the shopper carries on.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'option', 'oc_cart', 'open_on_add' ),
		);

		$f['cart_ship_bar'] = array(
			'type'    => 'choice',
			'group'   => __( 'The panel', 'oc-theme' ),
			'label'   => __( 'Show how far they are from free delivery?', 'oc-theme' ),
			'help'    => __( 'A line that says how much more is needed. Only worth it if you offer free delivery over an amount.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'option', 'oc_cart', 'ship_bar' ),
		);

		$f['cart_up'] = array(
			'type'    => 'choice',
			'group'   => __( 'Offering more in the panel', 'oc-theme' ),
			'label'   => __( 'Offer more products inside the cart panel?', 'oc-theme' ),
			'help'    => __( 'Something that goes with what they already took. Whatever is in the cart is never offered again.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'option', 'oc_cart', 'up_show' ),
		);

		$f['cart_up_style'] = array(
			'type'    => 'choice',
			'group'   => __( 'Offering more in the panel', 'oc-theme' ),
			'label'   => __( 'Where do they stand?', 'oc-theme' ),
			'options' => array(
				'side'     => __( 'A strip beside the panel', 'oc-theme' ),
				'list'     => __( 'After the cart items', 'oc-theme' ),
				'slider'   => __( 'A slider across', 'oc-theme' ),
				'collapse' => __( 'Above the total, foldable', 'oc-theme' ),
			),
			'default' => 'side',
			'quiet'   => true,
			'when'    => array( 'cart_up', array( 'yes' ) ),
			'target'  => array( 'option', 'oc_cart', 'up_style' ),
		);

		$f['cart_up_title'] = array(
			'type'        => 'text',
			'group'       => __( 'Offering more in the panel', 'oc-theme' ),
			'label'       => __( 'The heading above them', 'oc-theme' ),
			'placeholder' => __( 'You may also like', 'oc-theme' ),
			'when'        => array( 'cart_up', array( 'yes' ) ),
			'target'      => array( 'option', 'oc_cart', 'up_title' ),
		);

		$f['cart_up_source'] = array(
			'type'    => 'choice',
			'group'   => __( 'Offering more in the panel', 'oc-theme' ),
			'label'   => __( 'Which products are offered?', 'oc-theme' ),
			'options' => array(
				'items'    => __( 'Products that go with what is in the cart', 'oc-theme' ),
				'category' => __( 'One category, the same for everyone', 'oc-theme' ),
			),
			'default' => 'items',
			'quiet'   => true,
			'when'    => array( 'cart_up', array( 'yes' ) ),
			'target'  => array( 'option', 'oc_cart', 'up_source' ),
		);

		$f['cart_up_cat'] = array(
			'type'     => 'from_menu',
			'group'    => __( 'Offering more in the panel', 'oc-theme' ),
			'label'    => __( 'Which category', 'oc-theme' ),
			'one'      => true,
			'required' => true,
			'when'     => array( 'cart_up_source', array( 'category' ) ),
			'target'   => array( 'call', 'cart_cat' ),
		);

		$f['cart_up_max'] = array(
			'type'    => 'stepper',
			'group'   => __( 'Offering more in the panel', 'oc-theme' ),
			'label'   => __( 'How many at most', 'oc-theme' ),
			'min'     => 1,
			'max'     => 12,
			'default' => '5',
			'when'    => array( 'cart_up', array( 'yes' ) ),
			'target'  => array( 'option', 'oc_cart', 'up_max' ),
		);

		$f['cart_coupon'] = array(
			'type'    => 'choice',
			'group'   => __( 'The foot of the panel', 'oc-theme' ),
			'label'   => __( 'A coupon field in the panel?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'option', 'oc_cart', 'coupon' ),
		);

		$f['cart_btn_total'] = array(
			'type'    => 'choice',
			'group'   => __( 'The foot of the panel', 'oc-theme' ),
			'label'   => __( 'The cart total on the checkout button?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'option', 'oc_cart', 'btn_total' ),
		);

		$f['cart_continue'] = array(
			'type'    => 'choice',
			'group'   => __( 'The foot of the panel', 'oc-theme' ),
			'label'   => __( 'A "continue shopping" button underneath?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'option', 'oc_cart', 'continue' ),
		);

		/* ---- 7b: the checkout ---- */

		$f['ck_multi'] = array(
			'type'    => 'choice',
			'group'   => __( 'Who it goes to', 'oc-theme' ),
			'label'   => __( 'Let a customer keep more than one address?', 'oc-theme' ),
			'help'    => __( 'Home and work, say. A returning customer picks one instead of writing it again.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'option', 'oc_checkout', 'multi_address' ),
		);

		$f['ck_other'] = array(
			'type'    => 'choice',
			'group'   => __( 'Who it goes to', 'oc-theme' ),
			'label'   => __( 'Offer "I am sending to someone else"?', 'oc-theme' ),
			'help'    => __( 'A gift, or an order sent to the office. The buyer fills in a different address and name.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'option', 'oc_checkout', 'send_other' ),
		);

		$f['ck_phone2'] = array(
			'type'    => 'choice',
			'group'   => __( 'Who it goes to', 'oc-theme' ),
			'label'   => __( 'Must they give the recipient\'s phone as well?', 'oc-theme' ),
			'help'    => __( 'The courier calls whoever opens the door, not whoever paid.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'when'    => array( 'ck_other', array( 'yes' ) ),
			'target'  => array( 'option', 'oc_checkout', 'phone2_required' ),
		);

		$f['ck_req'] = array(
			'type'    => 'checks',
			'group'   => __( 'The delivery address', 'oc-theme' ),
			'label'   => __( 'What must be filled in besides the street?', 'oc-theme' ),
			'help'    => __( 'The street and the house number are always required. Tick whatever a courier of yours cannot do without.', 'oc-theme' ),
			'options' => array(
				'apt'   => __( 'Apartment number', 'oc-theme' ),
				'floor' => __( 'Floor', 'oc-theme' ),
				'entry' => __( 'Entry code', 'oc-theme' ),
			),
			'default' => array( 'apt', 'floor' ),
			'target'  => array( 'call', 'ck_req' ),
		);

		$f['ck_summary'] = array(
			'type'    => 'choice',
			'group'   => __( 'The order beside the form', 'oc-theme' ),
			'label'   => __( 'Show the list of products beside the form?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'option', 'oc_checkout', 'summary' ),
		);

		$f['ck_fold'] = array(
			'type'    => 'choice',
			'group'   => __( 'The order beside the form', 'oc-theme' ),
			'label'   => __( 'Does the list start folded on a wide screen?', 'oc-theme' ),
			'help'    => __( 'Folded, the form is what the eye lands on; the sum is in view either way.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'when'    => array( 'ck_summary', array( 'yes' ) ),
			'target'  => array( 'option', 'oc_checkout', 'summary_fold' ),
		);

		$f['ck_coupon'] = array(
			'type'    => 'choice',
			'group'   => __( 'The order beside the form', 'oc-theme' ),
			'label'   => __( 'The coupon field', 'oc-theme' ),
			'options' => array(
				'open'   => __( 'Always open', 'oc-theme' ),
				'button' => __( '"Have a coupon?" opens it', 'oc-theme' ),
				'hide'   => __( 'Not shown at all', 'oc-theme' ),
			),
			'default' => 'open',
			'quiet'   => true,
			'target'  => array( 'option', 'oc_checkout', 'coupon_mode' ),
		);

		$f['ck_btn_total'] = array(
			'type'    => 'choice',
			'group'   => __( 'The order beside the form', 'oc-theme' ),
			'label'   => __( 'The sum on the order button?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'option', 'oc_checkout', 'btn_total' ),
		);

		/* ---- 7c: delivery, collection and payment ---- */

		$f['ship_price'] = array(
			'type'     => 'number',
			'group'    => __( 'Delivery', 'oc-theme' ),
			'label'    => __( 'What a courier to the door costs', 'oc-theme' ),
			'help'     => __( 'Zero means you deliver for nothing, always.', 'oc-theme' ),
			'min'      => 0,
			'max'      => 2000,
			'suffix'   => '₪',
			'required' => true,
			'target'   => array( 'call', 'shipping' ),
		);

		$f['ship_title'] = array(
			'type'        => 'text',
			'group'       => __( 'Delivery', 'oc-theme' ),
			'label'       => __( 'What to call it at the checkout', 'oc-theme' ),
			'placeholder' => __( 'Courier to the door', 'oc-theme' ),
			'target'      => array( 'call', 'shipping' ),
		);

		$f['ship_free_over'] = array(
			'type'   => 'number',
			'group'  => __( 'Delivery', 'oc-theme' ),
			'label'  => __( 'Free delivery over', 'oc-theme' ),
			'help'   => __( 'Leave it empty and there is no free delivery. It is offered under the same name with "free" at the end.', 'oc-theme' ),
			'min'    => 0,
			'max'    => 100000,
			'suffix' => '₪',
			'target' => array( 'call', 'shipping' ),
		);

		$f['pickup_on'] = array(
			'type'    => 'choice',
			'group'   => __( 'Collection in person', 'oc-theme' ),
			'label'   => __( 'Can people come and collect?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'call', 'shipping' ),
		);

		$f['pickup_title'] = array(
			'type'        => 'text',
			'group'       => __( 'Collection in person', 'oc-theme' ),
			'label'       => __( 'What to call it at the checkout', 'oc-theme' ),
			'placeholder' => __( 'Collection from Allenby 1, Tel Aviv — by arrangement only', 'oc-theme' ),
			'when'        => array( 'pickup_on', array( 'yes' ) ),
			'target'      => array( 'call', 'shipping' ),
		);

		$f['pickup_branches'] = array(
			'type'   => 'branch_pick',
			'group'  => __( 'Collection in person', 'oc-theme' ),
			'label'  => __( 'Which branches can be collected from', 'oc-theme' ),
			'help'   => __( 'All of them to begin with. A branch can be given its own wording.', 'oc-theme' ),
			'of'     => 'branches',
			'when'   => array( array( 'pickup_on', array( 'yes' ) ), array( 'branches_mode', array( 'many' ) ) ),
			'target' => array( 'call', 'shipping' ),
		);

		$f['pay_gw'] = array(
			'type'    => 'choice',
			'group'   => __( 'Taking the money', 'oc-theme' ),
			'label'   => __( 'Who clears your card payments?', 'oc-theme' ),
			'options' => array(
				'none'    => __( 'I do not have one yet', 'oc-theme' ),
				'cardcom' => 'Cardcom',
				'payplus' => 'PayPlus',
				'other'   => __( 'Someone else', 'oc-theme' ),
			),
			'default' => 'none',
			'quiet'   => true,
			'target'  => array( 'call', 'payments' ),
		);

		$f['pay_none'] = array(
			'type'  => 'info',
			'group' => __( 'Taking the money', 'oc-theme' ),
			'label' => __( 'No problem — nothing here waits for it. At the end of the questionnaire we hand you a link to PayPlus; leave your details there and they come back to you. The shop is built either way, and the card payments switch on the day you have an account.', 'oc-theme' ),
			'when'  => array( 'pay_gw', array( 'none' ) ),
		);

		$f['pay_when'] = array(
			'type'    => 'choice',
			'group'   => __( 'Taking the money', 'oc-theme' ),
			'label'   => __( 'Do you have the details to hand?', 'oc-theme' ),
			'options' => array(
				'now'   => __( 'Yes, I will write them now', 'oc-theme' ),
				'later' => __( 'I will fill them in later', 'oc-theme' ),
			),
			'default' => 'now',
			'quiet'   => true,
			'when'    => array( 'pay_gw', array( 'cardcom', 'payplus' ) ),
			'target'  => array( 'call', 'payments' ),
		);

		$f['cc_terminal'] = array(
			'type'   => 'text',
			'group'  => __( 'Taking the money', 'oc-theme' ),
			'label'  => __( 'Terminal number', 'oc-theme' ),
			'dir'    => 'ltr',
			'when'   => array( array( 'pay_gw', array( 'cardcom' ) ), array( 'pay_when', array( 'now' ) ) ),
			'target' => array( 'call', 'payments' ),
		);

		$f['cc_user'] = array(
			'type'   => 'text',
			'group'  => __( 'Taking the money', 'oc-theme' ),
			'label'  => __( 'User name', 'oc-theme' ),
			'dir'    => 'ltr',
			'when'   => array( array( 'pay_gw', array( 'cardcom' ) ), array( 'pay_when', array( 'now' ) ) ),
			'target' => array( 'call', 'payments' ),
		);

		$f['cc_pass'] = array(
			'type'   => 'secret',
			'group'  => __( 'Taking the money', 'oc-theme' ),
			'label'  => __( 'Password', 'oc-theme' ),
			'help'   => __( 'It is kept on your own site and never shown again on this screen.', 'oc-theme' ),
			'when'   => array( array( 'pay_gw', array( 'cardcom' ) ), array( 'pay_when', array( 'now' ) ) ),
			'target' => array( 'call', 'payments' ),
		);

		$f['pp_api'] = array(
			'type'   => 'secret',
			'group'  => __( 'Taking the money', 'oc-theme' ),
			'label'  => __( 'API key', 'oc-theme' ),
			'help'   => __( 'All three sit in your PayPlus account, under the terminal.', 'oc-theme' ),
			'when'   => array( array( 'pay_gw', array( 'payplus' ) ), array( 'pay_when', array( 'now' ) ) ),
			'target' => array( 'call', 'payments' ),
		);

		$f['pp_secret'] = array(
			'type'   => 'secret',
			'group'  => __( 'Taking the money', 'oc-theme' ),
			'label'  => __( 'Secret key', 'oc-theme' ),
			'when'   => array( array( 'pay_gw', array( 'payplus' ) ), array( 'pay_when', array( 'now' ) ) ),
			'target' => array( 'call', 'payments' ),
		);

		$f['pp_page'] = array(
			'type'   => 'text',
			'group'  => __( 'Taking the money', 'oc-theme' ),
			'label'  => __( 'Payment page UID', 'oc-theme' ),
			'dir'    => 'ltr',
			'when'   => array( array( 'pay_gw', array( 'payplus' ) ), array( 'pay_when', array( 'now' ) ) ),
			'target' => array( 'call', 'payments' ),
		);

		$f['pay_other'] = array(
			'type'     => 'text',
			'group'    => __( 'Taking the money', 'oc-theme' ),
			'label'    => __( 'What are they called?', 'oc-theme' ),
			'required' => true,
			'when'     => array( 'pay_gw', array( 'other' ) ),
			'target'   => array( 'call', 'payments' ),
		);

		$f['pay_split'] = array(
			'type'    => 'choice',
			'group'   => __( 'How they pay', 'oc-theme' ),
			'label'   => __( 'Offer payment in instalments?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'call', 'payments' ),
		);

		$f['pay_max'] = array(
			'type'    => 'stepper',
			'group'   => __( 'How they pay', 'oc-theme' ),
			'label'   => __( 'Up to how many', 'oc-theme' ),
			'min'     => 2,
			'max'     => 12,
			'default' => '3',
			'when'    => array( 'pay_split', array( 'yes' ) ),
			'target'  => array( 'call', 'payments' ),
		);

		$f['pay_charge'] = array(
			'type'    => 'choice',
			'group'   => __( 'How they pay', 'oc-theme' ),
			'label'   => __( 'Is the card charged at once, or held?', 'oc-theme' ),
			'help'    => __( 'Charged at once, the money moves when the order is placed. Held, the sum is set aside on the card and only taken when you approve the order — which suits a shop that cannot always promise the stock.', 'oc-theme' ),
			'options' => array(
				'now'  => __( 'Charged at once', 'oc-theme' ),
				'hold' => __( 'Held until I approve', 'oc-theme' ),
			),
			'default' => 'now',
			'quiet'   => true,
			'target'  => array( 'call', 'payments' ),
		);

		$f['pay_more'] = array(
			'type'    => 'checks',
			'group'   => __( 'How they pay', 'oc-theme' ),
			'label'   => __( 'What else can they pay with?', 'oc-theme' ),
			'options' => array(
				'wallets'  => __( 'Apple Pay and Google Pay', 'oc-theme' ),
				'bit'      => 'bit',
				'transfer' => __( 'A bank transfer', 'oc-theme' ),
				'cash'     => __( 'Cash', 'oc-theme' ),
			),
			'default' => array(),
			'target'  => array( 'call', 'payments' ),
		);

		$f['pay_cash_pickup'] = array(
			'type'    => 'choice',
			'group'   => __( 'How they pay', 'oc-theme' ),
			'label'   => __( 'Cash only when they come and collect?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'when'    => array( 'pay_more', array( 'cash' ) ),
			'target'  => array( 'call', 'payments' ),
		);

		/* ---- 5a: the product page ---- */

		$f['prod_side'] = array(
			'type'    => 'choice',
			'group'   => __( 'The pictures', 'oc-theme' ),
			'label'   => __( 'Which side are the pictures on?', 'oc-theme' ),
			'options' => array(
				'gallery-start' => __( 'The side the page starts from', 'oc-theme' ),
				'gallery-end'   => __( 'The other side', 'oc-theme' ),
			),
			'default' => 'gallery-start',
			'target'  => array( 'mod', 'oc_product_layout_side' ),
		);

		$f['prod_gallery'] = array(
			'type'    => 'choice',
			'group'   => __( 'The pictures', 'oc-theme' ),
			'label'   => __( 'Where do the small pictures sit?', 'oc-theme' ),
			'options' => array(
				'thumbs-side'  => __( 'Beside the big one', 'oc-theme' ),
				'thumbs-under' => __( 'Under it', 'oc-theme' ),
				'stacked'      => __( 'Beside — and the big ones run one under the other', 'oc-theme' ),
				'grid'         => __( 'No small ones — two in a row, all of them', 'oc-theme' ),
			),
			'default' => 'thumbs-side',
			'target'  => array( 'mod', 'oc_gallery_preset' ),
		);

		$f['prod_qty'] = array(
			'type'    => 'choice',
			'group'   => __( 'The buying area', 'oc-theme' ),
			'label'   => __( 'A quantity box beside the buy button?', 'oc-theme' ),
			'help'    => __( 'Worth it when people buy several of the same thing. A buyer can change the quantity in the cart either way.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'mod', 'oc_atc_qty' ),
		);

		$f['prod_sku'] = array(
			'type'    => 'choice',
			'group'   => __( 'The buying area', 'oc-theme' ),
			'label'   => __( 'Show the product code?', 'oc-theme' ),
			'help'    => __( 'The number you use in the warehouse. Shoppers rarely need it; a trade customer does.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'mod', 'oc_product_sku' ),
		);

		$f['prod_price_btn'] = array(
			'type'    => 'choice',
			'group'   => __( 'The buying area', 'oc-theme' ),
			'label'   => __( 'The price on the button itself?', 'oc-theme' ),
			'help'    => __( 'The button reads "Add to cart · 1,890" and follows the quantity and the chosen option.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'mod', 'oc_atc_price' ),
		);

		$f['prod_stock'] = array(
			'type'    => 'choice',
			'group'   => __( 'The buying area', 'oc-theme' ),
			'label'   => __( 'A line above the button saying how the stock is?', 'oc-theme' ),
			'help'    => __( 'It takes turns between what the stock is doing and when the parcel would arrive — "in stock, arrives 2/12–4/12".', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'mod', 'oc_stock_indicator' ),
		);

		$f['prod_ship_days'] = array(
			'type'    => 'days',
			'group'   => __( 'The buying area', 'oc-theme' ),
			'label'   => __( 'Which days do orders go out?', 'oc-theme' ),
			'help'    => __( 'Counting starts the day after the order and skips the days you do not send, and the holidays.', 'oc-theme' ),
			'options' => array(
				'0' => __( 'Sunday', 'oc-theme' ),
				'1' => __( 'Monday', 'oc-theme' ),
				'2' => __( 'Tuesday', 'oc-theme' ),
				'3' => __( 'Wednesday', 'oc-theme' ),
				'4' => __( 'Thursday', 'oc-theme' ),
				'5' => __( 'Friday', 'oc-theme' ),
				'6' => __( 'Saturday', 'oc-theme' ),
			),
			'default' => array( '0', '1', '2', '3', '4' ),
			'when'    => array( 'prod_stock', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_ship_days' ),
			'as'      => 'csv',
		);

		$f['prod_lead_min'] = array(
			'type'    => 'stepper',
			'group'   => __( 'The buying area', 'oc-theme' ),
			'label'   => __( 'At the quickest, how many sending days', 'oc-theme' ),
			'min'     => 1,
			'max'     => 30,
			'default' => '1',
			'when'    => array( 'prod_stock', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_ship_lead_min' ),
		);

		$f['prod_lead_max'] = array(
			'type'    => 'stepper',
			'group'   => __( 'The buying area', 'oc-theme' ),
			'label'   => __( 'And at the slowest', 'oc-theme' ),
			'min'     => 1,
			'max'     => 30,
			'default' => '3',
			'when'    => array( 'prod_stock', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_ship_lead' ),
		);

		$f['prod_icons_lay'] = array(
			'type'    => 'choice',
			'group'   => __( 'Buying reasons under the button', 'oc-theme' ),
			'label'   => __( 'How do they stand?', 'oc-theme' ),
			'options' => array(
				'row'   => __( 'Side by side, in one row', 'oc-theme' ),
				'stack' => __( 'One under the other', 'oc-theme' ),
			),
			'default' => 'row',
			'quiet'   => true,
			'target'  => array( 'mod', 'oc_atc_icons_layout' ),
		);

		$f['prod_icons'] = array(
			'type'     => 'repeater',
			'group'    => __( 'Buying reasons under the button', 'oc-theme' ),
			'label'    => '',
			'help'     => __( 'The short promises that sit under the buy button. Three to begin with, up to four — write what is true of your shop.', 'oc-theme' ),
			'row'      => __( 'Buying reason', 'oc-theme' ),
			'add'      => __( 'Another buying reason', 'oc-theme' ),
			'max'      => 4,
			'fold'     => true,
			'required' => true,
			'fields'   => array(
				'icon' => array(
					'type'  => 'iconpick',
					'from'  => 'atc',
					'label' => __( 'The drawing', 'oc-theme' ),
				),
				'text' => array(
					'type'        => 'text',
					'label'       => __( 'What it says', 'oc-theme' ),
					'placeholder' => __( 'Free returns and exchanges', 'oc-theme' ),
					'required'    => true,
				),
			),
			// Each one carries its own example, so three identical grey
			// lines do not stand there saying the same thing.
			'hints'    => array(
				'text' => array(
					__( 'Free returns and exchanges', 'oc-theme' ),
					__( '12 months of warranty', 'oc-theme' ),
					__( 'Delivery within 3 working days', 'oc-theme' ),
					__( 'A secure purchase', 'oc-theme' ),
				),
			),
			'default'  => array(
				array(
					'icon' => 'returns',
					'text' => '',
				),
				array(
					'icon' => 'warranty',
					'text' => '',
				),
				array(
					'icon' => 'truck',
					'text' => '',
				),
			),
			'target'   => array( 'call', 'prod_icons' ),
		);

		$f['prod_short'] = array(
			'type'    => 'choice',
			'group'   => __( 'The buying area', 'oc-theme' ),
			'label'   => __( 'Where does the short description go?', 'oc-theme' ),
			'help'    => __( 'The line or two that sum the product up. Under the heading it is read by everyone; as the first tab it waits for whoever wants it.', 'oc-theme' ),
			'options' => array(
				'under' => __( 'Under the heading and the price', 'oc-theme' ),
				'tab'   => __( 'As the first tab, open to begin with', 'oc-theme' ),
			),
			'default' => 'under',
			'target'  => array( 'call', 'ship_tab' ),
		);

		$f['prod_tabs_style'] = array(
			'type'    => 'choice',
			'group'   => __( 'Tabs on the product page', 'oc-theme' ),
			'label'   => __( 'How do they open?', 'oc-theme' ),
			'options' => array(
				'accordion' => __( 'An accordion — each heading opens under itself', 'oc-theme' ),
				'tabs'      => __( 'Tabs — the headings in a row, one open underneath', 'oc-theme' ),
			),
			'default' => 'accordion',
			'quiet'   => true,
			'target'  => array( 'mod', 'oc_product_tabs' ),
		);

		$f['prod_tabs_pos'] = array(
			'type'    => 'choice',
			'group'   => __( 'Tabs on the product page', 'oc-theme' ),
			'label'   => __( 'Where do they sit?', 'oc-theme' ),
			'options' => array(
				'side'    => __( 'Under the buying reasons, in the buying column', 'oc-theme' ),
				'below'   => __( 'Below the whole buying area, across the page', 'oc-theme' ),
				'gallery' => __( 'Under the pictures', 'oc-theme' ),
			),
			'default' => 'side',
			'quiet'   => true,
			'target'  => array( 'mod', 'oc_product_tabs_pos' ),
		);

		$f['prod_ship_tab'] = array(
			'type'    => 'choice',
			'group'   => __( 'Tabs on the product page', 'oc-theme' ),
			'label'   => __( 'A delivery and returns tab on every product?', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'yes',
			'target'  => array( 'call', 'ship_tab' ),
		);

		$f['prod_ship_title'] = array(
			'type'        => 'text',
			'group'       => __( 'Tabs on the product page', 'oc-theme' ),
			'label'       => __( 'What the tab is called', 'oc-theme' ),
			'placeholder' => __( 'Delivery and returns', 'oc-theme' ),
			'when'        => array( 'prod_ship_tab', array( 'yes' ) ),
			'target'      => array( 'call', 'ship_tab' ),
		);

		$f['prod_ship_text'] = array(
			'type'   => 'textarea',
			'group'  => __( 'Tabs on the product page', 'oc-theme' ),
			'label'  => __( 'What the tab says', 'oc-theme' ),
			'help'   => __( 'Leave it empty and we write a general text you can rewrite later.', 'oc-theme' ),
			'rows'   => 4,
			'when'   => array( 'prod_ship_tab', array( 'yes' ) ),
			'target' => array( 'call', 'ship_tab' ),
		);

		$f['prod_tabs'] = array(
			'type'   => 'repeater',
			'group'  => __( 'Tabs on the product page', 'oc-theme' ),
			'label'  => '',
			'help'   => __( 'The same words on every product — care instructions, a size guide, a warranty.', 'oc-theme' ),
			'row'    => __( 'Tab', 'oc-theme' ),
			'add'    => __( 'Another tab', 'oc-theme' ),
			'max'    => 4,
			'empty'  => true,
			'fold'   => true,
			'fields' => array(
				'title' => array(
					'type'        => 'text',
					'label'       => __( 'What it is called', 'oc-theme' ),
					'placeholder' => __( 'Care instructions', 'oc-theme' ),
					'required'    => true,
				),
				'text'  => array(
					'type'     => 'textarea',
					'label'    => __( 'And what it says', 'oc-theme' ),
					'rows'     => 4,
					'required' => true,
				),
			),
			'target' => array( 'call', 'ship_tab' ),
		);

		$f['prod_contact'] = array(
			'type'    => 'choice',
			'group'   => __( 'Talk to a person', 'oc-theme' ),
			'label'   => __( 'Offer a way to reach someone from the product page?', 'oc-theme' ),
			'help'    => __( 'A face, a name and a button that opens WhatsApp or dials. On goods people hesitate over it is often what settles it.', 'oc-theme' ),
			'options' => $yesno,
			'default' => 'no',
			'target'  => array( 'mod', 'oc_contact_on' ),
		);

		$f['contact_place'] = array(
			'type'    => 'choice',
			'label'   => __( 'Where does the card stand?', 'oc-theme' ),
			'options' => array(
				'atc'  => __( 'Right under the add-to-cart area', 'oc-theme' ),
				'tabs' => __( 'After the tabs', 'oc-theme' ),
			),
			'default' => 'atc',
			'when'    => array( 'prod_contact', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_contact_place' ),
		);

		$f['contact_name'] = array(
			'type'        => 'text',
			'group'       => __( 'Talk to a person', 'oc-theme' ),
			'label'       => __( 'Who answers', 'oc-theme' ),
			'placeholder' => __( 'Israel Israeli', 'oc-theme' ),
			'required'    => true,
			'when'        => array( 'prod_contact', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_contact_name' ),
		);

		$f['contact_role'] = array(
			'type'        => 'text',
			'group'       => __( 'Talk to a person', 'oc-theme' ),
			'label'       => __( 'And what they do', 'oc-theme' ),
			'placeholder' => __( 'Sales adviser', 'oc-theme' ),
			'when'        => array( 'prod_contact', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_contact_role' ),
		);

		$f['contact_photo'] = array(
			'type'   => 'file',
			'accept' => 'image',
			'group'  => __( 'Talk to a person', 'oc-theme' ),
			'label'  => __( 'A photograph of them', 'oc-theme' ),
			'help'   => __( 'A real face does more here than a logo. It is cropped to a circle.', 'oc-theme' ),
			'when'   => array( 'prod_contact', array( 'yes' ) ),
			'target' => array( 'call', 'contact' ),
		);

		$f['contact_phone'] = array(
			'type'        => 'phone',
			'group'       => __( 'Talk to a person', 'oc-theme' ),
			'label'       => __( 'The number', 'oc-theme' ),
			'placeholder' => '0544-123456',
			'required'    => true,
			'when'        => array( 'prod_contact', array( 'yes' ) ),
			'target'      => array( 'mod', 'oc_contact_phone' ),
		);

		// "Available now" is a promise, so the card only makes it while
		// someone is really there. Outside the hours the green dot simply
		// goes; nothing announces that the shop is shut.
		$f['contact_from'] = array(
			'type'    => 'time',
			'group'   => __( 'Talk to a person', 'oc-theme' ),
			'label'   => __( 'Available from', 'oc-theme' ),
			'help'    => __( 'Between these hours the card says "available now". Outside them it says nothing of the kind.', 'oc-theme' ),
			'default' => '09:00',
			'when'    => array( 'prod_contact', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_contact_from' ),
		);

		$f['contact_to'] = array(
			'type'    => 'time',
			'group'   => __( 'Talk to a person', 'oc-theme' ),
			'label'   => __( 'And until', 'oc-theme' ),
			'default' => '18:00',
			'when'    => array( 'prod_contact', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_contact_to' ),
		);

		/* ---- 6a: brands ---- */

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

		// Not asked: a brand shop shows the name on the card and the logo on
		// the product. Two questions nobody has an opinion about.
		$f['brand_card'] = array(
			'type'    => 'choice',
			'label'   => '',
			'options' => $yesno,
			'default' => 'yes',
			'when'    => array( 'brands_has', array( 'yes' ) ),
			'target'  => array( 'mod', 'oc_card_brand' ),
		);

		$f['brand_product'] = array(
			'type'    => 'choice',
			'label'   => '',
			'options' => array(
				'text'  => __( 'The name', 'oc-theme' ),
				'image' => __( 'The logo', 'oc-theme' ),
				'none'  => __( 'Neither', 'oc-theme' ),
			),
			'default' => 'image',
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
					'ai'           => array(),
					'as'           => '',
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
	 * The screens in the order they are asked, by id. Used to tell which of
	 * two screens the customer reached later.
	 *
	 * @return array<int,string>
	 */
	public static function screen_order(): array {
		static $order = null;

		if ( null !== $order ) {
			return $order;
		}

		$order = array();

		foreach ( self::steps() as $step ) {
			foreach ( $step['screens'] as $screen ) {
				$order[] = (string) $screen['id'];
			}
		}

		return $order;
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
			case 'branch_pick':
			case 'attrs':
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

			// has:<kind> — does the arrangement still hold such a part, and
			// is it switched on? This is how a screen about one part of the
			// page knows whether that part is on the page at all.
			if ( is_string( $want ) && 0 === strpos( $want, 'has:' ) ) {
				$kind = substr( $want, 4 );

				foreach ( (array) $has as $row ) {
					if ( is_array( $row ) && ! empty( $row['on'] ) && $kind === (string) ( $row['type'] ?? '' ) ) {
						return true;
					}
				}

				continue;
			}

			if ( 'filled' === $want ) {
				if ( ! self::empty_value( $has ) ) {
					return true;
				}
				continue;
			}

			$wants = array_map( 'strval', (array) $want );

			// A list of ticks answers whether one of them is ticked; every
			// other kind of answer answers whether it is one of them.
			if ( is_array( $has ) ) {
				if ( array_intersect( array_map( 'strval', $has ), $wants ) ) {
					return true;
				}

				continue;
			}

			if ( in_array( (string) $has, $wants, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One level of the shop's menu, and whatever hangs under it. A shop
	 * reads in three: a department, a shelf, and a corner of that shelf.
	 * Deeper than that is a filter, not a menu.
	 *
	 * @param mixed $raw  What came from the browser.
	 * @param int   $left How many levels may still be opened.
	 * @return array<int,array{name:string,subs:array<int,mixed>}>
	 */
	private static function menu_level( $raw, int $left ): array {
		$out = array();

		foreach ( (array) $raw as $row ) {
			// An older answer kept the level under a department as plain
			// names; it still reads.
			$row = is_scalar( $row ) ? array( 'name' => (string) $row ) : $row;

			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );

			if ( '' === trim( $name ) ) {
				continue;
			}

			$out[] = array(
				'name' => $name,
				'subs' => $left > 1 ? self::menu_level( $row['subs'] ?? array(), $left - 1 ) : array(),
			);

			if ( count( $out ) >= 20 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The rows of an arrangement that are still waiting for an answer: a
	 * running line with no words, or a shelf with no shelf chosen. A row
	 * that is switched off is not asked anything.
	 *
	 * @param array<int,mixed> $rows The arrangement.
	 * @return array<int,int> Their places in the list.
	 */
	public static function layout_gaps( array $rows ): array {
		$blocks = self::home_blocks();
		$gaps   = array();

		foreach ( array_values( $rows ) as $i => $row ) {
			$row  = (array) $row;
			$type = (string) ( $row['type'] ?? '' );

			if ( ! isset( $blocks[ $type ] ) || empty( $row['on'] ) ) {
				continue;
			}

			if ( ! empty( $blocks[ $type ]['text'] ) && '' === trim( (string) ( $row['text'] ?? '' ) ) ) {
				$gaps[] = $i;
				continue;
			}

			if ( ! empty( $blocks[ $type ]['variants'] ) && ! empty( $blocks[ $type ]['blank'] ) && '' === (string) ( $row['variant'] ?? '' ) ) {
				$gaps[] = $i;
			}
		}

		return $gaps;
	}

	/**
	 * The parts of that kind still standing on the page, in their order.
	 *
	 * @param string              $kind   Block type.
	 * @param array<string,mixed> $values All the answers.
	 * @return array<int,array<string,mixed>>
	 */
	public static function parts_of( string $kind, array $values ): array {
		$rows = array_key_exists( 'home_layout', $values ) ? $values['home_layout'] : self::default_of( 'home_layout' );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['on'] ) && $kind === (string) ( $row['type'] ?? '' ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * How many have to be ticked — never more than there are to tick.
	 *
	 * @param array<string,mixed> $f      The field.
	 * @param array<string,mixed> $values All the answers.
	 */
	private static function floor_of( array $f, array $values ): int {
		$want = max( 1, (int) ( $f['min'] ?? 1 ) );

		if ( 'from_brands' === $f['type'] ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) ( $values['brand_list'] ?? '' ) );
			$have  = count( array_filter( array_map( 'trim', (array) $lines ) ) );
		} else {
			$rows = array_key_exists( 'site_menu', $values ) ? $values['site_menu'] : array();
			$have = self::menu_count( (array) $rows, ! empty( $f['deep'] ) );
		}

		return max( 1, min( $want, $have ) );
	}

	/**
	 * How many names the menu holds — the departments, or everything in it.
	 *
	 * @param array<int,mixed> $rows The menu, at this level.
	 * @param bool             $deep Whether what is inside counts too.
	 */
	private static function menu_count( array $rows, bool $deep ): int {
		$n = 0;

		foreach ( $rows as $row ) {
			$row = is_scalar( $row ) ? array( 'name' => (string) $row ) : (array) $row;

			if ( '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}

			++$n;

			if ( $deep ) {
				$n += self::menu_count( (array) ( $row['subs'] ?? array() ), true );
			}
		}

		return $n;
	}

	/**
	 * Does this block of questions want its second picture? Only where the
	 * content area it belongs to stands on two of them.
	 *
	 * @param array<string,mixed> $f      The repeater field.
	 * @param int                 $nth    Which block.
	 * @param array<string,mixed> $values All the answers.
	 */
	private static function twin_wanted( array $f, int $nth, array $values ): bool {
		$kind = (string) ( $f['grow'] ?? '' );

		if ( '' === $kind ) {
			return false;
		}

		$mine = self::parts_of( $kind, $values );

		return isset( $mine[ $nth ] ) && in_array( (string) ( $mine[ $nth ]['variant'] ?? '' ), array( 'duo', 'canvas' ), true );
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

			if ( 'from_menu' === $f['type'] || 'from_brands' === $f['type'] ) {
				$picked = is_array( $v ) ? count( $v ) : ( '' === trim( (string) $v ) ? 0 : 1 );

				if ( $picked < self::floor_of( $f, $values ) ) {
					$out[] = $id;
				}

				continue;
			}

			if ( 'menu' === $f['type'] ) {
				$named = false;

				foreach ( (array) $v as $row ) {
					if ( is_array( $row ) && '' !== trim( (string) ( $row['name'] ?? '' ) ) ) {
						$named = true;
						break;
					}
				}

				if ( ! $named ) {
					$out[] = $id;
				}

				continue;
			}

			if ( 'layout' === $f['type'] ) {
				if ( self::layout_gaps( (array) $v ) ) {
					$out[] = $id;
				}
				continue;
			}

			if ( 'repeater' === $f['type'] ) {
				if ( self::empty_value( $v ) ) {
					$out[] = $id;
					continue;
				}

				foreach ( array_values( (array) $v ) as $nth => $row ) {
					foreach ( $f['fields'] as $sub_id => $sub ) {
						if ( ! empty( $sub['twin'] ) && ! self::twin_wanted( $f, $nth, $values ) ) {
							continue;
						}

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

			case 'time':
				$v = trim( (string) ( is_scalar( $raw ) ? $raw : '' ) );

				return preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $v ) ? $v : '';

			// A key or a password: whatever they pasted, with nothing
			// stripped out of the middle of it.
			case 'secret':
				return mb_substr( trim( (string) ( is_scalar( $raw ) ? $raw : '' ) ), 0, 400 );

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

					// A row carries a mark of its own so the answers given
					// about it later follow it, rather than following the
					// place it happened to stand in.
					$uid = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $row['uid'] ?? '' ) ) );

					$keep = array(
						'type' => $type,
						'uid'  => '' !== (string) $uid ? substr( (string) $uid, 0, 12 ) : substr( md5( uniqid( (string) count( $rows ), true ) ), 0, 8 ),
						'on'   => empty( $row['on'] ) ? 0 : 1,
					);

					if ( ! empty( $blocks[ $type ]['title'] ) ) {
						$keep['title'] = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
					}

					if ( ! empty( $blocks[ $type ]['text'] ) ) {
						$keep['text'] = sanitize_text_field( (string) ( $row['text'] ?? '' ) );
					}

					if ( ! empty( $blocks[ $type ]['variants'] ) ) {
						$v    = (string) ( $row['variant'] ?? '' );
						$fall = empty( $blocks[ $type ]['blank'] ) ? (string) array_key_first( $blocks[ $type ]['variants'] ) : '';

						$keep['variant'] = isset( $blocks[ $type ]['variants'][ $v ] ) ? $v : $fall;
					}

					$rows[] = $keep;

					if ( count( $rows ) >= 24 ) {
						break;
					}
				}

				return $rows;

			case 'menu':
				return self::menu_level( $raw, 3 );

			case 'from_brands':
			case 'from_menu':
				$out = array();

				foreach ( (array) $raw as $v ) {
					$v = sanitize_text_field( (string) ( is_scalar( $v ) ? $v : '' ) );

					if ( '' !== trim( $v ) && ! in_array( $v, $out, true ) ) {
						$out[] = $v;
					}
				}

				return $out;

			case 'iconpick':
				$v   = (string) ( is_scalar( $raw ) ? $raw : '' );
				$set = 'atc' === ( $f['from'] ?? '' ) ? Art::atc_icons() : Art::icons();

				return isset( $set[ $v ] ) ? $v : '';

			case 'stepper':
				$n   = (int) ( is_scalar( $raw ) ? $raw : 0 );
				$min = isset( $f['min'] ) ? (int) $f['min'] : 1;
				$max = isset( $f['max'] ) ? (int) $f['max'] : 10;

				return (string) max( $min, min( $max, $n ) );

			case 'days':
				$out = array();

				foreach ( (array) $raw as $d ) {
					$d = (string) ( is_scalar( $d ) ? $d : '' );

					if ( preg_match( '/^[0-6]$/', $d ) && ! in_array( $d, $out, true ) ) {
						$out[] = $d;
					}
				}

				sort( $out );

				return $out;

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

			case 'attrs':
				$out   = array();
				$seen  = array();
				$max   = (int) ( $f['max'] ?? 10 );
				$kinds = array_keys( (array) ( $f['shows'] ?? array() ) );

				foreach ( (array) $raw as $row ) {
					$row  = (array) $row;
					$name = trim( sanitize_text_field( (string) ( is_scalar( $row['name'] ?? '' ) ? $row['name'] : '' ) ) );

					// Two attributes of the same name are one attribute.
					if ( '' === $name || isset( $seen[ mb_strtolower( $name ) ] ) || count( $out ) >= $max ) {
						continue;
					}

					$seen[ mb_strtolower( $name ) ] = true;

					$type = (string) ( is_scalar( $row['type'] ?? '' ) ? $row['type'] : '' );

					$out[] = array(
						'name' => mb_substr( $name, 0, 60 ),
						'type' => $kinds && in_array( $type, $kinds, true ) ? $type : (string) ( $kinds[0] ?? '' ),
					);
				}

				return $out;

			case 'branch_pick':
				$out = array();

				foreach ( (array) $raw as $row => $one ) {
					$one = (array) $one;

					$out[ (int) $row ] = array(
						'on'    => empty( $one['on'] ) ? 0 : 1,
						'title' => mb_substr( sanitize_text_field( (string) ( is_scalar( $one['title'] ?? '' ) ? $one['title'] : '' ) ), 0, 120 ),
					);
				}

				ksort( $out );

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

					// The mark that ties this block to the part of the page
					// it is about is not a question, but it has to survive.
					$uid = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $row['uid'] ?? '' ) ) );

					if ( '' !== (string) $uid ) {
						$clean['uid'] = substr( (string) $uid, 0, 12 );
					}

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

			// The page only needs to know a field can be written. What to
			// say to the writer is ours and stays on the server.
			$row['ai'] = ! empty( $f['ai'] );

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
							'from'     => '',
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
