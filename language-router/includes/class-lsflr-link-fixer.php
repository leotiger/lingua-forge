<?php
/**
 * Class LSFLR_Link_Fixer
 *
 * Scans translated posts/pages for internal links that still point to the
 * source-language version of a page and rewrites them to the correct
 * language equivalent using the TRID translation group system.
 *
 * UI: a "Fix Links" button appears in the posts/pages list view whenever
 * a language filter is active. Clicking it opens a modal overlay that shows
 * a dry-run scan, then lets the editor fix posts individually or all at once.
 *
 * Singleton of the admin concern — instantiated once from language-router.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSFLR_Link_Fixer {

	private Language_Router $router;

	public function __construct( Language_Router $router ) {
		$this->router = $router;
		$this->register_hooks();
	}

	// =========================================================
	// HOOKS
	// =========================================================

	private function register_hooks(): void {
		add_action( 'restrict_manage_posts',      [ $this, 'render_fix_links_button' ] );
		add_action( 'admin_footer',               [ $this, 'render_modal' ] );
		add_action( 'wp_ajax_lsflr_scan_links',   [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_lsflr_fix_post',     [ $this, 'ajax_fix_post' ] );
	}

	// =========================================================
	// CORE: URL EXTRACTION
	// =========================================================

	/**
	 * Return every internal post link found in <a> tags that carries a
	 * Gutenberg data-id attribute.
	 *
	 * Gutenberg sets data-id="<post_ID>" on every link that was created via the
	 * built-in link toolbar and points to an internal post or page.  This is the
	 * most reliable identifier available — no URL parsing, no slug resolution,
	 * no rewrite-rule dependency.
	 *
	 * Links WITHOUT data-id are silently skipped.  This eliminates false
	 * positives from breadcrumbs, navigation anchors, and other structural links
	 * that happen to be internal but are not editorial post links.
	 *
	 * Each entry:
	 *   'url' => string  Absolute URL (normalised to canonical home_url() scheme).
	 *   'id'  => int     Post ID from data-id.
	 *
	 * @return array<array{ url: string, id: int }> De-duplicated by post ID.
	 */
	private function extract_internal_links( string $content ): array {
		// Capture the full attribute string of every <a …> opening tag.
		if ( ! preg_match_all( '/<a\s([^>]*)>/i', $content, $tag_matches ) ) {
			return [];
		}

		$home     = untrailingslashit( home_url() );
		$home_alt = $this->alt_scheme( $home );

		$links = []; // keyed by post ID to de-duplicate

		foreach ( $tag_matches[1] as $attrs ) {

			// ── Require data-id — skip anything that doesn't have one ─────────────
			if ( ! preg_match( '/\bdata-id="(\d+)"/', $attrs, $id_m ) ) {
				continue;
			}
			$post_id = (int) $id_m[1];

			// ── href ──────────────────────────────────────────────────────────────
			if ( ! preg_match( '/\bhref="([^"#][^"]*)"/', $attrs, $href_m ) ) {
				continue;
			}
			$raw = trim( $href_m[1] );
			if ( ! $raw ) {
				continue;
			}

			// Normalise to absolute canonical URL.
			if ( str_starts_with( $raw, $home ) ) {
				$abs_url = $raw;
			} elseif ( $home_alt !== null && str_starts_with( $raw, $home_alt ) ) {
				$abs_url = $home . substr( $raw, strlen( $home_alt ) );
			} elseif ( $raw[0] === '/' && ( strlen( $raw ) < 2 || $raw[1] !== '/' ) ) {
				$abs_url = $home . $raw;
			} else {
				continue; // external or protocol-relative — not an internal post link
			}

			$links[ $post_id ] = [ 'url' => $abs_url, 'id' => $post_id ];
		}

		return array_values( $links );
	}

	/**
	 * Return the http↔https counterpart of $url, or null if not applicable.
	 */
	private function alt_scheme( string $url ): ?string {
		if ( str_starts_with( $url, 'https://' ) ) {
			return 'http://' . substr( $url, 8 );
		}
		if ( str_starts_with( $url, 'http://' ) ) {
			return 'https://' . substr( $url, 7 );
		}
		return null;
	}

	// =========================================================
	// CORE: data-id → validated post ID
	// =========================================================

	/**
	 * Validate that the post ID from a Gutenberg data-id attribute still exists.
	 *
	 * Returns the ID when the post is found, 0 when it has been deleted or the
	 * ID is otherwise invalid (e.g. content copy-pasted from another site).
	 */
	private function resolve_to_post_id( int $data_id ): int {
		return ( $data_id && get_post( $data_id ) ) ? $data_id : 0;
	}

	// =========================================================
	// CORE: SCAN
	// =========================================================

	/**
	 * Analyse a single post and return every internal link that does not
	 * already point to $target_lang.
	 *
	 * The first check is intentionally simple: any link whose href does NOT
	 * start with the target-language prefix is wrong by definition — whether
	 * it is a no-prefix Catalan source URL, a /fr/ URL, or any other language.
	 *
	 * Results are split into two buckets:
	 *
	 *   fixes   — links we can auto-correct (TRID translation found).
	 *   flagged — links that are wrong but couldn't be auto-resolved; shown
	 *             to the editor for manual review with a reason code.
	 *
	 * Reason codes for flagged items:
	 *   unresolved      – URL could not be mapped to a post ID
	 *   no_translation  – post found but has no $target_lang translation in TRID
	 *   permalink_error – target post found but get_permalink returned nothing useful
	 *
	 * @return array{
	 *   post_id: int,
	 *   title:   string,
	 *   fixes:   list<array{ from: string, to: string, linked_post_id: int, linked_post_title: string, target_post_id: int }>,
	 *   flagged: list<array{ url: string, reason: string, linked_post_id?: int, linked_post_title?: string }>
	 * }
	 */
	public function scan_post( int $post_id, string $target_lang ): array {
		// Always read fresh from the DB so an immediate Re-scan after a fix
		// doesn't receive stale cached data.
		clean_post_cache( $post_id );
		$this->router->clear_translation_cache( $post_id );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$target_prefix = trailingslashit( home_url() ) . $target_lang . '/';
		$fixes         = [];
		$flagged       = [];

		foreach ( $this->extract_internal_links( $post->post_content ) as $link ) {
			$url = $link['url'];

			// ── Already correct: link already carries the target-language prefix ──
			if ( str_starts_with( $url, $target_prefix ) ) {
				continue;
			}

			// ── Wrong language — validate the data-id and look up translations ────
			$linked_id = $this->resolve_to_post_id( $link['id'] );

			if ( ! $linked_id ) {
				// We can see the link is wrong but can't map it to a post.
				// Surface it so the editor can fix it manually.
				$flagged[] = [
					'url'    => $url,
					'reason' => 'unresolved',
				];
				continue;
			}

			$translations = $this->router->get_translations( $linked_id );

			if ( empty( $translations[ $target_lang ] ) ) {
				// Post found but has no translation registered for this language.
				$flagged[] = [
					'url'               => $url,
					'reason'            => 'no_translation',
					'linked_post_id'    => $linked_id,
					'linked_post_title' => get_the_title( $linked_id ),
				];
				continue;
			}

			$target_id = (int) $translations[ $target_lang ];

			if ( $target_id === $linked_id ) {
				// The resolved post IS the target-language version — already correct.
				continue;
			}

			$new_url = get_permalink( $target_id );

			if ( ! $new_url || $new_url === $url ) {
				$flagged[] = [
					'url'               => $url,
					'reason'            => 'permalink_error',
					'linked_post_id'    => $linked_id,
					'linked_post_title' => get_the_title( $linked_id ),
				];
				continue;
			}

			$fixes[] = [
				'from'              => $url,
				'to'                => $new_url,
				'linked_post_id'    => $linked_id,
				'linked_post_title' => get_the_title( $linked_id ),
				'target_post_id'    => $target_id,
			];
		}

		return [
			'post_id' => $post_id,
			'title'   => $post->post_title,
			'fixes'   => $fixes,
			'flagged' => $flagged,
		];
	}

	// =========================================================
	// CORE: FIX
	// =========================================================

	/**
	 * Apply all available link fixes to a single post and persist the result.
	 *
	 * Uses exact href-attribute matching instead of plain str_replace so that a
	 * short URL (e.g. /aprop/recursos/) never corrupts a longer sibling URL that
	 * shares the same prefix (e.g. /aprop/recursos/mu-plugins-de-cal-talaia/).
	 * Also handles root-relative hrefs that extract_internal_links normalises to
	 * absolute for scanning purposes.
	 *
	 * @return array{ applied: int }
	 */
	public function fix_post( int $post_id, string $target_lang ): array {
		$scan = $this->scan_post( $post_id, $target_lang );

		// scan_post returns [] (no keys) when the post does not exist.
		if ( empty( $scan ) || empty( $scan['fixes'] ) ) {
			return [ 'applied' => 0 ];
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return [ 'applied' => 0 ];
		}

		$content = $post->post_content;
		$applied = 0;

		$home = untrailingslashit( home_url() ); // e.g. https://example.com

		foreach ( $scan['fixes'] as $fix ) {
			$to_url = $fix['to'];
			$count  = 0;

			// Build the list of URL forms that may appear literally in the content.
			// Gutenberg saves absolute hrefs, but older content or copy-paste can
			// produce root-relative ones — handle both.
			$search_urls = [ $fix['from'] ];

			if ( str_starts_with( $fix['from'], $home ) ) {
				// Root-relative counterpart: strip the scheme+host prefix.
				$search_urls[] = substr( $fix['from'], strlen( $home ) );
			}

			foreach ( $search_urls as $search_url ) {
				// Match only the *exact* href value — double OR single quotes.
				// This prevents a shorter URL being treated as a substring of a
				// longer sibling URL, which was the root cause of corrupted links.
				$pattern = '/href=(["\'])' . preg_quote( $search_url, '/' ) . '\\1/i';

				$content = preg_replace_callback(
					$pattern,
					static function ( array $m ) use ( $to_url, &$count ): string {
						$count++;
						return 'href=' . $m[1] . $to_url . $m[1];
					},
					$content
				);
			}

			$applied += $count;
		}

		if ( $applied > 0 ) {
			// Temporarily unhook handle_save_post so that this content-only update
			// does not corrupt translation metadata: TRID assignments, language
			// timestamps, and the outdated flag must remain exactly as they were.
			remove_action( 'wp_after_insert_post', [ $this->router, 'handle_save_post' ], 10 );
			remove_action( 'wp_after_insert_post', [ $this->router, 'handle_cache_clear' ], 20 );

			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $content,
			] );

			add_action( 'wp_after_insert_post', [ $this->router, 'handle_save_post' ], 10, 2 );
			add_action( 'wp_after_insert_post', [ $this->router, 'handle_cache_clear' ], 20 );
		}

		return [ 'applied' => $applied ];
	}

	// =========================================================
	// AJAX: SCAN (dry-run for a whole language)
	// =========================================================

	public function ajax_scan(): void {
		check_ajax_referer( 'lsflr_link_fixer_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$lang = sanitize_text_field( $_POST['lang'] ?? '' );
		if ( ! $this->router->is_valid_lang( $lang ) ) {
			wp_send_json_error( 'Invalid language' );
		}

		$query = new WP_Query( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => '_lang', 'value' => $lang ] ],
		] );

		$scanned = 0;
		$results = [];
		foreach ( $query->posts as $post_id ) {
			$scanned++;
			$scan = $this->scan_post( (int) $post_id, $lang );
			// Include the post if it has auto-fixable links OR flagged links
			// that need manual review — surface everything that is wrong.
			if ( ! empty( $scan['fixes'] ) || ! empty( $scan['flagged'] ) ) {
				$results[] = $scan;
			}
		}

		wp_send_json_success( [
			'lang'    => $lang,
			'results' => $results,
			'total'   => count( $results ),
			'scanned' => $scanned,
		] );
	}

	// =========================================================
	// AJAX: FIX SINGLE POST
	// =========================================================

	public function ajax_fix_post(): void {
		check_ajax_referer( 'lsflr_link_fixer_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$lang    = sanitize_text_field( $_POST['lang'] ?? '' );

		if ( ! $post_id || ! $this->router->is_valid_lang( $lang ) ) {
			wp_send_json_error( 'Invalid parameters' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'Permission denied for this post' );
		}

		$result = $this->fix_post( $post_id, $lang );
		wp_send_json_success( $result );
	}

	// =========================================================
	// ADMIN UI: BUTTON (in the toolbar above the post list)
	// =========================================================

	/**
	 * Render the "Fix Links" button next to the language filter dropdown.
	 * Only shown when a language filter is currently active.
	 */
	public function render_fix_links_button( string $post_type ): void {
		if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
			return;
		}

		$lang = $this->active_lang_filter();
		if ( ! $lang ) {
			return;
		}

		$nonce = wp_create_nonce( 'lsflr_link_fixer_nonce' );
		printf(
			'<button type="button" class="button lsflr-open-fixer" data-lang="%s" data-nonce="%s">'
			. '🔗 Fix Links (%s)'
			. '</button>',
			esc_attr( $lang ),
			esc_attr( $nonce ),
			strtoupper( $lang )
		);
	}

	// =========================================================
	// ADMIN UI: MODAL OVERLAY + JS
	// =========================================================

	/**
	 * Output the modal markup, styles, and JavaScript.
	 * Only injected on the post/page list screen.
	 */
	public function render_modal(): void {
		global $pagenow;
		if ( $pagenow !== 'edit.php' )       return;
		if ( ! current_user_can( 'edit_posts' ) ) return;
		?>

		<!-- LSFLR Link Fixer modal -->
		<div id="lsflr-fixer-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="lsflr-fixer-title">
			<div id="lsflr-fixer-modal">

				<button id="lsflr-fixer-close" type="button" title="<?php esc_attr_e('Close', 'lingua-forge'); ?>">✕</button>

				<h2 id="lsflr-fixer-title">🔗 <?php esc_html_e('Internal Link Fixer', 'lingua-forge'); ?></h2>

				<p id="lsflr-fixer-status"></p>

				<div id="lsflr-fixer-results"></div>

				<div id="lsflr-fixer-actions" style="display:none">
					<button id="lsflr-fix-all" type="button" class="button button-primary">
						<?php esc_html_e('Fix All', 'lingua-forge'); ?>
					</button>
					<button id="lsflr-recheck" type="button" class="button">
						🔄 <?php esc_html_e('Re-scan', 'lingua-forge'); ?>
					</button>
					<span id="lsflr-fix-progress"></span>
				</div>

			</div>
		</div>

		<style>
		#lsflr-fixer-overlay {
			position: fixed;
			inset: 0;
			background: rgba(0, 0, 0, .55);
			z-index: 100000;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		#lsflr-fixer-modal {
			position: relative;
			background: #fff;
			border-radius: 6px;
			padding: 28px 32px;
			width: min(860px, 92vw);
			max-height: 82vh;
			overflow-y: auto;
			box-shadow: 0 8px 40px rgba(0, 0, 0, .25);
		}
		#lsflr-fixer-modal h2 { margin-top: 0; }

		#lsflr-fixer-close {
			position: absolute;
			top: 12px;
			right: 16px;
			background: none;
			border: none;
			font-size: 20px;
			line-height: 1;
			cursor: pointer;
			color: #666;
			padding: 0;
		}
		#lsflr-fixer-close:hover { color: #000; }

		/* ---- Results table ---- */
		#lsflr-fixer-results table {
			width: 100%;
			border-collapse: collapse;
			margin-top: 16px;
			font-size: 13px;
		}
		#lsflr-fixer-results th {
			background: #f6f7f7;
			text-align: left;
			padding: 8px 10px;
			border-bottom: 2px solid #dcdcde;
		}
		#lsflr-fixer-results td {
			padding: 8px 10px;
			border-bottom: 1px solid #f0f0f1;
			vertical-align: top;
		}
		#lsflr-fixer-results tr.lsflr-fixed  td { background: #edfaee; }
		#lsflr-fixer-results tr.lsflr-failed td { background: #fdf0f0; }

		/* ---- Link-pair display ---- */
		.lsflr-fix-pair {
			font-family: monospace;
			font-size: 11px;
			line-height: 1.6;
			margin-bottom: 4px;
			word-break: break-all;
		}
		.lsflr-fix-pair .lsflr-from { color: #c0392b; }
		.lsflr-fix-pair .lsflr-to   { color: #27ae60; }

		/* ---- Flagged (needs manual review) ---- */
		.lsflr-fix-pair.lsflr-flagged { margin-top: 6px; }
		.lsflr-flagged .lsflr-flag-url    { color: #b45309; }
		.lsflr-flagged .lsflr-flag-reason { color: #92400e; font-family: sans-serif; font-size: 11px; }

		/* ---- Actions bar ---- */
		#lsflr-fixer-actions {
			display: flex;
			align-items: center;
			gap: 14px;
			margin-top: 22px;
			padding-top: 16px;
			border-top: 1px solid #f0f0f1;
		}
		#lsflr-fix-progress { color: #555; font-size: 13px; }

		.lsflr-spinner {
			display: inline-block;
			width: 18px;
			height: 18px;
			border: 2px solid #ccc;
			border-top-color: #2271b1;
			border-radius: 50%;
			animation: lsflr-spin .7s linear infinite;
			vertical-align: middle;
			margin-right: 6px;
		}
		@keyframes lsflr-spin { to { transform: rotate(360deg); } }
		</style>

		<script>
		(function ($) {
			'use strict';

			var overlay    = $('#lsflr-fixer-overlay');
			var status     = $('#lsflr-fixer-status');
			var results    = $('#lsflr-fixer-results');
			var actions    = $('#lsflr-fixer-actions');
			var fixAllBtn  = $('#lsflr-fix-all');
			var recheckBtn = $('#lsflr-recheck');
			var progress   = $('#lsflr-fix-progress');

			var scanData = null;   // last scan response
			var activeLang  = '';
			var activeNonce = '';

			// ---- Open ----
			$(document).on('click', '.lsflr-open-fixer', function () {
				activeLang  = $(this).data('lang');
				activeNonce = $(this).data('nonce');

				// Reset state
				scanData = null;
				results.empty();
				actions.hide();
				fixAllBtn.show().prop('disabled', false);
				progress.text('');

				status.html('<span class="lsflr-spinner"></span> Scanning posts for broken language links…');
				overlay.css('display', 'flex');

				doScan();
			});

			// ---- Close: button or backdrop click ----
			$(document).on('click', '#lsflr-fixer-close', function () {
				overlay.hide();
			});
			overlay.on('click', function (e) {
				if (e.target === this) overlay.hide();
			});
			$(document).on('keydown', function (e) {
				if (e.key === 'Escape') overlay.hide();
			});

			// ---- Re-scan button ----
			recheckBtn.on('click', function () {
				scanData = null;
				results.empty();
				fixAllBtn.prop('disabled', false);
				progress.text('');
				status.html('<span class="lsflr-spinner"></span> Re-scanning…');
				doScan();
			});

			// ---- Scan ----
			function doScan() {
				$.post(ajaxurl, {
					action   : 'lsflr_scan_links',
					lang     : activeLang,
					nonce    : activeNonce,
					_nocache : Date.now()   // prevent browser from returning a cached response
				}, function (resp) {
					if (!resp.success) {
						status.text('Scan failed: ' + (resp.data || 'unknown error'));
						actions.show();   // still show Re-scan so the user can retry
						return;
					}
					scanData = resp.data;
					renderResults(scanData);
				}).fail(function () {
					status.text('Scan request failed. Please try again.');
					actions.show();
				});
			}

			// ---- Render scan results ----
			function renderResults(data) {
				if (!data.results.length) {
					if (!data.scanned) {
						// No posts were found at all — likely a missing _lang meta
						status.html(
							'⚠ No <strong>' + esc(data.lang.toUpperCase()) + '</strong> posts found. '
							+ 'Make sure all translated posts have their Language meta set to <strong>'
							+ esc(data.lang.toUpperCase()) + '</strong> in the Language metabox.'
						);
					} else {
						status.html(
							'✅ No broken links found for <strong>' + esc(data.lang.toUpperCase()) + '</strong>. '
							+ 'Scanned <strong>' + data.scanned + '</strong> post(s) — all internal links are already correct.'
						);
					}
					actions.show();
					fixAllBtn.hide();
					return;
				}

				var totalFixes   = data.results.reduce(function(n, r){ return n + (r.fixes   ? r.fixes.length   : 0); }, 0);
				var totalFlagged = data.results.reduce(function(n, r){ return n + (r.flagged ? r.flagged.length : 0); }, 0);

				var statusParts = [];
				if (totalFixes)   statusParts.push('<strong>' + totalFixes   + '</strong> auto-fixable link(s)');
				if (totalFlagged) statusParts.push('<strong>' + totalFlagged + '</strong> link(s) needing manual review');
				status.html(
					'Found ' + statusParts.join(' and ') + ' across <strong>' + data.total
					+ '</strong> of <strong>' + data.scanned + '</strong> scanned post(s) for <strong>'
					+ esc(data.lang.toUpperCase()) + '</strong>.'
				);

				var reasonLabel = {
					unresolved      : '⚠ URL could not be mapped to a post — check the link target exists',
					no_translation  : '⚠ No ' + data.lang.toUpperCase() + ' translation registered (TRID missing)',
					permalink_error : '⚠ Translation found but permalink could not be generated'
				};

				var html = '<table>'
					+ '<thead><tr>'
					+ '<th>Post</th>'
					+ '<th>Links</th>'
					+ '<th></th>'
					+ '</tr></thead><tbody>';

				data.results.forEach(function (item) {
					var fixes   = item.fixes   || [];
					var flagged = item.flagged || [];
					var linkCount = fixes.length + flagged.length;

					// Auto-fixable pairs (red → green)
					var pairs = fixes.map(function (f) {
						return '<div class="lsflr-fix-pair">'
							+ '<span class="lsflr-from">↳ ' + esc(stripHost(f.from)) + '</span><br>'
							+ '<span class="lsflr-to">→ '   + esc(stripHost(f.to))   + '</span>'
							+ '</div>';
					}).join('');

					// Flagged links (orange — needs manual attention)
					var flags = flagged.map(function (f) {
						var label = reasonLabel[f.reason] || ('⚠ ' + esc(f.reason));
						var detail = f.linked_post_title ? ' <em>(' + esc(f.linked_post_title) + ')</em>' : '';
						return '<div class="lsflr-fix-pair lsflr-flagged">'
							+ '<span class="lsflr-flag-url">⚑ ' + esc(stripHost(f.url)) + '</span><br>'
							+ '<span class="lsflr-flag-reason">' + label + detail + '</span>'
							+ '</div>';
					}).join('');

					var fixBtn = fixes.length
						? '<button type="button" class="button lsflr-fix-single" data-post-id="' + item.post_id + '">Fix</button>'
						: '';

					html += '<tr id="lsflr-row-' + item.post_id + '">'
						+ '<td><strong>' + esc(item.title) + '</strong><br>'
						+ '<small style="color:#888">#' + item.post_id + ' &mdash; ' + linkCount + ' link(s)</small></td>'
						+ '<td>' + pairs + flags + '</td>'
						+ '<td style="white-space:nowrap">' + fixBtn + '</td>'
						+ '</tr>';
				});

				html += '</tbody></table>';
				results.html(html);

				if (totalFixes) {
					fixAllBtn.show();
				} else {
					fixAllBtn.hide();
				}
				actions.show();
			}

			// ---- Fix single post (row button) ----
			$(document).on('click', '.lsflr-fix-single', function () {
				var btn    = $(this);
				var postId = btn.data('post-id');
				btn.prop('disabled', true).text('Fixing…');
				doFix(postId, function (ok, applied) {
					var row = $('#lsflr-row-' + postId);
					if (ok && applied > 0) {
						row.addClass('lsflr-fixed');
						btn.text('✅ Fixed (' + applied + ')');
					} else if (ok && applied === 0) {
						row.addClass('lsflr-failed');
						btn.text('⚠ No changes — re-scan?').prop('disabled', false);
					} else {
						row.addClass('lsflr-failed');
						btn.text('❌ Failed').prop('disabled', false);
					}
				});
			});

			// ---- Fix all (sequential to avoid DB contention) ----
			fixAllBtn.on('click', function () {
				if (!scanData || !scanData.results.length) return;
				fixAllBtn.prop('disabled', true);

				// Only queue posts that actually have auto-fixable links.
				var queue   = scanData.results.filter(function(r){ return r.fixes && r.fixes.length; });
				var done    = 0;
				var skipped = 0;
				var total   = queue.length;

				function next() {
					if (!queue.length) {
						var msg = 'Done — ' + done + ' of ' + total + ' post(s) fixed.';
						if (skipped) msg += ' (' + skipped + ' had no replaceable links — re-scan to investigate)';
						progress.text(msg);
						return;
					}
					var item = queue.shift();
					progress.html('<span class="lsflr-spinner"></span> Fixing ' + (done + skipped + 1) + ' / ' + total + '…');

					var rowBtn = $('#lsflr-row-' + item.post_id + ' .lsflr-fix-single');
					rowBtn.prop('disabled', true).text('Fixing…');

					doFix(item.post_id, function (ok, applied) {
						var row = $('#lsflr-row-' + item.post_id);
						if (ok && applied > 0) {
							done++;
							row.addClass('lsflr-fixed');
							rowBtn.text('✅ Fixed (' + applied + ')');
						} else if (ok && applied === 0) {
							skipped++;
							row.addClass('lsflr-failed');
							rowBtn.text('⚠ No changes');
						} else {
							skipped++;
							row.addClass('lsflr-failed');
							rowBtn.text('❌ Failed');
						}
						next();
					});
				}

				next();
			});

			// ---- AJAX helper — passes (ok, applied) to callback ----
			function doFix(postId, cb) {
				$.post(ajaxurl, {
					action  : 'lsflr_fix_post',
					post_id : postId,
					lang    : activeLang,
					nonce   : activeNonce
				}, function (resp) {
					var applied = (resp.success && resp.data) ? (resp.data.applied || 0) : 0;
					cb(resp.success, applied);
				}).fail(function () {
					cb(false, 0);
				});
			}

			// ---- Utilities ----
			function stripHost(url) {
				return url.replace(/^https?:\/\/[^/]+/, '');
			}

			function esc(s) {
				return String(s)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;');
			}

		}(jQuery));
		</script>

		<?php
	}

	// =========================================================
	// HELPERS
	// =========================================================

	/**
	 * Return the language currently chosen in the admin list filter,
	 * or an empty string when no filter is active.
	 */
	private function active_lang_filter(): string {
		if ( ! empty( $_GET['lf_lang_filter'] ) ) {
			$lang = sanitize_text_field( $_GET['lf_lang_filter'] );
			return $this->router->is_valid_lang( $lang ) ? $lang : '';
		}

		// Fall back to the persisted preference for the current user.
		$lang = (string) get_user_meta( get_current_user_id(), 'lf_lang_filter', true );
		return ( $lang && $this->router->is_valid_lang( $lang ) ) ? $lang : '';
	}
}
