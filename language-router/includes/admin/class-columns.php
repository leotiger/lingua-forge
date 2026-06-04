<?php
/**
 * Class LinguaForge\Router\Admin\Columns
 *
 * Adds a "Lang" column to the Posts and Pages list screens and provides the
 * quick-edit language dropdown.
 */

namespace LinguaForge\Router\Admin;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Columns {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		add_filter( 'manage_post_posts_columns',     [ $this, 'add_lang_column' ] );
		add_filter( 'manage_pages_columns',            [ $this, 'add_lang_column' ] );
		add_action( 'manage_post_posts_custom_column', [ $this, 'render_lang_column' ], 10, 2 );
		add_action( 'manage_pages_custom_column',      [ $this, 'render_lang_column' ], 10, 2 );
		add_action( 'quick_edit_custom_box',         [ $this, 'render_quick_edit_box' ], 10, 2 );
		// CPT-specific column hooks must be registered after post types are defined.
		// Priority 20 fires after most plugins register their CPTs at init priority 10.
		add_action( 'init', [ $this, 'register_cpt_column_hooks' ], 20 );
	}

	public function register_cpt_column_hooks(): void {
		foreach ( $this->cpt_post_types() as $pt ) {
			add_filter( "manage_{$pt}_posts_columns",       [ $this, 'add_lang_column' ] );
			add_action( "manage_{$pt}_posts_custom_column", [ $this, 'render_lang_column' ], 10, 2 );
		}
	}

	/**
	 * Returns the list of public CPTs (excluding 'post', 'page', and internal
	 * WordPress types) that receive the Lingua Forge Lang column.
	 *
	 * Filterable via the 'linguaforge_column_post_types' hook so site owners
	 * can opt specific CPTs out.
	 *
	 * @return string[]
	 */
	private function cpt_post_types(): array {
		// Standard internal-types exclusion list. See class-sync.php for the
		// intentional wp_navigation omission that exists only in that file.
		$internal = [
			'attachment', 'revision', 'nav_menu_item',
			'wp_template', 'wp_template_part', 'wp_navigation',
			'wp_block', 'wp_global_styles', 'wp_font_family', 'wp_font_face',
			'wp_navigation_fallback',
		];

		$types = array_values( array_diff(
			array_keys( get_post_types( [ 'public' => true ] ) ),
			array_merge( [ 'post', 'page' ], $internal )
		) );

		/**
		 * Filters the CPTs that receive Lingua Forge admin columns.
		 *
		 * 'post' and 'page' are always covered by their own dedicated hooks
		 * and are not included in this list.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return (array) apply_filters( 'linguaforge_column_post_types', $types ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
	}

	// =========================================================
	// LANG COLUMN
	// =========================================================

	public function add_lang_column( array $cols ): array {
		$cols['lang'] = 'Lang';
		return $cols;
	}

	public function render_lang_column( string $col, $id ): void {
		$id = (int) $id;
		if ( $col !== 'lang' ) return;

		$lang = $this->router->trid_group->get_lang( $id );
		echo '<strong data-lang="' . esc_attr( $lang ) . '">' . esc_html( strtoupper( $lang ) ) . '</strong>';

		if ( $this->router->sync->is_outdated( $id ) ) {
			echo ' <span class="lf-outdated-indicator">⚠</span>';

			/**
			 * Fires after the outdated indicator in the Lang column.
			 *
			 * The AI module hooks this to inject a "Retranslate" button
			 * without creating a hard dependency from the language-router
			 * to the AI sub-module.
			 *
			 * @param int $post_id  Current (target) post ID.
			 */
			do_action( 'lf_lang_column_outdated', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is the registered plugin prefix; WPCS skips 2-char prefixes for hook validation.
		}

		$missing = $this->router->trid_group->get_missing_languages( $id );
		if ( ! empty( $missing ) ) {
			echo ' <span class="lf-missing-langs">⭕ '
				. esc_html( implode( ',', array_map( 'strtoupper', $missing ) ) )
				. '</span>';

			/**
			 * Fires after the missing-language indicator in the Lang column.
			 *
			 * The AI module hooks this to inject a "Translate missing" button
			 * without creating a hard dependency from the language-router to the
			 * AI sub-module.
			 *
			 * @param int      $post_id  Current post ID.
			 * @param string[] $missing  Missing language codes.
			 */
			do_action( 'lf_lang_column_missing', $id, $missing ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is the registered plugin prefix; WPCS skips 2-char prefixes for hook validation.
		}

		/**
		 * Fires for every post in the Lang column, regardless of outdated or
		 * missing-translation status.
		 *
		 * The AI module hooks this to inject a "Retranslate" button that is
		 * always available on any TRID-linked post — not only when the ⚠
		 * outdated indicator is present.  The handler is responsible for
		 * checking whether the post actually has TRID siblings before rendering
		 * any UI.
		 *
		 * @param int $post_id  Current post ID.
		 */
		do_action( 'lf_lang_column_retranslate', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is the registered plugin prefix; WPCS skips 2-char prefixes for hook validation.
	}

	// =========================================================
	// QUICK EDIT
	// =========================================================

	public function render_quick_edit_box( string $column_name, string $post_type ): void {
		if ( $column_name !== 'lang' ) return;
		// Allow all public post types; quick_edit_custom_box only fires for the
		// type currently being listed, so this is already contextually scoped.
		$pto = get_post_type_object( $post_type );
		if ( ! $pto || ! $pto->public ) return;
		?>
		<fieldset class="inline-edit-col">
			<label>
				<span class="title">Language</span>
				<select name="lf_lang">
					<?php foreach ( $this->router->context->languages() as $l ) : ?>
						<option value="<?php echo esc_attr( $l ); ?>"><?php echo esc_html( strtoupper( $l ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</fieldset>
		<?php
	}
}
