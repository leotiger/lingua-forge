<?php
/**
 * Class LinguaForge\Router\Seo\IndexNowManager
 *
 * Implements the IndexNow protocol (https://www.indexnow.org/) to notify
 * Bing, Yandex, and other participating engines when LF-managed URLs are
 * published or updated — replacing the deprecated Bing/Yandex sitemap-ping
 * endpoints that were shut down.
 *
 * ── How IndexNow works ────────────────────────────────────────────────────
 * 1. The site generates a random key once and stores it in an option.
 * 2. The key must be verifiable: LF serves a plain-text file at
 *    /<key>.txt containing exactly the key string (+ newline).
 * 3. Any URL update is pushed to https://api.indexnow.org/indexnow via
 *    a JSON POST containing the host, the key, the key-file URL, and the
 *    list of updated URLs.  All IndexNow-enabled engines pick it up from
 *    the shared endpoint.
 *
 * ── Automatic submission ──────────────────────────────────────────────────
 * On wp_after_insert_post (fires for both classic admin saves and REST
 * block-editor saves), LF submits the updated post's URL and all its
 * translation alternates so every language version is indexed promptly.
 *
 * ── Manual submission ─────────────────────────────────────────────────────
 * The Sitemap panel exposes a "Submit all URLs via IndexNow" button that
 * calls submit_all() to push every published, LF-managed URL in one batch.
 *
 * ── Options ───────────────────────────────────────────────────────────────
 *   linguaforge_indexnow_key  string  The 32-char hex verification key.
 *
 * @package LinguaForge\Router\Seo
 * @since   2.3.0
 */

namespace LinguaForge\Router\Seo;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class IndexNowManager {

	/** IndexNow shared submission endpoint (all participating engines). */
	private const SUBMIT_URL = 'https://api.indexnow.org/indexnow';

	/** Maximum URLs per batch request. */
	private const BATCH_SIZE = 10000;

	/** Option name for the stored verification key. */
	private const KEY_OPTION = 'linguaforge_indexnow_key';

	/**
	 * Cron hook fired to submit a post's URLs asynchronously.
	 *
	 * The save handler schedules a single event on this hook so the outbound
	 * IndexNow HTTP POST never runs inside the editor save / REST response.
	 */
	private const CRON_HOOK = 'linguaforge_indexnow_submit';

	/**
	 * Delay before the scheduled submit fires (seconds).  A short delay lets a
	 * burst of related saves (e.g. "Translate missing" creating several
	 * siblings) collapse into a single submission, since the URL set is
	 * re-collected at run time.
	 */
	private const SUBMIT_DELAY = MINUTE_IN_SECONDS;

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		// Serve the key-verification file on the front end.
		add_action( 'template_redirect', [ $this, 'maybe_serve_key_file' ], 1 );

