/**
 * Lingua Forge — Quick Edit row language select.
 *
 * Populates the language <select> with the post's current language when the
 * editor expands the inline quick-edit row from the posts/pages list table.
 * The lang code is stored on the row's column-lang badge as a data-lang
 * attribute (rendered by Columns::render_lang_column).
 *
 * Loaded via wp_enqueue_script() on edit.php only.
 */

jQuery(function ($) {
	$(document).on('click', '.editinline', function () {
		var postId = $(this).closest('tr').attr('id').replace('post-', '');
		setTimeout(function () {
			var row     = $('#post-' + postId);
			var editRow = $('#edit-' + postId);
			if (!editRow.length) return;
			var lang = row.find('td.column-lang strong').data('lang');
			if (lang) { editRow.find('select[name="lf_lang"]').val(lang); }
		}, 200);
	});
});
