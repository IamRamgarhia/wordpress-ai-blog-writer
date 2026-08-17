<?php
/**
 * Database schema and migrations.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and versions Blogcraft's custom tables.
 */
class Blogcraft_Migrator {

	/**
	 * Option storing the installed schema version.
	 */
	const VERSION_OPTION = 'blogcraft_db_version';

	/**
	 * Build a fully prefixed table name.
	 *
	 * @param string $suffix Table suffix, e.g. 'jobs'.
	 * @return string
	 */
	public static function table_name( $suffix ) {
		global $wpdb;

		return $wpdb->prefix . 'blogcraft_' . $suffix;
	}

	/**
	 * Create or update the schema.
	 *
	 * Uses dbDelta, which is idempotent. Indexed varchar columns are capped at
	 * 191 characters for the utf8mb4 767-byte InnoDB key limit on older MySQL.
	 *
	 * @return void
	 */
	public static function migrate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$jobs            = self::table_name( 'jobs' );
		$log             = self::table_name( 'log' );

		$jobs_sql = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline varchar(64) NOT NULL DEFAULT '',
			stage varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			payload longtext NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			max_attempts smallint(5) unsigned NOT NULL DEFAULT 3,
			available_at datetime NOT NULL,
			locked_at datetime NULL,
			lock_token varchar(64) NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_available (status, available_at),
			KEY lock_token (lock_token)
		) {$charset_collate};";

		$log_sql = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY level_created (level, created_at)
		) {$charset_collate};";

		dbDelta( $jobs_sql );
		dbDelta( $log_sql );

		update_option( self::VERSION_OPTION, BLOGCRAFT_DB_VERSION, false );
	}

	/**
	 * Drop all Blogcraft tables and the version marker.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( array( 'jobs', 'log' ) as $suffix ) {
			$table = self::table_name( $suffix );
			// Table name cannot be parameterised; it is built from $wpdb->prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::VERSION_OPTION );
	}
}
