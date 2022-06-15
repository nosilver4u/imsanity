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
 * Searches for up to 250 images that are candidates for resize and renders them
 * to the browser as a json array, then dies
 */
function imsanity_get_images() {
	if ( ! current_user_can( 'activate_plugins' ) || empty( $_REQUEST['_wpnonce'] ) ) {
		die(
			wp_json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Administrator permission is required', 'imsanity' ),
				)
			)
		);
	}
	if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imsanity-bulk' ) && ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imsanity-manual-resize' ) ) {
		die(
			wp_json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Access token has expired, please reload the page.', 'imsanity' ),
				)
			)
		);
	}

	$resume_id = ! empty( $_POST['resume_id'] ) ? (int) $_POST['resume_id'] : PHP_INT_MAX;
	global $wpdb;
	// Load up all the image attachments we can find.
	$attachments = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID < %d AND post_type = 'attachment' AND post_mime_type LIKE %s ORDER BY ID DESC", $resume_id, '%%image%%' ) );
	array_walk( $attachments, 'intval' );
	die( wp_json_encode( $attachments ) );
}

/**
 * Resizes the image with the given id according to the configured max width and height settings
 * renders a json response indicating success/failure and dies
 */
function imsanity_ajax_resize() {
	if ( ! current_user_can( 'activate_plugins' ) || empty( $_REQUEST['_wpnonce'] ) ) {
		die(
			wp_json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Administrator permission is required', 'imsanity' ),
				)
			)
		);
	}
	if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imsanity-bulk' ) && ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imsanity-manual-resize' ) ) {
		die(
			wp_json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Access token has expired, please reload the page.', 'imsanity' ),
				)
			)
		);
	}

	$id = ! empty( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	if ( ! $id ) {
		die(
			wp_json_encode(
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

	die( wp_json_encode( $results ) );
}

/**
 * Resizes the image with the given id according to the configured max width and height settings
 * renders a json response indicating success/failure and dies
 */
function imsanity_ajax_remove_original() {
	if ( ! current_user_can( 'activate_plugins' ) || empty( $_REQUEST['_wpnonce'] ) ) {
		die(
			wp_json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Administrator permission is required', 'imsanity' ),
				)
			)
		);
	}
	if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imsanity-bulk' ) && ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imsanity-manual-resize' ) ) {
		die(
			wp_json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Access token has expired, please reload the page.', 'imsanity' ),
				)
			)
		);
	}

	$id = ! empty( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	if ( ! $id ) {
		die(
			wp_json_encode(
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
		die( wp_json_encode( array( 'success' => true ) ) );
	}

	die( wp_json_encode( array( 'success' => false ) ) );
}

/**
 * Finalizes the resizing process.
 */
function imsanity_ajax_finish() {
	if ( ! current_user_can( 'activate_plugins' ) || empty( $_REQUEST['_wpnonce'] ) ) {
		die(
			wp_json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Administrator permission is required', 'imsanity' ),
				)
			)
		);
	}
	if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imsanity-bulk' ) && ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imsanity-manual-resize' ) ) {
		die(
			wp_json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Access token has expired, please reload the page.', 'imsanity' ),
				)
			)
		);
	}

	update_option( 'imsanity_resume_id', 0, false );

	die();
}
