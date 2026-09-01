<?php
/**
 * Uninstall routine.
 *
 * Removes every trace of Blogcraft when the plugin is deleted, but only
 * when somebody has said in advance that is what they want.
 *
 * @package Blogcraft
 */

// WP_UNINSTALL_PLUGIN is the only constant that means WordPress is
// deleting this plugin right now. ABSPATH used to be accepted here too,
// as the second half of an or-chain — and ABSPATH is defined on every
// WordPress request, so the guard passed whenever this file was reached
// by any route at all, not only a real uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'blogcraft_should_purge' ) ) {

	/**
	 * Whether deleting the plugin should also delete what it stored.
	 *
	 * Read straight from the option rather than through Blogcraft_Settings.
	 * This runs while the plugin is being deleted, and a settings class that
	 * failed to load must not be the reason somebody's work is erased —
	 * absent, unreadable or malformed all have to mean keep.
	 *
	 * @return bool
	 */
	function blogcraft_should_purge() {
		$stored = get_option( 'blogcraft_settings', array() );

		return is_array( $stored ) && ! empty( $stored['purge_on_delete'] );
	}
}

if ( ! function_exists( 'blogcraft_uninstall_cleanup' ) ) {

	/**
	 * Delete all Blogcraft data.
	 *
	 * @return void
	 */
	function blogcraft_uninstall_cleanup() {
		if ( class_exists( 'Blogcraft_Scheduler' ) ) {
			Blogcraft_Scheduler::unschedule();
		}

		if ( class_exists( 'Blogcraft_Autopilot' ) ) {
			Blogcraft_Autopilot::unschedule();
		}

		if ( class_exists( 'Blogcraft_Capabilities' ) ) {
			Blogcraft_Capabilities::remove();
		}

		if ( class_exists( 'Blogcraft_Migrator' ) ) {
			Blogcraft_Migrator::drop_tables();
		}

		delete_option( 'blogcraft_settings' );
		delete_option( 'blogcraft_cron_heartbeat' );
		delete_option( 'blogcraft_activated_at' );
		delete_option( 'blogcraft_cost' );
		delete_option( 'blogcraft_autopilot_counter' );

		// The blueprint store and the schema version. These were missing, so
		// "every trace" above was not true: deleting the plugin and installing
		// it again handed the newcomer the last owner's writing rules, and a
		// schema version claiming tables that had just been dropped — which
		// would have stopped the runtime migration from rebuilding them.
		delete_option( 'blogcraft_blueprints' );
		delete_option( 'blogcraft_active_blueprint' );
		delete_option( 'blogcraft_blueprints_migrated' );
		delete_option( 'blogcraft_db_version' );
		delete_option( 'blogcraft_welcomed' );
		delete_option( 'blogcraft_welcome_pending' );

		// Connection tokens for AI clients. These are credentials, so they
		// go with everything else rather than outliving the plugin that
		// issued them.
		delete_option( 'blogcraft_mcp_tokens' );

		// And the apps those tokens were issued to. A client id is not a
		// secret, but leaving the list behind means the next install
		// inherits a set of approved redirect addresses it never agreed
		// to.
		delete_option( 'blogcraft_mcp_clients' );

		// Saved provider setups. These hold API keys, so they go with the
		// live one rather than outliving the plugin that stored them.
		delete_option( 'blogcraft_saved_providers' );

		delete_metadata( 'user', 0, 'blogcraft_dismissed_notices', '', true );
		delete_metadata( 'user', 0, 'blogcraft_mcp_test', '', true );

		// Running totals for jobs that never reached a post. A wildcard is
		// the only way to reach them; the options API has no such call.
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'blogcraft_usage_job_' ) . '%'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one prefix sweep at uninstall; there is no options API for a wildcard.

		// The posts themselves are the user's and stay. Everything Blogcraft
		// attached to them goes, because the note at the top of this file says
		// every trace is removed and it was not true of any of these.
		$post_meta = array(
			'_blogcraft_generated',
			'_blogcraft_mcp',
			'_blogcraft_evidence',
			'_blogcraft_usage',
			'_blogcraft_words',
			'_blogcraft_quality',
			'_blogcraft_quality_reasons',
			'_blogcraft_checks',
			'_blogcraft_metrics',
			'_blogcraft_topic',
			'_blogcraft_faq_schema',
			'_blogcraft_refreshed',
			'_blogcraft_job',
			'_blogcraft_section_images',
			'_blogcraft_section_images_done',
			'_blogcraft_seo_title',
			'_blogcraft_seo_description',
		);

		foreach ( $post_meta as $key ) {
			delete_metadata( 'post', 0, $key, '', true );
		}
	}
}

if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-blogcraft-autoloader.php';

	if ( ! defined( 'BLOGCRAFT_PATH' ) ) {
		define( 'BLOGCRAFT_PATH', plugin_dir_path( __FILE__ ) );
	}

	Blogcraft_Autoloader::register();

	// Kept unless asked otherwise. WordPress asks whether you meant to
	// delete the plugin. It has no way to ask whether you also meant to
	// delete the settings, the blueprints and every post it has a record
	// of writing — and dropping tables has no undo. Deleting a plugin to
	// reinstall it, to move hosts, or to clear a half-finished upload is
	// ordinary, and none of those are a request to throw the work away.
	//
	// Read directly rather than through Blogcraft_Settings, which is
	// deliberate: this runs during deletion, and a settings class that
	// failed to load must not be the reason everything gets erased.
	if ( blogcraft_should_purge() ) {
		blogcraft_uninstall_cleanup();
	} elseif ( class_exists( 'Blogcraft_Scheduler' ) ) {
		// The scheduled events go regardless. Leaving cron entries behind
		// for a plugin that is no longer installed means WordPress trying
		// to fire callbacks that do not exist, every few minutes, for ever.
		Blogcraft_Scheduler::unschedule();

		if ( class_exists( 'Blogcraft_Autopilot' ) ) {
			Blogcraft_Autopilot::unschedule();
		}
	}
}
