<?php
/**
 * Terms of sale: the page the checkout consent points at, written for the
 * kind of store the owner chose — general retail or food — with the figures
 * the Consumer Protection Law fixes (14 days, 4 months, 5% or ₪100, the
 * exceptions) and the clauses that only some stores need (made-to-order
 * products, large items).
 *
 * Written from a template built into the theme; the facts on it (operator,
 * contact channels, hours) are shortcodes, so the page follows the store
 * details screen instead of freezing a copy of it.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Legal;

use OC\Theme\Contact;

defined( 'ABSPATH' ) || exit;

/**
 * Terms page: generator and the checkout wiring.
 */
final class Terms {

	const OPTION = 'oc_terms_page';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_post_oc_legal_terms', array( $this, 'handle' ) );
	}

	/**
	 * The terms page we wrote, if it still exists.
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

		check_admin_referer( 'oc_legal_terms' );

		$id = self::create_page();

		wp_safe_redirect( add_query_arg( array( 'oc_page' => 'terms', 'ok' => $id > 0 ? 1 : 0 ), admin_url( 'admin.php?page=oc-contact' ) ) );
		exit;
	}

	/**
	 * Writes the terms page (or rewrites the one we wrote before) and makes
	 * it the WooCommerce terms page, so the checkout consent box and the
	 * checkout side panel point at it without another setting.
	 *
	 * @return int The page id, 0 on failure.
	 */
	public static function create_page(): int {
		$existing = self::page_id();
		$args     = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Terms of sale', 'oc-theme' ),
			'post_content' => self::page_html(),
			'post_name'    => 'terms',
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
		update_option( 'woocommerce_terms_page_id', (int) $id );

		return (int) $id;
	}

	/**
	 * The terms, in the site's language, for the store as described.
	 */
	public static function page_html(): string {
		$s      = Contact::settings();
		$hebrew = 0 === strpos( get_locale(), 'he' );
		$opts   = array(
			'food'     => 'food' === $s['terms_kind'],
			'custom'   => ! empty( $s['terms_custom'] ),
			'bulky'    => ! empty( $s['terms_bulky'] ),
			'physical' => (bool) Contact::a11y_rows(),
			'branches' => (bool) Contact::branches(),
			'date'     => wp_date( $hebrew ? 'j.n.Y' : 'F j, Y' ),
		);

		return $hebrew ? self::hebrew( $opts ) : self::english( $opts );
	}

	/**
	 * The Hebrew terms.
	 *
	 * @param array<string,mixed> $o Store options and today's date.
	 */
	private static function hebrew( array $o ): string {
		$pickup = $o['branches'] ? 'או ייאספו על ידי הלקוח מאחד מסניפי העסק, ' : 'או ייאספו על ידי הלקוח, ';

		$bulky = $o['bulky'] ? <<<HTML

<p><strong>פריטים גדולים:</strong> על הלקוח לוודא לפני ההזמנה שניתן להכניס את המוצר לבית — דרך הגישה, חדר המדרגות, המעלית ופתחי הדלתות. המשלוח כולל הובלה עד לדירת הלקוח עד לקומה שלישית ללא מעלית; מעבר לכך, או כשנדרשת הובלה בעזרת מנוף או אמצעי מיוחד, ייגבה תשלום נוסף בתיאום מראש. הרכבה אינה כלולה במחיר אלא אם צוין אחרת בעמוד המוצר. עם קבלת המוצר על הלקוח לבדוק אותו ולציין כל פגם או נזק על תעודת המשלוח; חתימה על תעודת המשלוח ללא הערה מעידה על קבלת המוצר תקין ושלם. מוצר שנקבע לו איסוף עצמי ייאסף בתוך 14 ימי עסקים ממועד ההודעה שהוא מוכן.</p>
HTML
		: '';

		$food_delivery = $o['food'] ? <<<HTML

<p><strong>מוצרי מזון ומוצרים מקוררים:</strong> מוצרים הדורשים קירור נשלחים בהובלה מקוררת או באריזה שומרת קור, ויש להיות זמינים לקבלתם בטווח השעות שתואם. עם המסירה יש להעביר את המוצרים לקירור בהתאם להוראות שעל האריזה; העסק אינו אחראי לאחסון המוצרים לאחר מסירתם.</p>
HTML
		: '';

		$no_cancel = array();

		if ( $o['food'] ) {
			$no_cancel[] = 'מוצרי מזון, מוצרים טריים, מקוררים או מתכלים אחרים ("טובין פסידים")';
		} else {
			$no_cancel[] = 'טובין פסידים — מוצרים שעלולים להתקלקל או להתיישן בתוך זמן קצר';
		}

		$no_cancel[] = 'מוצרים שיוצרו במיוחד בעבור הלקוח, לפי מידותיו, בחירותיו או דרישותיו';
		$no_cancel[] = 'מוצרים הניתנים להקלטה, לשעתוק או לשכפול, שהלקוח פתח את אריזתם המקורית';
		$no_cancel[] = 'מידע ותוכן דיגיטלי שהלקוח קיבל גישה אליו';
		$no_cancel[] = 'שוברי מתנה וכרטיסי מתנה — בכפוף לתנאים המופיעים עליהם';

		$no_cancel_html = '';

		foreach ( $no_cancel as $item ) {
			$no_cancel_html .= '<li>' . $item . '</li>';
		}

		$custom = $o['custom'] ? <<<HTML

<p><strong>מוצרים בהזמנה אישית:</strong> מוצר המיוצר או מותאם לפי הזמנת הלקוח (מידות, צבע, בד, חריטה וכיוצא באלה) הוא מוצר שיוצר במיוחד בעבורו כמשמעותו בחוק. ניתן לבטל את הזמנתו כל עוד לא החלה הכנתו; משהחלה — לא ניתן לבטל את העסקה, אלא אם נמצא במוצר פגם או שאינו תואם את ההזמנה. מועד תחילת ההכנה יימסר ללקוח באישור ההזמנה או בתיאום.</p>
HTML
		: '';

		$physical = $o['physical'] ? <<<HTML

<p>רכישה בחנות הפיזית של העסק כפופה לתקנות הגנת הצרכן (ביטול עסקה), התשע"א-2010, ולמדיניות ההחזרות המוצגת בחנות.</p>
HTML
		: '';

		$food_more = $o['food'] ? <<<HTML

<p><strong>מוצרים הנמכרים במשקל:</strong> ייתכנו סטיות של עד 10% בין המשקל שהוזמן למשקל שסופק בפועל, והחיוב ייעשה לפי המשקל בפועל. <strong>אלרגנים ורכיבים:</strong> המידע המלא על רכיבי המוצר, האלרגנים והערכים התזונתיים מופיע על אריזת המוצר ובעמוד המוצר; באחריות הלקוח לבדוק את התאמת המוצר לצרכיו לפני הצריכה.</p>
HTML
		: '';

		return <<<HTML
<p><em>עודכן לאחרונה: {$o['date']}</em></p>

<h2>1. כללי</h2>
<p>האתר [oc_site domain] ("האתר") מופעל על ידי [oc_site operator] ("העסק", "אנחנו"). תקנון זה מסדיר את השימוש באתר ואת הרכישה בו, ומהווה הסכם מחייב בין העסק לבין כל מי שגולש באתר או מבצע בו הזמנה ("הלקוח"). גלישה באתר או ביצוע הזמנה מהווים הסכמה לתנאים אלה; מי שאינו מסכים להם מתבקש שלא לעשות שימוש באתר.</p>
<p>התקנון מנוסח בלשון זכר מטעמי נוחות בלבד ומופנה לכל המינים. רשאים לבצע הזמנה בני 18 ומעלה, בעלי אמצעי תשלום תקף. העסק רשאי לעדכן את התקנון מעת לעת; הנוסח המחייב הוא זה המפורסם באתר במועד ביצוע ההזמנה.</p>

<h2>2. המוצרים והמחירים</h2>
<p>המוצרים המוצגים באתר מלווים בתיאור, בתמונות ובמחיר. התמונות נועדו להמחשה; ייתכנו הבדלים קלים בגוון או במראה בין התמונה למוצר, בין היתר בשל הבדלים בין מסכים. המחירים כוללים מע"מ כדין ואינם כוללים דמי משלוח, אלא אם צוין אחרת. העסק רשאי לשנות מחירים ולהפסיק מכירת מוצרים בכל עת; המחיר המחייב הוא זה שהוצג בעת אישור ההזמנה.</p>
<p>אם נפלה טעות ברורה בתיאור מוצר או במחירו, או אם המוצר אזל מהמלאי לאחר ההזמנה, יהיה העסק רשאי לבטל את ההזמנה, יודיע על כך ללקוח, וישיב לו את מלוא הסכום ששולם.</p>

<h2>3. ביצוע הזמנה ותשלום</h2>
<p>ההזמנה מתבצעת באתר, והשלמתה מותנית באישור העסקה על ידי חברת האשראי או ספק התשלום. עם השלמת ההזמנה יישלח ללקוח אישור בדוא"ל; האישור מעיד על קליטת ההזמנה, והעסקה תושלם עם אישור התשלום ואספקת המוצר. פרטי אמצעי התשלום נמסרים ישירות לספק הסליקה, העומד בתקן האבטחה PCI DSS, ואינם נשמרים אצל העסק.</p>
<p>הלקוח מתחייב למסור פרטים נכונים ומלאים. מסירת פרטים כוזבים היא עבירה פלילית, והעסק יהיה רשאי לבטל הזמנה שנמסרו בה פרטים שגויים.</p>

<h2>4. אספקה ומשלוחים</h2>
<p>המוצרים יסופקו לכתובת שמסר הלקוח באמצעות שליח או דואר, {$pickup}לפי הבחירה בקופה. דמי המשלוח ומועדי האספקה המשוערים מוצגים בעמוד המוצר ובקופה לפני התשלום, ונמנים בימי עסקים (ימים א'–ה', למעט ערבי חג, חגים ושבתות). אספקה ליישובים מרוחקים עשויה להימשך זמן רב יותר או להיות כפופה למגבלות של חברת השילוח.</p>
<p>העסק לא יהיה אחראי לעיכוב הנובע מכוח עליון, ממזג אוויר קיצוני, מפעולות איבה, משביתה או מנסיבות אחרות שאינן בשליטתו, ויעדכן את הלקוח ככל שניתן. הודיע העסק כי אינו יכול לספק מוצר במועד — יהיה הלקוח רשאי לבטל את ההזמנה ולקבל את מלוא כספו.</p>{$bulky}{$food_delivery}

<h2>5. ביטול עסקה והחזרת מוצרים</h2>
<p>ביטול עסקה ייעשה בהתאם לחוק הגנת הצרכן, התשמ"א-1981 ("החוק"), ולתקנות הגנת הצרכן (ביטול עסקה), התשע"א-2010, כמפורט להלן.</p>
<ul>
<li><strong>מועד הביטול:</strong> הלקוח רשאי לבטל עסקה שנעשתה באתר בתוך 14 ימים מיום קבלת המוצר או מיום קבלת מסמך הגילוי (אישור ההזמנה עם פרטי העסקה), לפי המאוחר. לקוח שהוא אדם עם מוגבלות, אזרח ותיק או עולה חדש רשאי לבטל בתוך ארבעה חודשים, ובלבד שההתקשרות כללה שיחה עם העסק, לרבות באמצעים אלקטרוניים; העסק רשאי לבקש תעודה המעידה על הזכאות.</li>
<li><strong>איך מבטלים:</strong> בהודעה לעסק בכל אחת מדרכי ההתקשרות המפורטות בסוף התקנון, בציון שם הלקוח, מספר ההזמנה ומספר הזהות. העסק ישלח אישור על קבלת הודעת הביטול.</li>
<li><strong>דמי ביטול:</strong> בביטול שלא בשל פגם או אי-התאמה, רשאי העסק לגבות דמי ביטול בשיעור של 5% ממחיר העסקה או 100 ₪, לפי הנמוך מביניהם. ביטול בשל פגם במוצר, אי-התאמה בין המוצר לבין הפרטים שנמסרו, אי-אספקה במועד או הפרה אחרת של העסק — פטור מדמי ביטול.</li>
<li><strong>החזרת המוצר:</strong> בביטול שלא בשל פגם, יחזיר הלקוח את המוצר לעסק על חשבונו, באריזתו המקורית, שלם וללא שימוש ובצירוף החשבונית. בביטול בשל פגם או אי-התאמה, יאסוף העסק את המוצר מהלקוח על חשבונו. העסק רשאי לתבוע את נזקיו אם ערך המוצר פחת כתוצאה מהרעה משמעותית במצבו.</li>
<li><strong>ההחזר הכספי:</strong> העסק ישיב ללקוח את הסכום ששולם, בניכוי דמי הביטול כשהם חלים, בתוך 14 ימים מקבלת הודעת הביטול, באותו אמצעי תשלום שבו בוצעה העסקה. דמי משלוח ששולמו יוחזרו כשהביטול נובע מפגם או מאי-התאמה.</li>
<li><strong>מוצרים שרכישתם אינה ניתנת לביטול</strong> (סעיף 14ג(ד) לחוק):<ul>{$no_cancel_html}</ul></li>
</ul>{$custom}{$physical}{$food_more}

<h2>6. אחריות למוצרים</h2>
<p>העסק אחראי לכך שהמוצרים יסופקו תקינים, שלמים ותואמים לתיאורם באתר. על מוצרי חשמל ואלקטרוניקה שמחירם עולה על 150 ₪ חלה אחריות של שנה לפחות לפי תקנות הגנת הצרכן (אחריות ושירות לאחר מכירה), התשס"ו-2006, ותעודת האחריות של היצרן או היבואן מצורפת למוצר. מוצר שהתגלה בו פגם יתוקן, יוחלף או יוחזר תמורתו, לפי העניין ובהתאם לדין. האחריות אינה חלה על נזק שנגרם משימוש שאינו סביר, מהתקנה או מטיפול בניגוד להוראות, או מבלאי רגיל.</p>

<h2>7. קניין רוחני</h2>
<p>כל זכויות הקניין הרוחני באתר — לרבות העיצוב, הטקסטים, התמונות, הסימנים המסחריים והקוד — שייכות לעסק או לצדדים שלישיים שהתירו לעסק את השימוש בהם. אין להעתיק, לשכפל, להפיץ או לעשות שימוש מסחרי בתוכן האתר ללא הסכמה מראש ובכתב.</p>

<h2>8. פרטיות</h2>
<p>המידע שנמסר בעת ההזמנה נשמר ומטופל לפי [oc_site privacy_link] של האתר ובהתאם לחוק הגנת הפרטיות, התשמ"א-1981. הלקוח רשאי לבקש בכל עת שלא לקבל דיוור שיווקי.</p>

<h2>9. הגבלת אחריות</h2>
<p>העסק עושה כמיטב יכולתו כדי שהאתר יפעל באופן תקין ורציף, אך אינו מתחייב שהאתר יהיה חסין מתקלות, שגיאות או הפרעות. העסק לא יישא באחריות לנזק עקיף או תוצאתי שייגרם מהשימוש באתר או מהסתמכות על תוכנו, ובכל מקרה אחריותו תוגבל לסכום ששילם הלקוח בעד ההזמנה שבמחלוקת — והכול בכפוף להוראות הדין שאין להתנות עליהן.</p>

<h2>10. דין וסמכות שיפוט</h2>
<p>על תקנון זה ועל כל שימוש באתר יחולו דיני מדינת ישראל בלבד. סמכות השיפוט בכל עניין הנוגע לתקנון ולשימוש באתר נתונה לבית המשפט המוסמך בישראל.</p>

<h2>11. יצירת קשר</h2>
<p>[oc_site operator]<br />[oc_site contact_line][oc_site hours_line]</p>
HTML;
	}

	/**
	 * The English terms.
	 *
	 * @param array<string,mixed> $o Store options and today's date.
	 */
	private static function english( array $o ): string {
		$pickup = $o['branches'] ? 'or collected by the customer from one of the business\'s branches, ' : 'or collected by the customer, ';

		$bulky = $o['bulky'] ? <<<HTML

<p><strong>Large items:</strong> before ordering, the customer must make sure the product can be brought into the home — the access path, the stairwell, the lift and the doorways. Delivery includes carrying the product to the customer's home up to the third floor without a lift; beyond that, or where a crane or special equipment is needed, an extra charge applies and is agreed in advance. Assembly is not included unless the product page says so. On delivery the customer must inspect the product and note any defect or damage on the delivery note; signing the note without a remark confirms the product was received sound and complete. A product set for self-collection must be collected within 14 business days of the notice that it is ready.</p>
HTML
		: '';

		$food_delivery = $o['food'] ? <<<HTML

<p><strong>Food and chilled products:</strong> products that need refrigeration are shipped in a refrigerated vehicle or in cold-keeping packaging, and the customer must be available to receive them within the agreed time window. On delivery the products must be refrigerated as the packaging instructs; the business is not responsible for storage after delivery.</p>
HTML
		: '';

		$no_cancel = array();

		if ( $o['food'] ) {
			$no_cancel[] = 'food, fresh, chilled and other perishable products';
		} else {
			$no_cancel[] = 'perishable goods — products that spoil or expire within a short time';
		}

		$no_cancel[] = 'products made specially for the customer, to their measurements, choices or requirements';
		$no_cancel[] = 'products that can be recorded, reproduced or copied, whose original packaging the customer opened';
		$no_cancel[] = 'information and digital content the customer has been given access to';
		$no_cancel[] = 'gift vouchers and gift cards — subject to the terms printed on them';

		$no_cancel_html = '';

		foreach ( $no_cancel as $item ) {
			$no_cancel_html .= '<li>' . $item . '</li>';
		}

		$custom = $o['custom'] ? <<<HTML

<p><strong>Made-to-order products:</strong> a product made or adapted to the customer's order (dimensions, colour, fabric, engraving and the like) is a product made specially for the customer within the meaning of the law. Its order may be cancelled as long as its preparation has not begun; once it has, the transaction cannot be cancelled unless the product is defective or does not match the order. The customer is told when preparation begins in the order confirmation or by arrangement.</p>
HTML
		: '';

		$physical = $o['physical'] ? <<<HTML

<p>A purchase in the business's physical store is subject to the Consumer Protection (Cancellation of a Transaction) Regulations, 5771-2010, and to the returns policy displayed in the store.</p>
HTML
		: '';

		$food_more = $o['food'] ? <<<HTML

<p><strong>Products sold by weight:</strong> the weight delivered may differ by up to 10% from the weight ordered, and the charge follows the weight actually delivered. <strong>Allergens and ingredients:</strong> the full information on ingredients, allergens and nutritional values appears on the product's packaging and on its page; it is the customer's responsibility to check that the product suits their needs before consuming it.</p>
HTML
		: '';

		return <<<HTML
<p><em>Last updated: {$o['date']}</em></p>

<h2>1. General</h2>
<p>The site [oc_site domain] ("the site") is operated by [oc_site operator] ("the business", "we"). These terms govern the use of the site and purchases on it, and form a binding agreement between the business and anyone who browses the site or places an order on it ("the customer"). Browsing the site or placing an order constitutes acceptance of these terms; anyone who does not accept them is asked not to use the site.</p>
<p>Orders may be placed by persons aged 18 or over holding a valid means of payment. The business may update these terms from time to time; the binding version is the one published on the site when the order is placed.</p>

<h2>2. Products and prices</h2>
<p>The products on the site are shown with a description, pictures and a price. The pictures are for illustration; slight differences in shade or appearance between the picture and the product are possible, among other reasons because screens differ. Prices include VAT as required by law and exclude delivery charges unless stated otherwise. The business may change prices and withdraw products at any time; the binding price is the one shown when the order was confirmed.</p>
<p>If a clear error occurred in a product's description or price, or a product ran out of stock after the order, the business may cancel the order, will tell the customer, and will refund the full amount paid.</p>

<h2>3. Ordering and payment</h2>
<p>Orders are placed on the site and are completed once the credit-card company or payment provider approves the transaction. When the order is completed a confirmation is emailed to the customer; the confirmation records that the order was received, and the transaction is completed when payment is approved and the product delivered. Payment details are passed directly to the PCI DSS-certified payment provider and are not stored by the business.</p>
<p>The customer undertakes to give correct and complete details. Giving false details is a criminal offence, and the business may cancel an order placed with incorrect details.</p>

<h2>4. Delivery</h2>
<p>Products are delivered to the address the customer gave, by courier or post, {$pickup}as chosen at the checkout. Delivery charges and estimated delivery times are shown on the product page and at the checkout before payment, and are counted in business days (Sunday to Thursday, excluding holiday eves, holidays and Saturdays). Delivery to remote localities may take longer or be subject to the carrier's limits.</p>
<p>The business is not responsible for a delay caused by force majeure, extreme weather, hostilities, a strike or other circumstances beyond its control, and will keep the customer informed as far as it can. If the business gives notice that it cannot deliver a product on time, the customer may cancel the order and receive a full refund.</p>{$bulky}{$food_delivery}

<h2>5. Cancellation and returns</h2>
<p>A transaction is cancelled under the Consumer Protection Law, 5741-1981 ("the law"), and the Consumer Protection (Cancellation of a Transaction) Regulations, 5771-2010, as follows.</p>
<ul>
<li><strong>When:</strong> the customer may cancel a transaction made on the site within 14 days of receiving the product or of receiving the disclosure document (the order confirmation with the transaction's details), whichever is later. A customer who is a person with a disability, a senior citizen or a new immigrant may cancel within four months, provided the transaction involved a conversation with the business, including by electronic means; the business may ask for a document showing eligibility.</li>
<li><strong>How:</strong> by notice to the business through any of the channels listed at the end of these terms, giving the customer's name, the order number and ID number. The business will confirm receipt of the cancellation notice.</li>
<li><strong>Cancellation fee:</strong> on a cancellation not due to a defect or non-conformity, the business may charge a cancellation fee of 5% of the transaction price or ₪100, whichever is lower. A cancellation due to a defect in the product, a non-conformity between the product and the details given, late delivery or another breach by the business carries no fee.</li>
<li><strong>Returning the product:</strong> on a cancellation not due to a defect, the customer returns the product to the business at the customer's expense, in its original packaging, complete and unused, with the invoice. On a cancellation due to a defect or non-conformity, the business collects the product from the customer at its own expense. The business may claim its loss if the product's value fell because of a significant deterioration in its condition.</li>
<li><strong>The refund:</strong> the business refunds the amount paid, less the cancellation fee where it applies, within 14 days of receiving the cancellation notice, to the means of payment used for the transaction. Delivery charges paid are refunded when the cancellation is due to a defect or non-conformity.</li>
<li><strong>Purchases that cannot be cancelled</strong> (section 14C(d) of the law):<ul>{$no_cancel_html}</ul></li>
</ul>{$custom}{$physical}{$food_more}

<h2>6. Warranty</h2>
<p>The business is responsible for delivering products that are sound, complete and as described on the site. Electrical and electronic products priced above ₪150 carry a warranty of at least one year under the Consumer Protection (Warranty and After-Sales Service) Regulations, 5766-2006, and the manufacturer's or importer's warranty certificate accompanies the product. A product found defective is repaired, replaced or refunded, as the case may be and as the law provides. The warranty does not cover damage caused by unreasonable use, installation or handling contrary to the instructions, or normal wear.</p>

<h2>7. Intellectual property</h2>
<p>All intellectual property rights in the site — including the design, texts, pictures, trademarks and code — belong to the business or to third parties who allowed the business to use them. The site's content may not be copied, reproduced, distributed or used commercially without prior written consent.</p>

<h2>8. Privacy</h2>
<p>Information given when ordering is kept and handled under the site's [oc_site privacy_link] and the Protection of Privacy Law, 5741-1981. The customer may ask at any time not to receive marketing messages.</p>

<h2>9. Limitation of liability</h2>
<p>The business does its best to keep the site working properly and continuously but does not undertake that the site will be free of faults, errors or interruptions. The business is not liable for indirect or consequential damage arising from use of the site or reliance on its content, and in any event its liability is limited to the amount the customer paid for the order in dispute — all subject to provisions of law that cannot be contracted out of.</p>

<h2>10. Law and jurisdiction</h2>
<p>These terms and any use of the site are governed by the laws of the State of Israel alone. Jurisdiction in any matter concerning these terms and the use of the site lies with the competent court in Israel.</p>

<h2>11. Contact</h2>
<p>[oc_site operator]<br />[oc_site contact_line][oc_site hours_line]</p>
HTML;
	}
}
