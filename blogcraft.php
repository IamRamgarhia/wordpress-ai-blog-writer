<?php
/**
 * Plugin Name:       Blogcraft
 * Plugin URI:        https://dicecodes.com/blogcraft
 * Description:       AI blog writer that researches first, writes in your voice, and checks its own work.
 * Version:           0.9.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dicecodes
 * Author URI:        https://dicecodes.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blogcraft
 * Domain Path:       /languages
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

define( 'BLOGCRAFT_VERSION', '0.9.4' );
define( 'BLOGCRAFT_DB_VERSION', '1' );
define( 'BLOGCRAFT_FILE', __FILE__ );
define( 'BLOGCRAFT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLOGCRAFT_URL', plugin_dir_url( __FILE__ ) );

require_once BLOGCRAFT_PATH . 'includes/class-blogcraft-autoloader.php';

Blogcraft_Autoloader::register();

register_activation_hook( __FILE__, array( 'Blogcraft_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Blogcraft_Deactivator', 'deactivate' ) );

Blogcraft::instance()->run();
