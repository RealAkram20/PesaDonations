/* PesaDonations Admin JS */
(function ($) {
	'use strict';

	// Copy shortcode on click.
	$(document).on('click', '.pd-shortcodes-box code', function () {
		const text = $(this).text();
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(function () {
				alert('Shortcode copied!');
			});
		}
	});

}(jQuery));
