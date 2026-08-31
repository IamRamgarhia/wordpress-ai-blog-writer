<?php
/**
 * Dismissible admin notices.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-user dismissible notices.
 *
 * Guideline 11 requires notices to be dismissible and contextual. Dismissal
 * is stored per user so one administrator hiding a warning does not hide it
 * from their colleagues.
 */
class Blogcraft_Notices {

	/**
	 * User meta key holding dismissed notice ids.
	 */
	const META_KEY = 'blogcraft_dismissed_notices';

	/**
	 * Nonce action for the dismiss request.
	 */
	const DISMISS_ACTION = 'blogcraft_dismiss_notice';

	/**
	 * Wire the dismissal handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_blogcraft_dismiss_notice', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_cron_health_notice' ) );
	}

	/**
	 * Mark a notice dismissed for a user.
	 *
	 * @param string $notice_id Notice identifier.
	 * @param int    $user_id   User id.
	 * @return void
	 */
	public static function dismiss( $notice_id, $user_id ) {
		$dismissed = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		$dismissed[ (string) $notice_id ] = true;

		update_user_meta( $user_id, self::META_KEY, $dismissed );
	}

	/**
	 * Whether a user has dismissed a notice.
	 *
	 * @param string $notice_id Notice identifier.
	 * @param int    $user_id   User id.
	 * @return bool
	 */
	public static function is_dismissed( $notice_id, $user_id ) {
		$dismissed = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $dismissed ) && ! empty( $dismissed[ (string) $notice_id ] );
	}

	/**
	 * Handle the dismiss request.
	 *
	 * @return void
	 */
	public static function handle_dismiss() {
		// The nonce value itself must be read before it can be verified below;
		// verification is centralised in Blogcraft_Request rather than inline,
		// so PHPCS cannot statically see that it happens.
		$nonce  = isset( $_REQUEST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_REQUEST['notice'] ) ? sanitize_key( wp_unslash( $_REQUEST['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		Blogcraft_Request::verify_or_die( self::DISMISS_ACTION, $nonce );

		if ( '' !== $notice ) {
			self::dismiss( $notice, get_current_user_id() );
		}

		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Build a dismissal URL for a notice.
	 *
	 * @param string $notice_id Notice identifier.
	 * @return string
	 */
	public static function dismiss_url( $notice_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'blogcraft_dismiss_notice',
					'notice' => $notice_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION,
			'_blogcraft_nonce'
		);
	}

	/**
	 * Warn when WP-Cron appears not to be firing.
	 *
	 * @return void
	 */
	public static function render_cron_health_notice() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! ( $screen instanceof WP_Screen ) || false === strpos( $screen->id, Blogcraft_Admin::MENU_SLUG ) ) {
			return;
		}

		if ( ! Blogcraft_Settings::get( 'cron_health_notice_enabled' ) ) {
			return;
		}

		if ( self::is_dismissed( 'cron_health', get_current_user_id() ) ) {
			return;
		}

		if ( ! Blogcraft_Cron_Health::is_stale() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Dicecodes AI Blog Writer has not processed its queue recently. WordPress only runs scheduled tasks when someone visits your site, so low-traffic sites may need a real system cron job.', 'dicecodes-ai-blog-writer' ),
			esc_url( self::dismiss_url( 'cron_health' ) ),
			esc_html__( 'Dismiss this notice', 'dicecodes-ai-blog-writer' )
		);
	}
}
