<?php
/**
 * Imsanity utility functions.
 *
 * @package Imsanity
 */

/**
 * Util function returns an array value, if not defined then returns default instead.
 *
 * @param array  $arr Any array.
 * @param string $key Any index from that array.
 * @param mixed  $default Whatever you want.
 */
function imsanity_val( $arr, $key, $default = '' ) {
	return isset( $arr[ $key ] ) ? $arr[ $key ] : $default;
}

/**
 * Retrieves the path of an attachment via the $id and the $meta.
 *
 * @param array  $meta The attachment metadata.
 * @param int    $id The attachment ID number.
 * @param string $file Optional. Path relative to the uploads folder. Default ''.
 * @param bool   $refresh_cache Optional. True to flush cache prior to fetching path. Default true.
 * @return string The full path to the image.
 */
function imsanity_attachment_path( $meta, $id, $file = '', $refresh_cache = true ) {
	// Retrieve the location of the WordPress upload folder.
	$upload_dir  = wp_upload_dir( null, false, $refresh_cache );
	$upload_path = trailingslashit( $upload_dir['basedir'] );
	if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
		$file_path = $meta['file'];
		if ( strpos( $file_path, 's3' ) === 0 ) {
			return '';
		}
		if ( is_file( $file_path ) ) {
			return $file_path;
		}
		$file_path = $upload_path . $file_path;
		if ( is_file( $file_path ) ) {
			return $file_path;
		}
		$upload_path = trailingslashit( WP_CONTENT_DIR ) . 'uploads/';
		$file_path   = $upload_path . $meta['file'];
		if ( is_file( $file_path ) ) {
			return $file_path;
		}
	}
	if ( ! $file ) {
		$file = get_post_meta( $id, '_wp_attached_file', true );
	}
	$file_path          = ( 0 !== strpos( $file, '/' ) && ! preg_match( '|^.:\\\|', $file ) ? $upload_path . $file : $file );
	$filtered_file_path = apply_filters( 'get_attached_file', $file_path, $id );
	if ( strpos( $filtered_file_path, 's3' ) === false && is_file( $filtered_file_path ) ) {
		return str_replace( '//_imsgalleries/', '/_imsgalleries/', $filtered_file_path );
	}
	if ( strpos( $file_path, 's3' ) === false && is_file( $file_path ) ) {
		return str_replace( '//_imsgalleries/', '/_imsgalleries/', $file_path );
	}
	return '';
}

/**
 * Get mimetype based on file extension instead of file contents when speed outweighs accuracy.
 *
 * @param string $path The name of the file.
 * @return string|bool The mime type based on the extension or false.
 */
function imsanity_quick_mimetype( $path ) {
	$pathextension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	switch ( $pathextension ) {
		case 'jpg':
		case 'jpeg':
		case 'jpe':
			return 'image/jpeg';
		case 'png':
			return 'image/png';
		case 'gif':
			return 'image/gif';
		case 'pdf':
			return 'application/pdf';
		default:
			return false;
	}
}

/**
 * Gets the orientation/rotation of a JPG image using the EXIF data.
 *
 * @param string $file Name of the file.
 * @param string $type Mime type of the file.
 * @return int|bool The orientation value or false.
 */
function imsanity_get_orientation( $file, $type ) {
	if ( function_exists( 'exif_read_data' ) && 'image/jpeg' === $type ) {
		$exif = @exif_read_data( $file );
		if ( is_array( $exif ) && array_key_exists( 'Orientation', $exif ) ) {
			return (int) $exif['Orientation'];
		}
	}
	return false;
}

/**
 * Check an image to see if it has transparency.
 *
 * @param string $filename The name of the image file.
 * @return bool True if transparency is found.
 */
function imsanity_has_alpha( $filename ) {
	if ( ! is_file( $filename ) ) {
		return false;
	}
	if ( false !== strpos( $filename, '../' ) ) {
		return false;
	}
	$file_contents = file_get_contents( $filename );
	// Determine what color type is stored in the file.
	$color_type = ord( substr( $file_contents, 25, 1 ) );
	// If we do not have GD and the PNG color type is RGB alpha or Grayscale alpha.
	if ( ! imsanity_gd_support() && ( 4 === $color_type || 6 === $color_type ) ) {
		return true;
	} elseif ( imsanity_gd_support() ) {
		$image = imagecreatefrompng( $filename );
		if ( imagecolortransparent( $image ) >= 0 ) {
			return true;
		}
		list( $width, $height ) = getimagesize( $filename );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$color = imagecolorat( $image, $x, $y );
				$rgb   = imagecolorsforindex( $image, $color );
				if ( $rgb['alpha'] > 0 ) {
					return true;
				}
			}
		}
	}
	return false;
}

