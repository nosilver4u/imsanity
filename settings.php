<?php
/**
 * Imsanity settings and admin UI.
 *
 * @package Imsanity
 */

// Setup custom $wpdb attribute for our image-tracking table.
global $wpdb;
if ( ! isset( $wpdb->imsanity_ms ) ) {
	$wpdb->imsanity_ms = $wpdb->get_blog_prefix( 0 ) . 'imsanity';
}

// Register the plugin settings menu.
add_action( 'admin_menu', 'imsanity_create_menu' );
add_action( 'network_admin_menu', 'imsanity_register_network' );
add_filter( 'plugin_action_links_imsanity/imsanity.php', 'imsanity_settings_link' );
add_action( 'admin_enqueue_scripts', 'imsanity_queue_script' );
add_action( 'admin_init', 'imsanity_register_settings' );

register_activation_hook( 'imsanity/imsanity.php', 'imsanity_maybe_created_custom_table' );

// settings cache.
$_imsanity_multisite_settings = null;

/**
 * Create the settings menu item in the WordPress admin navigation and
 * link it to the plugin settings page
 */
function imsanity_create_menu() {
	// Create new menu for site configuration.
	add_options_page( esc_html__( 'Imsanity Plugin Settings', 'imsanity' ), 'Imsanity', 'administrator', __FILE__, 'imsanity_settings_page' );
}

/**
 * Register the network settings page
 */
function imsanity_register_network() {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( is_multisite() && is_plugin_active_for_network( 'imsanity/imsanity.php' ) ) {
		add_submenu_page( 'settings.php', esc_html__( 'Imsanity Network Settings', 'imsanity' ), 'Imsanity', 'manage_options', 'imsanity_network', 'imsanity_network_settings' );
	}
}

/**
 * Settings link that appears on the plugins overview page
 *
 * @param array $links The plugin action links.
 * @return array The action links, with a settings link pre-pended.
 */
