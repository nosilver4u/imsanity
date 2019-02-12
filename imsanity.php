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
Text Domain: imsanity
Version: 2.4.2
Author URI: https://ewww.io/
License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMSANITY_VERSION', '2.4.2' );
define( 'IMSANITY_SCHEMA_VERSION', '1.1' );

define( 'IMSANITY_DEFAULT_MAX_WIDTH', 2048 );
define( 'IMSANITY_DEFAULT_MAX_HEIGHT', 2048 );
define( 'IMSANITY_DEFAULT_BMP_TO_JPG', 1 );
define( 'IMSANITY_DEFAULT_PNG_TO_JPG', 0 );
define( 'IMSANITY_DEFAULT_QUALITY', 82 );

define( 'IMSANITY_SOURCE_POST', 1 );
define( 'IMSANITY_SOURCE_LIBRARY', 2 );
define( 'IMSANITY_SOURCE_OTHER', 4 );

if ( ! defined( 'IMSANITY_AJAX_MAX_RECORDS' ) ) {
	define( 'IMSANITY_AJAX_MAX_RECORDS', 250 );
}

/**
 * Load translations for Imsanity.
 */
function imsanity_init() {
	load_plugin_textdomain( 'imsanity', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

/**
 * Import supporting libraries.
 */
include_once( plugin_dir_path( __FILE__ ) . 'libs/utils.php' );
include_once( plugin_dir_path( __FILE__ ) . 'settings.php' );
include_once( plugin_dir_path( __FILE__ ) . 'ajax.php' );

/**
 * Inspects the request and determines where the upload came from.
 *
 * @return IMSANITY_SOURCE_POST | IMSANITY_SOURCE_LIBRARY | IMSANITY_SOURCE_OTHER
 */
function imsanity_get_source() {
	$id     = array_key_exists( 'post_id', $_REQUEST ) ? (int) $_REQUEST['post_id'] : '';
	$action = array_key_exists( 'action', $_REQUEST ) ? $_REQUEST['action'] : '';

	// A post_id indicates image is attached to a post.
	if ( $id > 0 ) {
		return IMSANITY_SOURCE_POST;
	}

	// If the referrer is the post editor, that's a good indication the image is attached to a post.
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) && strpos( $_SERVER['HTTP_REFERER'], '/post.php' ) ) {
		return IMSANITY_SOURCE_POST;
	}

	// Post_id of 0 is 3.x otherwise use the action parameter.
	if ( 0 === $id || 'upload-attachment' == $action ) {
		return IMSANITY_SOURCE_LIBRARY;
	}

	// We don't know where this one came from but $_REQUEST['_wp_http_referer'] may contain info.
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

	return array( $w, $h );
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

	// If preferences specify so then we can convert an original bmp or png file into jpg.
	if ( 'image/bmp' == $params['type'] && imsanity_get_option( 'imsanity_bmp_to_jpg', IMSANITY_DEFAULT_BMP_TO_JPG ) ) {
		$params = imsanity_convert_to_jpg( 'bmp', $params );
	}

	if ( 'image/png' == $params['type'] && imsanity_get_option( 'imsanity_png_to_jpg', IMSANITY_DEFAULT_PNG_TO_JPG ) ) {
		$params = imsanity_convert_to_jpg( 'png', $params );
	}

	// Make sure this is a type of image that we want to convert and that it exists.
	$oldpath = $params['file'];

	if ( ( ! is_wp_error( $params ) ) && is_file( $oldpath ) && is_readable( $oldpath ) && is_writable( $oldpath ) && filesize( $oldpath ) > 0 && in_array( $params['type'], array( 'image/png', 'image/gif', 'image/jpeg' ) ) ) {

		// figure out where the upload is coming from.
		$source = imsanity_get_source();

		list( $maxw,$maxh ) = imsanity_get_max_width_height( $source );

		list( $oldw, $oldh ) = getimagesize( $oldpath );

		if ( ( $oldw > $maxw && $maxw > 0 ) || ( $oldh > $maxh && $maxh > 0 ) ) {
			$quality = imsanity_get_option( 'imsanity_quality', IMSANITY_DEFAULT_QUALITY );

			$ftype       = imsanity_quick_mimetype( $oldpath );
			$orientation = imsanity_get_orientation( $oldpath, $ftype );
			// If we are going to rotate the image 90 degrees during the resize, swap the existing image dimensions.
			if ( 6 == $orientation || 8 == $orientation ) {
				$old_oldw = $oldw;
				$oldw     = $oldh;
				$oldh     = $old_oldw;
			}

			if ( $oldw > $maxw && $maxw > 0 && $oldh > $maxh && $maxh > 0 && apply_filters( 'imsanity_crop_image', false ) ) {
				$neww = $maxw;
				$newh = $maxh;
			} else {
				list( $neww, $newh ) = wp_constrain_dimensions( $oldw, $oldh, $maxw, $maxh );
			}

			remove_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
			$resizeresult = imsanity_image_resize( $oldpath, $neww, $newh, apply_filters( 'imsanity_crop_image', false ), null, null, $quality );
			if ( function_exists( 'ewww_image_optimizer_load_editor' ) ) {
				add_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
			}

			if ( $resizeresult && ! is_wp_error( $resizeresult ) ) {
				$newpath = $resizeresult;

				if ( is_file( $newpath ) && filesize( $newpath ) < filesize( $oldpath ) ) {
					// we saved some file space. remove original and replace with resized image.
					unlink( $oldpath );
					rename( $newpath, $oldpath );
				} elseif ( is_file( $newpath ) ) {
					// theresized image is actually bigger in filesize (most likely due to jpg quality).
					// keep the old one and just get rid of the resized image.
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

	$img = null;

	if ( 'bmp' == $type ) {
		include_once( 'libs/imagecreatefrombmp.php' );
		$img = imagecreatefrombmp( $params['file'] );
	} elseif ( 'png' == $type ) {
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
	$oldfilename = basename( $params['file'] );
	$newfilename = basename( str_ireplace( '.' . $type, '.jpg', $oldfilename ) );
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

/* add filters to hook into uploads */
add_filter( 'wp_handle_upload', 'imsanity_handle_upload' );
add_action( 'plugins_loaded', 'imsanity_init' );
