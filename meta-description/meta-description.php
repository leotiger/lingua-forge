<?php
/**
 * Lingua Forge – Meta Description sub-module
 *
 * Adds a meta box for custom meta descriptions and outputs
 * <meta name="description">, og:description, and twitter:description.
 * Fallback chain: custom field → excerpt → site description.
 *
 * Post-meta key: `_linguaforge_meta_description` (prefixed to avoid collisions
 * with other plugins that also use a generic `meta_description` key).
 *
 * Backwards-compat: the old unprefixed key `meta_description` is read on first
 * save and migrated automatically so existing data is never lost.
 */

namespace LinguaForge\MetaDescription;

defined( 'ABSPATH' ) || exit;

class Module {

	const META_KEY     = '_linguaforge_meta_description';
	const LEGACY_KEY   = 'meta_description';
	const NONCE_ACTION = 'lf_meta_description_save';
	const NONCE_FIELD  = 'lf_meta_description_nonce';
	const INPUT_FIELD  = 'lf_meta_description_field';

	/**
	 * Option flag written once the bulk key migration has completed.
	 * Incrementing this value forces a re-run after future key renames.
	 */
	const MIGRATION_FLAG = 'lf_meta_key_migrated_v1';

	public static function init(): void {
		add_action( 'init',           [ self::class, 'register_meta'     ] );
		add_action( 'add_meta_boxes', [ self::class, 'register_meta_box' ] );
		add_action( 'save_post',      [ self::class, 'save'              ] );
		add_action( 'wp_head',        [ self::class, 'output_tags'       ], 1 );
		add_action( 'admin_init',     [ self::class, 'maybe_migrate'     ] );
	}

	// ── REST / block-editor registration ─────────────────────────────────────

	public static function register_meta(): void {
		register_post_meta( '', self::META_KEY, [
			'show_in_rest'      => true,
			'single'            => true,
			'type'              => 'string',
			'auth_callback'     => static function () {
				return current_user_can( 'edit_posts' );
			},
			'sanitize_callback' => 'sanitize_textarea_field',
		] );
	}

	// ── Meta box ──────────────────────────────────────────────────────────────

