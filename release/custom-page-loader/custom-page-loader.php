<?php
/*
Plugin Name: Custom Static Page Loader
Plugin URI: https://example.com/
Description: Upload custom HTML/CSS/JS files to replace specific WordPress pages.
Version: 1.1.6
Author: Your Name
Author URI: https://example.com/
License: GPLv2 or later
Text Domain: custom-page-loader
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CPL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CPL_UPLOAD_DIR', wp_upload_dir()['basedir'] . '/custom-static-pages' );
define( 'CPL_UPLOAD_URL', wp_upload_dir()['baseurl'] . '/custom-static-pages' );

// Include necessary files
require_once CPL_PLUGIN_DIR . 'includes/admin.php';
require_once CPL_PLUGIN_DIR . 'includes/frontend.php';

// Activation hook to create storage directory
register_activation_hook( __FILE__, 'cpl_activate_plugin' );

function cpl_activate_plugin() {
    if ( ! file_exists( CPL_UPLOAD_DIR ) ) {
        wp_mkdir_p( CPL_UPLOAD_DIR );
    }
}
