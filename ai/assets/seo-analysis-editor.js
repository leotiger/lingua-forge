/**
 * Lingua Forge — SEO Analysis — Block Editor Panel
 *
 * Registers a PluginDocumentSettingPanel in the Document sidebar that shows
 * the current post's rule-based SEO score with a button to open a full
 * analysis modal.  Inside the modal an "AI Recommendations" section calls
 * the AI provider (when configured) for natural-language suggestions.
 *
 * Globals (lfSeoAnalysisEditor, injected via wp_localize_script):
 *   ajaxUrl    — admin-ajax.php URL
 *   nonce      — shared nonce for rule-based + AI analysis requests
 *   aiEnabled  — bool — whether an AI provider is configured
 *   postLang   — current post's LF language code, or null
 *   strings    — translatable UI strings
 */
( function () {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editPost || ! wp.element ) { return; }

	var cfg      = window.lfSeoAnalysisEditor || {};
	var ajaxUrl  = cfg.ajaxUrl  || '';
	var nonce    = cfg.nonce    || '';
	var aiOn     = !! cfg.aiEnabled;
	var s        = cfg.strings  || {};

	var el          = wp.element.createElement;
	var useState    = wp.element.useState;
	var useEffect   = wp.element.useEffect;
	var useSelect   = wp.data.useSelect;
	var Button      = wp.components.Button;
	var Spinner     = wp.components.Spinner;
	var Modal       = wp.components.Modal;
	var Notice      = wp.components.Notice;

	// ── Panel component ────────────────────────────────────────────────────────

	function LfSeoPanel() {

		var postId    = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		} );
		var postTitle = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'title' );
		} );

		var _score       = useState( null );
		var score        = _score[0]; var setScore       = _score[1];

		var _loading     = useState( false );
		var loading      = _loading[0]; var setLoading     = _loading[1];

		var _modal       = useState( false );
		var showModal    = _modal[0]; var setShowModal    = _modal[1];

		var _metrics     = useState( null );
		var metrics      = _metrics[0]; var setMetrics     = _metrics[1];

		var _aiData      = useState( null );
		var aiData       = _aiData[0]; var setAiData      = _aiData[1];

		var _aiLoading   = useState( false );
		var aiLoading    = _aiLoading[0]; var setAiLoading   = _aiLoading[1];

		var _aiError     = useState( '' );
		var aiError      = _aiError[0]; var setAiError     = _aiError[1];

		// Load rule-based metrics for the score badge on first render.
		useEffect( function () {
			if ( postId ) { quickLoad( postId, setScore ); }
		}, [ postId, setScore ] );

		// ── Panel render ────────────────────────────────────────────────────────
		return el( wp.editPost.PluginDocumentSettingPanel,
			{
				name:  'lf-seo-analysis-panel',
				title: s.panelTitle || 'SEO Analysis',
				icon:  'chart-line',
			},
			// Score badge.
			score !== null && el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px' } },
				el( 'span', { style: { fontSize: '1.5em', fontWeight: '700', color: scoreColor( score ), lineHeight: 1 } }, String( score ) ),
				el( 'span', { style: { color: '#646970', fontSize: '12px' } }, s.outOf100 || '/ 100' )
			),
			loading && el( Spinner ),
			el( Button,
				{
					variant:  'secondary',
					isBusy:   loading,
					disabled: loading || ! postId,
					onClick:  function () { openModal( postId, setLoading, setMetrics, setScore, setShowModal, setAiData, setAiError ); },
				},
				s.analyze || 'Analyze'
			),
			// ── Modal ───────────────────────────────────────────────────────────
			showModal && el( Modal,
				{
					title:           ( s.panelTitle || 'SEO Analysis' ) + ' — ' + ( postTitle || '' ),
					onRequestClose:  function () { setShowModal( false ); setAiData( null ); setAiError( '' ); },
					style:           { maxWidth: '680px' },
				},
				renderModal( metrics, aiData, aiLoading, aiError, aiOn, postId, s, setAiData, setAiLoading, setAiError )
			)
		);
	}

	// ── Quick load (score badge only) ─────────────────────────────────────────

	function quickLoad( postId, setScore ) {
		post( 'linguaforge_seo_analyze', { post_id: postId, lang: cfg.postLang || '' } )
			.then( function ( d ) { if ( d.success ) setScore( d.data.score ); } )
			.catch( function () {} );
	}

	// ── Open modal + load full metrics ────────────────────────────────────────

	function openModal( postId, setLoading, setMetrics, setScore, setShowModal, setAiData, setAiError ) {

		setLoading( true );
		setAiData( null );
		setAiError( '' );

		post( 'linguaforge_seo_analyze', { post_id: postId, lang: cfg.postLang || '' } )
			.then( function ( d ) {
				if ( d.success ) {
					setMetrics( d.data );
					setScore( d.data.score );
					setShowModal( true );
				}
			} )
			.catch( function () {} )
			.finally( function () { setLoading( false ); } );
	}

	// ── Modal content ─────────────────────────────────────────────────────────

	function renderModal( metrics, aiData, aiLoading, aiError, aiEnabled, postId, strings, setAiData, setAiLoading, setAiError ) {

		if ( ! metrics ) {
			return el( Spinner );
		}

		var modalScore = metrics.score   || 0;
		var mets       = metrics.metrics || {};
		var rows       = [
			metricRow( strings.titleLabel || 'Title',            mets.title            ),
			metricRow( strings.metaDesc   || 'Meta description', mets.meta_description ),
			metricRow( strings.wordCount  || 'Word count',       mets.word_count       ),
			metricRow( strings.readTime   || 'Reading time',     mets.reading_time     ),
			metricRow( strings.headings   || 'Headings',         mets.headings         ),
			metricRow( strings.images     || 'Images',           mets.images           ),
			metricRow( strings.links      || 'Links',            mets.links            ),
		].filter( Boolean );

		return el( 'div', null,

			// Score
			el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '12px', marginBottom: '16px' } },
				el( 'div', { style: { fontSize: '2.2em', fontWeight: '700', color: scoreColor( modalScore ), lineHeight: 1 } }, String( modalScore ) ),
				el( 'div', null,
					el( 'div', { style: { fontWeight: 600 } }, strings.overallScore || 'Overall SEO score' ),
					el( 'div', { style: { color: '#646970', fontSize: '12px' } }, '/ 100' )
				)
			),

			// Metrics table
			el( 'table', { style: { width: '100%', borderCollapse: 'collapse', marginBottom: '20px' } },
				el( 'thead', null,
					el( 'tr', null,
						el( 'th', { style: thStyle( '28px' ) } ),
						el( 'th', { style: thStyle( '150px' ) }, strings.metric  || 'Metric'  ),
						el( 'th', { style: thStyle( 'auto'  ) }, strings.finding || 'Finding' )
					)
				),
				el( 'tbody', null, ...rows )
			),

			// AI Recommendations
			el( 'hr', { style: { margin: '0 0 16px' } } ),
			el( 'div', { style: { marginBottom: '8px', fontWeight: '600' } }, strings.aiSection || 'AI Recommendations' ),

			! aiEnabled && el( 'p', { style: { color: '#646970', fontSize: '13px' } },
				strings.aiNotConfigured || 'Configure an AI provider in Settings → API Keys to enable AI-powered recommendations.'
			),

			aiEnabled && ! aiData && ! aiLoading && ! aiError && el( Button,
				{
					variant: 'primary',
					onClick: function () { runAi( postId, setAiData, setAiLoading, setAiError ); },
				},
				strings.runAi || 'Run AI Analysis'
			),

			aiLoading && el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
				el( Spinner ),
				el( 'span', { style: { color: '#646970', fontSize: '13px' } }, strings.analyzing || 'Analyzing…' )
			),

			aiError && el( Notice, { status: 'error', isDismissible: false },
				aiError
			),

			aiData && renderAiResults( aiData, strings )
		);
	}

	function renderAiResults( ai, aiStrings ) {
		var items = ( ai.improvements || [] ).map( function ( imp, i ) {
			return el( 'li', { key: i, style: { marginBottom: '4px' } }, imp );
		} );

		return el( 'div', null,
			el( 'p', { style: { fontStyle: 'italic', color: '#3c434a', marginBottom: '12px' } }, ai.summary || '' ),
			items.length > 0 && el( 'ul', { style: { marginLeft: '1.2em', listStyle: 'disc' } }, ...items ),
			ai.title_suggestion && el( 'div', { style: { marginTop: '12px' } },
				el( 'strong', null, ( aiStrings.titleSuggestion || 'Suggested title' ) + ':' ),
				el( 'p', { style: { margin: '4px 0 0', fontStyle: 'italic' } }, ai.title_suggestion )
			),
			ai.meta_suggestion && el( 'div', { style: { marginTop: '12px' } },
				el( 'strong', null, ( aiStrings.metaSuggestion || 'Suggested meta description' ) + ':' ),
				el( 'p', { style: { margin: '4px 0 0', fontStyle: 'italic' } }, ai.meta_suggestion )
			)
		);
	}

	// ── Run AI analysis ───────────────────────────────────────────────────────

	function runAi( postId, setAiData, setAiLoading, setAiError ) {

		setAiLoading( true );
		setAiError( '' );

		post( 'linguaforge_seo_ai_analyze', { post_id: postId, lang: cfg.postLang || '' } )
			.then( function ( d ) {
				if ( d.success ) {
					setAiData( d.data );
				} else {
					setAiError( ( d.data && d.data.message ) || ( s.aiFailed || 'AI analysis failed.' ) );
				}
			} )
			.catch( function () {
				setAiError( s.aiFailed || 'AI analysis failed. Please try again.' );
			} )
			.finally( function () { setAiLoading( false ); } );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	function metricRow( label, metric ) {
		if ( ! metric ) { return null; }
		var status  = metric.status  || 'info';
		var message = metric.message || '';
		var extra   = '';

		if ( typeof metric.length === 'number' ) {
			extra = ' (' + metric.length + ' chars)';
		} else if ( metric.display ) {
			extra = ' ' + metric.display;
		}

		return el( 'tr', { style: { borderBottom: '1px solid #f0f0f1' } },
			el( 'td', { style: tdStyle() }, statusIcon( status ) ),
			el( 'td', { style: Object.assign( {}, tdStyle(), { fontWeight: '600' } ) }, label ),
			el( 'td', { style: tdStyle() },
				message,
				extra && el( 'span', { style: { color: '#646970', fontSize: '11px' } }, extra )
			)
		);
	}

	function statusIcon( status ) {
		var map = { ok: '✓', warn: '⚠', fail: '✗', info: 'ℹ' };
		var col = { ok: '#00a32a', warn: '#dba617', fail: '#d63638', info: '#646970' };
		return el( 'span', { style: { color: col[status] || '#646970', fontSize: '1.1em' } }, map[status] || 'ℹ' );
	}

	function scoreColor( n ) {
		return n >= 80 ? '#00a32a' : n >= 50 ? '#dba617' : '#d63638';
	}

	function thStyle( w ) {
		return { padding: '6px 8px', textAlign: 'left', borderBottom: '2px solid #dcdcde',
			fontWeight: '600', fontSize: '12px', width: w };
	}

	function tdStyle() {
		return { padding: '7px 8px', verticalAlign: 'top', fontSize: '13px' };
	}

	function post( action, params ) {
		var body = new URLSearchParams( Object.assign( { action: action, nonce: nonce }, params ) );
		return fetch( ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    body.toString(),
		} ).then( function ( r ) { return r.json(); } );
	}

	// ── Register ──────────────────────────────────────────────────────────────

	wp.plugins.registerPlugin( 'lingua-forge-seo-analysis', {
		render: LfSeoPanel,
		icon:   'chart-line',
	} );

} () );
