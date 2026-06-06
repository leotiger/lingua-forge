/**
 * Lingua Forge — SEO Analysis panel
 *
 * Flow:
 *   1. User selects language + post type and clicks "Load content".
 *   2. Post list is fetched via AJAX and rendered as a table.
 *   3. User clicks "Analyze" on any row.
 *   4. Analysis result is rendered in the panel below the list.
 *
 * Data injected by wp_localize_script as lfSeoAnalysis:
 *   ajaxUrl  — admin-ajax.php URL
 *   nonce    — wp_create_nonce('linguaforge_seo_analyze')
 *   strings  — translatable UI strings
 */
( function () {
	'use strict';

	var data       = window.lfSeoAnalysis || {};
	var ajaxUrl    = data.ajaxUrl || '';
	var nonce      = data.nonce   || '';
	var s          = data.strings || {};

	// ── Element refs ───────────────────────────────────────────────────────────
	var langSelect    = document.getElementById( 'lf-seo-filter-lang' );
	var typeSelect    = document.getElementById( 'lf-seo-filter-type' );
	var loadBtn       = document.getElementById( 'lf-seo-load-posts-btn' );
	var listSpinner   = document.getElementById( 'lf-seo-list-spinner' );
	var postList      = document.getElementById( 'lf-seo-post-list' );
	var resultsDiv    = document.getElementById( 'lf-seo-analysis-results' );

	if ( ! loadBtn || ! postList || ! resultsDiv ) { return; }

	// ── Load content list ──────────────────────────────────────────────────────
	loadBtn.addEventListener( 'click', function () {
		loadPosts();
	} );

	// Also reload when filters change.
	if ( langSelect ) {
		langSelect.addEventListener( 'change', function () {
			if ( postList.innerHTML !== '' ) { loadPosts(); }
		} );
	}
	if ( typeSelect ) {
		typeSelect.addEventListener( 'change', function () {
			if ( postList.innerHTML !== '' ) { loadPosts(); }
		} );
	}

	function loadPosts() {

		loadBtn.disabled          = true;
		listSpinner.style.display = 'inline-block';
		postList.innerHTML        = '';
		resultsDiv.innerHTML      = '';

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

		var rows = items.map( function ( item ) {
			return '<tr>' +
				'<td>' +
					'<strong>' + escHtml( item.title ) + '</strong>' +
					( item.edit_url
						? ' <a href="' + escHtml( item.edit_url ) + '" target="_blank" style="font-size:11px;color:#646970;">(' +
							escHtml( s.edit || 'edit' ) + ')</a>'
						: '' ) +
				'</td>' +
				'<td style="color:#646970;white-space:nowrap;">' + escHtml( item.type ) + '</td>' +
				'<td style="color:#646970;white-space:nowrap;">' + escHtml( item.modified ) + '</td>' +
				'<td>' +
					'<button type="button" class="button button-small lf-seo-analyze-row-btn" ' +
					'data-post-id="' + escHtml( String( item.id ) ) + '" ' +
					'data-lang="' + escHtml( lang ) + '">' +
					escHtml( s.analyze || 'Analyze' ) +
					'</button>' +
					'<span class="spinner lf-row-spinner" style="float:none;margin:0 4px;vertical-align:middle;display:none;"></span>' +
				'</td>' +
			'</tr>';
		} ).join( '' );

		postList.innerHTML =
			'<table class="widefat striped" style="margin-bottom:1.5em;">' +
				'<thead><tr>' +
					'<th>' + escHtml( s.title      || 'Title'    ) + '</th>' +
					'<th>' + escHtml( s.type       || 'Type'     ) + '</th>' +
					'<th>' + escHtml( s.modified   || 'Modified' ) + '</th>' +
					'<th style="width:100px;"></th>' +
				'</tr></thead>' +
				'<tbody>' + rows + '</tbody>' +
			'</table>';

		// Wire Analyze buttons.
		var btns = postList.querySelectorAll( '.lf-seo-analyze-row-btn' );
		btns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				analyzePost( btn );
			} );
		} );
	}

	// ── Analyze a single post ─────────────────────────────────────────────────
	function analyzePost( btn ) {

		var postId  = btn.dataset.postId;
		var lang    = btn.dataset.lang;
		var spinner = btn.nextElementSibling;

		// Disable all analyze buttons while running.
		postList.querySelectorAll( '.lf-seo-analyze-row-btn' ).forEach( function ( b ) {
			b.disabled = true;
		} );

		if ( spinner ) { spinner.style.display = 'inline-block'; }
		resultsDiv.innerHTML = '';

		var body = new URLSearchParams( {
			action:  'linguaforge_seo_analyze',
			nonce:   nonce,
			post_id: postId,
			lang:    lang,
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
					// Scroll results into view.
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
				postList.querySelectorAll( '.lf-seo-analyze-row-btn' ).forEach( function ( b ) {
					b.disabled = false;
				} );
			} );
	}

	// ── Result renderers ──────────────────────────────────────────────────────

	function renderError( msg ) {
		resultsDiv.innerHTML =
			'<div class="notice notice-error inline"><p>' + escHtml( msg ) + '</p></div>';
	}

	function renderResults( d ) {

		var score   = d.score   || 0;
		var metrics = d.metrics || {};

		var scoreColor = score >= 80 ? '#00a32a' : score >= 50 ? '#dba617' : '#d63638';

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

		resultsDiv.innerHTML =
			sourceNote +
			'<div style="display:flex;align-items:center;gap:1em;margin-bottom:1.2em;">' +
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

	function escHtml( v ) {
		return String( v )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#39;'  );
	}

} () );
