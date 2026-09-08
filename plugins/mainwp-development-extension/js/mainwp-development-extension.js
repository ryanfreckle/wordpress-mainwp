jQuery(document).ready(function ($) {

	$(document).on('click', '.mwp-dev-backup-btn', function (e) {
		e.preventDefault();

		var $btn = $(this);
		var $status = $('#mwp-dev-backup-status');
		var websiteId = $btn.data('site-id');
		var originalLabel = $btn.text();

		$btn.prop('disabled', true).text('Running backup…');
		$status
			.removeClass('mwp-dev-status-error mwp-dev-status-success')
			.text('Running WP Migrate DB Pro export on the child site — this can take a while for larger databases…');

		$.post(ajaxurl, {
			action: 'mainwp_development_migratedb_backup',
			security: (typeof security_nonces !== 'undefined') ? security_nonces['mainwp_development_migratedb_backup'] : '',
			website_id: websiteId
		}).done(function (response) {
			if (response && response.success) {
				var data = response.data || {};
				var html = 'Backup complete: ' + (data.filename || 'file');
				if (data.filesize) {
					html += ' (' + data.filesize + ')';
				}
				if (data.download_url) {
					html += ' — <a href="' + data.download_url + '" target="_blank" rel="noopener">Download</a>';
				}
				$status.addClass('mwp-dev-status-success').html(html);
			} else {
				var message = (response && response.data && response.data.message) ? response.data.message : 'Backup failed.';
				$status.addClass('mwp-dev-status-error').text(message);
			}
		}).fail(function () {
			$status.addClass('mwp-dev-status-error').text('Request failed — check the browser console and server logs.');
		}).always(function () {
			$btn.prop('disabled', false).text(originalLabel);
		});
	});

});
