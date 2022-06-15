<?php
/**
 * Imsanity Media Library functions.
 *
 * @package Imsanity
 */

/**
 * Add column header for Imsanity info/actions in the media library listing.
 *
 * @param array $columns A list of columns in the media library.
 * @return array The new list of columns.
 */
function imsanity_media_columns( $columns ) {
	$columns['imsanity'] = esc_html__( 'Imsanity', 'imsanity' );
	return $columns;
}

/**
 * Print Imsanity info/actions in the media library.
 *
 * @param string $column_name The name of the column being displayed.
 * @param int    $id The attachment ID number.
 * @param array  $meta Optional. The attachment metadata. Default null.
 */
function imsanity_custom_column( $column_name, $id, $meta = null ) {
	// Once we get to the EWWW IO custom column.
	if ( 'imsanity' === $column_name ) {
		$id = (int) $id;
		if ( is_null( $meta ) ) {
			// Retrieve the metadata.
			$meta = wp_get_attachment_metadata( $id );
		}
		echo '<div id="imsanity-media-status-' . (int) $id . '" class="imsanity-media-status" data-id="' . (int) $id . '">';
		if ( false && function_exists( 'print_r' ) ) {
			$print_meta = print_r( $meta, true );
			$print_meta = preg_replace( array( '/ /', '/\n+/' ), array( '&nbsp;', '<br />' ), $print_meta );
			echo "<div id='imsanity-debug-meta-" . (int) $id . "' style='font-size: 10px;padding: 10px;margin:3px -10px 10px;line-height: 1.1em;'>" . wp_kses_post( $print_meta ) . '</div>';
		}
		if ( is_array( $meta ) && ! empty( $meta['file'] ) && false !== strpos( $meta['file'], 'https://images-na.ssl-images-amazon.com' ) ) {
			echo esc_html__( 'Amazon-hosted image', 'imsanity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) && ! empty( $meta['cloudinary'] ) ) {
			echo esc_html__( 'Cloudinary image', 'imsanity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) & class_exists( 'WindowsAzureStorageUtil' ) && ! empty( $meta['url'] ) ) {
			echo '<div>' . esc_html__( 'Azure Storage image', 'imsanity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) && class_exists( 'Amazon_S3_And_CloudFront' ) && preg_match( '/^(http|s3|gs)\w*:/', get_attached_file( $id ) ) ) {
			echo '<div>' . esc_html__( 'Offloaded Media', 'imsanity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) && class_exists( 'S3_Uploads' ) && preg_match( '/^(http|s3|gs)\w*:/', get_attached_file( $id ) ) ) {
			echo '<div>' . esc_html__( 'Amazon S3 image', 'imsanity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) & class_exists( 'wpCloud\StatelessMedia' ) && ! empty( $meta['gs_link'] ) ) {
			echo '<div>' . esc_html__( 'WP Stateless image', 'imsanity' ) . '</div>';
			return;
		}
		$file_path = imsanity_attachment_path( $meta, $id );
		if ( is_array( $meta ) & function_exists( 'ilab_get_image_sizes' ) && ! empty( $meta['s3'] ) && empty( $file_path ) ) {
			echo esc_html__( 'Media Cloud image', 'imsanity' ) . '</div>';
			return;
		}
		// If the file does not exist.
		if ( empty( $file_path ) ) {
			echo esc_html__( 'Could not retrieve file path.', 'imsanity' ) . '</div>';
			return;
		}
		// Let folks filter the allowed mime-types for resizing.
		$allowed_types = apply_filters( 'imsanity_allowed_mimes', array( 'image/png', 'image/gif', 'image/jpeg' ), $file_path );
		if ( is_string( $allowed_types ) ) {
			$allowed_types = array( $allowed_types );
		} elseif ( ! is_array( $allowed_types ) ) {
			$allowed_types = array();
		}
		$ftype = imsanity_quick_mimetype( $file_path );
		if ( ! in_array( $ftype, $allowed_types, true ) ) {
			echo '</div>';
			return;
		}

		list( $imagew, $imageh ) = getimagesize( $file_path );
		if ( empty( $imagew ) || empty( $imageh ) ) {
			$imagew = $meta['width'];
			$imageh = $meta['height'];
		}

		if ( empty( $imagew ) || empty( $imageh ) ) {
			echo esc_html( 'Unknown dimensions', 'imsanity' );
			return;
		}
		echo '<div>' . (int) $imagew . 'w x ' . (int) $imageh . 'h</div>';

		$maxw = imsanity_get_option( 'imsanity_max_width', IMSANITY_DEFAULT_MAX_WIDTH );
		$maxh = imsanity_get_option( 'imsanity_max_height', IMSANITY_DEFAULT_MAX_HEIGHT );
		if ( $imagew > $maxw || $imageh > $maxh ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				$manual_nonce = wp_create_nonce( 'imsanity-manual-resize' );
				// Give the user the option to optimize the image right now.
				printf(
					'<div><button class="imsanity-manual-resize button button-secondary" data-id="%1$d" data-nonce="%2$s">%3$s</button>',
					(int) $id,
					esc_attr( $manual_nonce ),
					esc_html__( 'Resize Image', 'imsanity' )
				);
			}
		} elseif ( current_user_can( 'activate_plugins' ) && imsanity_get_option( 'imsanity_delete_originals', false ) && ! empty( $meta['original_image'] ) && function_exists( 'wp_get_original_image_path' ) ) {
			$original_image = wp_get_original_image_path( $id );
			if ( empty( $original_image ) || ! is_file( $original_image ) ) {
				$original_image = wp_get_original_image_path( $id, true );
			}
			if ( ! empty( $original_image ) && is_file( $original_image ) && is_writable( $original_image ) ) {
				$link_text = __( 'Remove Original', 'imsanity' );
			} else {
				$link_text = __( 'Remove Original Link', 'imsanity' );
			}
			$manual_nonce = wp_create_nonce( 'imsanity-manual-resize' );
			// Give the user the option to optimize the image right now.
			printf(
				'<div><button class="imsanity-manual-remove-original button button-secondary" data-id="%1$d" data-nonce="%2$s">%3$s</button>',
				(int) $id,
				esc_attr( $manual_nonce ),
				esc_html( $link_text )
			);
		}
		echo '</div>';
	}
}
