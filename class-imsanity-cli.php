<?php
/**
 * Class file for Imsanity_CLI
 *
 * Imsanity_CLI contains an extension for WP-CLI to enable bulk resizing of images via command line.
 *
 * @package Imsanity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Implements wp-cli extension for bulk resizing.
 */
class Imsanity_CLI extends WP_CLI_Command {
	/**
	 * Bulk Resize Images
	 *
	 * ## OPTIONS
	 *
	 * <noprompt>
	 * : do not prompt, just resize everything
	 *
	 * ## EXAMPLES
	 *
	 *     wp-cli imsanity resize --noprompt
	 *
	 * @synopsis [--noprompt]
	 *
	 * @param array $args A numbered array of arguments provided via WP-CLI without option names.
	 * @param array $assoc_args An array of named arguments provided via WP-CLI.
	 */
	function resize( $args, $assoc_args ) {

		// let's get started, shall we?
		// imsanity_init();.
		$maxw = imsanity_get_option( 'imsanity_max_width', IMSANITY_DEFAULT_MAX_WIDTH );
		$maxh = imsanity_get_option( 'imsanity_max_height', IMSANITY_DEFAULT_MAX_HEIGHT );

		if ( empty( $assoc_args['noprompt'] ) ) {
			WP_CLI::warning(
				__( 'Bulk Resize will alter your original images and cannot be undone!', 'imsanity' ) . "\n" .
				__( 'It is HIGHLY recommended that you backup your wp-content/uploads folder before proceeding. You will be prompted before resizing each image.', 'imsanity' ) . "\n" .
				__( 'It is also recommended that you initially resize only 1 or 2 images and verify that everything is working properly before processing your entire library.', 'imsanity' )
			);
		}

		/* translators: 1: width in pixels, 2: height in pixels */
		WP_CLI::line( sprintf( __( 'Resizing images to %1$d x %2$d', 'imsanity' ), $maxw, $maxh ) );

		global $wpdb;
		$attachments = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE (post_type = 'attachment' OR post_type = 'ims_image') AND post_mime_type LIKE '%%image%%' ORDER BY ID DESC" );

		$image_count = count( $attachments );
		if ( ! $image_count ) {
			WP_CLI::success( __( 'There are no images to resize.', 'imsanity' ) );
			return;
		} elseif ( empty( $assoc_args['noprompt'] ) ) {
			WP_CLI::confirm(
				/* translators: %d: number of images */
				sprintf( __( 'There are %d images to check.', 'imsanity' ), $image_count ) .
				' ' . __( 'Continue?', 'imsanity' )
			);
		} else {
			/* translators: %d: number of images */
			WP_CLI::line( sprintf( __( 'There are %d images to check.', 'imsanity' ), $image_count ) );
		}

		$images_finished = 0;
		foreach ( $attachments as $id ) {
			$imagew = false;
			$imageh = false;
			$images_finished++;

			$path = get_attached_file( $id );
			if ( $path ) {
				list( $imagew, $imageh ) = getimagesize( $path );
			}
			if ( empty( $imagew ) || empty( $imageh ) ) {
				continue;
			}

			if ( $imagew <= $maxw && $imageh <= $maxh ) {
				/* translators: %s: File-name of the image */
				WP_CLI::line( sprintf( esc_html__( 'SKIPPED: %s (Resize not required)', 'imsanity' ), $path ) . " -- $imagew x $imageh" );
				continue;
			}

			$confirm = '';
			if ( empty( $assoc_args['noprompt'] ) ) {
				$confirm = \cli\prompt(
					$path . ': ' . $imagew . 'x' . $imageh .
					"\n" . __( 'Resize (Y/n)?', 'imsanity' )
				);
			}
			if ( 'n' === $confirm ) {
				continue;
			}

			$result = imsanity_resize_from_id( $id );

			if ( $result['success'] ) {
				WP_CLI::line( $result['message'] . " $images_finished / $image_count" );
			} else {
				WP_CLI::warning( $result['message'] . " $images_finished / $image_count" );
			}
		}

		// and let the user know we are done.
		WP_CLI::success( __( 'Finished Resizing!', 'imsanity' ) );
	}
}

WP_CLI::add_command( 'imsanity', 'Imsanity_CLI' );
