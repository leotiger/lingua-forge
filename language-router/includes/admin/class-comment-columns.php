<?php
/**
 * Class LinguaForge\Router\Admin\CommentColumns
 *
 * Adds a "Lang" column to the Comments screen (wp-admin/edit-comments.php)
 * showing each comment's own written language and, when it has any, the
 * languages it's been mirrored into. Purely a display layer over
 * `Comments\CommentMirror`'s data model — no AI dependency, matching the
 * language-router/ vs ai/ split the rest of this feature follows.
 *
 * @package LinguaForge\Router\Admin
 * @since   2.7.0
 */

namespace LinguaForge\Router\Admin;

use LinguaForge\Router\Comments\CommentMirror;
use LinguaForge\Router\Router;
use WP_Comment;

if ( ! defined( 'ABSPATH' ) ) exit;

class CommentColumns {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	public function register_hooks(): void {
		add_filter( 'manage_edit-comments_columns', [ $this, 'add_lang_column' ] );
		add_action( 'manage_comments_custom_column', [ $this, 'render_lang_column' ], 10, 2 );
	}

	/**
	 * Only adds the column when the feature is enabled — an unused column
	 * on every Comments screen would be clutter for sites that never turn
	 * this on.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_lang_column( array $columns ): array {
		if ( ! CommentMirror::feature_enabled() ) {
			return $columns;
		}

		// Insert right before 'date', mirroring the Lang column's placement
		// on the Posts/Pages list (right before the date column there too).
		if ( ! isset( $columns['date'] ) ) {
			$columns['lf_comment_lang'] = __( 'Lang', 'lingua-forge' );
			return $columns;
		}

		$reordered = [];
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$reordered['lf_comment_lang'] = __( 'Lang', 'lingua-forge' );
			}
			$reordered[ $key ] = $label;
		}

		return $reordered;
	}

	public function render_lang_column( string $column, int $comment_id ): void {
		if ( 'lf_comment_lang' !== $column ) {
			return;
		}

		$mirror  = $this->router->comment_mirror;
		$comment = get_comment( $comment_id );

		if ( ! $comment instanceof WP_Comment ) {
			echo '—';
			return;
		}

		$lang = $mirror->get_source_lang( $comment_id );
		if ( '' === $lang ) {
			$post = get_post( (int) $comment->comment_post_ID );
			$lang = $post ? $this->router->get_lang( (int) $post->ID ) : '';
		}

		if ( '' === $lang ) {
			echo '—';
			return;
		}

		echo esc_html( strtoupper( $lang ) );

		$translations = $mirror->get_comment_translations_map( $comment_id );
		$other_langs  = array_values( array_diff( array_keys( $translations ), [ $lang ] ) );

		if ( empty( $other_langs ) ) {
			return;
		}

		$labels = array_map( 'strtoupper', $other_langs );

		printf(
			' <span class="lf-comment-mirrors" title="%s">(%s)</span>',
			esc_attr( sprintf(
				/* translators: %s: comma-separated list of language codes this comment has been mirrored into */
				__( 'Also mirrored to: %s', 'lingua-forge' ),
				implode( ', ', $labels )
			) ),
			esc_html( implode( ', ', $labels ) )
		);
	}
}
