<?php
/**
 * Class LinguaForge\Router\LinkFixer
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

namespace LinguaForge\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class LinkFixer {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
		$this->register_hooks();
	}

	// =========================================================
	// HOOKS
	// =========================================================

	private function register_hooks(): void {
		add_action( 'restrict_manage_posts',       [ $this, 'render_fix_links_button' ] );
		add_action( 'admin_enqueue_scripts',       [ $this, 'enqueue_assets' ] );
		add_action( 'admin_footer',                [ $this, 'render_modal' ] );
		add_action( 'wp_ajax_lsflr_scan_links',    [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_lsflr_fix_post',      [ $this, 'ajax_fix_post' ] );
		add_action( 'wp_ajax_lsflr_fix_template',  [ $this, 'ajax_fix_template' ] );
	}

	// =========================================================
	// ADMIN UI: ASSET ENQUEUE
	// =========================================================

	/**
	 * Enqueue modal CSS and JS, plus localized strings.
	 *
	 * Scoped to the post/page list screen (`edit.php`) with editor capability —
	 * same guard set as render_modal() — so the assets don't load on screens
	 * where the modal markup isn't even present.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$base_url = LINGUAFORGE_URL . 'language-router/assets/';
		$version  = defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false;

		wp_enqueue_style(
			'lsflr-link-fixer',
			$base_url . 'link-fixer.css',
			[],
			$version
		);

		wp_enqueue_script(
			'lsflr-link-fixer',
			$base_url . 'link-fixer.js',
			[ 'jquery' ],
			$version,
			true
		);

		wp_localize_script( 'lsflr-link-fixer', 'lsflrLinkFixer', [
			'i18n' => [
				'scanning'           => __( 'Scanning posts for broken language links…', 'lingua-forge' ),
				'rescanning'         => __( 'Re-scanning…', 'lingua-forge' ),
				'scanFailed'         => __( 'Scan failed: ', 'lingua-forge' ),
				'unknownError'       => __( 'unknown error', 'lingua-forge' ),
				'scanRequestFailed'  => __( 'Scan request failed. Please try again.', 'lingua-forge' ),
				'noPostsFound'       => __( '⚠ No <strong>{lang}</strong> posts found. Make sure all translated posts have their Language meta set to <strong>{lang}</strong> in the Language metabox.', 'lingua-forge' ),
				'noBrokenLinks'      => __( '✅ No broken links or template issues found for <strong>{lang}</strong>. Scanned <strong>{scanned}</strong> post(s) — all checks passed.', 'lingua-forge' ),
				'autoFixableCount'   => __( '<strong>{n}</strong> auto-fixable link(s)', 'lingua-forge' ),
				'manualReviewCount'  => __( '<strong>{n}</strong> link(s) needing manual review', 'lingua-forge' ),
				'and'                => __( 'and', 'lingua-forge' ),
				'foundSummary'       => __( 'Found {parts} across <strong>{total}</strong> of <strong>{scanned}</strong> scanned post(s) for <strong>{lang}</strong>.', 'lingua-forge' ),
				'colPost'            => __( 'Post', 'lingua-forge' ),
				'colLinks'           => __( 'Links', 'lingua-forge' ),
				'linksSuffix'        => __( 'link(s)', 'lingua-forge' ),
				'btnFix'             => __( 'Fix', 'lingua-forge' ),
				'btnFixing'          => __( 'Fixing…', 'lingua-forge' ),
				'btnFixed'           => __( '✅ Fixed ({n})', 'lingua-forge' ),
				'btnNoChangesRescan' => __( '⚠ No changes — re-scan?', 'lingua-forge' ),
				'btnNoChanges'       => __( '⚠ No changes', 'lingua-forge' ),
				'btnFailed'          => __( '❌ Failed', 'lingua-forge' ),
				'allDone'            => __( 'Done — {done} of {total} post(s) fixed.', 'lingua-forge' ),
				'skippedSuffix'      => __( '({skipped} had no replaceable links — re-scan to investigate)', 'lingua-forge' ),
				'fixingProgress'     => __( 'Fixing {n} / {total}…', 'lingua-forge' ),
			],
			'reasonLabels' => [
				'unresolved'      => __( 'URL could not be mapped to a post — check the link target exists', 'lingua-forge' ),
				'no_translation'  => __( 'No {lang} translation registered (TRID missing)', 'lingua-forge' ),
				'permalink_error' => __( 'Translation found but permalink could not be generated', 'lingua-forge' ),
			],
			'staleI18n' => [
				'count'    => __( '<strong>{n}</strong> stale path(s)', 'lingua-forge' ),
				'label'    => __( '📍 Stale path (page moved)', 'lingua-forge' ),
				'suffix'   => __( '{n} stale path(s)', 'lingua-forge' ),
			],
			'templateI18n' => [
				'issues'        => __( '<strong>{n}</strong> template issue(s)', 'lingua-forge' ),
				'label'         => __( '📄 Wrong template', 'lingua-forge' ),
				'expected'      => __( 'Expected: {expected}', 'lingua-forge' ),
				'current'       => __( 'Current: {current}', 'lingua-forge' ),
				'notFound'      => __( 'Template "{expected}" does not exist — create it in the Site Editor first.', 'lingua-forge' ),
				'btnFix'        => __( 'Fix Template', 'lingua-forge' ),
				'btnFixing'     => __( 'Fixing…', 'lingua-forge' ),
				'btnFixed'      => __( '✅ Template fixed', 'lingua-forge' ),
				'btnFailed'     => __( '❌ Failed', 'lingua-forge' ),
			],
		] );
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
	 * stale_fixes contains links that already carry the correct language prefix but
	 * whose path is outdated — typically because the target page was moved in the
	 * hierarchy (e.g. /de/aprop/ became /de/casa/aprop/ after reparenting).
	 * The data-id attribute is used as ground truth: if get_permalink(data-id) no
	 * longer matches the stored href, the link is stale and can be auto-corrected.
	 *
	 * @return array{
	 *   post_id:     int,
	 *   title:       string,
	 *   fixes:       list<array{ from: string, to: string, linked_post_id: int, linked_post_title: string, target_post_id: int, from_data_id?: int, to_data_id?: int }>,
	 *   stale_fixes: list<array{ from: string, to: string, linked_post_id: int, linked_post_title: string }>,
	 *   flagged:     list<array{ url: string, reason: string, linked_post_id?: int, linked_post_title?: string }>
	 * }
	 */
	public function scan_post( int $post_id, string $target_lang ): array {
		// Always read fresh from the DB so an immediate Re-scan after a fix
		// doesn't receive stale cached data.
		clean_post_cache( $post_id );
		$this->router->clear_translation_cache( $post_id );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return [
				'post_id'     => 0,
				'title'       => '',
				'fixes'       => [],
				'stale_fixes' => [],
				'flagged'     => [],
			];
		}

		$target_prefix = trailingslashit( home_url() ) . $target_lang . '/';
		$fixes         = [];
		$stale_fixes   = [];
		$flagged       = [];

		foreach ( $this->extract_internal_links( $post->post_content ) as $link ) {
			$url = $link['url'];

			// ── Correct language prefix — verify the path hasn't gone stale ──────
			// A page may have been moved in the hierarchy after the link was saved,
			// changing its permalink without changing its language prefix. We use
			// data-id as ground truth: if the current get_permalink() differs from
			// the stored href (and the new permalink is still in the same language),
			// the link is stale and can be auto-corrected.
			//
			// A second case arises when content was translated by copying source-
			// language blocks: the href is updated to the target language prefix
			// but data-id still references the source-language post (e.g. data-id
			// points to the Catalan post while href shows /pt/).  In that case
			// get_permalink(data-id) returns a non-target URL and the standard
			// stale check silently skips the link.  We detect this by looking up
			// the TRID translation of the source-language data-id post and
			// comparing its current permalink to the stored href.
			if ( str_starts_with( $url, $target_prefix ) ) {
				$linked_id = $this->resolve_to_post_id( $link['id'] );
				if ( $linked_id ) {
					$current_permalink = (string) get_permalink( $linked_id );

					if ( $current_permalink && str_starts_with( $current_permalink, $target_prefix ) ) {
						// data-id resolves to a target-language post — standard stale check.
						if ( rtrim( $current_permalink, '/' ) !== rtrim( $url, '/' ) ) {
							$stale_fixes[] = [
								'from'              => $url,
								'to'                => $current_permalink,
								'linked_post_id'    => $linked_id,
								'linked_post_title' => get_the_title( $linked_id ),
							];
						}
					} else {
						// data-id resolves to a different-language post (source-language
						// data-id left over from a copy-translated block).  Look up the
						// target-language translation via TRID and use its permalink.
						$translations = $this->router->get_translations( $linked_id );
						if ( ! empty( $translations[ $target_lang ] ) ) {
							$target_id        = (int) $translations[ $target_lang ];
							$target_permalink = (string) get_permalink( $target_id );
							if ( $target_permalink
								&& rtrim( $target_permalink, '/' ) !== rtrim( $url, '/' ) ) {
								// href is stale AND data-id points to the wrong language.
								// Record both the href replacement and the data-id correction.
								$stale_fixes[] = [
									'from'              => $url,
									'to'                => $target_permalink,
									'linked_post_id'    => $target_id,
									'linked_post_title' => get_the_title( $target_id ),
									'from_data_id'      => $linked_id,
									'to_data_id'        => $target_id,
								];
							}
						}
					}
				}
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
			'post_id'     => $post_id,
			'title'       => $post->post_title,
			'fixes'       => $fixes,
			'stale_fixes' => $stale_fixes,
			'flagged'     => $flagged,
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
		// Merge cross-language fixes and stale-path fixes — both use the same
		// from→to href-replacement logic.
		$all_fixes = array_merge( $scan['fixes'] ?? [], $scan['stale_fixes'] ?? [] );
		if ( empty( $scan ) || empty( $all_fixes ) ) {
			return [ 'applied' => 0 ];
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return [ 'applied' => 0 ];
		}

		$content = $post->post_content;
		$applied = 0;

		$home = untrailingslashit( home_url() ); // e.g. https://example.com

		foreach ( $all_fixes as $fix ) {
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

		// ── Fix data-id attributes left pointing to the wrong-language post ───
		// When stale-fix detection found that data-id references a source-language
		// post instead of the target-language equivalent, correct the attribute in
		// the (already href-updated) content so future scans work reliably.
		foreach ( $all_fixes as $fix ) {
			if ( empty( $fix['from_data_id'] ) || empty( $fix['to_data_id'] ) ) {
				continue;
			}
			$content = $this->fix_data_id_attr(
				$content,
				$fix['to'],
				(int) $fix['from_data_id'],
				(int) $fix['to_data_id']
			);
		}

		if ( $applied > 0 ) {
			// Temporarily unhook handle_save_post so that this content-only update
			// does not corrupt translation metadata: TRID assignments, language
			// timestamps, and the outdated flag must remain exactly as they were.
			// After §2.2 Router split: handle_save_post is on Sync, handle_cache_clear on TridGroup.
			remove_action( 'wp_after_insert_post', [ $this->router->sync,       'handle_save_post'  ], 10 );
			remove_action( 'wp_after_insert_post', [ $this->router->trid_group, 'handle_cache_clear' ], 20 );

			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $content,
			] );

			add_action( 'wp_after_insert_post', [ $this->router->sync,       'handle_save_post'  ], 10, 2 );
			add_action( 'wp_after_insert_post', [ $this->router->trid_group, 'handle_cache_clear' ], 20 );
		}

		return [ 'applied' => $applied ];
	}

	// =========================================================
	// HELPERS: CONTENT PATCHING
	// =========================================================

	/**
	 * Replace data-id="$old_id" with data-id="$new_id" inside every <a> opening
	 * tag whose href attribute matches $href exactly (double or single quotes).
	 *
	 * Using href as a scope guard means we never touch unrelated links that
	 * happen to carry the same data-id value (e.g. an image or block anchor).
	 *
	 * Called by fix_post() after the href replacement loop, so $href is the
	 * already-updated (new) permalink, not the old one.
	 */
	private function fix_data_id_attr(
		string $content,
		string $href,
		int    $old_id,
		int    $new_id
	): string {
		$href_pattern   = preg_quote( $href, '/' );
		$old_id_pattern = preg_quote( (string) $old_id, '/' );

		return preg_replace_callback(
			'/<a\s([^>]*)>/i',
			static function ( array $m ) use ( $href_pattern, $old_id_pattern, $new_id ): string {
				$attrs = $m[1];

				// Only touch tags that carry the specific updated href.
				if ( ! preg_match( '/\bhref=["\']' . $href_pattern . '["\']/', $attrs ) ) {
					return $m[0];
				}

				// Replace the data-id attribute value (double quotes only — Gutenberg
				// always serialises attribute values in double quotes).
				$new_attrs = preg_replace(
					'/\bdata-id="' . $old_id_pattern . '"/',
					'data-id="' . $new_id . '"',
					$attrs
				);

				return '<a ' . $new_attrs . '>';
			},
			$content
		);
	}

	// =========================================================
	// CORE: TEMPLATE CHECK
	// =========================================================

	/**
	 * Check whether a post is using the correct language-specific FSE template.
	 *
	 * Returns null when:
	 *   - The post type has no expected language template (neither page nor post).
	 *   - The current template already matches the expected one.
	 *
	 * Returns an array when a mismatch is found:
	 *   'expected' => string  Slug of the correct template (e.g. 'page-de').
	 *   'current'  => string  Slug currently stored in _wp_page_template ('default' when empty).
	 *   'can_fix'  => bool    True when the expected template exists in the wp_template CPT;
	 *                         false means the editor must create the template first.
	 *
	 * @return array{ expected: string, current: string, can_fix: bool }|null
	 */
	private function check_template( int $post_id, string $lang ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$expected = $this->router->resolve_template_for_lang( $post, $lang );
		if ( ! $expected ) {
			return null; // not a page or post — no language template defined
		}

		$current = (string) get_post_meta( $post_id, '_wp_page_template', true );
		if ( ! $current ) {
			$current = 'default';
		}

		if ( $current === $expected ) {
			return null; // already correct
		}

		return [
			'expected' => $expected,
			'current'  => $current,
			'can_fix'  => $this->router->template_exists( $expected ),
		];
	}

	// =========================================================
	// AJAX: FIX TEMPLATE
	// =========================================================

	/**
	 * Apply the correct language-specific FSE template to a single post.
	 *
	 * Only succeeds when the expected template slug (e.g. 'page-de') already
	 * exists in the wp_template CPT.  If the template does not exist yet the
	 * editor must create it in the Site Editor first.
	 */
	public function ajax_fix_template(): void {
		check_ajax_referer( 'lsflr_link_fixer_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() sanitizes to a non-negative integer.
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$lang    = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );

		if ( ! $post_id || ! $this->router->is_valid_lang( $lang ) ) {
			wp_send_json_error( 'Invalid parameters' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'Permission denied for this post' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( 'Post not found' );
		}

		$expected = $this->router->resolve_template_for_lang( $post, $lang );
		if ( ! $expected ) {
			wp_send_json_error( 'No language-specific template defined for this post type' );
		}

		if ( ! $this->router->template_exists( $expected ) ) {
			wp_send_json_error( 'Template "' . $expected . '" does not exist — create it in the Site Editor first' );
		}

		update_post_meta( $post_id, '_wp_page_template', $expected );
		clean_post_cache( $post_id );

		wp_send_json_success( [ 'template' => $expected ] );
	}

	// =========================================================
	// AJAX: SCAN (dry-run for a whole language)
	// =========================================================

	public function ajax_scan(): void {
		check_ajax_referer( 'lsflr_link_fixer_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$lang = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );
		if ( ! $this->router->is_valid_lang( $lang ) ) {
			wp_send_json_error( 'Invalid language' );
		}

		// Admin-only path: gated by current_user_can('edit_posts') and only fires when an admin clicks "Scan Links". Bounded to post/page + published. The slow-query warning is documented; this isn't a hot frontend path. (phpcs:ignore directive on the meta_query line itself, where the sniff actually fires.)
		$query = new \WP_Query( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- see comment above the $query assignment.
			'meta_query'     => [ [ 'key' => '_lang', 'value' => $lang ] ],
		] );

		$scanned = 0;
		$results = [];
		foreach ( $query->posts as $post_id ) {
			$scanned++;
			$scan                   = $this->scan_post( (int) $post_id, $lang );
			$scan['template_issue'] = $this->check_template( (int) $post_id, $lang );

			// Include the post when it has auto-fixable cross-language links,
			// stale same-language links (hierarchy change), flagged links that
			// need manual review, OR a template mismatch — surface everything wrong.
			if ( ! empty( $scan['fixes'] ) || ! empty( $scan['stale_fixes'] ) || ! empty( $scan['flagged'] ) || ! empty( $scan['template_issue'] ) ) {
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() sanitizes to a non-negative integer; no further sanitization is meaningful for a numeric ID.
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$lang    = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );

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
			esc_html( strtoupper( $lang ) )
		);
	}

	// =========================================================
	// ADMIN UI: MODAL OVERLAY + JS
	// =========================================================

	/**
	 * Output the modal markup.
	 *
	 * Styles and JavaScript are enqueued separately via enqueue_assets() and
	 * live in language-router/assets/link-fixer.{css,js}. Only injected on
	 * the post/page list screen.
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a list-filter URL parameter to determine the active language column filter; no data is modified.
		if ( ! empty( $_GET['lf_lang_filter'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only list-filter parameter; no data is modified.
			$lang = sanitize_text_field( wp_unslash( $_GET['lf_lang_filter'] ) );
			return $this->router->is_valid_lang( $lang ) ? $lang : '';
		}

		// Fall back to the persisted preference for the current user.
		$lang = (string) get_user_meta( get_current_user_id(), 'lf_lang_filter', true );
		return ( $lang && $this->router->is_valid_lang( $lang ) ) ? $lang : '';
	}
}

