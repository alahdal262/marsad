<?php
/**
 * Plugin Name:       Marsad — Unified Analytics
 * Plugin URI:        https://github.com/alahdal262/marsad
 * Description:       One dashboard for every number your site lives on: Google Analytics 4, Search Console, YouTube, Facebook, Instagram, Telegram, Google Play, the App Store, WordPress itself, and any JSON endpoint you own.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Marsad contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       marsad
 * Domain Path:       /languages
 * Update URI:        https://github.com/alahdal262/marsad
 */

defined( 'ABSPATH' ) || exit;

define( 'MARSAD_VERSION', '0.4.0' );
define( 'MARSAD_FILE', __FILE__ );
define( 'MARSAD_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARSAD_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'MARSAD_UPDATE_REPO' ) ) {
	define( 'MARSAD_UPDATE_REPO', 'alahdal262/marsad' );
}

require_once MARSAD_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'Marsad_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Marsad_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Marsad_Plugin', 'boot' ) );