		// Auto-submit when a translated post is published or updated.  The save
		// handler only schedules a cron event; the actual HTTP POST runs later
		// on the CRON_HOOK so it never blocks the save request.
		add_action( 'wp_after_insert_post', [ $this, 'on_post_saved' ], 20, 2 );
		add_action( self::CRON_HOOK, [ $this, 'run_scheduled_submit' ], 10, 1 );
	}

	// =========================================================
	// KEY FILE SERVING
	// =========================================================

	/**
	 * Serve /<key>.txt when the request matches the key-file path.
	 *
	 * Runs on every front-end request (template_redirect), so it reads the key
	 * with read_key() — the read-only accessor that never writes an option.
	 * The key is only ever generated in admin / cron / submission contexts
	 * (Sitemap panel render, run_scheduled_submit, manual submit), never as a
	 * side effect of an anonymous GET.  When no key exists yet there is also
	 * nothing for a search engine to verify (no submission has carried a key
	 * URL), so returning early is correct.
	 */
	public function maybe_serve_key_file(): void {

		$key = $this->read_key();
		if ( '' === $key ) {
			return;
		}

		$request_path = isset( $_SERVER['REQUEST_URI'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
			: '/';

		$home_path    = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$expected     = $home_path . '/' . $key . '.txt';

		if ( rtrim( $request_path, '/' ) !== $expected ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hex key is alphanumeric only.
		echo $key . "\n";
		exit;
	}

	// =========================================================
	// AUTO-SUBMIT ON SAVE
	// =========================================================

	/**
	 * Schedule an asynchronous IndexNow submission for the saved post.
	 *
	 * Fires on wp_after_insert_post so it covers both the classic meta-box
	 * save and the REST block-editor save path.  This method does no network
	 * I/O — it only queues a single cron event.  The blocking HTTP POST runs
	 * later in run_scheduled_submit() so the editor save / REST response is
	 * never delayed by IndexNow.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public function on_post_saved( int $post_id, \WP_Post $post ): void {

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Only act on LF-managed posts (have a TRID).
		if ( '' === $this->router->trid_group->get_trid( $post_id ) ) {
			return;
		}

		$this->schedule_submit( $post_id );
	}

	/**
	 * Queue a single cron event to submit this post's URLs.
	 *
	 * Debounce: wp_schedule_single_event() (and the wp_next_scheduled() guard)
	 * ignore a duplicate event with the same hook + args already queued, so
	 * rapid re-saves of the same post collapse into one submission.  The URL
	 * set is deliberately NOT passed as the cron argument — it is re-collected
	 * at run time so the submission reflects the final state of the translation
	 * group (and keeps the cron option row small).
	 *
	 * @param int $post_id
	 */
	private function schedule_submit( int $post_id ): void {

		if ( wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::SUBMIT_DELAY, self::CRON_HOOK, [ $post_id ] );
	}

	/**
	 * Cron callback: collect the post's current URLs and submit them.
	 *
	 * Runs in a separate (cron) request, after the save that scheduled it has
	 * already returned, so the blocking wp_remote_post() never affects the
	 * editor experience.  Guards against a post that was unpublished or deleted
	 * between scheduling and execution (collect_post_urls() returns only
	 * currently-published siblings).
	 *
	 * @param int $post_id
	 */
	public function run_scheduled_submit( int $post_id ): void {

		$urls = $this->collect_post_urls( $post_id );

		if ( empty( $urls ) ) {
			return;
		}

		$this->submit_urls( $urls );
	}

	// =========================================================
	// URL COLLECTION
	// =========================================================

	/**
	 * Collect the URL of a post and all its translation alternates.
	 *
	 * @param  int $post_id
	 * @return string[]
	 */
	public function collect_post_urls( int $post_id ): array {

		// get_translations() returns all non-auto-draft siblings; filter to
		// published only so we never submit a draft or pending URL to IndexNow.
		$translations = $this->router->trid_group->get_translations( $post_id );

		if ( empty( $translations ) ) {
			// Non-LF post or first save before TRID group is formed — submit self.
			$permalink = get_permalink( $post_id );
			return $permalink ? [ $permalink ] : [];
		}

		$urls = [];
		foreach ( $translations as $id ) {
			$sibling = get_post( (int) $id );
			if ( ! $sibling || $sibling->post_status !== 'publish' ) {
				continue;
			}
			$url = get_permalink( $sibling->ID );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Collect all published, LF-managed post URLs from the database.
	 *
	 * @return string[]
	 */
	public function collect_all_urls(): array {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off batch submit; result set can be large; caching is not appropriate here.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_lf_trid'
				 WHERE p.post_status = %s
				   AND p.post_type NOT IN (
				       'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
				       'oembed_cache', 'user_request', 'wp_block',
				       'wp_template', 'wp_template_part', 'wp_navigation',
				       'wp_global_styles', 'wp_font_face', 'wp_font_family',
				       'shop_order', 'shop_coupon', 'shop_subscription', 'shop_order_refund'
				   )",
				'publish'
			)
		);

		$urls = [];
		foreach ( $ids as $id ) {
			$url = get_permalink( (int) $id );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	// =========================================================
	// SUBMISSION
	// =========================================================

	/**
	 * Submit a list of URLs to IndexNow (chunked into BATCH_SIZE batches).
	 *
	 * @param  string[] $urls
	 * @return string  'ok' if all batches succeed, 'error' otherwise.
	 */
	public function submit_urls( array $urls ): string {

		$key          = $this->get_key();
		$key_location = $this->key_file_url();
		$host         = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' === $key || empty( $urls ) ) {
			return 'error';
		}

		$chunks = array_chunk( $urls, self::BATCH_SIZE );
		$all_ok = true;

		foreach ( $chunks as $chunk ) {

			$body = wp_json_encode( [
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => $key_location,
				'urlList'     => array_values( $chunk ),
			] );

			$response = wp_remote_post(
				self::SUBMIT_URL,
				[
					'timeout'    => 15,
					'headers'    => [
						'Content-Type' => 'application/json; charset=utf-8',
					],
					'body'       => $body,
					'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				]
			);

			if ( is_wp_error( $response ) ) {
				$all_ok = false;
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// IndexNow returns 200 or 202 on success.
			if ( ! in_array( $code, [ 200, 202 ], true ) ) {
				$all_ok = false;
			}
		}

		return $all_ok ? 'ok' : 'error';
	}

	/**
	 * Convenience: collect and submit all LF-managed URLs.
	 *
	 * @return string  'ok' / 'error'
	 */
	public function submit_all(): string {

		$urls = $this->collect_all_urls();

		if ( empty( $urls ) ) {
			return 'empty';
		}

		return $this->submit_urls( $urls );
	}

	// =========================================================
	// KEY MANAGEMENT
	// =========================================================

	/**
	 * Return the stored verification key WITHOUT generating one.
	 *
	 * Read-only — used by the front-end key-file serving path so an anonymous
	 * GET can never trigger an option write (and two cold GETs can never race to
	 * generate competing keys).  Returns '' when no key has been generated yet.
	 *
	 * @return string  32-char lowercase hex string, or '' when none is stored.
	 */
	public function read_key(): string {
		return (string) get_option( self::KEY_OPTION, '' );
	}

	/**
	 * Return the stored verification key, generating one if absent.
	 *
	 * This is the get-or-create accessor used by write-appropriate contexts:
	 * the admin Sitemap panel render, the submission path (cron / manual), and
	 * key_file_url().  The front-end serving path uses read_key() instead so it
	 * never writes — see maybe_serve_key_file().
	 *
	 * @return string  32-char lowercase hex string, or '' on failure.
	 */
	public function get_key(): string {

		$key = $this->read_key();

		if ( '' !== $key ) {
			return $key;
		}

		return $this->generate_key();
	}

	/**
	 * Generate and persist a new verification key.
	 *
	 * @return string  The new key, or '' on failure.
	 */
	public function generate_key(): string {

		try {
			$bytes = random_bytes( 16 );
		} catch ( \Exception $e ) {
			return '';
		}

		$key = bin2hex( $bytes ); // 32 lowercase hex chars.
		update_option( self::KEY_OPTION, $key, false );

		return $key;
	}

	/**
	 * Regenerate the key (rotate).  The old key-file URL stops being valid
	 * once the new key is saved — the new file is served immediately.
	 *
	 * @return string  The new key.
	 */
	public function rotate_key(): string {
		delete_option( self::KEY_OPTION );
		return $this->generate_key();
	}

	/**
	 * Absolute URL to the key-verification file (e.g. https://example.com/<key>.txt).
	 *
	 * @return string
	 */
	public function key_file_url(): string {
		return home_url( '/' . $this->get_key() . '.txt' );
	}

	/**
	 * Check whether the key file is publicly reachable.
	 *
	 * Makes a HEAD request to the key-file URL; returns true if the response
	 * body contains the expected key string.
	 *
	 * @return bool
	 */
	public function key_file_reachable(): bool {

		$key = $this->get_key();
		if ( '' === $key ) {
			return false;
		}

		$response = wp_remote_get(
			$this->key_file_url(),
			[
				'timeout'    => 5,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		return str_contains( wp_remote_retrieve_body( $response ), $key );
	}
}
