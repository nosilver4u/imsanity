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
add_filter( 'plugin_action_links_' . IMSANITY_PLUGIN_FILE_REL, 'imsanity_settings_link' );
add_filter( 'network_admin_plugin_action_links_' . IMSANITY_PLUGIN_FILE_REL, 'imsanity_settings_link' );
add_action( 'admin_enqueue_scripts', 'imsanity_queue_script' );
add_action( 'admin_init', 'imsanity_register_settings' );
add_filter( 'big_image_size_threshold', 'imsanity_adjust_default_threshold', 10, 3 );

register_activation_hook( IMSANITY_PLUGIN_FILE_REL, 'imsanity_maybe_created_custom_table' );

// settings cache.
$_imsanity_multisite_settings = null;

/**
 * Create the settings menu item in the WordPress admin navigation and
 * link it to the plugin settings page
 */
function imsanity_create_menu() {
	$permissions = apply_filters( 'imsanity_admin_permissions', 'manage_options' );
	// Create new menu for site configuration.
	add_options_page(
		esc_html__( 'Imsanity Plugin Settings', 'imsanity' ), // Page Title.
		esc_html__( 'Imsanity', 'imsanity' ),                 // Menu Title.
		$permissions,                                         // Required permissions.
		IMSANITY_PLUGIN_FILE_REL,                             // Slug.
		'imsanity_settings_page'                              // Function to call.
	);
}

/**
 * Register the network settings page
 */
