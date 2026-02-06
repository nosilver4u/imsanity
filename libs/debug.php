<?php
/**
 * Imsanity utility functions.
 *
 * @package Imsanity
 */

// Initialize global variable for debug information/outut.
$imsanity_debug_data = '';

/**
 * Finds the current PHP memory limit or a reasonable default.
 *
 * @return int The memory limit in bytes.
 */
function imsanity_memory_limit() {
	if ( \defined( 'IMSANITY_MEMORY_LIMIT' ) && IMSANITY_MEMORY_LIMIT ) {
		$memory_limit = IMSANITY_MEMORY_LIMIT;
	} elseif ( function_exists( 'ini_get' ) ) {
		$memory_limit = ini_get( 'memory_limit' );
	} else {
		if ( ! defined( 'IMSANITY_MEMORY_LIMIT' ) ) {
			// Conservative default, current usage + 16M.
			$current_memory = memory_get_usage( true );
			$memory_limit   = round( $current_memory / ( 1024 * 1024 ) ) + 16;
			define( 'IMSANITY_MEMORY_LIMIT', $memory_limit );
		}
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::debug( "memory limit is set at $memory_limit" );
	}
	if ( ! $memory_limit || -1 === intval( $memory_limit ) ) {
		// Unlimited, set to 32GB.
		$memory_limit = '32000M';
	}
	if ( stripos( $memory_limit, 'g' ) ) {
		$memory_limit = intval( $memory_limit ) * 1024 * 1024 * 1024;
	} else {
		$memory_limit = intval( $memory_limit ) * 1024 * 1024;
	}
	return $memory_limit;
}

/**
 * Get the path to the current debug log, if one exists. Otherwise, generate a new filename.
 *
 * @return string The full path to the debug log.
 */
function imsanity_debug_log_path() {
	if ( is_dir( IMSANITY_PLUGIN_DIR ) ) {
		$potential_logs = \scandir( IMSANITY_PLUGIN_DIR );
		if ( is_iterable( $potential_logs ) ) {
			foreach ( $potential_logs as $potential_log ) {
				if ( str_ends_with( $potential_log, '.log' ) && str_contains( $potential_log, 'imsanity-debug-' ) && is_file( IMSANITY_PLUGIN_DIR . $potential_log ) ) {
					return IMSANITY_PLUGIN_DIR . $potential_log;
				}
			}
		}
	}
	return IMSANITY_PLUGIN_DIR . 'imsanity-debug-' . uniqid() . '.log';
}

/**
 * Saves the in-memory debug data to a logfile in the plugin folder.  This is called on shutdown if the IMSANITY_DEBUG constant is set to true.
 */
function imsanity_debug_log() {
	if ( ! defined( 'IMSANITY_DEBUG' ) || ! IMSANITY_DEBUG ) {
		return;
	}
	global $imsanity_debug_data;
	$debug_log = imsanity_debug_log_path();
	if ( ! empty( $imsanity_debug_data ) && is_writable( IMSANITY_PLUGIN_DIR ) ) {
		$memory_limit = imsanity_memory_limit();
		clearstatcache();
		$timestamp = gmdate( 'Y-m-d H:i:s' ) . "\n";
		if ( ! file_exists( $debug_log ) ) {
			\touch( $debug_log );
		} else {
			if ( filesize( $debug_log ) + 4000000 + memory_get_usage( true ) > $memory_limit ) {
				unlink( $debug_log );
				clearstatcache();
				$debug_log = imsanity_debug_log_path();
				touch( $debug_log );
			}
		}
		if ( filesize( $debug_log ) + strlen( $imsanity_debug_data ) + 4000000 + memory_get_usage( true ) <= $memory_limit && is_writable( $debug_log ) ) {
			$imsanity_debug_data = str_replace( '<br>', "\n", $imsanity_debug_data );
			file_put_contents( $debug_log, $timestamp . $imsanity_debug_data, FILE_APPEND );
		}
	}
	$imsanity_debug_data = '';
}

/**
 * Use the EWWW IO debugging functions (if available).
 *
 * @param string $message A message to send to the debugger.
 */
function imsanity_debug( $message ) {
	global $imsanity_debug_data;
	if ( ! is_string( $message ) && ! is_int( $message ) && ! is_float( $message ) ) {
		return;
	}
	if ( \defined( 'IMSANITY_PHPUNIT' ) && IMSANITY_PHPUNIT ) {
		if (
			! empty( $_SERVER['argv'] ) &&
			( \in_array( '--debug', $_SERVER['argv'], true ) || \in_array( '--verbose', $_SERVER['argv'], true ) )
		) {
			$message = str_replace( '<br>', "\n", $message );
			$message = str_replace( '<b>', '+', $message );
			$message = str_replace( '</b>', '+', $message );
			echo esc_html( $message ) . "\n";
		}
	}
	$message = "$message";
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::debug( $message );
		return;
	}
	if ( defined( 'IMSANITY_DEBUG' ) && IMSANITY_DEBUG ) {
		$memory_limit = imsanity_memory_limit();
		// If the message size + 4 MB + current usage exceeds the limit, don't add any more to the debug log.
		if ( strlen( $message ) + 4000000 + memory_get_usage( true ) > $memory_limit ) {
			return;
		}
		$message              = \str_replace( "\n\n\n", '<br>', $message );
		$message              = \str_replace( "\n\n", '<br>', $message );
		$message              = \str_replace( "\n", '<br>', $message );
		$imsanity_debug_data .= "$message<br>";
	}
}

/**
 * View the debug log file from the wp-admin.
 */
function imsanity_view_debug_log() {
	check_admin_referer( 'imsanity-options' );
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'Access denied.', 'imsanity' ) );
	}
	$debug_log = imsanity_debug_log_path();
	if ( is_file( $debug_log ) ) {
		header( 'Content-Type: text/plain;charset=UTF-8' );
		readfile( $debug_log );
		exit;
	}
	wp_die( esc_html__( 'The Debug Log is empty.', 'imsanity' ) );
}

/**
 * Removes the debug log file.
 */
function imsanity_delete_debug_log() {
	check_admin_referer( 'imsanity-options' );
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'Access denied.', 'imsanity' ) );
	}
	$debug_log = imsanity_debug_log_path();
	if ( is_file( $debug_log ) && is_writable( $debug_log ) ) {
		unlink( $debug_log );
	}
	$sendback = wp_get_referer();
	if ( empty( $sendback ) ) {
		$sendback = imsanity_get_settings_link();
	}
	wp_safe_redirect( $sendback );
	exit;
}

// Flush the debug log on shutdown.
add_action( 'shutdown', 'imsanity_debug_log' );
// Non-AJAX handler to view the debug log from the wp-admin.
add_action( 'admin_action_imsanity_view_debug_log', 'imsanity_view_debug_log' );
// Non-AJAX handler to delete the debug log, and reroute back to the settings page.
add_action( 'admin_action_imsanity_delete_debug_log', 'imsanity_delete_debug_log' );
