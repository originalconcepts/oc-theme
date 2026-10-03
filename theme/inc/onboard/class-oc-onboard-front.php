<?php
/**
 * The questionnaire page: /start/<token>/.
 *
 * A page of its own, not a theme template: no header, no footer, no menu,
 * nothing the shop is not yet. It carries the site's name, logo and
 * primary colour so it already feels like the customer's, and hands the
 * schema, the draft and the words to one script that draws the steps.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

use OC\Theme\Legal\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * The page.
 */
final class Front {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'render' ), 1 );
	}

	/**
	 * Draw the page, or the "this link is no longer valid" note.
	 */
	public function render(): void {
		if ( ! Onboard::is_page() ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		global $wp_query;

		$wp_query->is_404 = false;

		$token = Onboard::request_token();

		if ( ! Onboard::token_ok( $token ) ) {
			status_header( 410 );
			$this->shell( $this->expired_html(), null );
			exit;
		}

		status_header( 200 );

		$state = Onboard::state();

		if ( ! $state['opened'] ) {
			Onboard::touch();
		}

		$this->shell( '<div id="oc-onb" class="oc-onb" data-loading="1"></div>', $this->config( $token ) );
		exit;
	}

	/**
	 * Everything the script needs, once.
	 *
	 * @param string $token The raw token.
	 * @return array<string,mixed>
	 */
	private function config( string $token ): array {
		global $wp_locale;

		$state = Onboard::state();
		$days  = array();

		for ( $d = 0; $d <= 6; $d++ ) {
			$days[] = array(
				'n'     => $d,
				'label' => $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $d ) ),
				'full'  => $wp_locale->get_weekday( $d ),
			);
		}

		return array(
			'rest'       => esc_url_raw( rest_url( Rest::NS . '/onboard' ) ),
			'token'      => $token,
			'status'     => (string) $state['status'],
			'step'       => (string) $state['step'],
			'far'        => (string) ( $state['far'] ?? '' ),
			'schema'     => Schema::for_js(),
			'values'     => Draft::values(),
			'days'       => $days,
			'disclaimer' => Consent::text(),
			'art'        => Art::all(),
			'icons'      => Art::icons(),
			'atc_icons'  => Art::atc_icons(),
			'site'       => array(
				'name' => (string) get_bloginfo( 'name' ),
				'home' => home_url( '/' ),
			),
			'i18n'       => array(
				'welcome_title'   => __( 'Online store questionnaire', 'oc-theme' ),
				'welcome_text'    => __( 'The first step is filling in this questionnaire. It is a series of questions, most of them multiple choice and a few open ones. It takes about twenty minutes.', 'oc-theme' ),
				'start'           => __( 'Let\'s start', 'oc-theme' ),
				'continue'        => __( 'Continue where I stopped', 'oc-theme' ),
				'next'            => __( 'Next', 'oc-theme' ),
				'back'            => __( 'Back', 'oc-theme' ),
				'saving'          => __( 'Saving…', 'oc-theme' ),
				'saved'           => __( 'Saved', 'oc-theme' ),
				'save_failed'     => __( 'Could not save. Check the connection and try again.', 'oc-theme' ),
				'step_of'         => /* translators: 1: step number, 2: total */ __( 'Step %1$s of %2$s', 'oc-theme' ),
				'required'        => __( 'Required', 'oc-theme' ),
				'fill_required'   => __( 'A few required fields are still empty.', 'oc-theme' ),
				'optional'        => __( 'Optional', 'oc-theme' ),
				'choose_file'     => __( 'Choose a file', 'oc-theme' ),
				'uploading'       => __( 'Uploading…', 'oc-theme' ),
				'upload_failed'   => __( 'The file could not be uploaded. Allowed: images up to 8MB, or Word / PDF.', 'oc-theme' ),
				'remove'          => __( 'Remove', 'oc-theme' ),
				'add_hours'       => __( 'Add a line', 'oc-theme' ),
				'from'            => __( 'From', 'oc-theme' ),
				'to'              => __( 'To', 'oc-theme' ),
				'days'            => __( 'Days', 'oc-theme' ),
				'summary_title'   => __( 'Almost done', 'oc-theme' ),
				'summary_text'    => __( 'Here is everything you told us. Anything missing is marked; tap it to go back.', 'oc-theme' ),
				'submit'          => __( 'I\'m done, build my site', 'oc-theme' ),
				'submitting'      => __( 'Setting things up…', 'oc-theme' ),
				'done_title'      => __( 'Thank you!', 'oc-theme' ),
				'done_text'       => __( 'The first part is in: your business details, your content pages and the thank-you page. They are already written into your site.', 'oc-theme' ),
				'done_next_title' => __( 'What happens now', 'oc-theme' ),
				'done_next_items' => array(
					__( 'We go over your answers and set up the shell of the site.', 'oc-theme' ),
					__( 'Next we go through the home page, the category page and the product page together — that part opens at this same link, and we let you know when it is ready.', 'oc-theme' ),
					__( 'Meanwhile you can start gathering your products and their pictures.', 'oc-theme' ),
				),
				'done_again'      => __( 'You can reopen this link any time to change an answer.', 'oc-theme' ),
				'submit_failed'   => __( 'Something went wrong. Nothing was lost — try again in a moment.', 'oc-theme' ),
				'not_answered'    => __( 'Not answered', 'oc-theme' ),
				'drop_sure'       => __( 'Really remove it?', 'oc-theme' ),
				'yes'             => __( 'Yes', 'oc-theme' ),
				'no'              => __( 'No', 'oc-theme' ),
				'edit'            => __( 'Edit', 'oc-theme' ),
				'rows'            => /* translators: %d: rows */ __( '%d entries', 'oc-theme' ),
				'consent_title'   => __( 'Before we write the page for you', 'oc-theme' ),
				'consent_ok'      => __( 'I have read and understood', 'oc-theme' ),
				'consent_open'    => __( 'Read and confirm', 'oc-theme' ),
				'suggested'       => __( 'Our suggestion', 'oc-theme' ),
				'sketch'          => __( 'A sketch of the page — it changes as you answer', 'oc-theme' ),
				'gal_take'        => __( 'Great, take this one', 'oc-theme' ),
				'gal_taken'       => __( 'This is your choice', 'oc-theme' ),
				'gal_next'        => __( 'Show me another', 'oc-theme' ),
				'less'            => __( 'One fewer', 'oc-theme' ),
				'more'            => __( 'One more', 'oc-theme' ),
				'wf_logo'         => __( 'Your shop', 'oc-theme' ),
				/* translators: %1$s: a stand-in number, so the sketch's product names differ. */
				'wf_product'      => __( 'Product %1$s', 'oc-theme' ),
				'wf_excerpt'      => __( 'A short line about the product', 'oc-theme' ),
				'wf_sale'         => __( 'SALE', 'oc-theme' ),
				'wf_new'          => __( 'NEW', 'oc-theme' ),
				'wf_marquee'      => __( 'Free delivery over a certain amount · New every week', 'oc-theme' ),
				'wf_sale_h'       => __( 'Hot offers', 'oc-theme' ),
				'wf_best_h'       => __( 'The best sellers of all', 'oc-theme' ),
				'wf_pick_h'       => __( 'A choice of ours', 'oc-theme' ),
				'wf_new_h'        => __( 'New in', 'oc-theme' ),
				'wf_cats_h'       => __( 'Our categories', 'oc-theme' ),
				'wf_look_h'       => __( 'Get the look', 'oc-theme' ),
				'wf_about_h'      => __( 'About the shop', 'oc-theme' ),
				'wf_trust1'       => __( 'Delivery across the country', 'oc-theme' ),
				'wf_trust2'       => __( 'Returns within 14 days', 'oc-theme' ),
				'wf_trust3'       => __( 'A secure purchase', 'oc-theme' ),
				'wf_cat_name'     => __( 'Category name', 'oc-theme' ),
				'wf_prod_name'    => __( 'Nordic three-seater sofa', 'oc-theme' ),
				'wf_stock'        => __( 'In stock', 'oc-theme' ),
				'wf_atc'          => __( 'Add to cart', 'oc-theme' ),
				'wf_sku'          => __( 'Code: 10482', 'oc-theme' ),
				'wf_short'        => __( 'Solid oak, woven fabric, and a frame meant to outlast the fashion.', 'oc-theme' ),
				/* translators: %1$s: the earliest date, %2$s: the latest, both short like 2 Sep. */
				'lead_between'    => __( 'Ordered today it will say: delivery between %1$s - %2$s.', 'oc-theme' ),
				/* translators: %1$s: a short date like 2 Sep. */
				'lead_on'         => __( 'Ordered today it will say: delivery on %1$s.', 'oc-theme' ),
				'lead_nodays'     => __( 'Pick at least one day you send orders out, and the dates appear here.', 'oc-theme' ),
				'months'          => self::months(),
				/* translators: 1: earliest date. 2: latest date, both short like 2 Sep. */
				'wf_eta'          => __( 'Delivery between %1$s - %2$s', 'oc-theme' ),
				/* translators: %s: a short date like 2 Sep. */
				'wf_eta_on'       => __( 'Delivery on %s', 'oc-theme' ),
				'wf_now'          => __( 'Online now', 'oc-theme' ),
				'wf_rel_h'        => __( 'Similar products', 'oc-theme' ),
				'wf_ty_thanks'    => __( 'Thank you, Dana', 'oc-theme' ),
				'wf_ty_said'      => __( 'Your order has been received and will be handled shortly.', 'oc-theme' ),
				'wf_ty_num'       => __( 'Your order number is:', 'oc-theme' ),
				'wf_ty_mail'      => __( 'The confirmation and order details were sent by email.', 'oc-theme' ),
				'wf_ty_call'      => __( 'Call us', 'oc-theme' ),
				'wf_ty_mailus'    => __( 'Email', 'oc-theme' ),
				'wf_ty_ref'       => __( 'Refer a friend', 'oc-theme' ),
				/* translators: %1$s: the friend's discount, %2$s: the buyer's reward, both percentages. */
				'wf_ty_ref_say'   => __( 'Your friend gets %1$s%% off, and you get %2$s%% on your next order.', 'oc-theme' ),
				'wf_ty_copy'      => __( 'Copy', 'oc-theme' ),
				'wf_ty_survey'    => __( 'How did it go?', 'oc-theme' ),
				'wf_ty_words'     => __( 'A word or two, if you feel like it', 'oc-theme' ),
				'wf_ty_wa'        => __( 'Join our WhatsApp group', 'oc-theme' ),
				'wf_ty_join'      => __( 'Join', 'oc-theme' ),
				'wf_ty_follow'    => __( 'Follow us', 'oc-theme' ),
				'wf_ups_h'        => __( 'You may also like', 'oc-theme' ),
				'wf_xs_h'         => __( 'Goes well with', 'oc-theme' ),
				'wf_bt_h'         => __( 'Bought together', 'oc-theme' ),
				'wf_cart'         => __( 'My cart', 'oc-theme' ),
				'wf_cart_n'       => __( '2 items', 'oc-theme' ),
				/* translators: %s: a sum of money. */
				'wf_cart_ship'    => __( '%s more and the delivery is on us', 'oc-theme' ),
				'wf_coupon'       => __( 'Coupon code', 'oc-theme' ),
				'wf_apply'        => __( 'Apply', 'oc-theme' ),
				'wf_total'        => __( 'Total', 'oc-theme' ),
				'wf_subtotal'     => __( 'Subtotal', 'oc-theme' ),
				'wf_shipping'     => __( 'Shipping', 'oc-theme' ),
				'wf_to_checkout'  => __( 'Checkout', 'oc-theme' ),
				'wf_keep_on'      => __( 'Continue shopping', 'oc-theme' ),
				'wf_upsell'       => __( 'You may also like', 'oc-theme' ),
				'wf_ck_other'     => __( "I'm sending to someone else", 'oc-theme' ),
				'wf_ck_rec'       => __( 'Recipient details', 'oc-theme' ),
				'wf_ck_rec_phone' => __( "Recipient's additional phone", 'oc-theme' ),
				'wf_ck_name'      => __( 'Full name', 'oc-theme' ),
				'wf_ck_first'     => __( 'First name', 'oc-theme' ),
				'wf_ck_last'      => __( 'Last name', 'oc-theme' ),
				'wf_ck_how'       => __( 'Delivery method', 'oc-theme' ),
				'wf_ck_where'     => __( 'Delivery address', 'oc-theme' ),
				'wf_ck_courier'   => __( 'Courier to the door', 'oc-theme' ),
				'wf_ck_pickup'    => __( 'Collection in person', 'oc-theme' ),
				'wf_ck_phone'     => __( 'Phone', 'oc-theme' ),
				'wf_ck_mail'      => __( 'Email', 'oc-theme' ),
				'wf_ck_street'    => __( 'Street and number', 'oc-theme' ),
				'wf_ck_city'      => __( 'City', 'oc-theme' ),
				'wf_ck_apt'       => __( 'Apartment', 'oc-theme' ),
				'wf_ck_floor'     => __( 'Floor', 'oc-theme' ),
				'wf_ck_entry'     => __( 'Entry code', 'oc-theme' ),
				'wf_ck_ship'      => __( 'Delivery', 'oc-theme' ),
				'wf_ck_home'      => _x( 'Home', 'address label', 'oc-theme' ),
				'wf_ck_work'      => _x( 'Work', 'address label', 'oc-theme' ),
				'wf_ck_addr_new'  => __( 'A new address', 'oc-theme' ),
				'wf_ck_sum'       => __( 'Order summary', 'oc-theme' ),
				'wf_ck_show'      => __( 'Show', 'oc-theme' ),
				'wf_ck_has'       => __( 'Have a coupon?', 'oc-theme' ),
				'wf_ck_pay'       => __( 'Place the order', 'oc-theme' ),
				'wf_whats'        => __( 'WhatsApp', 'oc-theme' ),
				'wf_call'         => __( 'Call now', 'oc-theme' ),
				'wf_tab_about'    => __( 'About the product', 'oc-theme' ),
				'wf_tab_ship'     => __( 'Delivery and returns', 'oc-theme' ),
				'wf_tab_short'    => __( 'In short', 'oc-theme' ),
				'wf_top'          => __( 'Free delivery over 400 ILS · 12 payments, no interest', 'oc-theme' ),
				'wf_posts_h'      => __( 'From the magazine', 'oc-theme' ),
				/* translators: %1$s: a stand-in number, so the sketch's article names differ. */
				'wf_post'         => __( 'Article %1$s', 'oc-theme' ),
				'row_up'          => __( 'Move up', 'oc-theme' ),
				'row_down'        => __( 'Move down', 'oc-theme' ),
				'row_hide'        => __( 'Hide this part', 'oc-theme' ),
				'row_show'        => __( 'Show this part', 'oc-theme' ),
				'row_copy'        => __( 'Another one like it', 'oc-theme' ),
				'row_drop'        => __( 'Remove', 'oc-theme' ),
				'row_title'       => __( 'The heading above it', 'oc-theme' ),
				'row_text'        => __( 'The words that run across', 'oc-theme' ),
				'row_add'         => __( 'Add:', 'oc-theme' ),
				'row_which'       => __( 'Which products?', 'oc-theme' ),
				'kept'            => __( 'Every answer is kept the moment you give it. You can close this and come back whenever you like — the same link brings you back to where you stopped.', 'oc-theme' ),
				'menu_name'       => __( 'A department — Sofas, Lighting, Dining', 'oc-theme' ),
				'menu_sub'        => __( 'Inside it — Three-seaters, Armchairs', 'oc-theme' ),
				'menu_add'        => __( 'Add a department', 'oc-theme' ),
				'menu_add_sub'    => __( 'Something inside it', 'oc-theme' ),
				'menu_sub2'       => __( 'And inside that — Leather, Fabric', 'oc-theme' ),
				'menu_add_sub2'   => __( 'Add one inside this', 'oc-theme' ),
				'menu_drop'       => __( 'Take it out', 'oc-theme' ),
				'menu_none'       => __( 'No departments yet. Add the first one and the menu above fills in.', 'oc-theme' ),
				'brands_first'    => __( 'No brands listed yet — go back and write them, one per line.', 'oc-theme' ),
				/* translators: %1$s: how many have to be ticked. */
				'pick_at_least'   => __( 'Choose at least %1$s.', 'oc-theme' ),
				'wf_nothing'      => __( 'This part is not on the page', 'oc-theme' ),
				'menu_first'      => __( 'The menu is still empty — go back a screen and name your departments first.', 'oc-theme' ),
				'rows_kept'       => __( 'parts on the page', 'oc-theme' ),
				'row_none'        => __( 'The page is empty. Add a part, or go back to the arrangement we suggest.', 'oc-theme' ),
				'row_reset'       => __( 'Back to the standard arrangement', 'oc-theme' ),
				'row_reset_warn'  => __( 'The page goes back to the arrangement we suggest, and everything you changed here is written over.', 'oc-theme' ),
				'row_reset_go'    => __( 'Yes, put it back', 'oc-theme' ),
				'cancel'          => __( 'Leave it as it is', 'oc-theme' ),
				'tab_fields'      => __( 'Settings', 'oc-theme' ),
				'tab_sketch_d'    => __( 'The sketch (desktop)', 'oc-theme' ),
				'tab_sketch_m'    => __( 'The sketch (phone)', 'oc-theme' ),
				'sketch_m'        => __( 'The page as a phone shows it — it changes as you answer', 'oc-theme' ),
				'wf_eyebrow'      => __( 'A SMALL HEADING', 'oc-theme' ),
				'wf_read'         => __( 'Read more', 'oc-theme' ),
				'wf_brands_h'     => __( 'The brands we carry', 'oc-theme' ),
				'wf_faq_h'        => __( 'Questions and answers', 'oc-theme' ),
				'wf_faq_1'        => __( 'How long does delivery take?', 'oc-theme' ),
				'wf_faq_2'        => __( 'Can I return a product?', 'oc-theme' ),
				'wf_faq_3'        => __( 'Do you have a shop I can visit?', 'oc-theme' ),
				'wf_story_h'      => __( 'Our story', 'oc-theme' ),
				'wf_sofa'         => __( 'Nordic three-seater sofa', 'oc-theme' ),
				/* translators: %1$s: this screen's number, %2$s: how many in this step. */
				'screen_of'       => __( 'Screen %1$s of %2$s', 'oc-theme' ),
				'wf_filters'      => __( 'Filter', 'oc-theme' ),
				'wf_more'         => __( 'Loading more…', 'oc-theme' ),
				'wf_cats'         => array(
					__( 'Category 1', 'oc-theme' ),
					__( 'Category 2', 'oc-theme' ),
					__( 'Category 3', 'oc-theme' ),
					__( 'Category 4', 'oc-theme' ),
				),
				'consent_upload'  => __( 'I will upload my own instead', 'oc-theme' ),
				'found_pages'     => __( 'We found these pages on your current site and filled the addresses in. Have a look that they are the right ones.', 'oc-theme' ),
				'found_hint'      => __( 'We found this address on your site. Change it if it is the wrong page.', 'oc-theme' ),
				'link_stale'      => __( 'This link is no longer active. Open the newest link we sent you, and nothing you filled in will be lost.', 'oc-theme' ),
				'branches_first'  => __( 'Add your branches on the previous screen and they will appear here.', 'oc-theme' ),
				'branch_word'     => __( 'Branch', 'oc-theme' ),
				/* translators: %1$s: the branch's name. */
				'pick_from'       => __( 'Collection from %1$s', 'oc-theme' ),
				/* translators: %1$s: how many branches. */
				'branches_on'     => __( '%1$s branches', 'oc-theme' ),
			),
		);
	}

	/**
	 * The HTML around the app.
	 *
	 * @param string                   $body   The inner HTML.
	 * @param array<string,mixed>|null $config Config for the script, or null for a static page.
	 */
	private function shell( string $body, ?array $config ): void {
		$logo_id = (int) get_theme_mod( 'custom_logo', 0 );
		$logo    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$dir     = is_rtl() ? 'rtl' : 'ltr';
		$css     = oc_asset_min( '/assets/css/onboard.css' );
		$js      = oc_asset_min( '/assets/js/onboard.js' );
		$fonts   = OC_THEME_URI . '/assets/fonts/assistant.css';
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Setting up %s', 'oc-theme' ), (string) get_bloginfo( 'name' ) ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $fonts ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- a page of its own, outside wp_head. ?>
	<link rel="stylesheet" href="<?php echo esc_url( OC_THEME_URI . $css . '?v=' . self::stamp( $css ) ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- same. ?>
	<style>:root{--onb-primary:#0143a5;--onb-cta:#0143a5}</style>
</head>
<body class="oc-onb-body">
	<header class="oc-onb-top">
		<span class="oc-onb-top__brand">
			<img class="oc-onb-top__oc" src="<?php echo esc_url( OC_THEME_URI . '/assets/img/oc-credit.svg' ); ?>" alt="Original Concepts" width="88" height="46">
			<?php if ( $logo ) : ?>
				<img class="oc-onb-top__logo" src="<?php echo esc_url( $logo ); ?>" alt="">
			<?php endif; ?>
		</span>
		<span class="oc-onb-top__save" id="oc-onb-save" aria-live="polite"></span>
	</header>
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
		<?php if ( null !== $config ) : ?>
		<script>window.ocOnboard = <?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE ); ?>;</script>
		<script src="<?php echo esc_url( OC_THEME_URI . $js . '?v=' . self::stamp( $js ) ); ?>" defer></script><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- a page of its own, outside wp_footer. ?>
		<?php endif; ?>
</body>
</html>
		<?php
	}

	/**
	 * An asset's own age as its version. The theme version does not move
	 * between two builds of the same release, and a customer holding this
	 * page open for days must not be served yesterday's script.
	 *
	 * @param string $relative Path relative to the theme root.
	 */
	private static function stamp( string $relative ): string {
		$path = OC_THEME_DIR . $relative;

		return (string) ( file_exists( $path ) ? filemtime( $path ) : ( defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '1' ) );
	}

	/**
	 * The twelve months the short way the shop writes them, so the sketch's
	 * delivery dates read exactly like the ones the product page prints.
	 *
	 * Hebrew abbreviates a month with a geresh — ספט׳, not ספט — which
	 * WordPress's own short names leave off. A month that is not shortened
	 * at all (מאי, מרץ) takes none.
	 *
	 * @return array<int,string>
	 */
	private static function months(): array {
		$out = array();
		$he  = 0 === strpos( get_locale(), 'he' );

		for ( $m = 1; $m <= 12; $m++ ) {
			$when  = mktime( 12, 0, 0, $m, 1, (int) gmdate( 'Y' ) );
			$short = (string) wp_date( 'M', $when );

			if ( $he && $short !== (string) wp_date( 'F', $when ) && '׳' !== mb_substr( $short, -1 ) ) {
				$short .= '׳';
			}

			$out[] = $short;
		}

		return $out;
	}

	/**
	 * When the link is spent.
	 */
	private function expired_html(): string {
		return '<div class="oc-onb oc-onb--static"><div class="oc-onb__card"><h1>' . esc_html__( 'This link is no longer active', 'oc-theme' ) . '</h1><p>' . esc_html__( 'It may have expired or been replaced. Write to us and we will send a fresh one.', 'oc-theme' ) . '</p></div></div>';
	}
}