function imsanity_register_network() {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( is_multisite() ) {
		$permissions = apply_filters( 'imsanity_superadmin_permissions', 'manage_network_options' );
		add_submenu_page(
			'settings.php',
			esc_html__( 'Imsanity Network Settings', 'imsanity' ),
			esc_html__( 'Imsanity', 'imsanity' ),
			$permissions,
			IMSANITY_PLUGIN_FILE_REL,
			'imsanity_network_settings'
		);
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
	if ( is_multisite() && is_network_admin() ) {
		$settings_link = '<a href="' . network_admin_url( 'settings.php?page=' . IMSANITY_PLUGIN_FILE_REL ) . '">' . esc_html__( 'Settings', 'imsanity' ) . '</a>';
	} else {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=' . IMSANITY_PLUGIN_FILE_REL ) . '">' . esc_html__( 'Settings', 'imsanity' ) . '</a>';
	}
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
	if ( strpos( $hook, 'settings_page_imsanity' ) !== 0 && 'upload.php' !== $hook ) {
		return;
	}
	if ( ! empty( $_REQUEST['imsanity_reset'] ) && ! empty( $_REQUEST['imsanity_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_REQUEST['imsanity_wpnonce'] ), 'imsanity-bulk-reset' ) ) {
		update_option( 'imsanity_resume_id', 0, false );
	}
	$resume_id     = (int) get_option( 'imsanity_resume_id' );
	$loading_image = plugins_url( '/images/ajax-loader.gif', __FILE__ );
	// Register the scripts that are used by the bulk resizer.
	wp_enqueue_script( 'imsanity_script', plugins_url( '/scripts/imsanity.js', __FILE__ ), array( 'jquery' ), IMSANITY_VERSION );
	wp_localize_script(
		'imsanity_script',
		'imsanity_vars',
		array(
			'_wpnonce'          => wp_create_nonce( 'imsanity-bulk' ),
			'resize_all_prompt' => esc_html__( 'You are about to resize all your existing images. Please be sure your site is backed up before proceeding. Do you wish to continue?', 'imsanity' ),
			'resizing_complete' => esc_html__( 'Resizing Complete', 'imsanity' ) . ' - <a target="_blank" href="https://wordpress.org/support/plugin/imsanity/reviews/#new-post">' . esc_html__( 'Leave a Review', 'imsanity' ) . '</a>',
			'resize_selected'   => esc_html__( 'Resize Selected Images', 'imsanity' ),
			'resizing'          => '<p>' . esc_html__( 'Please wait...', 'imsanity' ) . "&nbsp;<img src='$loading_image' /></p>",
			'removal_failed'    => esc_html__( 'Removal Failed', 'imsanity' ),
			'removal_succeeded' => esc_html__( 'Removal Complete', 'imsanity' ),
			'operation_stopped' => esc_html__( 'Resizing stopped, reload page to resume.', 'imsanity' ),
			'image'             => esc_html__( 'Image', 'imsanity' ),
			'invalid_response'  => esc_html__( 'Received an invalid response, please check for errors in the Developer Tools console of your browser.', 'imsanity' ),
			'none_found'        => esc_html__( 'There are no images that need to be resized.', 'imsanity' ),
			'resume_id'         => $resume_id,
		)
	);
	add_action( 'admin_notices', 'imsanity_missing_gd_admin_notice' );
	add_action( 'network_admin_notices', 'imsanity_missing_gd_admin_notice' );
	add_action( 'admin_print_scripts', 'imsanity_settings_css' );
}

/**
 * Return true if the multi-site settings table exists
 *
 * @return bool True if the Imsanity table exists.
 */
function imsanity_multisite_table_exists() {
	global $wpdb;
	return $wpdb->get_var( "SHOW TABLES LIKE '$wpdb->imsanity_ms'" ) === $wpdb->imsanity_ms;
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
	$data->imsanity_quality            = IMSANITY_DEFAULT_QUALITY;
	$data->imsanity_delete_originals   = false;
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

	if ( '0' === $schema ) {
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

	if ( IMSANITY_SCHEMA_VERSION !== $schema ) {
		// This is a schema update.  for the moment there is only one schema update available, from 1.0 to 1.1.
		if ( '1.0' === $schema ) {
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
	$settings = imsanity_get_multisite_settings(); ?>
<div class="wrap">
	<h1><?php esc_html_e( 'Imsanity Network Settings', 'imsanity' ); ?></h1>

	<div id="ewwwio-promo">
		<p>
		<?php
		printf(
			/* translators: %s: link to install EWWW Image Optimizer plugin */
			esc_html__( 'Get comprehensive image optimization with %s', 'imsanity' ),
			'<br><a href="' . admin_url( 'plugin-install.php?s=ewww+image+optimizer&tab=search&type=term' ) . '">EWWW Image Optimizer</a>'
		);
		?>
			<ul>
				<li><?php esc_html_e( 'Full Compression', 'imsanity' ); ?></li>
				<li><?php esc_html_e( 'Automatic Scaling', 'imsanity' ); ?></li>
				<li><?php esc_html_e( 'Lazy Load', 'imsanity' ); ?></li>
				<li><?php esc_html_e( 'WebP Conversion', 'imsanity' ); ?></li>
			</ul>
		</p>
	</div>

	<form method="post" action="">
	<input type="hidden" name="update_imsanity_settings" value="1" />
	<?php wp_nonce_field( 'imsanity_network_options' ); ?>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="imsanity_override_site"><?php esc_html_e( 'Global Settings Override', 'imsanity' ); ?></label></th>
			<td>
				<select name="imsanity_override_site">
					<option value="0" <?php selected( $settings->imsanity_override_site, '0' ); ?> ><?php esc_html_e( 'Allow each site to configure Imsanity settings', 'imsanity' ); ?></option>
					<option value="1" <?php selected( $settings->imsanity_override_site, '1' ); ?> ><?php esc_html_e( 'Use global Imsanity settings (below) for all sites', 'imsanity' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'If you allow per-site configuration, the settings below will be used as the defaults. Single-site defaults will be set the first time you visit the site admin after activating Imsanity.', 'imsanity' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images uploaded within a Page/Post', 'imsanity' ); ?></th>
			<td>
				<label for="imsanity_max_width"><?php esc_html_e( 'Max Width', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="imsanity_max_width" value="<?php echo (int) $settings->imsanity_max_width; ?>" />
				<label for="imsanity_max_height"><?php esc_html_e( 'Max Height', 'imsanity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imsanity_max_height" value="<?php echo (int) $settings->imsanity_max_height; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imsanity' ); ?>
				<p class="description"><?php esc_html_e( 'These dimensions are used for Bulk Resizing also.', 'imsanity' ); ?></p>
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
			<th scope="row">
				<label for='imsanity_quality'><?php esc_html_e( 'JPG image quality', 'imsanity' ); ?>
			</th>
			<td>
				<input type='text' id='imsanity_quality' name='imsanity_quality' class='small-text' value='<?php echo (int) $settings->imsanity_quality; ?>' />
				<?php esc_html_e( 'Valid values are 1-100.', 'imsanity' ); ?>
				<p class='description'><?php esc_html_e( 'Only used when resizing images, does not affect thumbnails.', 'imsanity' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for"imsanity_bmp_to_jpg"><?php esc_html_e( 'Convert BMP to JPG', 'imsanity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imsanity_bmp_to_jpg" name="imsanity_bmp_to_jpg" value="true" <?php checked( $settings->imsanity_bmp_to_jpg ); ?> />
				<?php esc_html_e( 'Only applies to new image uploads, existing BMP images cannot be converted or resized.', 'imsanity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imsanity_png_to_jpg"><?php esc_html_e( 'Convert PNG to JPG', 'imsanity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imsanity_png_to_jpg" name="imsanity_png_to_jpg" value="true" <?php checked( $settings->imsanity_png_to_jpg ); ?> />
				<?php
				printf(
					/* translators: %s: link to install EWWW Image Optimizer plugin */
					esc_html__( 'Only applies to new image uploads, existing images may be converted with %s.', 'imsanity' ),
					'<a href="' . admin_url( 'plugin-install.php?s=ewww+image+optimizer&tab=search&type=term' ) . '">EWWW Image Optimizer</a>'
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imsanity_delete_originals"><?php esc_html_e( 'Delete Originals', 'imsanity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imsanity_delete_originals" name="imsanity_delete_originals" value="true" <?php checked( $settings->imsanity_delete_originals ); ?> />
				<?php esc_html_e( 'Remove the large pre-scaled originals that WordPress retains for thumbnail generation.', 'imsanity' ); ?>
			</td>
		</tr>
	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Update Settings', 'imsanity' ); ?>" /></p>

	</form>

</div>
	<?php
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

	$data->imsanity_override_site      = (bool) $_POST['imsanity_override_site'];
	$data->imsanity_max_height         = sanitize_text_field( $_POST['imsanity_max_height'] );
	$data->imsanity_max_width          = sanitize_text_field( $_POST['imsanity_max_width'] );
	$data->imsanity_max_height_library = sanitize_text_field( $_POST['imsanity_max_height_library'] );
	$data->imsanity_max_width_library  = sanitize_text_field( $_POST['imsanity_max_width_library'] );
	$data->imsanity_max_height_other   = sanitize_text_field( $_POST['imsanity_max_height_other'] );
	$data->imsanity_max_width_other    = sanitize_text_field( $_POST['imsanity_max_width_other'] );
	$data->imsanity_bmp_to_jpg         = ! empty( $_POST['imsanity_bmp_to_jpg'] );
	$data->imsanity_png_to_jpg         = ! empty( $_POST['imsanity_png_to_jpg'] );
	$data->imsanity_quality            = imsanity_jpg_quality( $_POST['imsanity_quality'] );
	$data->imsanity_delete_originals   = ! empty( $_POST['imsanity_delete_originals'] );

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
		if ( ! isset( $_imsanity_multisite_settings->imsanity_max_height_library ) ) {
			$_imsanity_multisite_settings->imsanity_max_height_library = $_imsanity_multisite_settings->imsanity_max_height;
			$_imsanity_multisite_settings->imsanity_max_width_library  = $_imsanity_multisite_settings->imsanity_max_width;
			$_imsanity_multisite_settings->imsanity_max_height_other   = $_imsanity_multisite_settings->imsanity_max_height;
			$_imsanity_multisite_settings->imsanity_max_width_other    = $_imsanity_multisite_settings->imsanity_max_width;
		}
		$_imsanity_multisite_settings->imsanity_override_site = ! empty( $_imsanity_multisite_settings->imsanity_override_site ) ? '1' : '0';
		$_imsanity_multisite_settings->imsanity_bmp_to_jpg    = ! empty( $_imsanity_multisite_settings->imsanity_bmp_to_jpg ) ? true : false;
		$_imsanity_multisite_settings->imsanity_png_to_jpg    = ! empty( $_imsanity_multisite_settings->imsanity_png_to_jpg ) ? true : false;
		if ( ! property_exists( $_imsanity_multisite_settings, 'imsanity_delete_originals' ) ) {
			$_imsanity_multisite_settings->imsanity_delete_originals = false;
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
 * Run upgrade check for new version.
 */
function imsanity_upgrade() {
	if ( is_network_admin() ) {
		return;
	}
	if ( -1 === version_compare( get_option( 'imsanity_version' ), IMSANITY_VERSION ) ) {
		if ( wp_doing_ajax() ) {
			return;
		}
		imsanity_set_defaults();
		update_option( 'imsanity_version', IMSANITY_VERSION );
	}
}

/**
 * Set default options on multi-site.
 */
function imsanity_set_defaults() {
	$settings = imsanity_get_multisite_settings();
	add_option( 'imsanity_max_width', $settings->imsanity_max_width, '', false );
	add_option( 'imsanity_max_height', $settings->imsanity_max_height, '', false );
	add_option( 'imsanity_max_width_library', $settings->imsanity_max_width_library, '', false );
	add_option( 'imsanity_max_height_library', $settings->imsanity_max_height_library, '', false );
	add_option( 'imsanity_max_width_other', $settings->imsanity_max_width_other, '', false );
	add_option( 'imsanity_max_height_other', $settings->imsanity_max_height_other, '', false );
	add_option( 'imsanity_bmp_to_jpg', $settings->imsanity_bmp_to_jpg, '', false );
	add_option( 'imsanity_png_to_jpg', $settings->imsanity_png_to_jpg, '', false );
	add_option( 'imsanity_quality', $settings->imsanity_quality, '', false );
	add_option( 'imsanity_delete_originals', $settings->imsanity_delete_originals, '', false );
	if ( ! get_option( 'imsanity_version' ) ) {
		global $wpdb;
		$wpdb->query( "UPDATE $wpdb->options SET autoload='no' WHERE option_name LIKE 'imsanity_%'" );
	}
}

/**
 * Register the configuration settings that the plugin will use
 */
function imsanity_register_settings() {
	imsanity_upgrade();
	// We only want to update if the form has been submitted.
	if ( isset( $_POST['update_imsanity_settings'] ) && is_multisite() && is_network_admin() ) {
		imsanity_network_settings_update();
	}
	// Register our settings.
	register_setting( 'imsanity-settings-group', 'imsanity_max_height', 'intval' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_width', 'intval' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_height_library', 'intval' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_width_library', 'intval' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_height_other', 'intval' );
	register_setting( 'imsanity-settings-group', 'imsanity_max_width_other', 'intval' );
	register_setting( 'imsanity-settings-group', 'imsanity_bmp_to_jpg', 'boolval' );
	register_setting( 'imsanity-settings-group', 'imsanity_png_to_jpg', 'boolval' );
	register_setting( 'imsanity-settings-group', 'imsanity_quality', 'imsanity_jpg_quality' );
	register_setting( 'imsanity-settings-group', 'imsanity_delete_originals', 'boolval' );
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
 * Check default WP threshold and adjust to comply with normal Imsanity behavior.
 *
 * @param int    $size The default WP scaling size, or whatever has been filtered by other plugins.
 * @param array  $imagesize     {
 *     Indexed array of the image width and height in pixels.
 *
 *     @type int $0 The image width.
 *     @type int $1 The image height.
 * }
 * @param string $file Full path to the uploaded image file.
 * @return int The proper size to use for scaling originals.
 */
function imsanity_adjust_default_threshold( $size, $imagesize, $file ) {
	if ( false !== strpos( $file, 'noresize' ) ) {
		return false;
	}
	$max_size = max(
		imsanity_get_option( 'imsanity_max_width', IMSANITY_DEFAULT_MAX_WIDTH ),
		imsanity_get_option( 'imsanity_max_height', IMSANITY_DEFAULT_MAX_HEIGHT ),
		imsanity_get_option( 'imsanity_max_width_library', IMSANITY_DEFAULT_MAX_WIDTH ),
		imsanity_get_option( 'imsanity_max_height_library', IMSANITY_DEFAULT_MAX_HEIGHT ),
		imsanity_get_option( 'imsanity_max_width_other', IMSANITY_DEFAULT_MAX_WIDTH ),
		imsanity_get_option( 'imsanity_max_height_other', IMSANITY_DEFAULT_MAX_HEIGHT ),
		(int) $size
	);
	return $max_size;
}

/**
 * Helper function to render css styles for the settings forms
 * for both site and network settings page
 */
function imsanity_settings_css() {
	?>
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
	#ewwwio-promo {
		display: none;
		float: right;
	}
	#ewwwio-promo a, #ewwwio-promo a:visited {
		color: #3eadc9;
	}
	#ewwwio-promo ul {
		list-style: disc;
		padding-left: 13px;
	}
	@media screen and (min-width: 850px) {
		.form-table {
			clear: left;
			width: calc(100% - 210px);
		}
		#ewwwio-promo {
			display: block;
			border: 1px solid #7e8993;
			padding: 13px;
			margin: 13px;
			width: 150px;
		}
	}
</style>
	<?php
}

/**
 * Render the settings page by writing directly to stdout.  if multi-site is enabled
 * and imsanity_override_site is true, then display a notice message that settings
 * are not editable instead of the settings form
 */
function imsanity_settings_page() {
	?>
	<div class="wrap">
	<h1><?php esc_html_e( 'Imsanity Settings', 'imsanity' ); ?></h1>
	<p>
		<a target="_blank" href="https://wordpress.org/plugins/imsanity/#faq-header"><?php esc_html_e( 'FAQ', 'imsanity' ); ?></a> |
		<a target="_blank" href="https://wordpress.org/support/plugin/imsanity/"><?php esc_html_e( 'Support', 'imsanity' ); ?></a> |
		<a target="_blank" href="https://wordpress.org/support/plugin/imsanity/reviews/#new-post"><?php esc_html_e( 'Leave a Review', 'imsanity' ); ?></a>
	</p>

	<div id="ewwwio-promo">
		<p>
		<?php
		printf(
			/* translators: %s: link to install EWWW Image Optimizer plugin */
			esc_html__( 'Get comprehensive image optimization with %s', 'imsanity' ),
			'<br><a href="' . admin_url( 'plugin-install.php?s=ewww+image+optimizer&tab=search&type=term' ) . '">EWWW Image Optimizer</a>'
		);
		?>
			<ul>
				<li><?php esc_html_e( 'Full Compression', 'imsanity' ); ?></li>
				<li><?php esc_html_e( 'Automatic Scaling', 'imsanity' ); ?></li>
				<li><?php esc_html_e( 'Lazy Load', 'imsanity' ); ?></li>
				<li><?php esc_html_e( 'WebP Conversion', 'imsanity' ); ?></li>
			</ul>
		</p>
	</div>

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
		<p><?php esc_html_e( 'If you have existing images that were uploaded prior to installing Imsanity, you may resize them all in bulk to recover disk space (below).', 'imsanity' ); ?></p>
		<p>
			<?php
			printf(
				/* translators: 1: List View in the Media Library 2: the WP-CLI command */
				esc_html__( 'You may also use %1$s to selectively resize images or WP-CLI to resize your images in bulk: %2$s', 'imsanity' ),
				'<a href="' . esc_url( admin_url( 'upload.php?mode=list' ) ) . '">' . esc_html__( 'List View in the Media Library', 'imsanity' ) . '</a>',
				'<code>wp help imsanity resize</code>'
			);
			?>
		</p>
	</div>

	<div style="border: solid 1px #ff6666; background-color: #ffbbbb; padding: 0 10px;margin-bottom:1em;">
		<h4><?php esc_html_e( 'WARNING: Bulk Resize will alter your original images and cannot be undone!', 'imsanity' ); ?></h4>
		<p>
			<?php esc_html_e( 'It is HIGHLY recommended that you backup your images before proceeding.', 'imsanity' ); ?><br>
			<?php
			printf(
				/* translators: %s: List View in the Media Library */
				esc_html__( 'You may also resize 1 or 2 images using %s to verify that everything is working properly before processing your entire library.', 'imsanity' ),
				'<a href="' . esc_url( admin_url( 'upload.php?mode=list' ) ) . '">' . esc_html__( 'List View in the Media Library', 'imsanity' ) . '</a>'
			);
			?>
		</p>
	</div>

	<?php
	$button_text = __( 'Start Resizing All Images', 'imsanity' );
	if ( get_option( 'imsanity_resume_id' ) ) {
		$button_text = __( 'Continue Resizing', 'imsanity' );
	}
	?>

	<p class="submit" id="imsanity-examine-button">
		<button class="button-primary" onclick="imsanity_load_images();"><?php echo esc_html( $button_text ); ?></button>
	</p>
	<form id="imsanity-bulk-stop" style="display:none;margin:1em 0 1em;" method="post" action="">
		<button type="submit" class="button-secondary action"><?php esc_html_e( 'Stop Resizing', 'imsanity' ); ?></button>
	</form>
	<?php if ( get_option( 'imsanity_resume_id' ) ) : ?>
	<p class="imsanity-bulk-text" style="margin-top:1em;"><?php esc_html_e( 'Would you like to start back at the beginning?', 'imsanity' ); ?></p>
	<form class="imsanity-bulk-form" method="post" action="">
		<?php wp_nonce_field( 'imsanity-bulk-reset', 'imsanity_wpnonce' ); ?>
		<input type="hidden" name="imsanity_reset" value="1">
		<button id="imsanity-bulk-reset" type="submit" class="button-secondary action"><?php esc_html_e( 'Clear Queue', 'imsanity' ); ?></button>
	</form>
	<?php endif; ?>
	<div id="imsanity_loading" style="display: none;margin:1em 0 1em;"><img src="<?php echo plugins_url( 'images/ajax-loader.gif', __FILE__ ); ?>" style="margin-bottom: .25em; vertical-align:middle;" />
		<?php esc_html_e( 'Searching for images. This may take a moment.', 'imsanity' ); ?>
	</div>
	<div id="resize_results" style="display: none; border: solid 2px #666666; padding: 10px; height: 400px; overflow: auto;">
		<div id="bulk-resize-beginning"><?php esc_html_e( 'Resizing...', 'imsanity' ); ?> <img src="<?php echo plugins_url( 'images/ajax-loader.gif', __FILE__ ); ?>" style="margin-bottom: .25em; vertical-align:middle;" /></div>
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
 * Check to see if GD is missing, and alert the user.
 */
function imsanity_missing_gd_admin_notice() {
	if ( imsanity_gd_support() ) {
		return;
	}
	echo "<div id='imsanity-missing-gd' class='notice notice-warning'><p>" . esc_html__( 'The GD extension is not enabled in PHP, Imsanity may not function correctly. Enable GD or contact your web host for assistance.', 'imsanity' ) . '</p></div>';
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
			<p class="description"><?php esc_html_e( 'These dimensions are used for Bulk Resizing also.', 'imsanity' ); ?></p>
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
			<th scope="row">
				<label for='imsanity_quality' ><?php esc_html_e( 'JPG image quality', 'imsanity' ); ?>
			</th>
			<td>
				<input type='text' id='imsanity_quality' name='imsanity_quality' class='small-text' value='<?php echo imsanity_jpg_quality(); ?>' />
				<?php esc_html_e( 'Valid values are 1-100.', 'imsanity' ); ?>
				<p class='description'><?php esc_html_e( 'Only used when resizing images, does not affect thumbnails.', 'imsanity' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="imsanity_bmp_to_jpg"><?php esc_html_e( 'Convert BMP To JPG', 'imsanity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imsanity_bmp_to_jpg" name="imsanity_bmp_to_jpg" value="true" <?php checked( (bool) get_option( 'imsanity_bmp_to_jpg', IMSANITY_DEFAULT_BMP_TO_JPG ) ); ?> />
				<?php esc_html_e( 'Only applies to new image uploads, existing BMP images cannot be converted or resized.', 'imsanity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imsanity_png_to_jpg"><?php esc_html_e( 'Convert PNG To JPG', 'imsanity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imsanity_png_to_jpg" name="imsanity_png_to_jpg" value="true" <?php checked( (bool) get_option( 'imsanity_png_to_jpg', IMSANITY_DEFAULT_PNG_TO_JPG ) ); ?> />
				<?php
				printf(
					/* translators: %s: link to install EWWW Image Optimizer plugin */
					esc_html__( 'Only applies to new image uploads, existing images may be converted with %s.', 'imsanity' ),
					'<a href="' . admin_url( 'plugin-install.php?s=ewww+image+optimizer&tab=search&type=term' ) . '">EWWW Image Optimizer</a>'
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imsanity_delete_originals"><?php esc_html_e( 'Delete Originals', 'imsanity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imsanity_delete_originals" name="imsanity_delete_originals" value="true" <?php checked( get_option( 'imsanity_delete_originals' ) ); ?> />
				<?php esc_html_e( 'Remove the large pre-scaled originals that WordPress retains for thumbnail generation.', 'imsanity' ); ?>
			</td>
		</tr>
	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'imsanity' ); ?>" /></p>

	</form>
	<?php

}

?>
