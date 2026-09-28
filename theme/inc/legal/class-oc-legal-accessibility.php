<?php
/**
 * The accessibility statement: the page regulation 35(e) of the service
 * accessibility regulations asks every business site to publish — the
 * standard the site follows, the adaptations it really has, how to adjust
 * the display from the browser, what the premises offer, and who to call.
 *
 * Written from a template built into the theme; the facts on it (company,
 * coordinator, branches) are shortcodes, so the page follows the store
 * details screen instead of freezing a copy of it.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Legal;

use OC\Theme\Contact;

defined( 'ABSPATH' ) || exit;

/**
 * Accessibility statement: page, premises table, footer link.
 */
final class Accessibility {

	const OPTION = 'oc_a11y_page';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_shortcode( 'oc_a11y_table', array( __CLASS__, 'table' ) );
		add_action( 'admin_post_oc_legal_a11y', array( $this, 'handle' ) );
		add_action( 'oc_footer_legal', array( __CLASS__, 'footer_link' ), 20 );
	}

	/**
	 * The statement page we wrote, if it still exists.
	 */
	public static function page_id(): int {
		$id = (int) get_option( self::OPTION, 0 );

		if ( $id < 1 ) {
			return 0;
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post && 'trash' !== $post->post_status ? $id : 0;
	}

	/**
	 * The button on the store details screen.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_legal_a11y' );

		$id = self::create_page();

		wp_safe_redirect( add_query_arg( array( 'oc_page' => 'a11y', 'ok' => $id > 0 ? 1 : 0 ), admin_url( 'admin.php?page=oc-contact' ) ) );
		exit;
	}

	/**
	 * Writes the statement page, or rewrites the one we wrote before.
	 *
	 * @return int The page id, 0 on failure.
	 */
	public static function create_page(): int {
		$existing = self::page_id();
		$args     = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Accessibility statement', 'oc-theme' ),
			'post_content' => self::page_html(),
			'post_name'    => 'accessibility-statement',
		);

		if ( $existing > 0 ) {
			$args['ID'] = $existing;
			$id         = wp_update_post( $args, true );
		} else {
			$id = wp_insert_post( $args, true );
		}

		if ( is_wp_error( $id ) || (int) $id < 1 ) {
			return 0;
		}

		update_option( self::OPTION, (int) $id, false );

		return (int) $id;
	}

	/**
	 * The footer link, next to the privacy one, once the page exists.
	 */
	public static function footer_link(): void {
		$id = self::page_id();

		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			return;
		}

		echo '<a class="oc-footer__a11y" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'Accessibility statement', 'oc-theme' ) . '</a>';
	}

	/**
	 * [oc_a11y_table] — the premises, one row per branch (or the one store),
	 * every item of the checklist as a column, yes or no in words. A shop
	 * that only sells online says so instead.
	 */
	public static function table(): string {
		$rows = Contact::a11y_rows();

		if ( ! $rows ) {
			return '<p>' . esc_html__( 'The service is provided online only; the business has no store open to the public.', 'oc-theme' ) . '</p>';
		}

		$items = Contact::access_items();
		$html  = '<div class="oc-a11y-table"><table>'
			. '<caption class="screen-reader-text">' . esc_html__( 'Accessibility of the premises', 'oc-theme' ) . '</caption>'
			. '<thead><tr><th scope="col">' . esc_html__( 'Branch', 'oc-theme' ) . '</th>';

		foreach ( $items as $label ) {
			$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}

		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$html .= '<tr><th scope="row">' . esc_html( $row['name'] ) . '</th>';

			foreach ( array_keys( $items ) as $key ) {
				$has   = ! empty( $row['access'][ $key ] );
				$html .= '<td class="' . ( $has ? 'is-yes' : 'is-no' ) . '"><i aria-hidden="true">' . ( $has ? '✓' : '✕' ) . '</i> ' . esc_html( $has ? __( 'Yes', 'oc-theme' ) : __( 'No', 'oc-theme' ) ) . '</td>';
			}

			$html .= '</tr>';
		}

		return $html . '</tbody></table></div>';
	}

	/**
	 * The statement, in the site's language.
	 */
	public static function page_html(): string {
		$hebrew = 0 === strpos( get_locale(), 'he' );
		$date   = wp_date( $hebrew ? 'j.n.Y' : 'F j, Y' );

		return $hebrew ? self::hebrew( $date ) : self::english( $date );
	}

	/**
	 * The Hebrew statement.
	 *
	 * @param string $date Today.
	 */
	private static function hebrew( string $date ): string {
		return <<<HTML
<p><em>עודכן לאחרונה: {$date}</em></p>

<h2>המחויבות שלנו</h2>
<p>[oc_site company] ("העסק") מפעיל את האתר [oc_site domain] ורואה חשיבות רבה במתן שירות שוויוני ונגיש לכלל הציבור, ובכלל זה לאנשים עם מוגבלות. אנו פועלים בהתאם לחוק שוויון זכויות לאנשים עם מוגבלות, התשנ"ח-1998, ולתקנות שוויון זכויות לאנשים עם מוגבלות (התאמות נגישות לשירות), התשע"ג-2013 — ובראשן תקנה 35, המחילה על אתרי אינטרנט את תקן ישראלי 5568 ברמה AA, המבוסס על הנחיות הנגישות WCAG של ארגון W3C.</p>

<h2>רמת הנגישות של האתר</h2>
<p>האתר תוכנן ונבנה כך שהוא נגיש בעצמו, ללא צורך ברכיב או ב"סרגל נגישות" חיצוני: ההתאמות מובנות בעמודים ופועלות בכל דפדפן ובכל מכשיר, יחד עם כלי הנגישות של מערכת ההפעלה והדפדפן. האתר נבדק לפי הנחיות WCAG 2.1 ברמה AA בעזרת כלי הבדיקה axe ובניווט מקלדת ידני, בדפדפן Chrome עדכני במחשב ובטלפון.</p>

<h2>ההתאמות שבוצעו באתר</h2>
<ul>
<li>ניווט מלא במקלדת: מעבר בין הרכיבים ב-Tab וב-Shift+Tab, הפעלה ב-Enter או ברווח, סגירה ב-Esc, וקישור "דילוג לתוכן" בראש כל עמוד.</li>
<li>סימון ברור של הרכיב שבמיקוד המקלדת.</li>
<li>חלונות הנפתחים מעל העמוד (סל הקניות, התפריט, בחירת אפשרויות מוצר): המיקוד עובר אליהם, נשאר בתוכם עד לסגירה וחוזר למקום שממנו נפתחו.</li>
<li>שמות ותפקידים לכל רכיבי הניווט — כפתורים, חצים, נקודות גלריה ושדות — הודעות חיות על עדכון הסל, ומבנה כותרות תקין, לתמיכה בקוראי מסך.</li>
<li>טקסט חלופי לתמונות המוצרים.</li>
<li>ניגודיות של 4.5:1 לפחות בין טקסט לרקע ברכיבי התבנית.</li>
<li>הגדלת הטקסט עד 200% ללא אובדן תוכן או פונקציונליות, ופריסה המסתגלת למסכים צרים ולהגדלה.</li>
<li>כיבוד הגדרת "הפחתת תנועה" של מערכת ההפעלה — אנימציות מצומצמות למי שביקש זאת. אין תוכן מהבהב.</li>
<li>טפסים עם תוויות ברורות, הודעות שגיאה בטקסט וללא הגבלת זמן למילוי.</li>
<li>הגדרת שפת המסמך וכיוון הכתיבה, כך שקוראי מסך מקריאים נכון.</li>
</ul>

<h2>איך להתאים את התצוגה בדפדפן</h2>
<ul>
<li><strong>הגדלה והקטנה:</strong> לחצו Ctrl ו-+ (במק: ⌘ ו-+) להגדלה, Ctrl ו-− להקטנה, ו-Ctrl ו-0 לחזרה לגודל הרגיל.</li>
<li><strong>גודל טקסט קבוע:</strong> בהגדרות הדפדפן ניתן לקבוע גודל גופן גדול יותר לכל האתרים — האתר מכבד את ההגדרה.</li>
<li><strong>ניגודיות גבוהה:</strong> ב-Windows הפעילו מצב ניגודיות גבוהה (Alt + Shift + Print Screen); במק: הגדרות המערכת ← נגישות ← תצוגה ← "הגבר ניגודיות". האתר מציג את צבעי המערכת.</li>
<li><strong>קורא מסך:</strong> האתר פועל עם NVDA ו-JAWS ב-Windows, עם VoiceOver במק ובאייפון, ועם TalkBack באנדרואיד.</li>
</ul>

<h2>מה עדיין לא נגיש במלואו</h2>
<p>למרות מאמצינו ייתכן שחלקים מסוימים באתר אינם נגישים עדיין, ובהם תכנים של צדדים שלישיים המוטמעים בו (מפות, סרטונים, עמוד התשלום של חברת הסליקה), קבצים שהועלו לאתר לפני מועד ההנגשה ותוכן שכתבו גולשים. אם נתקלתם בקושי — נשמח שתדווחו לנו ונטפל בכך.</p>

<h2>נגישות השירות בחנות</h2>
[oc_a11y_table]

<h2>רכז/ת הנגישות ופנייה בנושא</h2>
<p>לכל שאלה, בקשה להתאמה או דיווח על בעיית נגישות ניתן לפנות אל [oc_site a11y_coordinator]: [oc_site a11y_line]. נשתדל להשיב בהקדם, ובכל מקרה נטפל בכל פנייה.</p>
<p>הצהרה זו נבדקת ומתעדכנת אחת לשנה לפחות, ובכל שינוי מהותי באתר.</p>
HTML;
	}

	/**
	 * The English statement.
	 *
	 * @param string $date Today.
	 */
	private static function english( string $date ): string {
		return <<<HTML
<p><em>Last updated: {$date}</em></p>

<h2>Our commitment</h2>
<p>[oc_site company] ("the business") operates [oc_site domain] and is committed to a service that is equal and accessible to everyone, including people with disabilities. We act under the Equal Rights for Persons with Disabilities Law, 5758-1998, and the Equal Rights for Persons with Disabilities (Service Accessibility Adjustments) Regulations, 5773-2013 — first among them regulation 35, which applies Israeli Standard 5568 at level AA, based on the W3C Web Content Accessibility Guidelines (WCAG), to websites.</p>

<h2>The site's level of accessibility</h2>
<p>The site was designed and built to be accessible in itself, with no external widget or "accessibility toolbar": the adaptations are part of every page and work in every browser and on every device, together with the accessibility tools of the operating system and the browser. The site is tested against WCAG 2.1 level AA with the axe checker and by hand with the keyboard, in a current Chrome on desktop and on a phone.</p>

<h2>Adaptations on the site</h2>
<ul>
<li>Full keyboard operation: Tab and Shift+Tab move between elements, Enter or Space activate, Esc closes, and a "skip to content" link opens every page.</li>
<li>A clear mark on the element that has keyboard focus.</li>
<li>Panels that open over the page (the cart, the menu, product options): focus moves into them, stays inside until they close, and returns to where it came from.</li>
<li>Names and roles on every control — buttons, arrows, gallery dots, fields — live announcements when the cart changes, and a sound heading structure, for screen readers.</li>
<li>Alternative text on product images.</li>
<li>A contrast of at least 4.5:1 between text and background in the theme's components.</li>
<li>Text enlargeable to 200% with no loss of content or function, and a layout that adapts to narrow screens and to zoom.</li>
<li>The operating system's "reduce motion" setting is honoured — animation is cut for those who asked. Nothing flashes.</li>
<li>Forms with clear labels, errors described in text, and no time limit.</li>
<li>The document's language and writing direction are declared, so screen readers read it correctly.</li>
</ul>

<h2>Adjusting the display in your browser</h2>
<ul>
<li><strong>Zoom:</strong> press Ctrl and + (on a Mac, ⌘ and +) to enlarge, Ctrl and − to shrink, Ctrl and 0 to return to the normal size.</li>
<li><strong>A fixed larger text size:</strong> the browser's settings let you choose a larger font for every site — the site honours it.</li>
<li><strong>High contrast:</strong> on Windows turn on high-contrast mode (Alt + Shift + Print Screen); on a Mac, System Settings → Accessibility → Display → "Increase contrast". The site shows the system colours.</li>
<li><strong>Screen readers:</strong> the site works with NVDA and JAWS on Windows, VoiceOver on Mac and iPhone, and TalkBack on Android.</li>
</ul>

<h2>What may not be fully accessible yet</h2>
<p>Despite our efforts, some parts of the site may not be fully accessible yet, among them third-party content embedded in it (maps, videos, the payment provider's page), files uploaded before the site was made accessible, and content written by visitors. If you run into a difficulty, please tell us and we will take care of it.</p>

<h2>Accessibility of the premises</h2>
[oc_a11y_table]

<h2>Accessibility coordinator and how to reach us</h2>
<p>For any question, request for an adjustment or report of an accessibility problem, contact [oc_site a11y_coordinator]: [oc_site a11y_line]. We will answer as soon as we can and act on every report.</p>
<p>This statement is reviewed and updated at least once a year and after any material change to the site.</p>
HTML;
	}
}
