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
Version: 2.8.2
Requires at least: 5.5
Requires PHP: 7.2
Author URI: https://ewww.io/about/
License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMSANITY_VERSION', '2.8.2' );
define( 'IMSANITY_SCHEMA_VERSION', '1.1' );

define( 'IMSANITY_DEFAULT_MAX_WIDTH', 1920 );
define( 'IMSANITY_DEFAULT_MAX_HEIGHT', 1920 );
define( 'IMSANITY_DEFAULT_BMP_TO_JPG', true );
define( 'IMSANITY_DEFAULT_PNG_TO_JPG', false );
define( 'IMSANITY_DEFAULT_QUALITY', 82 );

define( 'IMSANITY_SOURCE_POST', 1 );
define( 'IMSANITY_SOURCE_LIBRARY', 2 );
define( 'IMSANITY_SOURCE_OTHER', 4 );

if ( ! defined( 'IMSANITY_AJAX_MAX_RECORDS' ) ) {
	define( 'IMSANITY_AJAX_MAX_RECORDS', 250 );
}

/**
 * The full path of the main plugin file.
 *
 * @var string IMSANITY_PLUGIN_FILE
 */
define( 'IMSANITY_PLUGIN_FILE', __FILE__ );
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
require_once( plugin_dir_path( __FILE__ ) . 'libs/utils.php' );
require_once( plugin_dir_path( __FILE__ ) . 'settings.php' );
require_once( plugin_dir_path( __FILE__ ) . 'ajax.php' );
require_once( plugin_dir_path( __FILE__ ) . 'media.php' );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once( plugin_dir_path( __FILE__ ) . 'class-imsanity-cli.php' );
}

/**
 * Use the EWWW IO debugging functions (if available).
 *
 * @param string $message A message to send to the debugger.
 */
function imsanity_debug( $message ) {
	if ( function_exists( 'ewwwio_debug_message' ) ) {
		if ( ! is_string( $message ) ) {
			if ( function_exists( 'print_r' ) ) {
				$message = print_r( $message, true );
			} else {
				$message = 'not a string, print_r disabled';
			}
		}
		ewwwio_debug_message( $message );
		if ( function_exists( 'ewww_image_optimizer_debug_log' ) ) {
			ewww_image_optimizer_debug_log();
		}
	}
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
	$w = imsanity_get_option( 'imsanity_max_width', IMSANITY_DEFAULT_MAX_WIDTH );
	$h = imsanity_get_option( 'imsanity_max_height', IMSANITY_DEFAULT_MAX_HEIGHT );

	switch ( $source ) {
		case IMSANITY_SOURCE_POST:
			break;
		case IMSANITY_SOURCE_LIBRARY:
			$w = imsanity_get_option( 'imsanity_max_width_library', $w );
			$h = imsanity_get_option( 'imsanity_max_height_library', $h );
			break;
		default:
			$w = imsanity_get_option( 'imsanity_max_width_other', $w );
			$h = imsanity_get_option( 'imsanity_max_height_other', $h );
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

	// If "noresize" is included in the filename then we will bypass imsanity scaling.
	if ( strpos( $params['file'], 'noresize' ) !== false ) {
		return $params;
	}

	if ( apply_filters( 'imsanity_skip_image', false, $params['file'] ) ) {
		return $params;
	}

	// If preferences specify so then we can convert an original bmp or png file into jpg.
	if ( ( 'image/bmp' === $params['type'] || 'image/x-ms-bmp' === $params['type'] ) && imsanity_get_option( 'imsanity_bmp_to_jpg', IMSANITY_DEFAULT_BMP_TO_JPG ) ) {
		$params = imsanity_convert_to_jpg( 'bmp', $params );
	}

	if ( 'image/png' === $params['type'] && imsanity_get_option( 'imsanity_png_to_jpg', IMSANITY_DEFAULT_PNG_TO_JPG ) ) {
		$params = imsanity_convert_to_jpg( 'png', $params );
	}

	// Make sure this is a type of image that we want to convert and that it exists.
	$oldpath = $params['file'];

	// Let folks filter the allowed mime-types for resizing.
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

		// figure out where the upload is coming from.
		$source = imsanity_get_source();

		$maxw             = IMSANITY_DEFAULT_MAX_WIDTH;
		$maxh             = IMSANITY_DEFAULT_MAX_HEIGHT;
		$max_width_height = imsanity_get_max_width_height( $source );
		if ( is_array( $max_width_height ) && 2 === count( $max_width_height ) ) {
			list( $maxw, $maxh ) = $max_width_height;
		}

		list( $oldw, $oldh ) = getimagesize( $oldpath );

		if ( ( $oldw > $maxw + 1 && $maxw > 0 ) || ( $oldh > $maxh + 1 && $maxh > 0 ) ) {
			$quality = imsanity_get_option( 'imsanity_quality', IMSANITY_DEFAULT_QUALITY );

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
			$resizeresult        = imsanity_image_resize( $oldpath, $neww, $newh, apply_filters( 'imsanity_crop_image', false ), null, null, $quality );
			$ewww_preempt_editor = $original_preempt;

			if ( $resizeresult && ! is_wp_error( $resizeresult ) ) {
				$newpath = $resizeresult;

				if ( is_file( $newpath ) && filesize( $newpath ) < filesize( $oldpath ) ) {
					// We saved some file space. remove original and replace with resized image.
					unlink( $oldpath );
					rename( $newpath, $oldpath );
				} elseif ( is_file( $newpath ) ) {
					// The resized image is actually bigger in filesize (most likely due to jpg quality).
					// Keep the old one and just get rid of the resized image.
					unlink( $newpath );
				}
			} elseif ( false === $resizeresult ) {
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
			} else {
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

	if ( apply_filters( 'imsanity_disable_convert', false, $type, $params ) ) {
		return $params;
	}

	$img = null;

	if ( 'bmp' === $type ) {
		if ( ! function_exists( 'imagecreatefrombmp' ) ) {
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
// Checks for WebP support and adds it to the allowed mime types.
add_filter( 'imsanity_allowed_mimes', 'imsanity_add_webp_support' );
