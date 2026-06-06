<?php
/**
 * Class LinguaForge\Router\Seo\SocialShare
 *
 * Extends the WordPress Core Social Icons block with dynamic share actions.
 *
 * When enabled, this class rewrites Social Icon block link URLs that carry the
 * special "share:" protocol so they resolve to actual share URLs or trigger
 * JavaScript-powered actions at click time.
 *
 * Supported services (external URL redirect):
 *   share:facebook   — Facebook sharer
 *   share:x          — X / Twitter intent
 *   share:linkedin   — LinkedIn share
 *   share:whatsapp   — WhatsApp send
 *   share:telegram   — Telegram share
 *   share:email      — mailto: link
 *   share:reddit     — Reddit submit
 *   share:pinterest  — Pinterest pin
 *   share:mastodon   — Mastodon share (best-effort; relies on instance picker)
 *
 * JavaScript-powered actions (resolved at click time):
 *   share:copy       — copies the current URL to the clipboard (with toast feedback)
 *   share:native     — opens the browser's native Web Share API sheet (mobile)
 *   share:auto       — native share when available, clipboard copy as fallback
 *
 * Usage:
 *   In the Social Icons block editor, set any icon's link URL to one of the
 *   "share:" values above.  On the frontend the value is rewritten to a real
 *   share URL or a data-lf-share attribute that the companion JS handles.
 *
 * Deference:
 *   If the lf-social-share mu-plugin is active this class registers no hooks
 *   and the mu-plugin handles everything.
 *
 * Filter:
 *   linguaforge_social_share_url  string  Override the resolved share URL for
 *                                         a given service. Receives (url, service).
 *
 * @package LinguaForge\Router\Seo
 * @since   2.2.0
 */

namespace LinguaForge\Router\Seo;

if ( ! defined( 'ABSPATH' ) ) exit;

class SocialShare {

