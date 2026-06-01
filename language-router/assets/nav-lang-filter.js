/* global lfNavLang */
/**
 * nav-lang-filter.js
 *
 * Filters /wp/v2/pages REST requests in the Site Editor to show only pages
 * in the current navigation's language.
 *
 * Three-pronged approach:
 *  1. PHP passes lfNavLang.lang synchronously via wp_add_inline_script('before')
 *     so the middleware registers before the first pages request fires.
 *     PHP now resolves lang from four URL formats:
 *       ?p=/wp_navigation/{id}             — navigation post (direct edit)
 *       ?postType=wp_navigation&postId={id} — alternate navigation URL
 *       ?p=/wp_template/{theme}//{slug}    — template (direct edit / canvas)
 *       ?p=/wp_template_part/{theme}//{slug} — template part (direct edit)
 *  2. On SPA navigation (pushState/replaceState), the nav lang is re-resolved:
 *     — navigation post URL  → async REST fetch of nav meta
 *     — template/part URL    → synchronous slug-suffix extraction (no round-trip)
 *     — no lang context      → currentLang cleared
 *     Guards track both the navigation post ID and template slug so only
 *     genuine context switches trigger re-resolution.
 *  3. wp.data.subscribe watches block editor selection.  When the user selects
 *     a core/navigation block (or a child of one) inside a template or template
 *     part, the navigation's language is fetched immediately — before the pages
 *     sidebar panel opens.  Covers the residual gap where a template contains a
 *     navigation whose ref language differs from the template's own _lf_lang.
 *
 * Scope — two navigation types, two behaviours:
 *
 *  "Pages"-type (auto-add) navigations
 *    The block renders a live core/page-list that calls get_pages() server-side.
 *    This filter scopes that list to the navigation's language so the canvas and
 *    the sidebar page picker only show pages in the correct language.
 *
 *  Explicit (edited) navigations
 *    The wp_navigation post contains saved core/navigation-link blocks with
 *    hardcoded page IDs and URLs chosen by the site admin.  We intentionally
 *    do not filter here — the admin's explicit choices are preserved as-is.
 *    get_pages() is never called for these navigations, so the filter has no
 *    effect regardless.
 *
 * PHP inline var: lfNavLang = { lang: 'ca' | '' }
 */
