<?php
/**
 * Imsanity AJAX functions.
 *
 * @package Imsanity
 */

add_action( 'wp_ajax_imsanity_get_images', 'imsanity_get_images' );
add_action( 'wp_ajax_imsanity_resize_image', 'imsanity_ajax_resize' );
add_action( 'wp_ajax_imsanity_remove_original', 'imsanity_ajax_remove_original' );
add_action( 'wp_ajax_imsanity_bulk_complete', 'imsanity_ajax_finish' );

/**
 * Verifies that the current user has administrator permission and, if not,
 * renders a json warning and dies
 */
function imsanity_verify_permission() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		die(
			json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Administrator permission is required', 'imsanity' ),
				)
			)
		);
	}
	if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'imsanity-bulk' ) && ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'imsanity-manual-resize' ) ) {
		die(
			json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Access token has expired, please reload the page.', 'imsanity' ),
				)
			)
		);
	}
}


/**
 * Searches for up to 250 images that are candidates for resize and renders them
 * to the browser as a json array, then dies
 */
function imsanity_get_images() {
	imsanity_verify_permission();

	$resume_id = ! empty( $_POST['resume_id'] ) ? (int) $_POST['resume_id'] : PHP_INT_MAX;
	global $wpdb;
	// Load up all the image attachments we can find.
	$attachments = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID < %d AND post_type = 'attachment' AND post_mime_type LIKE %s ORDER BY ID DESC", $resume_id, '%%image%%' ) );
	array_walk( $attachments, 'intval' );
	die( json_encode( $attachments ) );

	// TODO: that's all, get rid of the rest.
	$offset  = 0;
	$limit   = apply_filters( 'imsanity_attachment_query_limit', 3000 );
	$results = array();
	$maxw    = imsanity_get_option( 'imsanity_max_width', IMSANITY_DEFAULT_MAX_WIDTH );
	$maxh    = imsanity_get_option( 'imsanity_max_height', IMSANITY_DEFAULT_MAX_HEIGHT );
	$count   = 0;

	$images = $wpdb->get_results( $wpdb->prepare( "SELECT metas.meta_value as file_meta,metas.post_id as ID FROM $wpdb->postmeta metas INNER JOIN $wpdb->posts posts ON posts.ID = metas.post_id WHERE posts.post_type = 'attachment' AND posts.post_mime_type LIKE %s AND posts.post_mime_type != 'image/bmp' AND metas.meta_key = '_wp_attachment_metadata' ORDER BY ID DESC LIMIT %d,%d", '%image%', $offset, $limit ) );
	while ( $images ) {

		foreach ( $images as $image ) {
			$imagew = false;
			$imageh = false;

			$meta = unserialize( $image->file_meta );

			// If "noresize" is included in the filename then we will bypass imsanity scaling.
			if ( ! empty( $meta['file'] ) && strpos( $meta['file'], 'noresize' ) !== false ) {
				continue;
			}

			// Let folks filter the allowed mime-types for resizing.
			$allowed_types = apply_filters( 'imsanity_allowed_mimes', array( 'image/png', 'image/gif', 'image/jpeg' ), $meta['file'] );
			if ( is_string( $allowed_types ) ) {
				$allowed_types = array( $allowed_types );
			} elseif ( ! is_array( $allowed_types ) ) {
				$allowed_types = array();
			}
			$ftype = imsanity_quick_mimetype( $meta['file'] );
			if ( ! in_array( $ftype, $allowed_types, true ) ) {
				continue;
			}

			if ( imsanity_get_option( 'imsanity_deep_scan', false ) ) {
				$file_path = imsanity_attachment_path( $meta, $image->ID, '', false );
				if ( $file_path ) {
					list( $imagew, $imageh ) = getimagesize( $file_path );
				}
			}
			if ( empty( $imagew ) || empty( $imageh ) ) {
				$imagew = $meta['width'];
				$imageh = $meta['height'];
			}

			if ( $imagew > $maxw || $imageh > $maxh ) {
				$count++;

				$results[] = array(
					'id'     => $image->ID,
					'width'  => $imagew,
					'height' => $imageh,
					'file'   => $meta['file'],
				);
			}

			// Make sure we only return a limited number of records so we don't overload the ajax features.
			if ( $count >= IMSANITY_AJAX_MAX_RECORDS ) {
				break 2;
			}
		}
		$offset += $limit;
		$images  = $wpdb->get_results( $wpdb->prepare( "SELECT metas.meta_value as file_meta,metas.post_id as ID FROM $wpdb->postmeta metas INNER JOIN $wpdb->posts posts ON posts.ID = metas.post_id WHERE posts.post_type = 'attachment' AND posts.post_mime_type LIKE %s AND posts.post_mime_type != 'image/bmp' AND metas.meta_key = '_wp_attachment_metadata' ORDER BY ID DESC LIMIT %d,%d", '%image%', $offset, $limit ) );
	} // endwhile
	die( json_encode( $results ) );
}

/**
 * Resizes the image with the given id according to the configured max width and height settings
 * renders a json response indicating success/failure and dies
 */
function imsanity_ajax_resize() {
	imsanity_verify_permission();

	$id = (int) $_POST['id'];
	if ( ! $id ) {
		die(
			json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Missing ID Parameter', 'imsanity' ),
				)
			)
		);
	}
	$results = imsanity_resize_from_id( $id );
	if ( ! empty( $_POST['resumable'] ) ) {
		update_option( 'imsanity_resume_id', $id, false );
		sleep( 1 );
	}

	die( json_encode( $results ) );
}

/**
 * Resizes the image with the given id according to the configured max width and height settings
 * renders a json response indicating success/failure and dies
 */
function imsanity_ajax_remove_original() {
	imsanity_verify_permission();

	$id = (int) $_POST['id'];
	if ( ! $id ) {
		die(
			json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Missing ID Parameter', 'imsanity' ),
				)
			)
		);
	}
	$remove_original = imsanity_remove_original_image( $id );
	if ( $remove_original && is_array( $remove_original ) ) {
		wp_update_attachment_metadata( $id, $remove_original );
		die( json_encode( array( 'success' => true ) ) );
	}

	die( json_encode( array( 'success' => false ) ) );
}

/**
 * Finalizes the resizing process.
 */
function imsanity_ajax_finish() {
	imsanity_verify_permission();

	update_option( 'imsanity_resume_id', 0, false );

	die();
}