	public function __construct() {}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {

		if ( ! get_option( 'linguaforge_seo_social_share_enabled', false ) ) {
			return;
		}

		// Defer to the lf-social-share mu-plugin when it is active — no conflicts.
		if ( $this->is_mu_plugin_active() ) {
			return;
		}

		add_filter( 'render_block_core/social-link', [ $this, 'rewrite_share_url' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_script' ] );
	}

	// =========================================================
	// DETECTION (public — used by SocialSharePanel)
	// =========================================================

	/**
	 * Whether the lf-social-share mu-plugin is already loaded.
	 */
	public function is_mu_plugin_active(): bool {
		return function_exists( 'lf_social_share_get_current_url' );
	}

	// =========================================================
	// BLOCK FILTER
	// =========================================================

	/**
	 * Rewrite Social Icon block links that carry a "share:" URL.
	 *
	 * External services get a fully-resolved share URL with the current page
	 * URL and title encoded as query parameters.
	 *
	 * JavaScript-powered actions get href="#" and a data-lf-share attribute
	 * that the companion social-share.js reads on click.
	 *
	 * @param  string $block_content  Rendered block HTML.
	 * @param  array  $block          Block data including attrs.
	 * @return string                 Modified (or original) block HTML.
	 */
	public function rewrite_share_url( string $block_content, array $block ): string {

		if ( empty( $block['attrs']['url'] ) ) {
			return $block_content;
		}

		$url = trim( (string) $block['attrs']['url'] );

		if ( strpos( $url, 'share:' ) !== 0 ) {
			return $block_content;
		}

		$action = strtolower( substr( $url, 6 ) );

		// ── JavaScript-powered actions ─────────────────────────────────────────
		if ( in_array( $action, [ 'copy', 'native', 'auto' ], true ) ) {

			$block_content = preg_replace(
				'/href="[^"]*"/',
				'href="#"',
				$block_content,
				1
			);

			$block_content = preg_replace(
				'/<a /',
				'<a data-lf-share="' . esc_attr( $action ) . '" ',
				$block_content,
				1
			);

			return $block_content;
		}

		// ── External share URLs ────────────────────────────────────────────────
		$share_url = $this->build_share_url( $action );

		if ( '' === $share_url ) {
			return $block_content;
		}

		$replacement = sprintf(
			'href="%s" target="_blank" rel="noopener noreferrer"',
			esc_url( $share_url )
		);

		return preg_replace(
			'/href="[^"]*"/',
			$replacement,
			$block_content,
			1
		);
	}

	// =========================================================
	// FRONTEND SCRIPT
	// =========================================================

	/**
	 * Enqueue the social-share.js companion script on the frontend.
	 *
	 * Only enqueued when the feature is enabled and at least one Social Icons
	 * block on the page uses a JS-powered share action — but detecting that at
	 * enqueue time is expensive, so we enqueue unconditionally and let the
	 * script self-exit when no [data-lf-share] elements exist.
	 */
	public function enqueue_frontend_script(): void {

		if ( is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'linguaforge-social-share',
			LINGUAFORGE_URL . 'language-router/assets/social-share.js',
			[],
			defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false,
			true
		);

		wp_localize_script(
			'linguaforge-social-share',
			'lfSocialShare',
			[
				'strings' => [
					'copied' => __( 'Link copied', 'lingua-forge' ),
					'failed' => __( 'Copy failed — please copy the URL manually.', 'lingua-forge' ),
				],
			]
		);
	}

	// =========================================================
	// SHARE URL BUILDER
	// =========================================================

	/**
	 * Build a share URL for a supported external service.
	 *
	 * @param  string $service  Service slug (facebook, x, linkedin, …).
	 * @return string           Resolved share URL, or '' for unknown services.
	 */
	private function build_share_url( string $service ): string {

		$current_url = rawurlencode( $this->get_current_url() );
		$title       = rawurlencode( (string) wp_get_document_title() );

		switch ( $service ) {

			case 'facebook':
				$url = "https://www.facebook.com/sharer/sharer.php?u={$current_url}";
				break;

			case 'x':
			case 'twitter': // legacy alias
				$url = "https://twitter.com/intent/tweet?url={$current_url}&text={$title}";
				break;

			case 'linkedin':
				$url = "https://www.linkedin.com/sharing/share-offsite/?url={$current_url}";
				break;

			case 'whatsapp':
				$url = "https://api.whatsapp.com/send?text={$current_url}";
				break;

			case 'telegram':
				$url = "https://t.me/share/url?url={$current_url}";
				break;

			case 'email':
				$url = "mailto:?subject={$title}&body={$current_url}";
				break;

			case 'reddit':
				$url = "https://www.reddit.com/submit?url={$current_url}&title={$title}";
				break;

			case 'pinterest':
				$url = "https://pinterest.com/pin/create/button/?url={$current_url}&description={$title}";
				break;

			case 'mastodon':
				// Mastodon has no universal share endpoint — use the share page
				// on mastodon.social as a common entry point that lets users
				// enter their own instance.
				$url = "https://mastodon.social/share?text={$title}%20{$current_url}";
				break;

			default:
				$url = '';
		}

		return (string) apply_filters( 'linguaforge_social_share_url', $url, $service );
	}

	/**
	 * Resolve the current page URL.
	 *
	 * Uses the canonical permalink for singular pages; reconstructs from
	 * SERVER vars for archives and other non-singular contexts.
	 */
	private function get_current_url(): string {

		if ( is_singular() ) {
			return (string) get_permalink();
		}

		$scheme = is_ssl() ? 'https' : 'http';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is extracted via sanitize_text_field + wp_parse_url (host component only).
		$raw_host = isset( $_SERVER['HTTP_HOST'] )
			? wp_parse_url( 'http://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ), PHP_URL_HOST )
			: wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $raw_host ) ? $raw_host : (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$raw_path = isset( $_SERVER['REQUEST_URI'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path extraction via wp_parse_url.
			? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
			: null;
		$path = is_string( $raw_path ) ? $raw_path : '/';

		return $scheme . '://' . $host . $path;
	}
}