( function () {
	var middlewareAdded = false;
	var currentLang     = '';

	// ── URL helpers ──────────────────────────────────────────────────────────

	function getNavPostId() {
		var params = new URLSearchParams( location.search );

		// Format used by WP 6.x Site Editor: ?p=/wp_navigation/{id}
		var p = params.get( 'p' ) || '';
		var m = p.match( /^\/wp_navigation\/(\d+)$/ );
		if ( m ) return parseInt( m[1], 10 );

		// Alternate format: ?postType=wp_navigation&postId={id}
		if ( params.get( 'postType' ) === 'wp_navigation' ) {
			var id = parseInt( params.get( 'postId' ) || '0', 10 );
			if ( id > 0 ) return id;
		}

		return 0;
	}

	/**
	 * Returns the slug portion of a wp_template or wp_template_part URL, or ''
	 * if the current URL is not pointing at a template/template-part.
	 *
	 * Handles: ?p=/wp_template/{theme}//{slug}
	 *      and ?p=/wp_template_part/{theme}//{slug}
	 * (the primary Site Editor URL format for templates and template parts).
	 */
	function getTemplateSlug() {
		var p = new URLSearchParams( location.search ).get( 'p' ) || '';
		var m = p.match( /^\/wp_template(?:_part)?\/[^/]+\/\/(.+)$/ );
		return m ? m[1] : '';
	}

	function extractLangFromSlug( slug ) {
		if ( ! slug ) return '';
		// Match ISO 639 codes (2–3 chars) with optional region suffix (e.g. ca, de, zh-tw, pt-br).
		// Capped at 3 chars to avoid greedily matching English words like "confirmation".
		var m = slug.match( /-([a-z]{2,3}(?:-[a-z]{2,4})?)$/ );
		return m ? m[1] : '';
	}

	// ── Middleware ───────────────────────────────────────────────────────────

	function ensureMiddleware() {
		if ( middlewareAdded ) return;
		middlewareAdded = true;

		wp.apiFetch.use( function ( options, next ) {
			if (
				currentLang &&
				typeof options.path === 'string' &&
				/\/wp\/v2\/pages(\?|$)/.test( options.path )
			) {
				options.path = options.path.replace( /[?&]lf_lang=[^&]*/g, '' );
				var sep = options.path.indexOf( '?' ) !== -1 ? '&' : '?';
				options.path += sep + 'lf_lang=' + encodeURIComponent( currentLang );
			}
			return next( options );
		} );
	}

	function setLang( lang ) {
		var newLang = lang || '';
		if ( newLang === currentLang ) return; // nothing changed

		currentLang = newLang;
		ensureMiddleware();

		// Invalidate the core-data page cache so the sidebar re-fetches with
		// the updated lf_lang param (or without it, for the primary navigation).
		if ( wp.data && wp.data.dispatch ) {
			wp.data.dispatch( 'core' ).invalidateResolutionForStoreSelector( 'getEntityRecords' );
		}
	}

	// ── Init ─────────────────────────────────────────────────────────────────

	// 1. Synchronous: use the PHP-resolved language (no race condition).
	if ( typeof lfNavLang !== 'undefined' && lfNavLang.lang ) {
		setLang( lfNavLang.lang );
	}

	// 2. Async/sync fallback: resolve lang for SPA navigation or when PHP had no lang.
	//
	// Priority order when the URL has no navigation post ID:
	//   a. Template/template-part slug suffix  — ?p=/wp_template/…//{slug}
	//      Synchronous, no REST round-trip. Reliable because our naming
	//      convention always appends -{lang} (e.g. order-confirmation-ca).
	//   b. PHP-resolved lang (lfNavLang.lang)  — preserved if set.
	//   c. Nothing resolvable               — clear currentLang.
	function maybeInitAsync() {
		var postId = getNavPostId();
		if ( postId ) {
			wp.apiFetch( { path: '/wp/v2/navigation/' + postId + '?_fields=id,slug,meta' } )
				.then( function ( nav ) {
					var lang = ( nav.meta && nav.meta.lf_lang ) || extractLangFromSlug( nav.slug );
					setLang( lang ); // always call — clears currentLang for primary nav
				} )
				.catch( function () {} );
			return;
		}

		// No navigation post in the URL.  Try template / template-part slug.
		var tplSlug = getTemplateSlug();
		if ( tplSlug ) {
			setLang( extractLangFromSlug( tplSlug ) );
			return;
		}

		// Site Editor home, patterns list, etc. — no lang context.
		// Preserve PHP-resolved lang if present; otherwise clear.
		if ( ! ( typeof lfNavLang !== 'undefined' && lfNavLang.lang ) ) {
			currentLang = '';
		}
	}

	// Run on initial load to cover the case where PHP had no lang (e.g. the
	// templates listing page where no post ID is in the URL).
	maybeInitAsync();

	// Re-run on SPA navigation, but only when the navigation post ID OR the
	// template slug actually changes.  The Site Editor fires pushState/replaceState
	// heavily for panel opens, block selections, and canvas transitions that are
	// irrelevant to language resolution.
	var lastNavId   = getNavPostId();
	var lastTplSlug = getTemplateSlug();

	function maybeInitAsyncIfChanged() {
		var id      = getNavPostId();
		var tplSlug = getTemplateSlug();
		if ( id === lastNavId && tplSlug === lastTplSlug ) return;
		lastNavId   = id;
		lastTplSlug = tplSlug;
		maybeInitAsync();
	}

	window.addEventListener( 'popstate', maybeInitAsyncIfChanged );

	( function ( origPush, origReplace ) {
		history.pushState = function () {
			origPush.apply( this, arguments );
			maybeInitAsyncIfChanged();
		};
		history.replaceState = function () {
			origReplace.apply( this, arguments );
			maybeInitAsyncIfChanged();
		};
	} )( history.pushState, history.replaceState );

	// ── Block selection watcher (prong 3) ────────────────────────────────────
	//
	// When editing a template or template part that contains a core/navigation
	// block, the URL never has a navigation post ID, so prongs 1 and 2 cannot
	// resolve the language. We subscribe to block-editor selection changes and
	// detect the nearest core/navigation ancestor (or self) of the selected
	// block. When its ref changes we fetch the navigation's language and call
	// setLang() so the middleware is correct before the pages sidebar opens.

	var lastBlockNavRef = 0;

	function initBlockSelectionWatcher() {
		if ( ! wp.data || ! wp.data.subscribe ) return;
		var blockEditor = wp.data.select( 'core/block-editor' );
		if ( ! blockEditor ) return;

		var lastClientId = null;

		wp.data.subscribe( ( function () {
			return function () {
				var editor = wp.data.select( 'core/block-editor' );
				if ( ! editor ) return;

				// Bail early on the cheap check: selected block clientId unchanged.
				var clientId = editor.getSelectedBlockClientId();
				if ( clientId === lastClientId ) return;
				lastClientId = clientId;

				if ( ! clientId ) return;

				// Walk up the block tree: selected block first, then its ancestors,
				// looking for the nearest core/navigation block.
				var navBlock = null;
				var chain    = [ clientId ].concat( editor.getBlockParents( clientId ) || [] );
				for ( var i = 0; i < chain.length; i++ ) {
					var b = editor.getBlock( chain[ i ] );
					if ( b && b.name === 'core/navigation' ) {
						navBlock = b;
						break;
					}
				}

				if ( ! navBlock ) return;

				var ref = parseInt( ( navBlock.attributes || {} ).ref || 0, 10 );
				// Guard: only act when the ref has changed to avoid redundant fetches.
				if ( ref === lastBlockNavRef ) return;
				lastBlockNavRef = ref;

				if ( ref <= 0 ) return;

				// Fetch nav meta to resolve language.  The /wp/v2/navigation
				// route is NOT intercepted by our lf_lang middleware (it only
				// targets /wp/v2/pages), so this fetch is always unfiltered.
				wp.apiFetch( { path: '/wp/v2/navigation/' + ref + '?_fields=id,slug,meta' } )
					.then( function ( nav ) {
						var lang = ( nav.meta && nav.meta.lf_lang ) || extractLangFromSlug( nav.slug );
						setLang( lang );
					} )
					.catch( function () {} );
			};
		} )() );
	}

	// Defer until the block-editor store is fully registered.
	if ( wp.domReady ) {
		wp.domReady( initBlockSelectionWatcher );
	} else {
		initBlockSelectionWatcher();
	}
} )();
