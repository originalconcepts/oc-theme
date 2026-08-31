<?php
/**
 * The blog: cards on the index, a quiet reading page for one post,
 * comments that people write and robots give up on.
 *
 * The templates stay thin — home.php, archive.php and single.php call the
 * pieces here, so the card drawn on the index and the one on a category
 * archive can never drift apart.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Posts, worn well.
 */
final class Blog {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'comment_form_after_fields', array( $this, 'trap_fields' ) );
		add_action( 'comment_form_logged_in_after', array( $this, 'trap_fields' ) );
		add_filter( 'preprocess_comment', array( $this, 'check_traps' ) );
		add_filter( 'comment_form_default_fields', array( $this, 'fields' ) );
		add_filter( 'comment_form_defaults', array( $this, 'form_words' ) );

		if ( ! is_admin() ) {
			add_filter( 'get_comment_author', array( $this, 'short_name' ) );
		}
	}

	/**
	 * A commenter is a first name and an initial — full names belong to the
	 * people who wrote them, not to every visitor who scrolls past.
	 *
	 * @param string $name Comment author name.
	 * @return string
	 */
	public function short_name( $name ): string {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );

		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return (string) $name;
		}

		return $parts[0] . ' ' . mb_substr( $parts[1], 0, 1 ) . '׳';
	}

	/*
	 * The index card.
	 */

	/**
	 * One post as a card.
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	public static function card( \WP_Post $post ): string {
		$link  = (string) get_permalink( $post );
		$thumb = get_the_post_thumbnail( $post, 'medium_large', array( 'loading' => 'lazy' ) );

		$out = '<article class="oc-bpost">';

		if ( '' !== $thumb ) {
			$out .= '<a class="oc-bpost__media" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">' . $thumb;

			// A film in the post announces itself on the card's corner.
			if ( self::has_video( $post ) ) {
				$out .= '<span class="oc-bpost__play" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="11" opacity=".85"/><path d="M10 8l6 4-6 4z" fill="#fff"/></svg></span>';
			}

			$out .= '</a>';
		}

		$out .= '<div class="oc-bpost__body">';

		if ( get_theme_mod( 'oc_blog_date', true ) ) {
			$out .= '<time class="oc-bpost__date" datetime="' . esc_attr( (string) get_the_date( 'c', $post ) ) . '">' . esc_html( (string) get_the_date( '', $post ) ) . '</time>';
		}

		$out .= '<h2 class="oc-bpost__title"><a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h2>';

		if ( get_theme_mod( 'oc_blog_excerpt', true ) ) {
			$out .= '<p class="oc-bpost__excerpt">' . esc_html( wp_trim_words( (string) get_the_excerpt( $post ), 22 ) ) . '</p>';
		}

		if ( get_theme_mod( 'oc_blog_comments', true ) ) {
			$count = (int) get_comments_number( $post );
			$out  .= '<a class="oc-bpost__comments" href="' . esc_url( $link . '#comments' ) . '">'
				. '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16a1 1 0 011 1v11a1 1 0 01-1 1H8l-5 4V5a1 1 0 011-1z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>'
				/* translators: %s: number of comments. */
				. esc_html( sprintf( _n( '%s comment', '%s comments', $count, 'oc-theme' ), number_format_i18n( $count ) ) )
				. '</a>';
		}

		return $out . '</div></article>';
	}

	/**
	 * Whether a film plays somewhere in the post.
	 *
	 * @param \WP_Post $post The post.
	 */
	public static function has_video( \WP_Post $post ): bool {
		$content = (string) $post->post_content;

		return has_block( 'core/video', $post )
			|| has_block( 'core/embed', $post )
			|| false !== strpos( $content, '<video' )
			|| (bool) preg_match( '~youtu\.?be|vimeo\.com~', $content );
	}

	/**
	 * The category chips over the index — every category that has posts,
	 * with "everything" leading back to the whole blog.
	 *
	 * @return string
	 */
	public static function filter_bar(): string {
		$cats = get_categories( array( 'hide_empty' => true ) );

		if ( count( $cats ) < 2 ) {
			return '';
		}

		$current = get_queried_object();
		$at_home = is_home();
		$blog    = get_option( 'page_for_posts' ) ? (string) get_permalink( (int) get_option( 'page_for_posts' ) ) : home_url( '/' );

		$out = '<nav class="oc-bfilter" aria-label="' . esc_attr__( 'Post categories', 'oc-theme' ) . '">';

		$out .= '<a href="' . esc_url( $blog ) . '"' . ( $at_home ? ' class="is-on"' : '' ) . '>' . esc_html__( 'Everything', 'oc-theme' ) . '</a>';

		foreach ( $cats as $cat ) {
			$on   = $current instanceof \WP_Term && $current->term_id === $cat->term_id;
			$out .= '<a href="' . esc_url( (string) get_category_link( $cat ) ) . '"' . ( $on ? ' class="is-on"' : '' ) . '>' . esc_html( $cat->name ) . '</a>';
		}

		return $out . '</nav>';
	}

	/**
	 * How many cards stand in a row, as a style the grid reads.
	 */
	public static function grid_style(): string {
		return '--oc-blog-cols:' . max( 1, min( 4, absint( get_theme_mod( 'oc_blog_cols', 3 ) ) ) );
	}

	/*
	 * The single post.
	 */

	/**
	 * The share row: the places people here actually send things.
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	public static function share( \WP_Post $post ): string {
		$url   = rawurlencode( (string) get_permalink( $post ) );
		$title = rawurlencode( get_the_title( $post ) );

		$doors = array(
			array( 'WhatsApp', 'https://api.whatsapp.com/send?text=' . $title . '%20' . $url, '<svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 00-8.5 15.3L2 22l4.9-1.4A10 10 0 1012 2zm5 13.9c-.2.7-1.3 1.3-1.9 1.4-.5.1-1.1.1-1.8-.1a16 16 0 01-6.6-5.8c-.6-1-.9-2-.7-2.8.2-.7.9-1.6 1.4-1.7h.8c.2 0 .5 0 .7.6l1 2.3c.1.2.1.4 0 .6l-.5.8c-.2.2-.3.4-.1.7a10 10 0 003.8 3.4c.3.2.5.1.7-.1l.9-1c.2-.3.4-.3.7-.2l2.2 1c.3.2.6.3.6.5.1.1.1.7-.2 1.4z"/></svg>' ),
			array( 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $url, '<svg viewBox="0 0 24 24"><path d="M13.5 22v-8h2.7l.4-3.2h-3.1V8.7c0-.9.3-1.6 1.6-1.6h1.7V4.2c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.4H7.4V14h2.7v8z"/></svg>' ),
			array( 'X', 'https://twitter.com/intent/tweet?url=' . $url . '&text=' . $title, '<svg viewBox="0 0 24 24"><path d="M17.5 3h3.2l-7 8 8.3 10h-6.5l-5.1-6.2L4.6 21H1.4l7.5-8.6L1 3h6.7l4.6 5.6zm-1.1 16.1h1.8L6.7 4.8H4.8z"/></svg>' ),
		);

		$out = '<div class="oc-bshare"><span class="oc-bshare__word">' . esc_html__( 'Share', 'oc-theme' ) . '</span>';

		foreach ( $doors as $door ) {
			$out .= '<a class="oc-bshare__btn" href="' . esc_url( $door[1] ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $door[0] ) . '">' . $door[2] . '</a>';
		}

		$out .= '<button type="button" class="oc-bshare__btn" data-oc-copy="' . esc_attr( (string) get_permalink( $post ) ) . '" aria-label="' . esc_attr__( 'Copy link', 'oc-theme' ) . '">'
			. '<svg viewBox="0 0 24 24"><path d="M10.6 13.4a4 4 0 005.6 0l3.5-3.5a4 4 0 10-5.6-5.6l-1.6 1.6M13.4 10.6a4 4 0 00-5.6 0l-3.5 3.5a4 4 0 105.6 5.6l1.6-1.6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
			. '</button>';

		return $out . '</div>';
	}

	/**
	 * Where the post belongs and what it is about: categories, then tags.
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	public static function belongs( \WP_Post $post ): string {
		$out = '';

		$cats = get_the_category( $post->ID );

		if ( ! empty( $cats ) ) {
			$out .= '<div class="oc-bmeta"><span class="oc-bmeta__word">' . esc_html__( 'Category', 'oc-theme' ) . '</span>';

			foreach ( $cats as $cat ) {
				$out .= '<a class="oc-bmeta__chip" href="' . esc_url( (string) get_category_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>';
			}

			$out .= '</div>';
		}

		$tags = get_the_tags( $post->ID );

		if ( is_array( $tags ) && ! empty( $tags ) ) {
			$out .= '<div class="oc-bmeta oc-bmeta--tags"><span class="oc-bmeta__word">' . esc_html__( 'Tags', 'oc-theme' ) . '</span>';

			foreach ( $tags as $tag ) {
				$out .= '<a class="oc-bmeta__chip oc-bmeta__chip--tag" href="' . esc_url( (string) get_tag_link( $tag ) ) . '">' . esc_html( $tag->name ) . '</a>';
			}

			$out .= '</div>';
		}

		return $out;
	}

	/*
	 * Comments: welcoming to people, discouraging to robots.
	 */

	/**
	 * Two quiet traps in the comment form: a field no person sees (robots
	 * fill everything), and the time the form was opened (robots answer in
	 * under a second).
	 */
	public function trap_fields(): void {
		echo '<p class="oc-cmt-trap" aria-hidden="true"><label>' .
			'<span>' . esc_html__( 'Leave this empty', 'oc-theme' ) . '</span>' .
			'<input type="text" name="oc_hp" value="" tabindex="-1" autocomplete="off" />' .
			'</label></p>';
		echo '<input type="hidden" name="oc_t" value="' . esc_attr( (string) time() ) . '" />';
	}

	/**
	 * Sprung traps end the visit politely.
	 *
	 * @param array<string,mixed> $data Comment data.
	 * @return array<string,mixed>
	 */
	public function check_traps( array $data ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- spam traps, deliberately nonce-free.
		$honey = isset( $_POST['oc_hp'] ) ? (string) wp_unslash( $_POST['oc_hp'] ) : '';
		$since = isset( $_POST['oc_t'] ) ? time() - (int) $_POST['oc_t'] : 999;
		// phpcs:enable

		if ( '' !== $honey || $since < 3 ) {
			wp_die(
				esc_html__( 'Your comment could not be posted.', 'oc-theme' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		// The note under the form is agreed to, not merely shown — unless
		// the site keeps no note at all.
		$note = (string) get_theme_mod(
			'oc_blog_disclaimer',
			__( 'Comments reflect their writers alone. Keep it kind — offensive or promotional comments are removed. Your email stays private and is never shown.', 'oc-theme' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- an agreement box, not an action.
		if ( '' !== trim( $note ) && empty( $_POST['oc_ok'] ) ) {
			wp_die(
				esc_html__( 'Please tick the comment-terms box before posting.', 'oc-theme' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		return $data;
	}

	/**
	 * Name and email stay; the website field goes. Nobody here needs it,
	 * and it is the one field spam exists to fill.
	 *
	 * @param array<string,string> $fields Form fields.
	 * @return array<string,string>
	 */
	public function fields( array $fields ): array {
		unset( $fields['url'] );

		if ( isset( $fields['author'] ) ) {
			$fields['author'] = str_replace( '<input', '<input placeholder="' . esc_attr__( 'Your name', 'oc-theme' ) . '"', $fields['author'] );
		}

		if ( isset( $fields['email'] ) ) {
			$fields['email'] = str_replace( '<input', '<input placeholder="' . esc_attr__( 'Email — never shown', 'oc-theme' ) . '"', $fields['email'] );
		}

		return $fields;
	}

	/**
	 * The form's words and shape.
	 *
	 * @param array<string,mixed> $defaults Form defaults.
	 * @return array<string,mixed>
	 */
	public function form_words( array $defaults ): array {
		$defaults['title_reply'] = __( 'Leave a comment', 'oc-theme' );
		/* translators: %s: the commenter being answered. */
		$defaults['title_reply_to']       = __( 'Answering %s', 'oc-theme' );
		$defaults['cancel_reply_link']    = __( 'Cancel', 'oc-theme' );
		$defaults['title_reply_before']   = '<h3 id="reply-title" class="oc-cmt__ftitle">';
		$defaults['title_reply_after']    = '</h3>';
		$defaults['label_submit']         = __( 'Send comment', 'oc-theme' );
		$defaults['comment_field']        = '<p class="comment-form-comment"><textarea id="comment" name="comment" rows="5" required placeholder="' . esc_attr__( 'Write your comment to the post here…', 'oc-theme' ) . '"></textarea></p>';
		$defaults['comment_notes_before'] = '';

		// Said in the site's own words, not the language pack's mood.
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			$defaults['logged_in_as'] = '<p class="logged-in-as">' . sprintf(
				/* translators: 1: display name, 2: logout url. */
				esc_html__( 'Commenting as %1$s. Not you? %2$s', 'oc-theme' ),
				'<b>' . esc_html( $user->display_name ) . '</b>',
				'<a href="' . esc_url( wp_logout_url( (string) get_permalink() ) ) . '">' . esc_html__( 'Log out', 'oc-theme' ) . '</a>'
			) . '</p>';
		}

		$note = (string) get_theme_mod(
			'oc_blog_disclaimer',
			__( 'Comments reflect their writers alone. Keep it kind — offensive or promotional comments are removed. Your email stays private and is never shown.', 'oc-theme' )
		);

		// Agreed to, not merely shown: the box must be ticked to post. The
		// browser holds the door with `required`; check_traps() holds it
		// again on the server for whoever walks around the browser.
		if ( '' !== trim( $note ) ) {
			$defaults['comment_notes_after'] = '<p class="oc-cmt__note"><label>' .
				'<input type="checkbox" name="oc_ok" value="1" required /> ' .
				'<span>' . esc_html( $note ) . '</span>' .
				'</label></p>';
		}

		return $defaults;
	}
}
