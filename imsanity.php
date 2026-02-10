<?php
/**
 * Main file for Imsanity plugin.
 *
 * This file includes the core of Imsanity and the top-level image handler.
 *
 * @link https://wordpress.org/plugins/imsanity/
 * @package Imsanity
 */

/*
Plugin Name: Imsanity
Plugin URI: https://wordpress.org/plugins/imsanity/
Description: Imsanity stops insanely huge image uploads
Author: Exactly WWW
Domain Path: /languages
Version: 2.9.0
Requires at least: 6.6
Requires PHP: 7.4
Author URI: https://ewww.io/about/
License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMSANITY_VERSION', '2.9.0' );
define( 'IMSANITY_SCHEMA_VERSION', '1.1' );

define( 'IMSANITY_DEFAULT_MAX_WIDTH', 1920 );
define( 'IMSANITY_DEFAULT_MAX_HEIGHT', 1920 );
define( 'IMSANITY_DEFAULT_BMP_TO_JPG', true );
define( 'IMSANITY_DEFAULT_PNG_TO_JPG', false );
define( 'IMSANITY_DEFAULT_QUALITY', 82 );
define( 'IMSANITY_DEFAULT_AVIF_QUALITY', 86 );
define( 'IMSANITY_DEFAULT_WEBP_QUALITY', 86 );

define( 'IMSANITY_SOURCE_POST', 1 );
define( 'IMSANITY_SOURCE_LIBRARY', 2 );
define( 'IMSANITY_SOURCE_OTHER', 4 );

/**
 * The full path of the main plugin file.
 *
 * @var string IMSANITY_PLUGIN_FILE
 */
define( 'IMSANITY_PLUGIN_FILE', __FILE__ );

/**
 * The directory path of the main plugin file.
 *
 * @var string IMSANITY_PLUGIN_DIR
 */
