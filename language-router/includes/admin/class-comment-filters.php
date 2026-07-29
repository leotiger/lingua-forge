<?php
/**
 * Class LinguaForge\Router\Admin\CommentFilters
 *
 * "Language" filter dropdown above the Comments list table, and the
 * matching `comments_clauses` query filter — the Comments-screen equivalent
 * of `Filters::render_lang_filter_dropdown()` for Posts/Pages.
 *
 * A comment's effective language is its own `_lf_comment_lang` meta when
 * set (always true for a mirror; true for a canonical comment once it's
 * been translated at least once), falling back to the language of the post
 * it lives on (true for a canonical comment that hasn't been translated
 * yet — showing it under its post's own language is the correct default,
 * since that's the language a moderator would actually see it in).
 *
 * @package LinguaForge\Router\Admin
 * @since   2.7.0
 */

namespace LinguaForge\Router\Admin;

use LinguaForge\Router\Comments\CommentMirror;
use LinguaForge\Router\Router;
use WP_Comment_Query;

if ( ! defined( 'ABSPATH' ) ) exit;

class CommentFilters {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	public function register_hooks(): void {
		add_action( 'restrict_manage_comments', [ $this, 'render_lang_filter_dropdown' ] );
		add_filter( 'comments_clauses', [ $this, 'filter_by_lang' ], 10, 2 );
	}

	public function render_lang_filter_dropdown(): void {
		if ( ! CommentMirror::feature_enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET param reflecting the current filter selection back into the dropdown; no data is written here. Comments list table has no dedicated filter nonce of its own (core's own comment_type/comment_status dropdowns read $_GET the same way).
		$current = isset( $_GET['lf_comment_lang_filter'] ) ? sanitize_key( wp_unslash( $_GET['lf_comment_lang_filter'] ) ) : '';
		?>
		<label class="screen-reader-text" for="lf_comment_lang_filter"><?php esc_html_e( 'Filter by language', 'lingua-forge' ); ?></label>
		<select name="lf_comment_lang_filter" id="lf_comment_lang_filter">
			<option value=""><?php esc_html_e( 'All languages', 'lingua-forge' ); ?></option>
			<?php foreach ( $this->router->languages() as $lang ) : ?>
				<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $current, $lang ); ?>>
					<?php echo esc_html( $this->router->language_label( $lang ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * @param array<string,string> $clauses
	 * @return array<string,string>
	 */
	public function filter_by_lang( array $clauses, WP_Comment_Query $query ): array {
		if ( ! is_admin() ) {
			return $clauses;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET param driving a list-table query filter, same convention as core's own comment_type/status filters; no data is written here.
		$lang = isset( $_GET['lf_comment_lang_filter'] ) ? sanitize_key( wp_unslash( $_GET['lf_comment_lang_filter'] ) ) : '';
		if ( '' === $lang || ! CommentMirror::feature_enabled() ) {
			return $clauses;
		}

		global $wpdb;

		// LEFT JOIN both sources of "this comment's language": its own meta
		// (set on every mirror, and on a canonical comment once translated),
		// falling back to its post's _lf_lang (the correct default for a
		// canonical comment that hasn't been translated yet).
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->commentmeta} lf_cm ON ( {$wpdb->comments}.comment_ID = lf_cm.comment_id AND lf_cm.meta_key = %s )" .
			" LEFT JOIN {$wpdb->postmeta} lf_pm ON ( {$wpdb->comments}.comment_post_ID = lf_pm.post_id AND lf_pm.meta_key = '_lf_lang' )",
			CommentMirror::META_LANG
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->commentmeta/$wpdb->postmeta/$wpdb->comments are server-known table-name properties, not caller data; the only caller-influenced value (CommentMirror::META_LANG, a PHP constant, not request input) is bound via %s.

		$clauses['where'] .= $wpdb->prepare(
			' AND ( lf_cm.meta_value = %s OR ( lf_cm.meta_value IS NULL AND lf_pm.meta_value = %s ) )',
			$lang,
			$lang
		);

		return $clauses;
	}
}
