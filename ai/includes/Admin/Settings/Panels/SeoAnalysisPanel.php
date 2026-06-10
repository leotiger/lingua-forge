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
 * ── Scoring profiles ──────────────────────────────────────────────────────
 *   blog     — Blog / Editorial (default). 300+ words, H1+H2 expected,
 *              internal links required.
 *   product  — Product / eCommerce. Thresholds relaxed for focused product
 *              copy; image alt coverage weighted more heavily.
 *   landing  — Landing / Short-form. Lower word-count expectations;
 *              H2 and internal links treated as optional.
 *
 *   Profile is auto-detected from post type (product → product profile) and
 *   can be overridden via the profile dropdown in the Settings panel or via
 *   the `profile` AJAX param.
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

use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class SeoAnalysisPanel {

	// =========================================================================
	// Scoring profiles
	// =========================================================================

	/**
	 * Scoring profile definitions.
	 *
	 * Each profile entry contains:
	 *   label          — human-readable name for the UI dropdown
	 *   title          — [ min, max ] character range for a passing title
	 *   meta           — [ min, max ] character range for a passing meta description
	 *   words          — [ fail, warn ] thresholds; ≥ warn = ok
	 *   h2_required    — whether missing H2 is a warn (true) or info (false)
	 *   links_required — whether missing internal links is a warn (true) or info (false)
	 *   weights        — per-metric score weights (must sum to 90; 10 reserved for reading time)
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function profiles(): array {
		return [
			'blog' => [
				'label'          => __( 'Blog / Editorial', 'lingua-forge' ),
				'title'          => [ 'min' => 30, 'max' => 60 ],
				'meta'           => [ 'min' => 120, 'max' => 210 ],
				'words'          => [ 'fail' => 100, 'warn' => 300 ],
				'h2_required'    => true,
				'links_required' => true,
				'weights'        => [
					'title'            => 15,
					'meta_description' => 20,
					'word_count'       => 15,
					'headings'         => 20,
					'images'           => 10,
					'links'            => 10,
				],
			],
			'product' => [
				'label'          => __( 'Product / eCommerce', 'lingua-forge' ),
				'title'          => [ 'min' => 20, 'max' => 70 ],
				'meta'           => [ 'min' => 120, 'max' => 210 ],
				'words'          => [ 'fail' => 40, 'warn' => 80 ],
				'h2_required'    => false,
				'links_required' => false,
				// Links & headings are deprioritised: the WooCommerce template
				// renders the product title as H1 automatically, and internal
				// links are not a meaningful ranking signal for product pages.
				'weights'        => [
					'title'            => 20,
					'meta_description' => 30,
					'word_count'       => 15,
					'headings'         => 5,
					'images'           => 20,
					'links'            => 0,
				],
			],
			'landing' => [
				'label'          => __( 'Landing / Short-form', 'lingua-forge' ),
				'title'          => [ 'min' => 20, 'max' => 70 ],
				'meta'           => [ 'min' => 120, 'max' => 210 ],
				'words'          => [ 'fail' => 60, 'warn' => 150 ],
				'h2_required'    => false,
				'links_required' => false,
				// Links have no weight on landing pages — conversion focus.
				'weights'        => [
					'title'            => 20,
					'meta_description' => 30,
					'word_count'       => 15,
					'headings'         => 10,
					'images'           => 15,
					'links'            => 0,
				],
			],
		];
	}

	/**
	 * Resolve the active profile key.
	 *
	 * Priority (highest → lowest):
	 *   1. Explicit override   — user chose a specific profile from the dropdown.
	 *   2. WC product          — 'product' post type always → 'product' profile.
	 *   3. Static front page   — the page set as the front page → 'landing' profile.
	 *   4. Fallback            — caller-supplied default (default 'blog').
	 *
	 * @param  string $post_type  WordPress post type slug.
	 * @param  string $override   Explicit profile key from the UI or AJAX param.
	 * @param  int    $post_id    Post ID; when > 0, enables front-page detection.
	 * @param  string $fallback   Profile to use when no rule matches (default 'blog').
	 * @return string  One of the keys returned by self::profiles().
	 */
	public static function resolve_profile(
		string $post_type,
		string $override  = '',
		int    $post_id   = 0,
		string $fallback  = 'blog'
	): string {

		$valid = array_keys( self::profiles() );

		// 1. Explicit override wins unconditionally.
		if ( '' !== $override && in_array( $override, $valid, true ) ) {
			return $override;
		}

		// 2. WooCommerce products always use the product profile.
		if ( 'product' === $post_type ) {
			return 'product';
		}

		// 3. The static front page uses the landing profile.
		if ( $post_id > 0 && (int) get_option( 'page_on_front' ) === $post_id ) {
			return 'landing';
		}

		// 4. Caller-supplied fallback (validated).
		return in_array( $fallback, $valid, true ) ? $fallback : 'blog';
	}

	// =========================================================================
	// Settings save handler
	// =========================================================================

	/**
	 * Handle the admin-post.php form that saves global analysis settings
	 * (currently: the H2-as-H1 equivalence toggle).
	 *
	 * Action: linguaforge_save_seo_analysis
	 * Registered in SettingsPage::init().
	 */
	public static function handle_save_analysis_settings(): void {

		check_admin_referer( 'linguaforge_save_seo_analysis', 'linguaforge_seo_analysis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ) );
		}

		$h2_as_h1 = ! empty( $_POST['linguaforge_seo_h2_as_h1'] );
		update_option( 'linguaforge_seo_h2_as_h1', $h2_as_h1 ? '1' : '' );

		wp_safe_redirect( add_query_arg( 'lf-saved', '1', wp_get_referer() ?: admin_url() ) );
		exit;
	}

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

		$h2_as_h1 = (bool) get_option( 'linguaforge_seo_h2_as_h1' );

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

		<!-- ── Global analysis settings ── -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1.5em;">
			<input type="hidden" name="action" value="linguaforge_save_seo_analysis">
			<?php wp_nonce_field( 'linguaforge_save_seo_analysis', 'linguaforge_seo_analysis_nonce' ); ?>

			<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
				<input type="checkbox" name="linguaforge_seo_h2_as_h1" value="1"
					<?php checked( $h2_as_h1 ); ?>>
				<strong><?php esc_html_e( 'Treat H2 as H1 equivalent', 'lingua-forge' ); ?></strong>
			</label>
			<p class="description" style="margin-top:4px;margin-bottom:8px;">
				<?php esc_html_e( 'Enable when your theme renders the post title as an H2 instead of an H1 (common in some block themes). The analyser fetches the rendered page and, if it finds no H1 but does find an H2, it promotes the first H2 to H1 for scoring purposes.', 'lingua-forge' ); ?>
			</p>

			<?php submit_button( __( 'Save analysis settings', 'lingua-forge' ), 'secondary small', 'submit', false ); ?>
		</form>

		<?php self::render_batch_section( $languages, $source, $public_types ); ?>

		<hr style="margin:2em 0 1.5em;">
		<h3 style="margin-bottom:0.4em;"><?php esc_html_e( 'Per-Post Analysis', 'lingua-forge' ); ?></h3>
		<p class="description" style="margin-bottom:1em;max-width:680px;">
			<?php esc_html_e( 'Select a post and run a detailed SEO audit to see per-metric results and a score for that specific item.', 'lingua-forge' ); ?>
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
	// Batch Analysis section (§10.2 point 2)
	// =========================================================================

	/**
	 * Render the Batch Analysis grid — one card per active language.
	 *
	 * @param string[] $languages   All active language codes.
	 * @param string   $source      Source language code.
	 * @param string[] $public_types  Public post types (attachment excluded).
	 */
	private static function render_batch_section( array $languages, string $source, array $public_types ): void {

		// Post counts per language (one COUNT query per lang — admin-only panel).
		$lang_counts = [];
		foreach ( $languages as $lang ) {
			$q = new \WP_Query( [
				'post_type'      => $public_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[ 'key' => '_lf_lang', 'value' => $lang ],
				],
			] );
			$lang_counts[ $lang ] = $q->found_posts;
		}

		?>
		<h3 style="margin-bottom:0.4em;"><?php esc_html_e( 'Batch Analysis', 'lingua-forge' ); ?></h3>
		<p class="description" style="margin-bottom:1em;max-width:680px;">
			<?php esc_html_e( 'Run a full SEO audit across all published posts for one or all active languages. Scores are saved per post and reflected in the Language column badges in the post list.', 'lingua-forge' ); ?>
			<?php if ( function_exists( 'wc_get_page_id' ) ) : ?>
			<?php esc_html_e( 'WooCommerce system pages (Shop, Cart, Checkout, My Account) are excluded — their content is managed by WooCommerce and cannot be improved through SEO edits.', 'lingua-forge' ); ?>
			<?php endif; ?>
		</p>

		<!-- Batch toolbar -->
		<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:0.6em;">
			<div>
				<label for="lf-batch-filter-type" style="font-weight:600;margin-right:4px;">
					<?php esc_html_e( 'Post type', 'lingua-forge' ); ?>
				</label>
				<select id="lf-batch-filter-type">
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
			<div>
				<label for="lf-batch-profile" style="font-weight:600;margin-right:4px;">
					<?php esc_html_e( 'Default profile', 'lingua-forge' ); ?>
				</label>
				<select id="lf-batch-profile">
					<option value=""><?php esc_html_e( '— Auto-detect —', 'lingua-forge' ); ?></option>
					<?php foreach ( self::profiles() as $pk => $prof ) : ?>
						<option value="<?php echo esc_attr( $pk ); ?>">
							<?php echo esc_html( $prof['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<button type="button" id="lf-batch-analyse-all-btn" class="button">
				<?php esc_html_e( 'Analyse all languages', 'lingua-forge' ); ?>
			</button>
			<span id="lf-batch-all-spinner" class="spinner" style="float:none;margin:0;vertical-align:middle;display:none;"></span>
		</div>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Auto-detect assigns: WooCommerce products → Product profile; the static front page → Landing profile; everything else → Blog profile. Choose a specific profile to override this for all posts.', 'lingua-forge' ); ?>
		</p>

		<!-- Language cards grid -->
		<div class="lf-batch-lang-grid" id="lf-batch-lang-grid">
			<?php foreach ( $languages as $lang ) :
				$count    = (int) ( $lang_counts[ $lang ] ?? 0 );
				$last_run = (int) get_option( 'linguaforge_seo_batch_last_' . $lang, 0 );
				$is_source = ( $lang === $source );
				?>
				<div class="lf-batch-lang-card" id="lf-batch-card-<?php echo esc_attr( $lang ); ?>" data-lang="<?php echo esc_attr( $lang ); ?>">
					<div class="lf-batch-card__lang">
						<?php echo esc_html( strtoupper( $lang ) ); ?>
						<?php if ( $is_source ) : ?>
							<span style="font-size:10px;font-weight:400;color:#646970;margin-left:4px;"><?php esc_html_e( 'source', 'lingua-forge' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="lf-batch-card__name"><?php echo esc_html( linguaforge_language_label( $lang ) ); ?></div>
					<div class="lf-batch-card__meta">
						<?php echo esc_html(
							/* translators: %d: number of posts */
							sprintf( _n( '%d post', '%d posts', $count, 'lingua-forge' ), $count )
						); ?>
					</div>
					<div class="lf-batch-card__meta" id="lf-batch-last-<?php echo esc_attr( $lang ); ?>">
						<?php if ( $last_run > 0 ) : ?>
							<?php echo esc_html(
								/* translators: %s: human-readable time difference */
								sprintf( __( 'Last run: %s ago', 'lingua-forge' ), human_time_diff( $last_run ) )
							); ?>
						<?php else : ?>
							<?php esc_html_e( 'Never run', 'lingua-forge' ); ?>
						<?php endif; ?>
					</div>
					<?php
				$lf_summary = get_option( 'linguaforge_seo_batch_summary_' . $lang, null );
				if ( is_array( $lf_summary ) ) :
					$lf_avg      = (int) ( $lf_summary['avg_score'] ?? 0 );
					$lf_analyzed = (int) ( $lf_summary['analyzed']  ?? 0 );
					$lf_total    = (int) ( $lf_summary['total']     ?? 0 );
					$lf_skipped  = (int) ( $lf_summary['skipped']   ?? 0 );
					$lf_ok       = (int) ( $lf_summary['ok']        ?? 0 );
					$lf_warn     = (int) ( $lf_summary['warn']      ?? 0 );
					$lf_fail     = (int) ( $lf_summary['fail']      ?? 0 );
					$lf_partial  = ! empty( $lf_summary['partial'] );
					$lf_avgcolor = $lf_avg >= 80 ? '#00a32a' : ( $lf_avg >= 50 ? '#dba617' : '#d63638' );
				?>
				<div class="lf-batch-card__stats" id="lf-batch-stats-<?php echo esc_attr( $lang ); ?>" style="margin:8px 0 4px;">
					<div style="font-size:1.4em;font-weight:700;color:<?php echo esc_attr( $lf_avgcolor ); ?>;line-height:1;margin-bottom:4px;">
						<?php echo esc_html( (string) $lf_avg ); ?><span style="font-size:0.5em;font-weight:400;color:#646970;margin-left:3px;"><?php esc_html_e( 'avg', 'lingua-forge' ); ?></span>
					</div>
					<div style="font-size:11px;color:#646970;margin-bottom:3px;">
						<?php echo esc_html( $lf_analyzed . ' / ' . $lf_total ); ?>
						<?php if ( $lf_partial ) : ?><em style="color:#646970;font-size:10px;"><?php esc_html_e( '(partial)', 'lingua-forge' ); ?></em><?php endif; ?>
						<?php if ( $lf_skipped > 0 ) : ?><em style="color:#646970;font-size:10px;"><?php echo esc_html( sprintf( '(%d skipped)', $lf_skipped ) ); ?></em><?php endif; ?>
					</div>
					<div style="font-size:11px;">
						<span style="color:#00a32a;font-weight:600;">&#10003;&thinsp;<?php echo esc_html( (string) $lf_ok ); ?></span>
						&ensp;
						<span style="color:#dba617;font-weight:600;">&#9651;&thinsp;<?php echo esc_html( (string) $lf_warn ); ?></span>
						&ensp;
						<span style="color:#d63638;font-weight:600;">&#10007;&thinsp;<?php echo esc_html( (string) $lf_fail ); ?></span>
					</div>
				</div>
				<?php else : ?>
				<div class="lf-batch-card__stats" id="lf-batch-stats-<?php echo esc_attr( $lang ); ?>" style="display:none;margin:8px 0 4px;"></div>
				<?php endif; ?>
					<div style="display:flex;align-items:center;gap:6px;margin-top:10px;">
						<button type="button" class="button button-small lf-batch-analyse-btn" data-lang="<?php echo esc_attr( $lang ); ?>">
							<?php esc_html_e( 'Analyse', 'lingua-forge' ); ?>
						</button>
						<span class="spinner lf-batch-card-spinner" style="float:none;margin:0;vertical-align:middle;display:none;"></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<!-- Attention list — posts scoring < 70, populated by JS after each run -->
		<div id="lf-batch-attention" style="display:none;margin-bottom:24px;"></div>
		<?php
	}

	// =========================================================================
	// AJAX — batch analysis (§10.2 point 2)
	// =========================================================================

	/**
	 * Return the flat set of post IDs that are WooCommerce system pages
	 * (shop, cart, checkout, myaccount, terms) plus all their language translations.
	 *
	 * These pages have near-zero user-editable SEO content and should be excluded
	 * from batch analysis rather than inflating the "fail" count.
	 *
	 * @return int[]
	 */
	private static function get_wc_system_page_ids(): array {

		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return [];
		}

		$slugs      = [ 'shop', 'cart', 'checkout', 'myaccount', 'terms' ];
		$source_ids = [];

		foreach ( $slugs as $slug ) {
			$id = (int) wc_get_page_id( $slug );
			if ( $id > 0 ) {
				$source_ids[] = $id;
			}
		}

		if ( empty( $source_ids ) ) {
			return [];
		}

		// Collect every language translation of each source page.
		$all_ids = $source_ids;
		$router  = Router::get_instance();

		foreach ( $source_ids as $id ) {
			$translations = $router->trid_group->get_translations( $id );
			foreach ( $translations as $translated_id ) {
				$all_ids[] = (int) $translated_id;
			}
		}

		return array_unique( $all_ids );
	}

	/**
	 * Run SEO analysis on all published posts for a language in fast mode
	 * (content-only headings — no per-post HTTP requests).
	 *
	 * Profile resolution priority per post:
	 *   1. WooCommerce system pages (shop/cart/checkout/myaccount/terms) → skipped
	 *   2. WooCommerce products           → 'product' (always)
	 *   3. The WordPress front page       → 'landing' (always)
	 *   4. Everything else                → $fallback_profile (user-selected, default 'blog')
	 *
	 * POST params:
	 *   lang      — language code (required)
	 *   post_type — limit to one post type; empty = all public types
	 *   profile   — fallback profile key for non-auto-detected posts ('blog'|'landing')
	 *
	 * Response JSON:
	 *   lang, total, analyzed, skipped, avg_score, ok, warn, fail, partial, attention[]
	 *   attention: all analyzed posts, sorted ascending by score, max 200 items per language.
	 *   Each item: { id, lang, title, source_title, type, profile, score, edit_url }
	 */
	public static function ajax_batch_analyze(): void {

		check_ajax_referer( 'linguaforge_seo_analyze', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'lingua-forge' ) ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_key handles unslashing.
		$lang      = sanitize_key( $_POST['lang']      ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_key handles unslashing.
		$post_type = sanitize_key( $_POST['post_type'] ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_key handles unslashing.
		$profile_param    = sanitize_key( $_POST['profile'] ?? 'blog' );
		$valid_profiles   = array_keys( self::profiles() );
		$fallback_profile = in_array( $profile_param, $valid_profiles, true ) ? $profile_param : 'blog';

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
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];

		if ( '' !== $lang ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => '_lf_lang', 'value' => $lang ],
			];
		}

		$post_ids       = get_posts( $query_args );
		$total          = count( $post_ids );
		$analyzed       = 0;
		$skipped        = 0;
		$ok             = 0;
		$warn           = 0;
		$fail           = 0;
		$score_sum      = 0;
		$attention      = [];
		$partial        = false;
		$start          = microtime( true );
		$max_exec       = (int) ini_get( 'max_execution_time' );
		$wc_system_ids  = self::get_wc_system_page_ids();
		$router         = Router::get_instance();
		$source_lang    = $router->context->source_language();

		foreach ( $post_ids as $id ) {

			// Bail if approaching the time limit (keep 5 s buffer).
			if ( $max_exec > 0 && ( microtime( true ) - $start ) > ( $max_exec - 5 ) ) {
				$partial = true;
				break;
			}

			// Skip WooCommerce system pages — their content is WC-managed, not user SEO.
			if ( in_array( (int) $id, $wc_system_ids, true ) ) {
				$skipped++;
				continue;
			}

			$post      = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			$post_type_key = get_post_type( $post ) ?: 'post';

			// Delegate to the canonical resolver.
			// resolve_profile() handles: explicit override → product → front page → fallback.
			// No explicit override in batch mode — the user-selected $fallback_profile
			// acts as the fallback (param 4), so product and front-page auto-detection
			// still fire normally.
			$profile_key = self::resolve_profile( $post_type_key, '', (int) $id, $fallback_profile );

			$profile_data = self::profiles()[ $profile_key ];
			// Fast mode: skip per-post frontend HTTP request for heading extraction.
			$metrics = self::analyze( $post, $profile_data, true );
			$score   = self::compute_score( $metrics, $profile_data['weights'] );
			self::save_score_history( $id, $score );

			$analyzed++;
			$score_sum += $score;

			if ( $score >= 80 )     { $ok++; }
			elseif ( $score >= 50 ) { $warn++; }
			else                    { $fail++; }

			// Collect every analyzed post for the parity overview.
			// Resolve the source-language title for translation comparison.
			$source_title  = '';
			$post_lang     = (string) get_post_meta( (int) $id, '_lf_lang', true );
			if ( '' !== $post_lang && $post_lang !== $source_lang ) {
				$trid = (string) get_post_meta( (int) $id, '_lf_trid', true );
				if ( '' !== $trid ) {
					$translations = $router->trid_group->get_translations( (int) $id );
					if ( isset( $translations[ $source_lang ] ) ) {
						$src_post = get_post( (int) $translations[ $source_lang ] );
						if ( $src_post ) {
							$source_title = get_the_title( $src_post );
						}
					}
				}
			}

			$post_type_obj = get_post_type_object( $post_type_key );
			$attention[]   = [
				'id'           => (int) $id,
				'lang'         => $lang,
				'title'        => get_the_title( $post ),
				'source_title' => $source_title,
				'type'         => $post_type_obj ? $post_type_obj->labels->singular_name : $post_type_key,
				'profile'      => $profile_key,
				'score'        => $score,
				'edit_url'     => get_edit_post_link( $id, 'raw' ),
			];
		}

		$avg_score = $analyzed > 0 ? (int) round( $score_sum / $analyzed ) : 0;

		// Sort by score ascending (worst first); cap at 200 per language to keep response lean.
		usort( $attention, static fn( array $a, array $b ): int => $a['score'] <=> $b['score'] );
		$attention = array_slice( $attention, 0, 200 );

		// Persist last-run timestamp and summary so the card can show results on
		// the next page load without requiring another run.
		if ( '' !== $lang ) {
			update_option( 'linguaforge_seo_batch_last_' . $lang, time(), false );
			update_option( 'linguaforge_seo_batch_summary_' . $lang, [
				'avg_score' => $avg_score,
				'analyzed'  => $analyzed,
				'total'     => $total,
				'skipped'   => $skipped,
				'ok'        => $ok,
				'warn'      => $warn,
				'fail'      => $fail,
				'partial'   => $partial,
			], false );
		}

		wp_send_json_success( [
			'lang'      => $lang,
			'total'     => $total,
			'analyzed'  => $analyzed,
			'skipped'   => $skipped,
			'avg_score' => $avg_score,
			'ok'        => $ok,
			'warn'      => $warn,
			'fail'      => $fail,
			'partial'   => $partial,
			'attention' => $attention,
		] );
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

		$post_id  = absint( $_POST['post_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_key handles unslashing.
		$lang     = sanitize_key( $_POST['lang']    ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$profile_override = sanitize_key( $_POST['profile'] ?? '' );

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

		// Check if this is a WooCommerce system page (shop/cart/checkout/myaccount/terms).
		// These pages are managed by WooCommerce, not editable SEO content.
		$is_wc_system_page = in_array( $analyzed_post->ID, self::get_wc_system_page_ids(), true );

		$profile_key  = self::resolve_profile( get_post_type( $analyzed_post ) ?: 'post', $profile_override, $analyzed_post->ID );
		$profile_data = self::profiles()[ $profile_key ];

		$metrics = self::analyze( $analyzed_post, $profile_data );

		$score          = self::compute_score( $metrics, $profile_data['weights'] );
		// Read previous score before overwriting so we can include it in the response.
		$prev_history   = self::get_score_history( $analyzed_post->ID );
		$previous_score = isset( $prev_history[0] ) ? (int) $prev_history[0]['score'] : null;
		self::save_score_history( $analyzed_post->ID, $score );

		wp_send_json_success( [
			'post_id'          => $analyzed_post->ID,
			'post_title'       => get_the_title( $analyzed_post ),
			'lang'             => $used_lang,
			'used_source'      => $used_source,
			'is_wc_system_page' => $is_wc_system_page,
			'profile'          => $profile_key,
			'metrics'          => $metrics,
			'score'            => $score,
			'previous_score'   => $previous_score,
		] );
	}

	// =========================================================================
	// Score history
	// =========================================================================

	/**
	 * Prepend a new score entry and keep the two most recent.
	 * Stored as a serialised PHP array in `_lf_seo_score_history`.
	 *
	 * @param int $post_id
	 * @param int $score   0–100
	 */
	public static function save_score_history( int $post_id, int $score ): void {
		$history = self::get_score_history( $post_id );
		array_unshift( $history, [ 'score' => $score, 'ts' => time() ] );
		update_post_meta( $post_id, '_lf_seo_score_history', array_slice( $history, 0, 2 ) );
	}

	/**
	 * Return stored score history for a post, newest first (up to 2 entries).
	 *
	 * @param  int $post_id
	 * @return array<int, array{score: int, ts: int}>
	 */
	public static function get_score_history( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_lf_seo_score_history', true );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		return array_values(
			array_filter( $raw, static fn( $e ) => isset( $e['score'], $e['ts'] ) )
		);
	}

	// =========================================================================
	// Analysis engine
	// =========================================================================

	/**
	 * Run all rule-based checks on a post.
	 *
	 * @param  \WP_Post             $post
	 * @param  array<string, mixed> $profile  Profile data from self::profiles(). Defaults to blog profile.
	 * @return array<string, array<string, mixed>>
	 */
	private static function analyze( \WP_Post $post, array $profile = [], bool $fast = false ): array {

		if ( empty( $profile ) ) {
			$profile = self::profiles()['blog'];
		}

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
		$images       = self::analyze_images( $raw_html );
		$links        = self::analyze_links( $raw_html );
		$has_lf_meta  = '' !== (string) get_post_meta( $post->ID, '_linguaforge_meta_description', true );
		$h2_as_h1     = (bool) get_option( 'linguaforge_seo_h2_as_h1' );

		// Prefer headings extracted from the full rendered frontend page — this
		// captures the theme-rendered title tag (H1 or H2) which is not present
		// in post_content.  Falls back to content-only parsing when the page is
		// unreachable (local dev, staging with HTTP auth, etc.).
		// $fast skips the HTTP request for batch mode where per-post fetches would
		// be prohibitively slow.
		$paragraph_count = self::count_paragraphs( $raw_html );

		$permalink = get_permalink( $post->ID );
		$headings  = ( ! $fast && $permalink ? self::extract_headings_from_url( $permalink ) : null )
			?? self::extract_headings( $raw_html );

		return [
			'title'            => self::rate_title( $title, $title_len, $profile['title'] ),
			'meta_description' => self::rate_meta( $meta_desc, $meta_len, $has_lf_meta, $profile['meta'] ),
			'word_count'       => self::rate_words( $words, $profile['words'] ),
			'reading_time'     => [
				'value'   => $reading_time,
				'display' => sprintf(
					/* translators: %d: minutes */
					_n( '~%d min read', '~%d min read', $reading_time, 'lingua-forge' ),
					$reading_time
				),
				'status'  => 'info',
			],
			'headings'         => self::rate_headings( $headings, $profile['h2_required'], $h2_as_h1, $paragraph_count ),
			'images'           => self::rate_images( $images ),
			'links'            => self::rate_links( $links, $profile['links_required'] ),
		];
	}

	// =========================================================================
	// Metric raters
	// =========================================================================

	/**
	 * @param  array{min:int,max:int} $thresholds  Title character range. Defaults to blog profile.
	 * @return array<string, mixed>
	 */
	public static function rate_title( string $title, int $len, array $thresholds = [ 'min' => 30, 'max' => 60 ] ): array {

		// $thresholds['min'] is no longer used for scoring — replaced by the
		// fixed > 10 char + word-count checks below.  $thresholds['max'] still
		// governs the SERP-truncation warning.
		$max   = $thresholds['max'];
		$words = ( '' !== $title )
			? count( array_filter( (array) preg_split( '/\s+/u', trim( $title ) ) ) )
			: 0;

		if ( '' === $title ) {
			return [ 'value' => '', 'length' => 0, 'status' => 'fail',
				'message' => __( 'No title set.', 'lingua-forge' ) ];
		}
		if ( $len > $max ) {
			return [ 'value' => $title, 'length' => $len, 'status' => 'warn',
				/* translators: %1$d: character count, %2$d: SERP limit */
				'message' => sprintf( __( 'Title is %1$d chars — may be truncated in SERPs (aim for under %2$d).', 'lingua-forge' ), $len, $max ) ];
		}
		if ( $len < 10 ) {
			return [ 'value' => $title, 'length' => $len, 'status' => 'warn',
				/* translators: %d: character count */
				'message' => sprintf( __( 'Title is only %d chars — aim for at least 10 characters.', 'lingua-forge' ), $len ) ];
		}
		if ( $words < 2 ) {
			return [ 'value' => $title, 'length' => $len, 'status' => 'warn',
				/* translators: %d: character count */
				'message' => sprintf( __( 'Single-word title (%d chars) — adding a second word improves clarity and keyword coverage.', 'lingua-forge' ), $len ) ];
		}
		// ≥ 10 chars, 2+ words, within SERP limit → ok
		return [ 'value' => $title, 'length' => $len, 'status' => 'ok',
			/* translators: %1$d: character count, %2$d: word count */
			'message' => sprintf( __( '%1$d chars, %2$d words — good title.', 'lingua-forge' ), $len, $words ) ];
	}

	/**
	 * @param  array{min:int,max:int} $thresholds  Meta description character range. Defaults to blog profile.
	 * @return array<string, mixed>
	 */
	public static function rate_meta( string $meta, int $len, bool $is_lf, array $thresholds = [ 'min' => 120, 'max' => 210 ] ): array {

		$min     = $thresholds['min'];
		$max     = $thresholds['max'];
		$ideal   = 160; // Soft ideal upper bound; above this is still ok up to $max.

		$source = $is_lf
			? __( 'Using LF AI meta description.', 'lingua-forge' )
			: __( 'No LF meta description set — using excerpt/content.', 'lingua-forge' );

		if ( $len === 0 ) {
			return [ 'value' => '', 'length' => 0, 'status' => 'fail',
				'message' => __( 'No meta description found. Generate one via the AI meta description feature.', 'lingua-forge' ) ];
		}
		if ( $len < $min ) {
			return [ 'value' => $meta, 'length' => $len, 'status' => 'warn',
				/* translators: %1$d: number of characters, %2$d: ideal min, %3$d: ideal max, %4$s: source note */
				'message' => sprintf( __( '%1$d chars — too short (aim for %2$d–%3$d). %4$s', 'lingua-forge' ), $len, $min + 20, $ideal, $source ) ];
		}
		if ( $len > $max ) {
			return [ 'value' => $meta, 'length' => $len, 'status' => 'warn',
				/* translators: %1$d: number of characters, %2$d: hard max, %3$s: source note */
				'message' => sprintf( __( '%1$d chars — likely truncated in SERPs (keep under %2$d). %3$s', 'lingua-forge' ), $len, $max, $source ) ];
		}
		if ( $len > $ideal ) {
			return [ 'value' => $meta, 'length' => $len, 'status' => 'ok',
				/* translators: %1$d: number of characters, %2$d: ideal max, %3$d: hard max, %4$s: source note */
				'message' => sprintf( __( '%1$d chars — good, slightly above the ideal %2$d but within the accepted %3$d-char range. %4$s', 'lingua-forge' ), $len, $ideal, $max, $source ) ];
		}
		return [ 'value' => $meta, 'length' => $len, 'status' => 'ok',
			/* translators: %1$d: number of characters, %2$s: source note */
			'message' => sprintf( __( '%1$d chars — ideal length. %2$s', 'lingua-forge' ), $len, $source ) ];
	}

	/**
	 * @param  array{fail:int,warn:int} $thresholds  Word count thresholds. Defaults to blog profile.
	 * @return array<string, mixed>
	 */
	public static function rate_words( int $count, array $thresholds = [ 'fail' => 100, 'warn' => 300 ] ): array {

		$fail = $thresholds['fail'];
		$warn = $thresholds['warn'];

		if ( $count < $fail ) {
			return [ 'value' => $count, 'status' => 'fail',
				/* translators: %1$d: word count, %2$d: recommended minimum */
				'message' => sprintf( __( '%1$d words — very thin content. Consider expanding to at least %2$d words.', 'lingua-forge' ), $count, $warn ) ];
		}
		if ( $count < $warn ) {
			return [ 'value' => $count, 'status' => 'warn',
				/* translators: %1$d: word count, %2$d: recommended minimum */
				'message' => sprintf( __( '%1$d words — below recommended minimum of %2$d.', 'lingua-forge' ), $count, $warn ) ];
		}
		return [ 'value' => $count, 'status' => 'ok',
			/* translators: %d: word count */
			'message' => sprintf( __( '%d words.', 'lingua-forge' ), $count ) ];
	}

	/**
	 * @param  bool $h2_required      Whether missing H2 is a warn (true) or info (false).
	 * @param  bool $h2_as_h1         When true and the rendered page has no H1 but has at
	 *                                least one H2, promote the first H2 to H1. This covers
	 *                                themes that output the post title inside <h2> rather
	 *                                than <h1>. Because we now fetch the rendered page,
	 *                                both the theme title tag and in-content headings are
	 *                                visible, so the H2 from the title will actually be
	 *                                present when this promotion is needed.
	 * @param  int  $paragraph_count  Number of <p> tags in the content. When between 1
	 *                                and 3 (inclusive) the missing-subheading warning is
	 *                                suppressed — short content does not benefit from
	 *                                forced H2/H3 structure. Pass 0 (default) to skip
	 *                                the threshold check (backward-compatible).
	 * @return array<string, mixed>
	 */
	public static function rate_headings( array $headings, bool $h2_required = true, bool $h2_as_h1 = false, int $paragraph_count = 0 ): array {

		$h1 = $headings['h1'] ?? 0;
		$h2 = $headings['h2'] ?? 0;

		if ( $h2_as_h1 ) {
			if ( $h1 === 0 && $h2 > 0 ) {
				// Rendered page has an H2 but no H1 — theme uses H2 for the title.
				// Promote the first H2 so it is counted as H1.
				$h1             = 1;
				$h2--;
				$headings['h1'] = $h1;
				$headings['h2'] = $h2;
			} elseif ( $h1 === 0 ) {
				// No H1 or H2 detected.  Most likely the rendered-page fetch failed
				// (local dev, staging auth) so the theme title tag was invisible.
				// Credit H1 = 1 as the user explicitly declared this theme pattern.
				// Use 'ok' so the score is not penalised; add a note for transparency.
				return array_merge( $headings, [
					'status'  => 'ok',
					'message' => __( 'H2-as-H1 mode enabled — title counted as H1 equivalent. (Heading tags could not be detected from the rendered page; verify your theme outputs the title correctly.)', 'lingua-forge' ),
				] );
			}
		}

		if ( $h1 === 0 ) {
			$status  = 'warn';
			$message = __( 'No H1 found. Typically the post title is the H1 — verify your theme is outputting it.', 'lingua-forge' );
		} elseif ( $h1 > 1 ) {
			$status = 'fail';
			/* translators: %d: number of H1 tags found */
			$message = sprintf( __( '%d H1 tags found — a page should have exactly one H1.', 'lingua-forge' ), $h1 );
		} elseif ( $h2 === 0 ) {
			$h3 = $headings['h3'] ?? 0;
			if ( $h3 > 0 ) {
				// Accordion and Details blocks default to H3 for their headings.
				// H3-based structure is valid when no H2 is present — treat as ok.
				$status  = 'ok';
				$message = sprintf(
					/* translators: %d: number of H3 headings found */
					__( '1 H1 ✓ — no H2, but %d H3 subheading(s) found (e.g. accordion/details headers). Structure is acceptable.', 'lingua-forge' ),
					$h3
				);
			} else {
				// Short content (≤ 3 paragraphs) does not benefit from forced heading
				// structure — suppress the warning when we have an actual count.
				$is_short = $paragraph_count > 0 && $paragraph_count <= 3;
				if ( ! $h2_required || $is_short ) {
					$status  = 'ok';
					$message = $is_short && $h2_required
						/* translators: %d: number of paragraphs in the content */
						? sprintf( __( '1 H1 ✓ — short content (%d paragraph(s)), subheadings not required.', 'lingua-forge' ), $paragraph_count )
						: __( '1 H1 ✓ — no H2 subheadings (acceptable for this content type).', 'lingua-forge' );
				} else {
					$status  = 'warn';
					$message = __( '1 H1 ✓ — no H2 or H3 subheadings found. Adding H2s improves structure.', 'lingua-forge' );
				}
			}
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

	/**
	 * @param  bool $links_required  Whether missing internal links is a warn (true) or info (false).
	 * @return array<string, mixed>
	 */
	public static function rate_links( array $links, bool $links_required = true ): array {

		$internal = $links['internal'];
		$external = $links['external'];

		if ( $internal === 0 && $external === 0 ) {
			$status  = $links_required ? 'warn' : 'info';
			$message = $links_required
				? __( 'No links found. Internal links improve crawlability and user navigation.', 'lingua-forge' )
				: __( 'No links found.', 'lingua-forge' );
			return array_merge( $links, [ 'status' => $status, 'message' => $message ] );
		}
		if ( $internal === 0 ) {
			$status  = $links_required ? 'warn' : 'info';
			$message = $links_required
				? sprintf(
					/* translators: %d: number of external links */
					__( '%d external link(s), no internal links. Add internal links to related content.', 'lingua-forge' ),
					$external
				)
				: sprintf(
					/* translators: %d: number of external links */
					__( '%d external link(s), no internal links.', 'lingua-forge' ),
					$external
				);
			return array_merge( $links, [ 'status' => $status, 'message' => $message ] );
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
	 * Count <p> tags in block-rendered HTML.
	 *
	 * Used by rate_headings() to decide whether missing subheadings should
	 * trigger a warning: short content (≤ 3 paragraphs) does not need H2/H3
	 * structure; longer content does.
	 *
	 * @param  string $html  Block-rendered post HTML (do_blocks output).
	 * @return int
	 */
	public static function count_paragraphs( string $html ): int {
		return (int) preg_match_all( '/<p[\s>]/i', $html );
	}

	/**
	 * Fetch the rendered frontend page and extract heading counts from it.
	 *
	 * This captures headings that live in the theme template (e.g. the post
	 * title rendered as <h1> or <h2>) which are not visible in post_content.
	 * Returns null when the page is unreachable so the caller can fall back to
	 * content-only parsing.
	 *
	 * @param  string $url  Public permalink of the post.
	 * @return array{h1:int,h2:int,h3:int,h4:int,h5:int,h6:int}|null
	 */
	private static function extract_headings_from_url( string $url ): ?array {

		$response = wp_remote_get( $url, [
			'timeout'    => 8,
			'user-agent' => 'LinguaForge-SEO-Analyzer/1.0',
			/** This filter allows disabling SSL verification for local/staging environments. */
			'sslverify'  => (bool) apply_filters( 'linguaforge_seo_sslverify', true ),
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return self::extract_headings( wp_remote_retrieve_body( $response ) );
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
	 * Weights must sum to 90; 10 points are always awarded for reading time
	 * (informational only). ok = full weight, warn = half weight, fail/info = 0.
	 *
	 * @param  array<string, array<string, mixed>> $metrics
	 * @param  array<string, int>                  $weights  Per-metric weights. Defaults to blog profile.
	 * @return int
	 */
	public static function compute_score( array $metrics, array $weights = [] ): int {

		if ( empty( $weights ) ) {
			$weights = self::profiles()['blog']['weights'];
		}

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

		$post_id       = absint( $_POST['post_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$lang          = sanitize_key( $_POST['lang'] ?? '' );
		$force_refresh = ! empty( $_POST['force_refresh'] );

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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$ai_profile_key  = self::resolve_profile( $post->post_type, sanitize_key( $_POST['profile'] ?? '' ), $post->ID );
		$ai_profile_data = self::profiles()[ $ai_profile_key ];
		$metrics         = self::analyze( $post, $ai_profile_data );
		$score           = self::compute_score( $metrics, $ai_profile_data['weights'] );

		// Build the AI prompt — tailored per profile.
		$title          = get_the_title( $post );
		$content        = wp_strip_all_tags( do_blocks( $post->post_content ) );
		$meta_desc      = (string) get_post_meta( $post->ID, '_linguaforge_meta_description', true );
		$word_count     = $metrics['word_count']['value'] ?? 0;
		$h1             = $metrics['headings']['h1'] ?? 0;
		$h2             = $metrics['headings']['h2'] ?? 0;
		$img_no_alt     = $metrics['images']['without_alt'] ?? 0;
		$internal       = $metrics['links']['internal'] ?? 0;
		$meta_display   = '' !== $meta_desc ? $meta_desc : 'Not set';
		$language_label = '' !== $lang ? linguaforge_language_label( $lang ) : 'Unknown';

		// ── Cache check ────────────────────────────────────────────────────────
		// Hash covers every input that influences the AI output. The score is
		// intentionally excluded because it is derived from these same inputs.
		$cache_feature = 'seo-ai-' . $ai_profile_key;
		$cache_hash    = CacheStore::hash( [ $post_id, $ai_profile_key, $lang, $post->post_content, $title, $meta_desc ] );

		if ( ! $force_refresh ) {
			$cached = CacheStore::get( $post_id, $cache_feature, $cache_hash );
			if ( is_array( $cached ) ) {
				wp_send_json_success( array_merge( $cached, [ 'from_cache' => true ] ) );
			}
		}

		// Build profile-specific metric context and constraints.
		$profile_context = match ( $ai_profile_key ) {
			'product' => sprintf(
				"You are an expert eCommerce SEO consultant analyzing a WooCommerce product page.\n\n" .
				"Content type: WooCommerce product\n" .
				"Product name: %s\n" .
				"Language: %s\n" .
				"Current SEO score: %d/100\n" .
				"Meta description: %s\n" .
				"Product description length: %d words\n" .
				"Images without alt text: %d\n\n" .
				"CONSTRAINTS — do NOT mention these in your output:\n" .
				"- Heading tags (H1/H2): the WooCommerce theme template outputs the product name as H1 automatically.\n" .
				"- Internal links: not a relevant ranking factor for product pages.\n\n" .
				"Focus your recommendations on: product title keyword clarity, meta description, " .
				"product description richness and keyword relevance, and image alt text.",
				$title, $language_label, $score, $meta_display, $word_count, $img_no_alt
			),
			'landing' => sprintf(
				"You are an expert SEO consultant analyzing a landing page.\n\n" .
				"Content type: Landing page\n" .
				"Page title: %s\n" .
				"Language: %s\n" .
				"Current SEO score: %d/100\n" .
				"Meta description: %s\n" .
				"Word count: %d\n" .
				"Images without alt text: %d\n\n" .
				"CONSTRAINTS — do NOT mention these in your output:\n" .
				"- Internal links: landing pages are conversion-focused; link structure is not a primary SEO concern here.\n\n" .
				"Focus your recommendations on: title keyword targeting, meta description, " .
				"content clarity and keyword depth, and image alt text.",
				$title, $language_label, $score, $meta_display, $word_count, $img_no_alt
			),
			default => sprintf( // blog / editorial
				"You are an expert multilingual SEO consultant analyzing a blog post.\n\n" .
				"Content type: Blog post / editorial\n" .
				"Post title: %s\n" .
				"Language: %s\n" .
				"Current SEO score: %d/100\n" .
				"Meta description: %s\n" .
				"Word count: %d\n" .
				"Headings — H1: %d, H2: %d\n" .
				"Images without alt text: %d\n" .
				"Internal links: %d",
				$title, $language_label, $score, $meta_display, $word_count, $h1, $h2, $img_no_alt, $internal
			),
		};

		$prompt = $profile_context . "\n\n" .
			"Content excerpt (first 1500 characters):\n" .
			mb_substr( $content, 0, 1500 ) . "\n\n" .
			"Respond ONLY with a valid JSON object — no markdown, no code fences, just the JSON:\n" .
			"{\n" .
			'  "summary": "2-3 sentence overall assessment of the SEO quality and main opportunity",' . "\n" .
			'  "improvements": ["specific actionable recommendation 1", "..."],' . "\n" .
			'  "title_suggestion": "improved title if current one could be better, or null",' . "\n" .
			'  "meta_suggestion": "improved meta description (140-160 chars ideal, up to 210 accepted) if needed, or null"' . "\n" .
			"}\n\n" .
			"Provide 3-5 improvements maximum. Be specific and actionable. Write in the same language as the post content.";

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

		$payload = [
			'summary'          => (string) ( $data['summary']          ?? '' ),
			'improvements'     => (array)  ( $data['improvements']     ?? [] ),
			'title_suggestion' => isset( $data['title_suggestion'] ) && null !== $data['title_suggestion']
				? (string) $data['title_suggestion'] : null,
			'meta_suggestion'  => isset( $data['meta_suggestion'] )  && null !== $data['meta_suggestion']
				? (string) $data['meta_suggestion']  : null,
		];

		// Store in cache — keyed by post + profile + content hash.
		CacheStore::set( $post_id, $cache_feature, $cache_hash, $payload );

		wp_send_json_success( array_merge( $payload, [ 'from_cache' => false ] ) );
	}
}