	public static function register_meta_box(): void {
		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $type ) {
			add_meta_box(
				'lf_meta_description',
				__( 'Meta Description', 'lingua-forge' ),
				[ self::class, 'render' ],
				$type,
				'normal',
				'high',
				[ '__block_editor_compatible_meta_box' => true ]
			);
		}
	}

	public static function render( \WP_Post $post ): void {

		$custom = self::get( $post->ID );

		if ( $custom !== '' ) {
			$value  = $custom;
			$source = 'custom';
		} else {
			$excerpt = $post->post_excerpt;
			if ( empty( $excerpt ) && ! empty( $post->post_content ) ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 25, '...' );
			}
			$value  = $excerpt;
			$source = 'excerpt';
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<p><label for="' . esc_attr( self::INPUT_FIELD ) . '"><strong>'
			. esc_html__( 'Meta Description', 'lingua-forge' )
			. '</strong></label></p>';

		echo '<textarea id="' . esc_attr( self::INPUT_FIELD ) . '" '
			. 'name="' . esc_attr( self::INPUT_FIELD ) . '" '
			. 'rows="3" maxlength="320" style="width:100%;">'
			. esc_textarea( $value )
			. '</textarea>';

		echo '<p style="margin-top:4px;font-size:12px;color:#666;display:flex;justify-content:space-between;align-items:center;">';
		if ( $source === 'custom' ) {
			echo '<span>' . esc_html__( 'Using custom meta description.', 'lingua-forge' ) . '</span>';
		} else {
			echo '<span>' . esc_html__( 'Prefilled from excerpt. Edit to override.', 'lingua-forge' ) . '</span>';
		}
		echo '<span id="lf_meta_desc_counter" style="font-weight:600;"></span>';
		echo '</p>';
		echo '<p style="font-size:11px;color:#999;margin-top:0;">'
			. esc_html__( 'Aim for 120–160 characters. Leave empty to use the excerpt fallback.', 'lingua-forge' )
			. '</p>';

		?>
		<script>
		( function () {
			var ta      = document.getElementById( '<?php echo esc_js( self::INPUT_FIELD ); ?>' );
			var counter = document.getElementById( 'lf_meta_desc_counter' );
			function update() {
				var len   = ta.value.length;
				var color = ( len >= 120 && len <= 160 ) ? '#00a32a'
				          : ( len > 160 && len <= 200 )  ? '#dba617'
				          :                                 '#cc1818';
				counter.textContent = len + ' chars';
				counter.style.color = color;
			}
			ta.addEventListener( 'input', update );
			update();
		} )();
		</script>
		<?php
	}

	// ── Save ─────────────────────────────────────────────────────────────────

	public static function save( int $post_id ): void {

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) )    return;

		if ( ! isset( $_POST[ self::INPUT_FIELD ] ) ) {
			return;
		}

		$value = sanitize_textarea_field( wp_unslash( $_POST[ self::INPUT_FIELD ] ) );

		if ( $value === '' ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, $value );
			// Remove any legacy unprefixed entry now that it is migrated.
			delete_post_meta( $post_id, self::LEGACY_KEY );
		}
	}

	// ── Front-end <head> output ───────────────────────────────────────────────

	public static function output_tags(): void {

		if ( is_admin() ) return;

		$description = '';
		$custom      = '';

		if ( is_singular() ) {
			$custom = self::get( get_the_ID() );
			if ( $custom !== '' ) {
				$description = $custom;
			}
		}

		if ( empty( $description ) && is_singular() ) {
			$post = get_post();
			if ( $post ) {
				$excerpt = $post->post_excerpt;
				if ( empty( $excerpt ) && ! empty( $post->post_content ) ) {
					$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' );
				}
				$description = $excerpt;
			}
		}

		if ( empty( $description ) ) {
			$description = get_bloginfo( 'description' );
		}

		$description = wp_strip_all_tags( $description );
		$description = trim( $description );
		$description = preg_replace( '/\s*\[&hellip;\]\s*$/', '...', $description );
		$description = preg_replace( '/\s*\[…\]\s*$/',        '...', $description );

		// Only truncate automatic fallback descriptions, never custom ones.
		if ( empty( $custom ) && mb_strlen( $description ) > 190 ) {
			$description = mb_substr( $description, 0, 187 ) . '...';
		}

		if ( empty( $description ) ) return;

		echo '<meta name="description" content="'          . esc_attr( $description ) . '">' . "\n";
		echo '<meta property="og:description" content="'  . esc_attr( $description ) . '">' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	}

	// ── One-time bulk migration ───────────────────────────────────────────────

	/**
	 * Run once per site: copy every post's legacy `meta_description` value to
	 * `_linguaforge_meta_description`, then delete the old key.
	 *
	 * Guarded by a DB option flag so subsequent requests are a single
	 * get_option() lookup and exit immediately.
	 */
	public static function maybe_migrate(): void {

		if ( get_option( self::MIGRATION_FLAG ) ) {
			return;
		}

		global $wpdb;

		// Find all rows that have the old key but not yet the new one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = %s
				   AND post_id NOT IN (
				       SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s
				   )",
				self::LEGACY_KEY,
				self::META_KEY
			)
		);

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				update_post_meta( (int) $row->post_id, self::META_KEY, $row->meta_value );
				delete_post_meta( (int) $row->post_id, self::LEGACY_KEY );
			}
		}

		update_option( self::MIGRATION_FLAG, '1', false );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Read the stored meta description for a post.
	 * Falls back to the legacy unprefixed key so old data is visible
	 * immediately without requiring a manual resave.
	 */
	public static function get( int $post_id ): string {

		$value = get_post_meta( $post_id, self::META_KEY, true );

		if ( $value === '' || $value === false ) {
			// Transparent legacy migration: surface old value in the UI;
			// it will be written to the new key on the next save.
			$value = get_post_meta( $post_id, self::LEGACY_KEY, true );
		}

		return is_string( $value ) ? $value : '';
	}
}

Module::init();