/**
 * Check for GD support of both PNG and JPG.
 *
 * @return bool True if full GD support is detected.
 */
function imsanity_gd_support() {
	if ( function_exists( 'gd_info' ) ) {
		$gd_support = gd_info();
		if ( is_iterable( $gd_support ) ) {
			if ( ( ! empty( $gd_support['JPEG Support'] ) || ! empty( $gd_support['JPG Support'] ) ) && ! empty( $gd_support['PNG Support'] ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Output a fatal error and optionally die.
 *
 * @param string $message The message to output.
 * @param string $title A title/header for the message.
 * @param bool   $die Default false. Whether we should die.
 */
function imsanity_fatal( $message, $title = '', $die = false ) {
	echo ( "<div style='margin:5px 0px 5px 0px;padding:10px;border: solid 1px red; background-color: #ff6666; color: black;'>"
		. ( $title ? "<h4 style='font-weight: bold; margin: 3px 0px 8px 0px;'>" . $title . '</h4>' : '' )
		. $message
		. '</div>' );
	if ( $die ) {
		die();
	}
}

/**
 * Resizes the image with the given id according to the configured max width and height settings.
 *
 * @param int $id The attachment ID of the image to process.
 * @return array The success status (bool) and a message to display.
 */
function imsanity_resize_from_id( $id = 0 ) {

	$id = (int) $id;

	if ( ! $id ) {
		return;
	}

	$meta = wp_get_attachment_metadata( $id );

	if ( $meta && is_array( $meta ) ) {
		$update_meta = false;
		// If "noresize" is included in the filename then we will bypass imsanity scaling.
		if ( ! empty( $meta['file'] ) && false !== strpos( $meta['file'], 'noresize' ) ) {
			/* translators: %s: File-name of the image */
			$msg = sprintf( esc_html__( 'SKIPPED: %s (noresize)', 'imsanity' ), $meta['file'] );
			return array(
				'success' => false,
				'message' => $msg,
			);
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
			/* translators: %s: File type of the image */
			$msg = sprintf( esc_html__( '%1$s does not have an allowed file type (%2$s)', 'imsanity' ), $meta['file'], $ftype );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		$uploads = wp_upload_dir();
		$oldpath = imsanity_attachment_path( $meta, $id, '', false );
		if ( empty( $oldpath ) || ! is_writable( $oldpath ) ) {
			/* translators: %s: File-name of the image */
			$msg = sprintf( esc_html__( '%s is not writable', 'imsanity' ), $meta['file'] );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		if ( apply_filters( 'imsanity_skip_image', false, $oldpath ) ) {
			/* translators: %s: File-name of the image */
			$msg = sprintf( esc_html__( 'SKIPPED: %s (by user exclusion)', 'imsanity' ), $meta['file'] );
			return array(
				'success' => false,
				'message' => $msg,
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

			if ( $maxw > 0 && $maxh > 0 && $oldw >= $maxw && $oldh >= $maxh && ( $oldh > $maxh || $oldw > $maxw ) && apply_filters( 'imsanity_crop_image', false ) ) {
				$neww = $maxw;
				$newh = $maxh;
			} else {
				list( $neww, $newh ) = wp_constrain_dimensions( $oldw, $oldh, $maxw, $maxh );
			}

			$resizeresult = imsanity_image_resize( $oldpath, $neww, $newh, apply_filters( 'imsanity_crop_image', false ), null, null, $quality );

			if ( $resizeresult && ! is_wp_error( $resizeresult ) ) {
				$newpath = $resizeresult;

				if ( $newpath !== $oldpath && is_file( $newpath ) && filesize( $newpath ) < filesize( $oldpath ) ) {
					// we saved some file space. remove original and replace with resized image.
					unlink( $oldpath );
					rename( $newpath, $oldpath );
					$meta['width']  = $neww;
					$meta['height'] = $newh;

					$update_meta = true;

					$results = array(
						'success' => true,
						'id'      => $id,
						/* translators: 1: File-name of the image */
						'message' => sprintf( esc_html__( 'OK: %1$s resized to %2$s x %3$s', 'imsanity' ), $meta['file'], $neww . 'w', $newh . 'h' ),
					);
				} elseif ( $newpath !== $oldpath ) {
					// the resized image is actually bigger in filesize (most likely due to jpg quality).
					// keep the old one and just get rid of the resized image.
					if ( is_file( $newpath ) ) {
						unlink( $newpath );
					}
					$results = array(
						'success' => false,
						'id'      => $id,
						/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
						'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $meta['file'], esc_html__( 'File size of resized image was larger than the original', 'imsanity' ) ),
					);
				} else {
					$results = array(
						'success' => false,
						'id'      => $id,
						/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
						'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $meta['file'], esc_html__( 'Unknown error, resizing function returned the same filename', 'imsanity' ) ),
					);
				}
			} elseif ( false === $resizeresult ) {
				$results = array(
					'success' => false,
					'id'      => $id,
					/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
					'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $meta['file'], esc_html__( 'wp_get_image_editor missing', 'imsanity' ) ),
				);
			} else {
				$results = array(
					'success' => false,
					'id'      => $id,
					/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
					'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $meta['file'], htmlentities( $resizeresult->get_error_message() ) ),
				);
			}
		} else {
			$results = array(
				'success' => true,
				'id'      => $id,
				/* translators: %s: File-name of the image */
				'message' => sprintf( esc_html__( 'SKIPPED: %s (Resize not required)', 'imsanity' ), $meta['file'] ) . " -- $oldw x $oldh",
			);
			if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
				if ( empty( $meta['width'] ) || $meta['width'] > $oldw ) {
					$meta['width'] = $oldw;
				}
				if ( empty( $meta['height'] ) || $meta['height'] > $oldh ) {
					$meta['height'] = $oldh;
				}
				$update_meta = true;
			}
		}
		$remove_original = imsanity_remove_original_image( $id, $meta );
		if ( $remove_original && is_array( $remove_original ) ) {
			$meta        = $remove_original;
			$update_meta = true;
		}
		if ( ! empty( $update_meta ) ) {
			wp_update_attachment_metadata( $id, $meta );
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

	return $results;
}

/**
 * Remove the backed-up original_image stored by WP 5.3+.
 *
 * @param int   $id The attachment ID number.
 * @param array $meta The attachment metadata. Optional, default to null.
 * @return bool True on success, false on failure.
 */
function imsanity_remove_original_image( $id, $meta = null ) {
	$id = (int) $id;
	if ( empty( $id ) ) {
		return false;
	}
	if ( is_null( $meta ) ) {
		$meta = wp_get_attachment_metadata( $id );
	}

	if (
		$meta && is_array( $meta ) &&
		imsanity_get_option( 'imsanity_delete_originals', false ) &&
		! empty( $meta['original_image'] ) && function_exists( 'wp_get_original_image_path' )
	) {
		$original_image = wp_get_original_image_path( $id );
		if ( empty( $original_image ) || ! is_file( $original_image ) ) {
			$original_image = wp_get_original_image_path( $id, true );
		}
		if ( ! empty( $original_image ) && is_file( $original_image ) && is_writable( $original_image ) ) {
			unlink( $original_image );
		}
		clearstatcache();
		if ( empty( $original_image ) || ! is_file( $original_image ) ) {
			unset( $meta['original_image'] );
			return $meta;
		}
	}
	return false;
}

/**
 * Resize an image using the WP_Image_Editor.
 *
 * @param string $file Image file path.
 * @param int    $max_w Maximum width to resize to.
 * @param int    $max_h Maximum height to resize to.
 * @param bool   $crop Optional. Whether to crop image or resize.
 * @param string $suffix Optional. File suffix.
 * @param string $dest_path Optional. New image file path.
 * @param int    $jpeg_quality Optional, default is 82. Image quality level (1-100).
 * @return mixed WP_Error on failure. String with new destination path.
 */
function imsanity_image_resize( $file, $max_w, $max_h, $crop = false, $suffix = null, $dest_path = null, $jpeg_quality = 82 ) {
	if ( function_exists( 'wp_get_image_editor' ) ) {
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$editor->set_quality( min( 92, $jpeg_quality ) );

		$ftype = imsanity_quick_mimetype( $file );

		// Return 1 to override auto-rotate.
		$orientation = (int) apply_filters( 'imsanity_orientation', imsanity_get_orientation( $file, $ftype ) );
		// Try to correct for auto-rotation if the info is available.
		switch ( $orientation ) {
			case 3:
				$editor->rotate( 180 );
				break;
			case 6:
				$editor->rotate( -90 );
				break;
			case 8:
				$editor->rotate( 90 );
				break;
		}

		$resized = $editor->resize( $max_w, $max_h, $crop );
		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$dest_file = $editor->generate_filename( $suffix, $dest_path );

		// Make sure that the destination file does not exist.
		if ( file_exists( $dest_file ) ) {
			$dest_file = $editor->generate_filename( 'TMP', $dest_path );
		}

		$saved = $editor->save( $dest_file );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $dest_file;
	}
	return false;
}
