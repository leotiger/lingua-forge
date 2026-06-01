/**
 * nav-lang-filter.js
 *
 * Filters /wp/v2/pages REST requests in the Site Editor to show only pages
 * in the current navigation's language.
 *
 * Two-pronged approach to avoid race conditions:
 *  1. PHP passes lfNavLang.lang synchronously via wp_add_inline_script('before')
 *     so the middleware registers before the first pages request fires.
 *  2. On SPA navigation (pushState/replaceState), the nav lang is re-resolved
 *     via an async wp.apiFetch call and the middleware is updated.
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

	function extractLangFromSlug( slug ) {
		if ( ! slug ) return '';
		// Match 2+ char codes with optional hyphen suffix (e.g. ca, de, zh-tw, pt-br).
		var m = slug.match( /-([a-z]{2,}(?:-[a-z]{2,})?)$/ );
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

	// 2. Async fallback: fetch nav meta for SPA navigation or when PHP had no lang.
	function maybeInitAsync() {
		var postId = getNavPostId();
		if ( ! postId ) {
			currentLang = '';
			return;
		}

		wp.apiFetch( { path: '/wp/v2/navigation/' + postId + '?_fields=id,slug,meta' } )
			.then( function ( nav ) {
				var lang = ( nav.meta && nav.meta.lf_lang ) || extractLangFromSlug( nav.slug );
				setLang( lang ); // always call, even if empty — clears currentLang for primary nav
			} )
			.catch( function () {} );
	}

	// Run async fetch to cover SPA navigation (also runs on initial load as
	// a safety net if PHP didn't resolve the lang).
	maybeInitAsync();

	// Re-run on SPA navigation, but only when the navigation post ID actually
	// changes. The Site Editor fires pushState/replaceState heavily for internal
	// route changes (panel opens, block selections, canvas transitions) that have
	// nothing to do with switching between navigation posts. Without this guard,
	// maybeInitAsync() would fire on every such event, triggering redundant REST
	// fetches and spurious cache invalidations.
	var lastNavId = getNavPostId();

	function maybeInitAsyncIfChanged() {
		var id = getNavPostId();
		if ( id === lastNavId ) return;
		lastNavId = id;
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
} )();
