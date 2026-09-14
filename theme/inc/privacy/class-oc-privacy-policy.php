<?php
/**
 * The policy page: written for the site, kept true by the site.
 *
 * Two shortcodes keep the page honest without anyone editing it: one
 * lists the cookies and the services that are actually switched on in
 * the theme's marketing settings, the other lets a visitor ask for
 * their data or its deletion through WordPress's own request tools.
 * One button writes a complete policy page in the site's language
 * with the shop's details filled in, and points WordPress at it.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Privacy;

use OC\Theme\Contact;
use OC\Theme\Marketing\Settings as Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Policy page helpers.
 */
final class Policy {

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_shortcode( 'oc_cookie_table', array( __CLASS__, 'cookie_table' ) );
		add_shortcode( 'oc_privacy_request', array( __CLASS__, 'request_form' ) );
		add_action( 'admin_init', array( __CLASS__, 'guide' ) );
		add_action( 'init', array( __CLASS__, 'handle_request' ) );
	}

	/**
	 * The services this site actually runs, from the marketing settings
	 * and the plugins on the site. Each is a row the policy can show.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function services(): array {
		$rows = array(
			array(
				'cat'     => 'necessary',
				'name'    => 'WordPress / WooCommerce',
				'cookies' => 'wordpress_*, wp-settings-*, woocommerce_cart_hash, woocommerce_items_in_cart, wp_woocommerce_session_*, oc_consent',
				'purpose' => __( 'Sign-in, the cart, the checkout, and remembering this very choice.', 'oc-theme' ),
				'keep'    => __( 'Session to 1 year', 'oc-theme' ),
			),
		);

		if ( class_exists( '\OC\Theme\Marketing\Settings' ) ) {
			$m = Marketing::get();

			if ( '' !== $m['ga4']['id'] || '' !== $m['gtm']['id'] ) {
				$rows[] = array(
					'cat'     => 'analytics',
					'name'    => 'Google Analytics',
					'cookies' => '_ga, _ga_*, _gid',
					'purpose' => __( 'Counts visits and pages, aggregated. With Google Consent Mode: nothing is stored without your permission.', 'oc-theme' ),
					'keep'    => __( 'Up to 2 years', 'oc-theme' ),
				);
			}

			if ( '' !== $m['gads']['id'] ) {
				$rows[] = array(
					'cat'     => 'marketing',
					'name'    => 'Google Ads',
					'cookies' => '_gcl_au, _gcl_aw, IDE (google.com)',
					'purpose' => __( 'Measures whether an ad led to a purchase and shows relevant ads on Google.', 'oc-theme' ),
					'keep'    => __( 'Up to 13 months', 'oc-theme' ),
				);
			}

			if ( '' !== $m['fb']['pixel'] ) {
				$rows[] = array(
					'cat'     => 'marketing',
					'name'    => 'Meta (Facebook / Instagram)',
					'cookies' => '_fbp, _fbc, fr (facebook.com)',
					'purpose' => __( 'Measures ads on Facebook and Instagram and shows you relevant ones.', 'oc-theme' ),
					'keep'    => __( 'Up to 3 months', 'oc-theme' ),
				);
			}

			if ( '' !== $m['tiktok']['pixel'] ) {
				$rows[] = array(
					'cat'     => 'marketing',
					'name'    => 'TikTok',
					'cookies' => '_ttp, _tt_enable_cookie',
					'purpose' => __( 'Measures ads on TikTok and shows you relevant ones.', 'oc-theme' ),
					'keep'    => __( 'Up to 13 months', 'oc-theme' ),
				);
			}
		}

		foreach ( (array) get_option( 'active_plugins', array() ) as $one ) {
			if ( false !== stripos( (string) $one, 'flashy' ) ) {
				$rows[] = array(
					'cat'     => 'marketing',
					'name'    => 'Flashy',
					'cookies' => 'flashy_*',
					'purpose' => __( 'Email and SMS marketing: remembers you between visits so messages match what you looked at.', 'oc-theme' ),
					'keep'    => __( 'Up to 1 year', 'oc-theme' ),
				);
				break;
			}
		}

		foreach ( Settings::get()['scripts'] as $row ) {
			if ( '' === $row['name'] ) {
				continue;
			}

			$rows[] = array(
				'cat'     => $row['cat'],
				'name'    => $row['name'],
				'cookies' => '',
				'purpose' => Settings::cat_text( $row['cat'], 'desc' ),
				'keep'    => '',
			);
		}

		/**
		 * The rows the policy's cookie table shows.
		 *
		 * @param array<int,array<string,string>> $rows Rows.
		 */
		return (array) apply_filters( 'oc_privacy_services', $rows );
	}

	/**
	 * [oc_cookie_table] — the live list.
	 */
	public static function cookie_table(): string {
		$out = '<div class="oc-cookie-table"><table><thead><tr>'
			. '<th>' . esc_html__( 'Service', 'oc-theme' ) . '</th>'
			. '<th>' . esc_html__( 'Category', 'oc-theme' ) . '</th>'
			. '<th>' . esc_html__( 'Purpose', 'oc-theme' ) . '</th>'
			. '<th>' . esc_html__( 'Cookies', 'oc-theme' ) . '</th>'
			. '<th>' . esc_html__( 'Kept for', 'oc-theme' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( self::services() as $row ) {
			$out .= '<tr>'
				. '<td>' . esc_html( $row['name'] ) . '</td>'
				. '<td>' . esc_html( Settings::cat_text( $row['cat'], 'label' ) ) . '</td>'
				. '<td>' . esc_html( $row['purpose'] ) . '</td>'
				. '<td class="oc-cookie-table__names">' . esc_html( $row['cookies'] ) . '</td>'
				. '<td>' . esc_html( $row['keep'] ) . '</td>'
				. '</tr>';
		}

		$out .= '</tbody></table>';

		if ( Settings::on() ) {
			$out .= '<p><button type="button" class="oc-cookie-table__open" data-oc-privacy-open>' . esc_html( Settings::text( 'badge' ) ) . '</button></p>';
		}

		return $out . '</div>';
	}

	/**
	 * [oc_privacy_request] — ask for your data, or its deletion. Goes
	 * through WordPress's own request flow: an email confirms the address,
	 * the site owner sees it under Tools, and the export or erasure runs
	 * from there.
	 */
	public static function request_form(): string {
		$msg = '';

		if ( isset( $_GET['oc_privacy_req'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice, nothing acted on.
			$msg = 'sent' === $_GET['oc_privacy_req'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? __( 'Thank you. We sent you an email — please confirm the request from there.', 'oc-theme' )
				: __( 'We could not file that request. Check the address and try again, or write to us directly.', 'oc-theme' );
		}

		$out = '<form class="oc-privacy-req" method="post">'
			. wp_nonce_field( 'oc_privacy_req', '_ocpn', true, false )
			. '<input type="hidden" name="oc_privacy_req_go" value="1" />'
			. ( '' !== $msg ? '<p class="oc-privacy-req__msg">' . esc_html( $msg ) . '</p>' : '' )
			. '<p><label>' . esc_html__( 'Your email address', 'oc-theme' ) . '<br /><input type="email" name="em" required /></label></p>'
			. '<p><label><input type="radio" name="kind" value="export" checked /> ' . esc_html__( 'Send me a copy of the personal data you hold about me', 'oc-theme' ) . '</label><br />'
			. '<label><input type="radio" name="kind" value="erase" /> ' . esc_html__( 'Delete the personal data you hold about me', 'oc-theme' ) . '</label></p>'
			. '<p><button type="submit" class="button">' . esc_html__( 'Send the request', 'oc-theme' ) . '</button></p>'
			. '</form>';

		return $out;
	}

	/**
	 * Files the request with WordPress and sends its confirmation mail.
	 */
	public static function handle_request(): void {
		if ( empty( $_POST['oc_privacy_req_go'] ) ) {
			return;
		}

		if ( ! isset( $_POST['_ocpn'] ) || ! wp_verify_nonce( sanitize_key( (string) wp_unslash( $_POST['_ocpn'] ) ), 'oc_privacy_req' ) ) {
			return;
		}

		$email = sanitize_email( (string) wp_unslash( $_POST['em'] ?? '' ) );
		$kind  = 'erase' === ( $_POST['kind'] ?? '' ) ? 'remove_personal_data' : 'export_personal_data';
		$back  = remove_query_arg( 'oc_privacy_req', wp_get_referer() ? wp_get_referer() : home_url( '/' ) );

		// One request per address per hour, so the form cannot be used to
		// mail somebody over and over.
		$rl = 'oc_privacy_req_' . md5( $email );

		if ( ! is_email( $email ) || get_transient( $rl ) ) {
			wp_safe_redirect( add_query_arg( 'oc_privacy_req', 'fail', $back ) );
			exit;
		}

		set_transient( $rl, 1, HOUR_IN_SECONDS );

		$id = wp_create_user_request( $email, $kind );

		if ( is_wp_error( $id ) ) {
			// A request that already exists is still a success to the
			// visitor: they will get (or have got) the confirmation mail.
			$ok = 'duplicate_request' === $id->get_error_code();
		} else {
			wp_send_user_request( (int) $id );
			$ok = true;
		}

		wp_safe_redirect( add_query_arg( 'oc_privacy_req', $ok ? 'sent' : 'fail', $back ) );
		exit;
	}

	/**
	 * Our part of WordPress's privacy policy guide (Settings → Privacy →
	 * Guide), for a site owner writing their own page.
	 */
	public static function guide(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'oc-theme',
			wp_kses_post( self::page_html( true ) )
		);
	}

	/**
	 * Creates the policy page (or refreshes a page we created before)
	 * and makes it the site's privacy page.
	 *
	 * @return int The page id, 0 on failure.
	 */
	public static function create_page(): int {
		$existing = (int) get_option( 'oc_privacy_page', 0 );
		$args     = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Privacy policy', 'oc-theme' ),
			'post_content' => self::page_html( false ),
			'post_name'    => 'privacy-policy',
		);

		if ( $existing > 0 && 'trash' !== get_post_status( $existing ) && get_post( $existing ) ) {
			$args['ID'] = $existing;
			$id         = wp_update_post( $args, true );
		} else {
			$id = wp_insert_post( $args, true );
		}

		if ( is_wp_error( $id ) || (int) $id < 1 ) {
			return 0;
		}

		update_option( 'oc_privacy_page', (int) $id, false );
		update_option( 'wp_page_for_privacy_policy', (int) $id );

		$s           = Settings::get();
		$s['policy'] = (int) $id;
		Settings::save( $s );

		return (int) $id;
	}

	/**
	 * The policy, written for this site. Every fact that can be read from
	 * the site is read; what only the owner knows is marked so it is not
	 * missed.
	 *
	 * @param bool $guide For the WordPress guide (shorter, no shortcodes).
	 */
	public static function page_html( bool $guide = false ): string {
		$name    = get_bloginfo( 'name' );
		$email   = class_exists( '\OC\Theme\Contact' ) ? Contact::email() : (string) get_option( 'admin_email' );
		$phone   = class_exists( '\OC\Theme\Contact' ) ? Contact::phone() : '';
		$address = trim( implode( ', ', array_filter( array( (string) get_option( 'woocommerce_store_address' ), (string) get_option( 'woocommerce_store_city' ) ) ) ) );
		$hebrew  = 0 === strpos( get_locale(), 'he' );
		$date    = wp_date( $hebrew ? 'j.n.Y' : 'F j, Y' );
		$table   = $guide ? '' : "\n\n[oc_cookie_table]\n\n";
		$form    = $guide ? '' : "\n\n[oc_privacy_request]\n\n";
		$contact = $email . ( '' !== $phone ? ' · ' . $phone : '' ) . ( '' !== $address ? ' · ' . $address : '' );

		if ( $hebrew ) {
			return self::hebrew( $name, $contact, $date, $table, $form );
		}

		return self::english( $name, $contact, $date, $table, $form );
	}

	/**
	 * The Hebrew policy.
	 *
	 * @param string $name    Site name.
	 * @param string $contact Contact line.
	 * @param string $date    Today.
	 * @param string $table   Cookie table shortcode or ''.
	 * @param string $form    Request form shortcode or ''.
	 */
	private static function hebrew( string $name, string $contact, string $date, string $table, string $form ): string {
		return <<<HTML
<p><em>עודכן לאחרונה: {$date}</em></p>

<h2>מי אנחנו</h2>
<p>האתר {$name} ("האתר", "אנחנו") מכבד את פרטיותך. מסמך זה מסביר איזה מידע נאסף כשאתם מבקרים באתר או רוכשים בו, למה הוא משמש, למי הוא מועבר ואילו זכויות עומדות לרשותכם, בהתאם לחוק הגנת הפרטיות, התשמ"א-1981, לתקנות הגנת הפרטיות (אבטחת מידע), התשע"ז-2017, ולדין החל על גולשים ממדינות אחרות.</p>
<p>יצירת קשר בכל עניין הנוגע לפרטיות: {$contact}</p>

<h2>הודעה על איסוף מידע (סעיף 11 לחוק הגנת הפרטיות)</h2>
<p>אין חובה חוקית למסור לנו מידע. מסירת המידע תלויה ברצונכם ובהסכמתכם; עם זאת, בלי פרטים מסוימים (כגון שם, כתובת למשלוח ואמצעי תשלום) לא נוכל לבצע הזמנה. המידע נאסף לצורך אספקת המוצרים והשירותים, מתן שירות לקוחות, שיפור האתר, ובכפוף להסכמתכם - למשלוח דיוור ופרסום. המידע נשמר במאגרי המידע שלנו ומועבר לספקים המפורטים במסמך זה, רק במידה הדרושה לשם כך.</p>

<h2>איזה מידע אנחנו אוספים</h2>
<ul>
<li><strong>פרטי חשבון והזמנה:</strong> שם, טלפון, דוא"ל, כתובת למשלוח וחיוב, היסטוריית הזמנות. פרטי כרטיס האשראי נמסרים ישירות לספק הסליקה ואינם נשמרים אצלנו.</li>
<li><strong>פניות:</strong> מה שכתבתם בטופסי יצירת הקשר, בצ'אט או במייל.</li>
<li><strong>דיוור:</strong> כתובת הדוא"ל או הטלפון שמסרתם לרישום לעדכונים, ותיעוד ההסכמה.</li>
<li><strong>מידע טכני:</strong> כתובת IP, סוג הדפדפן והמכשיר, עמודים שנצפו, ומזהי עוגיות - כמפורט בפרק העוגיות.</li>
</ul>

<h2>למה המידע משמש</h2>
<ul>
<li>ביצוע הזמנות, משלוחים, החזרות ושירות לקוחות.</li>
<li>תפעול ואבטחת האתר ומניעת הונאות.</li>
<li>הבנת השימוש באתר ושיפורו (סטטיסטיקה).</li>
<li>שיווק ופרסום מותאם - רק אם אישרתם זאת. אפשר לבטל את ההסכמה בכל עת, מהקישור בכל הודעה או דרך "הגדרות פרטיות" בתחתית האתר.</li>
</ul>

<h2>עוגיות וכלי מדידה</h2>
<p>האתר משתמש בעוגיות (Cookies) ובטכנולוגיות דומות. עוגיות הכרחיות דרושות לפעולת האתר (סל הקניות, התחברות, זכירת הבחירה שלכם בעניין הפרטיות) ופועלות תמיד. עוגיות סטטיסטיקה ושיווק פועלות רק לפי הבחירה שלכם בחלון הפרטיות, ואפשר לשנות אותה בכל עת. בהתאם לבחירתכם, אנו מיישמים את Google Consent Mode, כך שכלי גוגל לא שומרים מידע ללא הרשאה. הרשימה הבאה מתעדכנת אוטומטית לפי הכלים הפעילים באתר:</p>{$table}

<h2>למי המידע מועבר</h2>
<p>לספקים שמסייעים לנו להפעיל את האתר ולספק את ההזמנה: חברות סליקה, חברות משלוחים, ספק האחסון והדיוור, וכלי המדידה והפרסום המפורטים לעיל - כל אחד רק לצורך תפקידו. חלק מהספקים (למשל Google ו-Meta) פועלים מחוץ לישראל; ההעברה נעשית בהתאם לתקנות הגנת הפרטיות (העברת מידע אל מחוץ לגבולות המדינה). איננו מוכרים מידע אישי.</p>

<h2>כמה זמן המידע נשמר</h2>
<p>פרטי הזמנות נשמרים כל עוד נדרש לפי חוק (למשל לצורכי חשבונאות ומס - שבע שנים). פרטי חשבון נשמרים כל עוד החשבון פעיל. תיעוד ההסכמה לעוגיות נשמר עד שנה ממועד מתן ההסכמה. מידע שאינו נדרש עוד נמחק או מונגש באופן שאינו מזהה.</p>

<h2>הזכויות שלכם</h2>
<ul>
<li><strong>עיון ותיקון:</strong> הזכות לעיין במידע שנשמר עליכם ולבקש לתקן מידע שאינו נכון, שלם או מעודכן (סעיפים 13-14 לחוק).</li>
<li><strong>מחיקה:</strong> אפשר למחוק את החשבון בעצמכם מתוך "החשבון שלי", או לבקש מאיתנו מחיקה של המידע שאינו נדרש לפי חוק.</li>
<li><strong>סירוב לדיוור ישיר:</strong> הזכות לדרוש שמידע עליכם לא ישמש לדיוור ישיר (סעיף 17ו לחוק), ולהסיר את עצמכם מכל רשימת תפוצה בלחיצה אחת.</li>
<li><strong>גולשים מהאיחוד האירופי, בריטניה ושווייץ:</strong> הזכויות לפי ה-GDPR - גישה, תיקון, מחיקה, הגבלה, ניידות והתנגדות - וכן הזכות להגיש תלונה לרשות הפיקוח במדינתכם.</li>
</ul>
<p>לשליחת בקשה לקבלת עותק של המידע או למחיקתו:</p>{$form}

<h2>אבטחת מידע</h2>
<p>האתר מוגן בתעבורה מוצפנת (HTTPS), הגישה למידע מוגבלת לעובדים ולספקים שזקוקים לו, והתשלום מתבצע אצל ספק סליקה העומד בתקן PCI-DSS. אנו פועלים בהתאם לתקנות אבטחת המידע, ואם יתגלה אירוע אבטחה חמור, נדווח עליו כנדרש בחוק.</p>

<h2>קטינים</h2>
<p>האתר מיועד לבני 18 ומעלה. איננו אוספים ביודעין מידע על קטינים ללא הסכמת הורה.</p>

<h2>שינויים במדיניות</h2>
<p>ייתכן שנעדכן מסמך זה מעת לעת. הגרסה העדכנית תמיד תופיע בעמוד זה, עם תאריך העדכון בראשו. שינוי מהותי יביא לבקשת הסכמה מחודשת לעוגיות.</p>
HTML;
	}

	/**
	 * The English policy.
	 *
	 * @param string $name    Site name.
	 * @param string $contact Contact line.
	 * @param string $date    Today.
	 * @param string $table   Cookie table shortcode or ''.
	 * @param string $form    Request form shortcode or ''.
	 */
	private static function english( string $name, string $contact, string $date, string $table, string $form ): string {
		return <<<HTML
<p><em>Last updated: {$date}</em></p>

<h2>Who we are</h2>
<p>{$name} ("the site", "we") respects your privacy. This page explains what we collect when you visit or buy here, what it is used for, who it is shared with and what rights you have — under the Israeli Protection of Privacy Law, 5741-1981, its Data Security Regulations (2017), and the law that applies to visitors from other countries, including the GDPR.</p>
<p>Privacy contact: {$contact}</p>

<h2>Notice at collection</h2>
<p>You are not legally required to give us any information. Providing it is your choice; without some of it (a name, a delivery address, a payment method) we cannot fulfil an order. We collect it to supply the products and services you ask for, to provide customer service, to improve the site and — only with your consent — to send you news and offers. It is kept in our databases and shared with the providers named below, only as far as each needs it.</p>

<h2>What we collect</h2>
<ul>
<li><strong>Account and order details:</strong> name, phone, email, delivery and billing address, order history. Card details go straight to the payment provider and are never stored by us.</li>
<li><strong>Messages:</strong> what you write in contact forms, chat or email.</li>
<li><strong>Newsletter:</strong> the email or phone you signed up with, and a record of that consent.</li>
<li><strong>Technical data:</strong> IP address, browser and device, pages viewed and cookie identifiers — see the cookies section.</li>
</ul>

<h2>What it is used for</h2>
<ul>
<li>Orders, delivery, returns and customer service.</li>
<li>Running and securing the site, preventing fraud.</li>
<li>Understanding how the site is used and improving it (statistics).</li>
<li>Marketing and personalised advertising — only if you allowed it. You can withdraw at any time from the link in every message or through "Privacy settings" at the bottom of the site.</li>
</ul>

<h2>Cookies and measurement</h2>
<p>The site uses cookies and similar technologies. Necessary cookies keep it working (the cart, sign-in, remembering your privacy choice) and are always on. Statistics and marketing cookies run only according to your choice in the privacy window, which you can change at any time. We honour that choice through Google Consent Mode, so Google's tools store nothing without permission. The following list updates automatically from the tools active on the site:</p>{$table}

<h2>Who it is shared with</h2>
<p>Providers that help us run the site and fulfil your order: payment processors, delivery companies, our hosting and email providers, and the measurement and advertising tools listed above — each only for its role. Some of them (Google, Meta) operate outside Israel and the EEA; transfers are made under the applicable rules for cross-border transfers. We do not sell personal data.</p>

<h2>How long we keep it</h2>
<p>Order records are kept as long as the law requires (accounting and tax — seven years). Account details are kept while the account is active. Your cookie choice is kept for up to a year. Data no longer needed is deleted or anonymised.</p>

<h2>Your rights</h2>
<ul>
<li><strong>Access and correction:</strong> to see the data we hold about you and have inaccurate data corrected.</li>
<li><strong>Deletion:</strong> delete your account yourself under "My account", or ask us to delete data we are not required to keep.</li>
<li><strong>Direct marketing:</strong> to require that your data is not used for direct marketing, and to unsubscribe from any list with one click.</li>
<li><strong>EU, UK and Swiss visitors:</strong> the GDPR rights of access, rectification, erasure, restriction, portability and objection, and the right to complain to your supervisory authority.</li>
</ul>
<p>To request a copy of your data, or its deletion:</p>{$form}

<h2>Security</h2>
<p>Traffic is encrypted (HTTPS), access to data is limited to the people and providers who need it, and payment is handled by a PCI-DSS compliant processor. We follow the Data Security Regulations and will report a serious security incident as the law requires.</p>

<h2>Children</h2>
<p>The site is for adults. We do not knowingly collect data about minors without a parent's consent.</p>

<h2>Changes</h2>
<p>We may update this page from time to time; the current version always appears here with its date. A material change brings a fresh request for your cookie choice.</p>
HTML;
	}
}
