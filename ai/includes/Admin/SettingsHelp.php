<?php
/**
 * Class LinguaForge\AI\Admin\SettingsHelp
 *
 * Registers WordPress contextual help tabs (the collapsible "Help" tab in the
 * top-right corner of every admin screen) for the Lingua Forge settings page.
 *
 * Uses the native WP_Screen::add_help_tab() API so the help content renders
 * inside the standard WP help panel without any custom UI work.
 *
 * Registered once from SettingsPage::init() via the load-{hook} action.
 */

namespace LinguaForge\AI\Admin;

defined( 'ABSPATH' ) || exit;

class SettingsHelp {

	/**
	 * Base URL of the Lingua Forge online documentation.
	 *
	 * @var string
	 */
	private const DOCS_BASE = 'https://github.com/leotiger/lingua-forge/tree/main/docs/';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'load-toplevel_page_lingua-forge', [ self::class, 'register_help_tabs' ] );
	}

	// ── Tab registration ──────────────────────────────────────────────────────

	public static function register_help_tabs(): void {

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		foreach ( self::tabs() as $tab ) {
			$screen->add_help_tab( $tab );
		}

		$screen->set_help_sidebar( self::sidebar() );
	}

	// ── Content definitions ───────────────────────────────────────────────────

	/**
	 * Returns all help tab definitions.
	 *
	 * Each tab maps to one logical section of the settings page.
	 * Content is intentionally concise — detailed explanations live in docs/.
	 *
	 * @return array<int, array{id: string, title: string, content: string}>
	 */
	private static function tabs(): array {

		return [
			[
				'id'      => 'lf-help-overview',
				'title'   => __( 'Overview', 'lingua-forge' ),
				'content' =>
					'<h3>' . esc_html__( 'Lingua Forge Settings', 'lingua-forge' ) . '</h3>' .
					'<p>' . esc_html__( 'Lingua Forge adds multilingual routing, AI-powered translation, hreflang SEO tags, and a language switcher to your WordPress site.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'Use the tabs across the top of this page to configure each area. Changes to the Router tab take effect immediately; changes to AI keys and model settings apply to the next AI request.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'After changing URL structure settings (path prefix ↔ subdomain), always visit Settings → Permalinks and click Save Changes to flush the rewrite rules.', 'lingua-forge' ) . '</p>',
			],
			[
				'id'      => 'lf-help-router',
				'title'   => __( 'Router', 'lingua-forge' ),
				'content' =>
					'<h3>' . esc_html__( 'Router Tab', 'lingua-forge' ) . '</h3>' .
					'<p>' . esc_html__( 'Primary language: the language your existing content is written in. All translations are produced from this language; changing it does not move or delete any posts.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'URL structure: path-prefix mode (example.com/de/) requires no server changes. Subdomain mode (de.example.com) requires a wildcard DNS record, a wildcard SSL certificate, and a web server configuration change — see the Server Setup guide.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'Active languages: add each language you want to support. Each language gets a URL prefix or subdomain and an hreflang tag in the page &lt;head&gt;.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'FSE localisation: scaffold, translate, and fix links in Full Site Editing templates and template parts directly from this tab.', 'lingua-forge' ) . '</p>',
			],
			[
				'id'      => 'lf-help-api-keys',
				'title'   => __( 'API Keys', 'lingua-forge' ),
				'content' =>
					'<h3>' . esc_html__( 'API Keys Tab', 'lingua-forge' ) . '</h3>' .
					'<p>' . esc_html__( 'Enter your API key for at least one AI provider. Keys are encrypted at rest using AES-256-GCM with a site-specific secret (LINGUAFORGE_SECRET). Do not share database exports without rotating the secret first.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'Supported providers: Anthropic (Claude), OpenAI (GPT), Google (Gemini). Only one provider is active at a time. Select the active provider on the General tab.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'AI features are optional. Language routing, hreflang, the language switcher, and the translation group system all work without a provider configured.', 'lingua-forge' ) . '</p>',
			],
			[
				'id'      => 'lf-help-models',
				'title'   => __( 'Models', 'lingua-forge' ),
				'content' =>
					'<h3>' . esc_html__( 'Models Tab (General)', 'lingua-forge' ) . '</h3>' .
					'<p>' . esc_html__( 'Lingua Forge uses two model tiers per provider:', 'lingua-forge' ) . '</p>' .
					'<ul><li>' . esc_html__( 'Light: used for meta descriptions and excerpt generation — fast, low cost.', 'lingua-forge' ) . '</li>' .
					'<li>' . esc_html__( 'Quality: used for post translation and content generation — higher quality, higher cost.', 'lingua-forge' ) . '</li></ul>' .
					'<p>' . esc_html__( 'Leave a model field blank to use the built-in default for that tier. The placeholder text shows which model will be used.', 'lingua-forge' ) . '</p>',
			],
			[
				'id'      => 'lf-help-translation',
				'title'   => __( 'Translation', 'lingua-forge' ),
				'content' =>
					'<h3>' . esc_html__( 'Translation Limits & Behavior', 'lingua-forge' ) . '</h3>' .
					'<p>' . esc_html__( 'Translation Limits: control which user roles can trigger AI translation, and set a monthly token budget. Exceeding the budget suspends AI calls until the month resets.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'Behavior: choose the AI preset (Literal, Balanced, Creative), add site-wide custom prompt instructions, enable or disable automatic post-save translation, and configure the Translation Memory.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'Translation Memory caches AI results by content hash, language pair, provider, and model. Editing a post invalidates the cache entry so the next translate call gets a fresh result.', 'lingua-forge' ) . '</p>',
			],
			[
				'id'      => 'lf-help-glossary',
				'title'   => __( 'Glossary', 'lingua-forge' ),
				'content' =>
					'<h3>' . esc_html__( 'Glossary Tab', 'lingua-forge' ) . '</h3>' .
					'<p>' . esc_html__( 'Pin terms that must not be translated or must always be rendered in a specific way (e.g. brand names, product names, acronyms). Glossary constraints are injected into every AI translation request for all features: post translation, meta description, excerpt, and FSE template translation.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'Add one term per row. Both the source term and the target rendering are required. The language pair determines which translation jobs the constraint applies to.', 'lingua-forge' ) . '</p>',
			],
			[
				'id'      => 'lf-help-seo',
				'title'   => __( 'SEO', 'lingua-forge' ),
				'content' =>
					'<h3>' . esc_html__( 'SEO Tab', 'lingua-forge' ) . '</h3>' .
					'<p>' . esc_html__( 'Lingua Forge is a complete multilingual SEO solution. No additional SEO plugin or sitemap plugin is required.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'What LF provides: hreflang tags for every configured language, Open Graph and Twitter Card tags with og:locale and og:locale:alternate, Schema.org JSON-LD with inLanguage annotations, and a dedicated multilingual sitemap at /lf-sitemap.xml with xhtml:link alternate entries for all translation groups.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'The sitemap is announced in robots.txt automatically. All major search engines — Google, Bing, Yandex — discover it from robots.txt. No manual submission is required.', 'lingua-forge' ) . '</p>' .
					'<p><strong>' . esc_html__( 'SEO plugins and conflict avoidance:', 'lingua-forge' ) . '</strong> ' .
					esc_html__( 'If you have Yoast SEO, Rank Math, All in One SEO, or SEOPress installed, Lingua Forge detects them and adapts to avoid duplicate output. For hreflang, LF takes over entirely and suppresses the plugin\'s output because those plugins cannot produce correct hreflang for a multilingual routing configuration. For Open Graph, LF adds only og:locale and og:locale:alternate — the multilingual signals the plugin cannot provide — and leaves the base OG set to the plugin. For Schema.org, LF disables its own output entirely to avoid conflicting JSON-LD graphs.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'The SEO → Compatibility tab shows the live detection status and explains exactly what LF is doing for each feature area.', 'lingua-forge' ) . '</p>',
			],
			[
				'id'      => 'lf-help-maintenance',
				'title'   => __( 'Maintenance', 'lingua-forge' ),
				'content' =>
					'<h3>' . esc_html__( 'Maintenance Tab', 'lingua-forge' ) . '</h3>' .
					'<p>' . esc_html__( 'AI Cache: lists cached AI responses with hit counts. Clear the whole cache or a single post\'s entries. Use "Clear" before testing a translation change — cached results are returned even when you change the AI preset or model until the cache entry is evicted.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'Translation Memory: stores the full translated post per content hash. Clear it when you want to force a retranslation of all posts regardless of whether their content changed.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'Language Overrides: manage custom .po/.mo override files for the Lingua Forge UI strings. Use this instead of editing plugin files directly — overrides survive updates.', 'lingua-forge' ) . '</p>' .
					'<p>' . esc_html__( 'Debug: enable the debug log to capture AI prompts and API responses to a local file. Disable after troubleshooting — debug mode writes a file on every AI request.', 'lingua-forge' ) . '</p>',
			],
		];
	}

	/**
	 * Sidebar shown alongside all help tabs — links to the online documentation.
	 */
	private static function sidebar(): string {

		$links = [
			'getting-started.md'      => __( 'Getting started',               'lingua-forge' ),
			'server-subdomain-routing.md' => __( 'Server setup (subdomain)',   'lingua-forge' ),
			'translation-workflow.md' => __( 'Translation workflow',           'lingua-forge' ),
			'language-switcher.md'    => __( 'Language switcher',              'lingua-forge' ),
			'wp-cli.md'               => __( 'WP-CLI reference',               'lingua-forge' ),
			'integration-api.md'      => __( 'Integration API',                'lingua-forge' ),
		];

		$items = '';
		foreach ( $links as $file => $label ) {
			$items .= sprintf(
				'<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
				esc_url( self::DOCS_BASE . $file ),
				esc_html( $label )
			);
		}

		return
			'<p><strong>' . esc_html__( 'Documentation', 'lingua-forge' ) . '</strong></p>' .
			'<ul>' . $items . '</ul>' .
			'<p><a href="' . esc_url( 'https://github.com/leotiger/lingua-forge/issues' ) . '" target="_blank" rel="noopener noreferrer">' .
				esc_html__( 'Report an issue on GitHub', 'lingua-forge' ) .
			'</a></p>';
	}
}
