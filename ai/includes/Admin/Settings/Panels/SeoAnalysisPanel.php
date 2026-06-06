<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\SeoAnalysisPanel
 *
 * Renders the SEO Analysis section on the SEO tab.
 *
 * Provides a rule-based SEO content audit for any post or page.  The editor
 * selects a post and a language version; clicking Analyze sends an AJAX
 * request that returns structured metrics rendered inline by seo-analysis.js.
 *
 * ── Sprint 1 metrics (rule-based, zero cost) ──────────────────────────────
 *   Title            — character length, presence
 *   Meta description — presence, character length (LF AI meta preferred)
 *   Word count       — total words in post content
 *   Reading time     — estimated at 200 wpm
 *   Headings         — H1 count (should be 1), H2/H3 presence
 *   Images           — total count, images with/without alt text
 *   Links            — internal vs external link count
 *   Overall score    — weighted 0–100 from metric statuses
 *
 * ── Sprint 2 (planned) ────────────────────────────────────────────────────
 *   AI-powered quality assessment, keyword suggestions, multilingual parity
 *   check (source vs translated post metrics side-by-side).
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.2.0
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class SeoAnalysisPanel {

	// =========================================================================
	// Render
	// =========================================================================

	public static function render(): void {

		$router    = Router::get_instance();
		$languages = $router->context->languages();
		$source    = $router->context->source_language();

		$public_types = array_diff(
			array_values( get_post_types( [ 'public' => true ] ) ),
			[ 'attachment' ]
		);

		?>
		<!-- ── SEO Analysis ─────────────────────────────────── -->
		<p>
			<?php
			esc_html_e(
				'Filter by language and post type, browse your content, and click Analyze on any item to run a rule-based SEO audit — title length, meta description, word count, heading structure, image alt coverage, and internal links.',
				'lingua-forge'
			);
			?>
		</p>

		<div id="lf-seo-analysis" style="max-width:860px;">

			<!-- ── Filters ── -->
			<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:1em;">

				<div>
					<label for="lf-seo-filter-lang" style="font-weight:600;margin-right:4px;">
						<?php esc_html_e( 'Language', 'lingua-forge' ); ?>
					</label>
					<select id="lf-seo-filter-lang">
						<?php foreach ( $languages as $lang ) :
							$label  = linguaforge_language_label( $lang );
							$suffix = ( $lang === $source ) ? ' (' . __( 'source', 'lingua-forge' ) . ')' : '';
							?>
							<option value="<?php echo esc_attr( $lang ); ?>">
								<?php echo esc_html( $label . $suffix ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div>
					<label for="lf-seo-filter-type" style="font-weight:600;margin-right:4px;">
						<?php esc_html_e( 'Type', 'lingua-forge' ); ?>
					</label>
					<select id="lf-seo-filter-type">
						<option value=""><?php esc_html_e( 'All types', 'lingua-forge' ); ?></option>
						<?php foreach ( $public_types as $type ) :
							$obj = get_post_type_object( $type );
							?>
							<option value="<?php echo esc_attr( $type ); ?>">
								<?php echo esc_html( $obj ? $obj->labels->name : $type ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<button type="button" id="lf-seo-load-posts-btn" class="button">
					<?php esc_html_e( 'Load content', 'lingua-forge' ); ?>
				</button>
				<span id="lf-seo-list-spinner" class="spinner" style="float:none;margin-top:0;vertical-align:middle;display:none;"></span>
			</div>

			<!-- ── Post list (populated by JS) ── -->
			<div id="lf-seo-post-list"></div>

			<!-- ── Analysis results (populated by JS) ── -->
			<div id="lf-seo-analysis-results" style="margin-top:1.5em;"></div>

		</div>
		<?php
	}

	// =========================================================================
	// AJAX — post list
	// =========================================================================

	/**
	 * Return a list of published posts for the selected language and post type.
	 */
	public static function ajax_get_posts(): void {

		check_ajax_referer( 'linguaforge_seo_analyze', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'lingua-forge' ) ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_key handles unslashing.
		$lang      = sanitize_key( $_POST['lang'] ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_key handles unslashing.
		$post_type = sanitize_key( $_POST['post_type'] ?? '' );

		$public_types = array_diff(
			array_values( get_post_types( [ 'public' => true ] ) ),
			[ 'attachment' ]
		);

		$types = ( '' !== $post_type && in_array( $post_type, $public_types, true ) )
			? [ $post_type ]
			: $public_types;

		$query_args = [
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		];

		// Filter by language when a specific language is selected.
		if ( '' !== $lang ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => '_lf_lang',
					'value' => $lang,
				],
			];
		}

		$post_ids = get_posts( $query_args );
		$items    = [];

		foreach ( $post_ids as $id ) {
			$post_type_obj = get_post_type_object( (string) get_post_type( $id ) );
			$items[] = [
				'id'       => $id,
				/* translators: %d: post ID */
				'title'    => get_the_title( $id ) ?: sprintf( __( '(no title) #%d', 'lingua-forge' ), $id ),
				'type'     => $post_type_obj ? $post_type_obj->labels->singular_name : get_post_type( $id ),
				'modified' => get_the_modified_date( 'Y-m-d', $id ),
				'edit_url' => get_edit_post_link( $id, 'raw' ),
			];
		}

		wp_send_json_success( [
			'lang'  => $lang,
			'items' => $items,
		] );
	}

	// =========================================================================
	// AJAX handler
	// =========================================================================

	public static function ajax_analyze(): void {

		check_ajax_referer( 'linguaforge_seo_analyze', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'lingua-forge' ) ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_key handles unslashing.
		$lang    = sanitize_key( $_POST['lang'] ?? '' );

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'lingua-forge' ) ] );
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			wp_send_json_error( [ 'message' => __( 'Post not found or not published.', 'lingua-forge' ) ] );
		}

		$analyzed_post = $post;
		$used_lang     = $lang;
		$used_source   = false;

		// If a specific language is requested, try to find that translation.
		if ( '' !== $lang ) {
			$router       = Router::get_instance();
			$post_lang    = (string) get_post_meta( $post_id, '_lf_lang', true );
			$source_lang  = $router->context->source_language();

			if ( $post_lang !== $lang ) {
				$trid = (string) get_post_meta( $post_id, '_lf_trid', true );
				if ( '' !== $trid ) {
					$translations = $router->trid_group->get_translations( $post_id );
					if ( isset( $translations[ $lang ] ) ) {
						$translated = get_post( $translations[ $lang ] );
						if ( $translated ) {
							$analyzed_post = $translated;
						} else {
							$used_source = true;
						}
					} else {
						$used_source = true;
					}
				} elseif ( $post_lang !== $lang && $post_lang !== $source_lang ) {
					$used_source = true;
				}
			}
		}

		$metrics = self::analyze( $analyzed_post );

		wp_send_json_success( [
			'post_id'     => $analyzed_post->ID,
			'post_title'  => get_the_title( $analyzed_post ),
			'lang'        => $used_lang,
			'used_source' => $used_source,
			'metrics'     => $metrics,
			'score'       => self::compute_score( $metrics ),
		] );
	}

	// =========================================================================
	// Analysis engine
	// =========================================================================

	/**
	 * Run all rule-based checks on a post.
	 *
	 * @param  \WP_Post $post
	 * @return array<string, array<string, mixed>>
	 */
	private static function analyze( \WP_Post $post ): array {

		$title       = get_the_title( $post );
		$content     = wp_strip_all_tags( do_blocks( $post->post_content ) );
		$raw_html    = do_blocks( $post->post_content );
		$meta_desc   = (string) get_post_meta( $post->ID, '_linguaforge_meta_description', true );

		// Fall back to excerpt then to trimmed content.
		if ( '' === $meta_desc ) {
			$meta_desc = '' !== $post->post_excerpt
				? wp_strip_all_tags( $post->post_excerpt )
				: wp_trim_words( $content, 30 );
		}

		$title_len    = mb_strlen( $title );
		$meta_len     = mb_strlen( $meta_desc );
		$words        = self::count_words( $content );
		$reading_time = (int) ceil( $words / 200 );
		$headings     = self::extract_headings( $raw_html );
		$images       = self::analyze_images( $raw_html );
		$links        = self::analyze_links( $raw_html );
		$has_lf_meta  = '' !== (string) get_post_meta( $post->ID, '_linguaforge_meta_description', true );

		return [
			'title'            => self::rate_title( $title, $title_len ),
			'meta_description' => self::rate_meta( $meta_desc, $meta_len, $has_lf_meta ),
			'word_count'       => self::rate_words( $words ),
			'reading_time'     => [
				'value'   => $reading_time,
				'display' => sprintf(
					/* translators: %d: minutes */
					_n( '~%d min read', '~%d min read', $reading_time, 'lingua-forge' ),
					$reading_time
				),
				'status'  => 'info',
			],
			'headings'         => self::rate_headings( $headings ),
			'images'           => self::rate_images( $images ),
			'links'            => self::rate_links( $links ),
		];
	}

	// =========================================================================
	// Metric raters
	// =========================================================================

	/** @return array<string, mixed> */
	public static function rate_title( string $title, int $len ): array {

		if ( '' === $title ) {
			return [ 'value' => '', 'length' => 0, 'status' => 'fail',
				'message' => __( 'No title set.', 'lingua-forge' ) ];
		}
		if ( $len < 30 ) {
			return [ 'value' => $title, 'length' => $len, 'status' => 'warn',
				/* translators: %d: number of characters */
				'message' => sprintf( __( 'Title is %d chars — aim for 50–60.', 'lingua-forge' ), $len ) ];
		}
		if ( $len > 60 ) {
			return [ 'value' => $title, 'length' => $len, 'status' => 'warn',
				/* translators: %d: number of characters */
				'message' => sprintf( __( 'Title is %d chars — may be truncated in SERPs (aim for 50–60).', 'lingua-forge' ), $len ) ];
		}
		return [ 'value' => $title, 'length' => $len, 'status' => 'ok',
			/* translators: %d: number of characters */
			'message' => sprintf( __( '%d chars — good length.', 'lingua-forge' ), $len ) ];
	}

	/** @return array<string, mixed> */
	public static function rate_meta( string $meta, int $len, bool $is_lf ): array {

		$source = $is_lf
			? __( 'Using LF AI meta description.', 'lingua-forge' )
			: __( 'No LF meta description set — using excerpt/content.', 'lingua-forge' );

		if ( $len === 0 ) {
			return [ 'value' => '', 'length' => 0, 'status' => 'fail',
				'message' => __( 'No meta description found. Generate one via the AI meta description feature.', 'lingua-forge' ) ];
		}
		if ( $len < 120 ) {
			return [ 'value' => $meta, 'length' => $len, 'status' => 'warn',
				/* translators: %1$d: number of characters, %2$s: source note (e.g. "Using LF AI meta description.") */
				'message' => sprintf( __( '%1$d chars — too short (aim for 140–160). %2$s', 'lingua-forge' ), $len, $source ) ];
		}
		if ( $len > 160 ) {
			return [ 'value' => $meta, 'length' => $len, 'status' => 'warn',
				/* translators: %1$d: number of characters, %2$s: source note */
				'message' => sprintf( __( '%1$d chars — may be truncated in SERPs (aim for 140–160). %2$s', 'lingua-forge' ), $len, $source ) ];
		}
		return [ 'value' => $meta, 'length' => $len, 'status' => 'ok',
			/* translators: %1$d: number of characters, %2$s: source note */
			'message' => sprintf( __( '%1$d chars — good length. %2$s', 'lingua-forge' ), $len, $source ) ];
	}

	/** @return array<string, mixed> */
	public static function rate_words( int $count ): array {

		if ( $count < 100 ) {
			return [ 'value' => $count, 'status' => 'fail',
				/* translators: %d: word count */
				'message' => sprintf( __( '%d words — very thin content. Consider expanding to at least 300 words.', 'lingua-forge' ), $count ) ];
		}
		if ( $count < 300 ) {
			return [ 'value' => $count, 'status' => 'warn',
				/* translators: %d: word count */
				'message' => sprintf( __( '%d words — below recommended minimum of 300.', 'lingua-forge' ), $count ) ];
		}
		return [ 'value' => $count, 'status' => 'ok',
			/* translators: %d: word count */
			'message' => sprintf( __( '%d words.', 'lingua-forge' ), $count ) ];
	}

	/** @return array<string, mixed> */
	public static function rate_headings( array $headings ): array {

		$h1 = $headings['h1'] ?? 0;
		$h2 = $headings['h2'] ?? 0;

		if ( $h1 === 0 ) {
			$status  = 'warn';
			$message = __( 'No H1 found. Typically the post title is the H1 — verify your theme is outputting it.', 'lingua-forge' );
		} elseif ( $h1 > 1 ) {
			$status = 'fail';
			/* translators: %d: number of H1 tags found */
			$message = sprintf( __( '%d H1 tags found — a page should have exactly one H1.', 'lingua-forge' ), $h1 );
		} elseif ( $h2 === 0 ) {
			$status  = 'warn';
			$message = __( '1 H1 ✓ — no H2 subheadings found. Adding H2s improves structure.', 'lingua-forge' );
		} else {
			$status  = 'ok';
			$message = sprintf(
				/* translators: %1$d: number of H2 headings, %2$d: number of H3 headings */
				__( '1 H1 ✓, %1$d H2, %2$d H3.', 'lingua-forge' ),
				$h2,
				$headings['h3'] ?? 0
			);
		}

		return array_merge( $headings, [ 'status' => $status, 'message' => $message ] );
	}

	/** @return array<string, mixed> */
	public static function rate_images( array $images ): array {

		$total       = $images['total'];
		$without_alt = $images['without_alt'];

		if ( $total === 0 ) {
			return array_merge( $images, [ 'status' => 'info',
				'message' => __( 'No images found in content.', 'lingua-forge' ) ] );
		}
		if ( $without_alt > 0 ) {
			return array_merge( $images, [ 'status' => 'warn',
				'message' => sprintf(
					/* translators: %1$d: number of images missing alt text, %2$d: total image count */
					__( '%1$d of %2$d image(s) missing alt text.', 'lingua-forge' ),
					$without_alt, $total
				) ] );
		}
		return array_merge( $images, [ 'status' => 'ok',
			/* translators: %d: number of images */
			'message' => sprintf( __( '%d image(s), all have alt text.', 'lingua-forge' ), $total ) ] );
	}

	/** @return array<string, mixed> */
	public static function rate_links( array $links ): array {

		$internal = $links['internal'];
		$external = $links['external'];

		if ( $internal === 0 && $external === 0 ) {
			return array_merge( $links, [ 'status' => 'warn',
				'message' => __( 'No links found. Internal links improve crawlability and user navigation.', 'lingua-forge' ) ] );
		}
		if ( $internal === 0 ) {
			return array_merge( $links, [ 'status' => 'warn',
				/* translators: %d: number of external links */
				'message' => sprintf( __( '%d external link(s), no internal links. Add internal links to related content.', 'lingua-forge' ), $external ) ] );
		}
		return array_merge( $links, [ 'status' => 'ok',
			'message' => sprintf(
				/* translators: %1$d: number of internal links, %2$d: number of external links */
				__( '%1$d internal, %2$d external.', 'lingua-forge' ),
				$internal, $external
			) ] );
	}

	// =========================================================================
	// Content extractors
	// =========================================================================

	public static function count_words( string $text ): int {
		$text = trim( $text );
		if ( '' === $text ) return 0;
		return count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: [] );
	}

	/**
	 * @return array{h1:int, h2:int, h3:int, h4:int, h5:int, h6:int}
	 */
	public static function extract_headings( string $html ): array {

		$counts = [ 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ];

		preg_match_all( '/<h([1-6])[^>]*>/i', $html, $matches );

		foreach ( $matches[1] as $level ) {
			$key = 'h' . $level;
			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ]++;
			}
		}

		return $counts;
	}

	/**
	 * @return array{total:int, with_alt:int, without_alt:int}
	 */
	public static function analyze_images( string $html ): array {

		preg_match_all( '/<img[^>]+>/i', $html, $matches );

		$total       = count( $matches[0] );
		$without_alt = 0;

		foreach ( $matches[0] as $img ) {
			// Missing alt attribute OR empty alt value.
			if ( ! preg_match( '/\balt=["\'][^"\']+["\']/i', $img ) ) {
				$without_alt++;
			}
		}

		return [
			'total'       => $total,
			'with_alt'    => $total - $without_alt,
			'without_alt' => $without_alt,
		];
	}

	/**
	 * @return array{internal:int, external:int}
	 */
	/**
	 * @param  string $home  Base URL (default: home_url()). Accepts explicit value
	 *                       so unit tests can pass a fixed URL without WP runtime.
	 * @return array{internal:int, external:int}
	 */
	public static function analyze_links( string $html, string $home = '' ): array {

		if ( '' === $home ) {
			$home = rtrim( home_url(), '/' );
		}

		preg_match_all( '/<a[^>]+href=["\']([^"\']*)["\'][^>]*>/i', $html, $matches );

		$internal = 0;
		$external = 0;

		foreach ( $matches[1] as $href ) {
			$href = trim( $href );
			if ( '' === $href || str_starts_with( $href, '#' ) || str_starts_with( $href, 'mailto:' ) ) {
				continue;
			}
			if ( str_starts_with( $href, '/' ) || str_starts_with( $href, $home ) ) {
				$internal++;
			} else {
				$external++;
			}
		}

		return [ 'internal' => $internal, 'external' => $external ];
	}

	// =========================================================================
	// Score
	// =========================================================================

	/**
	 * Compute an overall SEO score 0–100 from metric statuses.
	 *
	 * Weights: title 15, meta 20, words 15, headings 20, images 10, links 10 = 90.
	 * Reading time is informational only (10 points always awarded).
	 *
	 * @param  array<string, array<string, mixed>> $metrics
	 * @return int
	 */
	public static function compute_score( array $metrics ): int {

		$weights = [
			'title'            => 15,
			'meta_description' => 20,
			'word_count'       => 15,
			'headings'         => 20,
			'images'           => 10,
			'links'            => 10,
		];

		$earned = 10; // reading_time: always informational

		foreach ( $weights as $key => $weight ) {
			$status = $metrics[ $key ]['status'] ?? 'fail';
			if ( 'ok' === $status ) {
				$earned += $weight;
			} elseif ( 'warn' === $status ) {
				$earned += (int) round( $weight * 0.5 );
			}
			// fail / info = 0 contribution
		}

		return min( 100, max( 0, $earned ) );
	}

	// =========================================================================
	// AI analysis AJAX handler
	// =========================================================================

	/**
	 * Run an AI-powered SEO analysis for a post and return recommendations.
	 *
	 * Uses the configured AI provider (quality tier) to produce:
	 *   - summary          — 2–3 sentence overall assessment
	 *   - improvements     — up to 5 specific actionable recommendations
	 *   - title_suggestion — improved title, or null
	 *   - meta_suggestion  — improved meta description, or null
	 *
	 * The post content and rule-based metrics are sent as context so the AI
	 * can ground its recommendations in the actual content state.
	 */
	public static function ajax_ai_analyze(): void {

		check_ajax_referer( 'linguaforge_seo_analyze', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'lingua-forge' ) ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$lang    = sanitize_key( $_POST['lang'] ?? '' );

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'lingua-forge' ) ] );
		}

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_status, [ 'publish', 'draft', 'pending' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Post not found.', 'lingua-forge' ) ] );
		}

		// Check AI provider is configured.
		$provider_slug = Config::provider();
		if ( empty( \LinguaForge\AI\Core\KeyStore::get( $provider_slug ) ) ) {
			wp_send_json_error( [ 'message' => __( 'No AI provider configured. Add an API key in Settings → API Keys.', 'lingua-forge' ) ] );
		}

		// Run rule-based analysis first to give the AI grounded context.
		$metrics = self::analyze( $post );
		$score   = self::compute_score( $metrics );

		// Build the AI prompt.
		$title       = get_the_title( $post );
		$content     = wp_strip_all_tags( do_blocks( $post->post_content ) );
		$meta_desc   = (string) get_post_meta( $post->ID, '_linguaforge_meta_description', true );
		$word_count  = $metrics['word_count']['value'] ?? 0;
		$h1          = $metrics['headings']['h1'] ?? 0;
		$h2          = $metrics['headings']['h2'] ?? 0;
		$img_no_alt  = $metrics['images']['without_alt'] ?? 0;
		$internal    = $metrics['links']['internal'] ?? 0;

		$language_label = '' !== $lang ? linguaforge_language_label( $lang ) : 'Unknown';

		$prompt = sprintf(
			/* translators: SEO analysis AI prompt — values are post data */
			'You are an expert multilingual SEO consultant. Analyze the following WordPress post and provide specific, actionable SEO recommendations.

Post title: %s
Language: %s
Current rule-based SEO score: %d/100
Word count: %d
Meta description: %s
Headings: H1 count = %d, H2 count = %d
Images without alt text: %d
Internal links: %d

Content excerpt (first 1500 characters):
%s

Respond ONLY with a valid JSON object — no markdown, no code fences, just the JSON:
{
  "summary": "2-3 sentence overall assessment of the SEO quality and main opportunity",
  "improvements": ["specific actionable recommendation 1", "..."],
  "title_suggestion": "improved title if current one could be better, or null",
  "meta_suggestion": "improved meta description (140-160 chars) if needed, or null"
}

Provide 3-5 improvements maximum. Be specific and actionable. Write in the same language as the post content.',
			$title,
			$language_label,
			$score,
			$word_count,
			'' !== $meta_desc ? $meta_desc : 'Not set',
			$h1,
			$h2,
			$img_no_alt,
			$internal,
			mb_substr( $content, 0, 1500 )
		);

		// Call AI provider (quality tier — same as translation).
		// WorkerConfig( model, max_tokens, temperature )
		$worker_config = Config::apply_compliance(
			new WorkerConfig( Config::model( 'quality' ), 800, 0.3 )
		);

		/** @var \LinguaForge\AI\Contracts\AIProviderInterface $provider */
		$provider = apply_filters(
			'linguaforge_ai_provider',
			ProviderFactory::make( $worker_config ),
			$post_id,
			$worker_config
		);

		$raw = UsageRecorder::tracked(
			'seo-analysis',
			static fn() => $provider->chat( [
				[ 'role' => 'system', 'content' => Config::apply_compliance_to_system(
					'You are an expert multilingual SEO consultant. Always respond with valid JSON only.'
				) ],
				[ 'role' => 'user', 'content' => $prompt ],
			] )
		);

		if ( null === $raw || '' === $raw ) {
			wp_send_json_error( [ 'message' => __( 'No response from AI provider. Check your API key and try again.', 'lingua-forge' ) ] );
		}

		// Parse JSON response — strip any accidental markdown fences.
		$clean = trim( (string) $raw );
		$clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
		$clean = preg_replace( '/\s*```$/i', '', $clean ) ?? $clean;
		$data  = json_decode( $clean, true );

		if ( ! is_array( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'AI returned an unexpected response format. Please try again.', 'lingua-forge' ) ] );
		}

		wp_send_json_success( [
			'summary'          => (string) ( $data['summary']          ?? '' ),
			'improvements'     => (array)  ( $data['improvements']     ?? [] ),
			'title_suggestion' => isset( $data['title_suggestion'] ) && null !== $data['title_suggestion']
				? (string) $data['title_suggestion'] : null,
			'meta_suggestion'  => isset( $data['meta_suggestion'] )  && null !== $data['meta_suggestion']
				? (string) $data['meta_suggestion']  : null,
		] );
	}
}
