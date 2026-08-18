<?php
/**
 * Plugin Name:       Blogcraft
 * Plugin URI:        https://dicecodes.com/blogcraft
 * Description:       AI blog writer and content generator. Connect any AI provider with your own API key.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dicecodes
 * Author URI:        https://dicecodes.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blogcraft
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

define( 'BLOGCRAFT_VERSION', '0.2.0' );
define( 'BLOGCRAFT_DB_VERSION', '1' );
define( 'BLOGCRAFT_FILE', __FILE__ );
define( 'BLOGCRAFT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLOGCRAFT_URL', plugin_dir_url( __FILE__ ) );

require_once BLOGCRAFT_PATH . 'includes/class-blogcraft-autoloader.php';

Blogcraft_Autoloader::register();

register_activation_hook( __FILE__, array( 'Blogcraft_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Blogcraft_Deactivator', 'deactivate' ) );

Blogcraft::instance()->run();
