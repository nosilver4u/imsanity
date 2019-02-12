/**
 * imsanity admin javascript functions
 */

/**
 * Begin the process of re-sizing all of the checked images
 */
function imsanity_resize_images()
{
	var images = [];
	jQuery('.imsanity_image_cb:checked').each(function(i) {
       images.push(this.value);
    });

	var target = jQuery('#resize_results');
	target.html('');
	//jQuery(document).scrollTop(target.offset().top);

	// start the recursion
	imsanity_resize_next(images,0);
}

/**
 * recursive function for resizing images
 */
function imsanity_resize_next(images,next_index)
{
	if (next_index >= images.length) return imsanity_resize_complete();

	jQuery.post(
		ajaxurl, // (defined by wordpress - points to admin-ajax.php)
		{_wpnonce: imsanity_vars._wpnonce, action: 'imsanity_resize_image', id: images[next_index]},
		function(response)
		{
			var result;
			var target = jQuery('#resize_results');
			target.show();

			try {
				result = JSON.parse(response);
				target.append('<div>' + (next_index+1) + '/' + images.length + ' &gt;&gt; ' + result['message'] +'</div>');
			}
			catch(e) {
				target.append('<div>' + imsanity_vars.invalid_response + '</div>');
				if (console) {
					console.warn(images[next_index] + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + response);
				}
		    }

			target.animate({scrollTop: target.prop('scrollHeight')}, 200);
			// recurse
			imsanity_resize_next(images,next_index+1);
		}
	);
}

/**
 * fired when all images have been resized
 */
function imsanity_resize_complete()
{
	var target = jQuery('#resize_results');
	target.append('<div><strong>' + imsanity_vars.resizing_complete + '</strong></div>');
	target.animate({scrollTop: target.prop('scrollHeight')});
}

/**
 * ajax post to return all images that are candidates for resizing
 * @param string the id of the html element into which results will be appended
 */
function imsanity_load_images(container_id)
{
	var container = jQuery('#'+container_id);

	var target = jQuery('#imsanity_target');
	target.show();
	jQuery('.imsanity-selection').remove();
	jQuery('#imsanity_loading').show();

	target.animate({height: [250,'swing']},500, function()
	{
		jQuery(document).scrollTop(container.offset().top);

		jQuery.post(
				ajaxurl, // (global defined by wordpress - points to admin-ajax.php)
				{_wpnonce: imsanity_vars._wpnonce, action: 'imsanity_get_images'},
				function(response) {
					var is_json = true;
					try {
						var images = jQuery.parseJSON(response);
					} catch ( err ) {
						is_json = false;
					}
					if ( ! is_json ) {
						console.log( response );
						return false;
					}

					jQuery('#imsanity_loading').hide();
					if (images.length > 0)
					{
						target.append('<div class="imsanity-selection"><input id="imsanity_check_all" type="checkbox" checked="checked" onclick="jQuery(\'.imsanity_image_cb\').attr(\'checked\', this.checked);" /> Select All</div>');
						for (var i = 0; i < images.length; i++)
						{
							target.append('<div class="imsanity-selection"><input class="imsanity_image_cb" name="imsanity_images" value="' + images[i].id + '" type="checkbox" checked="checked" />' + imsanity_vars.image + ' ' + images[i].id + ': ' + images[i].file +' ('+images[i].width+' x '+images[i].height+')</div>');
						}
						if ( ! jQuery( '#resize-submit' ).length ) {
							container.append('<p id="resize-submit" class="submit"><button class="button-primary" onclick="imsanity_resize_images();">' + imsanity_vars.resize_selected + '</button></p>');
							container.append('<div id="resize_results" style="display: none; border: solid 2px #666666; padding: 10px; height: 250px; overflow: auto;" />');
						}
					}
					else
					{
						target.html('<div>' + imsanity_vars.none_found + '</div>');

					}
				}
			);
	});
}
