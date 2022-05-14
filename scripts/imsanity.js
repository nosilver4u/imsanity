/**
 * imsanity admin javascript functions
 */

jQuery(document).ready(function($) {$(".fade").fadeTo(5000,1).fadeOut(3000);});

// Handle a manual resize from the media library.
jQuery(document).on('click', '.imsanity-manual-resize', function() {
	var post_id = jQuery(this).data('id');
	var imsanity_nonce = jQuery(this).data('nonce');
	jQuery('#imsanity-media-status-' + post_id ).html( imsanity_vars.resizing );
	jQuery.post(
		ajaxurl,
		{_wpnonce: imsanity_nonce, action: 'imsanity_resize_image', id: post_id},
		function(response) {
			var target = jQuery('#imsanity-media-status-' + post_id );
			try {
				var result = JSON.parse(response);
				target.html(result['message']);
			} catch(e) {
				target.html(imsanity_vars.invalid_response);
				if (console) {
					console.warn(post_id + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + response);
				}
			}
		}
	);
	return false;
});

// Handle an original image removal request from the media library.
jQuery(document).on('click', '.imsanity-manual-remove-original', function() {
	var post_id = jQuery(this).data('id');
	var imsanity_nonce = jQuery(this).data('nonce');
	jQuery('#imsanity-media-status-' + post_id ).html( imsanity_vars.resizing );
	jQuery.post(
		ajaxurl,
		{_wpnonce: imsanity_nonce, action: 'imsanity_remove_original', id: post_id},
		function(response) {
			var target = jQuery('#imsanity-media-status-' + post_id );
			try {
				var result = JSON.parse(response);
				if (! result['success']) {
					target.html(imsanity_vars.removal_failed);
				} else {
					target.html(imsanity_vars.removal_succeeded);
				}
			} catch(e) {
				target.html(imsanity_vars.invalid_response);
				if (console) {
					console.warn(post_id + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + response);
				}
			}
		}
	);
	return false;
});

jQuery(document).on('submit', '#imsanity-bulk-stop', function() {
	jQuery(this).hide();
	imsanity_vars.stopped = true;
	imsanity_vars.attachments = [];
	jQuery('#imsanity_loading').html(imsanity_vars.operation_stopped);
	jQuery('#imsanity_loading').show();
	return false;
});

/**
 * Begin the process of re-sizing all of the checked images
 */
function imsanity_resize_images() {
	// start the recursion
	imsanity_resize_next(0);
}

/**
 * recursive function for resizing images
 */
function imsanity_resize_next(next_index) {
	if (next_index >= imsanity_vars.attachments.length) return imsanity_resize_complete();
	var total_images = imsanity_vars.attachments.length;
	var target = jQuery('#resize_results');
	target.show();

	jQuery.post(
		ajaxurl, // (defined by wordpress - points to admin-ajax.php)
		{_wpnonce: imsanity_vars._wpnonce, action: 'imsanity_resize_image', id: imsanity_vars.attachments[next_index], resumable: 1},
		function (response) {
			var result;
			jQuery('#bulk-resize-beginning').hide();

			try {
				result = JSON.parse(response);
				target.append('<div>' + (next_index+1) + '/' + total_images + ' &gt;&gt; ' + result['message'] +'</div>');
			} catch(e) {
				target.append('<div>' + imsanity_vars.invalid_response + '</div>');
				if (console) {
					console.warn(imsanity_vars.attachments[next_index] + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + response);
				}
			}
			// recurse
			imsanity_resize_next(next_index+1);
		}
	);
}

/**
 * fired when all images have been resized
 */
function imsanity_resize_complete() {
	var target = jQuery('#resize_results');
	if (! imsanity_vars.stopped) {
		jQuery('#imsanity-bulk-stop').hide();
		target.append('<div><strong>' + imsanity_vars.resizing_complete + '</strong></div>');
		jQuery.post(
			ajaxurl, // (global defined by wordpress - points to admin-ajax.php)
			{_wpnonce: imsanity_vars._wpnonce, action: 'imsanity_bulk_complete'}
		);
	}
}

/**
 * ajax post to return all images from the library
 * @param string the id of the html element into which results will be appended
 */
function imsanity_load_images() {
	var imsanity_really_resize_all = confirm(imsanity_vars.resize_all_prompt);
	if ( ! imsanity_really_resize_all ) {
		return;
	}
	jQuery('#imsanity-examine-button').hide();
	jQuery('.imsanity-bulk-text').hide();
	jQuery('#imsanity-bulk-reset').hide();
	jQuery('#imsanity_loading').show();

	jQuery.post(
		ajaxurl, // (global defined by wordpress - points to admin-ajax.php)
		{_wpnonce: imsanity_vars._wpnonce, action: 'imsanity_get_images', resume_id: imsanity_vars.resume_id},
		function(response) {
			var is_json = true;
			try {
				var images = JSON.parse(response);
			} catch ( err ) {
				is_json = false;
			}
			if ( ! is_json ) {
				console.log( response );
				return false;
			}

			jQuery('#imsanity_loading').hide();
			if (images.length > 0) {
				imsanity_vars.attachments = images;
				imsanity_vars.stopped = false;
				jQuery('#imsanity-bulk-stop').show();
				imsanity_resize_images();
			} else {
				jQuery('#imsanity_loading').html('<div>' + imsanity_vars.none_found + '</div>');
			}
		}
	);
}