function imsanity_settings_link( $links ) {
	if ( ! is_array( $links ) ) {
		$links = array();
	}
	$settings_link = '<a href="' . get_admin_url( null, 'options-general.php?page=' . __FILE__ ) . '">' . esc_html__( 'Settings', 'imsanity' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

/**
 * Queues up the AJAX script and any localized JS vars we need.
 *
 * @param string $hook The hook name for the current page.
 */
function imsanity_queue_script( $hook ) {
	// Make sure we are being called from the settings page.
	if ( strpos( $hook, 'settings_page_imsanity' ) !== 0 ) {
		return;
	}
	// Register the scripts that are used by the bulk resizer.
	wp_enqueue_script( 'imsanity_script', plugins_url( '/scripts/imsanity.js', __FILE__ ), array( 'jquery' ), IMSANITY_VERSION );
	wp_localize_script(
		'imsanity_script',
		'imsanity_vars',
		array(
			'_wpnonce'          => wp_create_nonce( 'imsanity-bulk' ),
			'resizing_complete' => esc_html__( 'Resizing Complete', 'imsanity' ),
			'resize_selected'   => esc_html__( 'Resize Selected Images', 'imsanity' ),
			'image'             => esc_html__( 'Image', 'imsanity' ),
			'invalid_response'  => esc_html__( 'Received an invalid response, please check for errors in the Developer Tools console of your browser.', 'imsanity' ),
			'none_found'        => esc_html__( 'There are no images that need to be resized.', 'imsanity' ),
		)
	);
}

/**
 * Return true if the multi-site settings table exists
 *
 * @return bool True if the Imsanity table exists.
 */
function imsanity_multisite_table_exists() {
	global $wpdb;
	return $wpdb->get_var( "SHOW TABLES LIKE '$wpdb->imsanity_ms'" ) == $wpdb->imsanity_ms;
}

/**
 * Checks the schema version for the Imsanity table.
 *
 * @return string The version identifier for the schema.
 */
function imsanity_multisite_table_schema_version() {
	// If the table doesn't exist then there is no schema to report.
	if ( ! imsanity_multisite_table_exists() ) {
		return '0';
	}

	global $wpdb;
	$version = $wpdb->get_var( "SELECT data FROM $wpdb->imsanity_ms WHERE setting = 'schema'" );

	if ( ! $version ) {
		$version = '1.0'; // This is a legacy version 1.0 installation.
	}

	return $version;
}

/**
 * Returns the default network settings in the case where they are not
 * defined in the database, or multi-site is not enabled.
 *
 * @return stdClass
 */
function imsanity_get_default_multisite_settings() {
	$data = new stdClass();

	$data->imsanity_override_site      = false;
	$data->imsanity_max_height         = IMSANITY_DEFAULT_MAX_HEIGHT;
	$data->imsanity_max_width          = IMSANITY_DEFAULT_MAX_WIDTH;
	$data->imsanity_max_height_library = IMSANITY_DEFAULT_MAX_HEIGHT;
	$data->imsanity_max_width_library  = IMSANITY_DEFAULT_MAX_WIDTH;
	$data->imsanity_max_height_other   = IMSANITY_DEFAULT_MAX_HEIGHT;
	$data->imsanity_max_width_other    = IMSANITY_DEFAULT_MAX_WIDTH;
	$data->imsanity_bmp_to_jpg         = IMSANITY_DEFAULT_BMP_TO_JPG;
	$data->imsanity_png_to_jpg         = IMSANITY_DEFAULT_PNG_TO_JPG;
	$data->imsanity_deep_scan          = false;
	$data->imsanity_quality            = IMSANITY_DEFAULT_QUALITY;
	return $data;
}


/**
 * On activation create the multisite database table if necessary.  this is
 * called when the plugin is activated as well as when it is automatically
 * updated.
 */
function imsanity_maybe_created_custom_table() {
	// If not a multi-site no need to do any custom table lookups.
	if ( ! function_exists( 'is_multisite' ) || ( ! is_multisite() ) ) {
		return;
	}

	global $wpdb;

	$schema = imsanity_multisite_table_schema_version();

	if ( '0' == $schema ) {
		// This is an initial database setup.
		$sql = 'CREATE TABLE IF NOT EXISTS ' . $wpdb->imsanity_ms . ' (
					  setting varchar(55),
					  data text NOT NULL,
					  PRIMARY KEY (setting)
					);';

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		// Add the rows to the database.
		$data = imsanity_get_default_multisite_settings();
		$wpdb->insert(
			$wpdb->imsanity_ms,
			array(
				'setting' => 'multisite',
				'data'    => maybe_serialize( $data ),
			)
		);
		$wpdb->insert(
			$wpdb->imsanity_ms,
			array(
				'setting' => 'schema',
				'data'    => IMSANITY_SCHEMA_VERSION,
			)
		);
	}

	if ( IMSANITY_SCHEMA_VERSION != $schema ) {
		// This is a schema update.  for the moment there is only one schema update available, from 1.0 to 1.1.
		if ( '1.0' == $schema ) {
			// Update from version 1.0 to 1.1.
			$wpdb->insert(
				$wpdb->imsanity_ms,
				array(
					'setting' => 'schema',
					'data'    => IMSANITY_SCHEMA_VERSION,
				)
			);
			$wpdb->query( "ALTER TABLE $wpdb->imsanity_ms CHANGE COLUMN data data TEXT NOT NULL;" );
		} else {
			// @todo we don't have this yet
			$wpdb->update(
				$wpdb->imsanity_ms,
				array( 'data' => IMSANITY_SCHEMA_VERSION ),
				array( 'setting' => 'schema' )
			);
		}
	}
}

/**
 * Display the form for the multi-site settings page.
 */
function imsanity_network_settings() {
	imsanity_settings_css();

	echo '
		<div class="wrap">
		<h1>' . esc_html__( 'Imsanity Network Settings', 'imsanity' ) . '</h1>
		';

	$settings = imsanity_get_multisite_settings();
	?>
	<script type='text/javascript'>
		jQuery(document).ready(function($) {$(".fade").fadeTo(5000,1).fadeOut(3000);});
	</script>
	<form method="post" action="settings.php?page=imsanity_network">
	<input type="hidden" name="update_imsanity_settings" value="1" />
	<?php wp_nonce_field( 'imsanity_network_options' ); ?>
	<table class="form-table">
	<tr>
	<th scope="row"><label for="imsanity_override_site"><?php esc_html_e( 'Global Settings Override', 'imsanity' ); ?></label></th>
	<td>
		<select name="imsanity_override_site">
			<option value="0" <?php echo ( '0' == $settings->imsanity_override_site ) ? "selected='selected'" : ''; ?> ><?php esc_html_e( 'Allow each site to configure Imsanity settings', 'imsanity' ); ?></option>
			<option value="1" <?php echo ( '1' == $settings->imsanity_override_site ) ? "selected='selected'" : ''; ?> ><?php esc_html_e( 'Use global Imsanity settings (below) for all sites', 'imsanity' ); ?></option>
		</select>
	</td>
	</tr>

	<tr>
	<th scope="row"><?php esc_html_e( 'Images uploaded within a Page/Post', 'imsanity' ); ?></th>
	<td>
		<label for="imsanity_max_width"><?php esc_html_e( 'Max Width', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="imsanity_max_width" value="<?php echo (int) $settings->imsanity_max_width; ?>" />
		<label for="imsanity_max_height"><?php esc_html_e( 'Max Height', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_height" value="<?php echo (int) $settings->imsanity_max_height; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imsanity' ); ?>
	</td>
	</tr>

	<tr>
	<th scope="row"><?php esc_html_e( 'Images uploaded directly to the Media Library', 'imsanity' ); ?></th>
	<td>
		<label for="imsanity_max_width_library"><?php esc_html_e( 'Max Width', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="imsanity_max_width_library" value="<?php echo (int) $settings->imsanity_max_width_library; ?>" />
		<label for="imsanity_max_height_library"><?php esc_html_e( 'Max Height', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_height_library" value="<?php echo (int) $settings->imsanity_max_height_library; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imsanity' ); ?>
	</td>
	</tr>

	<tr>
	<th scope="row"><?php esc_html_e( 'Images uploaded elsewhere (Theme headers, backgrounds, logos, etc)', 'imsanity' ); ?></th>
	<td>
		<label for="imsanity_max_width_other"><?php esc_html_e( 'Max Width', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="imsanity_max_width_other" value="<?php echo (int) $settings->imsanity_max_width_other; ?>" />
		<label for="imsanity_max_height_other"><?php esc_html_e( 'Max Height', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_height_other" value="<?php echo (int) $settings->imsanity_max_height_other; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imsanity' ); ?>
	</td>
	</tr>

	<tr>
	<th scope="row"><label for"imsanity_bmp_to_jpg"><?php esc_html_e( 'Convert BMP to JPG', 'imsanity' ); ?></label></th>
	<td><select name="imsanity_bmp_to_jpg">
		<option value="1" <?php echo ( '1' == $settings->imsanity_bmp_to_jpg ) ? "selected='selected'" : ''; ?> ><?php esc_html_e( 'Yes', 'imsanity' ); ?></option>
		<option value="0" <?php echo ( '0' == $settings->imsanity_bmp_to_jpg ) ? "selected='selected'" : ''; ?> ><?php esc_html_e( 'No', 'imsanity' ); ?></option>
	</select></td>
	</tr>

	<tr>
	<th scope="row"><label for="imsanity_png_to_jpg"><?php esc_html_e( 'Convert PNG to JPG', 'imsanity' ); ?></label></th>
	<td><select name="imsanity_png_to_jpg">
		<option value="1" <?php echo ( '1' == $settings->imsanity_png_to_jpg ) ? "selected='selected'" : ''; ?> ><?php esc_html_e( 'Yes', 'imsanity' ); ?></option>
		<option value="0" <?php echo ( '0' == $settings->imsanity_png_to_jpg ) ? "selected='selected'" : ''; ?> ><?php esc_html_e( 'No', 'imsanity' ); ?></option>
	</select></td>
	</tr>

	<tr>
	<th scope="row"><label for='imsanity_quality' ><?php esc_html_e( 'JPG image quality', 'imsanity' ); ?></th>
	<td><input type='text' id='imsanity_quality' name='imsanity_quality' class='small-text' value='<?php echo (int) $settings->imsanity_quality; ?>' /> <?php esc_html_e( 'Valid values are 1-100.', 'imsanity' ); ?>
	<p class='description'><?php esc_html_e( 'WordPress default is 82', 'imsanity' ); ?></p></td>
	</tr>

	<tr>
		<th scope="row"><label for="imsanity_deep_scan"><?php esc_html_e( 'Deep Scan', 'imsanity' ); ?></label></th>
		<td><input type="checkbox" id="imsanity_deep_scan" name="imsanity_deep_scan" value="true"<?php echo ( $settings->imsanity_deep_scan ) ? " checked='true'" : ''; ?> /><?php esc_html_e( 'If searching repeatedly returns the same images, deep scanning will check the actual image dimensions instead of relying on metadata from the database.', 'imsanity' ); ?></td>
	</tr>

	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Update Settings', 'imsanity' ); ?>" /></p>

	</form>
	<?php

	echo '</div>';
}

/**
 * Process the form, update the network settings
 * and clear the cached settings
 */
function imsanity_network_settings_update() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'imsanity_network_options' ) ) {
		return;
	}
	global $wpdb;
	global $_imsanity_multisite_settings;

	// ensure that the custom table is created when the user updates network settings
	// this is not ideal but it's better than checking for this table existance
	// on every page load.
	imsanity_maybe_created_custom_table();

	$data = new stdClass();

	$data->imsanity_override_site      = 1 == $_POST['imsanity_override_site'];
	$data->imsanity_max_height         = sanitize_text_field( $_POST['imsanity_max_height'] );
	$data->imsanity_max_width          = sanitize_text_field( $_POST['imsanity_max_width'] );
	$data->imsanity_max_height_library = sanitize_text_field( $_POST['imsanity_max_height_library'] );
	$data->imsanity_max_width_library  = sanitize_text_field( $_POST['imsanity_max_width_library'] );
	$data->imsanity_max_height_other   = sanitize_text_field( $_POST['imsanity_max_height_other'] );
	$data->imsanity_max_width_other    = sanitize_text_field( $_POST['imsanity_max_width_other'] );
	$data->imsanity_bmp_to_jpg         = 1 == $_POST['imsanity_bmp_to_jpg'];
	$data->imsanity_png_to_jpg         = 1 == $_POST['imsanity_png_to_jpg'];
	$data->imsanity_quality            = imsanity_jpg_quality( $_POST['imsanity_quality'] );
	$data->imsanity_deep_scan          = (bool) $_POST['imsanity_deep_scan'];

	$success = $wpdb->update(
		$wpdb->imsanity_ms,
		array( 'data' => maybe_serialize( $data ) ),
		array( 'setting' => 'multisite' )
	);

	// Clear the cache.
	$_imsanity_multisite_settings = null;
	add_action( 'network_admin_notices', 'imsanity_network_settings_saved' );
}

/**
 * Display a message to inform the user the multi-site setting have been saved.
 */
function imsanity_network_settings_saved() {
	echo "<div id='imsanity-network-settings-saved' class='updated fade'><p><strong>" . esc_html__( 'Imsanity network settings saved.', 'imsanity' ) . '</strong></p></div>';
}

/**
 * Return the multi-site settings as a standard class.  If the settings are not
 * defined in the database or multi-site is not enabled then the default settings
 * are returned.  This is cached so it only loads once per page load, unless
 * imsanity_network_settings_update is called.
 *
 * @return stdClass
 */
function imsanity_get_multisite_settings() {
	global $_imsanity_multisite_settings;
	$result = null;

	if ( ! $_imsanity_multisite_settings ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			global $wpdb;
			$result = $wpdb->get_var( "SELECT data FROM $wpdb->imsanity_ms WHERE setting = 'multisite'" );
		}

		// if there's no results, return the defaults instead.
		$_imsanity_multisite_settings = $result
			? unserialize( $result )
			: imsanity_get_default_multisite_settings();

		// this is for backwards compatibility.
		if ( empty( $_imsanity_multisite_settings->imsanity_max_height_library ) ) {
			$_imsanity_multisite_settings->imsanity_max_height_library = $_imsanity_multisite_settings->imsanity_max_height;
			$_imsanity_multisite_settings->imsanity_max_width_library  = $_imsanity_multisite_settings->imsanity_max_width;
			$_imsanity_multisite_settings->imsanity_max_height_other   = $_imsanity_multisite_settings->imsanity_max_height;
			$_imsanity_multisite_settings->imsanity_max_width_other    = $_imsanity_multisite_settings->imsanity_max_width;
		}
		if ( ! property_exists( $_imsanity_multisite_settings, 'imsanity_deep_scan' ) ) {
			$_imsanity_multisite_settings->imsanity_deep_scan = false;
		}
	}
	return $_imsanity_multisite_settings;
}

/**
 * Gets the option setting for the given key, first checking to see if it has been
 * set globally for multi-site.  Otherwise checking the site options.
 *
 * @param string $key The name of the option to retrieve.
 * @param string $ifnull Value to use if the requested option returns null.
 */
function imsanity_get_option( $key, $ifnull ) {
	$result = null;

	$settings = imsanity_get_multisite_settings();

	if ( $settings->imsanity_override_site ) {
		$result = $settings->$key;
		if ( is_null( $result ) ) {
			$result = $ifnull;
		}
	} else {
		$result = get_option( $key, $ifnull );
	}

	return $result;
}

/**
 * Register the configuration settings that the plugin will use
 */
function imsanity_register_settings() {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	// We only want to update if the form has been submitted.
	if ( isset( $_POST['update_imsanity_settings'] ) && is_multisite() && is_plugin_active_for_network( 'imsanity/imsanity.php' ) ) {
		imsanity_network_settings_update();
	}
	// Register our settings.
	register_setting( 'imsanity-settings-group', 'imsanity_max_height' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_width' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_height_library' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_width_library' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_height_other' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_width_other' );
	register_setting( 'imsanity-settings-group', 'imsanity_bmp_to_jpg' );
	register_setting( 'imsanity-settings-group', 'imsanity_png_to_jpg' );
	register_setting( 'imsanity-settings-group', 'imsanity_quality', 'imsanity_jpg_quality' );
	register_setting( 'imsanity-settings-group', 'imsanity_deep_scan' );
}

/**
 * Validate and return the JPG quality setting.
 *
 * @param int $quality The JPG quality currently set.
 * @return int The (potentially) adjusted quality level.
 */
function imsanity_jpg_quality( $quality = null ) {
	if ( is_null( $quality ) ) {
		$quality = get_option( 'imsanity_quality' );
	}
	if ( preg_match( '/^(100|[1-9][0-9]?)$/', $quality ) ) {
		return (int) $quality;
	} else {
		return IMSANITY_DEFAULT_QUALITY;
	}
}

/**
 * Helper function to render css styles for the settings forms
 * for both site and network settings page
 */
function imsanity_settings_css() {
	echo '
	<style>
	#imsanity_header {
		border: solid 1px #c6c6c6;
		margin: 10px 0px;
		padding: 0px 10px;
		background-color: #e1e1e1;
	}
	#imsanity_header p {
		margin: .5em 0;
	}
	</style>';
}

/**
 * Render the settings page by writing directly to stdout.  if multi-site is enabled
 * and imsanity_override_site is true, then display a notice message that settings
 * are not editable instead of the settings form
 */
function imsanity_settings_page() {
	imsanity_settings_css();

	?>
	<script type='text/javascript'>
		jQuery(document).ready(function($) {$(".fade").fadeTo(5000,1).fadeOut(3000);});
	</script>
	<div class="wrap">
	<h1><?php esc_html_e( 'Imsanity Settings', 'imsanity' ); ?></h1>
	<?php

	$settings = imsanity_get_multisite_settings();

	if ( $settings->imsanity_override_site ) {
		imsanity_settings_page_notice();
	} else {
		imsanity_settings_page_form();
	}

	?>

	<h2 style="margin-top: 0px;"><?php esc_html_e( 'Bulk Resize Images', 'imsanity' ); ?></h2>

	<div id="imsanity_header">
	<p><?php esc_html_e( 'If you have existing images that were uploaded prior to installing Imsanity, you may resize them all in bulk to recover disk space. To begin, click the "Search Images" button to search all existing attachments for images that are larger than the configured limit.', 'imsanity' ); ?></p>
	<?php /* translators: %d: the number of images */ ?>
	<p><?php printf( esc_html__( 'NOTE: To give you greater control over the resizing process, a maximum of %d images will be returned at one time. Bitmap images cannot be bulk resized and will not appear in the search results.', 'imsanity' ), IMSANITY_AJAX_MAX_RECORDS ); ?></p>
	</div>

	<div style="border: solid 1px #ff6666; background-color: #ffbbbb; padding: 0 10px;">
		<h4><?php esc_html_e( 'WARNING: Bulk Resize will alter your original images and cannot be undone!', 'imsanity' ); ?></h4>

		<p><?php esc_html_e( 'It is HIGHLY recommended that you backup your wp-content/uploads folder before proceeding. You will have a chance to preview and select the images to convert.', 'imsanity' ); ?><br>
		<?php esc_html_e( 'It is also recommended that you initially select only 1 or 2 images and verify that everything is working properly before processing your entire library.', 'imsanity' ); ?></p>
	</div>

	<p class="submit" id="imsanity-examine-button">
		<button class="button-primary" onclick="imsanity_load_images('imsanity_image_list');"><?php esc_html_e( 'Search Images...', 'imsanity' ); ?></button>
	</p>
	<div id='imsanity_image_list'>
		<div id="imsanity_target" style="display: none; border: solid 2px #666666; padding: 10px; height: 0px; overflow: auto;">
			<div id="imsanity_loading" style="display: none;"><img src="<?php echo plugins_url( 'images/ajax-loader.gif', __FILE__ ); ?>" style="margin-bottom: .25em; vertical-align:middle;" />
				<?php esc_html_e( 'Scanning existing images. This may take a moment.', 'imsanity' ); ?>
			</div>
		</div>
	</div>

	<?php

	echo '</div>';

}

/**
 * Multi-user config file exists so display a notice
 */
function imsanity_settings_page_notice() {
	?>
	<div class="updated settings-error">
	<p><strong><?php esc_html_e( 'Imsanity settings have been configured by the server administrator. There are no site-specific settings available.', 'imsanity' ); ?></strong></p>
	</div>
	<?php
}

/**
 * Render the site settings form.  This is processed by
 * WordPress built-in options persistance mechanism
 */
function imsanity_settings_page_form() {
	?>
	<form method="post" action="options.php">
	<?php settings_fields( 'imsanity-settings-group' ); ?>
		<table class="form-table">

		<tr>
		<th scope="row"><?php esc_html_e( 'Images uploaded within a Page/Post', 'imsanity' ); ?></th>
		<td>
			<label for="imsanity_max_width"><?php esc_html_e( 'Max Width', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_width" value="<?php echo (int) get_option( 'imsanity_max_width', IMSANITY_DEFAULT_MAX_WIDTH ); ?>" />
			<label for="imsanity_max_height"><?php esc_html_e( 'Max Height', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_height" value="<?php echo (int) get_option( 'imsanity_max_height', IMSANITY_DEFAULT_MAX_HEIGHT ); ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imsanity' ); ?>
		</td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Images uploaded directly to the Media Library', 'imsanity' ); ?></th>
		<td>
			<label for="imsanity_max_width_library"><?php esc_html_e( 'Max Width', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_width_library" value="<?php echo (int) get_option( 'imsanity_max_width_library', IMSANITY_DEFAULT_MAX_WIDTH ); ?>" />
			<label for="imsanity_max_height_library"><?php esc_html_e( 'Max Height', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_height_library" value="<?php echo (int) get_option( 'imsanity_max_height_library', IMSANITY_DEFAULT_MAX_HEIGHT ); ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imsanity' ); ?>
		</td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Images uploaded elsewhere (Theme headers, backgrounds, logos, etc)', 'imsanity' ); ?></th>
		<td>
			<label for="imsanity_max_width_other"><?php esc_html_e( 'Max Width', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_width_other" value="<?php echo (int) get_option( 'imsanity_max_width_other', IMSANITY_DEFAULT_MAX_WIDTH ); ?>" />
			<label for="imsanity_max_height_other"><?php esc_html_e( 'Max Height', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_height_other" value="<?php echo (int) get_option( 'imsanity_max_height_other', IMSANITY_DEFAULT_MAX_HEIGHT ); ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imsanity' ); ?>
		</td>
		</tr>


		<tr>
		<th scope="row"><label for='imsanity_quality' ><?php esc_html_e( 'JPG image quality', 'imsanity' ); ?></th>
		<td><input type='text' id='imsanity_quality' name='imsanity_quality' class='small-text' value='<?php echo imsanity_jpg_quality(); ?>' /> <?php esc_html_e( 'Valid values are 1-100.', 'imsanity' ); ?>
		<p class='description'><?php esc_html_e( 'WordPress default is 82', 'imsanity' ); ?></p></td>
		</tr>

		<tr>
		<th scope="row"><label for="imsanity_bmp_to_jpg"><?php esc_html_e( 'Convert BMP To JPG', 'imsanity' ); ?></label></th>
		<td><select name="imsanity_bmp_to_jpg">
			<option <?php echo ( '1' == get_option( 'imsanity_bmp_to_jpg', IMSANITY_DEFAULT_BMP_TO_JPG ) ) ? "selected='selected'" : ''; ?> value="1"><?php esc_html_e( 'Yes', 'imsanity' ); ?></option>
			<option <?php echo ( '0' == get_option( 'imsanity_bmp_to_jpg', IMSANITY_DEFAULT_BMP_TO_JPG ) ) ? "selected='selected'" : ''; ?> value="0"><?php esc_html_e( 'No', 'imsanity' ); ?></option>
		</select></td>
		</tr>

		<tr>
		<th scope="row"><label for="imsanity_png_to_jpg"><?php esc_html_e( 'Convert PNG To JPG', 'imsanity' ); ?></label></th>
		<td><select name="imsanity_png_to_jpg">
			<option <?php echo ( '1' == get_option( 'imsanity_png_to_jpg', IMSANITY_DEFAULT_PNG_TO_JPG ) ) ? "selected='selected'" : ''; ?> value="1"><?php esc_html_e( 'Yes', 'imsanity' ); ?></option>
			<option <?php echo ( '0' == get_option( 'imsanity_png_to_jpg', IMSANITY_DEFAULT_PNG_TO_JPG ) ) ? "selected='selected'" : ''; ?> value="0"><?php esc_html_e( 'No', 'imsanity' ); ?></option>
		</select></td>
		</tr>

		<tr>
			<th scope="row"><label for="imsanity_deep_scan"><?php esc_html_e( 'Deep Scan', 'imsanity' ); ?></label></th>
			<td><input type="checkbox" id="imsanity_deep_scan" name="imsanity_deep_scan" value="true"<?php echo ( get_option( 'imsanity_deep_scan' ) ) ? " checked='true'" : ''; ?> /><?php esc_html_e( 'If searching repeatedly returns the same images, deep scanning will check the actual image dimensions instead of relying on metadata from the database.', 'imsanity' ); ?></td>
		</tr>
	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'imsanity' ); ?>" /></p>

	</form>
	<?php

}

?>