define( 'IMSANITY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * The path of the main plugin file, relative to the plugins/ folder.
 *
 * @var string IMSANITY_PLUGIN_FILE_REL
 */
define( 'IMSANITY_PLUGIN_FILE_REL', plugin_basename( __FILE__ ) );

/**
 * Load translations for Imsanity.
 */
function imsanity_init() {
	load_plugin_textdomain( 'imsanity', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

/**
 * Import supporting libraries.
 */
require_once plugin_dir_path( __FILE__ ) . 'libs/debug.php';
require_once plugin_dir_path( __FILE__ ) . 'libs/utils.php';
require_once plugin_dir_path( __FILE__ ) . 'settings.php';
require_once plugin_dir_path( __FILE__ ) . 'ajax.php';
require_once plugin_dir_path( __FILE__ ) . 'media.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once plugin_dir_path( __FILE__ ) . 'class-imsanity-cli.php';
}

/**
 * Inspects the request and determines where the upload came from.
 *
 * @return IMSANITY_SOURCE_POST | IMSANITY_SOURCE_LIBRARY | IMSANITY_SOURCE_OTHER
 */
function imsanity_get_source() {
	imsanity_debug( __FUNCTION__ );
	$id     = array_key_exists( 'post_id', $_REQUEST ) ? (int) $_REQUEST['post_id'] : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$action = ! empty( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	imsanity_debug( "getting source for id=$id and action=$action" );

	// Uncomment this (and remove the trailing .) to temporarily check the full $_SERVER vars.
	// imsanity_debug( $_SERVER );.
	$referer = '';
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$referer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		imsanity_debug( "http_referer: $referer" );
	}

	$request_uri = wp_referer_field( false );
	imsanity_debug( "request URI: $request_uri" );

	// A post_id indicates image is attached to a post.
	if ( $id > 0 ) {
		imsanity_debug( 'from a post (id)' );
		return IMSANITY_SOURCE_POST;
	}

	// If the referrer is the post editor, that's a good indication the image is attached to a post.
	if ( false !== strpos( $referer, '/post.php' ) ) {
		imsanity_debug( 'from a post.php' );
		return IMSANITY_SOURCE_POST;
	}
	// If the referrer is the (new) post editor, that's a good indication the image is attached to a post.
	if ( false !== strpos( $referer, '/post-new.php' ) ) {
		imsanity_debug( 'from a new post' );
		return IMSANITY_SOURCE_POST;
	}

	// Post_id of 0 is 3.x otherwise use the action parameter.
	if ( 0 === $id || 'upload-attachment' === $action ) {
		imsanity_debug( 'from the library' );
		return IMSANITY_SOURCE_LIBRARY;
	}

	// We don't know where this one came from but $_REQUEST['_wp_http_referer'] may contain info.
	imsanity_debug( 'unknown source' );
	return IMSANITY_SOURCE_OTHER;
}

/**
 * Given the source, returns the max width/height.
 *
 * @example:  list( $w, $h ) = imsanity_get_max_width_height( IMSANITY_SOURCE_LIBRARY );
 * @param int $source One of IMSANITY_SOURCE_POST | IMSANITY_SOURCE_LIBRARY | IMSANITY_SOURCE_OTHER.
 */
function imsanity_get_max_width_height( $source ) {
	$w = (int) imsanity_get_option( 'imsanity_max_width', IMSANITY_DEFAULT_MAX_WIDTH );
	$h = (int) imsanity_get_option( 'imsanity_max_height', IMSANITY_DEFAULT_MAX_HEIGHT );

	switch ( $source ) {
		case IMSANITY_SOURCE_POST:
			break;
		case IMSANITY_SOURCE_LIBRARY:
			$w = (int) imsanity_get_option( 'imsanity_max_width_library', $w );
			$h = (int) imsanity_get_option( 'imsanity_max_height_library', $h );
			break;
		default:
			$w = (int) imsanity_get_option( 'imsanity_max_width_other', $w );
			$h = (int) imsanity_get_option( 'imsanity_max_height_other', $h );
			break;
	}

	// NOTE: filters MUST return an array of 2 items, or the defaults will be used.
	return apply_filters( 'imsanity_get_max_width_height', array( $w, $h ), $source );
}

/**
 * Handler after a file has been uploaded.  If the file is an image, check the size
 * to see if it is too big and, if so, resize and overwrite the original.
 *
 * @param Array $params The parameters submitted with the upload.
 */
function imsanity_handle_upload( $params ) {
	imsanity_debug( __FUNCTION__ );

	if ( empty( $params['file'] ) || empty( $params['type'] ) ) {
		imsanity_debug( 'missing file or type parameter, skipping' );
		return $params;
	}

	// If "noresize" is included in the filename then we will bypass imsanity scaling.
	if ( strpos( $params['file'], 'noresize' ) !== false ) {
		imsanity_debug( "skipping {$params['file']}" );
		return $params;
	}

	if ( apply_filters( 'imsanity_skip_image', false, $params['file'] ) ) {
		imsanity_debug( "skipping {$params['file']} per filter" );
		return $params;
	}

	// If preferences specify so then we can convert an original bmp or png file into jpg.
	if ( ( 'image/bmp' === $params['type'] || 'image/x-ms-bmp' === $params['type'] ) && imsanity_get_option( 'imsanity_bmp_to_jpg', IMSANITY_DEFAULT_BMP_TO_JPG ) ) {
		$params = imsanity_convert_to_jpg( 'bmp', $params );
	}

	if ( 'image/png' === $params['type'] && imsanity_get_option( 'imsanity_png_to_jpg', IMSANITY_DEFAULT_PNG_TO_JPG ) ) {
		$params = imsanity_convert_to_jpg( 'png', $params );
	}

	// Store the path for reference in case $params is modified.
	$oldpath = $params['file'];

	// Let folks filter the allowed mime-types for resizing.
	// Also allows conditional support for WebP and AVIF if the server supports it.
	$allowed_types = apply_filters( 'imsanity_allowed_mimes', array( 'image/png', 'image/gif', 'image/jpeg' ), $oldpath );
	if ( is_string( $allowed_types ) ) {
		$allowed_types = array( $allowed_types );
	} elseif ( ! is_array( $allowed_types ) ) {
		$allowed_types = array();
	}

	if (
		( ! is_wp_error( $params ) ) &&
		is_file( $oldpath ) &&
		is_readable( $oldpath ) &&
		is_writable( $oldpath ) &&
		filesize( $oldpath ) > 0 &&
		in_array( $params['type'], $allowed_types, true )
	) {
		// If the Modern Image Formats plugin is active but fallback mode is disabled, permit conversion to AVIF/WebP during upload by defining IMSANITY_ALLOW_CONVERSION.
		// Otherwise, no conversion should be allowed at all. The upload handler will still check for conversion and work with it if it happens somehow.
		if ( ! defined( 'IMSANITY_ALLOW_CONVERSION' ) && function_exists( 'webp_uploads_is_fallback_enabled' ) && ! webp_uploads_is_fallback_enabled() ) {
			define( 'IMSANITY_ALLOW_CONVERSION', true );
		}

		// figure out where the upload is coming from.
		$source = imsanity_get_source();

		$maxw             = IMSANITY_DEFAULT_MAX_WIDTH;
		$maxh             = IMSANITY_DEFAULT_MAX_HEIGHT;
		$max_width_height = imsanity_get_max_width_height( $source );
		if ( is_array( $max_width_height ) && 2 === count( $max_width_height ) ) {
			list( $maxw, $maxh ) = $max_width_height;
		}
		$maxw = (int) $maxw;
		$maxh = (int) $maxh;

		$dimensions = getimagesize( $oldpath );
		if ( is_array( $dimensions ) && count( $dimensions ) >= 2 ) {
			$oldw = $dimensions[0];
			$oldh = $dimensions[1];
		} else {
			imsanity_debug( "could not get dimensions for $oldpath, skipping" );
			return $params;
		}

		if ( ( $oldw > $maxw + 1 && $maxw > 0 ) || ( $oldh > $maxh + 1 && $maxh > 0 ) ) {

			$ftype       = imsanity_quick_mimetype( $oldpath );
			$orientation = imsanity_get_orientation( $oldpath, $ftype );
			// If we are going to rotate the image 90 degrees during the resize, swap the existing image dimensions.
			if ( 6 === (int) $orientation || 8 === (int) $orientation ) {
				$old_oldw = $oldw;
				$oldw     = $oldh;
				$oldh     = $old_oldw;
			}

			if ( $maxw > 0 && $maxh > 0 && $oldw >= $maxw && $oldh >= $maxh && ( $oldh > $maxh || $oldw > $maxw ) && apply_filters( 'imsanity_crop_image', false ) ) {
				$neww = $maxw;
				$newh = $maxh;
			} else {
				list( $neww, $newh ) = wp_constrain_dimensions( $oldw, $oldh, $maxw, $maxh );
			}

			global $ewww_preempt_editor;
			if ( ! isset( $ewww_preempt_editor ) ) {
				$ewww_preempt_editor = false;
			}
			$original_preempt    = $ewww_preempt_editor;
			$ewww_preempt_editor = true;
			$resizeresult        = imsanity_image_resize( $oldpath, $neww, $newh, apply_filters( 'imsanity_crop_image', false ) );
			$ewww_preempt_editor = $original_preempt;

			if ( $resizeresult && ! is_wp_error( $resizeresult ) ) {
				$newpath  = $resizeresult;
				$new_type = $params['type'];

				imsanity_debug( "checking $newpath to see if resize was successful" );
				if ( is_file( $newpath ) && filesize( $newpath ) < filesize( $oldpath ) ) {
					imsanity_debug( 'resized image is smaller, replacing original' );
					// We saved some file space. remove original and replace with resized image.
					$new_type = imsanity_mimetype( $newpath );
					unlink( $oldpath );
					rename( $newpath, $oldpath );
					if ( $new_type && $new_type !== $params['type'] ) {
						imsanity_debug( "mimetype changed from {$params['type']} to $new_type" );
						$params['type'] = $new_type;
						$params['file'] = imsanity_update_extension( $oldpath, $new_type );
						if ( $params['file'] !== $oldpath ) {
							rename( $oldpath, $params['file'] );
						}
						$params['url'] = imsanity_update_extension( $params['url'], $new_type );
						imsanity_debug( "renamed file to match new extension: {$params['file']} / {$params['url']}" );
					}
				} elseif ( is_file( $newpath ) ) {
					imsanity_debug( 'resized image is bigger, discarding' );
					// The resized image is actually bigger in filesize (most likely due to jpg quality).
					// Keep the old one and just get rid of the resized image.
					unlink( $newpath );
				}
			} elseif ( false === $resizeresult ) {
				imsanity_debug( 'resize returned false, unknown error' );
				return $params;
			} elseif ( is_wp_error( $resizeresult ) ) {
				// resize didn't work, likely because the image processing libraries are missing.
				// remove the old image so we don't leave orphan files hanging around.
				unlink( $oldpath );

				$params = wp_handle_upload_error(
					$oldpath,
					sprintf(
						/* translators: 1: error message 2: link to support forums */
						esc_html__( 'Imsanity was unable to resize this image for the following reason: %1$s. If you continue to see this error message, you may need to install missing server components. If you think you have discovered a bug, please report it on the Imsanity support forum: %2$s', 'imsanity' ),
						$resizeresult->get_error_message(),
						'https://wordpress.org/support/plugin/imsanity'
					)
				);
				imsanity_debug( 'resize result is wp_error, should have already output error to log' );
			} else {
				imsanity_debug( 'unknown resize result, inconceivable!' );
				return $params;
			}
		}
	}
	clearstatcache();
	return $params;
}


/**
 * Read in the image file from the params and then save as a new jpg file.
 * if successful, remove the original image and alter the return
 * parameters to return the new jpg instead of the original
 *
 * @param string $type Type of the image to be converted: 'bmp' or 'png'.
 * @param array  $params The upload parameters.
 * @return array altered params
 */
function imsanity_convert_to_jpg( $type, $params ) {
	imsanity_debug( __FUNCTION__ );

	if ( apply_filters( 'imsanity_disable_convert', false, $type, $params ) ) {
		imsanity_debug( "skipping conversion for {$params['file']}" );
		return $params;
	}

	$img = null;

	if ( 'bmp' === $type ) {
		if ( ! function_exists( 'imagecreatefrombmp' ) ) {
			imsanity_debug( 'imagecreatefrombmp does not exist' );
			return $params;
		}
		$img = imagecreatefrombmp( $params['file'] );
	} elseif ( 'png' === $type ) {
		// Prevent converting PNG images with alpha/transparency, unless overridden by the user.
		if ( apply_filters( 'imsanity_skip_alpha', imsanity_has_alpha( $params['file'] ), $params['file'] ) ) {
			return $params;
		}
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return wp_handle_upload_error( $params['file'], esc_html__( 'Imsanity requires the GD library to convert PNG images to JPG', 'imsanity' ) );
		}

		$input = imagecreatefrompng( $params['file'] );
		// convert png transparency to white.
		$img = imagecreatetruecolor( imagesx( $input ), imagesy( $input ) );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
		imagealphablending( $img, true );
		imagecopy( $img, $input, 0, 0, 0, 0, imagesx( $input ), imagesy( $input ) );
	} else {
		return wp_handle_upload_error( $params['file'], esc_html__( 'Unknown image type specified in imsanity_convert_to_jpg', 'imsanity' ) );
	}

	// We need to change the extension from the original to .jpg so we have to ensure it will be a unique filename.
	$uploads     = wp_upload_dir();
	$oldfilename = wp_basename( $params['file'] );
	$newfilename = wp_basename( str_ireplace( '.' . $type, '.jpg', $oldfilename ) );
	$newfilename = wp_unique_filename( $uploads['path'], $newfilename );

	$quality = imsanity_get_option( 'imsanity_quality', IMSANITY_DEFAULT_QUALITY );

	if ( imagejpeg( $img, $uploads['path'] . '/' . $newfilename, $quality ) ) {
		// Conversion succeeded: remove the original bmp & remap the params.
		unlink( $params['file'] );

		$params['file'] = $uploads['path'] . '/' . $newfilename;
		$params['url']  = $uploads['url'] . '/' . $newfilename;
		$params['type'] = 'image/jpeg';
	} else {
		unlink( $params['file'] );

		return wp_handle_upload_error(
			$oldfilename,
			/* translators: %s: the image mime type */
			sprintf( esc_html__( 'Imsanity was unable to process the %s file. If you continue to see this error you may need to disable the conversion option in the Imsanity settings.', 'imsanity' ), $type )
		);
	}

	return $params;
}

// Add filter to hook into uploads.
add_filter( 'wp_handle_upload', 'imsanity_handle_upload' );
// Run necessary actions on init (loading translations mostly).
add_action( 'plugins_loaded', 'imsanity_init' );

// Adds a column to the media library list view to display optimization results.
add_filter( 'manage_media_columns', 'imsanity_media_columns' );
// Outputs the actual column information for each attachment.
add_action( 'manage_media_custom_column', 'imsanity_custom_column', 10, 2 );
// Checks for AVIF support and adds it to the allowed mime types.
add_filter( 'imsanity_allowed_mimes', 'imsanity_add_avif_support' );
// Checks for WebP support and adds it to the allowed mime types.
add_filter( 'imsanity_allowed_mimes', 'imsanity_add_webp_support' );
