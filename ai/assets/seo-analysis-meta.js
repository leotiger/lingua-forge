/**
 * Lingua Forge — SEO Analysis meta box (classic editor / WooCommerce products)
 *
 * Rendered as a standard WordPress meta box on post types that use the classic
 * editor (e.g. WooCommerce products).  Mirrors the per-row profile select
 * behaviour in seo-analysis.js but scoped to the single current post.
 *
 * Globals (lfSeoAnalysisMeta, injected via wp_localize_script):
 *   ajaxUrl  — admin-ajax.php URL
 *   nonce    — wp_create_nonce('linguaforge_seo_analyze')
 *   postId   — current post ID (0 on new-post screen)
 *   postLang — LF language code for the current post, or null
 *   strings  — translatable UI strings
 */
( function () {
	'use strict';

	var cfg     = window.lfSeoAnalysisMeta || {};
	var ajaxUrl = cfg.ajaxUrl   || '';
	var nonce   = cfg.nonce     || '';
	var postId  = cfg.postId    || 0;
	var lang    = cfg.postLang  || '';
	var aiOn    = !! cfg.aiEnabled;
	var s       = cfg.strings   || {};

	// Track the last analysed post + profile so AI button knows what to pass.
	var lastPid     = 0;
	var lastProfile = '';

	var profileSelect = document.getElementById( 'lf-seo-meta-profile' );
	var spinner       = document.getElementById( 'lf-seo-meta-spinner' );
	var resultsDiv    = document.getElementById( 'lf-seo-meta-results' );

	if ( ! profileSelect || ! resultsDiv ) { return; }

	// ── Wire profile selector ─────────────────────────────────────────────────

	profileSelect.addEventListener( 'change', function () {
		var profile = profileSelect.value;
		if ( ! profile ) { return; }

		// Resolve post ID at run-time in case we are on a new-post screen.
		var pid = postId || resolveNewPostId();
		if ( ! pid ) {
			renderError( 'Please save the post before running SEO Analysis.' );
			return;
		}

		lastPid     = pid;
		lastProfile = profile;
		runAnalysis( pid, lang, profile );
	} );

	// ── Analysis request ──────────────────────────────────────────────────────

	function runAnalysis( pid, postLang, profile ) {

		profileSelect.disabled = true;
		if ( spinner ) { spinner.style.display = 'inline-block'; }
		resultsDiv.innerHTML = '';

		var body = new URLSearchParams( {
			action:  'linguaforge_seo_analyze',
			nonce:   nonce,
			post_id: pid,
			lang:    postLang,
			profile: profile,
		} );

		fetch( ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    body.toString(),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json.success ) {
					renderResults( json.data );
				} else {
					renderError( ( json.data && json.data.message ) || ( s.requestFailed || 'Analysis failed.' ) );
				}
			} )
			.catch( function () {
				renderError( s.requestFailed || 'Request failed. Please try again.' );
			} )
			.finally( function () {
				profileSelect.disabled = false;
				if ( spinner ) { spinner.style.display = 'none'; }
			} );
	}

	// ── Renderers ─────────────────────────────────────────────────────────────

	function renderError( msg ) {
		resultsDiv.innerHTML =
			'<div class="notice notice-error inline"><p>' + escHtml( msg ) + '</p></div>';
	}

	function renderResults( d ) {

		var score      = d.score   || 0;
		var metrics    = d.metrics || {};
		var scoreColor = score >= 80 ? '#00a32a' : score >= 50 ? '#dba617' : '#d63638';

		var rows = [
			metricRow( s.titleLabel   || 'Title',            metrics.title            ),
			metricRow( s.metaDesc     || 'Meta description', metrics.meta_description ),
			metricRow( s.wordCount    || 'Word count',       metrics.word_count       ),
			metricRow( s.readTime     || 'Reading time',     metrics.reading_time     ),
			metricRow( s.headings     || 'Headings',         metrics.headings         ),
			metricRow( s.images       || 'Images',           metrics.images           ),
			metricRow( s.links        || 'Links',            metrics.links            ),
		].join( '' );

		var sourceNote = d.used_source
			? '<div class="notice notice-warning inline" style="margin-bottom:1em;"><p>' +
			  escHtml( s.usedSource || 'No translation found — analyzed the source language version.' ) +
			  '</p></div>'
			: '';

		var aiSection =
			'<hr style="margin:1.4em 0 1em;">' +
			'<div style="font-weight:600;margin-bottom:8px;">' + escHtml( s.aiSection || 'AI Recommendations' ) + '</div>' +
			( aiOn
				? '<button id="lf-seo-meta-ai-btn" class="button button-secondary">' +
				  escHtml( s.runAi || 'Run AI Analysis' ) + '</button>' +
				  '<span id="lf-seo-meta-ai-spinner" class="spinner" style="float:none;margin:0 4px;vertical-align:middle;display:none;"></span>' +
				  '<div id="lf-seo-meta-ai-results" style="margin-top:10px;"></div>'
				: '<p style="color:#646970;font-size:13px;">' +
				  escHtml( s.aiNotConfigured || 'Configure an AI provider in Settings → API Keys to enable AI-powered recommendations.' ) +
				  '</p>'
			);

		resultsDiv.innerHTML =
			sourceNote +
			'<div style="display:flex;align-items:center;gap:1em;margin-bottom:1.2em;margin-top:0.8em;">' +
				'<div style="font-size:2.4em;font-weight:700;line-height:1;color:' + scoreColor + ';">' + score + '</div>' +
				'<div>' +
					'<strong style="font-size:1.05em;">' + escHtml( d.post_title || '' ) + '</strong><br>' +
					'<span class="description">' + escHtml( ( s.overallScore || 'Overall SEO score' ) + ' / 100' ) + '</span>' +
				'</div>' +
			'</div>' +
			'<table class="widefat striped" style="max-width:680px;">' +
				'<thead><tr>' +
					'<th style="width:24px;"></th>' +
					'<th style="width:160px;">' + escHtml( s.metric  || 'Metric'  ) + '</th>' +
					'<th>' + escHtml( s.finding || 'Finding' ) + '</th>' +
				'</tr></thead>' +
				'<tbody>' + rows + '</tbody>' +
			'</table>' +
			aiSection;

		// Wire AI button (only present when aiOn).
		if ( aiOn ) {
			var aiBtn = document.getElementById( 'lf-seo-meta-ai-btn' );
			if ( aiBtn ) {
				aiBtn.addEventListener( 'click', function () {
					runAi( lastPid, lastProfile, false );
				} );
			}
		}
	}

	function metricRow( label, metric ) {

		if ( ! metric ) { return ''; }

		var status  = metric.status  || 'info';
		var message = metric.message || '';
		var extra   = '';

		if ( typeof metric.length === 'number' ) {
			extra = ' <span style="color:#646970;font-size:11px;">(' + metric.length + ' chars)</span>';
		} else if ( metric.display ) {
			extra = ' <span style="color:#646970;font-size:11px;">' + escHtml( metric.display ) + '</span>';
		}

		return '<tr>' +
			'<td>' + statusIcon( status ) + '</td>' +
			'<td><strong>' + escHtml( label ) + '</strong></td>' +
			'<td>' + escHtml( message ) + extra + '</td>' +
		'</tr>';
	}

	function statusIcon( status ) {
		switch ( status ) {
			case 'ok':   return '<span style="color:#00a32a;font-size:1.2em;">✓</span>';
			case 'warn': return '<span style="color:#dba617;font-size:1.2em;">⚠</span>';
			case 'fail': return '<span style="color:#d63638;font-size:1.2em;">✗</span>';
			default:     return '<span style="color:#646970;font-size:1.2em;">ℹ</span>';
		}
	}

	// ── AI analysis ───────────────────────────────────────────────────────────

	function runAi( pid, profile, forceRefresh ) {

		var aiBtn     = document.getElementById( 'lf-seo-meta-ai-btn' );
		var aiSpinner = document.getElementById( 'lf-seo-meta-ai-spinner' );
		var aiResults = document.getElementById( 'lf-seo-meta-ai-results' );

		if ( ! aiBtn || ! aiResults ) { return; }

		aiBtn.disabled = true;
		if ( aiSpinner ) { aiSpinner.style.display = 'inline-block'; }
		aiResults.innerHTML = '';

		var params = {
			action:  'linguaforge_seo_ai_analyze',
			nonce:   nonce,
			post_id: pid,
			lang:    lang,
			profile: profile,
		};
		if ( forceRefresh ) { params.force_refresh = '1'; }

		fetch( ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    new URLSearchParams( params ).toString(),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json.success ) {
					renderAiResults( json.data, aiResults );
					// After a successful result, show a refresh button instead of the run button.
					aiBtn.style.display = 'none';
					var refreshBtn = document.createElement( 'button' );
					refreshBtn.className   = 'button button-secondary';
					refreshBtn.style.marginTop = '10px';
					refreshBtn.textContent = s.refreshAi || '↺ Refresh AI Analysis';
					refreshBtn.addEventListener( 'click', function () {
						refreshBtn.remove();
						aiBtn.style.display = '';
						runAi( pid, profile, true );
					} );
					aiResults.parentNode.insertBefore( refreshBtn, aiResults );
				} else {
					aiResults.innerHTML =
						'<div class="notice notice-error inline"><p>' +
						escHtml( ( json.data && json.data.message ) || ( s.aiFailed || 'AI analysis failed.' ) ) +
						'</p></div>';
				}
			} )
			.catch( function () {
				aiResults.innerHTML =
					'<div class="notice notice-error inline"><p>' +
					escHtml( s.aiFailed || 'AI analysis failed. Please try again.' ) +
					'</p></div>';
			} )
			.finally( function () {
				aiBtn.disabled = false;
				if ( aiSpinner ) { aiSpinner.style.display = 'none'; }
			} );
	}

	function renderAiResults( ai, container ) {

		var fromCache = !! ai.from_cache;

		var items = ( ai.improvements || [] ).map( function ( imp ) {
			return '<li style="margin-bottom:4px;">' + escHtml( imp ) + '</li>';
		} ).join( '' );

		var html =
			( fromCache
				? '<p style="color:#646970;font-size:11px;margin-bottom:8px;">ℹ ' +
				  escHtml( s.fromCache || 'Loaded from cache.' ) + '</p>'
				: '' ) +
			( ai.summary
				? '<p style="font-style:italic;color:#3c434a;margin-bottom:12px;">' + escHtml( ai.summary ) + '</p>'
				: '' ) +
			( items
				? '<ul style="margin-left:1.2em;list-style:disc;">' + items + '</ul>'
				: '' ) +
			( ai.title_suggestion
				? '<div style="margin-top:12px;"><strong>' +
				  escHtml( s.titleSuggestion || 'Suggested title' ) + ':</strong>' +
				  '<p style="margin:4px 0 0;font-style:italic;">' + escHtml( ai.title_suggestion ) + '</p></div>'
				: '' ) +
			( ai.meta_suggestion
				? '<div style="margin-top:12px;"><strong>' +
				  escHtml( s.metaSuggestion || 'Suggested meta description' ) + ':</strong>' +
				  '<p style="margin:4px 0 0;font-style:italic;">' + escHtml( ai.meta_suggestion ) + '</p></div>'
				: '' );

		container.innerHTML = html || '<p style="color:#646970;">' + escHtml( s.analyzing || 'No suggestions returned.' ) + '</p>';
	}

	// ── Utilities ─────────────────────────────────────────────────────────────

	/**
	 * On new-post screens (?action=edit&post_type=product) the post ID is 0
	 * until the auto-draft is created.  WooCommerce inserts it as `post_ID`
	 * in a hidden input once the auto-draft exists, so we read it from there.
	 */
	function resolveNewPostId() {
		var input = document.getElementById( 'post_ID' );
		return input ? parseInt( input.value, 10 ) : 0;
	}

	function escHtml( v ) {
		return String( v )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#39;'  );
	}

} () );
