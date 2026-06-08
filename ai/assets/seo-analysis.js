/**
 * Lingua Forge — SEO Analysis panel (Settings page)
 *
 * Flow:
 *   1. User selects language + post type and clicks "Load content".
 *   2. Post list is fetched via AJAX and rendered as a table.
 *   3. Each row shows a profile <select>; changing it immediately runs analysis
 *      for that row's post with the chosen profile.
 *   4. Analysis result is rendered in the panel below the list.
 *
 * Data injected by wp_localize_script as lfSeoAnalysis:
 *   ajaxUrl  — admin-ajax.php URL
 *   nonce    — wp_create_nonce('linguaforge_seo_analyze')
 *   profiles — array of { value, label } for the per-row profile selector
 *   strings  — translatable UI strings
 */
( function () {
	'use strict';

	var data    = window.lfSeoAnalysis || {};
	var ajaxUrl = data.ajaxUrl  || '';
	var nonce   = data.nonce    || '';
	var s       = data.strings  || {};
	var profiles = data.profiles || [];

	// ── Element refs ───────────────────────────────────────────────────────────
	var langSelect  = document.getElementById( 'lf-seo-filter-lang' );
	var typeSelect  = document.getElementById( 'lf-seo-filter-type' );
	var loadBtn     = document.getElementById( 'lf-seo-load-posts-btn' );
	var listSpinner = document.getElementById( 'lf-seo-list-spinner' );
	var postList    = document.getElementById( 'lf-seo-post-list' );
	var resultsDiv  = document.getElementById( 'lf-seo-analysis-results' );

	if ( ! loadBtn || ! postList || ! resultsDiv ) { return; }

	// ── Load content list ──────────────────────────────────────────────────────
	loadBtn.addEventListener( 'click', function () {
		resultsDiv.innerHTML = '';
		loadPosts();
	} );

	// Reload post list when language / type filters change.
	if ( langSelect ) {
		langSelect.addEventListener( 'change', function () {
			if ( postList.innerHTML !== '' ) { resultsDiv.innerHTML = ''; loadPosts(); }
		} );
	}
	if ( typeSelect ) {
		typeSelect.addEventListener( 'change', function () {
			if ( postList.innerHTML !== '' ) { resultsDiv.innerHTML = ''; loadPosts(); }
		} );
	}

	function loadPosts() {

		loadBtn.disabled          = true;
		listSpinner.style.display = 'inline-block';
		postList.innerHTML        = '';

		var body = new URLSearchParams( {
			action:    'linguaforge_seo_get_posts',
			nonce:     nonce,
			lang:      langSelect ? langSelect.value : '',
			post_type: typeSelect ? typeSelect.value : '',
		} );

		fetch( ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    body.toString(),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json.success ) {
					renderPostList( json.data );
				} else {
					postList.innerHTML = '<p class="notice notice-error inline">' +
						escHtml( ( json.data && json.data.message ) || ( s.requestFailed || 'Failed to load posts.' ) ) +
						'</p>';
				}
			} )
			.catch( function () {
				postList.innerHTML = '<p class="notice notice-error inline">' +
					escHtml( s.requestFailed || 'Request failed.' ) + '</p>';
			} )
			.finally( function () {
				loadBtn.disabled          = false;
				listSpinner.style.display = 'none';
			} );
	}

	// ── Render post list ───────────────────────────────────────────────────────
	function renderPostList( d ) {

		var items = d.items || [];
		var lang  = d.lang  || '';

		if ( items.length === 0 ) {
			postList.innerHTML =
				'<p class="description">' +
				escHtml( s.noPostsFound || 'No published posts found for the selected filters.' ) +
				'</p>';
			return;
		}

		// Build profile options HTML once and reuse per row.
		// A disabled placeholder is always first so any profile choice fires the change event.
		var profileOptions =
			'<option value="" selected>' + escHtml( s.autoDetect || '— Auto-detect —' ) + '</option>' +
			profiles.map( function ( p ) {
				return '<option value="' + escHtml( p.value ) + '">' + escHtml( p.label ) + '</option>';
			} ).join( '' );

		var rows = items.map( function ( item ) {
			return '<tr>' +
				'<td>' +
					( item.edit_url
						? '<a href="' + escHtml( item.edit_url ) + '" target="_blank"><strong>' + escHtml( item.title ) + '</strong></a>'
						: '<strong>' + escHtml( item.title ) + '</strong>' ) +
				'</td>' +
				'<td style="color:#646970;white-space:nowrap;">' + escHtml( item.type ) + '</td>' +
				'<td style="color:#646970;white-space:nowrap;">' + escHtml( item.modified ) + '</td>' +
				'<td style="white-space:nowrap;">' +
					'<select class="lf-seo-profile-select" ' +
						'data-post-id="' + escHtml( String( item.id ) ) + '" ' +
						'data-lang="' + escHtml( lang ) + '" ' +
						'style="max-width:160px;">' +
						profileOptions +
					'</select>' +
					'<span class="spinner lf-row-spinner" style="float:none;margin:0 4px;vertical-align:middle;display:none;"></span>' +
				'</td>' +
			'</tr>';
		} ).join( '' );

		postList.innerHTML =
			'<table class="widefat striped" style="margin-bottom:1.5em;">' +
				'<thead><tr>' +
					'<th>' + escHtml( s.title    || 'Title'    ) + '</th>' +
					'<th>' + escHtml( s.type     || 'Type'     ) + '</th>' +
					'<th>' + escHtml( s.modified || 'Modified' ) + '</th>' +
					'<th style="width:180px;">' + escHtml( s.profile || 'Profile' ) + '</th>' +
				'</tr></thead>' +
				'<tbody>' + rows + '</tbody>' +
			'</table>';

		// Wire profile selects — change triggers immediate analysis.
		postList.querySelectorAll( '.lf-seo-profile-select' ).forEach( function ( sel ) {
			sel.addEventListener( 'change', function () {
				runAnalysis( sel.dataset.postId, sel.dataset.lang, sel.value, sel.nextElementSibling );
			} );
		} );
	}

	// ── Run analysis ──────────────────────────────────────────────────────────
	function runAnalysis( postId, lang, profile, spinner ) {

		// Disable all profile selects while a request is running.
		postList.querySelectorAll( '.lf-seo-profile-select' ).forEach( function ( sel ) {
			sel.disabled = true;
		} );

		if ( spinner ) { spinner.style.display = 'inline-block'; }
		resultsDiv.innerHTML = '';

		var body = new URLSearchParams( {
			action:   'linguaforge_seo_analyze',
			nonce:    nonce,
			post_id:  postId,
			lang:     lang,
			profile:  profile,
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
					resultsDiv.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
				} else {
					renderError( ( json.data && json.data.message ) || ( s.requestFailed || 'Analysis failed.' ) );
				}
			} )
			.catch( function () {
				renderError( s.requestFailed || 'Request failed.' );
			} )
			.finally( function () {
				if ( spinner ) { spinner.style.display = 'none'; }
				postList.querySelectorAll( '.lf-seo-profile-select' ).forEach( function ( sel ) {
					sel.disabled = false;
				} );
			} );
	}

	// ── Result renderers ──────────────────────────────────────────────────────

	function renderError( msg ) {
		resultsDiv.innerHTML =
			'<div class="notice notice-error inline"><p>' + escHtml( msg ) + '</p></div>';
	}

	function renderResults( d ) {

		var score      = d.score   || 0;
		var metrics    = d.metrics || {};
		var scoreColor = score >= 80 ? '#00a32a' : score >= 50 ? '#dba617' : '#d63638';

		// Delta vs previous run (null when no prior score exists).
		var prevScore  = ( typeof d.previous_score === 'number' ) ? d.previous_score : null;
		var deltaHtml  = '';
		if ( prevScore !== null && prevScore !== score ) {
			var diff       = score - prevScore;
			var deltaColor = diff > 0 ? '#00a32a' : '#d63638';
			var arrow      = diff > 0 ? '↑' : '↓';
			deltaHtml =
				'<span style="font-size:0.55em;font-weight:400;color:' + deltaColor + ';margin-left:6px;vertical-align:middle;">' +
					arrow + Math.abs( diff ) + ' <span style="color:#646970;">(' + prevScore + ')</span>' +
				'</span>';
		}

		var rows = [
			metricRow( s.titleLabel || 'Title',            metrics.title ),
			metricRow( s.metaDesc   || 'Meta description', metrics.meta_description ),
			metricRow( s.wordCount  || 'Word count',       metrics.word_count ),
			metricRow( s.readTime   || 'Reading time',     metrics.reading_time ),
			metricRow( s.headings   || 'Headings',         metrics.headings ),
			metricRow( s.images     || 'Images',           metrics.images ),
			metricRow( s.links      || 'Links',            metrics.links ),
		].join( '' );

		var sourceNote = d.used_source
			? '<div class="notice notice-warning inline" style="margin-bottom:1em;"><p>' +
			  escHtml( s.usedSource || 'No translation found — analyzed the source language version.' ) +
			  '</p></div>'
			: '';

		var wcNote = d.is_wc_system_page
			? '<div class="notice notice-info inline" style="margin-bottom:1em;"><p>' +
			  escHtml( s.wcSystemPageNotice || 'This is a WooCommerce system page (Shop, Cart, Checkout, etc.). Its content is managed by WooCommerce — the score reflects structural signals only, not user-editable SEO content.' ) +
			  '</p></div>'
			: '';

		resultsDiv.innerHTML =
			sourceNote +
			wcNote +
			'<div style="display:flex;align-items:center;gap:1em;margin-bottom:1.2em;">' +
				'<div style="font-size:2.4em;font-weight:700;line-height:1;color:' + scoreColor + ';">' + score + deltaHtml + '</div>' +
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
			'</table>';
	}

	function metricRow( label, metric ) {

		if ( ! metric ) { return ''; }

		var status  = metric.status  || 'info';
		var message = metric.message || '';
		var extra   = '';

		if ( typeof metric.length === 'number' ) {
			extra = ' <span style="color:#646970;font-size:11px;">(' + metric.length + ' chars)</span>';
		}
		if ( metric.display ) {
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

	// ── Batch Analysis ────────────────────────────────────────────────────────

	var batchGrid         = document.getElementById( 'lf-batch-lang-grid' );
	var batchAllBtn       = document.getElementById( 'lf-batch-analyse-all-btn' );
	var batchAllSpinner   = document.getElementById( 'lf-batch-all-spinner' );
	var batchTypeFilter   = document.getElementById( 'lf-batch-filter-type' );
	var batchProfileSel   = document.getElementById( 'lf-batch-profile' );
	var batchAttentionDiv = document.getElementById( 'lf-batch-attention' );

	// Accumulated attention items across "Analyse all" runs.
	var allAttentionItems = [];

	if ( batchGrid ) {

		// Wire individual "Analyse" buttons on each language card.
		batchGrid.querySelectorAll( '.lf-batch-analyse-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				allAttentionItems = [];
				runBatch( btn.dataset.lang );
			} );
		} );

		// "Analyse all languages" — clear accumulator, run each card sequentially.
		if ( batchAllBtn ) {
			batchAllBtn.addEventListener( 'click', function () {
				var cards = Array.from( batchGrid.querySelectorAll( '.lf-batch-lang-card' ) );
				allAttentionItems = [];
				batchAllBtn.disabled = true;
				if ( batchAllSpinner ) { batchAllSpinner.style.display = 'inline-block'; }

				var chain = Promise.resolve();
				cards.forEach( function ( card ) {
					chain = chain.then( function () {
						return runBatch( card.dataset.lang, true /* accumulate */ );
					} );
				} );
				chain.finally( function () {
					batchAllBtn.disabled = false;
					if ( batchAllSpinner ) { batchAllSpinner.style.display = 'none'; }
				} );
			} );
		}
	}

	/**
	 * Run batch analysis for one language card.
	 *
	 * @param {string}  lang        Language code.
	 * @param {boolean} accumulate  When true, merge attention items into allAttentionItems
	 *                              instead of replacing (used by "Analyse all").
	 * @return {Promise<void>}
	 */
	function runBatch( lang, accumulate ) {

		var card       = document.getElementById( 'lf-batch-card-' + lang );
		var spinner    = card ? card.querySelector( '.lf-batch-card-spinner' ) : null;
		var analyseBtn = card ? card.querySelector( '.lf-batch-analyse-btn' ) : null;
		var statsDiv   = document.getElementById( 'lf-batch-stats-' + lang );

		if ( analyseBtn ) { analyseBtn.disabled = true; }
		if ( spinner )    { spinner.style.display = 'inline-block'; }

		var postType = batchTypeFilter  ? batchTypeFilter.value  : '';
		var profile  = batchProfileSel  ? batchProfileSel.value  : 'blog';

		var body = new URLSearchParams( {
			action:    'linguaforge_seo_batch_analyze',
			nonce:     nonce,
			lang:      lang,
			post_type: postType,
			profile:   profile,
		} );

		return fetch( ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    body.toString(),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json.success ) {
					renderBatchCard( lang, json.data );
					// Merge or replace the attention list.
					var items = Array.isArray( json.data.attention ) ? json.data.attention : [];
					if ( accumulate ) {
						allAttentionItems = allAttentionItems.concat( items );
					} else {
						allAttentionItems = items;
					}
					renderAttentionList( allAttentionItems );
				} else if ( statsDiv ) {
					statsDiv.style.display = '';
					statsDiv.innerHTML =
						'<span style="color:#d63638;">' +
						escHtml( ( json.data && json.data.message ) || ( s.requestFailed || 'Failed.' ) ) +
						'</span>';
				}
			} )
			.catch( function () {
				if ( statsDiv ) {
					statsDiv.style.display = '';
					statsDiv.innerHTML =
						'<span style="color:#d63638;">' +
						escHtml( s.requestFailed || 'Request failed.' ) +
						'</span>';
				}
			} )
			.finally( function () {
				if ( spinner )    { spinner.style.display = 'none'; }
				if ( analyseBtn ) { analyseBtn.disabled = false; }
			} );
	}

	/**
	 * Update a language card with aggregate stats from a completed batch run.
	 *
	 * @param {string} lang
	 * @param {Object} d  AJAX response data
	 */
	function renderBatchCard( lang, d ) {

		var statsDiv = document.getElementById( 'lf-batch-stats-' + lang );
		var lastDiv  = document.getElementById( 'lf-batch-last-'  + lang );

		if ( ! statsDiv ) { return; }

		var avg      = typeof d.avg_score === 'number' ? d.avg_score : 0;
		var avgColor = avg >= 80 ? '#00a32a' : avg >= 50 ? '#dba617' : '#d63638';
		var analyzed = d.analyzed || 0;
		var total    = d.total    || 0;
		var skipped  = d.skipped  || 0;
		var ok       = d.ok       || 0;
		var warn     = d.warn     || 0;
		var fail     = d.fail     || 0;
		var partial  = d.partial
			? ' <em style="color:#646970;font-size:10px;">(partial)</em>'
			: '';
		var skippedNote = skipped > 0
			? ' <em style="color:#646970;font-size:10px;">(' + skipped + ' skipped)</em>'
			: '';

		statsDiv.style.display = '';
		statsDiv.innerHTML =
			'<div style="font-size:1.4em;font-weight:700;color:' + avgColor + ';line-height:1;margin-bottom:4px;">' +
				avg +
				'<span style="font-size:0.5em;font-weight:400;color:#646970;margin-left:3px;">avg</span>' +
			'</div>' +
			'<div style="font-size:11px;color:#646970;margin-bottom:3px;">' +
				escHtml( String( analyzed ) + ' / ' + String( total ) ) + partial + skippedNote +
			'</div>' +
			'<div style="font-size:11px;">' +
				'<span style="color:#00a32a;font-weight:600;">&#10003;&thinsp;' + ok   + '</span>' +
				'&ensp;' +
				'<span style="color:#dba617;font-weight:600;">&#9651;&thinsp;' + warn + '</span>' +
				'&ensp;' +
				'<span style="color:#d63638;font-weight:600;">&#10007;&thinsp;' + fail + '</span>' +
			'</div>';

		if ( lastDiv ) {
			lastDiv.textContent = s.justNow || 'Just now';
		}
	}

	/**
	 * Render the "Posts needing attention" table (score < 70) from accumulated items.
	 * Items arrive sorted per-language; re-sorted here after "Analyse all" merges them.
	 * The table wrapper gets a max-height + scroll when there are more than 10 rows.
	 *
	 * @param {Array} items
	 */
	function renderAttentionList( items ) {

		if ( ! batchAttentionDiv ) { return; }

		if ( ! items || items.length === 0 ) {
			batchAttentionDiv.style.display = 'none';
			return;
		}

		var profileLabels = {};
		profiles.forEach( function ( p ) { profileLabels[ p.value ] = p.label; } );

		// Group by language, preserving order of first appearance.
		var byLang = {};
		var langs  = [];
		items.forEach( function ( item ) {
			var l = item.lang || 'unknown';
			if ( ! byLang[ l ] ) { byLang[ l ] = []; langs.push( l ); }
			byLang[ l ].push( item );
		} );

		// Sort each group worst-first; server sorts per run but merges may reorder.
		langs.forEach( function ( l ) {
			byLang[ l ].sort( function ( a, b ) { return a.score - b.score; } );
		} );

		// Build tab strip.
		var tabButtons = langs.map( function ( l, i ) {
			var count = byLang[ l ].length;
			return '<button type="button" class="lf-parity-tab-btn' + ( i === 0 ? ' is-active' : '' ) + '" data-lang="' + escHtml( l ) + '">' +
				escHtml( l.toUpperCase() ) +
				' <span class="lf-parity-tab-count">(' + count + ')</span>' +
			'</button>';
		} ).join( '' );

		// Build panels.
		var panels = langs.map( function ( l, i ) {
			var langItems   = byLang[ l ];
			var scrollStyle = langItems.length > 12 ? 'max-height:400px;overflow-y:auto;' : '';

			var rows = langItems.map( function ( item ) {
				var score        = item.score || 0;
				var clr          = score >= 80 ? '#00a32a' : score >= 50 ? '#dba617' : '#d63638';
				var profileLabel = profileLabels[ item.profile ] || escHtml( item.profile || '' );
				var titleHtml    = item.edit_url
					? '<a href="' + escHtml( item.edit_url ) + '" target="_blank"><strong>' + escHtml( item.title || '' ) + '</strong></a>'
					: '<strong>' + escHtml( item.title || '' ) + '</strong>';
				var sourceCell   = item.source_title
					? '<td class="lf-parity-source-col">' + escHtml( item.source_title ) + '</td>'
					: '<td class="lf-parity-source-col" style="color:#c3c4c7;">—</td>';
				return '<tr>' +
					'<td style="width:36px;text-align:center;">' +
						'<span style="font-weight:700;color:' + clr + ';">' + score + '</span>' +
					'</td>' +
					'<td>' + titleHtml + '</td>' +
					sourceCell +
					'<td style="color:#646970;white-space:nowrap;font-size:11px;">' + escHtml( item.type    || '' ) + '</td>' +
					'<td style="color:#646970;white-space:nowrap;font-size:11px;">' + escHtml( profileLabel       ) + '</td>' +
				'</tr>';
			} ).join( '' );

			return '<div class="lf-parity-panel" data-lang="' + escHtml( l ) + '" style="display:' + ( i === 0 ? 'block' : 'none' ) + ';">' +
				'<div style="' + scrollStyle + '">' +
					'<table class="widefat striped" style="max-width:960px;">' +
						'<thead><tr>' +
							'<th style="width:36px;">'  + escHtml( s.score       || 'Score'        ) + '</th>' +
							'<th>'                       + escHtml( s.title       || 'Title'        ) + '</th>' +
							'<th style="width:220px;">' + escHtml( s.sourceTitle || 'Source title' ) + '</th>' +
							'<th style="width:120px;">' + escHtml( s.type        || 'Type'         ) + '</th>' +
							'<th style="width:140px;">' + escHtml( s.profile     || 'Profile'      ) + '</th>' +
						'</tr></thead>' +
						'<tbody>' + rows + '</tbody>' +
					'</table>' +
				'</div>' +
			'</div>';
		} ).join( '' );

		var totalCount = items.length;
		var parityHint = s.parityHint ||
			'Scores are a signal, not a verdict. Some content is structurally limited — very short pages, landing pages with little body text, or pages whose purpose is navigation rather than information may score lower by nature. Use this overview to spot genuine parity gaps across languages, not to chase a number.';
		batchAttentionDiv.style.display = '';
		batchAttentionDiv.innerHTML =
			'<h4 style="margin:0 0 0.3em;">' +
				escHtml( s.parityHeading || 'Multilingual SEO overview' ) +
				' <span style="font-weight:400;color:#646970;font-size:0.85em;">(' + totalCount + ')</span>' +
			'</h4>' +
			'<p class="description" style="margin:0 0 1em;max-width:680px;">' + escHtml( parityHint ) + '</p>' +
			'<div class="lf-parity-tabs">' + tabButtons + '</div>' +
			panels;

		// Wire tab switching.
		batchAttentionDiv.querySelectorAll( '.lf-parity-tab-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				batchAttentionDiv.querySelectorAll( '.lf-parity-tab-btn' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
				batchAttentionDiv.querySelectorAll( '.lf-parity-panel'   ).forEach( function ( p ) { p.style.display = 'none'; } );
				btn.classList.add( 'is-active' );
				var panel = batchAttentionDiv.querySelector( '.lf-parity-panel[data-lang="' + btn.dataset.lang + '"]' );
				if ( panel ) { panel.style.display = 'block'; }
			} );
		} );
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
