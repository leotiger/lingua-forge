/**
 * Meta Description — character counter
 *
 * Colours the counter span green (120–160), amber (161–200), or red (outside
 * both ranges) so editors can target the recommended length at a glance.
 *
 * Enqueued by Module::enqueue_assets() on post-edit screens only.
 */
( function () {
	var ta      = document.getElementById( 'lf_meta_description_field' );
	var counter = document.getElementById( 'lf_meta_desc_counter' );
	if ( ! ta || ! counter ) { return; }

	function update() {
		var len   = ta.value.length;
		var color = ( len >= 120 && len <= 160 ) ? '#00a32a'
		          : ( len > 160 && len <= 200 )  ? '#dba617'
		          :                                 '#cc1818';
		counter.textContent = len + ' chars';
		counter.style.color = color;
	}

	ta.addEventListener( 'input', update );
	update();
} )();
