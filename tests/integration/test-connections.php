<?php
/**
 * One row per connection, not one row per credential.
 *
 * Signing an app in mints two tokens: the one it calls with, and the one it
 * renews with. The screen listed both, beside every token issued by hand,
 * with nothing saying which was which — so one app appeared twice, the second
 * row claiming it had never been used, and revoking the wrong one either did
 * nothing visible or left the app free to renew itself straight back in.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Connections extends WP_UnitTestCase {

	/**
	 * Somebody allowed to connect apps.
	 *
	 * @var int
	 */
	private $author = 0;

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		$this->author = self::factory()->user->create( array( 'role' => 'administrator' ) );

		delete_option( Blogcraft_Mcp_Auth::OPTION );
		delete_option( 'blogcraft_settings' );

		Blogcraft_Settings::set( 'mcp_enabled', true );
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		wp_set_current_user( $this->author );
	}

	public function tear_down() {
		delete_option( Blogcraft_Mcp_Auth::OPTION );
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Sign an app in, the way the token endpoint does.
	 *
	 * @param string $client Which app.
	 * @param string $label  What to call it.
	 * @return array The two secrets.
	 */
	private function sign_in( $client, $label ) {
		return array(
			Blogcraft_Mcp_Auth::issue( $this->author, $label, array( 'client' => $client, 'expires' => time() + DAY_IN_SECONDS ) ),
			Blogcraft_Mcp_Auth::issue( $this->author, $label, array( 'client' => $client, 'kind' => 'refresh', 'expires' => 0 ) ),
		);
	}

	public function test_an_app_that_signed_in_is_one_connection_not_two() {
		$this->sign_in( 'bc_claude', 'Claude' );

		$this->assertCount( 2, Blogcraft_Mcp_Auth::all(), 'signing in should still mint two credentials' );
		$this->assertCount( 1, Blogcraft_Mcp_Auth::connections(), 'and they are one connection' );
	}

	public function test_a_token_typed_in_by_hand_is_its_own_connection() {
		Blogcraft_Mcp_Auth::issue( $this->author, 'my laptop' );
		$this->sign_in( 'bc_claude', 'Claude' );

		$this->assertCount( 2, Blogcraft_Mcp_Auth::connections() );
	}

	public function test_a_connection_says_how_it_got_in() {
		Blogcraft_Mcp_Auth::issue( $this->author, 'my laptop' );
		$this->sign_in( 'bc_claude', 'Claude' );

		$how = wp_list_pluck( Blogcraft_Mcp_Auth::connections(), 'signed_in' );

		$this->assertContains( true, $how, 'the app that signed in is not marked as such' );
		$this->assertContains( false, $how, 'the hand-issued token is not marked as such' );
	}

	public function test_the_last_use_is_the_later_of_the_pair() {
		// The renewal token is often never used at all, and a row saying
		// "Never" beside a connection working perfectly well is a row that
		// invites somebody to revoke the wrong thing.
		list( $access ) = $this->sign_in( 'bc_claude', 'Claude' );

		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$request->set_header( 'authorization', 'Bearer ' . $access );

		Blogcraft_Mcp_Auth::user_for( $request );

		$connections = Blogcraft_Mcp_Auth::connections();
		$one         = reset( $connections );

		$this->assertNotEmpty( $one['used'], 'the connection looks unused although it was just used' );
	}

	public function test_disconnecting_takes_every_credential_with_it() {
		// The whole point. Leaving the renewal token behind is a
		// disconnection that appears to work and then does not.
		$this->sign_in( 'bc_claude', 'Claude' );

		$connections = Blogcraft_Mcp_Auth::connections();
		$id          = array_key_first( $connections );

		$this->assertTrue( Blogcraft_Mcp_Auth::disconnect( $id ) );
		$this->assertSame( array(), Blogcraft_Mcp_Auth::all(), 'a credential survived the disconnection' );
	}

	public function test_disconnecting_one_app_leaves_the_others_alone() {
		$this->sign_in( 'bc_claude', 'Claude' );
		$this->sign_in( 'bc_chatgpt', 'ChatGPT' );
		Blogcraft_Mcp_Auth::issue( $this->author, 'my laptop' );

		$this->assertCount( 3, Blogcraft_Mcp_Auth::connections() );

		foreach ( Blogcraft_Mcp_Auth::connections() as $id => $one ) {
			if ( 'Claude' === $one['label'] ) {
				Blogcraft_Mcp_Auth::disconnect( $id );
				break;
			}
		}

		$left = wp_list_pluck( Blogcraft_Mcp_Auth::connections(), 'label' );

		$this->assertNotContains( 'Claude', $left );
		$this->assertContains( 'ChatGPT', $left );
		$this->assertContains( 'my laptop', $left );
	}

	public function test_a_disconnected_app_can_no_longer_call_anything() {
		list( $access ) = $this->sign_in( 'bc_claude', 'Claude' );

		$connections = Blogcraft_Mcp_Auth::connections();
		Blogcraft_Mcp_Auth::disconnect( array_key_first( $connections ) );

		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$request->set_header( 'authorization', 'Bearer ' . $access );

		$this->assertSame( 0, Blogcraft_Mcp_Auth::user_for( $request ) );
	}

	public function test_the_screen_lists_connections_and_offers_to_end_them() {
		$this->sign_in( 'bc_claude', 'Claude' );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Connected apps', $html );
		$this->assertStringContainsString( 'Disconnect', $html );

		// One row, not two, for one app.
		$this->assertSame(
			1,
			substr_count( $html, 'name="connection"' ),
			'an app that signed in is listed more than once'
		);
	}

	public function test_the_screen_offers_nothing_to_disconnect_when_nothing_is() {
		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Nothing is connected yet', $html );
		$this->assertStringNotContainsString( 'name="connection"', $html );
	}
}
