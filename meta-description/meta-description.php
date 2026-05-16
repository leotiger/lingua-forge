<?php
/**
 * LinguaForge – Meta Description sub-module
 *
 * Adds a meta box for custom meta descriptions and outputs
 * <meta name="description">, og:description, and twitter:description.
 * Fallback chain: custom field → excerpt → site description.
 *
 * The post-meta key `meta_description` is intentionally left generic
 * so themes and other plugins can read it without coupling to LinguaForge.
 */

defined( 'ABSPATH' ) || exit;

// --- Register Post Meta (enables REST API / Block Editor access) ---
add_action( 'init', function () {
	register_post_meta( '', 'meta_description', [
		'show_in_rest'      => true,
		'single'            => true,
		'type'              => 'string',
		'auth_callback'     => function () {
			return current_user_can( 'edit_posts' );
		},
		'sanitize_callback' => 'sanitize_textarea_field',
	] );
} );

// --- Meta Box UI ---
add_action( 'add_meta_boxes', function () {
	foreach ( get_post_types( [ 'public' => true ], 'names' ) as $type ) {
		add_meta_box(
			'lf_meta_description',
			__( 'Meta Description', 'lingua-forge' ),
			'lf_meta_description_box',
			$type,
			'normal',
			'high',
			[ '__block_editor_compatible_meta_box' => true ]
		);
	}
} );

function lf_meta_description_box( $post ) {
	$custom = get_post_meta( $post->ID, 'meta_description', true );

	if ( ! empty( $custom ) ) {
		$value  = $custom;
		$source = 'custom';
	} else {
		$excerpt = $post->post_excerpt;

		if ( empty( $excerpt ) && ! empty( $post->post_content ) ) {
			// Trim content as fallback — strip [...] artifact that WP auto-excerpts include
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 25, '...' );
		}

		$value  = $excerpt;
		$source = 'excerpt';
	}

	wp_nonce_field( 'lf_meta_description_save', 'lf_meta_description_nonce' );

	echo '<p><label for="lf_meta_description_field"><strong>' . esc_html__( 'Meta Description', 'lingua-forge' ) . '</strong></label></p>';
	echo '<textarea id="lf_meta_description_field" name="lf_meta_description_field" rows="3" maxlength="320" style="width:100%;">' . esc_textarea( $value ) . '</textarea>';

	echo '<p style="margin-top:4px;font-size:12px;color:#666;display:flex;justify-content:space-between;align-items:center;">';
	if ( $source === 'custom' ) {
		echo '<span>' . esc_html__( 'Using custom meta description.', 'lingua-forge' ) . '</span>';
	} else {
		echo '<span>' . esc_html__( 'Prefilled from excerpt. Edit to override.', 'lingua-forge' ) . '</span>';
	}
	echo '<span id="lf_meta_desc_counter" style="font-weight:600;"></span>';
	echo '</p>';
	echo '<p style="font-size:11px;color:#999;margin-top:0;">' . esc_html__( 'Aim for 120–160 characters. Leave empty to use the excerpt fallback.', 'lingua-forge' ) . '</p>';

	?>
	<script>
	( function () {
		var ta      = document.getElementById( 'lf_meta_description_field' );
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

// --- Save Meta ---
add_action( 'save_post', function ( $post_id ) {

	if ( ! isset( $_POST['lf_meta_description_nonce'] ) ||
		! wp_verify_nonce( $_POST['lf_meta_description_nonce'], 'lf_meta_description_save' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) )    return;

	if ( isset( $_POST['lf_meta_description_field'] ) ) {
		$value = sanitize_textarea_field( $_POST['lf_meta_description_field'] );

		if ( $value === '' ) {
			// Clean up rather than storing an empty string
			delete_post_meta( $post_id, 'meta_description' );
		} else {
			update_post_meta( $post_id, 'meta_description', $value );
		}
	}
} );

// --- Output in <head> ---
add_action( 'wp_head', function () {

	if ( is_admin() ) return;

	$description = '';
	$custom      = ''; // initialised here so it's always defined in scope

	// 1. Custom field
	if ( is_singular() ) {
		$custom = get_post_meta( get_the_ID(), 'meta_description', true );
		if ( ! empty( $custom ) ) {
			$description = $custom;
		}
	}

	// 2. Excerpt / content fallback
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

	// 3. Site description fallback
	if ( empty( $description ) ) {
		$description = get_bloginfo( 'description' );
	}

	// Cleanup
	$description = wp_strip_all_tags( $description );
	$description = trim( $description );

	// Strip WordPress "[…]" auto-excerpt artifact
	$description = preg_replace( '/\s*\[&hellip;\]\s*$/', '...', $description );
	$description = preg_replace( '/\s*\[…\]\s*$/',        '...', $description );

	// Only truncate automatic fallback descriptions, never custom ones
	if ( empty( $custom ) && mb_strlen( $description ) > 190 ) {
		$description = mb_substr( $description, 0, 187 ) . '...';
	}

	if ( empty( $description ) ) return;

	$esc = esc_attr( $description );
	echo '<meta name="description" content="'          . $esc . '">' . "\n";
	echo '<meta property="og:description" content="'  . $esc . '">' . "\n";
	echo '<meta name="twitter:description" content="' . $esc . '">' . "\n";

}, 1 );
