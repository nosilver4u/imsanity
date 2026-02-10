<?php
/**
 * Imsanity utility functions.
 *
 * @package Imsanity
 */

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
 * Checks the filename for a protocal wrapper (like s3://).
 *
 * @param string $path The path of the file to check.
 * @return bool True if the file contains :// indicating a stream wrapper.
 */
function imsanity_file_is_stream_wrapped( $path ) {
	if ( false !== strpos( $path, '://' ) ) {
		return true;
	}
	return false;
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
		case 'avif':
			return 'image/avif';
		case 'webp':
			return 'image/webp';
		default:
			return false;
	}
}

/**
 * Check the mimetype of the given file with magic mime strings/patterns.
 *
 * @param string $path The absolute path to the file.
 * @return bool|string A valid mime-type or false.
 */
function imsanity_mimetype( $path ) {
	imsanity_debug( "testing mimetype: $path" );
	$type = false;
	// For S3 images/files, don't attempt to read the file, just use the quick (filename) mime check.
	if ( imsanity_file_is_stream_wrapped( $path ) ) {
		return imsanity_quick_mimetype( $path );
	}
	$path = \realpath( $path );
	if ( ! is_file( $path ) ) {
		imsanity_debug( "$path is not a file, or out of bounds" );
		return $type;
	}
	if ( ! is_readable( $path ) ) {
		imsanity_debug( "$path is not readable" );
		return $type;
	}
	$file_handle   = fopen( $path, 'rb' );
	$file_contents = fread( $file_handle, 4096 );
	if ( $file_contents ) {
		// Read first 12 bytes, which equates to 24 hex characters.
		$magic = bin2hex( substr( $file_contents, 0, 12 ) );
		imsanity_debug( $magic );
		if ( 8 === strpos( $magic, '6674797061766966' ) ) {
			$type = 'image/avif';
			imsanity_debug( "imsanity type: $type" );
			return $type;
		}
		if ( '424d' === substr( $magic, 0, 4 ) ) {
			$type = 'image/bmp';
			imsanity_debug( "imsanity type: $type" );
			return $type;
		}
		if ( 0 === strpos( $magic, '52494646' ) && 16 === strpos( $magic, '57454250' ) ) {
			$type = 'image/webp';
			imsanity_debug( "imsanity type: $type" );
			return $type;
		}
		if ( 'ffd8ff' === substr( $magic, 0, 6 ) ) {
			$type = 'image/jpeg';
			imsanity_debug( "imsanity type: $type" );
			return $type;
		}
		if ( '89504e470d0a1a0a' === substr( $magic, 0, 16 ) ) {
			$type = 'image/png';
			imsanity_debug( "imsanity type: $type" );
			return $type;
		}
		if ( '474946383761' === substr( $magic, 0, 12 ) || '474946383961' === substr( $magic, 0, 12 ) ) {
			$type = 'image/gif';
			imsanity_debug( "imsanity type: $type" );
			return $type;
		}
		if ( '25504446' === substr( $magic, 0, 8 ) ) {
			$type = 'application/pdf';
			imsanity_debug( "imsanity type: $type" );
			return $type;
		}
		if ( preg_match( '/<svg/', $file_contents ) ) {
			$type = 'image/svg+xml';
			imsanity_debug( "imsanity type: $type" );
			return $type;
		}
		imsanity_debug( "match not found for file: $magic" );
	} else {
		imsanity_debug( 'could not open for reading' );
	}
	return false;
}

/**
 * Update the file extension based on the new mime type.
 *
 * @param string $path The path of the file to update.
 * @param string $new_mime The new mime type.
 * @return string The updated path with the new extension.
 */
function imsanity_update_extension( $path, $new_mime ) {
	$extension = '';
	switch ( $new_mime ) {
		case 'image/jpeg':
			$extension = 'jpg';
			break;
		case 'image/png':
			$extension = 'png';
			break;
		case 'image/gif':
			$extension = 'gif';
			break;
		case 'image/avif':
			$extension = 'avif';
			break;
		case 'image/webp':
			$extension = 'webp';
			break;
		default:
			return $path;
	}
	$pathinfo = pathinfo( $path );
	if ( empty( $pathinfo['dirname'] ) || empty( $pathinfo['filename'] ) ) {
		return $path;
	}
	$new_name = trailingslashit( $pathinfo['dirname'] ) . $pathinfo['filename'] . '.' . $extension;
	return $new_name;
}

/**
 * Check for AVIF support in the image editor and add to the list of allowed mimes.
 *
 * @param array $mimes A list of allowed mime types.
 * @return array The updated list of mimes after checking AVIF support.
 */
