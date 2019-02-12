<?php
/**
 * Imsanity AJAX functions.
 *
 * @package Imsanity
 */

add_action( 'wp_ajax_imsanity_get_images', 'imsanity_get_images' );
add_action( 'wp_ajax_imsanity_resize_image', 'imsanity_resize_image' );

/**
 * Verifies that the current user has administrator permission and, if not,
 * renders a json warning and dies
 */
function imsanity_verify_permission() {
	if ( ! current_user_can( 'activate_plugins' ) ) { // this isn't a real capability, but super admins can do anything, so it works.
		die(
			json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Administrator permission is required', 'imsanity' ),
				)
			)
		);
	}
	if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'imsanity-bulk' ) ) {
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

	global $wpdb;
	$offset  = 0;
	$limit   = apply_filters( 'imsanity_attachment_query_limit', 3000 );
	$results = array();
	$maxw    = imsanity_get_option( 'imsanity_max_width', IMSANITY_DEFAULT_MAX_WIDTH );
	$maxh    = imsanity_get_option( 'imsanity_max_height', IMSANITY_DEFAULT_MAX_HEIGHT );
	$count   = 0;

	$images = $wpdb->get_results( $wpdb->prepare( "SELECT metas.meta_value as file_meta,metas.post_id as ID FROM $wpdb->postmeta metas INNER JOIN $wpdb->posts posts ON posts.ID = metas.post_id WHERE posts.post_type = 'attachment' AND posts.post_mime_type LIKE %s AND posts.post_mime_type != 'image/bmp' AND metas.meta_key = '_wp_attachment_metadata' ORDER BY ID DESC LIMIT %d,%d", '%image%', $offset, $limit ) );
	/* $images = $wpdb->get_results( "SELECT metas.meta_value as file_meta,metas.post_id as ID FROM $wpdb->postmeta metas INNER JOIN $wpdb->posts posts ON posts.ID = metas.post_id WHERE posts.post_type LIKE 'attachment' AND posts.post_mime_type LIKE 'image%%' AND posts.post_mime_type NOT LIKE 'image/bmp' AND metas.meta_key = '_wp_attachment_metadata' LIMIT $offset,$limit" ); */
	while ( $images ) {

		foreach ( $images as $image ) {
			$imagew = false;
			$imageh = false;

			$meta = unserialize( $image->file_meta );
		
			// If "noresize" is included in the filename then we will bypass imsanity scaling.
			if ( ! empty( $meta['file'] ) && strpos( $meta['file'], 'noresize' ) !== false ) {
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
function imsanity_resize_image() {
	imsanity_verify_permission();

	global $wpdb;

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

	$meta = wp_get_attachment_metadata( $id );

	if ( $meta && is_array( $meta ) ) {
		$uploads = wp_upload_dir();
		$oldpath = imsanity_attachment_path( $meta['file'], $id, '', false );
		if ( empty( $oldpath ) || ! is_writable( $oldpath ) ) {
			/* translators: %s: File-name of the image */
			$msg = sprintf( esc_html__( '%s is not writable', 'imsanity' ), $meta['file'] );
			die(
				json_encode(
					array(
						'success' => false,
						'message' => $msg,
					)
				)
			);
		}

		$maxw = imsanity_get_option( 'imsanity_max_width', IMSANITY_DEFAULT_MAX_WIDTH );
		$maxh = imsanity_get_option( 'imsanity_max_height', IMSANITY_DEFAULT_MAX_HEIGHT );

		// method one - slow but accurate, get file size from file itself.
		list( $oldw, $oldh ) = getimagesize( $oldpath );
		// method two - get file size from meta, fast but resize will fail if meta is out of sync.
		if ( ! $oldw || ! $oldh ) {
			$oldw = $meta['width'];
			$oldh = $meta['height'];
		}

		if ( ( $oldw > $maxw && $maxw > 0 ) || ( $oldh > $maxh && $maxh > 0 ) ) {
			$quality = imsanity_get_option( 'imsanity_quality', IMSANITY_DEFAULT_QUALITY );

			if ( $oldw > $maxw && $maxw > 0 && $oldh > $maxh && $maxh > 0 && apply_filters( 'imsanity_crop_image', false ) ) {
				$neww = $maxw;
				$newh = $maxh;
			} else {
				list( $neww, $newh ) = wp_constrain_dimensions( $oldw, $oldh, $maxw, $maxh );
			}

			$resizeresult = imsanity_image_resize( $oldpath, $neww, $newh, apply_filters( 'imsanity_crop_image', false ), null, null, $quality );

			if ( $resizeresult && ! is_wp_error( $resizeresult ) ) {
				$newpath = $resizeresult;

				if ( $newpath != $oldpath && is_file( $newpath ) && filesize( $newpath ) < filesize( $oldpath ) ) {
					// we saved some file space. remove original and replace with resized image.
					unlink( $oldpath );
					rename( $newpath, $oldpath );
					$meta['width']  = $neww;
					$meta['height'] = $newh;

					wp_update_attachment_metadata( $id, $meta );

					$results = array(
						'success' => true,
						'id'      => $id,
						/* translators: %s: File-name of the image */
						'message' => sprintf( esc_html__( 'OK: %s', 'imsanity' ), $oldpath ),
					);
				} elseif ( $newpath != $oldpath ) {
					// the resized image is actually bigger in filesize (most likely due to jpg quality).
					// keep the old one and just get rid of the resized image.
					if ( is_file( $newpath ) ) {
						unlink( $newpath );
					}
					$results = array(
						'success' => false,
						'id'      => $id,
						/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
						'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $oldpath, esc_html__( 'Resized image was larger than the original', 'imsanity' ) ),
					);
				} else {
					$results = array(
						'success' => false,
						'id'      => $id,
						/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
						'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $oldpath, esc_html__( 'Unknown error, resizing function returned the same filename', 'imsanity' ) ),
					);
				}
			} elseif ( false === $resizeresult ) {
				$results = array(
					'success' => false,
					'id'      => $id,
					/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
					'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $oldpath, esc_html__( 'wp_get_image_editor missing', 'imsanity' ) ),
				);
			} else {
				$results = array(
					'success' => false,
					'id'      => $id,
					/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
					'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $oldpath, htmlentities( $resizeresult->get_error_message() ) ),
				);
			}
		} else {
			$results = array(
				'success' => true,
				'id'      => $id,
				/* translators: %s: File-name of the image */
				'message' => sprintf( esc_html__( 'SKIPPED: %s (Resize not required)', 'imsanity' ), $oldpath ) . " -- $oldw x $oldh",
			);
			if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
				if ( empty( $meta['width'] ) || $meta['width'] > $oldw ) {
					$meta['width'] = $oldw;
				}
				if ( empty( $meta['height'] ) || $meta['height'] > $oldh ) {
					$meta['height'] = $oldh;
				}
				wp_update_attachment_metadata( $id, $meta );
			}
		}
	} else {
		$results = array(
			'success' => false,
			'id'      => $id,
			/* translators: %s: ID number of the image */
			'message' => sprintf( esc_html__( 'ERROR: Attachment with ID of %d not found', 'imsanity' ), intval( $id ) ),
		);
	}

	// If there is a quota we need to reset the directory size cache so it will re-calculate.
	delete_transient( 'dirsize_cache' );

	die( json_encode( $results ) );
}
