<?php
/**
 * Plugin Name:       Dicecodes AI Blog Writer
 * Plugin URI:        https://dicecodes.com/ai-blog-writer/
 * Description:       AI blog writer that researches first, writes in your voice, and checks its own work.
 * Version:           0.73.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dicecodes
 * Author URI:        https://dicecodes.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dicecodes-ai-blog-writer
 * Domain Path:       /languages
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/*
 * A second copy of this plugin is already running.
 *
 * Two copies in wp-content/plugins is easy to end up with: a manual
 * upload beside an installed one, a zip extracted into a folder of its
 * own, a staging site restored over a live one. Activating the second is
 * then a fatal error, because the require_once below takes an absolute
 * path — a different one for each copy, so it does not short-circuit, and
 * PHP refuses to declare the autoloader class twice.
 *
 * WordPress catches that and rolls the activation back, which is the right
 * thing to do and tells nobody anything useful. What people see is a
 * plugin that will not switch on, with no indication that the copy they
 * are looking at is the problem or that another one is already working.
 *
 * Standing aside is the only safe answer: the copy that loaded first owns
 * the classes, the hooks and the database. This one says which folder it
 * is in, so there is something to delete.
 */
if ( defined( 'BLOGCRAFT_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: folder of the duplicate copy. 2: version already running. */
						__( 'Dicecodes AI Blog Writer is installed twice. The copy in %1$s did nothing, because version %2$s had already loaded. Delete the folder named above from your plugins directory — nothing is lost, the working copy owns the settings and the posts.', 'dicecodes-ai-blog-writer' ),
						basename( __DIR__ ),
						BLOGCRAFT_VERSION
					)
				)
			);
		}
	);

	return;
}

define( 'BLOGCRAFT_VERSION', '0.73.0' );
define( 'BLOGCRAFT_DB_VERSION', '1' );
define( 'BLOGCRAFT_FILE', __FILE__ );
define( 'BLOGCRAFT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLOGCRAFT_URL', plugin_dir_url( __FILE__ ) );


require_once BLOGCRAFT_PATH . 'includes/class-blogcraft-autoloader.php';

Blogcraft_Autoloader::register();

register_activation_hook( __FILE__, array( 'Blogcraft_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Blogcraft_Deactivator', 'deactivate' ) );

Blogcraft::instance()->run();