function imsanity_add_avif_support( $mimes ) {
	if ( ! in_array( 'image/avif', $mimes, true ) ) {
		if ( class_exists( 'Imagick' ) ) {
			$imagick = new Imagick();
			$formats = $imagick->queryFormats();
			if ( in_array( 'AVIF', $formats, true ) ) {
				$mimes[] = 'image/avif';
			}
		}
	}
	return $mimes;
}

/**
 * Check for WebP support in the image editor and add to the list of allowed mimes.
 *
 * @param array $mimes A list of allowed mime types.
 * @return array The updated list of mimes after checking WebP support.
 */
function imsanity_add_webp_support( $mimes ) {
	if ( ! in_array( 'image/webp', $mimes, true ) ) {
		if ( class_exists( 'Imagick' ) ) {
			$imagick = new Imagick();
			$formats = $imagick->queryFormats();
			if ( in_array( 'WEBP', $formats, true ) ) {
				$mimes[] = 'image/webp';
			}
		}
	}
	return $mimes;
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
		$exif = @exif_read_data( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
	imsanity_debug( __FUNCTION__ );
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
		imsanity_debug( "color type $color_type indicates alpha channel in $filename" );
		return true;
	} elseif ( imsanity_gd_support() ) {
		$image = imagecreatefrompng( $filename );
		if ( ! $image ) {
			imsanity_debug( "could not create GD image from $filename" );
			return false;
		}
		if ( imagecolortransparent( $image ) >= 0 ) {
			imsanity_debug( "$filename has a transparent color" );
			return true;
		}
		$image_size = getimagesize( $filename );
		if ( empty( $image_size[0] ) || empty( $image_size[1] ) ) {
			imsanity_debug( "invalid dimensions for $filename" );
			return false;
		}
		$width  = (int) $image_size[0];
		$height = (int) $image_size[1];
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$color = imagecolorat( $image, $x, $y );
				$rgb   = imagecolorsforindex( $image, $color );
				if ( $rgb['alpha'] > 0 ) {
					imsanity_debug( "found alpha in $filename at pixel $x, $y" );
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
 * Resizes the image with the given id according to the configured max width and height settings.
 *
 * @param int $id The attachment ID of the image to process.
 * @return array The success status (bool) and a message to display.
 */
function imsanity_resize_from_id( $id = 0 ) {
	imsanity_debug( __FUNCTION__ );

	$id = (int) $id;

	if ( ! $id ) {
		return;
	}
	imsanity_debug( "attempting to resize attachment $id" );

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

		$oldpath = imsanity_attachment_path( $meta, $id, '', false );

		if ( empty( $oldpath ) ) {
			/* translators: %s: File-name of the image */
			$msg = sprintf( esc_html__( 'Could not retrieve location of %s', 'imsanity' ), $meta['file'] );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		// Let folks filter the allowed mime-types for resizing.
		$allowed_types = apply_filters( 'imsanity_allowed_mimes', array( 'image/png', 'image/gif', 'image/jpeg' ), $oldpath );
		if ( is_string( $allowed_types ) ) {
			$allowed_types = array( $allowed_types );
		} elseif ( ! is_array( $allowed_types ) ) {
			$allowed_types = array();
		}
		$ftype = imsanity_quick_mimetype( $oldpath );
		if ( ! in_array( $ftype, $allowed_types, true ) ) {
			/* translators: %s: File type of the image */
			$msg = sprintf( esc_html__( '%1$s does not have an allowed file type (%2$s)', 'imsanity' ), wp_basename( $oldpath ), $ftype );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		if ( ! is_writable( $oldpath ) ) {
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
		$oldw = false;
		$oldh = false;

		// method one - slow but accurate, get file size from file itself.
		$dimensions = getimagesize( $oldpath );
		if ( is_array( $dimensions ) && count( $dimensions ) >= 2 ) {
			$oldw = $dimensions[0];
			$oldh = $dimensions[1];
		}
		// method two - get file size from meta, fast but resize will fail if meta is out of sync.
		if ( ! $oldw || ! $oldh ) {
			$oldw = $meta['width'];
			$oldh = $meta['height'];
		}

		if ( ( $oldw > $maxw && $maxw > 0 ) || ( $oldh > $maxh && $maxh > 0 ) ) {

			if ( $maxw > 0 && $maxh > 0 && $oldw >= $maxw && $oldh >= $maxh && ( $oldh > $maxh || $oldw > $maxw ) && apply_filters( 'imsanity_crop_image', false ) ) {
				$neww = $maxw;
				$newh = $maxh;
			} else {
				list( $neww, $newh ) = wp_constrain_dimensions( $oldw, $oldh, $maxw, $maxh );
			}

			$source_image = $oldpath;
			if ( ! empty( $meta['original_image'] ) ) {
				$source_image = path_join( dirname( $oldpath ), $meta['original_image'] );
				imsanity_debug( "subbing in $source_image for resizing" );
			}
			remove_all_filters( 'image_editor_output_format' );
			$resizeresult = imsanity_image_resize( $source_image, $neww, $newh, apply_filters( 'imsanity_crop_image', false ) );

			if ( $resizeresult && ! is_wp_error( $resizeresult ) ) {
				$newpath = $resizeresult;

				$new_type = imsanity_mimetype( $newpath );
				if ( $new_type && $new_type !== $ftype ) {
					// The resized image is a different format,
					// keep the old one and just get rid of the resized image.
					imsanity_debug( "mime type changed from $ftype to $new_type, not allowed for existing images" );
					if ( is_file( $newpath ) ) {
						unlink( $newpath );
					}
					$results = array(
						'success' => false,
						'id'      => $id,
						/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
						'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $meta['file'], esc_html__( 'File format/mime type was changed', 'imsanity' ) ),
					);
				} elseif ( $newpath !== $oldpath && is_file( $newpath ) && filesize( $newpath ) < filesize( $oldpath ) ) {
					// we saved some file space. remove original and replace with resized image.
					imsanity_debug( "$newpath is smaller, hurrah!" );
					unlink( $oldpath );
					rename( $newpath, $oldpath );
					$meta['width']  = $neww;
					$meta['height'] = $newh;

					$update_meta = true;

					$results = array(
						'success' => true,
						'id'      => $id,
						/* translators: 1: File-name of the image 2: the image width in pixels 3: the image height in pixels */
						'message' => sprintf( esc_html__( 'OK: %1$s resized to %2$s x %3$s', 'imsanity' ), $meta['file'], $neww . 'w', $newh . 'h' ),
					);
				} elseif ( $newpath !== $oldpath ) {
					// the resized image is actually bigger in filesize (most likely due to jpg quality).
					// keep the old one and just get rid of the resized image.
					imsanity_debug( "$newpath is larger than $oldpath, bummer..." );
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
					imsanity_debug( "$newpath === $oldpath, strange?" );
					$results = array(
						'success' => false,
						'id'      => $id,
						/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
						'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $meta['file'], esc_html__( 'Unknown error, resizing function returned the same filename', 'imsanity' ) ),
					);
				}
			} elseif ( false === $resizeresult ) {
				imsanity_debug( 'wp_get_image_editor likely missing, no resize result, and no error' );
				$results = array(
					'success' => false,
					'id'      => $id,
					/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
					'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $meta['file'], esc_html__( 'wp_get_image_editor missing', 'imsanity' ) ),
				);
			} else {
				imsanity_debug( 'image editor returned an error: ' . $resizeresult->get_error_message() );
				$results = array(
					'success' => false,
					'id'      => $id,
					/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
					'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imsanity' ), $meta['file'], htmlentities( $resizeresult->get_error_message() ) ),
				);
			}
		} else {
			imsanity_debug( "$oldpath is already small enough: $oldw x $oldh" );
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
			clearstatcache();
			if ( ! empty( $oldpath ) && is_file( $oldpath ) ) {
				$meta['filesize'] = filesize( $oldpath );
			}
			wp_update_attachment_metadata( $id, $meta );
			do_action( 'imsanity_post_process_attachment', $id, $meta );
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
 * Find the path to a backed-up original (not the full-size version like the core WP function).
 *
 * @param int    $id The attachment ID number.
 * @param string $image_file The path to a scaled image file.
 * @param array  $meta The attachment metadata. Optional, default to null.
 * @return bool True on success, false on failure.
 */
function imsanity_get_original_image_path( $id, $image_file = '', $meta = null ) {
	$id = (int) $id;
	if ( empty( $id ) ) {
		return false;
	}
	if ( ! wp_attachment_is_image( $id ) ) {
		return false;
	}
	if ( is_null( $meta ) ) {
		$meta = wp_get_attachment_metadata( $id );
	}
	if ( empty( $image_file ) ) {
		$image_file = get_attached_file( $id, true );
	}
	if ( empty( $image_file ) || ! is_iterable( $meta ) || empty( $meta['original_image'] ) ) {
		return false;
	}

	return trailingslashit( dirname( $image_file ) ) . wp_basename( $meta['original_image'] );
}

/**
 * Remove the backed-up original_image stored by WP 5.3+.
 *
 * @param int   $id The attachment ID number.
 * @param array $meta The attachment metadata. Optional, default to null.
 * @return bool|array Returns meta if modified, false otherwise (even if an "unlinked" original is removed).
 */
function imsanity_remove_original_image( $id, $meta = null ) {
	imsanity_debug( __FUNCTION__ );
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
		$original_image = imsanity_get_original_image_path( $id, '', $meta );
		imsanity_debug( "attempting to remove original image at $original_image" );
		if ( $original_image && is_file( $original_image ) && is_writable( $original_image ) ) {
			imsanity_debug( 'original is writable, unlinking!' );
			unlink( $original_image );
		}
		clearstatcache();
		if ( empty( $original_image ) || ! is_file( $original_image ) ) {
			imsanity_debug( 'removal successful, updating meta' );
			unset( $meta['original_image'] );
			return $meta;
		}
	} elseif ( empty( $meta['original_image'] ) ) {
		imsanity_debug( 'no original_image meta found, nothing to remove' );
	} elseif ( ! imsanity_get_option( 'imsanity_delete_originals', false ) ) {
		imsanity_debug( 'delete_originals option not enabled, not removing' );
	} elseif ( ! function_exists( 'wp_get_original_image_path' ) ) {
		imsanity_debug( 'wp_get_original_image_path function does not exist, cannot remove' );
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
 * @return mixed WP_Error on failure. String with new destination path.
 */
function imsanity_image_resize( $file, $max_w, $max_h, $crop = false, $suffix = null, $dest_path = null ) {
	imsanity_debug( __FUNCTION__ );

	if ( function_exists( 'wp_get_image_editor' ) ) {
		imsanity_debug( "resizing $file to $max_w x $max_h" );
		if ( $crop ) {
			imsanity_debug( ' cropping enabled' );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			imsanity_debug( 'get editor error: ' . $editor->get_error_message() );
			return $editor;
		}

		// Default is 82 for JPG, can be anything from 1-100, though the extremes are kind of, well, extreme...
		$quality = imsanity_jpg_quality();
		$ftype   = imsanity_quick_mimetype( $file );
		if ( 'image/webp' === $ftype ) {
			$quality = imsanity_webp_quality();
		} elseif ( 'image/avif' === $ftype ) {
			$quality = imsanity_avif_quality();
		}

		// Return 1 to override auto-rotate.
		$orientation = (int) apply_filters( 'imsanity_orientation', imsanity_get_orientation( $file, $ftype ) );
		// Try to correct for auto-rotation if the info is available.
		switch ( $orientation ) {
			case 3:
				imsanity_debug( 'rotating 180' );
				$editor->rotate( 180 );
				break;
			case 6:
				imsanity_debug( 'rotating -90' );
				$editor->rotate( -90 );
				break;
			case 8:
				imsanity_debug( 'rotating 90' );
				$editor->rotate( 90 );
				break;
		}

		$resized = $editor->resize( $max_w, $max_h, $crop );
		if ( is_wp_error( $resized ) ) {
			imsanity_debug( 'resize error: ' . $resized->get_error_message() );
			return $resized;
		}

		$dest_file = $editor->generate_filename( $suffix, $dest_path );

		// Make sure that the destination file does not exist.
		if ( file_exists( $dest_file ) ) {
			$dest_file = $editor->generate_filename( 'TMP', $dest_path );
		}
		imsanity_debug( "saving resized image to $dest_file with quality $quality" );

		$editor->set_quality( min( 92, $quality ) );

		// If Modern Image Formats is active, but fallback option is disabled, IMSANITY_ALLOW_CONVERSION will be set to allow AVIF/WebP conversion.
		// Otherwise don't allow conversion by any plugin at this stage--MIF will do it later during thumbnail generation.
		if ( defined( 'IMSANITY_ALLOW_CONVERSION' ) && IMSANITY_ALLOW_CONVERSION ) {
			imsanity_debug( 'Modern Image Formats detected, but no fallback option, conversion allowed' );
			add_filter( 'wp_editor_set_quality', 'imsanity_editor_quality', 11, 2 );
			$saved = $editor->save( $dest_file );
			remove_filter( 'wp_editor_set_quality', 'imsanity_editor_quality', 11 );
		} else {
			imsanity_debug( "passing mime type $ftype to prevent conversion by Modern Image Formats (or any other plugin)" );
			remove_all_filters( 'image_editor_output_format' );
			$saved = $editor->save( $dest_file, $ftype );
		}

		if ( is_wp_error( $saved ) ) {
			imsanity_debug( 'save error: ' . $saved->get_error_message() );
			return $saved;
		}

		if ( ! empty( $saved['path'] ) && $saved['path'] !== $dest_file && is_file( $saved['path'] ) ) {
			$dest_file = $saved['path'];
		}
		imsanity_debug( "resized image saved to $dest_file" );
		return $dest_file;
	}
	return false;
}
